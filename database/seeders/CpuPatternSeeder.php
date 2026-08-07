<?php

namespace Database\Seeders;

use App\Models\CpuPattern;
use Illuminate\Database\Seeder;

/**
 * CPU対戦パターンのマスタデータ。強さを1(最弱)〜5(最強)のdifficulty_tierで表し、
 * MatchStateServiceがラウンド進行(3ラウンドごとにtier+1、最大5)に応じてこの中から
 * 対戦相手を選出する。座標は各素材が狙いの武器に隣接するようにあらかじめ配置してある。
 */
class CpuPatternSeeder extends Seeder
{
    public function run(): void
    {
        $patterns = [
            // --- tier 1: 基礎武器中心。最低限のバフのみ ---
            [
                'key' => 'cpu_speed',
                'name' => 'CPU-速攻',
                'description' => 'ダガー+鎖+ダガー+皮革。手数の多さと底上げしたHPで押し切るタイプ。',
                'difficulty_tier' => 1,
                'layout' => [
                    ['item_type' => 'weapon', 'item_key' => 'dagger', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'chain', 'x' => 1, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'weapon', 'item_key' => 'dagger', 'x' => 2, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'leather', 'x' => 0, 'y' => 1, 'width' => 1, 'height' => 1],
                ],
            ],
            [
                'key' => 'cpu_lone_sword',
                'name' => 'CPU-見習い',
                'description' => 'ソード+鎖(隣接)+皮革。単体武器に軽い底上げを加えた最弱構成。',
                'difficulty_tier' => 1,
                'layout' => [
                    ['item_type' => 'weapon', 'item_key' => 'sword', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 2],
                    ['item_type' => 'material', 'item_key' => 'chain', 'x' => 1, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'leather', 'x' => 2, 'y' => 0, 'width' => 1, 'height' => 1],
                ],
            ],

            // --- tier 2: 基礎武器+隣接バフを複数併用 ---
            [
                'key' => 'cpu_balance',
                'name' => 'CPU-バランス',
                'description' => 'ソード+ダガー+シールド(隣接)+砥石+皮革。シールドと砥石の二重ATKバフを持つ安定型。',
                'difficulty_tier' => 2,
                'layout' => [
                    ['item_type' => 'weapon', 'item_key' => 'sword', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 2],
                    ['item_type' => 'weapon', 'item_key' => 'shield', 'x' => 1, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'weapon', 'item_key' => 'dagger', 'x' => 1, 'y' => 1, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'whetstone', 'x' => 2, 'y' => 1, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'leather', 'x' => 3, 'y' => 0, 'width' => 1, 'height' => 1],
                ],
            ],
            [
                'key' => 'cpu_archer',
                'name' => 'CPU-弓兵',
                'description' => 'ボウ+砥石(隣接)+鎖(隣接)。ATK・SPD二重バフで発生の遅さを補う遠距離型。',
                'difficulty_tier' => 2,
                'layout' => [
                    ['item_type' => 'weapon', 'item_key' => 'bow', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 3],
                    ['item_type' => 'material', 'item_key' => 'whetstone', 'x' => 1, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'chain', 'x' => 1, 'y' => 1, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'leather', 'x' => 1, 'y' => 2, 'width' => 1, 'height' => 1],
                ],
            ],

            // --- tier 3: 高火力武器+複数バフ、または合成武器を絡めた構成 ---
            [
                'key' => 'cpu_heavy',
                'name' => 'CPU-重火力',
                'description' => 'メイス+シールド(隣接)+砥石(隣接)+皮革。ATK二重バフで一撃の重さを増した高火力型。',
                'difficulty_tier' => 3,
                'layout' => [
                    ['item_type' => 'weapon', 'item_key' => 'mace', 'x' => 0, 'y' => 0, 'width' => 2, 'height' => 2],
                    ['item_type' => 'weapon', 'item_key' => 'shield', 'x' => 2, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'whetstone', 'x' => 2, 'y' => 1, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'leather', 'x' => 3, 'y' => 0, 'width' => 1, 'height' => 1],
                ],
            ],
            [
                'key' => 'cpu_twin_dagger',
                'name' => 'CPU-双撃',
                'description' => '双撃の刃×2+鎖(隣接)+砥石×2(隣接)。両ダブルヒット武器をATK・SPD共に強化した手数型。',
                'difficulty_tier' => 3,
                'layout' => [
                    ['item_type' => 'weapon', 'item_key' => 'twin_strike_blade', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'chain', 'x' => 1, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'weapon', 'item_key' => 'twin_strike_blade', 'x' => 2, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'whetstone', 'x' => 0, 'y' => 1, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'whetstone', 'x' => 2, 'y' => 1, 'width' => 1, 'height' => 1],
                ],
            ],

            // --- tier 4: 合成武器+複数バフを組み合わせた準上位構成 ---
            [
                'key' => 'cpu_sharp_shield',
                'name' => 'CPU-精鋭剣士',
                'description' => '鋭利な剣+名匠の盾(隣接)+砥石(隣接)+皮革。ATK三重・SPD二重バフを持つ準上位構成。',
                'difficulty_tier' => 4,
                'layout' => [
                    ['item_type' => 'weapon', 'item_key' => 'sharp_sword', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 2],
                    ['item_type' => 'weapon', 'item_key' => 'masterwork_shield', 'x' => 1, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'leather', 'x' => 2, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'whetstone', 'x' => 0, 'y' => 2, 'width' => 1, 'height' => 1],
                ],
            ],
            [
                'key' => 'cpu_heavy_mace',
                'name' => 'CPU-重装突撃',
                'description' => '重装メイス+砥石+鎖(隣接)+皮革。高火力かつ発生を早めた重量型に耐久も追加。',
                'difficulty_tier' => 4,
                'layout' => [
                    ['item_type' => 'weapon', 'item_key' => 'heavy_mace', 'x' => 0, 'y' => 0, 'width' => 2, 'height' => 2],
                    ['item_type' => 'material', 'item_key' => 'whetstone', 'x' => 2, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'chain', 'x' => 2, 'y' => 1, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'leather', 'x' => 3, 'y' => 0, 'width' => 1, 'height' => 1],
                ],
            ],

            // --- tier 5: 合成武器を複数集約し、多重バフを乗せた最上位構成 ---
            [
                'key' => 'cpu_elite_guard',
                'name' => 'CPU-精鋭護衛',
                'description' => '名匠の盾を中心に鋭利な剣・双撃の刃・砥石・鎖を集約した最上位構成。複数武器が同時に多重バフを受ける。',
                'difficulty_tier' => 5,
                'layout' => [
                    ['item_type' => 'weapon', 'item_key' => 'sharp_sword', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 2],
                    ['item_type' => 'weapon', 'item_key' => 'masterwork_shield', 'x' => 1, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'weapon', 'item_key' => 'twin_strike_blade', 'x' => 2, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'whetstone', 'x' => 1, 'y' => 1, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'chain', 'x' => 2, 'y' => 1, 'width' => 1, 'height' => 1],
                ],
            ],
            [
                'key' => 'cpu_synthesis_squad',
                'name' => 'CPU-合成軍団',
                'description' => '重装メイス+名匠の盾+双撃の刃(隣接)+皮革+砥石。合成武器を揃えた最終試練。',
                'difficulty_tier' => 5,
                'layout' => [
                    ['item_type' => 'weapon', 'item_key' => 'heavy_mace', 'x' => 0, 'y' => 0, 'width' => 2, 'height' => 2],
                    ['item_type' => 'weapon', 'item_key' => 'masterwork_shield', 'x' => 2, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'weapon', 'item_key' => 'twin_strike_blade', 'x' => 2, 'y' => 1, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'leather', 'x' => 3, 'y' => 0, 'width' => 1, 'height' => 1],
                    ['item_type' => 'material', 'item_key' => 'whetstone', 'x' => 3, 'y' => 1, 'width' => 1, 'height' => 1],
                ],
            ],
        ];

        foreach ($patterns as $pattern) {
            CpuPattern::query()->updateOrCreate(['key' => $pattern['key']], $pattern);
        }
    }
}
