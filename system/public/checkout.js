(() => {
    'use strict';

    const registration = document.getElementById('registration_number');
    registration?.addEventListener('input', () => {
        registration.value = registration.value.toUpperCase().replace(/[^A-ZÆØÅ0-9 ]/g, '');
    });

    const statusUrl = document.querySelector('meta[name="checkout-status-url"]')?.content;
    if (!statusUrl) return;

    let stopped = false;
    let delay = 6000;

    const poll = async () => {
        if (stopped || document.hidden) {
            window.setTimeout(poll, delay);
            return;
        }
        try {
            const response = await fetch(statusUrl, {
                headers: {Accept: 'application/json'},
                cache: 'no-store',
            });
            if (response.status === 429) {
                delay = Math.min(delay * 2, 60000);
            } else if (response.ok) {
                delay = 6000;
                const data = await response.json();
                if (['paid', 'expired', 'failed'].includes(data.status)) {
                    stopped = true;
                    window.location.reload();
                    return;
                }
            }
        } catch (_error) {
            delay = Math.min(delay * 2, 60000);
        }
        window.setTimeout(poll, delay);
    };

    window.setTimeout(poll, delay);
})();
