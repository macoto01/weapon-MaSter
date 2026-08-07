<?php

namespace App\Http\Controllers;

use App\Services\MatchStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * バトル準備画面ワイヤーフレーム仕様に対応するAPIエンドポイント。
 * ロジックは全てMatchStateService以下のドメイン層が担い、
 * このコントローラは入力の受け渡しとレスポンス整形のみを行う(技術要件定義の役割分担)。
 */
class BattlePrepController extends Controller
{
    public function __construct(private readonly MatchStateService $match) {}

    public function index()
    {
        if (! $this->match->hasMatch()) {
            return redirect()->route('title.index');
        }

        return view('battle-prep');
    }

    public function state(): JsonResponse
    {
        return $this->handle(fn () => $this->match->state());
    }

    public function rerollShop(): JsonResponse
    {
        return $this->handle(fn () => $this->match->rerollShop());
    }

    public function synthesisPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_type' => ['required', 'string', 'in:weapon,material'],
            'item_key' => ['required', 'string'],
            'exclude_placement_id' => ['sometimes', 'string'],
        ]);

        return $this->handle(fn () => $this->match->synthesisPreview($data['item_type'], $data['item_key'], $data['exclude_placement_id'] ?? null));
    }

    public function synergyPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_type' => ['required', 'string', 'in:weapon,material'],
            'item_key' => ['required', 'string'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'width' => ['required', 'integer', 'min:1'],
            'height' => ['required', 'integer', 'min:1'],
            'exclude_placement_id' => ['sometimes', 'string'],
        ]);

        return $this->handle(fn () => $this->match->synergyPreview(
            $data['item_type'], $data['item_key'], $data['x'], $data['y'], $data['width'], $data['height'], $data['exclude_placement_id'] ?? null
        ));
    }

    public function buy(Request $request): JsonResponse
    {
        $data = $request->validate(['offer_id' => ['required', 'string']]);

        return $this->handle(fn () => $this->match->buyFromShop($data['offer_id']));
    }

    public function cancelPurchase(Request $request): JsonResponse
    {
        $data = $request->validate(['temp_storage_id' => ['required', 'string']]);

        return $this->handle(fn () => $this->match->cancelPurchase($data['temp_storage_id']));
    }

    public function sell(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string'],
            'source' => ['required', 'string', 'in:temp,grid'],
        ]);

        return $this->handle(fn () => $this->match->sellItem($data['id'], $data['source']));
    }

    public function place(Request $request): JsonResponse
    {
        $data = $request->validate([
            'temp_storage_id' => ['required', 'string'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'rotated' => ['sometimes', 'boolean'],
        ]);

        return $this->handle(fn () => $this->match->placeItem($data['temp_storage_id'], $data['x'], $data['y'], $data['rotated'] ?? false));
    }

    public function move(Request $request): JsonResponse
    {
        $data = $request->validate([
            'placement_id' => ['required', 'string'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'rotated' => ['sometimes', 'boolean'],
        ]);

        return $this->handle(fn () => $this->match->moveItem($data['placement_id'], $data['x'], $data['y'], $data['rotated'] ?? false));
    }

    public function rotate(Request $request): JsonResponse
    {
        $data = $request->validate(['placement_id' => ['required', 'string']]);

        return $this->handle(fn () => $this->match->rotateItem($data['placement_id']));
    }

    public function unequip(Request $request): JsonResponse
    {
        $data = $request->validate(['placement_id' => ['required', 'string']]);

        return $this->handle(fn () => $this->match->unequipItem($data['placement_id']));
    }

    public function unlockCell(Request $request): JsonResponse
    {
        $data = $request->validate([
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
        ]);

        return $this->handle(fn () => $this->match->unlockCell($data['x'], $data['y']));
    }

    public function startBattle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirm_synthesis' => ['sometimes', 'boolean'],
            'cpu_key' => ['sometimes', 'string'],
        ]);

        return $this->handle(fn () => $this->match->startBattle($data['confirm_synthesis'] ?? false, $data['cpu_key'] ?? null));
    }

    public function retire(): JsonResponse
    {
        $this->match->abandonMatch();

        return response()->json(['redirect' => route('title.index')]);
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
