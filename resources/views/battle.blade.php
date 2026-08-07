<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>バトル - ウエポンマスター</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <main class="battle-layout">
        <!-- 1. プレイヤー情報エリア -->
        <section class="battle-info-row">
            <div class="battle-info-row__backpack">
                <canvas id="player-canvas"></canvas>
            </div>
            <div class="battle-info-row__illustration">プレイヤーイラスト(準備中)</div>
            <div class="battle-info-row__time-controls">
                <div class="time-frame" id="time-label">TIME 0.0s</div>
                <button class="pause-button" id="pause-button" type="button">一時停止</button>
            </div>
        </section>

        <!-- 2. VSパネル -->
        <div class="vs-panel">
            <div class="vs-block player">
                <div class="vs-block__header">プレイヤー</div>
                <div class="vs-block__bars">
                    <div class="vs-block__bars-inner">
                        <div class="hp-bar-track"><div id="player-hp-bar" class="hp-bar-fill" style="width:100%"></div></div>
                        <div id="player-hp-text" class="hint">HP -/-</div>
                        <div class="st-bar-track"><div id="player-st-bar" class="st-bar-fill" style="width:100%"></div></div>
                        <div id="player-st-text" class="hint">ST -</div>
                    </div>
                    <div class="shield-icon" title="盾(耐久値表示は今後実装)">盾</div>
                </div>
                <div class="status-effects-row hint">状態異常・バフ(未定)</div>
            </div>
            <div class="vs-mid">VS</div>
            <div class="vs-block enemy">
                <div class="vs-block__header" id="enemy-name-label">CPU</div>
                <div class="vs-block__bars">
                    <div class="vs-block__bars-inner">
                        <div class="hp-bar-track"><div id="enemy-hp-bar" class="hp-bar-fill" style="width:100%"></div></div>
                        <div id="enemy-hp-text" class="hint">HP -/-</div>
                        <div class="st-bar-track"><div id="enemy-st-bar" class="st-bar-fill" style="width:100%"></div></div>
                        <div id="enemy-st-text" class="hint">ST -</div>
                    </div>
                    <div class="shield-icon" title="盾(耐久値表示は今後実装)">盾</div>
                </div>
                <div class="status-effects-row hint">状態異常・バフ(未定)</div>
            </div>
        </div>

        <!-- 3. 敵情報エリア -->
        <section class="battle-info-row battle-info-row--enemy">
            <div class="battle-info-row__illustration">敵イラスト(準備中)</div>
            <div class="battle-info-row__backpack">
                <canvas id="enemy-canvas"></canvas>
                <button id="skip-button" class="skip-button" type="button">SKIP</button>
            </div>
        </section>

        <div id="battle-log" class="battle-log"></div>
    </main>

    <audio id="battle-bgm" loop preload="auto"></audio>

    <div id="result-overlay" class="overlay" hidden>
        <div class="overlay-box result-popup">
            <div id="result-title" class="result-title">-</div>
            <div>
                <div class="hint" style="text-align:center;">勝利</div>
                <div id="win-marks" class="marks-row"></div>
                <div class="hint" style="text-align:center;">ライフ</div>
                <div id="life-marks" class="marks-row"></div>
            </div>
            <button id="next-button" class="next-button" type="button">次へ</button>
        </div>
    </div>

    <script src="{{ asset('js/grid_renderer.js') }}"></script>
    <script src="{{ asset('js/battle.js') }}"></script>
</body>
</html>
