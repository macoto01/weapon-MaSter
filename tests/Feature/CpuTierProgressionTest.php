<?php

namespace Tests\Feature;

use App\Models\CpuPattern;
use Database\Seeders\CpuPatternSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 対戦相手はラウンド進行に応じて強さ段階(difficulty_tier 1〜5)が上がり、
 * 3ラウンドごとに1段階、15ラウンド目までに最大の5に達することを検証する。
 * cpu_keyを明示指定した場合は段階を無視して直接その相手と戦えることも確認する
 * (EncyclopediaDiscoveryTest等、既存テストがこの挙動に依存している)。
 */
class CpuTierProgressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CpuPatternSeeder::class);
    }

    public static function roundToTierProvider(): array
    {
        return [
            'round 1 -> tier 1' => [1, 1],
            'round 3 -> tier 1' => [3, 1],
            'round 4 -> tier 2' => [4, 2],
            'round 6 -> tier 2' => [6, 2],
            'round 7 -> tier 3' => [7, 3],
            'round 10 -> tier 4' => [10, 4],
            'round 13 -> tier 5' => [13, 5],
            'round 15 -> tier 5' => [15, 5],
        ];
    }

    #[DataProvider('roundToTierProvider')]
    public function test_opponent_tier_matches_expected_round_bracket(int $round, int $expectedTier): void
    {
        $this->postJson('/api/match/start', ['mode' => 'casual'])->assertOk();

        $match = session('weapon_master_match');
        $match['round'] = $round;
        $match['placed_items'] = [[
            'id' => 'placed-sword',
            'item_type' => 'weapon',
            'item_key' => 'sword',
            'x' => 1,
            'y' => 1,
            'width' => 1,
            'height' => 2,
        ]];
        session(['weapon_master_match' => $match]);

        $this->postJson('/api/prep/start-battle', ['confirm_synthesis' => false])->assertOk();

        $cpuKey = session('weapon_master_match')['pending_battle']['cpu_key'];

        $tierByKey = collect(
            CpuPattern::all()->pluck('difficulty_tier', 'key')
        );

        $this->assertSame($expectedTier, $tierByKey[$cpuKey]);
    }

    public function test_explicit_cpu_key_overrides_tier_selection(): void
    {
        $this->postJson('/api/match/start', ['mode' => 'casual'])->assertOk();

        $match = session('weapon_master_match');
        $match['round'] = 1; // tier 1が本来選ばれるラウンドでも、明示指定があればそちらを優先する
        $match['placed_items'] = [[
            'id' => 'placed-sword',
            'item_type' => 'weapon',
            'item_key' => 'sword',
            'x' => 1,
            'y' => 1,
            'width' => 1,
            'height' => 2,
        ]];
        session(['weapon_master_match' => $match]);

        $this->postJson('/api/prep/start-battle', [
            'cpu_key' => 'cpu_elite_guard', // tier 5
            'confirm_synthesis' => false,
        ])->assertOk();

        $this->assertSame('cpu_elite_guard', session('weapon_master_match')['pending_battle']['cpu_key']);
    }
}
