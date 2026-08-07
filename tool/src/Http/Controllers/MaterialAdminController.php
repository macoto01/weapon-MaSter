<?php

namespace Tool\Http\Controllers;

use App\Services\MasterDataRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Tool\Http\Controllers\Concerns\GuardsMasterDataReferences;
use Tool\Support\TsvParser;

/**
 * database/data/materials.json を直接読み書きする管理ツール用コントローラ。
 * WeaponAdminControllerと同じ方針(ゲーム本体は読み取り専用、書き込みはここのみ)。
 * 削除時は合成レシピ・CPUパターンから参照されていないかをGuardsMasterDataReferencesで確認する。
 */
class MaterialAdminController
{
    use GuardsMasterDataReferences;

    /** @var array<string, string> effect_type => 表示名。EffectRegistry::describeMaterial()が扱う値と揃える */
    private const EFFECT_TYPES = [
        'buff_atk' => 'buff_atk(ATKバフ)',
        'buff_spd' => 'buff_spd(SPDバフ)',
        'buff_hp' => 'buff_hp(HPバフ)',
    ];

    /** @var array<string, string> effect_range => 表示名 */
    private const EFFECT_RANGES = [
        'adjacent' => 'adjacent(隣接する武器のみ)',
        'global' => 'global(配置しているだけで全体に発動)',
    ];

    /** @var string[] Excel貼り付けデータの見出し行に必須の列名 */
    private const IMPORT_REQUIRED_HEADERS = ['key', 'name', 'grid_width', 'grid_height', 'effect_type', 'effect_value', 'effect_range', 'price'];

    public function __construct(private readonly MasterDataRepository $catalog) {}

    public function index(): View
    {
        return view('tool::materials.index', [
            'materials' => $this->catalog->materials()->values(),
        ]);
    }

    public function edit(string $key): View
    {
        return view('tool::materials.edit', [
            'material' => $this->catalog->material($key),
            'effectTypes' => self::EFFECT_TYPES,
            'effectRanges' => self::EFFECT_RANGES,
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'grid_width' => ['required', 'integer', 'min:1', 'max:8'],
            'grid_height' => ['required', 'integer', 'min:1', 'max:8'],
            'effect_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::EFFECT_TYPES))],
            'effect_value' => ['required', 'integer', 'min:0'],
            'effect_range' => ['required', 'string', 'in:'.implode(',', array_keys(self::EFFECT_RANGES))],
            'price' => ['required', 'integer', 'min:0'],
        ]);
        $data['grid_width'] = (int) $data['grid_width'];
        $data['grid_height'] = (int) $data['grid_height'];
        $data['effect_value'] = (int) $data['effect_value'];
        $data['price'] = (int) $data['price'];

        $rows = $this->readRows();

        $found = false;
        foreach ($rows as &$row) {
            if ($row['key'] === $key) {
                $row = ['key' => $key, ...$data];
                $found = true;
                break;
            }
        }
        unset($row);

        if (! $found) {
            abort(404, "素材 {$key} が見つかりません。");
        }

        $this->writeRows($rows);

        return redirect()->route('tool.materials.index')->with('status', "「{$data['name']}」を更新しました。");
    }

    public function destroy(string $key): RedirectResponse
    {
        $material = $this->catalog->material($key);

        $blockers = $this->findMasterDataReferences('material', $key);
        if (! empty($blockers)) {
            return redirect()->route('tool.materials.index')
                ->with('status', "「{$material->name}」は次から参照されているため削除できません: ".implode('、', $blockers));
        }

        $rows = collect($this->readRows())->reject(fn (array $row) => $row['key'] === $key)->values()->all();
        $this->writeRows($rows);

        return redirect()->route('tool.materials.index')->with('status', "「{$material->name}」を削除しました。");
    }

    /**
     * 現在のマスタデータをTSVファイルとしてダウンロードする。インポート機能と同じ列構成のため、
     * 出力したファイルをExcelで編集してそのままインポートに貼り戻せる。
     */
    public function export(): Response
    {
        $rows = $this->catalog->materials()->values()->map(fn ($material) => [
            'key' => $material->key,
            'name' => $material->name,
            'grid_width' => $material->grid_width,
            'grid_height' => $material->grid_height,
            'effect_type' => $material->effect_type,
            'effect_value' => $material->effect_value,
            'effect_range' => $material->effect_range,
            'price' => $material->price,
        ])->all();

        return response(TsvParser::build(['key', 'name', 'grid_width', 'grid_height', 'effect_type', 'effect_value', 'effect_range', 'price'], $rows), 200, [
            'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="materials.tsv"',
        ]);
    }

    public function importForm(): View
    {
        return view('tool::materials.import', ['preview' => null, 'raw' => '']);
    }

    /**
     * Excelから貼り付けたTSVデータをプレビューし、確認済み(confirmed=1)かつエラーがなければ反映する。
     * key一致行は更新、新規keyは追加、貼り付けデータに無い既存行はそのまま残す。
     */
    public function import(Request $request): View|RedirectResponse
    {
        $raw = (string) $request->input('data', '');
        $confirmed = $request->boolean('confirmed');

        $preview = $this->buildImportPreview($raw);

        if ($confirmed && $preview['global_error'] === null && $preview['error_count'] === 0 && $preview['row_count'] > 0) {
            $this->applyImport($preview['rows']);

            return redirect()->route('tool.materials.index')
                ->with('status', "貼り付けデータを反映しました(新規{$preview['insert_count']}件・更新{$preview['update_count']}件)。");
        }

        return view('tool::materials.import', ['preview' => $preview, 'raw' => $raw]);
    }

    /**
     * @return array{row_count:int,insert_count:int,update_count:int,error_count:int,global_error:?string,rows:array}
     */
    private function buildImportPreview(string $raw): array
    {
        ['headers' => $headers, 'rows' => $rawRows] = TsvParser::parse($raw);

        $empty = ['row_count' => 0, 'insert_count' => 0, 'update_count' => 0, 'error_count' => 0, 'global_error' => null, 'rows' => []];

        if (empty($headers)) {
            return [...$empty, 'global_error' => '貼り付けデータがありません。見出し行を含めて貼り付けてください。'];
        }

        $missing = array_diff(self::IMPORT_REQUIRED_HEADERS, $headers);
        if (! empty($missing)) {
            return [...$empty, 'global_error' => '見出し行に次の列がありません: '.implode('、', $missing)];
        }

        $existingKeys = $this->catalog->materials()->keys()->all();
        $seenKeys = [];
        $parsedRows = [];
        $insertCount = 0;
        $updateCount = 0;
        $errorCount = 0;

        foreach ($rawRows as $i => $row) {
            $errors = [];
            $key = $row['key'] ?? '';

            if ($key === '') {
                $errors[] = 'keyが空です。';
            } elseif (! preg_match('/^[a-z0-9_]+$/', $key)) {
                $errors[] = 'keyは半角英小文字・数字・アンダースコアのみ使用できます。';
            } elseif (isset($seenKeys[$key])) {
                $errors[] = "key「{$key}」が貼り付けデータ内で重複しています。";
            }
            $seenKeys[$key] = true;

            $data = null;
            if (empty($errors)) {
                $validator = Validator::make($row, [
                    'name' => ['required', 'string', 'max:50'],
                    'grid_width' => ['required', 'integer', 'min:1', 'max:8'],
                    'grid_height' => ['required', 'integer', 'min:1', 'max:8'],
                    'effect_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::EFFECT_TYPES))],
                    'effect_value' => ['required', 'integer', 'min:0'],
                    'effect_range' => ['required', 'string', 'in:'.implode(',', array_keys(self::EFFECT_RANGES))],
                    'price' => ['required', 'integer', 'min:0'],
                ]);

                if ($validator->fails()) {
                    $errors = [...$errors, ...$validator->errors()->all()];
                } else {
                    $data = $validator->validated();
                    $data['grid_width'] = (int) $data['grid_width'];
                    $data['grid_height'] = (int) $data['grid_height'];
                    $data['effect_value'] = (int) $data['effect_value'];
                    $data['price'] = (int) $data['price'];
                }
            }

            $isUpdate = in_array($key, $existingKeys, true);
            if (empty($errors)) {
                $isUpdate ? $updateCount++ : $insertCount++;
            } else {
                $errorCount++;
            }

            $parsedRows[] = [
                'line' => $i + 2,
                'key' => $key,
                'name' => $row['name'] ?? '',
                'status' => empty($errors) ? ($isUpdate ? 'update' : 'insert') : 'error',
                'errors' => $errors,
                'data' => $data,
            ];
        }

        return [
            'row_count' => count($parsedRows),
            'insert_count' => $insertCount,
            'update_count' => $updateCount,
            'error_count' => $errorCount,
            'global_error' => null,
            'rows' => $parsedRows,
        ];
    }

    private function applyImport(array $previewRows): void
    {
        $byKey = collect($this->readRows())->keyBy('key');

        foreach ($previewRows as $row) {
            if ($row['status'] === 'error') {
                continue;
            }
            $byKey[$row['key']] = ['key' => $row['key'], ...$row['data']];
        }

        $this->writeRows($byKey->values()->all());
    }

    private function readRows(): array
    {
        return json_decode(file_get_contents(database_path('data/materials.json')), true) ?? [];
    }

    private function writeRows(array $rows): void
    {
        file_put_contents(
            database_path('data/materials.json'),
            json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
        );
    }
}
