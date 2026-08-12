document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-task-open]').forEach(button=>button.addEventListener('click',()=>document.getElementById(button.dataset.taskOpen)?.showModal()));
  document.querySelectorAll('.work-task-dialog').forEach(dialog=>{
    dialog.querySelectorAll('[data-task-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
    dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close()});
    const result=dialog.querySelector('[name="result"]'),notes=dialog.querySelector('[name="notes"]');
    dialog.querySelector('[data-task-form]')?.addEventListener('submit',event=>{
      const submitter=event.submitter;
      if(submitter?.value==='1'&&result?.value==='deviation'&&!notes?.value.trim()){
        event.preventDefault();notes.setCustomValidity('Beskriv avviket før du lagrer.');notes.reportValidity();notes.focus();
      }else notes?.setCustomValidity('');
    });
    notes?.addEventListener('input',()=>notes.setCustomValidity(''));
  });
});
