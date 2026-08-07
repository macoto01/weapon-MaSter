@extends('tool::layout')

@section('title', $label.'情報')

@section('content')
    <h2>{{ $label }}一覧({{ $items->count() }}件)</h2>
    <p>
        <a class="button-link secondary" href="{{ route($routePrefix.'.import') }}">Excelから貼り付けてインポート</a>
        <a class="button-link secondary" href="{{ route($routePrefix.'.export') }}">TSVでエクスポート</a>
    </p>

    <table>
        <thead>
            <tr>
                <th>key</th>
                <th>名前</th>
                <th>サイズ</th>
                <th>ATK</th>
                <th>SPD</th>
                <th>価格</th>
                <th>レア度</th>
                <th>合成品</th>
                <th>特殊効果</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse ($items as $item)
            <tr>
                <td>{{ $item->key }}</td>
                <td>{{ $item->name }}</td>
                <td>{{ $item->grid_width }}×{{ $item->grid_height }}</td>
                <td>{{ $item->atk }}</td>
                <td>{{ $item->spd ?? '-' }}</td>
                <td>{{ $item->price }}</td>
                <td>{{ $item->rarity }}</td>
                <td>{{ $item->is_synthesized ? '○' : '' }}</td>
                <td>{{ $item->special_effect_key ?? '-' }}</td>
                <td>
                    <a class="edit-link" href="{{ route($routePrefix.'.edit', $item->key) }}">編集</a>
                    <form method="POST" action="{{ route($routePrefix.'.destroy', $item->key) }}" style="display:inline;" onsubmit="return confirm('「{{ $item->name }}」を削除しますか?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="button-link secondary" style="padding:2px 8px;font-size:12px;">削除</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="10">登録されている{{ $label }}はありません。</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
