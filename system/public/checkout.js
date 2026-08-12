(() => {
    'use strict';

    const registration = document.getElementById('registration_number');
    registration?.addEventListener('input', () => {
        registration.value = registration.value.toUpperCase().replace(/[^A-ZÆØÅ0-9 ]/g, '');
    });

    const statusUrl = document.querySelector('meta[name="checkout-status-url"]')?.content;
    if (!statusUrl) return;

    const poll = window.setInterval(async () => {
        try {
            const response = await fetch(statusUrl, {headers: {Accept: 'application/json'}});
            if (!response.ok) return;
            const data = await response.json();
            if (data.status === 'paid' || data.status === 'expired') {
                window.clearInterval(poll);
                window.location.reload();
            }
        } catch (_error) {
            // Midlertidige nettverksfeil forsøkes igjen ved neste intervall.
        }
    }, 3000);
})();
