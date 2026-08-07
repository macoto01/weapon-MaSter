<?php

namespace Tool\Http\Controllers;

use Tool\Http\Controllers\Concerns\GuardsMasterDataReferences;

/**
 * database/data/weapons.json を直接読み書きする管理ツール用コントローラ。
 * ゲーム本体側(App\Services\MasterDataRepository)は読み取り専用のため、
 * マスタデータへの書き込みはこの管理ツールでのみ行う。
 * 確認・修正・削除の共通ロジックはAbstractEquipmentAdminController(防具・秘宝と共有)。
 * 削除時は合成レシピ・CPUパターンから参照されていないかをGuardsMasterDataReferencesで確認する。
 */
class WeaponAdminController extends AbstractEquipmentAdminController
{
    use GuardsMasterDataReferences;

    protected function kind(): string
    {
        return 'weapon';
    }

    protected function label(): string
    {
        return '武器';
    }

    protected function referenceBlockers(string $key): array
    {
        return $this->findMasterDataReferences('weapon', $key);
    }
}
