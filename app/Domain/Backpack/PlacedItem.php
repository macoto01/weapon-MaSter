<?php

namespace App\Domain\Backpack;

/**
 * バックパックのグリッドに配置された1個のアイテム(武器または素材)。
 * セッションに保存する際は toArray()/fromArray() でやり取りする。
 */
class PlacedItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $itemType, // 'weapon' | 'material'
        public readonly string $itemKey,
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
    ) {}

    /**
     * @return array<int, array{0:int,1:int}> このアイテムが占有するグリッド座標
     */
    public function occupiedCells(): array
    {
        $cells = [];
        for ($dx = 0; $dx < $this->width; $dx++) {
            for ($dy = 0; $dy < $this->height; $dy++) {
                $cells[] = [$this->x + $dx, $this->y + $dy];
            }
        }

        return $cells;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'item_type' => $this->itemType,
            'item_key' => $this->itemKey,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['item_type'],
            $data['item_key'],
            $data['x'],
            $data['y'],
            $data['width'],
            $data['height'],
        );
    }
}
