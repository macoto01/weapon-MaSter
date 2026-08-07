@extends('tool::layout')

@section('title', 'ダッシュボード')

@section('content')
    <h2>管理メニュー</h2>
    <ul class="menu-list">
        <li><a href="{{ route('tool.weapons.index') }}">武器情報の更新</a></li>
        <li><a href="{{ route('tool.materials.index') }}">素材情報の更新</a></li>
        <li><a href="{{ route('tool.armors.index') }}">防具情報の更新</a></li>
        <li><a href="{{ route('tool.relics.index') }}">秘宝情報の更新</a></li>
        <li><a href="{{ route('tool.recipes.index') }}">合成レシピの更新</a></li>
        <li><a href="{{ route('tool.cpu.index') }}">CPU管理</a></li>
        <li><a href="{{ route('tool.bgm.index') }}">BGM管理</a></li>
        <li><a href="{{ route('tool.users.index') }}">ユーザーデータの確認(準備中)</a></li>
        <li><a href="{{ route('tool.dummy-data.index') }}">ダミーデータの自動編成(準備中)</a></li>
    </ul>
@endsection
