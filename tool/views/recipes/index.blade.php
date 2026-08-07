@extends('tool::layout')

@section('title', '合成レシピ')

@section('content')
    <h2>合成レシピ一覧({{ $recipes->count() }}件)</h2>
    <p>
        <a class="button-link secondary" href="{{ route('tool.recipes.import') }}">Excelから貼り付けてインポート</a>
        <a class="button-link secondary" href="{{ route('tool.recipes.export') }}">TSVでエクスポート</a>
    </p>

    <table>
        <thead>
            <tr>
                <th>key</th>
                <th>名前</th>
                <th>素材</th>
                <th>完成する武器</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse ($recipes as $recipe)
            <tr>
                <td>{{ $recipe->key }}</td>
                <td>{{ $recipe->name }}</td>
                <td>{{ collect($recipe->inputs)->map(fn ($input) => $input['key'])->implode(' + ') }}</td>
                <td>{{ $recipe->output_weapon_key }}</td>
                <td>
                    <a class="edit-link" href="{{ route('tool.recipes.edit', $recipe->key) }}">編集</a>
                    <form method="POST" action="{{ route('tool.recipes.destroy', $recipe->key) }}" style="display:inline;" onsubmit="return confirm('「{{ $recipe->name }}」を削除しますか?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="button-link secondary" style="padding:2px 8px;font-size:12px;">削除</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5">登録されている合成レシピはありません。</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
