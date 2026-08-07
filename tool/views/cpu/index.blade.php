@extends('tool::layout')

@section('title', 'CPU管理')

@section('content')
    <h2>CPU一覧({{ $patterns->count() }}件)</h2>
    <p>
        <a class="button-link" href="{{ route('tool.cpu.create') }}">新規CPUを追加</a>
        <a class="button-link secondary" href="{{ route('tool.cpu.import') }}">Excelから貼り付けてインポート</a>
        <a class="button-link secondary" href="{{ route('tool.cpu.export') }}">TSVでエクスポート</a>
    </p>

    <table>
        <thead>
            <tr>
                <th>tier</th>
                <th>key</th>
                <th>名前</th>
                <th>説明</th>
                <th>アイテム数</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse ($patterns as $pattern)
            <tr>
                <td>{{ str_repeat('★', $pattern->difficulty_tier) }}{{ str_repeat('☆', 5 - $pattern->difficulty_tier) }}</td>
                <td>{{ $pattern->key }}</td>
                <td>{{ $pattern->name }}</td>
                <td>{{ $pattern->description }}</td>
                <td>{{ count($pattern->layout) }}</td>
                <td>
                    <a class="edit-link" href="{{ route('tool.cpu.edit', $pattern->key) }}">編集</a>
                    <form method="POST" action="{{ route('tool.cpu.destroy', $pattern->key) }}" style="display:inline;" onsubmit="return confirm('「{{ $pattern->name }}」を削除しますか?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="button-link secondary" style="padding:2px 8px;font-size:12px;">削除</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6">登録されているCPUはありません。</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
