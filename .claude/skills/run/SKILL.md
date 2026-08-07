---
name: run
description: Start, verify, and stop the weapon-MaSter Laravel dev server for manual/browser verification of changes.
---

# Run weapon-MaSter

Plain Laravel app (Blade views + hand-written JS/CSS served from `public/`,
no `@vite` directives in any view). There is no frontend build step required
to run or verify the app — `npm run dev`/`vite` is not needed for pages to
render correctly.

## Prerequisites & setup

Already satisfied in this environment (vendor installed, `.env` present,
SQLite DB migrated). If starting from a clean checkout, run:

```bash
composer install
[ -f .env ] || cp .env.example .env
php artisan key:generate
[ -f database/database.sqlite ] || touch database/database.sqlite
php artisan migrate --graceful --force
```

## Run

One command does setup-check + background launch + readiness wait + smoke
check:

```bash
bash .claude/skills/run/smoke.sh
```

It prints the PID and the base URL when the server is up. Logs go to
`/tmp/weapon-master-serve.log`.

To use a different port: `PORT=8010 bash .claude/skills/run/smoke.sh`.

## Verify

Hit any of the four pages/routes (see `routes/web.php`):

```bash
curl -s http://127.0.0.1:8000/               # title screen
curl -s http://127.0.0.1:8000/battle-prep    # battle prep screen
curl -s http://127.0.0.1:8000/battle         # battle screen
curl -s http://127.0.0.1:8000/encyclopedia   # encyclopedia
```

For actual UI verification (not just curl), open the URL with the
claude-in-chrome skill or a headless browser and click through the golden
path — a 200 response only proves the server is up, not that the feature
works.

## Stop

```bash
lsof -ti:8000 -sTCP:LISTEN | xargs -r kill
```

(Adjust the port if you started with `PORT=...`.)

### Environment

| Variable | Required | Default | Notes |
|---|---|---|---|
| `PORT` | No | `8000` | Passed through to `php artisan serve --port=`. |
| `DB_CONNECTION` | No | `sqlite` (from `.env`) | DB already migrated at `database/database.sqlite`. |
