<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ウエポンマスター</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <div class="title-screen">
        <h1 class="logo">ウエポンマスター</h1>
        <div class="title-buttons">
            <button id="rank-match-button" type="button">ランクマッチ</button>
            <button id="casual-match-button" type="button">カジュアルマッチ</button>
            <button id="encyclopedia-button" type="button">図鑑</button>
        </div>
    </div>

    <button id="howto-button" class="howto-button" type="button">あそびかた</button>

    <div id="howto-overlay" class="overlay" hidden>
        <div class="overlay-box">
            <h2>あそびかた</h2>
            <p class="hint">
                バックパックのマスに武器・素材を配置すると、隣接したアイテム同士でシナジーが発生します。<br>
                CPUと自動戦闘を行い、戦闘後に隣接していた組み合わせがレシピと一致すると合成されます。<br>
                (解説画像は準備中のため、テキストでの仮説明です)
            </p>
            <button id="howto-close-button" type="button">閉じる</button>
        </div>
    </div>

    <script src="{{ asset('js/title.js') }}"></script>
</body>
</html>
