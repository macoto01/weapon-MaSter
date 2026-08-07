<?php

use App\Http\Controllers\BattleController;
use App\Http\Controllers\BattlePrepController;
use App\Http\Controllers\BgmController;
use App\Http\Controllers\EncyclopediaController;
use App\Http\Controllers\TitleController;
use Illuminate\Support\Facades\Route;

Route::get('/', [TitleController::class, 'index'])->name('title.index');
Route::get('/encyclopedia', [EncyclopediaController::class, 'index'])->name('encyclopedia.index');
Route::get('/battle-prep', [BattlePrepController::class, 'index'])->name('battle-prep.index');
Route::get('/battle', [BattleController::class, 'index'])->name('battle.index');

Route::prefix('api')->group(function () {
    Route::post('/match/start', [TitleController::class, 'startMatch']);

    Route::get('/encyclopedia/tag', [EncyclopediaController::class, 'tag']);

    Route::get('/prep/state', [BattlePrepController::class, 'state']);
    Route::get('/prep/synthesis-preview', [BattlePrepController::class, 'synthesisPreview']);
    Route::get('/prep/synergy-preview', [BattlePrepController::class, 'synergyPreview']);
    Route::post('/prep/shop/reroll', [BattlePrepController::class, 'rerollShop']);
    Route::post('/prep/shop/buy', [BattlePrepController::class, 'buy']);
    Route::post('/prep/shop/cancel', [BattlePrepController::class, 'cancelPurchase']);
    Route::post('/prep/sell', [BattlePrepController::class, 'sell']);
    Route::post('/prep/place', [BattlePrepController::class, 'place']);
    Route::post('/prep/move', [BattlePrepController::class, 'move']);
    Route::post('/prep/rotate', [BattlePrepController::class, 'rotate']);
    Route::post('/prep/unequip', [BattlePrepController::class, 'unequip']);
    Route::post('/prep/unlock-cell', [BattlePrepController::class, 'unlockCell']);
    Route::post('/prep/start-battle', [BattlePrepController::class, 'startBattle']);
    Route::post('/prep/retire', [BattlePrepController::class, 'retire']);

    Route::get('/battle/state', [BattleController::class, 'state']);
    Route::post('/battle/finish', [BattleController::class, 'finish']);

    Route::get('/bgm/active', [BgmController::class, 'active']);
});
