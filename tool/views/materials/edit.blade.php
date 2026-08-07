@extends('tool::layout')

@section('title', $material->name.' を編集')

@section('content')
    <h2>素材: {{ $material->name }}({{ $material->key }})を編集</h2>

    @if ($errors->any())
        <div class="status status--error">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="edit-form" method="POST" action="{{ route('tool.materials.update', $material->key) }}">
        @csrf
        @method('PUT')

        <label>名前
            <input type="text" name="name" value="{{ old('name', $material->name) }}" required>
        </label>

        <label>横幅(マス)
            <input type="number" name="grid_width" value="{{ old('grid_width', $material->grid_width) }}" min="1" max="8" required>
        </label>

        <label>縦幅(マス)
            <input type="number" name="grid_height" value="{{ old('grid_height', $material->grid_height) }}" min="1" max="8" required>
        </label>

        <label>効果種別
            @php $currentType = old('effect_type', $material->effect_type); @endphp
            <select name="effect_type">
                @foreach ($effectTypes as $value => $optionLabel)
                    <option value="{{ $value }}" {{ $currentType === $value ? 'selected' : '' }}>{{ $optionLabel }}</option>
                @endforeach
            </select>
        </label>

        <label>効果量(ATK/SPDは%、HPは数値)
            <input type="number" name="effect_value" value="{{ old('effect_value', $material->effect_value) }}" min="0" required>
        </label>

        <label>効果範囲
            @php $currentRange = old('effect_range', $material->effect_range); @endphp
            <select name="effect_range">
                @foreach ($effectRanges as $value => $optionLabel)
                    <option value="{{ $value }}" {{ $currentRange === $value ? 'selected' : '' }}>{{ $optionLabel }}</option>
                @endforeach
            </select>
        </label>

        <p class="hint">現在の効果文: {{ $material->effect_description }}</p>

        <label>価格(マニー)
            <input type="number" name="price" value="{{ old('price', $material->price) }}" min="0" required>
        </label>

        <div class="actions">
            <button type="submit">保存</button>
            <a class="button-link secondary" href="{{ route('tool.materials.index') }}">キャンセル</a>
        </div>
    </form>
@endsection
