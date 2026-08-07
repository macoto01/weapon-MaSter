# CLAUDE.md

Claude Code向けガイド。

## What this is

「ウエポンマスター」— バックパックに武器/素材を配置してシナジー/合成を組み、
CPU相手に自動戦闘するオートバトラー系プロトタイプ。Laravel + Blade/素のJS/CSS。

設計資料は `docs/`:
- `docs/weapon_master_game_design.md` ほか — 企画・技術要件・検証用仕様
- `docs/weapon_master_*_wireframe.svg` + `*_spec.md` — 画面ごとの仕様
- `docs/step/weapon_master_production_implementation_steps.md` — 実装手順書。
  **依頼は基本このStep番号単位**(例:「Step 2-3を実装して」)。各Stepの
  「完了の定義」を実際にブラウザで確認してから完了とする
- `docs/error/*.md` — 過去のエラー記録。同種の問題は先に確認する

## Commands

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --graceful --force
php artisan serve --host=0.0.0.0 --port=8000
lsof -ti:8000 -sTCP:LISTEN | xargs -r kill
```

- Vite不使用。読み込まれるのは `public/js/*.js` / `public/css/app.css`(手書き、ビルド不要)
- テスト: `php artisan test`(単体: `--filter=RankProgressFlowTest`)/ Lint: `vendor/bin/pint`
- 動作確認はcurlではなく実ブラウザ(またはheadless)で操作して確認する

## Architecture

- **状態管理はセッション**(DB永続なし)。`Services/MatchStateService`がマッチ進行状態を
  `weapon_master_match`キーに保持。ランクポイント/図鑑既読は別セッションキー
- **層**: `Http/Controllers`(薄い)→`Services/MatchStateService`(司令塔)/
  `Services/MasterDataRepository`(マスタ読取)→`Domain/{Backpack,Combat,Cpu,Rank,Encyclopedia}`
  (配置・合成・戦闘・ランク・図鑑ロジック)
- **マスタデータは武器/防具/素材/秘宝/合成レシピのみJSON管理**(`database/data/*.json`、DB不使用)。
  `MasterDataRepository`がJSONを読み込み`Domain/MasterData/MasterDataEntry`(Eloquentモデル互換の
  `->name`等プロパティアクセスができる軽量値オブジェクト)のCollectionにして返す。防具(`armors.json`)・
  秘宝(`relics.json`)はまだ実データが無く空配列(スキーマは武器と同一)。CPUパターン(`cpu_patterns`)
  のみ従来通りDBテーブル+`CpuPatternSeeder`で管理(このJSON化の対象外)
- ルーティング: 画面表示(`/`, `/battle-prep`など)と`api/`のJSON APIに分離。
  フロントは`public/js/*.js`がfetchしてDOM更新(SPA無し)
- `config/game.php`等の数値は企画書に無く**仮置きの値**(コード内コメントに経緯あり)。
  変更時は企画書との矛盾を確認
- テストは`tests/Feature/*`中心。武器/素材/レシピはJSON管理のためシーディング不要(常時利用可能)。
  `CpuPatternSeeder` + `RefreshDatabase`のパターンに倣う