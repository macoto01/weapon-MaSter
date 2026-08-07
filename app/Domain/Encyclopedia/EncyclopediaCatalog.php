<?php

namespace App\Domain\Encyclopedia;

use App\Domain\Combat\EffectRegistry;
use App\Services\MasterDataRepository;
use App\Services\MatchStateService;

/**
 * 図鑑画面ワイヤーフレーム仕様 3章「タグ一覧」に対応する参照用クラス。
 * 検証範囲には「武器」「合成」タグのデータのみ存在するため、
 * 他タグ(防具/ライセンス/秘宝)はプレースホルダーとして返す。
 * 伏せ表示(?????)は図鑑画面ワイヤーフレーム仕様5章に従い、アイテム一覧(武器タグ)のみに適用する。
 * 合成タグは事前の戦略立案に使う情報(企画書4.2)のため伏せない。
 */
class EncyclopediaCatalog
{
    public const TAGS = [
        ['key' => 'weapon', 'label' => '武器', 'available' => true],
        ['key' => 'armor', 'label' => '防具', 'available' => false],
        ['key' => 'license', 'label' => 'ライセンス', 'available' => false],
        ['key' => 'relic', 'label' => '秘宝', 'available' => false],
        ['key' => 'recipe', 'label' => '合成', 'available' => true],
    ];

    private const HIDDEN_LABEL = '?????';

    public function __construct(
        private readonly MasterDataRepository $catalog,
        private readonly MatchStateService $match,
    ) {}

    public function tags(): array
    {
        return self::TAGS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function entriesForTag(string $tag): array
    {
        return match ($tag) {
            'weapon' => $this->catalog->weapons()
                ->reject(fn ($w) => $w->is_synthesized)
                ->map(function ($w) {
                    $discovered = in_array($w->key, $this->match->discoveredWeaponKeys(), true);

                    if (! $discovered) {
                        return [
                            'name' => self::HIDDEN_LABEL,
                            'stat' => self::HIDDEN_LABEL,
                            'detail' => self::HIDDEN_LABEL,
                            'price' => null,
                            'rarity' => null,
                            'skill_detail' => self::HIDDEN_LABEL,
                            'image_url' => null,
                        ];
                    }

                    return [
                        'name' => $w->name,
                        'stat' => "ATK{$w->atk} / SPD".($w->spd ?? '-'),
                        'detail' => $w->role,
                        'price' => $w->price,
                        'rarity' => $w->rarity,
                        'skill_detail' => EffectRegistry::describe($w->special_effect_key),
                        'image_url' => $w->image_url,
                    ];
                })->values()->all(),
            'recipe' => $this->catalog->recipes()->map(function ($r) {
                $inputLabels = collect($r->inputs)->map(function ($i) {
                    $meta = $i['type'] === 'weapon' ? $this->catalog->weapon($i['key']) : $this->catalog->material($i['key']);

                    return $meta->name;
                })->implode(' + ');

                $output = $this->catalog->weapon($r->output_weapon_key);

                return [
                    'name' => $r->name,
                    'stat' => $inputLabels,
                    'detail' => "生成物: {$output->name}",
                    'price' => null,
                    'rarity' => $output->rarity,
                    'skill_detail' => EffectRegistry::describe($output->special_effect_key),
                    'image_url' => $output->image_url,
                ];
            })->values()->all(),
            default => [],
        };
    }
}
