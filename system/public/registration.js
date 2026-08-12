(() => {
  const form = document.querySelector('[data-registration-form]');
  if (!form) return;
  const input = form.querySelector('[name="organization_number"]');
  const result = document.querySelector('#company-result');
  const showResult = (title, detail = '') => {
    result.replaceChildren();
    const strong = document.createElement('strong');
    strong.textContent = String(title || '');
    result.append(strong);
    if (detail) { const span = document.createElement('span'); span.textContent = String(detail); result.append(span); }
  };
  let timer;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const number = input.value.replace(/\D/g, '');
    result.hidden = true;
    if (number.length !== 9) return;
    result.hidden = false;
    showResult('Kontrollerer virksomheten …');
    timer = setTimeout(async () => {
      try {
        const response = await fetch(`${form.dataset.lookupUrl}?organization_number=${encodeURIComponent(number)}`, {headers: {'Accept': 'application/json'}});
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Kunne ikke kontrollere virksomheten.');
        showResult(data.name, `Org.nr. ${data.organization_number || ''}${data.organization_type_name ? ` · ${data.organization_type_name}` : ''}`);
      } catch (error) {
        showResult('Kunne ikke bekrefte', error.message);
      }
    }, 350);
  });
})();
