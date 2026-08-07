<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>バトル準備 - ウエポンマスター</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
    <main class="prep-layout">
        <div class="prep-header-row">
            <div class="prep-panel prep-header-row__player status-block--stacked">
                <span>プレイヤー</span>
                <span id="rank-label" class="rank-label" hidden></span>
            </div>
            <div class="prep-panel prep-header-row__status status-block status-block--stacked">
                <span id="hp-label">HP -</span>
                <span id="st-label">ST -</span>
                <span id="st-consumption-label">ST消費 -</span>
            </div>
            <div class="prep-panel prep-header-row__round"><span id="round-label">ラウンド -</span></div>
            <div class="prep-panel prep-header-row__menu">
                <button id="retire-button" type="button">リタイア</button>
            </div>
        </div>

        <section id="backpack-panel" class="prep-panel">
            <h2>バックパック(8×7予約 / 解放済みマスのみ配置可)</h2>
            <div id="expand-banner" class="expand-banner" hidden>
                バックパック拡張: 「＋」が付いたマスを選んで解放してください(残り<span id="expand-remaining-label">0</span>マス)
            </div>
            <p class="hint">アイテムをドラッグして配置・移動(Rキーで回転)。</p>
            <canvas id="backpack-canvas" class="backpack-canvas"></canvas>
            <div id="item-actions" class="item-actions" hidden>
                <span id="item-actions-label"></span>
                <button id="rotate-button" type="button">回転</button>
                <button id="cancel-select-button" type="button">選択解除</button>
            </div>
        </section>

        <section id="shop-actions-section">
            <div class="prep-panel">
                <h2>ショップ(最大6品)</h2>
                <div id="shop-row" class="shop-row"></div>
                <button id="reroll-button" class="reroll-button" type="button">更新(<span id="reroll-cost">1</span>マニー消費)</button>
            </div>

            <div class="prep-bottom-row">
                <div class="prep-panel temp-storage-panel">
                    <span id="money-label" class="money-badge money-badge--overlay">マニー -</span>
                    <h2>武器置き場</h2>
                    <p class="hint">バックパックに入りきらないアイテムを保管。ドラッグして配置。</p>
                    <div id="temp-storage-row" class="temp-storage-row"></div>
                </div>

                <div class="prep-panel battle-start-frame">
                    <div class="battle-start-frame__button">
                        <button id="start-battle-button" class="start-battle" type="button">バトル開始</button>
                    </div>
                    <div class="battle-start-frame__illustration">
                        <div id="merchant-bubble" class="merchant-bubble">いらっしゃいませ</div>
                        <img id="merchant-image" class="merchant-image" src="{{ asset('images/merchant/merchant.png') }}" data-buy-src="{{ asset('images/merchant/merchant_buy.jpg') }}" alt="商売人">
                    </div>
                </div>
            </div>
        </section>
    </main>

    <div id="confirm-overlay" class="overlay" hidden>
        <div class="overlay-box confirm-popup">
            <h2>合成する武器がありますが問題ないですか?</h2>
            <ul id="confirm-list"></ul>
            <div class="confirm-actions">
                <button id="confirm-yes-button" type="button">はい</button>
                <button id="confirm-no-button" type="button">いいえ</button>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/grid_renderer.js') }}?v={{ filemtime(public_path('js/grid_renderer.js')) }}"></script>
    <script src="{{ asset('js/battle_prep.js') }}?v={{ filemtime(public_path('js/battle_prep.js')) }}"></script>
</body>
</html>
