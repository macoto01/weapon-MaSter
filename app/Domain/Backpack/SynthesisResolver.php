<?php

namespace App\Domain\Backpack;

use App\Domain\MasterData\MasterDataEntry;
use App\Services\MasterDataRepository;

/**
 * 企画書 4.3節「戦闘後にバックパック内で接触しているマス同士を自動的に合成候補として扱う方式」
 * に対応する合成判定・適用ロジック。
 *
 * 隣接(接触)しているアイテム群のうち、正規のレシピ(素材/武器の組み合わせ)に
 * 一致するものを検出し、生成物の武器に置き換える。
 */
class SynthesisResolver
{
    public function __construct(private readonly MasterDataRepository $catalog) {}

    /**
     * 戦闘直後の「合成候補として提示するだけ」の検出専用呼び出し。
     * バックパックのアイテムは一切変更しない(企画書4.3: 合成の確定はバトル開始時の確認まで行わない)。
     *
     * @param  PlacedItem[]  $items
     * @return array<int, array{recipe:string, output:string, consumed: array<int,string>}>
     */
    public function detect(array $items, ?BackpackGrid $grid = null): array
    {
        return $this->resolve($items, $grid)['synthesized'];
    }

    /**
     * まだ配置していない(ショップ/武器置き場から掴んだ、またはバックパック内で移動中の)
     * アイテムを対象に、現在バックパックに置かれているアイテムとの間で成立しうる合成の
     * 組み合わせを検出する。ドラッグ中のプレビュー表示専用の読み取り専用呼び出しで、
     * 隣接判定はまだ行わない(配置位置が定まっていないため)し、バックパックの状態は変更しない。
     *
     * @param  PlacedItem[]  $items  現在バックパックに置かれているアイテム(掴んでいるアイテム自身は含めない)
     * @return array<int, array{recipe:string, output:string, partner_ids: array<int,string>}>
     */
    public function previewPartners(string $heldItemType, string $heldItemKey, array $items): array
    {
        $heldSignature = $heldItemType.':'.$heldItemKey;
        $matches = [];

        foreach ($this->catalog->recipes() as $recipe) {
            $required = collect($recipe->inputs)->map(fn ($i) => $i['type'].':'.$i['key'])->values();
            $heldIndex = $required->search($heldSignature);
            if ($heldIndex === false) {
                continue;
            }

            $remaining = $required->reject(fn ($sig, $i) => $i === $heldIndex)->values();

            $candidates = array_values(array_filter(
                $items,
                fn (PlacedItem $item) => $remaining->contains($item->itemType.':'.$item->itemKey)
            ));

            foreach ($this->combinations($candidates, $remaining->count()) as $combo) {
                $comboSignature = collect($combo)->map(fn (PlacedItem $i) => $i->itemType.':'.$i->itemKey)->sort()->values();
                if ($comboSignature->all() !== $remaining->sort()->values()->all()) {
                    continue;
                }

                $outputWeapon = $this->catalog->weapon($recipe->output_weapon_key);
                $matches[] = [
                    'recipe' => $recipe->name,
                    'output' => $outputWeapon->name,
                    'partner_ids' => collect($combo)->pluck('id')->all(),
                ];
                break; // このレシピについては最初に見つかった組み合わせのみ提示する
            }
        }

        return $matches;
    }

    /**
     * @param  PlacedItem[]  $items
     * @return array{items: PlacedItem[], synthesized: array<int, array{recipe:string, output:string, consumed: array<int,string>}>}
     */
    public function resolve(array $items, ?BackpackGrid $grid = null): array
    {
        $grid ??= new BackpackGrid;
        $byId = [];
        foreach ($items as $item) {
            $byId[$item->id] = $item;
        }

        $recipes = $this->catalog->recipes();
        $synthesizedLog = [];

        $changed = true;
        $safety = 0;
        while ($changed && $safety < 10) {
            $changed = false;
            $safety++;

            $currentItems = array_values($byId);
            $adjacency = $grid->adjacencyGraph($currentItems);

            foreach ($recipes as $recipe) {
                $match = $this->findMatch($recipe, $byId, $adjacency);
                if ($match === null) {
                    continue;
                }

                [$anchor, $consumedIds] = $match;
                $outputWeapon = $this->catalog->weapon($recipe->output_weapon_key);

                $output = new PlacedItem(
                    id: 'synth-'.$recipe->key.'-'.$anchor->x.'-'.$anchor->y.'-'.count($synthesizedLog),
                    itemType: 'weapon',
                    itemKey: $outputWeapon->key,
                    x: $anchor->x,
                    y: $anchor->y,
                    width: $outputWeapon->grid_width,
                    height: $outputWeapon->grid_height,
                );

                foreach ($consumedIds as $id) {
                    unset($byId[$id]);
                }
                $byId[$output->id] = $output;

                $synthesizedLog[] = [
                    'recipe' => $recipe->name,
                    'output' => $outputWeapon->name,
                    'consumed' => $consumedIds,
                ];

                $changed = true;
                break; // restart scan since the item set changed
            }
        }

        return [
            'items' => array_values($byId),
            'synthesized' => $synthesizedLog,
        ];
    }

    /**
     * @param  array<string, PlacedItem>  $byId
     * @param  array<string, string[]>  $adjacency
     * @return array{0: PlacedItem, 1: string[]}|null [anchor item, consumed item ids]
     */
    private function findMatch(MasterDataEntry $recipe, array $byId, array $adjacency): ?array
    {
        $required = collect($recipe->inputs)->map(fn ($i) => $i['type'].':'.$i['key']);

        $candidates = array_values(array_filter(
            $byId,
            fn (PlacedItem $item) => $required->contains($item->itemType.':'.$item->itemKey)
        ));

        foreach ($this->combinations($candidates, $required->count()) as $combo) {
            $comboSignature = collect($combo)->map(fn (PlacedItem $i) => $i->itemType.':'.$i->itemKey)->sort()->values();
            if ($comboSignature->all() !== $required->sort()->values()->all()) {
                continue;
            }

            if (! $this->isConnected($combo, $adjacency)) {
                continue;
            }

            $anchor = collect($combo)->first(fn (PlacedItem $i) => $i->itemType === 'weapon') ?? $combo[0];

            return [$anchor, collect($combo)->pluck('id')->all()];
        }

        return null;
    }

    /**
     * @param  PlacedItem[]  $items
     * @return bool 与えられたアイテム群が隣接グラフ上で連結しているか
     */
    private function isConnected(array $items, array $adjacency): bool
    {
        if (count($items) <= 1) {
            return true;
        }

        $ids = collect($items)->pluck('id')->all();
        $idSet = array_flip($ids);
        $visited = [];
        $stack = [$ids[0]];

        while ($stack) {
            $current = array_pop($stack);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach ($adjacency[$current] ?? [] as $neighbor) {
                if (isset($idSet[$neighbor]) && ! isset($visited[$neighbor])) {
                    $stack[] = $neighbor;
                }
            }
        }

        return count($visited) === count($ids);
    }

    /**
     * @param  PlacedItem[]  $items
     * @return array<int, PlacedItem[]>
     */
    private function combinations(array $items, int $size): array
    {
        if ($size <= 0) {
            return [[]];
        }
        if (count($items) < $size) {
            return [];
        }

        $results = [];
        $count = count($items);
        $this->combineRecursive($items, $size, 0, [], $results);

        return $results;
    }

    private function combineRecursive(array $items, int $size, int $start, array $current, array &$results): void
    {
        if (count($current) === $size) {
            $results[] = $current;

            return;
        }

        for ($i = $start; $i < count($items); $i++) {
            $this->combineRecursive($items, $size, $i + 1, [...$current, $items[$i]], $results);
        }
    }
}
