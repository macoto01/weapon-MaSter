/*
 * タイトル画面。PHP側APIの呼び出しと画面遷移のみを行う薄いレイヤー。
 */
(() => {
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

    async function startMatch(mode) {
        const res = await fetch('/api/match/start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN, 'Accept': 'application/json' },
            body: JSON.stringify({ mode }),
        });
        if (!res.ok) {
            alert('マッチを開始できませんでした。');
            return;
        }
        window.location.href = '/battle-prep';
    }

    document.getElementById('rank-match-button').addEventListener('click', () => startMatch('rank'));
    document.getElementById('casual-match-button').addEventListener('click', () => startMatch('casual'));
    document.getElementById('encyclopedia-button').addEventListener('click', () => {
        window.location.href = '/encyclopedia';
    });

    document.getElementById('howto-button').addEventListener('click', () => {
        document.getElementById('howto-overlay').hidden = false;
    });
    document.getElementById('howto-close-button').addEventListener('click', () => {
        document.getElementById('howto-overlay').hidden = true;
    });
})();
