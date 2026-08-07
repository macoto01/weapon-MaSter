<?php

namespace App\Http\Controllers;

use App\Services\MatchStateService;
use Illuminate\Http\JsonResponse;

/**
 * バトル画面・バトル結果ポップアップのワイヤーフレーム仕様に対応するAPIエンドポイント。
 */
class BattleController extends Controller
{
    public function __construct(private readonly MatchStateService $match) {}

    public function index()
    {
        if (! $this->match->hasMatch()) {
            return redirect()->route('title.index');
        }

        return view('battle');
    }

    public function state(): JsonResponse
    {
        return $this->handle(fn () => $this->match->battleState());
    }

    public function finish(): JsonResponse
    {
        return $this->handle(fn () => $this->match->finishBattle());
    }

    private function handle(\Closure $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
