<?php

namespace Tool\Http\Controllers;

/**
 * database/data/armors.json を直接読み書きする管理ツール用コントローラ。
 * 防具は武器と同一スキーマのため、確認・修正・削除の実装はAbstractEquipmentAdminControllerを
 * そのまま使う。現状どこからも参照されないため、削除時の参照チェックは既定(常に削除可能)のまま。
 */
class ArmorAdminController extends AbstractEquipmentAdminController
{
    protected function kind(): string
    {
        return 'armor';
    }

    protected function label(): string
    {
        return '防具';
    }
}
