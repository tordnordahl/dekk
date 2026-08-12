document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[action*="git-oppdatering"]').forEach(form => {
        let submitted = false;
        form.addEventListener('submit', event => {
            if (submitted) {
                event.preventDefault();
                return;
            }
            submitted = true;
            const button = form.querySelector('button[type="submit"], button:not([type])');
            if (!button) return;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.dataset.label = button.textContent;
            button.textContent = 'Arbeider … ikke lukk siden';
        });
    });
});
