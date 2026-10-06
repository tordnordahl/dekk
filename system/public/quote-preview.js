document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-quote-preview-form]');
  const dialog = document.querySelector('[data-quote-preview]');
  if (!form || !dialog) return;
  const button = form.querySelector('[data-preview-quote]');
  const frame = dialog.querySelector('[data-quote-preview-frame]');
  const state = dialog.querySelector('[data-quote-preview-state]');
  if (!button || !frame || !state) return;
  let pending;
  document.querySelectorAll('[data-quote-preview-close]').forEach(close => close.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', () => pending?.abort());
  button.addEventListener('click', async event => {
    event.preventDefault();
    const selected = form.querySelectorAll('[name="product_ids[]"]:checked');
    if (!selected.length || selected.length > 3) { window.alert('Velg mellom ett og tre dekkalternativer.'); return; }
    if (pending) return;
    pending = new AbortController();
    const request = pending;
    const timeout = setTimeout(() => request.abort(), 20000);
    button.disabled = true;
    state.hidden = false;
    state.textContent = 'Laster selve e-posten …';
    frame.hidden = true;
    frame.removeAttribute('src');
    frame.srcdoc = '';
    dialog.showModal();
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const response = await fetch(form.dataset.previewUrl, {
        method: 'POST', credentials: 'same-origin', signal: request.signal,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
      });
      if (response.redirected || response.status === 401 || response.status === 419) throw new Error('Økten er utløpt eller tilgangen er endret. Last siden på nytt og logg inn om nødvendig.');
      if (!response.ok) {
        let details;
        if ((response.headers.get('Content-Type') || '').includes('application/json')) details = await response.json();
        const validation = response.status === 422 ? Object.values(details?.errors || {}).flat()[0] : null;
        const known = { 402: 'Abonnementet må aktiveres før du kan forhåndsvise tilbud.', 403: 'Du har ikke tilgang til forhåndsvisningen.', 429: 'For mange forespørsler. Vent litt og prøv igjen.' };
        throw new Error(validation || known[response.status] || `Forhåndsvisningen kunne ikke lastes (HTTP ${response.status}). Prøv igjen.`);
      }
      if (response.headers.get('X-DekkPilot-Preview') !== 'quote-email') throw new Error('Serveren returnerte en annen side enn e-postforhåndsvisningen. Last siden på nytt.');
      const html = await response.text();
      frame.srcdoc = html;
      state.hidden = true;
      frame.hidden = false;
    } catch (error) {
      state.hidden = false;
      frame.hidden = true;
      state.textContent = error.name === 'AbortError' ? 'Forhåndsvisningen tok for lang tid eller ble avbrutt. Lukk og prøv igjen.' : (error.message || 'Kunne ikke koble til serveren. Prøv igjen.');
    } finally {
      clearTimeout(timeout);
      pending = null;
      button.disabled = false;
    }
  });
});
