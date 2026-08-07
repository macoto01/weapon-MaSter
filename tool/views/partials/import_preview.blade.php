@if ($preview)
    @if ($preview['global_error'])
        <div class="status status--error">{{ $preview['global_error'] }}</div>
    @else
        <div class="status {{ $preview['error_count'] > 0 ? 'status--error' : '' }}">
            プレビュー結果: 新規{{ $preview['insert_count'] }}件 / 更新{{ $preview['update_count'] }}件
            @if ($preview['error_count'] > 0)
                / エラー{{ $preview['error_count'] }}件(エラーがある間は反映できません)
            @endif
        </div>
        <table style="margin-bottom:16px;">
            <thead>
                <tr>
                    <th>行</th>
                    <th>key</th>
                    <th>名前</th>
                    <th>結果</th>
                    <th>エラー</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($preview['rows'] as $row)
                <tr>
                    <td>{{ $row['line'] }}</td>
                    <td>{{ $row['key'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td>
                        @if ($row['status'] === 'insert')
                            新規
                        @elseif ($row['status'] === 'update')
                            更新
                        @else
                            エラー
                        @endif
                    </td>
                    <td>{{ implode('、', $row['errors']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endif
