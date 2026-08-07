<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', '管理ツール') - ウエポンマスター管理ツール</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Hiragino Kaku Gothic ProN", sans-serif; margin: 0; background: #f4f5f7; color: #222; }
        header { background: #2b2f77; color: #fff; padding: 12px 20px; display: flex; align-items: center; gap: 20px; }
        header a { color: #cfd2ff; text-decoration: none; font-size: 14px; }
        header a:hover { color: #fff; }
        header h1 { font-size: 16px; margin: 0 auto 0 0; }
        main { padding: 20px; max-width: 960px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { border: 1px solid #ddd; padding: 6px 10px; font-size: 13px; text-align: left; }
        th { background: #eee; }
        .status { background: #e1f5ee; border: 1px solid #1d9e75; padding: 8px 12px; margin-bottom: 16px; border-radius: 4px; font-size: 14px; }
        .status--error { background: #fde8e8; border-color: #d22; }
        .status--error ul { margin: 0; padding-left: 20px; }
        form.edit-form label { display: block; margin-bottom: 12px; font-size: 13px; }
        form.edit-form input, form.edit-form select { width: 100%; padding: 6px; margin-top: 4px; font-size: 14px; }
        form.edit-form .checkbox-row { display: flex; align-items: center; gap: 8px; }
        form.edit-form .checkbox-row input { width: auto; }
        .actions { margin-top: 16px; display: flex; gap: 10px; }
        button, .button-link { padding: 8px 16px; border: none; background: #2b2f77; color: #fff; border-radius: 4px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        .button-link.secondary { background: #999; }
        a.edit-link { color: #2b2f77; }
        .menu-list { list-style: none; padding: 0; }
        .menu-list li { margin-bottom: 8px; }
        .menu-list a { font-size: 15px; }
        .hint { font-size: 12px; color: #666; line-height: 1.6; }
        textarea { font-family: monospace; }
        .grid-preview {
            display: grid;
            grid-template-columns: repeat(var(--grid-w), 32px);
            grid-template-rows: repeat(var(--grid-h), 32px);
            gap: 2px;
            width: max-content;
            margin-bottom: 16px;
        }
        .grid-preview__cell { background: #e9eaef; border: 1px solid #ccc; }
        .grid-preview__item {
            border-radius: 3px;
            font-size: 10px;
            line-height: 1.2;
            padding: 2px;
            overflow: hidden;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            word-break: break-all;
            cursor: default;
        }
        .grid-preview__item--weapon { background: #2b6cb0; }
        .grid-preview__item--material { background: #2f855a; }
        .grid-preview__item--error { background: #c53030; }
    </style>
</head>
<body>
    <header>
        <h1>ウエポンマスター 管理ツール</h1>
        <a href="{{ route('tool.dashboard') }}">ダッシュボード</a>
        <a href="{{ route('tool.weapons.index') }}">武器情報</a>
        <a href="{{ route('tool.bgm.index') }}">BGM管理</a>
        <a href="{{ route('tool.users.index') }}">ユーザーデータ</a>
        <a href="{{ route('tool.dummy-data.index') }}">ダミーデータ</a>
    </header>
    <main>
        @if (session('status'))
            <div class="status">{{ session('status') }}</div>
        @endif
        @yield('content')
    </main>
</body>
</html>
