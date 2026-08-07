<?php

namespace App\Domain\MasterData;

/**
 * JSON(database/data/*.json)で定義された武器・防具・素材・秘宝・合成レシピの
 * 1レコードを表す軽量な値オブジェクト。呼び出し側は旧Eloquentモデルと同じ
 * $entry->fieldの形でアクセスできる(呼び出し側のコード変更を不要にするため)。
 */
class MasterDataEntry
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  string|null  $imageDir  public/images/{$imageDir}/{key}.jpg を画像として扱う。
     *                                 画像を持たない種別(レシピ等)はnullを渡す。
     */
    public function __construct(
        private readonly array $attributes,
        private readonly ?string $imageDir = null,
    ) {}

    public function __get(string $name): mixed
    {
        if ($name === 'image_url') {
            return $this->resolveImageUrl();
        }

        return $this->attributes[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return $name === 'image_url' || array_key_exists($name, $this->attributes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    private function resolveImageUrl(): ?string
    {
        if ($this->imageDir === null) {
            return null;
        }

        $path = "images/{$this->imageDir}/{$this->attributes['key']}.jpg";

        return file_exists(public_path($path)) ? asset($path) : null;
    }
}
