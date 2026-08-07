<?php

namespace App\Domain\Rank;

/**
 * 企画書4.7(ランク制度)の8階級と昇格ポイント(仮数値)を定義する参照用クラス。
 * 「昇格に必要なポイント数」は階級ごとの進捗(階級が上がるとリセットされる)として扱う。
 * 敗北してもポイントは減らない(降格なし)ため、加算のみを呼び出し側で行う想定。
 */
class RankTable
{
    /**
     * @var array<int, array{key:string,label:string,threshold:?int}>
     */
    private const TIERS = [
        ['key' => 'bronze', 'label' => 'ブロンズ', 'threshold' => 5],
        ['key' => 'silver', 'label' => 'シルバー', 'threshold' => 8],
        ['key' => 'gold', 'label' => 'ゴールド', 'threshold' => 12],
        ['key' => 'platinum', 'label' => 'プラチナ', 'threshold' => 17],
        ['key' => 'diamond', 'label' => 'ダイヤモンド', 'threshold' => 23],
        ['key' => 'master', 'label' => 'マスター', 'threshold' => 30],
        ['key' => 'grandmaster', 'label' => 'グランドマスター', 'threshold' => 40],
        ['key' => 'challenger', 'label' => 'チャレンジャー', 'threshold' => null],
    ];

    /**
     * 総獲得ポイントから現在の階級・階級内の進捗を算出する。
     *
     * @return array{tier_index:int,tier_key:string,tier_label:string,points_in_tier:int,points_to_next:?int,total_points:int}
     */
    public function resolve(int $totalPoints): array
    {
        $remaining = $totalPoints;

        foreach (self::TIERS as $index => $tier) {
            if ($tier['threshold'] === null || $remaining < $tier['threshold']) {
                return [
                    'tier_index' => $index,
                    'tier_key' => $tier['key'],
                    'tier_label' => $tier['label'],
                    'points_in_tier' => $remaining,
                    'points_to_next' => $tier['threshold'] === null ? null : $tier['threshold'] - $remaining,
                    'total_points' => $totalPoints,
                ];
            }
            $remaining -= $tier['threshold'];
        }

        $last = self::TIERS[count(self::TIERS) - 1];

        return [
            'tier_index' => count(self::TIERS) - 1,
            'tier_key' => $last['key'],
            'tier_label' => $last['label'],
            'points_in_tier' => $remaining,
            'points_to_next' => null,
            'total_points' => $totalPoints,
        ];
    }
}
