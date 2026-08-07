<?php

namespace App\Services;

/**
 * database/data/bgm.jsonへの読み取り専用アクセス。書き込みは管理ツール(tool/)側でのみ行う
 * (MasterDataRepositoryが武器等のマスタデータに対して担う役割と同じ位置づけ)。
 */
class BgmLibraryService
{
    public function activeTrackUrl(string $screen): ?string
    {
        $data = $this->read();
        $key = $data['assignments'][$screen] ?? null;
        if (! $key) {
            return null;
        }

        $track = collect($data['tracks'])->firstWhere('key', $key);
        if (! $track) {
            return null;
        }

        return asset('audio/bgm/'.$track['file']);
    }

    private function read(): array
    {
        return json_decode(file_get_contents(database_path('data/bgm.json')), true);
    }
}
