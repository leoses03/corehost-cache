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
