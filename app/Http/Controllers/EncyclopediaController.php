<?php

namespace App\Http\Controllers;

use App\Domain\Encyclopedia\EncyclopediaCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 図鑑画面ワイヤーフレーム仕様に対応。EncyclopediaCatalogが整形したデータをそのまま返す。
 */
class EncyclopediaController extends Controller
{
    public function __construct(private readonly EncyclopediaCatalog $catalog) {}

    public function index()
    {
        return view('encyclopedia');
    }

    public function tag(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tag' => ['required', 'string'],
        ]);

        return response()->json([
            'tags' => $this->catalog->tags(),
            'entries' => $this->catalog->entriesForTag($data['tag']),
        ]);
    }
}
