@extends('tool::layout')

@section('title', 'CPUの貼り付けインポート')

@section('content')
    <h2>CPU: Excelから貼り付けてインポート</h2>

    <form method="POST" action="{{ route('tool.cpu.import.submit') }}">
        @csrf

        <label>Excelから貼り付け(1行目は見出し、タブ区切り)
            <textarea name="data" rows="14" style="width:100%;font-family:monospace;font-size:13px;">{{ $raw }}</textarea>
        </label>
        <p class="hint">
            見出し列(この順でなくても可): key, name, description, difficulty_tier, layout<br>
            すべて必須。difficulty_tierは1〜5の整数。layout列は1セルに
            <code>weapon:sword:0:0:1:2;material:whetstone:1:0:1:1</code> のように
            <code>item_type:item_key:x:y:width:height</code> を <code>;</code> 区切りで並べる
            (グリッドは{{ \App\Services\MatchStateService::RESERVED_WIDTH }}×{{ \App\Services\MatchStateService::RESERVED_HEIGHT }})。<br>
            既存keyと一致する行は更新、一致しない行は新規追加。貼り付けデータに含まれない既存のCPUパターンはそのまま残る。
        </p>

        @include('tool::partials.import_preview', ['preview' => $preview])

        <div class="actions">
            <button type="submit">プレビュー</button>
            @if ($preview && $preview['global_error'] === null && $preview['error_count'] === 0 && $preview['row_count'] > 0)
                <button type="submit" name="confirmed" value="1">この内容で反映する</button>
            @endif
            <a class="button-link secondary" href="{{ route('tool.cpu.index') }}">一覧に戻る</a>
        </div>
    </form>
@endsection
