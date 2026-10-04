document.addEventListener('DOMContentLoaded',()=>{
 document.querySelectorAll('.vehicle-add').forEach(form=>{if(form.closest('details'))return;const details=document.createElement('details');details.className='progressive-form';const summary=document.createElement('summary');summary.textContent='Legg til kjøretøy';form.parentNode.insertBefore(details,form);details.append(summary,form)});
 const booking=document.querySelector('[data-booking-form]');
 document.querySelectorAll('[data-auto-submit]').forEach(field=>field.addEventListener('change',()=>field.form?.requestSubmit()));
 document.querySelectorAll('[data-confirm]').forEach(button=>button.addEventListener('click',event=>{if(!window.confirm(button.dataset.confirm||'Er du sikker?'))event.preventDefault()}));
 document.querySelectorAll('form').forEach(form=>form.addEventListener('submit',event=>{if(event.defaultPrevented||form.hasAttribute('data-billing-action'))return;const button=event.submitter;if(button){button.dataset.originalText=button.textContent;button.textContent='Jobber …';button.setAttribute('aria-busy','true')}}));
});
