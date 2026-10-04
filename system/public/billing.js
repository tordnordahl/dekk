document.addEventListener('DOMContentLoaded', () => {
  const forms = [...document.querySelectorAll('[data-billing-action]')];
  const progress = document.querySelector('[data-billing-progress]');
  const gate = document.querySelector('.billing-gate');
  gate?.focus();
  let timer;
  const reset = () => {
    clearTimeout(timer);
    forms.forEach(form => {
      delete form.dataset.submitting;
      form.querySelectorAll('button').forEach(button => {
        if (button.dataset.billingText) button.textContent = button.dataset.billingText;
        button.disabled = false;
        button.removeAttribute('aria-busy');
      });
    });
  };
  forms.forEach(form => form.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    if (forms.some(item => item.dataset.submitting)) { event.preventDefault(); return; }
    form.dataset.submitting = 'true';
    const button = event.submitter;
    if (button) {
      button.dataset.billingText = button.textContent;
      button.textContent = 'Kobler til Stripe …';
      button.setAttribute('aria-busy', 'true');
    }
    if (progress) { progress.hidden = false; progress.textContent = 'Venter på Stripe. Du blir sendt videre automatisk.'; }
    timer = setTimeout(() => {
      reset();
      if (progress) progress.textContent = 'Dette tar lengre tid enn forventet. Du kan prøve igjen eller oppdatere status. DekkPilot gjenbruker betalingsforsøket.';
    }, 45000);
  }));
  window.addEventListener('pageshow', () => { reset(); if (progress) progress.hidden = true; });
  document.addEventListener('securitypolicyviolation', event => {
    if (event.effectiveDirective !== 'form-action') return;
    reset();
    if (progress) { progress.hidden = false; progress.textContent = 'Nettleseren stoppet videresendingen. Last siden på nytt og prøv igjen.'; }
  });
  gate?.addEventListener('keydown', event => {
    if (event.key !== 'Tab') return;
    const elements = [...gate.querySelectorAll('a[href], button, input, select, textarea')].filter(el => !el.disabled && el.getClientRects().length);
    const first = elements[0], last = elements[elements.length - 1];
    if (event.shiftKey && (document.activeElement === first || document.activeElement === gate)) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  });
});
