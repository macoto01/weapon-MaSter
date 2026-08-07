@extends('tool::layout')

@section('title', '素材情報')

@section('content')
    <h2>素材一覧({{ $materials->count() }}件)</h2>
    <p>
        <a class="button-link secondary" href="{{ route('tool.materials.import') }}">Excelから貼り付けてインポート</a>
        <a class="button-link secondary" href="{{ route('tool.materials.export') }}">TSVでエクスポート</a>
    </p>

    <table>
        <thead>
            <tr>
                <th>key</th>
                <th>名前</th>
                <th>サイズ</th>
                <th>効果</th>
                <th>価格</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse ($materials as $material)
            <tr>
                <td>{{ $material->key }}</td>
                <td>{{ $material->name }}</td>
                <td>{{ $material->grid_width }}×{{ $material->grid_height }}</td>
                <td>{{ $material->effect_description }}</td>
                <td>{{ $material->price }}</td>
                <td>
                    <a class="edit-link" href="{{ route('tool.materials.edit', $material->key) }}">編集</a>
                    <form method="POST" action="{{ route('tool.materials.destroy', $material->key) }}" style="display:inline;" onsubmit="return confirm('「{{ $material->name }}」を削除しますか?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="button-link secondary" style="padding:2px 8px;font-size:12px;">削除</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6">登録されている素材はありません。</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
