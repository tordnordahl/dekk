document.addEventListener('DOMContentLoaded', () => {
    const viewport = document.querySelector('[data-map-viewport]');
    const stage = document.querySelector('[data-map-stage]');
    const surface = document.querySelector('[data-map-surface]');

    if (!viewport || !stage || !surface) return;

    const baseWidth = 900;
    const baseHeight = 650;
    const minimumScale = 0.55;
    const maximumScale = 1.6;
    let scale = 1;

    const label = document.querySelector('[data-map-zoom-label]');
    const clamp = (value) => Math.min(maximumScale, Math.max(minimumScale, value));

    function applyScale(nextScale, preserveCenter = true) {
        const oldScale = scale;
        const centerX = (viewport.scrollLeft + viewport.clientWidth / 2) / oldScale;
        const centerY = (viewport.scrollTop + viewport.clientHeight / 2) / oldScale;
        scale = clamp(nextScale);
        surface.style.setProperty('--map-scale', scale);
        stage.style.width = `${baseWidth * scale}px`;
        stage.style.height = `${baseHeight * scale}px`;
        if (label) label.value = `${Math.round(scale * 100)} %`;

        if (preserveCenter) {
            requestAnimationFrame(() => {
                viewport.scrollLeft = centerX * scale - viewport.clientWidth / 2;
                viewport.scrollTop = centerY * scale - viewport.clientHeight / 2;
            });
        }
    }

    function fitMap() {
        const available = Math.max(260, viewport.clientWidth - 12);
        applyScale(clamp(available / baseWidth), false);
        viewport.scrollTo({ left: 0, top: 0, behavior: 'smooth' });
    }

    document.querySelector('[data-map-zoom-in]')?.addEventListener('click', () => applyScale(scale + 0.15));
    document.querySelector('[data-map-zoom-out]')?.addEventListener('click', () => applyScale(scale - 0.15));
    document.querySelector('[data-map-fit]')?.addEventListener('click', fitMap);

    viewport.addEventListener('keydown', (event) => {
        const amount = 80;
        const movement = {
            ArrowLeft: [-amount, 0], ArrowRight: [amount, 0],
            ArrowUp: [0, -amount], ArrowDown: [0, amount],
        }[event.key];
        if (!movement) return;
        event.preventDefault();
        viewport.scrollBy({ left: movement[0], top: movement[1], behavior: 'smooth' });
    });

    document.querySelectorAll('.map-results a[href^="#map-location-"]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const rack = document.querySelector(link.getAttribute('href'));
            if (!rack) return;
            event.preventDefault();
            const left = (rack.offsetLeft + rack.offsetWidth / 2) * scale - viewport.clientWidth / 2;
            const top = (rack.offsetTop + rack.offsetHeight / 2) * scale - viewport.clientHeight / 2;
            viewport.scrollTo({ left, top, behavior: 'smooth' });
            rack.focus({ preventScroll: true });
        });
    });

    if (window.matchMedia('(max-width: 650px)').matches) {
        applyScale(0.8, false);
    } else {
        applyScale(1, false);
    }

    const highlighted = surface.querySelector('.floor-rack.highlight');
    if (highlighted) {
        requestAnimationFrame(() => {
            viewport.scrollLeft = (highlighted.offsetLeft + highlighted.offsetWidth / 2) * scale - viewport.clientWidth / 2;
            viewport.scrollTop = (highlighted.offsetTop + highlighted.offsetHeight / 2) * scale - viewport.clientHeight / 2;
        });
    }
});
