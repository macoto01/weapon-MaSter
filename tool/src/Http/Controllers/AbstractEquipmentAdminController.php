<?php

namespace Tool\Http\Controllers;

use App\Domain\Combat\EffectRegistry;
use App\Domain\MasterData\MasterDataEntry;
use App\Services\MasterDataRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Tool\Support\TsvParser;

/**
 * 武器・防具・秘宝は同一スキーマ(企画書上「防具・秘宝はまだ実データが無く、スキーマは武器と同一」)
 * のため、確認・修正・削除の画面とロジックをこの基底クラスに集約する。種別ごとの差分は
 * kind()/label()/referenceBlockers()の3つだけ継承先で定義すればよい。
 */
abstract class AbstractEquipmentAdminController
{
    public function __construct(protected readonly MasterDataRepository $catalog) {}

    /** 'weapon' | 'armor' | 'relic'。JSONファイル名(kind+'s')・MasterDataRepositoryのメソッド名に使う */
    abstract protected function kind(): string;

    /** 画面表示用ラベル(例: '武器') */
    abstract protected function label(): string;

    /**
     * 削除前の参照チェック。参照が残っている場合はブロック理由の一覧を返す(空なら削除可能)。
     * 武器以外(防具・秘宝)は現状どこからも参照されないため、既定は「常に削除可能」。
     *
     * @return string[]
     */
    protected function referenceBlockers(string $key): array
    {
        return [];
    }

    public function index(): View
    {
        return view('tool::equipment.index', [
            'items' => $this->collection()->values(),
            'routePrefix' => $this->routePrefix(),
            'label' => $this->label(),
        ]);
    }

    public function edit(string $key): View
    {
        return view('tool::equipment.edit', [
            'item' => $this->find($key),
            'routePrefix' => $this->routePrefix(),
            'label' => $this->label(),
            'effectOptions' => $this->effectOptions(),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        // 空欄(未入力)は「値なし」として扱うため、数値バリデーションの前にnullへ寄せる。
        // special_effect_keyの「なし」選択肢もvalue=""で送られてくるため同様に扱う。
        foreach (['spd', 'stamina_cost', 'cooldown_seconds', 'accuracy', 'special_effect_key'] as $field) {
            if ($request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'grid_width' => ['required', 'integer', 'min:1', 'max:8'],
            'grid_height' => ['required', 'integer', 'min:1', 'max:8'],
            'atk' => ['required', 'numeric', 'min:0'],
            'spd' => ['nullable', 'numeric', 'min:0'],
            'stamina_cost' => ['nullable', 'integer', 'min:0'],
            'cooldown_seconds' => ['nullable', 'numeric', 'min:0'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100'],
            'special_effect_key' => ['nullable', 'string'],
            'role' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'integer', 'min:0'],
            'rarity' => ['required', 'integer', 'min:1', 'max:3'],
        ]);
        $data['is_synthesized'] = $request->boolean('is_synthesized');

        // $request->validate()はフォーム入力(すべて文字列)をそのまま返すため、
        // 数値項目はJSONへ書き戻す前に明示的に数値へキャストする
        // (そのまま書き込むと"999"のような文字列としてJSONに残ってしまう)。
        $data['grid_width'] = (int) $data['grid_width'];
        $data['grid_height'] = (int) $data['grid_height'];
        $data['atk'] = $data['atk'] + 0;
        $data['spd'] = $data['spd'] === null ? null : $data['spd'] + 0;
        $data['stamina_cost'] = $data['stamina_cost'] === null ? null : (int) $data['stamina_cost'];
        $data['cooldown_seconds'] = $data['cooldown_seconds'] === null ? null : $data['cooldown_seconds'] + 0;
        $data['accuracy'] = $data['accuracy'] === null ? null : (int) $data['accuracy'];
        $data['price'] = (int) $data['price'];
        $data['rarity'] = (int) $data['rarity'];

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
            abort(404, "{$this->label()} {$key} が見つかりません。");
        }

        $this->writeRows($rows);

        return redirect()->route("{$this->routePrefix()}.index")->with('status', "「{$data['name']}」を更新しました。");
    }

    public function destroy(string $key): RedirectResponse
    {
        $item = $this->find($key);

        $blockers = $this->referenceBlockers($key);
        if (! empty($blockers)) {
            return redirect()->route("{$this->routePrefix()}.index")
                ->with('status', "「{$item->name}」は次から参照されているため削除できません: ".implode('、', $blockers));
        }

        $rows = collect($this->readRows())->reject(fn (array $row) => $row['key'] === $key)->values()->all();
        $this->writeRows($rows);

        return redirect()->route("{$this->routePrefix()}.index")->with('status', "「{$item->name}」を削除しました。");
    }

    /** @var string[] Excel貼り付けデータの見出し行に必須の列名 */
    private const IMPORT_REQUIRED_HEADERS = ['key', 'name', 'grid_width', 'grid_height', 'atk', 'price', 'rarity'];

    /** @var string[] エクスポートするTSVの列(この形式のままインポートに貼り戻せる) */
    private const EXPORT_HEADERS = [
        'key', 'name', 'grid_width', 'grid_height', 'atk', 'spd', 'stamina_cost',
        'cooldown_seconds', 'accuracy', 'special_effect_key', 'role', 'is_synthesized', 'price', 'rarity',
    ];

    /**
     * 現在のマスタデータをTSVファイルとしてダウンロードする。インポート機能と同じ列構成のため、
     * 出力したファイルをExcelで編集してそのままインポートに貼り戻せる。
     */
    public function export(): Response
    {
        $rows = $this->collection()->values()->map(fn (MasterDataEntry $item) => [
            'key' => $item->key,
            'name' => $item->name,
            'grid_width' => $item->grid_width,
            'grid_height' => $item->grid_height,
            'atk' => $item->atk,
            'spd' => $item->spd,
            'stamina_cost' => $item->stamina_cost,
            'cooldown_seconds' => $item->cooldown_seconds,
            'accuracy' => $item->accuracy,
            'special_effect_key' => $item->special_effect_key,
            'role' => $item->role,
            'is_synthesized' => $item->is_synthesized,
            'price' => $item->price,
            'rarity' => $item->rarity,
        ])->all();

        return response(TsvParser::build(self::EXPORT_HEADERS, $rows), 200, [
            'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$this->kind()}s.tsv\"",
        ]);
    }

    public function importForm(): View
    {
        return view('tool::equipment.import', [
            'routePrefix' => $this->routePrefix(),
            'label' => $this->label(),
            'preview' => null,
            'raw' => '',
        ]);
    }

    /**
     * Excelから貼り付けたTSVデータをプレビューし、確認済み(confirmed=1)かつエラーがなければ反映する。
     * DBにある情報を上書きする機能: key一致行は更新、新規keyは追加、貼り付けデータに無い既存行はそのまま残す。
     */
    public function import(Request $request): View|RedirectResponse
    {
        $raw = (string) $request->input('data', '');
        $confirmed = $request->boolean('confirmed');

        $preview = $this->buildImportPreview($raw);

        if ($confirmed && $preview['global_error'] === null && $preview['error_count'] === 0 && $preview['row_count'] > 0) {
            $this->applyImport($preview['rows']);

            return redirect()->route("{$this->routePrefix()}.index")
                ->with('status', "貼り付けデータを反映しました(新規{$preview['insert_count']}件・更新{$preview['update_count']}件)。");
        }

        return view('tool::equipment.import', [
            'routePrefix' => $this->routePrefix(),
            'label' => $this->label(),
            'preview' => $preview,
            'raw' => $raw,
        ]);
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

        $existingKeys = $this->collection()->keys()->all();
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
                [$data, $rowErrors] = $this->validateImportRow($row);
                $errors = [...$errors, ...$rowErrors];
            }

            $isUpdate = in_array($key, $existingKeys, true);
            if (empty($errors)) {
                $isUpdate ? $updateCount++ : $insertCount++;
            } else {
                $errorCount++;
            }

            $parsedRows[] = [
                'line' => $i + 2, // 1行目は見出しのため+2
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

    /**
     * @param  array<string, string>  $row
     * @return array{0: ?array, 1: string[]}
     */
    private function validateImportRow(array $row): array
    {
        $input = $row;
        foreach (['spd', 'stamina_cost', 'cooldown_seconds', 'accuracy', 'special_effect_key', 'role'] as $field) {
            if (($input[$field] ?? '') === '') {
                $input[$field] = null;
            }
        }
        $isSynthesized = in_array(mb_strtolower((string) ($row['is_synthesized'] ?? '')), ['1', 'true', 'yes', '○', 'はい'], true);

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:50'],
            'grid_width' => ['required', 'integer', 'min:1', 'max:8'],
            'grid_height' => ['required', 'integer', 'min:1', 'max:8'],
            'atk' => ['required', 'numeric', 'min:0'],
            'spd' => ['nullable', 'numeric', 'min:0'],
            'stamina_cost' => ['nullable', 'integer', 'min:0'],
            'cooldown_seconds' => ['nullable', 'numeric', 'min:0'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100'],
            'special_effect_key' => ['nullable', 'string', 'in:'.implode(',', array_keys($this->effectOptions()))],
            'role' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'integer', 'min:0'],
            'rarity' => ['required', 'integer', 'min:1', 'max:3'],
        ]);

        if ($validator->fails()) {
            return [null, $validator->errors()->all()];
        }

        $data = $validator->validated();
        $data['is_synthesized'] = $isSynthesized;
        $data['grid_width'] = (int) $data['grid_width'];
        $data['grid_height'] = (int) $data['grid_height'];
        $data['atk'] = $data['atk'] + 0;
        $data['spd'] = $data['spd'] === null ? null : $data['spd'] + 0;
        $data['stamina_cost'] = $data['stamina_cost'] === null ? null : (int) $data['stamina_cost'];
        $data['cooldown_seconds'] = $data['cooldown_seconds'] === null ? null : $data['cooldown_seconds'] + 0;
        $data['accuracy'] = $data['accuracy'] === null ? null : (int) $data['accuracy'];
        $data['price'] = (int) $data['price'];
        $data['rarity'] = (int) $data['rarity'];

        return [$data, []];
    }

    /**
     * @param  array<int, array{key:string,status:string,data:?array}>  $previewRows
     */
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

    private function routePrefix(): string
    {
        return "tool.{$this->kind()}s";
    }

    private function collection(): Collection
    {
        $method = "{$this->kind()}s";

        return $this->catalog->{$method}();
    }

    private function find(string $key): MasterDataEntry
    {
        $kind = $this->kind();

        return $this->catalog->{$kind}($key);
    }

    private function jsonPath(): string
    {
        return database_path("data/{$this->kind()}s.json");
    }

    private function readRows(): array
    {
        return json_decode(file_get_contents($this->jsonPath()), true) ?? [];
    }

    private function writeRows(array $rows): void
    {
        file_put_contents($this->jsonPath(), json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * @return array<string, string> 特殊効果キー => 説明文。EffectRegistryが唯一の正とする一覧をここでも使う。
     */
    private function effectOptions(): array
    {
        return collect([
            EffectRegistry::NO_ATTACK_WINDOW_1S,
            EffectRegistry::ADJACENT_ATK_BUFF_20,
            EffectRegistry::DOUBLE_HIT,
            EffectRegistry::BONUS_HP_20,
            EffectRegistry::ADJACENT_ATK_SPD_BUFF,
        ])->mapWithKeys(fn (string $key) => [$key => "{$key} - ".EffectRegistry::describe($key)])->all();
    }
}
