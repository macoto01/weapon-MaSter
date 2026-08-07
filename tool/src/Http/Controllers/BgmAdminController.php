<?php

namespace Tool\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * database/data/bgm.json(BGM曲一覧・画面ごとの割当)とpublic/audio/bgm/(音声ファイル本体)を
 * 直接読み書きする管理ツール用コントローラ。ゲーム本体側(App\Services\BgmLibraryService)は
 * 読み取り専用のため、書き込みはこの管理ツールでのみ行う(WeaponAdminControllerと同じ方針)。
 */
class BgmAdminController
{
    /** @var array<string, string> 画面キー => 表示名。BGMを割り当てられる画面はここに追加していく */
    private const SCREENS = [
        'battle' => 'バトル画面',
    ];

    public function index(): View
    {
        $data = $this->read();

        return view('tool::bgm.index', [
            'tracks' => $data['tracks'],
            'assignments' => $data['assignments'],
            'screens' => self::SCREENS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'file' => ['required', 'file', 'mimes:mp3,ogg,wav,m4a', 'max:20480'],
        ]);

        $data = $this->read();

        $key = Str::slug($validated['name']).'-'.Str::lower(Str::random(6));
        $extension = $request->file('file')->getClientOriginalExtension();
        $filename = "{$key}.{$extension}";
        $request->file('file')->move(public_path('audio/bgm'), $filename);

        $data['tracks'][] = ['key' => $key, 'name' => $validated['name'], 'file' => $filename];
        $this->write($data);

        return redirect()->route('tool.bgm.index')->with('status', "「{$validated['name']}」を追加しました。");
    }

    public function destroy(string $key): RedirectResponse
    {
        $data = $this->read();

        $track = collect($data['tracks'])->firstWhere('key', $key);
        if (! $track) {
            abort(404, "BGM {$key} が見つかりません。");
        }

        $path = public_path('audio/bgm/'.$track['file']);
        if (is_file($path)) {
            unlink($path);
        }

        $data['tracks'] = collect($data['tracks'])->reject(fn (array $t) => $t['key'] === $key)->values()->all();
        $data['assignments'] = collect($data['assignments'])
            ->map(fn (?string $assignedKey) => $assignedKey === $key ? null : $assignedKey)
            ->all();
        $this->write($data);

        return redirect()->route('tool.bgm.index')->with('status', "「{$track['name']}」を削除しました。");
    }

    public function assign(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'screen' => ['required', 'string', 'in:'.implode(',', array_keys(self::SCREENS))],
            'key' => ['nullable', 'string'],
        ]);

        $data = $this->read();

        if ($validated['key'] !== null && ! collect($data['tracks'])->contains('key', $validated['key'])) {
            abort(404, 'BGMが見つかりません。');
        }

        $data['assignments'][$validated['screen']] = $validated['key'];
        $this->write($data);

        $screenLabel = self::SCREENS[$validated['screen']];

        return redirect()->route('tool.bgm.index')->with('status', "「{$screenLabel}」の設定を更新しました。");
    }

    private function read(): array
    {
        return json_decode(file_get_contents(database_path('data/bgm.json')), true);
    }

    private function write(array $data): void
    {
        file_put_contents(
            database_path('data/bgm.json'),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
        );
    }
}
