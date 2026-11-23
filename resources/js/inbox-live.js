/*
 * Inbox live updates.
 *  - Every 5 s asks inbox.updates for leads created after the newest one on screen (same filters, same
 *    branch scoping). When there are new ones, the list pane's [data-live-region] parts are re-rendered
 *    from the server (Blade HTML, already escaped), new rows glow briefly and a toast names the lead.
 *  - Admin demo: "Send sample Google lead" gets a Google-format body from google.sample and POSTs it to
 *    /webhooks/google-leads itself — the exact request Google Ads makes.
 */
const root = document.getElementById('inbox-live');

if (root) {
    const POLL_MS = 5000;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let latestId = Number(root.dataset.latestId) || 0;
    let inflight = null;

    const toast = (title, body = '') => {
        const box = document.getElementById('toasts');
        if (!box) return;
        const el = document.createElement('div');
        el.className = 'toast';
        el.setAttribute('role', 'status');
        const heading = document.createElement('p');
        heading.className = 'font-medium';
        heading.textContent = title;
        el.append(heading);
        if (body) {
            const text = document.createElement('p');
            text.className = 'text-slate-500';
            text.textContent = body;
            el.append(text);
        }
        box.append(el);
        setTimeout(() => el.remove(), 6000);
    };

    /** Re-render the list pane from the current URL (keeps filters, page and the open lead). */
    const refreshList = async () => {
        const response = await fetch(window.location.href, { headers: { Accept: 'text/html' } });
        if (!response.ok) return;
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        document.querySelectorAll('[data-live-region]').forEach((region) => {
            const fresh = doc.getElementById(region.id);
            if (fresh) region.innerHTML = fresh.innerHTML; // Alpine initialises the new x-* bindings itself
        });
    };

    const check = async () => {
        const url = new URL(root.dataset.updatesUrl, window.location.origin);
        new URLSearchParams(window.location.search).forEach((value, key) => url.searchParams.set(key, value));
        url.searchParams.set('since', String(latestId));

        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) return; // logged out / server error: try again next tick
        const data = await response.json();

        if (data.new.length) {
            await refreshList();
            for (const lead of data.new) {
                document.querySelectorAll(`[data-lead-id="${Number(lead.id)}"]`).forEach((row) => {
                    row.classList.add('lead-new');
                    setTimeout(() => row.classList.remove('lead-new'), 6000);
                });
                toast(`New lead: ${lead.name}`, [lead.source, lead.city].filter(Boolean).join(' · '));
            }
        }
        latestId = Math.max(latestId, Number(data.latest_id) || 0);
    };

    const poll = () => {
        inflight ??= check().catch(() => {}).finally(() => (inflight = null));
        return inflight;
    };

    const loop = async () => {
        if (!document.hidden) await poll();
        setTimeout(loop, POLL_MS);
    };
    setTimeout(loop, POLL_MS);
    document.addEventListener('visibilitychange', () => !document.hidden && poll());

    /* ---- Admin demo: Send sample Google lead ---- */
    const button = document.getElementById('send-sample-google');
    button?.addEventListener('click', async () => {
        const label = button.querySelector('[data-label]');
        const original = label.textContent;
        button.disabled = true;
        label.textContent = 'Sending…';
        try {
            const prepared = await fetch(root.dataset.sampleUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            if (!prepared.ok) throw new Error(`Could not build a sample lead (HTTP ${prepared.status}).`);
            const { webhook_url: webhookUrl, body } = await prepared.json();

            // The same request Google Ads sends.
            const response = await fetch(webhookUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body,
            });
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(`Webhook answered ${response.status}: ${error.message ?? response.statusText}`);
            }

            if (inflight) await inflight;
            await poll(); // show it now instead of at the next tick
            label.textContent = 'Sent ✓';
        } catch (error) {
            toast('Sample lead failed', error.message);
        } finally {
            setTimeout(() => {
                button.disabled = false;
                label.textContent = original;
            }, 1200);
        }
    });
}
