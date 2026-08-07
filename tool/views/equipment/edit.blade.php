@extends('tool::layout')

@section('title', $item->name.' を編集')

@section('content')
    <h2>{{ $label }}: {{ $item->name }}({{ $item->key }})を編集</h2>

    @if ($errors->any())
        <div class="status status--error">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="edit-form" method="POST" action="{{ route($routePrefix.'.update', $item->key) }}">
        @csrf
        @method('PUT')

        <label>名前
            <input type="text" name="name" value="{{ old('name', $item->name) }}" required>
        </label>

        <label>横幅(マス)
            <input type="number" name="grid_width" value="{{ old('grid_width', $item->grid_width) }}" min="1" max="8" required>
        </label>

        <label>縦幅(マス)
            <input type="number" name="grid_height" value="{{ old('grid_height', $item->grid_height) }}" min="1" max="8" required>
        </label>

        <label>ATK
            <input type="number" step="0.1" name="atk" value="{{ old('atk', $item->atk) }}" min="0" required>
        </label>

        <label>SPD(空欄で未設定)
            <input type="number" step="0.1" name="spd" value="{{ old('spd', $item->spd) }}" min="0">
        </label>

        <label>スタミナ消費(空欄で未設定)
            <input type="number" name="stamina_cost" value="{{ old('stamina_cost', $item->stamina_cost) }}" min="0">
        </label>

        <label>使用間隔・秒(空欄で未設定)
            <input type="number" step="0.01" name="cooldown_seconds" value="{{ old('cooldown_seconds', $item->cooldown_seconds) }}" min="0">
        </label>

        <label>命中率・%(空欄で未設定)
            <input type="number" name="accuracy" value="{{ old('accuracy', $item->accuracy) }}" min="0" max="100">
        </label>

        <label>特殊効果
            @php $currentEffect = old('special_effect_key', $item->special_effect_key); @endphp
            <select name="special_effect_key">
                <option value="" {{ $currentEffect === null ? 'selected' : '' }}>なし</option>
                @foreach ($effectOptions as $value => $optionLabel)
                    <option value="{{ $value }}" {{ $currentEffect === $value ? 'selected' : '' }}>{{ $optionLabel }}</option>
                @endforeach
            </select>
        </label>

        <label>役割説明
            <input type="text" name="role" value="{{ old('role', $item->role) }}">
        </label>

        <label class="checkbox-row">
            <input type="checkbox" name="is_synthesized" value="1" {{ old('is_synthesized', $item->is_synthesized) ? 'checked' : '' }}>
            合成生成物である(ショップに出さない)
        </label>

        <label>価格(マニー)
            <input type="number" name="price" value="{{ old('price', $item->price) }}" min="0" required>
        </label>

        <label>レア度(1〜3)
            <input type="number" name="rarity" value="{{ old('rarity', $item->rarity) }}" min="1" max="3" required>
        </label>

        <div class="actions">
            <button type="submit">保存</button>
            <a class="button-link secondary" href="{{ route($routePrefix.'.index') }}">キャンセル</a>
        </div>
    </form>
@endsection
