document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-directory-lookup]').forEach(button => {
        button.addEventListener('click', async () => {
            const form = button.closest('form');
            const phone = form?.querySelector('[name="phone"]');
            const status = button.parentElement.querySelector('[data-directory-status]');
            if (!phone?.value.trim()) { phone?.focus(); if (status) status.textContent = 'Skriv telefonnummer først.'; return; }
            button.disabled = true;
            if (status) status.textContent = 'Søker hos 1881 …';
            try {
                const response = await fetch(`${button.dataset.directoryUrl}?phone=${encodeURIComponent(phone.value)}`, {headers: {Accept: 'application/json'}});
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || 'Ingen treff.');
                [['name', data.name], ['address', data.address], ['postal_code', data.postal_code], ['city', data.city]].forEach(([name, value]) => {
                    const input = form.querySelector(`[name="${name}"]`);
                    if (input && value) { input.value = value; input.dispatchEvent(new Event('input', {bubbles: true})); }
                });
                if (status) status.textContent = 'Opplysninger funnet. Kontroller før du lagrer.';
            } catch (error) {
                if (status) status.textContent = error.message || 'Oppslaget feilet.';
            } finally { button.disabled = false; }
        });
    });
});
