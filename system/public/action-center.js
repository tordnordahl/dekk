document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.querySelector('[data-action-tire-dialog]');
    const form = document.querySelector('[data-action-tire-form]');
    let tires = [];
    try { tires = JSON.parse(dialog?.dataset.tires || '[]'); } catch (_error) {}

    const allSections = [...(form?.querySelectorAll('.action-dialog-section') || [])];
    const sectionKinds = ['location', 'measure', 'wash', 'label'];
    const progress = dialog?.querySelector('[data-action-step-progress]');
    const backButton = form?.querySelector('[data-action-step-back]');
    const nextButton = form?.querySelector('[data-action-step-next]');
    const saveButton = form?.querySelector('[data-action-step-save]');
    const depths = [...document.querySelectorAll('[data-action-depth]')];
    const depthResult = document.querySelector('[data-action-depth-result]');
    let activeSections = [];
    let currentStep = 0;

    const updateDepth = () => {
        const values = depths.map(input => Number.parseFloat(input.value)).filter(Number.isFinite);
        depthResult?.classList.remove('attention', 'replace');
        if (!depthResult) return;
        if (values.length !== 4) {
            depthResult.textContent = `${values.length} av 4 målt`;
            return;
        }
        const minimum = Math.min(...values);
        depthResult.textContent = `Laveste måling: ${minimum.toFixed(1).replace('.', ',')} mm`;
        if (minimum < 3) depthResult.classList.add('replace');
        else if (minimum < 4) depthResult.classList.add('attention');
    };

    const showStep = index => {
        currentStep = Math.max(0, Math.min(index, activeSections.length - 1));
        allSections.forEach(section => { section.hidden = true; section.classList.remove('is-current'); });
        const section = activeSections[currentStep];
        if (section) { section.hidden = false; section.classList.add('is-current'); }
        if (progress) progress.textContent = `STEG ${currentStep + 1} AV ${activeSections.length}`;
        if (backButton) backButton.hidden = currentStep === 0;
        if (nextButton) nextButton.hidden = currentStep >= activeSections.length - 1;
        if (saveButton) saveButton.hidden = currentStep < activeSections.length - 1;
    };

    const currentIsValid = () => {
        const controls = [...(activeSections[currentStep]?.querySelectorAll('input, select, textarea') || [])];
        for (const control of controls) {
            if (!control.checkValidity()) { control.reportValidity(); return false; }
        }
        return true;
    };

    const openTire = id => {
        const tire = tires.find(item => Number(item.id) === Number(id));
        if (!tire || !dialog || !form) return;
        form.action = tire.action;
        dialog.dataset.selectedTire = tire.id;
        dialog.querySelector('[data-action-tire-code]').textContent = tire.code;
        dialog.querySelector('[data-action-tire-title]').textContent = tire.registration || 'Hjulsett';
        dialog.querySelector('[data-action-tire-customer]').textContent = tire.customer || 'Ukjent kunde';
        const customerLink = dialog.querySelector('[data-action-customer-link]');
        if (customerLink) customerLink.href = tire.customer_url || tire.tire_url;
        dialog.querySelector('[data-action-label-registration]').textContent = tire.registration || 'Uten registreringsnummer';
        dialog.querySelector('[data-action-label-code]').textContent = tire.code;
        form.querySelectorAll('[name="storage_location_id"]').forEach(input => {
            input.checked = Number(input.value) === Number(tire.location_id);
            if (input.checked) input.disabled = false;
        });
        form.querySelectorAll('[name="wash_status"]').forEach(input => { input.checked = input.value === (tire.wash_status === 'not_assessed' ? '' : tire.wash_status); });
        depths.forEach(input => { input.value = tire.measurements?.[input.dataset.actionDepth] ?? ''; });
        updateDepth();
        const requestedKinds = tire.needs?.length ? tire.needs : ['label'];
        activeSections = allSections.filter((_section, index) => requestedKinds.includes(sectionKinds[index]));
        if (!activeSections.length) activeSections = [allSections[allSections.length - 1]];
        activeSections.forEach((section, index) => {
            const number = section.querySelector('.step-label>b');
            if (number) number.textContent = String(index + 1);
        });
        showStep(0);
        dialog.showModal();
    };

    document.querySelectorAll('[data-action-tire-open]').forEach(button => button.addEventListener('click', () => openTire(button.dataset.actionTireOpen)));
    document.querySelectorAll('[data-action-tire-close]').forEach(button => button.addEventListener('click', () => dialog?.close()));
    depths.forEach(input => input.addEventListener('input', updateDepth));
    backButton?.addEventListener('click', () => showStep(currentStep - 1));
    nextButton?.addEventListener('click', () => { if (currentIsValid()) showStep(currentStep + 1); });
    form?.addEventListener('submit', event => { if (!currentIsValid()) event.preventDefault(); });
    if (dialog?.dataset.openId) window.setTimeout(() => openTire(dialog.dataset.openId), 0);

    const labelModal = document.querySelector('[data-action-label-modal]');
    const labelFrame = document.querySelector('[data-action-label-frame]');
    document.querySelector('[data-action-label-preview]')?.addEventListener('click', event => {
        labelFrame.src = event.currentTarget.dataset.actionLabelPreview + (event.currentTarget.dataset.actionLabelPreview.includes('?') ? '&' : '?') + 'embed=1';
        labelModal.showModal();
    });
    document.querySelectorAll('[data-action-label-close]').forEach(button => button.addEventListener('click', () => labelModal?.close()));
    document.querySelector('[data-action-label-print]')?.addEventListener('click', () => labelFrame?.contentWindow?.print());

    const camera = document.querySelector('[data-action-camera-modal]');
    const video = document.querySelector('[data-action-camera-video]');
    const cameraStatus = document.querySelector('[data-action-camera-status]');
    const codeInput = document.querySelector('[data-action-code]');
    let stream;
    let running = false;
    const stopCamera = () => { running = false; stream?.getTracks().forEach(track => track.stop()); stream = null; };
    document.querySelector('[data-action-camera-open]')?.addEventListener('click', () => camera?.showModal());
    document.querySelector('[data-action-camera-close]')?.addEventListener('click', () => { stopCamera(); camera?.close(); });
    document.querySelector('[data-action-camera-start]')?.addEventListener('click', async () => {
        if (!('BarcodeDetector' in window)) { cameraStatus.textContent = 'Nettleseren støtter ikke direkte kodeskanning. Skriv hjulkoden i feltet i stedet.'; return; }
        try {
            stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}}});
            video.srcObject = stream;
            await video.play();
            running = true;
            cameraStatus.textContent = 'Leter etter etikett …';
            const detector = new BarcodeDetector({formats: ['code_39', 'code_128', 'qr_code']});
            const scan = async () => {
                if (!running) return;
                try {
                    const codes = await detector.detect(video);
                    if (codes[0]?.rawValue) {
                        codeInput.value = codes[0].rawValue.toUpperCase().trim();
                        stopCamera();
                        camera.close();
                        codeInput.form.submit();
                        return;
                    }
                } catch (_error) {}
                window.requestAnimationFrame(scan);
            };
            scan();
        } catch (_error) { cameraStatus.textContent = 'Kunne ikke åpne kameraet. Kontroller kameratillatelsen eller skriv koden.'; }
    });
    camera?.addEventListener('close', stopCamera);
});
