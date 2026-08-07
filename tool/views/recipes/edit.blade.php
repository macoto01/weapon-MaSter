@extends('tool::layout')

@section('title', $recipe->name.' を編集')

@section('content')
    <h2>合成レシピ: {{ $recipe->name }}({{ $recipe->key }})を編集</h2>

    @if ($errors->any())
        <div class="status status--error">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="edit-form" method="POST" action="{{ route('tool.recipes.update', $recipe->key) }}">
        @csrf
        @method('PUT')

        <label>名前
            <input type="text" name="name" value="{{ old('name', $recipe->name) }}" required>
        </label>

        <label>完成する武器
            @php $currentOutput = old('output_weapon_key', $recipe->output_weapon_key); @endphp
            <select name="output_weapon_key" required>
                @foreach ($weaponKeys as $weaponKey)
                    <option value="{{ $weaponKey }}" {{ $currentOutput === $weaponKey ? 'selected' : '' }}>{{ $weaponKey }}</option>
                @endforeach
            </select>
        </label>

        <p class="hint" style="margin-bottom:4px;">素材(武器または素材の組み合わせ、最低1件)</p>
        <div id="recipe-inputs"></div>
        <p><button type="button" id="recipe-inputs-add" class="button-link secondary">素材を追加</button></p>

        <div class="actions">
            <button type="submit">保存</button>
            <a class="button-link secondary" href="{{ route('tool.recipes.index') }}">キャンセル</a>
        </div>
    </form>

    <template id="recipe-input-row-template">
        <div class="recipe-input-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
            <select class="recipe-input-type">
                <option value="weapon">武器</option>
                <option value="material">素材</option>
            </select>
            <input type="text" class="recipe-input-key" placeholder="key" list="recipe-master-keys">
            <button type="button" class="recipe-input-remove button-link secondary" style="padding:2px 8px;">削除</button>
        </div>
    </template>

    <datalist id="recipe-master-keys">
        @foreach ($weaponKeys as $weaponKey)
            <option value="{{ $weaponKey }}">
        @endforeach
        @foreach ($materialKeys as $materialKey)
            <option value="{{ $materialKey }}">
        @endforeach
    </datalist>

    <script>
        (function () {
            const container = document.getElementById('recipe-inputs');
            const template = document.getElementById('recipe-input-row-template');
            const addButton = document.getElementById('recipe-inputs-add');
            const form = document.querySelector('form.edit-form');
            const initialInputs = @json(old('inputs', $recipe->inputs));

            function addRow(type, key) {
                const row = template.content.firstElementChild.cloneNode(true);
                row.querySelector('.recipe-input-type').value = type ?? 'weapon';
                row.querySelector('.recipe-input-key').value = key ?? '';
                row.querySelector('.recipe-input-remove').addEventListener('click', () => row.remove());
                container.appendChild(row);
            }

            (initialInputs.length ? initialInputs : [{}]).forEach((input) => addRow(input.type, input.key));

            addButton.addEventListener('click', () => addRow());

            form.addEventListener('submit', () => {
                container.querySelectorAll('.recipe-input-row').forEach((row, index) => {
                    const type = row.querySelector('.recipe-input-type').value;
                    const key = row.querySelector('.recipe-input-key').value;

                    const typeInput = document.createElement('input');
                    typeInput.type = 'hidden';
                    typeInput.name = `inputs[${index}][type]`;
                    typeInput.value = type;
                    row.appendChild(typeInput);

                    const keyInput = document.createElement('input');
                    keyInput.type = 'hidden';
                    keyInput.name = `inputs[${index}][key]`;
                    keyInput.value = key;
                    row.appendChild(keyInput);
                });
            });
        })();
    </script>
@endsection
