<?php

namespace Tests\Feature;

use Database\Seeders\CpuPatternSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 7-3の「完了の定義」(未使用武器が?????表示になり、使用後に開示される)を検証する。
 * 図鑑画面ワイヤーフレーム仕様5章: 伏せ表示はアイテム一覧(武器タグ)のみが対象。
 * 企画書4.2: 「使用」(自分の武器として実戦投入)・「遭遇」(対戦相手の武器として実戦投入)
 * のどちらでも開示されることを、プレイヤー武器(ボウ)・敵武器(ダガー)の両方で確認する。
 */
class EncyclopediaDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CpuPatternSeeder::class);
    }

    public function test_unused_weapons_are_masked_until_used_or_encountered_in_battle(): void
    {
        $this->postJson('/api/match/start', ['mode' => 'casual'])->assertOk();

        $entriesByName = fn (array $entries) => collect($entries)->keyBy('name');

        // 開戦前は全武器が伏せ表示(?????)になっている
        $before = $this->getJson('/api/encyclopedia/tag?tag=weapon')->assertOk()->json('entries');
        foreach ($before as $entry) {
            $this->assertSame('?????', $entry['name']);
            $this->assertSame('?????', $entry['skill_detail']);
            $this->assertNull($entry['rarity']);
        }

        // プレイヤーはボウ(bow)のみを装備してcpu_speed(ダガー使用)と対戦する
        $match = session('weapon_master_match');
        $match['placed_items'] = [[
            'id' => 'placed-bow',
            'item_type' => 'weapon',
            'item_key' => 'bow',
            'x' => 1,
            'y' => 1,
            'width' => 1,
            'height' => 3,
        ]];
        session(['weapon_master_match' => $match]);

        $this->postJson('/api/prep/start-battle', [
            'cpu_key' => 'cpu_speed',
            'confirm_synthesis' => false,
        ])->assertOk();

        $afterEntries = $this->getJson('/api/encyclopedia/tag?tag=weapon')->assertOk()->json('entries');
        $after = $entriesByName($afterEntries);

        // 使用したボウ・遭遇したダガーは開示される
        $this->assertTrue($after->has('ボウ'));
        $this->assertTrue($after->has('ダガー'));
        $this->assertNotSame('?????', $after['ボウ']['skill_detail']);

        // 対戦に登場しなかった武器(ソード・メイス・シールド)は引き続き伏せられたまま
        $stillHidden = collect($afterEntries)->filter(fn ($entry) => $entry['name'] === '?????');
        $this->assertCount(3, $stillHidden);
    }
}
