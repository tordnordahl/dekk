document.addEventListener('DOMContentLoaded',()=>{
 const form=document.querySelector('[data-booking-form]'),toggle=form?.querySelector('[data-drop-in]');if(!form||!toggle)return;
 const scheduled=form.querySelector('[data-scheduled-time]'),startWrap=form.querySelector('[data-start-time]'),start=form.querySelector('[name="starts_at"]'),submit=form.querySelector('button[type="submit"]');
 const update=()=>{const active=toggle.checked;scheduled.hidden=active;startWrap.hidden=active;start.required=!active;if(active)start.value='';if(submit)submit.textContent=active?'Legg til i dagens drop-in-kø':'Book og send bekreftelse'};
 toggle.addEventListener('change',update);update();
});
