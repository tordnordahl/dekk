document.addEventListener('DOMContentLoaded', () => {
  const open = dialog => { if (dialog && !dialog.open) dialog.showModal(); };
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-edit-open]');
    if (trigger) open(document.getElementById(trigger.dataset.editOpen));
    if (event.target.closest('[data-edit-close]')) event.target.closest('dialog')?.close();
    if (event.target.matches('dialog[data-edit-dialog]')) {
      const r = event.target.getBoundingClientRect();
      if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) event.target.close();
    }
  });
  document.querySelectorAll('dialog[data-reopen]').forEach(open);
  const form = document.querySelector('[data-product-search]');
  if (!form) return;
  const results = document.querySelector('[data-product-results]');
  const status = document.querySelector('[data-product-search-status]');
  const input = form.querySelector('[name=product_q]');
  let timer, controller, sequence = 0;
  const search = async url => {
    const current = ++sequence;
    controller?.abort(); controller = new AbortController();
    const requestController = controller;
    const timeout = setTimeout(() => requestController.abort(), 15000);
    results.setAttribute('aria-busy','true'); status.textContent = 'Søker …';
    try {
      const response = await fetch(url, {headers:{Accept:'application/json'},credentials:'same-origin',signal:controller.signal});
      if (!response.ok) throw new Error('request');
      const data = await response.json();
      if (current !== sequence) return;
      if (typeof data.html !== 'string') throw new Error('response');
      results.innerHTML = data.html;
      history.replaceState(null,'',url);
      status.textContent = results.querySelector('[data-result-count]')?.textContent || 'Søket er oppdatert.';
    } catch (error) {
      if (current === sequence) status.textContent = 'Søket kunne ikke fullføres. Prøv igjen med Søk-knappen.';
    } finally {
      clearTimeout(timeout);
      if (current === sequence) results.setAttribute('aria-busy','false');
    }
  };
  const queryUrl = () => {const url=new URL(form.action,location.href);url.search=new URLSearchParams(new FormData(form)).toString();return url;};
  input.addEventListener('input',()=>{clearTimeout(timer);++sequence;controller?.abort();timer=setTimeout(()=>search(queryUrl()),250);});
  form.addEventListener('submit',event=>{event.preventDefault();clearTimeout(timer);search(queryUrl());});
  results.addEventListener('click',event=>{const link=event.target.closest('a[href]');if(link && new URL(link.href).searchParams.has('products_page')){event.preventDefault();search(new URL(link.href));}});
});
