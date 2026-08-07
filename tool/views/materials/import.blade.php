@extends('tool::layout')

@section('title', '素材の貼り付けインポート')

@section('content')
    <h2>素材: Excelから貼り付けてインポート</h2>

    <form method="POST" action="{{ route('tool.materials.import.submit') }}">
        @csrf

        <label>Excelから貼り付け(1行目は見出し、タブ区切り)
            <textarea name="data" rows="14" style="width:100%;font-family:monospace;font-size:13px;">{{ $raw }}</textarea>
        </label>
        <p class="hint">
            見出し列(この順でなくても可): key, name, grid_width, grid_height, effect_type, effect_value, effect_range, price<br>
            すべて必須。effect_typeは buff_atk / buff_spd / buff_hp、effect_rangeは adjacent / global のいずれか。<br>
            既存keyと一致する行は更新、一致しない行は新規追加。貼り付けデータに含まれない既存の素材はそのまま残る。
        </p>

        @include('tool::partials.import_preview', ['preview' => $preview])

        <div class="actions">
            <button type="submit">プレビュー</button>
            @if ($preview && $preview['global_error'] === null && $preview['error_count'] === 0 && $preview['row_count'] > 0)
                <button type="submit" name="confirmed" value="1">この内容で反映する</button>
            @endif
            <a class="button-link secondary" href="{{ route('tool.materials.index') }}">一覧に戻る</a>
        </div>
    </form>
@endsection
