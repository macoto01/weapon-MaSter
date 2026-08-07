<?php

namespace Tool\Http\Controllers;

use App\Models\CpuPattern;
use App\Services\MasterDataRepository;
use App\Services\MatchStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Tool\Support\TsvParser;

/**
 * cpu_patternsテーブル(対戦相手データ)を管理するコントローラ。
 * 武器・素材と違いDBテーブルのため、武器管理ツールと異なり新規作成・削除にも対応する。
 * layoutはJSONテキストとして編集する(item_type/item_key/x/y/width/heightの配列)。
 * 編集画面ではJSONの下にグリッドのプレビューを表示し、配置を視覚的に確認できるようにしている。
 * CPU側のバックパックはMatchStateServiceでプレイヤー側と同じグリッドサイズで扱われるため、
 * その範囲・重なりをここでも検証する(グリッドサイズはMatchStateService::RESERVED_WIDTH/HEIGHTを
 * 単一の情報源として参照し、値の二重管理を避ける)。
 */
class CpuAdminController
{
    /** @var string[] Excel貼り付けデータの見出し行に必須の列名。layoutは"type:key:x:y:width:height;..."形式の1セル */
    private const IMPORT_REQUIRED_HEADERS = ['key', 'name', 'description', 'difficulty_tier', 'layout'];

    public function __construct(private readonly MasterDataRepository $catalog) {}

    public function index(): View
    {
        return view('tool::cpu.index', [
            'patterns' => CpuPattern::orderBy('difficulty_tier')->orderBy('key')->get(),
        ]);
    }

    public function create(): View
    {
        return view('tool::cpu.edit', [
            'pattern' => null,
            'layoutJson' => '[]',
            'weaponKeys' => $this->catalog->weapons()->keys(),
            'materialKeys' => $this->catalog->materials()->keys(),
            'gridWidth' => MatchStateService::RESERVED_WIDTH,
            'gridHeight' => MatchStateService::RESERVED_HEIGHT,
        ]);
    }

    public function edit(string $key): View
    {
        $pattern = CpuPattern::where('key', $key)->firstOrFail();

        return view('tool::cpu.edit', [
            'pattern' => $pattern,
            'layoutJson' => json_encode($pattern->layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'weaponKeys' => $this->catalog->weapons()->keys(),
            'materialKeys' => $this->catalog->materials()->keys(),
            'gridWidth' => MatchStateService::RESERVED_WIDTH,
            'gridHeight' => MatchStateService::RESERVED_HEIGHT,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, isCreate: true);

        CpuPattern::create($data);

        return redirect()->route('tool.cpu.index')->with('status', "「{$data['name']}」を追加しました。");
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $pattern = CpuPattern::where('key', $key)->firstOrFail();
        $data = $this->validated($request, isCreate: false);

        $pattern->update($data);

        return redirect()->route('tool.cpu.index')->with('status', "「{$data['name']}」を更新しました。");
    }

    public function destroy(string $key): RedirectResponse
    {
        $pattern = CpuPattern::where('key', $key)->firstOrFail();
        $pattern->delete();

        return redirect()->route('tool.cpu.index')->with('status', "「{$pattern->name}」を削除しました。");
    }

    /**
     * 現在のマスタデータをTSVファイルとしてダウンロードする。インポート機能と同じ列構成・
     * layoutのitem_type:item_key:x:y:width:height;...記法のため、出力したファイルをExcelで
     * 編集してそのままインポートに貼り戻せる。
     */
    public function export(): Response
    {
        $rows = CpuPattern::orderBy('difficulty_tier')->orderBy('key')->get()->map(fn (CpuPattern $pattern) => [
            'key' => $pattern->key,
            'name' => $pattern->name,
            'description' => $pattern->description,
            'difficulty_tier' => $pattern->difficulty_tier,
            'layout' => collect($pattern->layout)
                ->map(fn (array $item) => "{$item['item_type']}:{$item['item_key']}:{$item['x']}:{$item['y']}:{$item['width']}:{$item['height']}")
                ->implode(';'),
        ])->all();

        return response(TsvParser::build(['key', 'name', 'description', 'difficulty_tier', 'layout'], $rows), 200, [
            'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="cpu_patterns.tsv"',
        ]);
    }

    public function importForm(): View
    {
        return view('tool::cpu.import', ['preview' => null, 'raw' => '']);
    }

    /**
     * Excelから貼り付けたTSVデータをプレビューし、確認済み(confirmed=1)かつエラーがなければ反映する。
     * key一致行は更新、新規keyは追加、貼り付けデータに無い既存行はそのまま残す。
     * layout列は "weapon:sword:0:0:1:2;material:whetstone:1:0:1:1" のように
     * item_type:item_key:x:y:width:height を;区切りで並べたセルとして扱う。
     */
    public function import(Request $request): View|RedirectResponse
    {
        $raw = (string) $request->input('data', '');
        $confirmed = $request->boolean('confirmed');

        $preview = $this->buildImportPreview($raw);

        if ($confirmed && $preview['global_error'] === null && $preview['error_count'] === 0 && $preview['row_count'] > 0) {
            $this->applyImport($preview['rows']);

            return redirect()->route('tool.cpu.index')
                ->with('status', "貼り付けデータを反映しました(新規{$preview['insert_count']}件・更新{$preview['update_count']}件)。");
        }

        return view('tool::cpu.import', ['preview' => $preview, 'raw' => $raw]);
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

        $existingKeys = CpuPattern::pluck('key')->all();
        $seenKeys = [];
        $parsedRows = [];
        $insertCount = 0;
        $updateCount = 0;
        $errorCount = 0;

        foreach ($rawRows as $i => $row) {
            $errors = [];
            $key = $row['key'] ?? '';
            $name = $row['name'] ?? '';
            $description = $row['description'] ?? '';

            if ($key === '') {
                $errors[] = 'keyが空です。';
            } elseif (! preg_match('/^[a-z0-9_]+$/', $key)) {
                $errors[] = 'keyは半角英小文字・数字・アンダースコアのみ使用できます。';
            } elseif (isset($seenKeys[$key])) {
                $errors[] = "key「{$key}」が貼り付けデータ内で重複しています。";
            }
            $seenKeys[$key] = true;

            if ($name === '') {
                $errors[] = '名前が空です。';
            }
            if ($description === '') {
                $errors[] = '説明が空です。';
            }

            $tier = $row['difficulty_tier'] ?? '';
            if (! ctype_digit((string) $tier) || (int) $tier < 1 || (int) $tier > 5) {
                $errors[] = '強さ(difficulty_tier)は1〜5の整数で指定してください。';
            }

            $layout = $this->parseLayoutCell($row['layout'] ?? '');
            if ($layout === null) {
                $errors[] = 'layout列は "weapon:sword:0:0:1:2;material:whetstone:1:0:1:1" の形式(item_type:item_key:x:y:width:height を;区切り)で指定してください。';
            } else {
                $errors = [...$errors, ...$this->validateLayout($layout)];
            }

            $data = null;
            if (empty($errors)) {
                $data = [
                    'name' => $name,
                    'description' => $description,
                    'difficulty_tier' => (int) $tier,
                    'layout' => $layout,
                ];
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
                'name' => $name,
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
     * @return array<int, array{item_type:string,item_key:string,x:int,y:int,width:int,height:int}>|null 形式が不正な場合はnull
     */
    private function parseLayoutCell(string $cell): ?array
    {
        $cell = trim($cell);
        if ($cell === '') {
            return [];
        }

        $items = [];
        foreach (explode(';', $cell) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $segments = explode(':', $part);
            if (count($segments) !== 6 || ! in_array($segments[0], ['weapon', 'material'], true)) {
                return null;
            }
            [$type, $key, $x, $y, $width, $height] = $segments;
            if ($key === '' || ! ctype_digit($x) || ! ctype_digit($y) || ! ctype_digit($width) || ! ctype_digit($height)) {
                return null;
            }
            $items[] = [
                'item_type' => $type,
                'item_key' => $key,
                'x' => (int) $x,
                'y' => (int) $y,
                'width' => (int) $width,
                'height' => (int) $height,
            ];
        }

        return $items;
    }

    private function applyImport(array $previewRows): void
    {
        foreach ($previewRows as $row) {
            if ($row['status'] === 'error') {
                continue;
            }
            CpuPattern::updateOrCreate(['key' => $row['key']], $row['data']);
        }
    }

    /**
     * @return array{key:string,name:string,description:string,difficulty_tier:int,layout:array}
     */
    private function validated(Request $request, bool $isCreate): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:200'],
            'difficulty_tier' => ['required', 'integer', 'min:1', 'max:5'],
            'layout' => ['required', 'string'],
        ];
        if ($isCreate) {
            $rules['key'] = ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'unique:cpu_patterns,key'];
        }

        $validator = Validator::make($request->all(), $rules, [
            'key.regex' => 'keyは半角英小文字・数字・アンダースコアのみ使用できます。',
        ]);
        $validator->validate();

        $layout = json_decode($request->input('layout'), true);
        if (! is_array($layout) || json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages([
                'layout' => ['layoutは有効なJSON配列で入力してください。'],
            ]);
        }

        $errors = $this->validateLayout($layout);
        if (! empty($errors)) {
            throw ValidationException::withMessages(['layout' => $errors]);
        }

        return [
            ...($isCreate ? ['key' => $request->input('key')] : []),
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'difficulty_tier' => (int) $request->input('difficulty_tier'),
            'layout' => $layout,
        ];
    }

    /**
     * @return string[] 検出したエラーメッセージ一覧(空ならOK)
     */
    private function validateLayout(array $layout): array
    {
        $errors = [];
        $occupied = [];

        foreach ($layout as $index => $item) {
            $n = $index + 1;
            if (! is_array($item) || ! isset($item['item_type'], $item['item_key'], $item['x'], $item['y'], $item['width'], $item['height'])) {
                $errors[] = "{$n}番目: item_type/item_key/x/y/width/heightが揃っていません。";

                continue;
            }
            if (! in_array($item['item_type'], ['weapon', 'material'], true)) {
                $errors[] = "{$n}番目: item_typeはweaponまたはmaterialのみです。";

                continue;
            }

            try {
                if ($item['item_type'] === 'weapon') {
                    $this->catalog->weapon($item['item_key']);
                } else {
                    $this->catalog->material($item['item_key']);
                }
            } catch (\InvalidArgumentException) {
                $errors[] = "{$n}番目: item_key「{$item['item_key']}」が見つかりません。";

                continue;
            }

            [$x, $y, $width, $height] = [(int) $item['x'], (int) $item['y'], (int) $item['width'], (int) $item['height']];
            $gridWidth = MatchStateService::RESERVED_WIDTH;
            $gridHeight = MatchStateService::RESERVED_HEIGHT;
            if ($x < 0 || $y < 0 || $width < 1 || $height < 1 || $x + $width > $gridWidth || $y + $height > $gridHeight) {
                $errors[] = "{$n}番目: 配置がCPU用グリッド({$gridWidth}×{$gridHeight})の範囲外です。";

                continue;
            }

            for ($dx = 0; $dx < $width; $dx++) {
                for ($dy = 0; $dy < $height; $dy++) {
                    $cellKey = ($x + $dx).','.($y + $dy);
                    if (isset($occupied[$cellKey])) {
                        $errors[] = "{$n}番目: 他のアイテムとマス({$cellKey})が重なっています。";

                        continue 2;
                    }
                    $occupied[$cellKey] = true;
                }
            }
        }

        return $errors;
    }
}
