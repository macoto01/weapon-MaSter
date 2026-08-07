<?php

namespace App\Services;

use App\Domain\Backpack\BackpackGrid;
use App\Domain\Backpack\PlacedItem;
use App\Domain\Backpack\SynthesisResolver;
use App\Domain\Combat\BattleSimulator;
use App\Domain\Combat\EffectRegistry;
use App\Domain\Combat\SynergyCalculator;
use App\Domain\Cpu\CpuRoster;
use App\Domain\MasterData\MasterDataEntry;
use App\Domain\Rank\RankTable;
use Illuminate\Support\Str;

/**
 * 1マッチ(ラン)分の進行状態をセッションに保持し、バトル準備画面・バトル画面が必要とする
 * 全てのユースケース(ショップ・一時置き場・グリッド解放・配置・合成確定・戦闘)を仲介する司令塔。
 *
 * 企画書4.5(マッチ構造)・4.6(マニー経済)、バトル準備画面ワイヤーフレーム仕様2〜3節に対応。
 * ログイン機構を作らない検証範囲のため、進行状態はLaravelセッションに保持する。
 */
class MatchStateService
{
    private const SESSION_KEY = 'weapon_master_match';

    /**
     * ランクポイントはマッチ(1ラン)をまたいで持ち越す進行度のため、
     * マッチ開始のたびに初期化されるSESSION_KEYとは別のセッションキーで保持する。
     */
    private const RANK_SESSION_KEY = 'weapon_master_rank_points';

    /**
     * 図鑑の伏せ表示(?????)解除用の「使用/遭遇済み武器」は、ランクポイントと同様に
     * マッチをまたいで持ち越す進行度のため、専用のセッションキーで保持する。
     */
    private const DISCOVERED_WEAPONS_SESSION_KEY = 'weapon_master_discovered_weapons';

    private const RANK_POINTS_PER_WIN = 1;

    /**
     * バックパックのグリッドサイズ(プレイヤー・CPU共通)。管理ツール(tool/cpu)の
     * layoutバリデーションもこの値を参照し、実戦闘のグリッドと常に一致させる。
     */
    public const RESERVED_WIDTH = 8;

    public const RESERVED_HEIGHT = 7;

    private const INITIAL_UNLOCK_X = 1;

    private const INITIAL_UNLOCK_Y = 1;

    private const INITIAL_UNLOCK_WIDTH = 4;

    private const INITIAL_UNLOCK_HEIGHT = 3;

    private const MAX_ROUNDS = 15;

    /** 何ラウンドごとにCPUの強さ段階(difficulty_tier)を1つ上げるか。MAX_ROUNDS(15)を割り切れる値。 */
    private const ROUNDS_PER_TIER = 3;

    private const MAX_LOSSES = 5;

    private const WINS_TO_CLEAR = 10;

    private const SHOP_SIZE = 6;

    private const SHOP_REROLL_COST = 1;

    private const WIN_REWARD = 10;

    private const STARTING_MONEY = 10;

    private const UNLOCK_TOKENS_PER_BATTLE = 4;

    public function __construct(
        private readonly MasterDataRepository $catalog,
        private readonly SynergyCalculator $synergy,
        private readonly SynthesisResolver $synthesis,
        private readonly BattleSimulator $battleSimulator,
        private readonly CpuRoster $cpuRoster,
        private readonly RankTable $rankTable,
    ) {}

    public function hasMatch(): bool
    {
        return is_array(session(self::SESSION_KEY));
    }

    /**
     * バトル準備画面「リタイア」ボタン押下。進行中のマッチを破棄してタイトル画面に戻す。
     * ランクポイント・図鑑の発見済み武器はマッチをまたいで持ち越す進行度のため消去しない。
     */
    public function abandonMatch(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public function startMatch(string $mode): array
    {
        $raw = [
            'mode' => $mode,
            'money' => self::STARTING_MONEY,
            'wins' => 0,
            'losses' => 0,
            'round' => 1,
            'match_status' => 'in_progress',
            'unlocked_cells' => $this->initialUnlockedCells(),
            'pending_unlock_tokens' => 0,
            'placed_items' => [],
            'temp_storage' => [],
            'shop_offers' => [],
            'pending_battle' => null,
        ];
        $raw['shop_offers'] = $this->rollShopOffers();

        $this->save($raw);

        return $this->present($raw);
    }

    public function state(): array
    {
        return $this->present($this->load());
    }

    /**
     * ショップ/武器置き場/バックパックで武器・素材を掴んでいる(ドラッグ中の)間、
     * それを置いたら合成できるバックパック内アイテムを提示するためのプレビュー専用呼び出し。
     * バックパックの状態は変更しない。$excludePlacementId はバックパック内の武器を
     * 掴んで移動している場合に、その武器自身を候補から除くために使う。
     */
    public function synthesisPreview(string $itemType, string $itemKey, ?string $excludePlacementId = null): array
    {
        $raw = $this->load();
        $items = $this->itemsFromRaw($raw['placed_items']);
        if ($excludePlacementId !== null) {
            $items = array_values(array_filter($items, fn (PlacedItem $item) => $item->id !== $excludePlacementId));
        }

        return ['candidates' => $this->synthesis->previewPartners($itemType, $itemKey, $items)];
    }

    /**
     * ショップ/武器置き場/バックパックで武器・素材を掴んでいる(ドラッグ中の)間、
     * 今つかんでいる位置に置いたら隣接することになるバックパック内アイテムそれぞれについて、
     * シナジー(隣接効果)が成立するか(○)しないか(×)を提示するためのプレビュー専用呼び出し。
     * バックパックの状態は変更しない。$excludePlacementId の用途はsynthesisPreview()と同じ。
     */
    public function synergyPreview(string $itemType, string $itemKey, int $x, int $y, int $width, int $height, ?string $excludePlacementId = null): array
    {
        $raw = $this->load();
        $items = $this->itemsFromRaw($raw['placed_items']);
        if ($excludePlacementId !== null) {
            $items = array_values(array_filter($items, fn (PlacedItem $item) => $item->id !== $excludePlacementId));
        }

        return ['markers' => $this->synergy->previewNeighborJudgement($itemType, $itemKey, $x, $y, $width, $height, $items, $this->grid())];
    }

    public function rerollShop(): array
    {
        $raw = $this->load();
        $this->assertNoExpansionPending($raw);

        if ($raw['money'] < self::SHOP_REROLL_COST) {
            throw new \InvalidArgumentException('マニーが不足しています。');
        }

        $raw['money'] -= self::SHOP_REROLL_COST;
        $raw['shop_offers'] = $this->rollShopOffers();
        $this->save($raw);

        return $this->present($raw);
    }

    public function buyFromShop(string $offerId): array
    {
        $raw = $this->load();
        $this->assertNoExpansionPending($raw);

        $offerIndex = null;
        foreach ($raw['shop_offers'] as $index => $offer) {
            if ($offer['id'] === $offerId) {
                $offerIndex = $index;
                break;
            }
        }

        if ($offerIndex === null) {
            throw new \InvalidArgumentException('その商品はショップにありません。');
        }

        $offer = $raw['shop_offers'][$offerIndex];
        if ($raw['money'] < $offer['price']) {
            throw new \InvalidArgumentException('マニーが不足しています。');
        }

        $raw['money'] -= $offer['price'];
        array_splice($raw['shop_offers'], $offerIndex, 1);
        $raw['temp_storage'][] = [
            'id' => 't_'.Str::random(8),
            'item_type' => $offer['item_type'],
            'item_key' => $offer['item_key'],
        ];

        $this->save($raw);

        return $this->present($raw);
    }

    /**
     * ショップから武器置き場へ買った直後、そのままバックパックへの配置に失敗した場合専用。
     * 「購入しなかったこと」にする: 支払ったマニーを全額返金し武器置き場から取り除いた上で、
     * 同じ商品をショップの品揃えに戻す。半額になる通常の売却(sellItem)とは異なる。
     */
    public function cancelPurchase(string $tempStorageId): array
    {
        $raw = $this->load();
        $this->assertNoExpansionPending($raw);

        $index = $this->findIndexById($raw['temp_storage'], $tempStorageId);
        if ($index === null) {
            throw new \InvalidArgumentException('武器置き場に見つかりません。');
        }

        $entry = $raw['temp_storage'][$index];
        $meta = $entry['item_type'] === 'weapon' ? $this->catalog->weapon($entry['item_key']) : $this->catalog->material($entry['item_key']);

        $raw['money'] += $meta->price;
        array_splice($raw['temp_storage'], $index, 1);
        $raw['shop_offers'][] = [
            'id' => 'shop_'.Str::random(8),
            'item_type' => $entry['item_type'],
            'item_key' => $entry['item_key'],
            'price' => $meta->price,
            'original_price' => null,
        ];

        $this->save($raw);

        return $this->present($raw);
    }

    /**
     * @param  'temp'|'grid'  $source
     */
    public function sellItem(string $id, string $source): array
    {
        $raw = $this->load();
        $this->assertNoExpansionPending($raw);

        if ($source === 'temp') {
            $index = $this->findIndexById($raw['temp_storage'], $id);
            if ($index === null) {
                throw new \InvalidArgumentException('武器置き場に見つかりません。');
            }
            $entry = $raw['temp_storage'][$index];
            $raw['money'] += $this->saleValue($entry['item_type'], $entry['item_key']);
            array_splice($raw['temp_storage'], $index, 1);
        } elseif ($source === 'grid') {
            $items = $this->itemsFromRaw($raw['placed_items']);
            $target = null;
            foreach ($items as $item) {
                if ($item->id === $id) {
                    $target = $item;
                    break;
                }
            }
            if ($target === null) {
                throw new \InvalidArgumentException('バックパックに見つかりません。');
            }
            $raw['money'] += $this->saleValue($target->itemType, $target->itemKey);
            $items = array_values(array_filter($items, fn (PlacedItem $i) => $i->id !== $id));
            $raw['placed_items'] = array_map(fn (PlacedItem $i) => $i->toArray(), $items);
        } else {
            throw new \InvalidArgumentException('不明な売却元です。');
        }

        $this->save($raw);

        return $this->present($raw);
    }

    public function placeItem(string $tempStorageId, int $x, int $y, bool $rotated = false): array
    {
        $raw = $this->load();
        $this->assertNoExpansionPending($raw);

        $tempIndex = $this->findIndexById($raw['temp_storage'], $tempStorageId);
        if ($tempIndex === null) {
            throw new \InvalidArgumentException('武器置き場に見つかりません。');
        }
        $entry = $raw['temp_storage'][$tempIndex];
        [$width, $height] = $this->dimensionsOf($entry['item_type'], $entry['item_key']);
        if ($rotated) {
            [$width, $height] = [$height, $width];
        }

        $candidate = new PlacedItem(
            id: 'p_'.Str::random(8),
            itemType: $entry['item_type'],
            itemKey: $entry['item_key'],
            x: $x,
            y: $y,
            width: $width,
            height: $height,
        );

        $items = $this->itemsFromRaw($raw['placed_items']);
        if (! $this->grid()->canPlace($items, $candidate, null, $raw['unlocked_cells'])) {
            throw new \InvalidArgumentException('そこには配置できません(未解放・範囲外・重複のいずれかです)。');
        }

        $items[] = $candidate;
        $raw['placed_items'] = array_map(fn (PlacedItem $i) => $i->toArray(), $items);
        array_splice($raw['temp_storage'], $tempIndex, 1);

        $this->save($raw);

        return $this->present($raw);
    }

    public function moveItem(string $placementId, int $x, int $y, bool $rotate = false): array
    {
        $raw = $this->load();
        $this->assertNoExpansionPending($raw);
        $items = $this->itemsFromRaw($raw['placed_items']);

        $target = null;
        foreach ($items as $item) {
            if ($item->id === $placementId) {
                $target = $item;
                break;
            }
        }
        if ($target === null) {
            throw new \InvalidArgumentException('指定されたアイテムが見つかりません。');
        }

        $width = $rotate ? $target->height : $target->width;
        $height = $rotate ? $target->width : $target->height;

        $moved = new PlacedItem($target->id, $target->itemType, $target->itemKey, $x, $y, $width, $height);
        if (! $this->grid()->canPlace($items, $moved, $target->id, $raw['unlocked_cells'])) {
            throw new \InvalidArgumentException('そこには移動できません(未解放・範囲外・重複のいずれかです)。');
        }

        $items = array_map(fn (PlacedItem $i) => $i->id === $target->id ? $moved : $i, $items);
        $raw['placed_items'] = array_map(fn (PlacedItem $i) => $i->toArray(), $items);

        $this->save($raw);

        return $this->present($raw);
    }

    /**
     * 配置済みアイテムを同じ左上座標のまま90度回転(幅と高さを入れ替え)する。
     */
    public function rotateItem(string $placementId): array
    {
        $raw = $this->load();
        $this->assertNoExpansionPending($raw);
        $items = $this->itemsFromRaw($raw['placed_items']);

        $target = null;
        foreach ($items as $item) {
            if ($item->id === $placementId) {
                $target = $item;
                break;
            }
        }
        if ($target === null) {
            throw new \InvalidArgumentException('指定されたアイテムが見つかりません。');
        }

        $rotated = new PlacedItem($target->id, $target->itemType, $target->itemKey, $target->x, $target->y, $target->height, $target->width);
        if (! $this->grid()->canPlace($items, $rotated, $target->id, $raw['unlocked_cells'])) {
            throw new \InvalidArgumentException('回転すると配置できません(未解放・範囲外・重複のいずれかです)。');
        }

        $items = array_map(fn (PlacedItem $i) => $i->id === $target->id ? $rotated : $i, $items);
        $raw['placed_items'] = array_map(fn (PlacedItem $i) => $i->toArray(), $items);

        $this->save($raw);

        return $this->present($raw);
    }

    /**
     * バックパックから一時置き場へ戻す(「外す」)。売却はしない。
     */
    public function unequipItem(string $placementId): array
    {
        $raw = $this->load();
        $this->assertNoExpansionPending($raw);
        $items = $this->itemsFromRaw($raw['placed_items']);

        $target = null;
        foreach ($items as $item) {
            if ($item->id === $placementId) {
                $target = $item;
                break;
            }
        }
        if ($target === null) {
            throw new \InvalidArgumentException('指定されたアイテムが見つかりません。');
        }

        $items = array_values(array_filter($items, fn (PlacedItem $i) => $i->id !== $placementId));
        $raw['placed_items'] = array_map(fn (PlacedItem $i) => $i->toArray(), $items);
        $raw['temp_storage'][] = [
            'id' => 't_'.Str::random(8),
            'item_type' => $target->itemType,
            'item_key' => $target->itemKey,
        ];

        $this->save($raw);

        return $this->present($raw);
    }

    public function unlockCell(int $x, int $y): array
    {
        $raw = $this->load();

        if ($raw['pending_unlock_tokens'] <= 0) {
            throw new \InvalidArgumentException('解放できるマスがありません。');
        }
        if ($x < 0 || $y < 0 || $x >= self::RESERVED_WIDTH || $y >= self::RESERVED_HEIGHT) {
            throw new \InvalidArgumentException('範囲外です。');
        }

        foreach ($raw['unlocked_cells'] as [$ux, $uy]) {
            if ($ux === $x && $uy === $y) {
                throw new \InvalidArgumentException('既に解放済みです。');
            }
        }

        $isAdjacentToUnlocked = collect($this->unlockableCells($raw['unlocked_cells']))
            ->contains(fn ($c) => $c[0] === $x && $c[1] === $y);
        if (! $isAdjacentToUnlocked) {
            throw new \InvalidArgumentException('解放済みのマスに隣接した場所のみ拡張できます。');
        }

        $raw['unlocked_cells'][] = [$x, $y];
        $raw['pending_unlock_tokens']--;

        $this->save($raw);

        return $this->present($raw);
    }

    /**
     * 既に解放済みのマスに(4方向で)隣接している未解放マスの一覧。
     * バックパック拡張は既存の使用可能エリアと連続した形でのみ広げられる仕様のため、
     * unlockCell()のバリデーションと、フロント側の「＋」表示の両方がこれを参照する。
     *
     * @param  array<int, array{0:int,1:int}>  $unlockedCells
     * @return array<int, array{0:int,1:int}>
     */
    private function unlockableCells(array $unlockedCells): array
    {
        $unlockedSet = [];
        foreach ($unlockedCells as [$ux, $uy]) {
            $unlockedSet["{$ux}:{$uy}"] = true;
        }

        $candidates = [];
        foreach ($unlockedCells as [$ux, $uy]) {
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $ux + $dx;
                $ny = $uy + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= self::RESERVED_WIDTH || $ny >= self::RESERVED_HEIGHT) {
                    continue;
                }
                $key = "{$nx}:{$ny}";
                if (isset($unlockedSet[$key])) {
                    continue;
                }
                $candidates[$key] = [$nx, $ny];
            }
        }

        return array_values($candidates);
    }

    /**
     * 戦闘終了後に付与されるバックパック拡張(+4マス)が残っている間は、
     * ユーザーに拡張マスの選択を促すため、リタイア(abandonMatch)・拡張(unlockCell)以外の
     * 操作を一切受け付けない(コントローラ層のマスク表示と対になるサーバー側の防御)。
     */
    private function assertNoExpansionPending(array $raw): void
    {
        if ($raw['pending_unlock_tokens'] > 0) {
            throw new \InvalidArgumentException('バックパック拡張(残り'.$raw['pending_unlock_tokens'].'マス)を完了するまで他の操作はできません。');
        }
    }

    /**
     * バトル準備画面「バトル開始」ボタン押下。
     * 合成候補が未確認のまま存在する場合は $confirmSynthesis=true が渡されるまで実行しない
     * (企画書4.3: 「合成する武器がありますが問題ないですか?」の確認ポップアップに対応)。
     * 対戦相手はプレイヤーが選択するのではなく、開始時にランダムで決定する。
     */
    public function startBattle(bool $confirmSynthesis, ?string $cpuKey = null): array
    {
        $raw = $this->load();
        $this->assertNoExpansionPending($raw);

        $grid = $this->grid();
        $items = $this->itemsFromRaw($raw['placed_items']);
        // 合成候補は保存済みの値ではなく、その時点のバックパック内容から都度検出する
        // (ショップ購入・配置・移動など、バックパックの中身を変えるあらゆる操作の直後に
        // 最新の候補が反映されるようにするため。詳細はSynthesisResolverのコメント参照)。
        $detected = $this->synthesis->detect($items, $grid);

        if (! empty($detected) && ! $confirmSynthesis) {
            return [
                'needs_confirmation' => true,
                'pending_synthesis' => $this->presentSynthesis($detected),
            ];
        }

        if (! empty($detected)) {
            $applied = $this->synthesis->resolve($items, $grid);
            $items = $applied['items'];
            $raw['placed_items'] = array_map(fn (PlacedItem $i) => $i->toArray(), $items);
        }

        $roster = $this->cpuRoster->list();
        if ($cpuKey === null || ! isset($roster[$cpuKey])) {
            $tierRoster = $this->cpuRoster->listByTier($this->currentCpuTier($raw['round']));
            $cpuKey = array_rand($tierRoster !== [] ? $tierRoster : $roster);
        }
        $cpuInfo = $roster[$cpuKey];
        $cpuItems = $this->cpuRoster->backpack($cpuKey);
        $cpuGrid = new BackpackGrid(self::RESERVED_WIDTH, self::RESERVED_HEIGHT);

        $playerSynergy = $this->synergy->calculate($items, $grid);
        $cpuSynergy = $this->synergy->calculate($cpuItems, $cpuGrid);
        $baseHp = config('game.base_hp');
        $baseSt = config('game.base_st');

        $result = $this->battleSimulator->simulate(
            ['name' => 'プレイヤー', 'hp' => $baseHp + $playerSynergy['bonus_hp'], 'st' => $baseSt, 'attackers' => $playerSynergy['attackers']],
            ['name' => $cpuInfo['name'], 'hp' => $baseHp + $cpuSynergy['bonus_hp'], 'st' => $baseSt, 'attackers' => $cpuSynergy['attackers']],
            config('game.st_regen_per_second'),
        );

        $raw['pending_battle'] = [
            'cpu_key' => $cpuKey,
            'cpu_name' => $cpuInfo['name'],
            'result' => $result,
            'player_items' => array_map(fn (PlacedItem $i) => $i->toArray(), $items),
            'player_unlocked_cells' => $raw['unlocked_cells'],
            'enemy_items' => array_map(fn (PlacedItem $i) => $i->toArray(), $cpuItems),
        ];

        $this->save($raw);

        // 図鑑埋め(企画書4.2): 実戦に持ち込んだ武器(使用)・対戦相手の武器(遭遇)を開示済みにする
        $this->markWeaponsDiscovered([...$items, ...$cpuItems]);

        return ['redirect' => '/battle'];
    }

    /**
     * ラウンド番号から現在の対戦相手強さ段階(1〜5)を求める。3ラウンドごとに1段階上がり、
     * 5段階目(13〜15ラウンド)で頭打ちになる。
     */
    private function currentCpuTier(int $round): int
    {
        return (int) min(5, max(1, ceil($round / self::ROUNDS_PER_TIER)));
    }

    /**
     * @return string[] これまでに使用/遭遇して開示済みの武器キー一覧
     */
    public function discoveredWeaponKeys(): array
    {
        return session(self::DISCOVERED_WEAPONS_SESSION_KEY, []);
    }

    /**
     * @param  PlacedItem[]  $items
     */
    private function markWeaponsDiscovered(array $items): void
    {
        $weaponKeys = collect($items)
            ->filter(fn (PlacedItem $item) => $item->itemType === 'weapon')
            ->map(fn (PlacedItem $item) => $item->itemKey);

        if ($weaponKeys->isEmpty()) {
            return;
        }

        $discovered = array_unique([...$this->discoveredWeaponKeys(), ...$weaponKeys->all()]);
        session([self::DISCOVERED_WEAPONS_SESSION_KEY => array_values($discovered)]);
    }

    public function battleState(): array
    {
        $raw = $this->load();
        if ($raw['pending_battle'] === null) {
            throw new \InvalidArgumentException('進行中の戦闘がありません。');
        }

        $enrich = function (array $itemArray) {
            $item = PlacedItem::fromArray($itemArray);
            $meta = $item->itemType === 'weapon' ? $this->catalog->weapon($item->itemKey) : $this->catalog->material($item->itemKey);

            return [...$item->toArray(), 'name' => $meta->name, 'image_url' => $meta->image_url];
        };

        return [
            'cpu_name' => $raw['pending_battle']['cpu_name'],
            'result' => $raw['pending_battle']['result'],
            'player_items' => array_map($enrich, $raw['pending_battle']['player_items']),
            'player_unlocked_cells' => $raw['pending_battle']['player_unlocked_cells'],
            'reserved_width' => self::RESERVED_WIDTH,
            'reserved_height' => self::RESERVED_HEIGHT,
            'enemy_items' => array_map($enrich, $raw['pending_battle']['enemy_items']),
            'round' => $raw['round'],
            'wins' => $raw['wins'],
            'losses' => $raw['losses'],
        ];
    }

    public function finishBattle(): array
    {
        $raw = $this->load();
        $pending = $raw['pending_battle'];
        if ($pending === null) {
            throw new \InvalidArgumentException('進行中の戦闘がありません。');
        }

        $winner = $pending['result']['winner'];
        if ($winner === 'player') {
            $raw['wins']++;
            // ランク制度(企画書4.7)はランクマッチのみが対象。カジュアルマッチの勝利では加算しない。
            if ($raw['mode'] === 'rank') {
                $this->saveRankPoints($this->loadRankPoints() + self::RANK_POINTS_PER_WIN);
            }
        } elseif ($winner === 'enemy') {
            $raw['losses']++;
        }
        // マニーは勝敗に関係なく毎戦固定で加算する
        $raw['money'] += self::WIN_REWARD;
        // 引き分けは勝敗数に加算しないが、消化ラウンド数は進める

        $raw['round']++;
        $raw['pending_unlock_tokens'] += self::UNLOCK_TOKENS_PER_BATTLE;

        if ($raw['wins'] >= self::WINS_TO_CLEAR) {
            $raw['match_status'] = 'clear';
        } elseif ($raw['losses'] >= self::MAX_LOSSES) {
            $raw['match_status'] = 'game_over';
        } elseif ($raw['round'] > self::MAX_ROUNDS) {
            $raw['match_status'] = 'finished';
        } else {
            $raw['match_status'] = 'in_progress';
        }

        $raw['pending_battle'] = null;
        // バトル準備画面に戻るタイミングでショップの品揃えを無償で更新する
        $raw['shop_offers'] = $this->rollShopOffers();

        $this->save($raw);

        return [
            'winner' => $winner,
            'match_status' => $raw['match_status'],
            'wins' => $raw['wins'],
            'losses' => $raw['losses'],
            'round' => min($raw['round'], self::MAX_ROUNDS),
            'next' => $raw['match_status'] === 'in_progress' ? 'battle-prep' : 'title',
        ];
    }

    private function grid(): BackpackGrid
    {
        return new BackpackGrid(self::RESERVED_WIDTH, self::RESERVED_HEIGHT);
    }

    /**
     * @return array<int, array{0:int,1:int}>
     */
    private function initialUnlockedCells(): array
    {
        $cells = [];
        for ($x = self::INITIAL_UNLOCK_X; $x < self::INITIAL_UNLOCK_X + self::INITIAL_UNLOCK_WIDTH; $x++) {
            for ($y = self::INITIAL_UNLOCK_Y; $y < self::INITIAL_UNLOCK_Y + self::INITIAL_UNLOCK_HEIGHT; $y++) {
                $cells[] = [$x, $y];
            }
        }

        return $cells;
    }

    /**
     * @return array<int, array{id:string,item_type:string,item_key:string,price:int}>
     */
    private function rollShopOffers(): array
    {
        $pool = [
            ...$this->catalog->draftableWeapons()->map(fn ($w) => ['item_type' => 'weapon', 'item_key' => $w->key, 'price' => $w->price])->all(),
            ...$this->catalog->materials()->map(fn ($m) => ['item_type' => 'material', 'item_key' => $m->key, 'price' => $m->price])->all(),
        ];

        shuffle($pool);
        $offers = array_slice($pool, 0, self::SHOP_SIZE);

        return array_map(function ($o) {
            // 武器オファーのみ、一定確率で半額(端数切り上げ)になる。素材は対象外。
            $discounted = $o['item_type'] === 'weapon'
                && mt_rand() / mt_getrandmax() < config('game.shop_weapon_discount_chance');

            return [
                'id' => 'shop_'.Str::random(8),
                'item_type' => $o['item_type'],
                'item_key' => $o['item_key'],
                'price' => $discounted ? intdiv($o['price'] + 1, 2) : $o['price'],
                'original_price' => $discounted ? $o['price'] : null,
            ];
        }, $offers);
    }

    private function saleValue(string $itemType, string $itemKey): int
    {
        $price = $itemType === 'weapon' ? $this->catalog->weapon($itemKey)->price : $this->catalog->material($itemKey)->price;

        return intdiv($price + 1, 2);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function dimensionsOf(string $type, string $key): array
    {
        if ($type === 'weapon') {
            $weapon = $this->catalog->weapon($key);

            return [$weapon->grid_width, $weapon->grid_height];
        }
        $material = $this->catalog->material($key);

        return [$material->grid_width, $material->grid_height];
    }

    /**
     * @return PlacedItem[]
     */
    private function itemsFromRaw(array $rawItems): array
    {
        return array_map(fn (array $i) => PlacedItem::fromArray($i), $rawItems);
    }

    private function findIndexById(array $entries, string $id): ?int
    {
        foreach ($entries as $index => $entry) {
            if ($entry['id'] === $id) {
                return $index;
            }
        }

        return null;
    }

    private function load(): array
    {
        $raw = session(self::SESSION_KEY);
        if (! is_array($raw)) {
            throw new \InvalidArgumentException('マッチが開始されていません。タイトルからやり直してください。');
        }

        return $raw;
    }

    private function save(array $raw): void
    {
        session([self::SESSION_KEY => $raw]);
    }

    private function loadRankPoints(): int
    {
        return (int) session(self::RANK_SESSION_KEY, 0);
    }

    private function saveRankPoints(int $points): void
    {
        session([self::RANK_SESSION_KEY => $points]);
    }

    /**
     * バトル準備画面ワイヤーフレーム仕様7章: 装備中の全武器について
     * 「ST消費/クールダウン攻撃間隔秒数」を合計し、範囲に応じた文言を返す。
     *
     * @param  array<int, array<string, mixed>>  $attackers  SynergyCalculator::calculate() の attackers
     */
    private function stConsumptionLabel(array $attackers): string
    {
        $rate = 0.0;
        foreach ($attackers as $attacker) {
            $cooldown = $attacker['cooldown_seconds'] ?? null;
            if (! $cooldown) {
                continue;
            }
            $rate += ($attacker['stamina_cost'] ?? 0) / $cooldown;
        }

        return match (true) {
            $rate < 0.50 => '非常に少ない',
            $rate < 0.90 => '少ない',
            $rate < 1.20 => 'ほどほど',
            $rate < 1.50 => '多い',
            default => '非常に多い',
        };
    }

    /**
     * 武器情報ツールチップ(item_tooltip_wireframe)向けの共通フィールド。
     * バックパック内・一時置き場・ショップのいずれの一覧でも同じ内容を返す。
     * 素材にはrarity列が無いため、レアリティ未設定(星0埋め表示)として扱う。
     *
     * @param  MasterDataEntry  $meta
     */
    private function tooltipFields(string $itemType, $meta): array
    {
        return [
            'rarity' => $itemType === 'weapon' ? $meta->rarity : null,
            'skill_detail' => $itemType === 'weapon'
                ? EffectRegistry::describe($meta->special_effect_key)
                : $meta->effect_description,
            'category' => $itemType === 'weapon' ? '武器' : '素材',
            // 命中率はツールチップ表示専用(戦闘シミュレーションには反映しない)。
            'accuracy' => $itemType === 'weapon' ? $meta->accuracy : null,
        ];
    }

    private function presentSynthesis(array $pendingSynthesis): array
    {
        return array_map(fn ($s) => [
            'recipe' => $s['recipe'],
            'output' => $s['output'],
            'consumed' => $s['consumed'],
        ], $pendingSynthesis);
    }

    private function present(array $raw): array
    {
        $items = $this->itemsFromRaw($raw['placed_items']);
        $grid = $this->grid();
        $synergy = $this->synergy->calculate($items, $grid);
        $attackersByPlacementId = collect($synergy['attackers'])->keyBy('placed_item_id');

        // 合成候補はバックパックの中身から都度検出する(配置・移動・購入など、あらゆる
        // 操作の直後に最新の候補が画面に反映されるようにするため。startBattle()も同様)。
        $detectedSynthesis = $this->synthesis->detect($items, $grid);
        $highlightedIds = collect($detectedSynthesis)->flatMap(fn ($s) => $s['consumed'])->all();

        $placedItems = array_map(function (PlacedItem $item) use ($attackersByPlacementId, $highlightedIds) {
            $meta = $item->itemType === 'weapon' ? $this->catalog->weapon($item->itemKey) : $this->catalog->material($item->itemKey);
            $attacker = $attackersByPlacementId->get($item->id);

            return [
                ...$item->toArray(),
                'name' => $meta->name,
                'image_url' => $meta->image_url,
                'base_atk' => $item->itemType === 'weapon' ? (int) $meta->atk : null,
                'base_spd' => $item->itemType === 'weapon' ? $meta->spd : null,
                'effective_atk' => $attacker['effective_atk'] ?? null,
                'effective_spd' => $attacker['effective_spd'] ?? null,
                'atk_buff_percent' => $attacker['atk_buff_percent'] ?? 0,
                'spd_buff_percent' => $attacker['spd_buff_percent'] ?? 0,
                'double_hit' => $attacker['double_hit'] ?? false,
                // 攻撃1回あたりのスタミナ消費量とクールダウン秒数。数値は仮置き(database/data/weapons.json参照)。
                'stamina_cost' => $item->itemType === 'weapon' ? $meta->stamina_cost : null,
                'cooldown_seconds' => $item->itemType === 'weapon' ? $meta->cooldown_seconds : null,
                ...$this->tooltipFields($item->itemType, $meta),
                // 商売人へドラッグ&ドロップで売却する際の買取額プレビュー用。
                'sale_value' => $this->saleValue($item->itemType, $item->itemKey),
                'synthesis_candidate' => in_array($item->id, $highlightedIds, true),
            ];
        }, $items);

        $tempStorage = array_map(function (array $entry) {
            $meta = $entry['item_type'] === 'weapon' ? $this->catalog->weapon($entry['item_key']) : $this->catalog->material($entry['item_key']);

            return [
                'id' => $entry['id'],
                'item_type' => $entry['item_type'],
                'item_key' => $entry['item_key'],
                'name' => $meta->name,
                'image_url' => $meta->image_url,
                'grid_width' => $meta->grid_width,
                'grid_height' => $meta->grid_height,
                'price' => $meta->price,
                'sale_value' => $this->saleValue($entry['item_type'], $entry['item_key']),
                'atk' => $entry['item_type'] === 'weapon' ? (int) $meta->atk : null,
                'spd' => $entry['item_type'] === 'weapon' ? $meta->spd : null,
                'stamina_cost' => $entry['item_type'] === 'weapon' ? $meta->stamina_cost : null,
                'cooldown_seconds' => $entry['item_type'] === 'weapon' ? $meta->cooldown_seconds : null,
                ...$this->tooltipFields($entry['item_type'], $meta),
            ];
        }, $raw['temp_storage']);

        $shopOffers = array_map(function (array $offer) {
            $meta = $offer['item_type'] === 'weapon' ? $this->catalog->weapon($offer['item_key']) : $this->catalog->material($offer['item_key']);

            return [
                'id' => $offer['id'],
                'item_type' => $offer['item_type'],
                'item_key' => $offer['item_key'],
                'name' => $meta->name,
                'image_url' => $meta->image_url,
                'price' => $offer['price'],
                'original_price' => $offer['original_price'] ?? null,
                'grid_width' => $meta->grid_width,
                'grid_height' => $meta->grid_height,
                'atk' => $offer['item_type'] === 'weapon' ? (int) $meta->atk : null,
                'spd' => $offer['item_type'] === 'weapon' ? $meta->spd : null,
                'stamina_cost' => $offer['item_type'] === 'weapon' ? $meta->stamina_cost : null,
                'cooldown_seconds' => $offer['item_type'] === 'weapon' ? $meta->cooldown_seconds : null,
                ...$this->tooltipFields($offer['item_type'], $meta),
            ];
        }, $raw['shop_offers']);

        return [
            'mode' => $raw['mode'],
            'money' => $raw['money'],
            'wins' => $raw['wins'],
            'losses' => $raw['losses'],
            'lives_remaining' => self::MAX_LOSSES - $raw['losses'],
            'rank' => $raw['mode'] === 'rank' ? $this->rankTable->resolve($this->loadRankPoints()) : null,
            'round' => min($raw['round'], self::MAX_ROUNDS),
            'max_rounds' => self::MAX_ROUNDS,
            'wins_to_clear' => self::WINS_TO_CLEAR,
            'match_status' => $raw['match_status'],
            'base_hp' => config('game.base_hp'),
            'bonus_hp' => $synergy['bonus_hp'],
            // ST(スタミナ)はバトル開始時にconfig('game.base_st')を満タンとして持ち込むため、
            // 準備画面では常に上限値を表示する(実際の増減はバトル画面側でのみ発生する)。
            'st' => config('game.base_st'),
            'st_consumption_label' => $this->stConsumptionLabel($synergy['attackers']),
            'grid' => [
                'reserved_width' => self::RESERVED_WIDTH,
                'reserved_height' => self::RESERVED_HEIGHT,
                'unlocked_cells' => $raw['unlocked_cells'],
                'unlockable_cells' => $raw['pending_unlock_tokens'] > 0 ? $this->unlockableCells($raw['unlocked_cells']) : [],
            ],
            'pending_unlock_tokens' => $raw['pending_unlock_tokens'],
            'placed_items' => $placedItems,
            'temp_storage' => $tempStorage,
            'shop_offers' => $shopOffers,
            'shop_reroll_cost' => self::SHOP_REROLL_COST,
            'pending_synthesis' => $this->presentSynthesis($detectedSynthesis),
        ];
    }
}
