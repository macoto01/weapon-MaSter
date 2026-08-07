<?php

use Illuminate\Support\Facades\Route;
use Tool\Http\Controllers\ArmorAdminController;
use Tool\Http\Controllers\BgmAdminController;
use Tool\Http\Controllers\CpuAdminController;
use Tool\Http\Controllers\MaterialAdminController;
use Tool\Http\Controllers\RecipeAdminController;
use Tool\Http\Controllers\RelicAdminController;
use Tool\Http\Controllers\WeaponAdminController;

/*
 * ゲーム本体(routes/web.php)とは別に管理する、裏方の管理ツール用ルート定義。
 * bootstrap/app.phpからwebミドルウェアグループ付きで読み込まれる。
 */
Route::prefix('tool')->name('tool.')->group(function () {
    Route::get('/', fn () => view('tool::dashboard'))->name('dashboard');

    Route::get('/weapons', [WeaponAdminController::class, 'index'])->name('weapons.index');
    Route::get('/weapons/export', [WeaponAdminController::class, 'export'])->name('weapons.export');
    Route::get('/weapons/import', [WeaponAdminController::class, 'importForm'])->name('weapons.import');
    Route::post('/weapons/import', [WeaponAdminController::class, 'import'])->name('weapons.import.submit');
    Route::get('/weapons/{key}/edit', [WeaponAdminController::class, 'edit'])->name('weapons.edit');
    Route::put('/weapons/{key}', [WeaponAdminController::class, 'update'])->name('weapons.update');
    Route::delete('/weapons/{key}', [WeaponAdminController::class, 'destroy'])->name('weapons.destroy');

    Route::get('/materials', [MaterialAdminController::class, 'index'])->name('materials.index');
    Route::get('/materials/export', [MaterialAdminController::class, 'export'])->name('materials.export');
    Route::get('/materials/import', [MaterialAdminController::class, 'importForm'])->name('materials.import');
    Route::post('/materials/import', [MaterialAdminController::class, 'import'])->name('materials.import.submit');
    Route::get('/materials/{key}/edit', [MaterialAdminController::class, 'edit'])->name('materials.edit');
    Route::put('/materials/{key}', [MaterialAdminController::class, 'update'])->name('materials.update');
    Route::delete('/materials/{key}', [MaterialAdminController::class, 'destroy'])->name('materials.destroy');

    Route::get('/armors', [ArmorAdminController::class, 'index'])->name('armors.index');
    Route::get('/armors/export', [ArmorAdminController::class, 'export'])->name('armors.export');
    Route::get('/armors/import', [ArmorAdminController::class, 'importForm'])->name('armors.import');
    Route::post('/armors/import', [ArmorAdminController::class, 'import'])->name('armors.import.submit');
    Route::get('/armors/{key}/edit', [ArmorAdminController::class, 'edit'])->name('armors.edit');
    Route::put('/armors/{key}', [ArmorAdminController::class, 'update'])->name('armors.update');
    Route::delete('/armors/{key}', [ArmorAdminController::class, 'destroy'])->name('armors.destroy');

    Route::get('/relics', [RelicAdminController::class, 'index'])->name('relics.index');
    Route::get('/relics/export', [RelicAdminController::class, 'export'])->name('relics.export');
    Route::get('/relics/import', [RelicAdminController::class, 'importForm'])->name('relics.import');
    Route::post('/relics/import', [RelicAdminController::class, 'import'])->name('relics.import.submit');
    Route::get('/relics/{key}/edit', [RelicAdminController::class, 'edit'])->name('relics.edit');
    Route::put('/relics/{key}', [RelicAdminController::class, 'update'])->name('relics.update');
    Route::delete('/relics/{key}', [RelicAdminController::class, 'destroy'])->name('relics.destroy');

    Route::get('/recipes', [RecipeAdminController::class, 'index'])->name('recipes.index');
    Route::get('/recipes/export', [RecipeAdminController::class, 'export'])->name('recipes.export');
    Route::get('/recipes/import', [RecipeAdminController::class, 'importForm'])->name('recipes.import');
    Route::post('/recipes/import', [RecipeAdminController::class, 'import'])->name('recipes.import.submit');
    Route::get('/recipes/{key}/edit', [RecipeAdminController::class, 'edit'])->name('recipes.edit');
    Route::put('/recipes/{key}', [RecipeAdminController::class, 'update'])->name('recipes.update');
    Route::delete('/recipes/{key}', [RecipeAdminController::class, 'destroy'])->name('recipes.destroy');

    Route::get('/bgm', [BgmAdminController::class, 'index'])->name('bgm.index');
    Route::post('/bgm', [BgmAdminController::class, 'store'])->name('bgm.store');
    Route::delete('/bgm/{key}', [BgmAdminController::class, 'destroy'])->name('bgm.destroy');
    Route::post('/bgm/assign', [BgmAdminController::class, 'assign'])->name('bgm.assign');

    Route::get('/cpu', [CpuAdminController::class, 'index'])->name('cpu.index');
    Route::get('/cpu/export', [CpuAdminController::class, 'export'])->name('cpu.export');
    Route::get('/cpu/import', [CpuAdminController::class, 'importForm'])->name('cpu.import');
    Route::post('/cpu/import', [CpuAdminController::class, 'import'])->name('cpu.import.submit');
    Route::get('/cpu/create', [CpuAdminController::class, 'create'])->name('cpu.create');
    Route::post('/cpu', [CpuAdminController::class, 'store'])->name('cpu.store');
    Route::get('/cpu/{key}/edit', [CpuAdminController::class, 'edit'])->name('cpu.edit');
    Route::put('/cpu/{key}', [CpuAdminController::class, 'update'])->name('cpu.update');
    Route::delete('/cpu/{key}', [CpuAdminController::class, 'destroy'])->name('cpu.destroy');

    // 次のステップで実装予定の機能(着手順序の合意に基づき、武器情報更新を先に作った)。
    Route::get('/users', fn () => view('tool::coming_soon', ['title' => 'ユーザーデータの確認']))->name('users.index');
    Route::get('/dummy-data', fn () => view('tool::coming_soon', ['title' => 'ダミーデータの自動編成']))->name('dummy-data.index');
});
