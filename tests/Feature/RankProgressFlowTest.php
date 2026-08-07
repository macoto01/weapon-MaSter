<?php

namespace Tests\Feature;

use Database\Seeders\CpuPatternSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 6-4の「完了の定義」(勝利するたびにランクポイントが正しく加算され、
 * バトル準備画面に戻った際に反映される)を検証する。
 */
class RankProgressFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CpuPatternSeeder::class);
    }

    public function test_rank_match_win_increments_rank_points_and_is_reflected_in_prep_state(): void
    {
        $this->postJson('/api/match/start', ['mode' => 'rank'])->assertOk();

        $this->postJson('/api/prep/start-battle', [
            'cpu_key' => 'cpu_speed',
            'confirm_synthesis' => false,
        ])->assertOk();

        $battle = session('weapon_master_match');
        $battle['pending_battle']['result']['winner'] = 'player';
        session(['weapon_master_match' => $battle]);

        $this->postJson('/api/battle/finish')->assertOk();

        $state = $this->getJson('/api/prep/state')->assertOk()->json();

        $this->assertNotNull($state['rank']);
        $this->assertSame('bronze', $state['rank']['tier_key']);
        $this->assertSame(1, $state['rank']['points_in_tier']);
    }

    public function test_casual_match_win_does_not_grant_rank_points(): void
    {
        $this->postJson('/api/match/start', ['mode' => 'casual'])->assertOk();

        $this->postJson('/api/prep/start-battle', [
            'cpu_key' => 'cpu_speed',
            'confirm_synthesis' => false,
        ])->assertOk();

        $battle = session('weapon_master_match');
        $battle['pending_battle']['result']['winner'] = 'player';
        session(['weapon_master_match' => $battle]);

        $this->postJson('/api/battle/finish')->assertOk();

        $state = $this->getJson('/api/prep/state')->assertOk()->json();

        $this->assertNull($state['rank']);
        $this->assertSame(0, session('weapon_master_rank_points', 0));
    }

    public function test_rank_points_persist_across_a_new_match_start(): void
    {
        $this->postJson('/api/match/start', ['mode' => 'rank'])->assertOk();
        $this->postJson('/api/prep/start-battle', [
            'cpu_key' => 'cpu_speed',
            'confirm_synthesis' => false,
        ])->assertOk();
        $battle = session('weapon_master_match');
        $battle['pending_battle']['result']['winner'] = 'player';
        session(['weapon_master_match' => $battle]);
        $this->postJson('/api/battle/finish')->assertOk();

        // 新しいマッチ(ラン)を開始してもランクポイントは持ち越される
        $this->postJson('/api/match/start', ['mode' => 'rank'])->assertOk();
        $state = $this->getJson('/api/prep/state')->assertOk()->json();

        $this->assertSame(1, $state['rank']['points_in_tier']);
    }
}
