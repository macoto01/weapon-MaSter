<?php

namespace App\Http\Controllers;

use App\Services\MatchStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * タイトル画面ワイヤーフレーム仕様に対応。ロジックはMatchStateServiceに委譲する。
 */
class TitleController extends Controller
{
    public function __construct(private readonly MatchStateService $match) {}

    public function index()
    {
        return view('title');
    }

    public function startMatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:rank,casual'],
        ]);

        return response()->json($this->match->startMatch($data['mode']));
    }
}
