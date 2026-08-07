@extends('tool::layout')

@section('title', $pattern ? $pattern->name.' を編集' : 'CPUを新規追加')

@section('content')
    <h2>{{ $pattern ? "{$pattern->name}({$pattern->key})を編集" : 'CPUを新規追加' }}</h2>

    @if ($errors->any())
        <div class="status status--error">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="edit-form" method="POST" action="{{ $pattern ? route('tool.cpu.update', $pattern->key) : route('tool.cpu.store') }}">
        @csrf
        @if ($pattern)
            @method('PUT')
        @else
            <label>key(半角英数字・アンダースコアのみ、登録後は変更不可)
                <input type="text" name="key" value="{{ old('key') }}" required pattern="[a-z0-9_]+">
            </label>
        @endif

        <label>名前
            <input type="text" name="name" value="{{ old('name', $pattern->name ?? '') }}" required maxlength="50">
        </label>

        <label>説明
            <input type="text" name="description" value="{{ old('description', $pattern->description ?? '') }}" required maxlength="200">
        </label>

        <label>強さ(1=最弱〜5=最強)
            @php $currentTier = old('difficulty_tier', $pattern->difficulty_tier ?? 1); @endphp
            <select name="difficulty_tier">
                @for ($tier = 1; $tier <= 5; $tier++)
                    <option value="{{ $tier }}" {{ (int) $currentTier === $tier ? 'selected' : '' }}>
                        {{ $tier }}({{ str_repeat('★', $tier) }}{{ str_repeat('☆', 5 - $tier) }})
                    </option>
                @endfor
            </select>
        </label>

        <label>配置(layout, JSON配列)
            <textarea name="layout" rows="14" style="width:100%;font-family:monospace;font-size:13px;">{{ old('layout', $layoutJson) }}</textarea>
        </label>
        <p class="hint">
            CPU用バックパックは{{ $gridWidth }}(横)×{{ $gridHeight }}(縦)固定です。各要素は
            <code>{"item_type":"weapon|material","item_key":"...","x":0,"y":0,"width":1,"height":1}</code>
            の形式で、範囲外・重なりはエラーになります。<br>
            武器key一覧: {{ $weaponKeys->implode('、') }}<br>
            素材key一覧: {{ $materialKeys->implode('、') }}
        </p>

        <p class="hint" style="margin-bottom:4px;">
            配置プレビュー({{ $gridWidth }}×{{ $gridHeight }}、上のJSONを編集すると自動更新されます)
        </p>
        <p id="cpu-grid-preview-status" class="status status--error" style="display:none;"></p>
        <div id="cpu-grid-preview" class="grid-preview" style="--grid-w:{{ $gridWidth }};--grid-h:{{ $gridHeight }};">
            @for ($y = 0; $y < $gridHeight; $y++)
                @for ($x = 0; $x < $gridWidth; $x++)
                    <div class="grid-preview__cell" style="grid-column:{{ $x + 1 }};grid-row:{{ $y + 1 }};"></div>
                @endfor
            @endfor
        </div>

        <div class="actions">
            <button type="submit">保存</button>
            <a class="button-link secondary" href="{{ route('tool.cpu.index') }}">キャンセル</a>
        </div>
    </form>

    <script>
        (function () {
            const textarea = document.querySelector('textarea[name="layout"]');
            const grid = document.getElementById('cpu-grid-preview');
            const statusEl = document.getElementById('cpu-grid-preview-status');
            const gridWidth = {{ $gridWidth }};
            const gridHeight = {{ $gridHeight }};

            function showStatus(message) {
                if (! message) {
                    statusEl.style.display = 'none';
                    statusEl.textContent = '';
                    return;
                }
                statusEl.style.display = 'block';
                statusEl.textContent = message;
            }

            function render() {
                grid.querySelectorAll('.grid-preview__item').forEach((el) => el.remove());

                let layout;
                try {
                    layout = JSON.parse(textarea.value || '[]');
                } catch (e) {
                    showStatus('JSONを解析できません(構文エラー)。');
                    return;
                }
                if (! Array.isArray(layout)) {
                    showStatus('layoutは配列で入力してください。');
                    return;
                }
                showStatus(null);

                layout.forEach((item) => {
                    if (! item || typeof item !== 'object') {
                        return;
                    }
                    const { item_type, item_key, x, y, width, height } = item;
                    const w = Math.max(1, Number(width) || 1);
                    const h = Math.max(1, Number(height) || 1);
                    const type = item_type === 'weapon' || item_type === 'material' ? item_type : 'error';

                    const el = document.createElement('div');
                    el.className = 'grid-preview__item grid-preview__item--' + type;
                    el.style.gridColumn = (Number(x) + 1) + ' / span ' + w;
                    el.style.gridRow = (Number(y) + 1) + ' / span ' + h;
                    el.textContent = item_key ?? '?';
                    el.title = (item_key ?? '?') + ' (' + x + ',' + y + ') ' + w + 'x' + h;
                    grid.appendChild(el);
                });
            }

            textarea.addEventListener('input', render);
            render();
        })();
    </script>
@endsection
