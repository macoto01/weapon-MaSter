/*
 * バトル準備画面。PHP側APIの呼び出しとCanvas描画のみを行う薄いレイヤー。
 * 配置バリデーション・シナジー計算・合成判定・マニー計算は全てサーバー側で行われ、
 * このファイルはその結果をそのまま画面に反映するだけ。
 * (装備のドラッグ&回転操作も、見た目の追従とドロップ時のAPI呼び出しに専念し、
 *  配置可否の判定はしない。判定結果はサーバーからのエラーメッセージで受け取る。)
 */
(() => {
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
    const CELL_SIZE = 40;
    const DRAG_THRESHOLD = 6;

    const canvas = document.getElementById('backpack-canvas');
    const shopRow = document.getElementById('shop-row');
    const tempStorageRow = document.getElementById('temp-storage-row');
    // 「武器置き場」として枠表示されているのは行(アイテムが並ぶ部分)だけでなく
    // パネル全体(タイトル・ヒント文を含む)なので、ドロップ判定もパネル全体を対象にする。
    const tempStoragePanel = tempStorageRow.closest('.temp-storage-panel');
    let state = null;
    let selectedPlacementId = null;

    // ドラッグ中の状態。座標確定・配置可否はドロップ時にサーバーへ問い合わせるため、
    // ここで保持するのは「どこから掴んだか」「今どの向きで見せているか」の表示用情報のみ。
    let drag = null; // { source:'temp'|'grid', id, name, width, height, rotated, moved, startX, startY, hoverCell }
    let suppressNextCanvasClick = false;

    // 配置確定時の演出。判定・状態確定は常にサーバー側で完了済みで、
    // ここではその結果を「スライド/ポップイン/却下フラッシュ」として滑らかに見せるだけ。
    const ANIMATION_MS = 150;
    const REJECT_FLASH_MS = 300;
    let slideAnimation = null; // { itemId, fromX, fromY, toX, toY, startTime }
    let popInAnimation = null; // { itemId, startTime }
    let rejectFlash = null; // { x, y, width, height, startTime }
    // 武器を置いた瞬間、隣接効果(シナジー)が○判定だった相手にだけ付けるパルス演出。
    // ドラッグ中の○/×表示はこれとは別に(掴んでいる間だけ)drag.synergyMarkersで管理する。
    const SYNERGY_PULSE_MS = 480;
    let synergyPulses = []; // [{ itemId, startTime }]

    function easeOutCubic(t) {
        return 1 - Math.pow(1 - t, 3);
    }

    function sizeOf(info) {
        return info.rotated ? { width: info.height, height: info.width } : { width: info.width, height: info.height };
    }

    // ドラッグ中、掴んでいるアイテムを置いたら合成できるバックパック内アイテムへ結ぶ線。
    // 判定(どのアイテムが合成候補か)はサーバー(SynthesisResolver::previewPartners)が行い、
    // ここではその結果(partner_ids)をカーソル位置から各アイテム中心へ結ぶだけの見た目専用の描画。
    // ドラッグはcanvas外(ショップ・武器置き場)にも及ぶため、canvas座標系ではなく画面全体を覆う
    // SVGオーバーレイに描く。
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const synthesisConnectorOverlay = document.createElementNS(SVG_NS, 'svg');
    synthesisConnectorOverlay.setAttribute('class', 'synthesis-connector-overlay');
    document.body.appendChild(synthesisConnectorOverlay);

    function clearSynthesisConnectors() {
        synthesisConnectorOverlay.innerHTML = '';
    }

    function updateSynthesisConnectors(clientX, clientY) {
        clearSynthesisConnectors();
        if (!drag || !drag.moved || !drag.synthesisPartnerIds || drag.synthesisPartnerIds.size === 0) return;
        const rect = canvas.getBoundingClientRect();
        state.placed_items.forEach((item) => {
            if (!drag.synthesisPartnerIds.has(item.id)) return;
            const targetX = rect.left + (item.x + item.width / 2) * CELL_SIZE;
            const targetY = rect.top + (item.y + item.height / 2) * CELL_SIZE;
            const line = document.createElementNS(SVG_NS, 'line');
            line.setAttribute('x1', clientX);
            line.setAttribute('y1', clientY);
            line.setAttribute('x2', targetX);
            line.setAttribute('y2', targetY);
            line.setAttribute('stroke', '#e0a800');
            line.setAttribute('stroke-width', '2');
            synthesisConnectorOverlay.appendChild(line);
        });
    }

    // 掴んでいるアイテムの種類/キーから、置いたら合成できるバックパック内アイテムを問い合わせる。
    // 結果が届くまでの間にドラッグが終わっている/別アイテムに切り替わっている場合は捨てる。
    async function fetchSynthesisPreview(info) {
        try {
            const params = new URLSearchParams({ item_type: info.item_type, item_key: info.item_key });
            if (info.source === 'grid') params.set('exclude_placement_id', info.id);
            const data = await api('GET', `/api/prep/synthesis-preview?${params}`);
            if (!drag || drag.source !== info.source || drag.id !== info.id) return;
            drag.synthesisPartnerIds = new Set(data.candidates.flatMap((c) => c.partner_ids));
            updateSynthesisConnectors(drag.lastClientX ?? drag.startX, drag.lastClientY ?? drag.startY);
        } catch (e) {
            // プレビュー取得の失敗は見た目(線が出ないだけ)にしか影響しないため、操作は継続させる。
        }
    }

    // 掴んでいるアイテムを今のホバー位置に置いたら隣接することになるバックパック内アイテムに
    // ついて、隣接効果(シナジー)が成立するか(○/×)を問い合わせる。マス目(hoverCell)が
    // 変わるたびに呼ぶだけでよいので、呼び出し側(onDragMove)でセル・回転が変わった時だけ叩く。
    async function fetchSynergyPreview(info, hoverCell) {
        try {
            const size = dragSize();
            const params = new URLSearchParams({
                item_type: info.item_type,
                item_key: info.item_key,
                x: hoverCell.x,
                y: hoverCell.y,
                width: size.width,
                height: size.height,
            });
            if (info.source === 'grid') params.set('exclude_placement_id', info.id);
            const data = await api('GET', `/api/prep/synergy-preview?${params}`);
            if (!drag || drag.source !== info.source || drag.id !== info.id) return;
            if (!drag.hoverCell || drag.hoverCell.x !== hoverCell.x || drag.hoverCell.y !== hoverCell.y) return;
            drag.synergyMarkers = data.markers;
            renderBackpack();
        } catch (e) {
            // プレビュー取得の失敗は○/×が出ないだけにしか影響しないため、操作は継続させる。
        }
    }

    // hoverCell(マス目)・回転が前回問い合わせた時と変わっていれば、隣接効果プレビューを
    // 取り直す。cellFromPoint()自体が40px単位でしか変わらないため、無駄な連投は自然と防げる。
    function refreshSynergyPreview() {
        if (!drag) return;
        if (!drag.hoverCell) {
            drag.synergyMarkers = null;
            drag.lastSynergyCell = null;
            return;
        }
        const prev = drag.lastSynergyCell;
        if (prev && prev.x === drag.hoverCell.x && prev.y === drag.hoverCell.y && prev.rotated === drag.rotated) {
            return;
        }
        drag.lastSynergyCell = { x: drag.hoverCell.x, y: drag.hoverCell.y, rotated: drag.rotated };
        fetchSynergyPreview(drag, drag.hoverCell);
    }

    // 武器を置いた(配置/移動が成立した)瞬間、ドラッグ中に○判定だった相手にだけパルス演出を付ける。
    function triggerSynergyPulses(markers) {
        if (!markers) return;
        const now = performance.now();
        markers.filter((m) => m.satisfied).forEach((m) => {
            synergyPulses.push({ itemId: m.item_id, startTime: now });
        });
    }

    // ドラッグ中、掴んだアイテムの見た目(画像)をカーソル/指の動きに追従させて表示する要素。
    // 中身は武器/素材カードと同じ thumbElementHtml() を使い回し、画像が無いアイテムは色付き矩形にフォールバックする。
    const dragGhost = document.createElement('div');
    dragGhost.className = 'drag-ghost';
    dragGhost.hidden = true;
    const dragGhostVisual = document.createElement('div');
    dragGhostVisual.className = 'drag-ghost-visual';
    const dragGhostLabel = document.createElement('div');
    dragGhostLabel.className = 'drag-ghost-label';
    dragGhost.appendChild(dragGhostVisual);
    dragGhost.appendChild(dragGhostLabel);
    document.body.appendChild(dragGhost);

    // --- 武器画像アイテム(バックパック/ショップ/一時置き場)共通のホバーツールチップ ---
    // 画像はどこも見た目(サムネイル/アイコン)のみを表示し、ATK/SPD/価格等の詳細はホバー時にのみ表示する。
    const itemTooltip = document.createElement('div');
    itemTooltip.className = 'item-tooltip';
    itemTooltip.hidden = true;
    document.body.appendChild(itemTooltip);

    // レアリティの最大段階数。図鑑画面(encyclopedia.js)の★表記と揃えている。
    const MAX_RARITY = 3;

    // ステータス欄の1マス分。値が無い(null/undefined)アイテムはそのマスごと非表示にする。
    function statBoxHtml(label, value) {
        if (value === null || value === undefined) return '';
        return `<div class="item-tooltip-card__stat"><span class="item-tooltip-card__stat-label">${label}:</span>${value}</div>`;
    }

    // 武器/素材のホバーツールチップ(武器情報ツールチップ仕様書 weapon_master_item_tooltip_wireframe.svg 準拠)。
    // バックパック/ショップ/一時置き場のいずれのホバーでも同じ見た目で表示する。
    // 該当項目の情報が無いアイテムは、その行(マス)ごと非表示にする(空欄のまま表示しない)。
    // 奥義ゲージ獲得量は一旦保留のため未実装。詳細アイコン(i)の押下時の挙動も未定のため、
    // 見た目のみ用意しクリックハンドラは付けない。
    function tooltipHtml(item) {
        const hasRarity = item.rarity !== null && item.rarity !== undefined;
        const rarityHtml = hasRarity ? `
                    <span class="item-tooltip-card__rarity">${Array.from({ length: MAX_RARITY }, (_, i) => (
                        `<span class="item-tooltip-card__star${i < item.rarity ? '' : ' item-tooltip-card__star--empty'}"></span>`
                    )).join('')}</span>` : '';

        // ATK=ダメージ、ST(stamina_cost)=スタミナ消費量、SPD由来のcooldown_seconds=使用間隔として表示する。
        const damage = item.effective_atk ?? item.atk;
        const accuracy = item.accuracy !== null && item.accuracy !== undefined ? `${item.accuracy}%` : null;
        const interval = item.cooldown_seconds !== null && item.cooldown_seconds !== undefined ? `${item.cooldown_seconds}秒` : null;
        const stats = [
            statBoxHtml('ダメージ', damage),
            statBoxHtml('スタミナ消費量', item.stamina_cost),
            statBoxHtml('命中率', accuracy),
            statBoxHtml('使用間隔', interval),
        ].join('');
        const statsHtml = stats ? `<div class="item-tooltip-card__stats">${stats}</div>` : '';

        const hasSkillDetail = !!item.skill_detail && item.skill_detail !== '特殊効果なし';
        const effectHtml = hasSkillDetail ? `
                <div class="item-tooltip-card__label">固有効果:</div>
                <div class="item-tooltip-card__effect-box">${item.skill_detail}</div>` : '';

        const categoryHtml = item.category ? `
                <div class="item-tooltip-card__label">カテゴリ:</div>
                <span class="item-tooltip-card__category">${item.category}</span>` : '';

        return `
            <div class="item-tooltip-card">
                <div class="item-tooltip-card__header">
                    <span class="item-tooltip-card__name">${item.name}</span>${rarityHtml}
                    <span class="item-tooltip-card__info">i</span>
                </div>${statsHtml}${effectHtml}${categoryHtml}
            </div>
        `;
    }

    function showItemTooltip(html, clientX, clientY) {
        itemTooltip.innerHTML = html;
        itemTooltip.hidden = false;
        itemTooltip.style.left = `${clientX + 14}px`;
        itemTooltip.style.top = `${clientY + 14}px`;
    }

    function hideItemTooltip() {
        itemTooltip.hidden = true;
    }

    // 任意の要素にホバーツールチップを紐付ける(ショップ/一時置き場のカード用)。
    function bindTooltip(el, htmlFn) {
        el.addEventListener('mousemove', (event) => showItemTooltip(htmlFn(), event.clientX, event.clientY));
        el.addEventListener('mouseleave', hideItemTooltip);
    }

    // 画像が用意されていない武器・素材用のフォールバック表示(バックパックの色付き矩形と同じ配色)。
    function thumbElementHtml(item) {
        if (item.image_url) {
            return `<img src="${item.image_url}" class="item-thumb" alt="${item.name}" draggable="false">`;
        }
        return `<div class="item-thumb item-thumb--fallback" style="background:${window.GridRenderer.colorForKey(item.item_key)}"></div>`;
    }

    // ショップ/一時置き場のカードに実際に表示されているサムネイルの寸法を取得する。
    // ドラッグ中もこの寸法のまま持ち運ぶことで、掴んだ瞬間に見た目(縮尺)が変わらないようにする。
    function thumbVisualSize(card) {
        const thumb = card.querySelector('.item-thumb');
        if (!thumb) return {};
        const rect = thumb.getBoundingClientRect();
        return { visualWidth: rect.width, visualHeight: rect.height };
    }

    canvas.addEventListener('mousemove', (event) => {
        if (drag) {
            hideItemTooltip();
            return;
        }
        const { x: cx, y: cy } = window.GridRenderer.cellFromPoint(canvas, CELL_SIZE, event.clientX, event.clientY);
        const item = window.GridRenderer.itemAt(state.placed_items, cx, cy);
        if (item) {
            showItemTooltip(tooltipHtml(item), event.clientX, event.clientY);
        } else {
            hideItemTooltip();
        }
    });

    canvas.addEventListener('mouseleave', hideItemTooltip);

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

    function render() {
        renderExceptBackpack();
        renderBackpack();
    }

    // 戦闘終了ごとに付与されるバックパック拡張(+4マス)を使い切るまでは、
    // 拡張マスの選択とリタイア以外の操作を行わせない(サーバー側にも同じ制限あり)。
    function isExpansionLocked() {
        return state.pending_unlock_tokens > 0;
    }

    // バックパック以外の表示更新。配置アニメーション中は、アニメーション対象の
    // requestAnimationFrameループから毎フレームrenderBackpack()だけを呼びたいため分離している。
    function renderExceptBackpack() {
        renderHeader();
        renderShop();
        renderTempStorage();
        document.getElementById('money-label').textContent = `マニー ${state.money}`;
        document.getElementById('reroll-cost').textContent = state.shop_reroll_cost;

        const locked = isExpansionLocked();
        document.body.classList.toggle('expansion-locked', locked);
        document.getElementById('expand-banner').hidden = !locked;
        if (locked) {
            document.getElementById('expand-remaining-label').textContent = state.pending_unlock_tokens;
        }
    }

    function renderHeader() {
        document.getElementById('hp-label').textContent = `HP ${state.base_hp + state.bonus_hp}`;
        document.getElementById('st-label').textContent = `ST ${state.st ?? '-'}`;
        document.getElementById('st-consumption-label').textContent = `ST消費 ${state.st_consumption_label}`;
        document.getElementById('round-label').textContent = `ラウンド ${state.round}/${state.max_rounds}`;

        const rankLabel = document.getElementById('rank-label');
        if (state.rank) {
            rankLabel.hidden = false;
            const { tier_label: tierLabel, points_in_tier: pointsInTier, points_to_next: pointsToNext } = state.rank;
            rankLabel.textContent = pointsToNext === null
                ? tierLabel
                : `${tierLabel} (${pointsInTier}/${pointsInTier + pointsToNext})`;
        } else {
            rankLabel.hidden = true;
        }
    }

    function dragSize() {
        if (!drag) return null;
        return drag.rotated ? { width: drag.height, height: drag.width } : { width: drag.width, height: drag.height };
    }

    function renderBackpack() {
        let preview = null;
        if (drag && drag.moved && drag.hoverCell) {
            const size = dragSize();
            preview = { x: drag.hoverCell.x, y: drag.hoverCell.y, width: size.width, height: size.height };
        }

        const now = performance.now();
        let items = state.placed_items;
        // バックパック内の武器をつかんでいる間は、元のマスからは描画せず、
        // 追従表示(dragGhost)だけがその画像を持って動いているように見せる。
        if (drag && drag.moved && drag.source === 'grid') {
            items = items.filter((item) => item.id !== drag.id);
        }
        let animating = false;

        if (slideAnimation) {
            const t = Math.min(1, (now - slideAnimation.startTime) / ANIMATION_MS);
            const eased = easeOutCubic(t);
            items = items.map((item) => (item.id === slideAnimation.itemId ? {
                ...item,
                x: slideAnimation.fromX + (slideAnimation.toX - slideAnimation.fromX) * eased,
                y: slideAnimation.fromY + (slideAnimation.toY - slideAnimation.fromY) * eased,
            } : item));
            if (t >= 1) {
                slideAnimation = null;
            } else {
                animating = true;
            }
        }

        if (popInAnimation) {
            const t = Math.min(1, (now - popInAnimation.startTime) / ANIMATION_MS);
            items = items.map((item) => (item.id === popInAnimation.itemId ? {
                ...item,
                renderAlpha: t,
                renderScale: 0.5 + 0.5 * easeOutCubic(t),
            } : item));
            if (t >= 1) {
                popInAnimation = null;
            } else {
                animating = true;
            }
        }

        if (rejectFlash) {
            const t = Math.min(1, (now - rejectFlash.startTime) / REJECT_FLASH_MS);
            preview = { x: rejectFlash.x, y: rejectFlash.y, width: rejectFlash.width, height: rejectFlash.height, color: '#d22', alpha: 1 - t };
            if (t >= 1) {
                rejectFlash = null;
            } else {
                animating = true;
            }
        }

        // 隣接効果(シナジー)の○/×は「掴んでいる間」だけ、掴んでいるものに隣接するアイテムに表示する。
        // 武器を置いた瞬間は、その時点で○だった相手にだけ短いパルス演出として再表示する。
        let synergyMarkers = null;
        if (drag && drag.moved && drag.hoverCell && drag.synergyMarkers) {
            synergyMarkers = drag.synergyMarkers;
        }
        if (synergyPulses.length > 0) {
            const stillActive = [];
            const pulseMarkers = [];
            synergyPulses.forEach((pulse) => {
                const t = Math.min(1, (now - pulse.startTime) / SYNERGY_PULSE_MS);
                items = items.map((item) => (item.id === pulse.itemId ? {
                    ...item,
                    renderScale: 1 + 0.22 * Math.sin(t * Math.PI),
                } : item));
                // t>=1になったフレームでは○を描かない(このフレームを最後に描画を止めるため、
                // ここでマーカーを含めてしまうとcanvasに○が残ったまま止まってしまう)。
                if (t < 1) {
                    pulseMarkers.push({ item_id: pulse.itemId, satisfied: true });
                    stillActive.push(pulse);
                }
            });
            if (!synergyMarkers) synergyMarkers = pulseMarkers;
            synergyPulses = stillActive;
            if (synergyPulses.length > 0) animating = true;
        }

        window.GridRenderer.draw(canvas, {
            width: state.grid.reserved_width,
            height: state.grid.reserved_height,
            cellSize: CELL_SIZE,
            unlockedCells: state.grid.unlocked_cells,
            unlockableCells: state.grid.unlockable_cells,
            items,
            selectedId: selectedPlacementId,
            synthesisGroups: state.pending_synthesis.map((s) => s.consumed),
            synergyMarkers,
            preview,
        });

        if (animating) requestAnimationFrame(renderBackpack);
    }

    function renderShop() {
        const row = document.getElementById('shop-row');
        row.innerHTML = '';
        state.shop_offers.forEach((offer) => {
            const card = document.createElement('div');
            card.className = 'shop-item';
            // ショップに並んでいる間だけ、価格を武器の下に枠付きで常時表示する
            // (一時置き場・バックパックでは表示しない。詳細はホバー時のツールチップで確認する)。
            // 半額セール中の武器は割引前価格を出さず、割引後価格のみ強調表示する(併記は見づらいため)。
            const priceHtml = offer.original_price
                ? `<div class="shop-item__price shop-item__price--discounted">${offer.price}マニー</div>`
                : `<div class="shop-item__price">${offer.price}マニー</div>`;
            card.innerHTML = `${thumbElementHtml(offer)}${priceHtml}`;
            bindTooltip(card, () => tooltipHtml(offer));
            // バックパックまでスライド(ドラッグ)すると、購入と同時にその場へ配置する。
            // (クリックだけでの即時購入は廃止。ドラッグ操作のみが購入手段。)
            card.addEventListener('pointerdown', (ev) => {
                startDrag(ev, {
                    source: 'shop',
                    id: offer.id,
                    name: offer.name,
                    width: offer.grid_width,
                    height: offer.grid_height,
                    image_url: offer.image_url,
                    item_key: offer.item_key,
                    item_type: offer.item_type,
                    sourceEl: card,
                    ...thumbVisualSize(card),
                });
            });
            row.appendChild(card);
        });
    }

    // --- 商売人イラスト・吹き出し ---
    const merchantImage = document.getElementById('merchant-image');
    const merchantBubble = document.getElementById('merchant-bubble');
    // 商売人へドラッグ&ドロップで売却する際のドロップ先。バトル開始ボタンとは別領域。
    const merchantDropZone = document.querySelector('.battle-start-frame__illustration');
    const MERCHANT_IDLE_SRC = merchantImage.src;
    const MERCHANT_BUY_SRC = merchantImage.dataset.buySrc;
    const MERCHANT_IDLE_TEXT = merchantBubble.textContent;
    let merchantThankTimer = null;

    // 一定時間後に通常表示へ戻る「お礼」演出(購入・売却で共通)。
    function showMerchantThanks(text) {
        merchantImage.src = MERCHANT_BUY_SRC;
        merchantBubble.textContent = text;
        clearTimeout(merchantThankTimer);
        merchantThankTimer = setTimeout(() => {
            merchantImage.src = MERCHANT_IDLE_SRC;
            merchantBubble.textContent = MERCHANT_IDLE_TEXT;
        }, 2000);
    }

    function thankMerchant() {
        showMerchantThanks('購入ありがとうございます');
    }

    // ドラッグ中、所持アイテムの買取額を吹き出しに表示する(時間経過での復帰はしない)。
    function setMerchantBubbleText(text) {
        clearTimeout(merchantThankTimer);
        merchantBubble.textContent = text;
    }

    function resetMerchantBubble() {
        clearTimeout(merchantThankTimer);
        merchantImage.src = MERCHANT_IDLE_SRC;
        merchantBubble.textContent = MERCHANT_IDLE_TEXT;
    }

    // 武器置き場に新しく現れたアイテムだけを検知して着地演出を付けるための、
    // 前回描画時点のID一覧。初回描画(ページを開いた直後)は「新しく置かれた」わけではないため
    // 演出を付けない(knownTempStorageIdsInitializedで区別する)。
    let knownTempStorageIds = new Set();
    let knownTempStorageIdsInitialized = false;

    function renderTempStorage() {
        const row = document.getElementById('temp-storage-row');
        row.innerHTML = '';
        const currentIds = new Set();
        state.temp_storage.forEach((item) => {
            currentIds.add(item.id);
            const card = document.createElement('div');
            card.className = 'temp-item';
            // バックパックから外す/ショップで買ってそのまま置く、いずれの経路で武器置き場に
            // 現れた場合も、ふわっと着地するアニメーションを付ける。
            if (knownTempStorageIdsInitialized && !knownTempStorageIds.has(item.id)) {
                card.classList.add('temp-item--drop-in');
            }
            card.innerHTML = thumbElementHtml(item);
            bindTooltip(card, () => tooltipHtml(item));
            card.addEventListener('pointerdown', (ev) => {
                startDrag(ev, {
                    source: 'temp',
                    id: item.id,
                    name: item.name,
                    width: item.grid_width,
                    height: item.grid_height,
                    image_url: item.image_url,
                    item_key: item.item_key,
                    item_type: item.item_type,
                    saleValue: item.sale_value,
                    sourceEl: card,
                    ...thumbVisualSize(card),
                });
            });

            row.appendChild(card);
        });
        knownTempStorageIds = currentIds;
        knownTempStorageIdsInitialized = true;
    }

    function updateItemActions() {
        const panel = document.getElementById('item-actions');
        if (!selectedPlacementId) {
            panel.hidden = true;
            return;
        }
        const item = state.placed_items.find((i) => i.id === selectedPlacementId);
        panel.hidden = !item;
        if (item) document.getElementById('item-actions-label').textContent = `選択中: ${item.name}(${item.width}×${item.height})`;
    }

    // --- ドラッグ操作(一時置き場からの配置 / バックパック内での移動) ---

    function startDrag(event, info) {
        if (event.button !== undefined && event.button !== 0) return;
        if (isExpansionLocked()) return;
        hideItemTooltip();
        drag = { ...info, rotated: false, moved: false, startX: event.clientX, startY: event.clientY, hoverCell: null };
        window.addEventListener('pointermove', onDragMove);
        window.addEventListener('pointerup', onDragEnd);
        window.addEventListener('keydown', onDragKeydown);
    }

    function onDragMove(event) {
        if (!drag) return;
        const dx = event.clientX - drag.startX;
        const dy = event.clientY - drag.startY;
        if (!drag.moved && Math.hypot(dx, dy) < DRAG_THRESHOLD) return;
        const justStartedMoving = !drag.moved;
        // つかんだ瞬間、元の位置(ショップ/一時置き場カード、またはバックパック内)からは
        // 画像を消し、同じ画像がカーソル/指にそのまま追従しているように見せる。
        if (justStartedMoving && drag.sourceEl) {
            drag.sourceEl.style.visibility = 'hidden';
        }
        drag.moved = true;

        // 所持している(ショップ以外から掴んだ)武器を持っている間、商売人が買取額を提示する。
        if (justStartedMoving && drag.source !== 'shop' && drag.saleValue !== null && drag.saleValue !== undefined) {
            setMerchantBubbleText(`${drag.saleValue}マニーで買い取るよ`);
        }

        // 持っている武器/素材を置いたら合成できるバックパック内アイテムを問い合わせ、線で結ぶ。
        if (justStartedMoving) {
            fetchSynthesisPreview(drag);
        }

        const rect = canvas.getBoundingClientRect();
        const overCanvas = event.clientX >= rect.left && event.clientX < rect.right
            && event.clientY >= rect.top && event.clientY < rect.bottom;
        drag.hoverCell = overCanvas ? window.GridRenderer.cellFromPoint(canvas, CELL_SIZE, event.clientX, event.clientY) : null;
        refreshSynergyPreview();

        // 商売人の枠(バトル開始ボタンとは別領域)に離すと売却できる。
        const merchantRect = merchantDropZone.getBoundingClientRect();
        drag.hoverMerchant = event.clientX >= merchantRect.left && event.clientX < merchantRect.right
            && event.clientY >= merchantRect.top && event.clientY < merchantRect.bottom;
        merchantDropZone.classList.toggle('battle-start-frame__illustration--drop-target',
            drag.source !== 'shop' && drag.hoverMerchant);

        // ショップの武器をショップの中で離した場合は、購入せず元の位置に戻す。
        const shopRowRect = shopRow.getBoundingClientRect();
        drag.hoverShop = event.clientX >= shopRowRect.left && event.clientX < shopRowRect.right
            && event.clientY >= shopRowRect.top && event.clientY < shopRowRect.bottom;

        // バックパックの武器を武器置き場に離すと「外す」が成立する(ボタンは廃止しスライドのみ)。
        const tempStorageRect = tempStoragePanel.getBoundingClientRect();
        drag.hoverTempStorage = event.clientX >= tempStorageRect.left && event.clientX < tempStorageRect.right
            && event.clientY >= tempStorageRect.top && event.clientY < tempStorageRect.bottom;
        tempStoragePanel.classList.toggle('temp-storage-row--drop-target',
            drag.source === 'grid' && drag.hoverTempStorage);

        updateGhost(event.clientX, event.clientY);
        updateSynthesisConnectors(event.clientX, event.clientY);
        renderBackpack();
    }

    function onDragKeydown(event) {
        if (!drag) return;
        if (event.key !== 'r' && event.key !== 'R') return;
        drag.rotated = !drag.rotated;
        updateGhost(drag.lastClientX ?? drag.startX, drag.lastClientY ?? drag.startY);
        refreshSynergyPreview();
        renderBackpack();
    }

    function updateGhost(clientX, clientY) {
        if (!drag) return;
        drag.lastClientX = clientX;
        drag.lastClientY = clientY;
        if (!drag.moved) {
            dragGhost.hidden = true;
            return;
        }
        const size = dragSize();
        // ショップ/一時置き場から掴んだ場合は、そのカードに表示されていたサムネイルと
        // 同じ寸法のまま持ち運ぶ(掴んだ瞬間に見た目が変わらないようにする)。
        // バックパック内から掴んだ場合は元々マス目のサイズで表示されていたので、その縮尺を使う。
        const width = drag.visualWidth ?? size.width * CELL_SIZE;
        const height = drag.visualHeight ?? size.height * CELL_SIZE;
        dragGhost.hidden = false;
        dragGhostVisual.style.width = `${width}px`;
        dragGhostVisual.style.height = `${height}px`;
        dragGhost.style.left = `${clientX - width / 2}px`;
        dragGhost.style.top = `${clientY - height / 2}px`;
        dragGhostVisual.innerHTML = thumbElementHtml(drag);
        dragGhostLabel.textContent = `${drag.name} ${size.width}×${size.height}${drag.rotated ? '(回転中)' : ''} [Rキーで回転]`;
    }

    async function onDragEnd(event) {
        window.removeEventListener('pointermove', onDragMove);
        window.removeEventListener('pointerup', onDragEnd);
        window.removeEventListener('keydown', onDragKeydown);

        const finished = drag;
        drag = null;
        clearSynthesisConnectors();
        if (!finished) return;

        // ドラッグ中に付けていたハイライトを外す。買取額プレビューも一旦通常表示へ戻すが、
        // 売却が成立した場合はこの後showMerchantThanks()で上書きされる。
        merchantDropZone.classList.remove('battle-start-frame__illustration--drop-target');
        tempStoragePanel.classList.remove('temp-storage-row--drop-target');
        if (finished.source !== 'shop' && finished.moved) {
            resetMerchantBubble();
        }

        // 所持している武器(ショップ以外から掴んだもの)を商売人の枠に離すと売却が成立する。
        const soldToMerchant = finished.source !== 'shop' && finished.moved && finished.hoverMerchant;
        // バックパックの武器を武器置き場に離すと「外す」が成立する(ボタンは廃止しスライドのみ)。
        const unequippedToStorage = finished.source === 'grid' && finished.moved && finished.hoverTempStorage;

        // ショップ(ドロップ先を問わず購入する)・一時置き場→バックパックへの配置を試みる場合、
        // 商売人への売却・バックパック→武器置き場への「外す」を試みる場合は、成否に関わらず
        // 必ずrenderExceptBackpack()/render()でそのカードのDOMごと作り直されるため、
        // 元の画像(sourceEl)の非表示を先に解除すると、作り直されるまでの間だけ元のカードが
        // 一瞬再表示されてしまう(かつ着地アニメーション中のdragGhostとも二重に見える)。
        // そのため、この場合はsourceElの復元をせず、DOMの作り直しに任せる。
        // それ以外(クリックのみ・ショップ内で離した場合・一時置き場からキャンバス外へのドロップ・
        // バックパック内移動)は再描画が起きない経路もあるため、ここで即座に復元しておく。
        const willTriggerApiCall = soldToMerchant || unequippedToStorage || (finished.moved
            && ((finished.source === 'shop' && !finished.hoverShop) || (finished.source === 'temp' && finished.hoverCell)));
        if (!willTriggerApiCall) {
            if (finished.sourceEl) {
                finished.sourceEl.style.visibility = '';
            }
            dragGhost.hidden = true;
        }

        if (soldToMerchant) {
            dragGhost.hidden = true;
            try {
                state = await api('POST', '/api/prep/sell', { id: finished.id, source: finished.source });
                if (finished.source === 'grid' && selectedPlacementId === finished.id) {
                    selectedPlacementId = null;
                    updateItemActions();
                }
                showMerchantThanks('ありがとう!');
            } catch (e) {
                alert(e.message);
            }
            render();
            return;
        }

        if (unequippedToStorage) {
            dragGhost.hidden = true;
            try {
                state = await api('POST', '/api/prep/unequip', { placement_id: finished.id });
                if (selectedPlacementId === finished.id) {
                    selectedPlacementId = null;
                    updateItemActions();
                }
            } catch (e) {
                alert(e.message);
            }
            render();
            return;
        }

        if (finished.source === 'grid') {
            suppressNextCanvasClick = true;

            if (!finished.moved) {
                selectedPlacementId = selectedPlacementId === finished.id ? null : finished.id;
                updateItemActions();
                renderBackpack();
                return;
            }

            if (!finished.hoverCell) {
                renderBackpack();
                return;
            }

            try {
                state = await api('POST', '/api/prep/move', {
                    placement_id: finished.id,
                    x: finished.hoverCell.x,
                    y: finished.hoverCell.y,
                    rotated: finished.rotated,
                });
                renderExceptBackpack();
                const movedItem = state.placed_items.find((i) => i.id === finished.id);
                if (movedItem && (movedItem.x !== finished.originX || movedItem.y !== finished.originY)) {
                    slideAnimation = {
                        itemId: finished.id,
                        fromX: finished.originX,
                        fromY: finished.originY,
                        toX: movedItem.x,
                        toY: movedItem.y,
                        startTime: performance.now(),
                    };
                }
                triggerSynergyPulses(finished.synergyMarkers);
                renderBackpack();
            } catch (e) {
                alert(e.message);
                const size = sizeOf(finished);
                rejectFlash = { x: finished.hoverCell.x, y: finished.hoverCell.y, width: size.width, height: size.height, startTime: performance.now() };
                renderBackpack();
            }
            return;
        }

        if (finished.source === 'shop') {
            if (!finished.moved) {
                return;
            }

            if (finished.hoverShop) {
                // ショップの中で離した場合は購入しない。sourceElの表示は既に上で復元済みなので、
                // 元の位置に戻る(何もしない)。
                return;
            }

            const beforeIds = new Set(state.temp_storage.map((t) => t.id));

            try {
                // ドロップ先に関わらず、まず購入(武器置き場へ追加)する。
                const afterBuy = await api('POST', '/api/prep/shop/buy', { offer_id: finished.id });
                state = afterBuy;
                thankMerchant();
                const boughtEntry = state.temp_storage.find((t) => !beforeIds.has(t.id));
                if (!boughtEntry) {
                    dragGhost.hidden = true;
                    render();
                    return;
                }

                if (!finished.hoverCell) {
                    // バックパック以外へドロップした場合は、武器置き場への購入だけで完了する。
                    dragGhost.hidden = true;
                    render();
                    return;
                }

                const dropX = finished.hoverCell.x;
                const dropY = finished.hoverCell.y;

                try {
                    state = await api('POST', '/api/prep/place', {
                        temp_storage_id: boughtEntry.id,
                        x: dropX,
                        y: dropY,
                        rotated: finished.rotated,
                    });
                    renderExceptBackpack();
                    dragGhost.hidden = true;
                    const placedItem = state.placed_items.find((i) => i.x === dropX && i.y === dropY);
                    if (placedItem) {
                        popInAnimation = { itemId: placedItem.id, startTime: performance.now() };
                    }
                    triggerSynergyPulses(finished.synergyMarkers);
                    renderBackpack();
                } catch (placeError) {
                    // バックパックへの配置に失敗した場合は「購入しなかったこと」にする
                    // (武器置き場から取り除き、全額返金してショップに戻す)。
                    dragGhost.hidden = true;
                    alert(placeError.message);
                    state = await api('POST', '/api/prep/shop/cancel', { temp_storage_id: boughtEntry.id });
                    renderExceptBackpack();
                    const size = sizeOf(finished);
                    rejectFlash = { x: dropX, y: dropY, width: size.width, height: size.height, startTime: performance.now() };
                    renderBackpack();
                }
            } catch (e) {
                dragGhost.hidden = true;
                alert(e.message);
                renderExceptBackpack();
            }
            return;
        }

        // source === 'temp'
        if (!finished.moved || !finished.hoverCell) {
            return;
        }

        try {
            const dropX = finished.hoverCell.x;
            const dropY = finished.hoverCell.y;
            state = await api('POST', '/api/prep/place', {
                temp_storage_id: finished.id,
                x: dropX,
                y: dropY,
                rotated: finished.rotated,
            });
            renderExceptBackpack();
            dragGhost.hidden = true;
            const placedItem = state.placed_items.find((i) => i.x === dropX && i.y === dropY);
            if (placedItem) {
                popInAnimation = { itemId: placedItem.id, startTime: performance.now() };
            }
            triggerSynergyPulses(finished.synergyMarkers);
            renderBackpack();
        } catch (e) {
            dragGhost.hidden = true;
            alert(e.message);
            renderExceptBackpack();
            const size = sizeOf(finished);
            rejectFlash = { x: finished.hoverCell.x, y: finished.hoverCell.y, width: size.width, height: size.height, startTime: performance.now() };
            renderBackpack();
        }
    }

    canvas.addEventListener('pointerdown', (event) => {
        const { x: cx, y: cy } = window.GridRenderer.cellFromPoint(canvas, CELL_SIZE, event.clientX, event.clientY);
        const item = window.GridRenderer.itemAt(state.placed_items, cx, cy);
        if (item) {
            startDrag(event, {
                source: 'grid',
                id: item.id,
                name: item.name,
                width: item.width,
                height: item.height,
                image_url: item.image_url,
                item_key: item.item_key,
                item_type: item.item_type,
                saleValue: item.sale_value,
                originX: item.x,
                originY: item.y,
            });
        }
    });

    canvas.addEventListener('click', async (event) => {
        if (suppressNextCanvasClick) {
            suppressNextCanvasClick = false;
            return;
        }
        const { x: cx, y: cy } = window.GridRenderer.cellFromPoint(canvas, CELL_SIZE, event.clientX, event.clientY);
        if (window.GridRenderer.itemAt(state.placed_items, cx, cy)) return;

        if (isExpansionLocked()) {
            // 拡張中に押せるのは「＋」(既存の使用可能エリアに隣接する未解放マス)だけ。
            const isUnlockable = (state.grid.unlockable_cells || []).some(([ux, uy]) => ux === cx && uy === cy);
            if (!isUnlockable) return;
            try {
                state = await api('POST', '/api/prep/unlock-cell', { x: cx, y: cy });
                render();
            } catch (e) {
                alert(e.message);
            }
        }
    });

    document.getElementById('rotate-button').addEventListener('click', async () => {
        if (!selectedPlacementId) return;
        try {
            state = await api('POST', '/api/prep/rotate', { placement_id: selectedPlacementId });
            render();
            updateItemActions();
        } catch (e) {
            alert(e.message);
        }
    });

    document.getElementById('cancel-select-button').addEventListener('click', () => {
        selectedPlacementId = null;
        updateItemActions();
        renderBackpack();
    });

    document.getElementById('reroll-button').addEventListener('click', async () => {
        if (isExpansionLocked()) return;
        try {
            state = await api('POST', '/api/prep/shop/reroll');
            render();
        } catch (e) {
            alert(e.message);
        }
    });

    document.getElementById('retire-button').addEventListener('click', async () => {
        if (!confirm('リタイアすると現在のマッチの進行状況(バックパック・マニー・勝敗数)は失われます。よろしいですか?')) {
            return;
        }
        try {
            const res = await api('POST', '/api/prep/retire');
            if (res.redirect) {
                window.location.href = res.redirect;
            }
        } catch (e) {
            alert(e.message);
        }
    });

    async function requestStartBattle(confirmSynthesis) {
        if (isExpansionLocked()) {
            alert('先にバックパック拡張(残り' + state.pending_unlock_tokens + 'マス)を完了してください。');
            return;
        }
        try {
            const res = await api('POST', '/api/prep/start-battle', { confirm_synthesis: confirmSynthesis });
            if (res.needs_confirmation) {
                showConfirmPopup(res.pending_synthesis);
                return;
            }
            if (res.redirect) {
                window.location.href = res.redirect;
            }
        } catch (e) {
            alert(e.message);
        }
    }

    function showConfirmPopup(pendingSynthesis) {
        const list = document.getElementById('confirm-list');
        list.innerHTML = '';
        pendingSynthesis.forEach((s) => {
            const li = document.createElement('li');
            li.textContent = `${s.recipe} → ${s.output}`;
            list.appendChild(li);
        });
        document.getElementById('confirm-overlay').hidden = false;
    }

    document.getElementById('start-battle-button').addEventListener('click', () => requestStartBattle(false));
    document.getElementById('confirm-yes-button').addEventListener('click', () => {
        document.getElementById('confirm-overlay').hidden = true;
        requestStartBattle(true);
    });
    document.getElementById('confirm-no-button').addEventListener('click', () => {
        document.getElementById('confirm-overlay').hidden = true;
    });

    (async function init() {
        state = await api('GET', '/api/prep/state');
        render();
    })();
})();
