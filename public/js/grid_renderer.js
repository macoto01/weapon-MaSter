/*
 * 共通グリッド描画モジュール(HTML5 Canvas)。
 * 技術要件定義の役割分担(「ロジックはPHP、描画のみJS」)に従い、
 * ここには配置可否判定・シナジー計算・戦闘計算は一切書かない。PHP側APIが返した
 * 状態(座標・実効ATK/SPDなど計算済みの値)をそのままCanvasに描画するだけの薄い関数群。
 */
window.GridRenderer = (() => {
    const PALETTE = ['#e07a5f', '#3d5a80', '#81b29a', '#f2cc8f', '#9b5de5', '#00b4d8', '#c9a227', '#5f6caf', '#ef476f', '#118ab2', '#8d6a9f', '#6a994e'];

    function colorForKey(key) {
        let hash = 0;
        for (let i = 0; i < key.length; i++) {
            hash = (hash * 31 + key.charCodeAt(i)) >>> 0;
        }
        return PALETTE[hash % PALETTE.length];
    }

    // 武器画像はPHP側が「用意されていればURL、無ければnull」を返すため(Weapon::getImageUrlAttribute)、
    // 読み込み済みの画像だけをキャッシュして描画し、未取得のものは読み込み完了時に再描画を促す。
    const imageCache = new Map();

    function getImage(url, onLoad) {
        let img = imageCache.get(url);
        if (!img) {
            img = new Image();
            img.src = url;
            imageCache.set(url, img);
        }
        if (!img.complete && onLoad) {
            img.addEventListener('load', onLoad, { once: true });
        }
        return img;
    }

    /**
     * @param {HTMLCanvasElement} canvas
     * @param {Object} opts
     *   width, height: 描画するグリッド全体のマス数
     *   cellSize: 1マスのピクセルサイズ
     *   unlockedCells: [[x,y], ...] 省略時は全マスを解放済み扱い
     *   items: [{id,x,y,width,height,item_key,name,effective_atk,effective_spd,atk_buff_percent,spd_buff_percent,double_hit,synthesis_candidate}]
     *   selectedId: 選択中アイテムのid
     *   synthesisGroups: [[id, id, ...], ...] 合成候補としてつなぐ組
     *   synergyMarkers: [{item_id,satisfied}, ...] 隣接効果(シナジー)の○/×判定。
     *     プレイヤーが武器/素材を掴んでいる間だけ、今つかんでいる位置に隣接する
     *     バックパック内アイテムに対して呼び出し側が都度計算して渡す(常時表示はしない)。
     *   occupiedOnly: trueなら空きマスを描画せず、装備があるマスの外接矩形のみを表示する(CPU表示用)
     */
    function draw(canvas, opts) {
        const cellSize = opts.cellSize || 70;
        const items = opts.items || [];
        const itemById = new Map(items.map((i) => [i.id, i]));

        let width = opts.width;
        let height = opts.height;
        let offsetX = 0;
        let offsetY = 0;
        let occupiedSet = null;

        if (opts.occupiedOnly) {
            occupiedSet = new Set();
            let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            items.forEach((item) => {
                for (let dx = 0; dx < item.width; dx++) {
                    for (let dy = 0; dy < item.height; dy++) {
                        const cx = item.x + dx;
                        const cy = item.y + dy;
                        occupiedSet.add(`${cx}:${cy}`);
                        minX = Math.min(minX, cx);
                        minY = Math.min(minY, cy);
                        maxX = Math.max(maxX, cx);
                        maxY = Math.max(maxY, cy);
                    }
                }
            });
            if (items.length > 0) {
                offsetX = minX;
                offsetY = minY;
                width = maxX - minX + 1;
                height = maxY - minY + 1;
            } else {
                width = 0;
                height = 0;
            }
        }

        const dpr = window.devicePixelRatio || 1;
        const cssWidth = Math.max(1, width * cellSize);
        const cssHeight = Math.max(1, height * cellSize);
        canvas.style.width = `${cssWidth}px`;
        canvas.style.height = `${cssHeight}px`;
        canvas.width = cssWidth * dpr;
        canvas.height = cssHeight * dpr;
        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, cssWidth, cssHeight);

        const unlockedSet = new Set((opts.unlockedCells || []).map(([x, y]) => `${x}:${y}`));
        const hasLockInfo = !!opts.unlockedCells;
        // 既に解放済みのマスに隣接している未解放マス(バックパック拡張で選べる場所)。
        // ここだけ「＋」を表示し、選べない場所と見た目で区別する。
        const unlockableSet = new Set((opts.unlockableCells || []).map(([x, y]) => `${x}:${y}`));

        for (let x = 0; x < width; x++) {
            for (let y = 0; y < height; y++) {
                const worldX = x + offsetX;
                const worldY = y + offsetY;
                if (occupiedSet && !occupiedSet.has(`${worldX}:${worldY}`)) {
                    continue; // 装備の無いマスは描画しない
                }
                const unlocked = !hasLockInfo || unlockedSet.has(`${worldX}:${worldY}`);
                ctx.fillStyle = unlocked ? '#ffffff' : '#f2f2f2';
                ctx.fillRect(x * cellSize, y * cellSize, cellSize, cellSize);
                ctx.strokeStyle = unlocked ? '#ccc' : '#ddd';
                if (!unlocked) ctx.setLineDash([3, 3]);
                ctx.strokeRect(x * cellSize + 0.5, y * cellSize + 0.5, cellSize - 1, cellSize - 1);
                ctx.setLineDash([]);

                if (!unlocked && unlockableSet.has(`${worldX}:${worldY}`)) {
                    ctx.fillStyle = '#e0a800';
                    ctx.font = `bold ${Math.floor(cellSize * 0.5)}px sans-serif`;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText('+', x * cellSize + cellSize / 2, y * cellSize + cellSize / 2 + 1);
                }
            }
        }

        // 隣接効果(シナジー)の○/×判定。呼び出し側(battle_prep.js)がドラッグ中の
        // 掴んでいるアイテムに隣接するものだけを都度計算して渡すため、ここに含まれない
        // アイテムには何も描かない(常時表示はしない)。
        const synergyMarkerMap = new Map((opts.synergyMarkers || []).map((m) => [m.item_id, m.satisfied]));

        items.forEach((item) => {
            const px = (item.x - offsetX) * cellSize;
            const py = (item.y - offsetY) * cellSize;
            const pw = item.width * cellSize;
            const ph = item.height * cellSize;

            // renderAlpha/renderScaleは配置アニメーション(スライド/ポップイン)専用の一時的な描画パラメータ。
            // 通常描画時(アニメーション対象外のアイテム)は未設定のためデフォルト値のまま何も変わらない。
            ctx.save();
            if (item.renderAlpha !== undefined) ctx.globalAlpha = item.renderAlpha;
            if (item.renderScale !== undefined && item.renderScale !== 1) {
                const cx = px + pw / 2;
                const cy = py + ph / 2;
                ctx.translate(cx, cy);
                ctx.scale(item.renderScale, item.renderScale);
                ctx.translate(-cx, -cy);
            }

            const img = item.image_url ? getImage(item.image_url, () => draw(canvas, opts)) : null;
            const hasImage = !!(img && img.complete && img.naturalWidth > 0);

            if (hasImage) {
                const pad = 2;
                const boxW = pw - pad * 2;
                const boxH = ph - pad * 2;
                const scale = Math.min(boxW / img.naturalWidth, boxH / img.naturalHeight);
                const drawW = img.naturalWidth * scale;
                const drawH = img.naturalHeight * scale;
                ctx.fillStyle = '#fff';
                ctx.fillRect(px + pad, py + pad, boxW, boxH);
                ctx.drawImage(img, px + pad + (boxW - drawW) / 2, py + pad + (boxH - drawH) / 2, drawW, drawH);
            } else {
                ctx.fillStyle = colorForKey(item.item_key);
                ctx.fillRect(px + 2, py + 2, pw - 4, ph - 4);
            }

            if (item.synthesis_candidate) {
                ctx.strokeStyle = '#e0a800';
                ctx.lineWidth = 3;
                ctx.strokeRect(px + 2, py + 2, pw - 4, ph - 4);
                ctx.lineWidth = 1;
            }

            // 隣接効果(シナジー)の○/×記号。プレイヤーが掴んでいるアイテムに隣接する
            // アイテムにのみ付く(synergyMarkerMapに含まれない場合は何も描かない)。
            if (synergyMarkerMap.has(item.id)) {
                const isSynergyActive = synergyMarkerMap.get(item.id);
                const markerColor = isSynergyActive ? '#1e88e5' : '#999999';
                const markerRadius = Math.max(7, Math.min(11, cellSize * 0.22));
                const mx = px + pw - markerRadius - 2;
                const my = py + markerRadius + 2;
                ctx.fillStyle = '#fff';
                ctx.beginPath();
                ctx.arc(mx, my, markerRadius, 0, Math.PI * 2);
                ctx.fill();
                ctx.strokeStyle = markerColor;
                ctx.lineWidth = 1.5;
                ctx.stroke();
                ctx.fillStyle = markerColor;
                ctx.font = `bold ${Math.floor(markerRadius * 1.3)}px sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(isSynergyActive ? '○' : '×', mx, my + 1);
                ctx.lineWidth = 1;
            }

            if (item.id === opts.selectedId) {
                ctx.strokeStyle = '#d22';
                ctx.lineWidth = 3;
                ctx.strokeRect(px + 4, py + 4, pw - 8, ph - 8);
                ctx.lineWidth = 1;
            }

            // 名前・ATK/SPD等のステータス文字は描画しない(武器の画像のみを表示する)。
            // 詳細はキャラクター(アイテム)へのホバーでツールチップとして表示する(呼び出し側の責務)。
            ctx.restore();
        });

        // 合成候補の連結線(縁の光り+連結線の二重表現のうち「線」部分)。
        // 隣接アイテムの塗りつぶしより後に描画し、線が隠れないようにする。
        (opts.synthesisGroups || []).forEach((group) => {
            const points = group.map((id) => itemById.get(id)).filter(Boolean).map((i) => ({
                x: (i.x - offsetX + i.width / 2) * cellSize,
                y: (i.y - offsetY + i.height / 2) * cellSize,
            }));
            if (points.length < 2) return;
            ctx.strokeStyle = '#e0a800';
            ctx.lineWidth = 2;
            for (let i = 1; i < points.length; i++) {
                ctx.beginPath();
                ctx.moveTo(points[0].x, points[0].y);
                ctx.lineTo(points[i].x, points[i].y);
                ctx.stroke();
            }
            ctx.lineWidth = 1;
        });

        // ドラッグ中のプレースメントプレビュー。配置可否の判定は行わず、
        // つかんでいるアイテムの大きさ・向きをその場に重ねて見せるだけの純粋な描画。
        // 実際に置けるかどうかはドロップ時にPHP側APIへ問い合わせて判定する。
        if (opts.preview) {
            const p = opts.preview;
            const px = (p.x - offsetX) * cellSize;
            const py = (p.y - offsetY) * cellSize;
            const pw = p.width * cellSize;
            const ph = p.height * cellSize;
            // 通常のドロップ先プレビュー(青・半透明)と、配置不可時の赤フラッシュ演出を
            // 同じ描画経路で扱えるよう色とアルファ値を差し替え可能にしている。
            const color = p.color || '#3d5a80';
            const fillAlpha = p.fillAlpha ?? p.alpha ?? 0.3;
            const strokeAlpha = p.strokeAlpha ?? p.alpha ?? 1;
            ctx.save();
            ctx.globalAlpha = fillAlpha;
            ctx.fillStyle = color;
            ctx.fillRect(px, py, pw, ph);
            ctx.globalAlpha = strokeAlpha;
            ctx.strokeStyle = color;
            ctx.setLineDash([4, 3]);
            ctx.lineWidth = 2;
            ctx.strokeRect(px + 1, py + 1, pw - 2, ph - 2);
            ctx.setLineDash([]);
            ctx.lineWidth = 1;
            ctx.restore();
        }
    }

    /**
     * canvasクリック位置からグリッド座標(セル単位)を求める。
     */
    function cellFromClick(canvas, cellSize, event) {
        return cellFromPoint(canvas, cellSize, event.clientX, event.clientY);
    }

    /**
     * 任意のポインタ座標(pointermove等)からグリッド座標(セル単位)を求める。
     */
    function cellFromPoint(canvas, cellSize, clientX, clientY) {
        const rect = canvas.getBoundingClientRect();
        const x = Math.floor((clientX - rect.left) / cellSize);
        const y = Math.floor((clientY - rect.top) / cellSize);
        return { x, y };
    }

    function itemAt(items, x, y) {
        return items.find((i) => x >= i.x && x < i.x + i.width && y >= i.y && y < i.y + i.height);
    }

    return { draw, colorForKey, cellFromClick, cellFromPoint, itemAt };
})();
