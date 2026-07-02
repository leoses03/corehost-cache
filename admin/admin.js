document.getElementById('chc-purge')?.addEventListener('click', async function () {
    const msg = document.getElementById('chc-purge-msg');
    this.disabled = true; msg.textContent = 'Purgando…';
    try {
        const body = new URLSearchParams({ action: 'chc_purge_all', _wpnonce: chcAdmin.nonce });
        const r = await fetch(chcAdmin.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
        const j = await r.json();
        msg.textContent = j.success ? 'Cache purgada ✓' : 'Error';
    } catch (e) { msg.textContent = 'Error'; }
    this.disabled = false;
    setTimeout(() => { location.reload(); }, 800);
});

document.getElementById('chc-warm')?.addEventListener('click', async function () {
    const btn = this;
    const msg = document.getElementById('chc-warm-msg');
    const wrap = document.getElementById('chc-warm-progress');
    const bar = document.getElementById('chc-warm-bar');
    const count = document.getElementById('chc-warm-count');
    btn.disabled = true; msg.textContent = ''; wrap.style.display = 'block';
    let offset = 0, total = 0;
    try {
        while (true) {
            const body = new URLSearchParams({ action: 'chc_warm_step', _wpnonce: chcAdmin.warmNonce, offset: String(offset) });
            const r = await fetch(chcAdmin.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
            const j = await r.json();
            if (!j.success) { msg.textContent = 'Error'; break; }
            total = j.data.total;
            offset = j.data.processed;
            bar.value = total ? Math.round((offset / total) * 100) : 100;
            count.textContent = ' ' + offset + '/' + total;
            if (j.data.done) { msg.textContent = 'Precarga completa ✓ (' + total + ' páginas)'; break; }
        }
    } catch (e) { msg.textContent = 'Error'; }
    btn.disabled = false;
    setTimeout(() => { location.reload(); }, 1200);
});
