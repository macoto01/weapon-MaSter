<?php

namespace Tool\Http\Controllers\Concerns;

use App\Models\CpuPattern;

/**
 * 武器・素材の削除前に、合成レシピ(recipes.json)・CPUパターン(cpu_patternsテーブル)から
 * 参照されていないかを確認する。参照が残ったまま削除すると、MasterDataRepository::weapon()/
 * material()が例外を投げてバトルシミュレーション等が壊れるため、WeaponAdminController・
 * MaterialAdminControllerのdestroy()から共通で使う。
 */
trait GuardsMasterDataReferences
{
    /**
     * @param  'weapon'|'material'  $itemType
     * @return string[] 参照元の説明一覧(空なら削除可能)
     */
    private function findMasterDataReferences(string $itemType, string $key): array
    {
        $blockers = [];

        $recipes = json_decode(file_get_contents(database_path('data/recipes.json')), true) ?? [];
        foreach ($recipes as $recipe) {
            $usedAsInput = collect($recipe['inputs'] ?? [])
                ->contains(fn (array $input) => ($input['type'] ?? null) === $itemType && ($input['key'] ?? null) === $key);
            $usedAsOutput = $itemType === 'weapon' && ($recipe['output_weapon_key'] ?? null) === $key;

            if ($usedAsInput || $usedAsOutput) {
                $blockers[] = "合成レシピ「{$recipe['name']}」";
            }
        }

        foreach (CpuPattern::all() as $pattern) {
            $used = collect($pattern->layout ?? [])
                ->contains(fn (array $item) => ($item['item_type'] ?? null) === $itemType && ($item['item_key'] ?? null) === $key);

            if ($used) {
                $blockers[] = "CPUパターン「{$pattern->name}」";
            }
        }

        return $blockers;
    }
}
