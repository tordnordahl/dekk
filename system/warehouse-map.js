document.addEventListener('DOMContentLoaded', () => {
    const floor = document.querySelector('[data-warehouse-floor]');
    if (!floor) return;
    const status = document.querySelector('[data-floor-status]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let active = null, pointerId = null, offsetX = 0, offsetY = 0, original = null;
    const tell = (message, state = '') => { if (status) { status.textContent = message; status.dataset.state = state; } };

    function position(event) {
        const floorRect = floor.getBoundingClientRect(), rackRect = active.getBoundingClientRect();
        const left = Math.min(Math.max(0, floorRect.width - rackRect.width), Math.max(0, event.clientX - floorRect.left - offsetX));
        const top = Math.min(Math.max(0, floorRect.height - rackRect.height), Math.max(0, event.clientY - floorRect.top - offsetY));
        active.style.setProperty('--x', (left / floorRect.width * 100).toFixed(2));
        active.style.setProperty('--y', (top / floorRect.height * 100).toFixed(2));
    }

    floor.addEventListener('pointerdown', event => {
        const rack = event.target.closest('[data-floor-rack]');
        if (!rack || event.target.closest('a')) return;
        event.preventDefault();
        const rect = rack.getBoundingClientRect();
        active = rack; pointerId = event.pointerId;
        offsetX = event.clientX - rect.left; offsetY = event.clientY - rect.top;
        original = {x: rack.style.getPropertyValue('--x'), y: rack.style.getPropertyValue('--y')};
        rack.classList.add('dragging'); rack.setPointerCapture(pointerId);
    });
    floor.addEventListener('pointermove', event => { if (active && event.pointerId === pointerId) { event.preventDefault(); position(event); } });

    async function finish(event) {
        if (!active || event.pointerId !== pointerId) return;
        const rack = active; rack.classList.remove('dragging'); active = null; pointerId = null;
        tell('Lagrer plasseringen …', 'saving');
        try {
            const response = await fetch(floor.dataset.moveUrl, {method: 'PUT', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'}, body: JSON.stringify({id: Number(rack.dataset.locationId), map_x: parseFloat(rack.style.getPropertyValue('--x')), map_y: parseFloat(rack.style.getPropertyValue('--y'))})});
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error();
            const result = await response.json();
            rack.style.setProperty('--x', result.position.x); rack.style.setProperty('--y', result.position.y);
            tell(result.message, 'success');
        } catch (_) {
            rack.style.setProperty('--x', original.x); rack.style.setProperty('--y', original.y);
            tell('Kunne ikke lagre. Reolen er satt tilbake.', 'error');
        }
    }
    floor.addEventListener('pointerup', finish);
    floor.addEventListener('pointercancel', finish);
});
