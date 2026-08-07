<?php

namespace App\Domain\Backpack;

/**
 * バトル準備画面ワイヤーフレーム仕様 2章: バックパックは8×7マスの予約エリアを持ち、
 * そのうち「解放済み」のマスにのみ配置できる(初期は4×3マスのみ解放)。
 * CPU側の固定バックパックなど解放制御が不要な場合は $unlockedCells を渡さず、
 * width×height の矩形全体を配置可能として扱う。
 * 配置バリデーション(範囲外・未解放・重なり判定)と、隣接(接触)グラフの構築を担当する。
 */
class BackpackGrid
{
    public function __construct(
        public readonly int $width = 4,
        public readonly int $height = 3,
    ) {}

    /**
     * @param  PlacedItem[]  $existingItems
     * @param  array<int, array{0:int,1:int}>|null  $unlockedCells  解放済みマス。nullなら制限なし(bounds+重なりのみ判定)
     */
    public function canPlace(array $existingItems, PlacedItem $candidate, ?string $ignoreId = null, ?array $unlockedCells = null): bool
    {
        $unlockedSet = $unlockedCells === null ? null : $this->toCellSet($unlockedCells);

        foreach ($candidate->occupiedCells() as [$cx, $cy]) {
            if ($cx < 0 || $cy < 0 || $cx >= $this->width || $cy >= $this->height) {
                return false;
            }
            if ($unlockedSet !== null && ! isset($unlockedSet[$cx.':'.$cy])) {
                return false;
            }
        }

        $occupied = [];
        foreach ($existingItems as $item) {
            if ($ignoreId !== null && $item->id === $ignoreId) {
                continue;
            }
            foreach ($item->occupiedCells() as [$cx, $cy]) {
                $occupied[$cx.':'.$cy] = true;
            }
        }

        foreach ($candidate->occupiedCells() as [$cx, $cy]) {
            if (isset($occupied[$cx.':'.$cy])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 各アイテムについて「グリッド上で辺を共有して接触している」隣接アイテムIDの一覧を返す。
     *
     * @param  PlacedItem[]  $items
     * @return array<string, string[]> item id => 隣接している item id の配列
     */
    public function adjacencyGraph(array $items): array
    {
        $cellOwners = [];
        foreach ($items as $item) {
            foreach ($item->occupiedCells() as [$cx, $cy]) {
                $cellOwners[$cx.':'.$cy] = $item->id;
            }
        }

        $graph = [];
        foreach ($items as $item) {
            $neighbors = [];
            foreach ($item->occupiedCells() as [$cx, $cy]) {
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    $key = ($cx + $dx).':'.($cy + $dy);
                    if (! isset($cellOwners[$key])) {
                        continue;
                    }
                    $neighborId = $cellOwners[$key];
                    if ($neighborId !== $item->id) {
                        $neighbors[$neighborId] = true;
                    }
                }
            }
            $graph[$item->id] = array_keys($neighbors);
        }

        return $graph;
    }

    /**
     * @param  array<int, array{0:int,1:int}>  $cells
     * @return array<string, true>
     */
    private function toCellSet(array $cells): array
    {
        $set = [];
        foreach ($cells as [$cx, $cy]) {
            $set[$cx.':'.$cy] = true;
        }

        return $set;
    }
}
