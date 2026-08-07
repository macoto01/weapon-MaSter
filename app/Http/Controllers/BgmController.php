<?php

namespace App\Http\Controllers;

use App\Services\BgmLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BgmController extends Controller
{
    public function __construct(private readonly BgmLibraryService $bgm) {}

    public function active(Request $request): JsonResponse
    {
        $data = $request->validate([
            'screen' => ['required', 'string'],
        ]);

        return response()->json([
            'url' => $this->bgm->activeTrackUrl($data['screen']),
        ]);
    }
}
