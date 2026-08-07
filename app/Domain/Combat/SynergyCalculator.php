<?php

namespace App\Domain\Combat;

use App\Domain\Backpack\BackpackGrid;
use App\Domain\Backpack\PlacedItem;
use App\Services\MasterDataRepository;

/**
 * 検証用ゲームデザイン仕様書 3章(素材の隣接効果)・5章(武器の隣接効果)に対応する
 * シナジー計算。バックパックの配置から、各武器の実効ATK/SPDとプレイヤーの追加HPを求める。
 */
class SynergyCalculator
{
    public function __construct(private readonly MasterDataRepository $catalog) {}

    /**
     * まだ配置していない(ドラッグ中の)アイテムを対象に、今つかんでいる位置(x,y,width,height)に
     * 置いたとして隣接することになるバックパック内アイテムそれぞれについて、
     * シナジー(隣接効果)が成立するか(○)しないか(×)を判定する。
     * ドラッグ中のプレビュー表示専用の読み取り専用呼び出しで、バックパックの状態は変更しない。
     *
     * @param  PlacedItem[]  $items  現在バックパックに置かれているアイテム(掴んでいるアイテム自身は含めない)
     * @return array<int, array{item_id:string, satisfied:bool}>
     */
    public function previewNeighborJudgement(
        string $heldItemType,
        string $heldItemKey,
        int $x,
        int $y,
        int $width,
        int $height,
        array $items,
        ?BackpackGrid $grid = null
    ): array {
        $grid ??= new BackpackGrid;
        $held = new PlacedItem('__held__', $heldItemType, $heldItemKey, $x, $y, $width, $height);
        $adjacency = $grid->adjacencyGraph([...$items, $held]);
        $neighborIds = $adjacency['__held__'] ?? [];

        $itemsById = collect($items)->keyBy('id');
        $heldEmits = ! empty($this->emittedBuffs($heldItemType, $heldItemKey));

        return collect($neighborIds)
            ->map(function ($neighborId) use ($itemsById, $heldEmits) {
                $neighbor = $itemsById->get($neighborId);
                if (! $neighbor) {
                    return null;
                }
                $neighborEmits = ! empty($this->emittedBuffs($neighbor->itemType, $neighbor->itemKey));

                return ['item_id' => $neighborId, 'satisfied' => $heldEmits || $neighborEmits];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{stat:string, percent:int|float}>
     */
    private function emittedBuffs(string $itemType, string $itemKey): array
    {
        if ($itemType === 'material') {
            $material = $this->catalog->material($itemKey);
            if ($material->effect_range !== 'adjacent') {
                return [];
            }
            $stat = match ($material->effect_type) {
                'buff_atk' => 'atk',
                'buff_spd' => 'spd',
                default => null,
            };

            return $stat !== null ? [['stat' => $stat, 'percent' => $material->effect_value]] : [];
        }

        $weapon = $this->catalog->weapon($itemKey);

        return EffectRegistry::adjacentBuffs($weapon->special_effect_key);
    }

    /**
     * @param  PlacedItem[]  $items
     * @return array{attackers: array<int, array<string, mixed>>, bonus_hp: int, buff_map: array<string, array<int, array<string,mixed>>>}
     */
    public function calculate(array $items, ?BackpackGrid $grid = null): array
    {
        $grid ??= new BackpackGrid;
        $adjacency = $grid->adjacencyGraph($items);
        $itemsById = collect($items)->keyBy('id');

        // 1. 各アイテムが発する隣接バフ・全体バフを集約する
        $adjacentBuffEmissions = []; // item id => [['stat'=>..,'percent'=>..], ...]
        $bonusHp = 0;

        foreach ($items as $item) {
            if ($item->itemType === 'material') {
                $material = $this->catalog->material($item->itemKey);
                if ($material->effect_range === 'global') {
                    if ($material->effect_type === 'buff_hp') {
                        $bonusHp += $material->effect_value;
                    }

                    continue;
                }

                $stat = match ($material->effect_type) {
                    'buff_atk' => 'atk',
                    'buff_spd' => 'spd',
                    default => null,
                };
                if ($stat !== null) {
                    $adjacentBuffEmissions[$item->id][] = ['stat' => $stat, 'percent' => $material->effect_value];
                }
            } else {
                $weapon = $this->catalog->weapon($item->itemKey);
                $bonusHp += EffectRegistry::bonusHp($weapon->special_effect_key);
                $buffs = EffectRegistry::adjacentBuffs($weapon->special_effect_key);
                if (! empty($buffs)) {
                    $adjacentBuffEmissions[$item->id] = $buffs;
                }
            }
        }

        // 2. 各武器アイテムが、隣接するアイテムから受け取るバフを集計する
        $buffMap = []; // 受け取る側 item id => [['from'=>id,'stat'=>..,'percent'=>..], ...]
        foreach ($adjacentBuffEmissions as $sourceId => $buffs) {
            foreach ($adjacency[$sourceId] ?? [] as $neighborId) {
                foreach ($buffs as $buff) {
                    $buffMap[$neighborId][] = ['from' => $sourceId, 'stat' => $buff['stat'], 'percent' => $buff['percent']];
                }
            }
        }

        // 3. 武器ごとの実効ステータスを算出する(ATK>0のもののみ攻撃者として扱う)
        $attackers = [];
        foreach ($items as $item) {
            if ($item->itemType !== 'weapon') {
                continue;
            }
            $weapon = $this->catalog->weapon($item->itemKey);
            if ((int) $weapon->atk <= 0) {
                continue;
            }

            $atkPercent = 0;
            $spdPercent = 0;
            foreach ($buffMap[$item->id] ?? [] as $buff) {
                if ($buff['stat'] === 'atk') {
                    $atkPercent += $buff['percent'];
                } elseif ($buff['stat'] === 'spd') {
                    $spdPercent += $buff['percent'];
                }
            }

            $effectiveAtk = $weapon->atk * (1 + $atkPercent / 100);
            $effectiveSpd = $weapon->spd * (1 + $spdPercent / 100);

            $attackers[] = [
                'placed_item_id' => $item->id,
                'item_key' => $item->itemKey,
                'name' => $weapon->name,
                'base_atk' => (int) $weapon->atk,
                'base_spd' => (int) $weapon->spd,
                'effective_atk' => round($effectiveAtk, 2),
                'effective_spd' => round($effectiveSpd, 2),
                'atk_buff_percent' => $atkPercent,
                'spd_buff_percent' => $spdPercent,
                'double_hit' => EffectRegistry::isDoubleHit($weapon->special_effect_key),
                'no_attack_window' => EffectRegistry::noAttackWindowSeconds($weapon->special_effect_key),
                'stamina_cost' => $weapon->stamina_cost !== null ? (int) $weapon->stamina_cost : 0,
                'cooldown_seconds' => $weapon->cooldown_seconds !== null ? (float) $weapon->cooldown_seconds : null,
            ];
        }

        return [
            'attackers' => $attackers,
            'bonus_hp' => $bonusHp,
            'buff_map' => $buffMap,
        ];
    }
}
