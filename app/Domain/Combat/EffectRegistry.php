<?php

namespace App\Domain\Combat;

/**
 * Resolves a weapon's special_effect_key into a behavior descriptor.
 *
 * 技術要件定義 3.1: 「special_effect_key(特殊効果を識別するキー。ロジックはPHP側の効果クラスで処理)」
 * に対応する、武器の特殊効果を集中管理する登録簿。
 */
class EffectRegistry
{
    public const NO_ATTACK_WINDOW_1S = 'no_attack_window_1s';

    public const ADJACENT_ATK_BUFF_20 = 'adjacent_atk_buff_20';

    public const DOUBLE_HIT = 'double_hit';

    public const BONUS_HP_20 = 'bonus_hp_20';

    public const ADJACENT_ATK_SPD_BUFF = 'adjacent_atk_buff_20_spd_buff_30';

    /**
     * @return array<int, array{stat: string, percent: int}> buffs this weapon emits to adjacent weapons
     */
    public static function adjacentBuffs(?string $effectKey): array
    {
        return match ($effectKey) {
            self::ADJACENT_ATK_BUFF_20 => [
                ['stat' => 'atk', 'percent' => 20],
            ],
            self::ADJACENT_ATK_SPD_BUFF => [
                ['stat' => 'atk', 'percent' => 20],
                ['stat' => 'spd', 'percent' => 30],
            ],
            default => [],
        };
    }

    public static function noAttackWindowSeconds(?string $effectKey): float
    {
        return $effectKey === self::NO_ATTACK_WINDOW_1S ? 1.0 : 0.0;
    }

    public static function isDoubleHit(?string $effectKey): bool
    {
        return $effectKey === self::DOUBLE_HIT;
    }

    public static function bonusHp(?string $effectKey): int
    {
        return $effectKey === self::BONUS_HP_20 ? 20 : 0;
    }

    /**
     * 図鑑画面の「スキル詳細」欄に表示する、効果の説明文。
     */
    public static function describe(?string $effectKey): string
    {
        return match ($effectKey) {
            self::NO_ATTACK_WINDOW_1S => '戦闘開始から1秒間は攻撃できない',
            self::ADJACENT_ATK_BUFF_20 => '隣接する武器のATKを+20%する',
            self::DOUBLE_HIT => '1回の攻撃判定で2回分のダメージを与える',
            self::BONUS_HP_20 => '配置しているだけでプレイヤーのHPを+20する',
            self::ADJACENT_ATK_SPD_BUFF => '隣接する武器のATKを+20%、SPDを+30%する',
            default => '特殊効果なし',
        };
    }

    /**
     * 素材のeffect_type/effect_value/effect_rangeから効果の説明文を組み立てる
     * (旧App\Models\Material::getEffectDescriptionAttribute()から移設)。
     * 素材はspecial_effect_keyを持たず、この3値の組み合わせが直接効果を表す。
     */
    public static function describeMaterial(string $effectType, int $effectValue, string $effectRange): string
    {
        $subject = $effectRange === 'global' ? '配置しているだけで' : '隣接する武器の';

        return match ($effectType) {
            'buff_atk' => "{$subject}ATKを+{$effectValue}%する",
            'buff_spd' => "{$subject}SPDを+{$effectValue}%する",
            'buff_hp' => "{$subject}プレイヤーのHPを+{$effectValue}する",
            default => '特殊効果なし',
        };
    }
}
