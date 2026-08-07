<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>図鑑 - ウエポンマスター</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="encyclopedia-header">
        <h1 style="font-size:16px;margin:0;">図鑑</h1>
        <button id="back-button" type="button">戻る</button>
    </header>

    <div class="encyclopedia-layout">
        <div id="tag-list" class="tag-list"></div>
        <div id="entry-list" class="entry-list"></div>
    </div>

    <script src="{{ asset('js/encyclopedia.js') }}"></script>
</body>
</html>
