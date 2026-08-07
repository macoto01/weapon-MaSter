/*
 * 図鑑画面。タグ切替とアイテム一覧表示のみ。データ整形(準備中判定含む)はPHP側で行う。
 */
(() => {
    const tagListEl = document.getElementById('tag-list');
    const entryListEl = document.getElementById('entry-list');
    let selectedTag = 'weapon';

    async function loadTag(tag) {
        selectedTag = tag;
        const res = await fetch(`/api/encyclopedia/tag?tag=${encodeURIComponent(tag)}`, {
            headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        renderTags(data.tags);
        renderEntries(data.entries, data.tags.find((t) => t.key === tag));
    }

    function renderTags(tags) {
        tagListEl.innerHTML = '';
        tags.forEach((tag) => {
            const el = document.createElement('div');
            el.className = 'tag-item' + (tag.key === selectedTag ? ' selected' : '');
            el.textContent = tag.label;
            el.addEventListener('click', () => loadTag(tag.key));
            tagListEl.appendChild(el);
        });
    }

    function renderEntries(entries, tagInfo) {
        entryListEl.innerHTML = '';
        if (!tagInfo || !tagInfo.available) {
            const note = document.createElement('div');
            note.className = 'placeholder-note';
            note.textContent = `「${tagInfo ? tagInfo.label : ''}」タグは検証範囲外のため準備中です。`;
            entryListEl.appendChild(note);
            return;
        }

        entries.forEach((entry) => {
            const row = document.createElement('div');
            row.className = 'entry-row';
            const priceText = entry.price !== null && entry.price !== undefined ? `${entry.price}マニー` : '';
            const rarityText = entry.rarity ? '★'.repeat(entry.rarity) + '☆'.repeat(Math.max(0, 3 - entry.rarity)) : '';
            const image = entry.image_url ? `<img src="${entry.image_url}" class="entry-thumb" alt="${entry.name}" draggable="false">` : '';
            row.innerHTML = `
                ${image}
                <strong>${entry.name}</strong>
                <span class="entry-rarity">${rarityText}</span>
                <span>${entry.stat}</span>
                <span>${priceText}</span>
                <span class="entry-detail">${entry.detail ?? ''}</span>
                <span class="entry-detail entry-skill">スキル詳細: ${entry.skill_detail ?? ''}</span>
            `;
            entryListEl.appendChild(row);
        });
    }

    document.getElementById('back-button').addEventListener('click', () => {
        window.location.href = '/';
    });

    loadTag(selectedTag);
})();
