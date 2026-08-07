<?php

namespace App\Domain\Cpu;

use App\Domain\Backpack\PlacedItem;
use App\Services\MasterDataRepository;

/**
 * CPU対戦パターンの固定構成。データは cpu_patterns テーブル(CpuPatternSeeder)から取得する。
 * 各パターンは強さ1(最弱)〜5(最強)のdifficulty_tierを持ち、MatchStateServiceが
 * ラウンド進行に応じてこのtierからランダムに対戦相手を選出する。
 */
class CpuRoster
{
    public function __construct(private readonly MasterDataRepository $catalog) {}

    /**
     * @return array<string, array{key:string, name:string, description:string, difficulty_tier:int}>
     */
    public function list(): array
    {
        return $this->catalog->cpuPatterns()->map(fn ($pattern) => [
            'key' => $pattern->key,
            'name' => "{$pattern->name} {$this->tierStars($pattern->difficulty_tier)}",
            'description' => $pattern->description,
            'difficulty_tier' => $pattern->difficulty_tier,
        ])->all();
    }

    /**
     * 強さ段階(1〜5)を★の数で表す表示用ラベル。名前に付与してバトル画面で
     * 対戦相手の強さが一目で分かるようにする(名前自体にtier番号を書き込むと
     * difficulty_tierとの二重管理になるため、表示時にここで組み立てる)。
     */
    private function tierStars(int $tier): string
    {
        return str_repeat('★', $tier).str_repeat('☆', 5 - $tier);
    }

    /**
     * @return array<string, array{key:string, name:string, description:string, difficulty_tier:int}>
     */
    public function listByTier(int $tier): array
    {
        return collect($this->list())->filter(fn (array $pattern) => $pattern['difficulty_tier'] === $tier)->all();
    }

    /**
     * @return PlacedItem[]
     */
    public function backpack(string $cpuKey): array
    {
        $pattern = $this->catalog->cpuPattern($cpuKey);

        return collect($pattern->layout)->map(fn (array $item, int $index) => new PlacedItem(
            id: "cpu-{$index}",
            itemType: $item['item_type'],
            itemKey: $item['item_key'],
            x: $item['x'],
            y: $item['y'],
            width: $item['width'],
            height: $item['height'],
        ))->all();
    }
}
