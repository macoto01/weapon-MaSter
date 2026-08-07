@extends('tool::layout')

@section('title', 'BGM管理')

@section('content')
    <h2>BGM一覧({{ count($tracks) }}件)</h2>

    @if ($errors->any())
        <div class="status status--error">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>key</th>
                <th>名前</th>
                <th>ファイル</th>
                <th>試聴</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse ($tracks as $track)
            <tr>
                <td>{{ $track['key'] }}</td>
                <td>{{ $track['name'] }}</td>
                <td>{{ $track['file'] }}</td>
                <td><audio controls preload="none" src="{{ asset('audio/bgm/'.$track['file']) }}" style="height:28px;"></audio></td>
                <td>
                    <form method="POST" action="{{ route('tool.bgm.destroy', $track['key']) }}" onsubmit="return confirm('「{{ $track['name'] }}」を削除しますか?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="button-link secondary">削除</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5">登録されているBGMはありません。</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2 style="margin-top:24px;">BGMを追加</h2>
    <form class="edit-form" method="POST" action="{{ route('tool.bgm.store') }}" enctype="multipart/form-data">
        @csrf
        <label>曲名
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="50">
        </label>
        <label>音声ファイル(mp3 / ogg / wav / m4a、20MBまで)
            <input type="file" name="file" accept=".mp3,.ogg,.wav,.m4a" required>
        </label>
        <div class="actions">
            <button type="submit">追加</button>
        </div>
    </form>

    <h2 style="margin-top:24px;">画面ごとの再生曲</h2>
    @foreach ($screens as $screenKey => $screenLabel)
        <form class="edit-form" method="POST" action="{{ route('tool.bgm.assign') }}" style="margin-bottom:12px;">
            @csrf
            <input type="hidden" name="screen" value="{{ $screenKey }}">
            <label>{{ $screenLabel }}
                <select name="key" onchange="this.form.submit()">
                    <option value="" {{ ($assignments[$screenKey] ?? null) === null ? 'selected' : '' }}>なし(BGMを再生しない)</option>
                    @foreach ($tracks as $track)
                        <option value="{{ $track['key'] }}" {{ ($assignments[$screenKey] ?? null) === $track['key'] ? 'selected' : '' }}>{{ $track['name'] }}</option>
                    @endforeach
                </select>
            </label>
        </form>
    @endforeach
@endsection
