document.addEventListener('DOMContentLoaded', () => {
  const boxes = [...document.querySelectorAll('[data-label-id]')], all = document.querySelector('[data-select-all]'), batch = document.querySelector('[data-print-selected]');
  const modal = document.querySelector('[data-label-modal]'), frame = document.querySelector('[data-label-frame]');
  const openLabel = url => { if (!modal || !frame || !url) return; frame.src = url + (url.includes('?') ? '&' : '?') + 'embed=1'; modal.showModal(); };
  const sync = () => { const ids = boxes.filter(x => x.checked).map(x => x.value); if (batch) { batch.disabled = !ids.length; batch.dataset.ids = ids.join(','); } if (all) all.checked = ids.length === boxes.length && boxes.length > 0; };
  all?.addEventListener('change', () => { boxes.forEach(x => x.checked = all.checked); sync(); }); boxes.forEach(x => x.addEventListener('change', sync));
  document.querySelectorAll('[data-label-preview]').forEach(x => x.addEventListener('click', () => openLabel(x.dataset.labelPreview)));
  batch?.addEventListener('click', () => openLabel(batch.dataset.labelBase + '?ids=' + encodeURIComponent(batch.dataset.ids)));
  document.querySelectorAll('[data-label-close]').forEach(x => x.addEventListener('click', () => modal.close())); modal?.addEventListener('click', e => { if (e.target === modal) modal.close(); });
  document.querySelector('[data-label-print]')?.addEventListener('click', async () => { const ids=new URL(frame.src).searchParams.get('ids')?.split(',').filter(Boolean)||[];if(ids.length)await fetch(`${window.location.pathname.replace(/\/index\.php.*$/,'/index.php')}/lager/etiketter/utskrevet`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,'Accept':'application/json'},body:JSON.stringify({ids})});frame.contentWindow?.print(); });
  const workspace = document.querySelector('.inventory-workspace'), intake = workspace?.querySelector(':scope > aside');
  if (intake) { intake.classList.add('intake-drawer'); const close = document.createElement('button'); close.type = 'button'; close.className = 'intake-close'; close.textContent = '×'; close.setAttribute('aria-label','Lukk'); intake.prepend(close); close.addEventListener('click', () => intake.classList.remove('open')); intake.addEventListener('click', e => { if (e.target === intake) intake.classList.remove('open'); }); }
  document.querySelector('[data-intake-open]')?.addEventListener('click', () => intake?.classList.add('open'));
  const depths = [...document.querySelectorAll('[data-wheel-depth]')], lowest = document.querySelector('[data-lowest-depth]'), advice = document.querySelector('[data-depth-advice]'), summary = document.querySelector('[data-measurement-summary]'), season = document.querySelector('[data-intake-season]');
  const updateMeasurements = () => {
    if (!lowest || !advice || !summary) return;
    const values = depths.map(input => Number.parseFloat(input.value)).filter(Number.isFinite);
    summary.classList.remove('attention', 'replace');
    if (values.length !== 4) { lowest.textContent = `${values.length} av 4 målt`; advice.textContent = 'Alle fire dekk må måles før hjulsettet kan lagres.'; return; }
    const minimum = Math.min(...values), winter = season?.value === 'winter';
    lowest.textContent = `${minimum.toFixed(1).replace('.', ',')} mm`;
    if (minimum < (winter ? 3 : 1.6)) { summary.classList.add('replace'); advice.textContent = `Under lovkravet for ${winter ? 'vinterføre (3 mm)' : 'sommerføre (1,6 mm)'}. Må følges opp.`; }
    else if (minimum < 4) { summary.classList.add('attention'); advice.textContent = 'Bør følges opp. DekkPilot oppretter automatisk en salgsmulighet.'; }
    else advice.textContent = 'Målingene ser gode ut.';
  };
  depths.forEach(input => input.addEventListener('input', updateMeasurements)); season?.addEventListener('change', updateMeasurements); updateMeasurements();
  if (depths.some(input => input.value !== '')) intake?.classList.add('open');
});
