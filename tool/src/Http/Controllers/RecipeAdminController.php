<?php

namespace Tool\Http\Controllers;

use App\Services\MasterDataRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Tool\Support\TsvParser;

/**
 * database/data/recipes.json を直接読み書きする管理ツール用コントローラ。
 * レシピkeyは他のマスタデータから参照されない(CPUパターンや他レシピはweapon/material keyしか
 * 持たない)ため、WeaponAdminController等と異なり削除前の参照チェックは不要。
 */
class RecipeAdminController
{
    /** @var string[] Excel貼り付けデータの見出し行に必須の列名。inputsは"type:key;type:key"形式の1セル */
    private const IMPORT_REQUIRED_HEADERS = ['key', 'name', 'inputs', 'output_weapon_key'];

    public function __construct(private readonly MasterDataRepository $catalog) {}

    public function index(): View
    {
        return view('tool::recipes.index', [
            'recipes' => $this->catalog->recipes(),
        ]);
    }

    public function edit(string $key): View
    {
        return view('tool::recipes.edit', [
            'recipe' => $this->findOrFail($key),
            'weaponKeys' => $this->catalog->weapons()->keys(),
            'materialKeys' => $this->catalog->materials()->keys(),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $this->findOrFail($key);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'output_weapon_key' => ['required', 'string'],
            'inputs' => ['required', 'array', 'min:1'],
            'inputs.*.type' => ['required', 'string', 'in:weapon,material'],
            'inputs.*.key' => ['required', 'string'],
        ]);

        $errors = $this->validateReferences($validated['output_weapon_key'], $validated['inputs']);
        if (! empty($errors)) {
            throw ValidationException::withMessages(['inputs' => $errors]);
        }

        $rows = $this->readRows();

        $found = false;
        foreach ($rows as &$row) {
            if ($row['key'] === $key) {
                $row = [
                    'key' => $key,
                    'name' => $validated['name'],
                    'inputs' => array_map(fn (array $input) => ['type' => $input['type'], 'key' => $input['key']], $validated['inputs']),
                    'output_weapon_key' => $validated['output_weapon_key'],
                ];
                $found = true;
                break;
            }
        }
        unset($row);

        if (! $found) {
            abort(404, "合成レシピ {$key} が見つかりません。");
        }

        $this->writeRows($rows);

        return redirect()->route('tool.recipes.index')->with('status', "「{$validated['name']}」を更新しました。");
    }

    public function destroy(string $key): RedirectResponse
    {
        $recipe = $this->findOrFail($key);

        $rows = collect($this->readRows())->reject(fn (array $row) => $row['key'] === $key)->values()->all();
        $this->writeRows($rows);

        return redirect()->route('tool.recipes.index')->with('status', "「{$recipe->name}」を削除しました。");
    }

    /**
     * 現在のマスタデータをTSVファイルとしてダウンロードする。インポート機能と同じ列構成・
     * inputsのtype:key;type:key記法のため、出力したファイルをExcelで編集してそのまま
     * インポートに貼り戻せる。
     */
    public function export(): Response
    {
        $rows = $this->catalog->recipes()->map(fn ($recipe) => [
            'key' => $recipe->key,
            'name' => $recipe->name,
            'inputs' => collect($recipe->inputs)->map(fn (array $input) => "{$input['type']}:{$input['key']}")->implode(';'),
            'output_weapon_key' => $recipe->output_weapon_key,
        ])->all();

        return response(TsvParser::build(['key', 'name', 'inputs', 'output_weapon_key'], $rows), 200, [
            'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="recipes.tsv"',
        ]);
    }

    public function importForm(): View
    {
        return view('tool::recipes.import', ['preview' => null, 'raw' => '']);
    }

    /**
     * Excelから貼り付けたTSVデータをプレビューし、確認済み(confirmed=1)かつエラーがなければ反映する。
     * key一致行は更新、新規keyは追加、貼り付けデータに無い既存行はそのまま残す。
     * inputs列は "weapon:sword;material:whetstone" のように type:key を ; 区切りで並べたセルとして扱う。
     */
    public function import(Request $request): View|RedirectResponse
    {
        $raw = (string) $request->input('data', '');
        $confirmed = $request->boolean('confirmed');

        $preview = $this->buildImportPreview($raw);

        if ($confirmed && $preview['global_error'] === null && $preview['error_count'] === 0 && $preview['row_count'] > 0) {
            $this->applyImport($preview['rows']);

            return redirect()->route('tool.recipes.index')
                ->with('status', "貼り付けデータを反映しました(新規{$preview['insert_count']}件・更新{$preview['update_count']}件)。");
        }

        return view('tool::recipes.import', ['preview' => $preview, 'raw' => $raw]);
    }

    private function findOrFail(string $key)
    {
        $recipe = $this->catalog->recipes()->firstWhere('key', $key);
        if (! $recipe) {
            abort(404, "合成レシピ {$key} が見つかりません。");
        }

        return $recipe;
    }

    /**
     * @param  array<int, array{type:string,key:string}>  $inputs
     * @return string[]
     */
    private function validateReferences(string $outputWeaponKey, array $inputs): array
    {
        $errors = [];

        if (! $this->catalog->weapons()->has($outputWeaponKey)) {
            $errors[] = "完成する武器「{$outputWeaponKey}」が見つかりません。";
        }

        foreach ($inputs as $i => $input) {
            $n = $i + 1;
            $exists = $input['type'] === 'weapon'
                ? $this->catalog->weapons()->has($input['key'])
                : $this->catalog->materials()->has($input['key']);

            if (! $exists) {
                $errors[] = "{$n}番目の素材key「{$input['key']}」が見つかりません。";
            }
        }

        return $errors;
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

        $existingKeys = $this->catalog->recipes()->pluck('key')->all();
        $seenKeys = [];
        $parsedRows = [];
        $insertCount = 0;
        $updateCount = 0;
        $errorCount = 0;

        foreach ($rawRows as $i => $row) {
            $errors = [];
            $key = $row['key'] ?? '';
            $name = $row['name'] ?? '';

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

            $outputWeaponKey = $row['output_weapon_key'] ?? '';
            if ($outputWeaponKey === '') {
                $errors[] = '完成する武器keyが空です。';
            }

            $inputs = $this->parseInputsCell($row['inputs'] ?? '');
            if ($inputs === null) {
                $errors[] = 'inputs列は "weapon:sword;material:whetstone" のように type:key を;区切りで指定してください。';
                $inputs = [];
            } elseif (empty($inputs)) {
                $errors[] = 'inputsは最低1件必要です。';
            }

            $data = null;
            if (empty($errors)) {
                $refErrors = $this->validateReferences($outputWeaponKey, $inputs);
                $errors = [...$errors, ...$refErrors];
            }
            if (empty($errors)) {
                $data = [
                    'name' => $name,
                    'inputs' => $inputs,
                    'output_weapon_key' => $outputWeaponKey,
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
     * @return array<int, array{type:string,key:string}>|null 形式が不正な場合はnull
     */
    private function parseInputsCell(string $cell): ?array
    {
        $cell = trim($cell);
        if ($cell === '') {
            return [];
        }

        $inputs = [];
        foreach (explode(';', $cell) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $segments = explode(':', $part);
            if (count($segments) !== 2 || ! in_array($segments[0], ['weapon', 'material'], true) || $segments[1] === '') {
                return null;
            }
            $inputs[] = ['type' => $segments[0], 'key' => $segments[1]];
        }

        return $inputs;
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
        return json_decode(file_get_contents(database_path('data/recipes.json')), true) ?? [];
    }

    private function writeRows(array $rows): void
    {
        file_put_contents(
            database_path('data/recipes.json'),
            json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
        );
    }
}
