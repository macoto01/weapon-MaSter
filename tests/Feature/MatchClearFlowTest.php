<?php

namespace Tests\Feature;

use Database\Seeders\CpuPatternSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 6-3の「完了の定義」(10勝達成時にクリア表示・タイトルへの遷移分岐)を
 * 実際の対戦を10回グラインドすることなく検証する回帰テスト。
 *
 * wins=9の状態を直接セッションに仕込み、あと1勝すればmatch_statusがclearになり、
 * 次の遷移先がtitleになることを確認する。
 */
class MatchClearFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CpuPatternSeeder::class);
    }

    public function test_reaching_ten_wins_marks_match_as_clear_and_redirects_to_title(): void
    {
        $this->postJson('/api/match/start', ['mode' => 'casual'])->assertOk();

        // 9勝済みの状態を直接セッションに仕込む(10連勝をグラインドせずに境界条件を検証するため)
        $match = session('weapon_master_match');
        $match['wins'] = 9;
        session(['weapon_master_match' => $match]);

        $this->postJson('/api/prep/start-battle', [
            'cpu_key' => 'cpu_speed',
            'confirm_synthesis' => false,
        ])->assertOk();

        // このテストではプレイヤーのバックパックが空のため、必ず敗北する。
        // clear分岐は「wins」の境界のみを検証したいので、勝利を直接注入する。
        $battle = session('weapon_master_match');
        $battle['pending_battle']['result']['winner'] = 'player';
        session(['weapon_master_match' => $battle]);

        $finishResponse = $this->postJson('/api/battle/finish')->assertOk();

        $finishResponse->assertJson([
            'winner' => 'player',
            'match_status' => 'clear',
            'wins' => 10,
            'next' => 'title',
        ]);
    }

    public function test_five_losses_marks_match_as_game_over_and_redirects_to_title(): void
    {
        $this->postJson('/api/match/start', ['mode' => 'casual'])->assertOk();

        $match = session('weapon_master_match');
        $match['losses'] = 4;
        session(['weapon_master_match' => $match]);

        $this->postJson('/api/prep/start-battle', [
            'cpu_key' => 'cpu_speed',
            'confirm_synthesis' => false,
        ])->assertOk();

        $finishResponse = $this->postJson('/api/battle/finish')->assertOk();

        $finishResponse->assertJson([
            'winner' => 'enemy',
            'match_status' => 'game_over',
            'losses' => 5,
            'next' => 'title',
        ]);
    }
}
