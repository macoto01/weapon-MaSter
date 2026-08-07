/*
 * バトル画面。戦闘シミュレーション結果(PHP側で計算済み)をテキスト/数値で簡易再生し、
 * SKIPで即座に結果へ進められるようにする。演出はスプライトアニメーション等を用いず、
 * ログの逐次表示とHPバーの数値更新のみで構成する。
 */
(() => {
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
    const CELL_SIZE = 30;

    let battle = null;
    let playerHp = 0;
    let enemyHp = 0;
    let playerSt = 0;
    let enemySt = 0;
    let logIndex = 0;
    let timer = null;
    let finished = false;

    async function api(method, url, body) {
        const options = {
            method,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN, Accept: 'application/json' },
        };
        if (body !== undefined) options.body = JSON.stringify(body);
        const res = await fetch(url, options);
        const data = await res.json();
        if (!res.ok) throw new Error(data.message || 'エラーが発生しました');
        return data;
    }

    function drawBackpacks() {
        window.GridRenderer.draw(document.getElementById('player-canvas'), {
            width: battle.reserved_width,
            height: battle.reserved_height,
            cellSize: CELL_SIZE,
            unlockedCells: battle.player_unlocked_cells,
            items: battle.player_items,
        });
        window.GridRenderer.draw(document.getElementById('enemy-canvas'), {
            width: 4,
            height: 3,
            cellSize: CELL_SIZE,
            items: battle.enemy_items,
            occupiedOnly: true,
        });
    }

    function updateHpBars() {
        const playerMax = battle.result.player.max_hp;
        const enemyMax = battle.result.enemy.max_hp;
        document.getElementById('player-hp-bar').style.width = `${Math.max(0, (playerHp / playerMax) * 100)}%`;
        document.getElementById('enemy-hp-bar').style.width = `${Math.max(0, (enemyHp / enemyMax) * 100)}%`;
        document.getElementById('player-hp-text').textContent = `HP ${Math.max(0, Math.round(playerHp * 10) / 10)}/${playerMax}`;
        document.getElementById('enemy-hp-text').textContent = `HP ${Math.max(0, Math.round(enemyHp * 10) / 10)}/${enemyMax}`;
    }

    function updateStBars() {
        const playerMax = battle.result.player.max_st;
        const enemyMax = battle.result.enemy.max_st;
        document.getElementById('player-st-bar').style.width = `${Math.max(0, (playerSt / playerMax) * 100)}%`;
        document.getElementById('enemy-st-bar').style.width = `${Math.max(0, (enemySt / enemyMax) * 100)}%`;
        document.getElementById('player-st-text').textContent = `ST ${Math.max(0, Math.round(playerSt * 10) / 10)}/${playerMax}`;
        document.getElementById('enemy-st-text').textContent = `ST ${Math.max(0, Math.round(enemySt * 10) / 10)}/${enemyMax}`;
    }

    function appendLog(line) {
        const logEl = document.getElementById('battle-log');
        const div = document.createElement('div');
        div.textContent = line;
        logEl.appendChild(div);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function step() {
        const log = battle.result.log;
        if (logIndex >= log.length) {
            completePlayback();
            return;
        }
        const entry = log[logIndex];
        if (entry.side === 'player') {
            enemyHp = entry.defender_hp_after;
            playerSt = entry.attacker_st_after;
        } else {
            playerHp = entry.defender_hp_after;
            enemySt = entry.attacker_st_after;
        }
        document.getElementById('time-label').textContent = `TIME ${entry.time.toFixed(2)}s`;
        appendLog(`[${entry.time.toFixed(2)}s] ${entry.attacker}(${entry.side === 'player' ? '味方' : '敵'}) が ${entry.damage} ダメージ${entry.hits > 1 ? `(${entry.hits}HIT)` : ''}`);
        updateHpBars();
        updateStBars();
        logIndex++;
    }

    function startPlayback() {
        const log = battle.result.log;
        const delay = log.length > 0 ? Math.min(150, Math.max(30, 3500 / log.length)) : 0;
        if (log.length === 0) {
            completePlayback();
            return;
        }
        timer = setInterval(step, delay);
    }

    function completePlayback() {
        if (timer) clearInterval(timer);
        timer = null;
        playerHp = battle.result.player.hp_remaining;
        enemyHp = battle.result.enemy.hp_remaining;
        playerSt = battle.result.player.st_remaining;
        enemySt = battle.result.enemy.st_remaining;
        document.getElementById('time-label').textContent = `TIME ${battle.result.duration.toFixed(2)}s`;
        updateHpBars();
        updateStBars();
        finishBattle();
    }

    async function finishBattle() {
        if (finished) return;
        finished = true;
        try {
            const res = await api('POST', '/api/battle/finish');
            showResult(res);
        } catch (e) {
            alert(e.message);
        }
    }

    function marksHtml(total, filled, className) {
        let html = '';
        for (let i = 0; i < total; i++) {
            html += `<span class="mark ${i < filled ? 'filled ' + className : ''}"></span>`;
        }
        return html;
    }

    function showResult(res) {
        const titleMap = {
            clear: 'クリア',
            game_over: 'ゲームオーバー',
            finished: '終了',
        };
        let title = titleMap[res.match_status];
        if (!title) {
            title = res.winner === 'player' ? '勝利' : (res.winner === 'enemy' ? '敗北' : '引き分け');
        }
        document.getElementById('result-title').textContent = title;
        document.getElementById('win-marks').innerHTML = marksHtml(10, res.wins, 'win');
        document.getElementById('life-marks').innerHTML = marksHtml(5, 5 - res.losses, 'life');

        const nextButton = document.getElementById('next-button');
        nextButton.onclick = () => {
            window.location.href = res.next === 'battle-prep' ? '/battle-prep' : '/';
        };

        document.getElementById('result-overlay').hidden = false;
    }

    document.getElementById('skip-button').addEventListener('click', () => {
        completePlayback();
    });

    async function initBgm() {
        const { url } = await api('GET', '/api/bgm/active?screen=battle');
        if (!url) return;
        const bgm = document.getElementById('battle-bgm');
        bgm.src = url;
        bgm.play().catch(() => {
            // ブラウザの自動再生制限で再生がブロックされた場合、画面内の最初のクリックで開始する
            // (仕様上、音量ボタン等の専用UIは追加しない)。
            document.addEventListener('click', () => bgm.play().catch(() => {}), { once: true });
        });
    }

    (async function init() {
        battle = await api('GET', '/api/battle/state');
        document.getElementById('enemy-name-label').textContent = battle.cpu_name;
        playerHp = battle.result.player.max_hp;
        enemyHp = battle.result.enemy.max_hp;
        playerSt = battle.result.player.max_st;
        enemySt = battle.result.enemy.max_st;
        drawBackpacks();
        updateHpBars();
        updateStBars();
        startPlayback();
        initBgm();
    })();
})();
