<?php

namespace App\Services;

use App\Domain\Combat\EffectRegistry;
use App\Domain\MasterData\MasterDataEntry;
use App\Models\CpuPattern;
use Illuminate\Support\Collection;

/**
 * 武器・防具・素材・秘宝・合成レシピ・CPUパターンのマスタデータへの読み取り専用アクセスをまとめたリポジトリ。
 * 武器/防具/素材/秘宝/合成レシピはdatabase/data/*.jsonで管理し(DBは使わない)、
 * CPUパターンのみ従来通りDB(cpu_patternsテーブル)で管理する。
 * リクエスト内で使い回せるよう key => エントリ のコレクションとしてキャッシュする。
 */
class MasterDataRepository
{
    private ?Collection $weapons = null;

    private ?Collection $armors = null;

    private ?Collection $materials = null;

    private ?Collection $relics = null;

    private ?Collection $recipes = null;

    private ?Collection $cpuPatterns = null;

    public function weapons(): Collection
    {
        return $this->weapons ??= $this->loadEntries('weapons', 'weapons')->keyBy('key');
    }

    public function armors(): Collection
    {
        return $this->armors ??= $this->loadEntries('armors', 'armors')->keyBy('key');
    }

    public function materials(): Collection
    {
        return $this->materials ??= $this->loadEntries('materials', 'materials', function (array $row) {
            $row['effect_description'] = EffectRegistry::describeMaterial(
                $row['effect_type'],
                $row['effect_value'],
                $row['effect_range'],
            );

            return $row;
        })->keyBy('key');
    }

    public function relics(): Collection
    {
        return $this->relics ??= $this->loadEntries('relics', 'relics')->keyBy('key');
    }

    /**
     * @return Collection<int, MasterDataEntry> 合成レシピは単一キー検索を持たず常に一覧で扱う。
     */
    public function recipes(): Collection
    {
        return $this->recipes ??= $this->loadEntries('recipes', null);
    }

    public function cpuPatterns(): Collection
    {
        return $this->cpuPatterns ??= CpuPattern::all()->keyBy('key');
    }

    public function cpuPattern(string $key): CpuPattern
    {
        $pattern = $this->cpuPatterns()->get($key);
        if (! $pattern) {
            throw new \InvalidArgumentException("Unknown CPU pattern key: {$key}");
        }

        return $pattern;
    }

    public function weapon(string $key): MasterDataEntry
    {
        return $this->findOrFail($this->weapons(), $key, 'weapon');
    }

    public function armor(string $key): MasterDataEntry
    {
        return $this->findOrFail($this->armors(), $key, 'armor');
    }

    public function material(string $key): MasterDataEntry
    {
        return $this->findOrFail($this->materials(), $key, 'material');
    }

    public function relic(string $key): MasterDataEntry
    {
        return $this->findOrFail($this->relics(), $key, 'relic');
    }

    /**
     * 抽選/初期化用に「置ける」ベース武器のみ(合成生成物は除く)を返す。
     */
    public function draftableWeapons(): Collection
    {
        return $this->weapons()->reject(fn (MasterDataEntry $w) => $w->is_synthesized)->values();
    }

    /**
     * database/data/{$file}.jsonを読み込み、MasterDataEntryのコレクションにする。
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $transform  行ごとの追加加工(素材のeffect_description算出など)
     * @return Collection<int, MasterDataEntry>
     */
    private function loadEntries(string $file, ?string $imageDir, ?callable $transform = null): Collection
    {
        $path = database_path("data/{$file}.json");
        $rows = json_decode(file_get_contents($path), true) ?? [];

        return collect($rows)->map(function (array $row) use ($imageDir, $transform) {
            if ($transform !== null) {
                $row = $transform($row);
            }

            return new MasterDataEntry($row, $imageDir);
        });
    }

    private function findOrFail(Collection $entries, string $key, string $label): MasterDataEntry
    {
        $entry = $entries->get($key);
        if (! $entry) {
            throw new \InvalidArgumentException("Unknown {$label} key: {$key}");
        }

        return $entry;
    }
}
