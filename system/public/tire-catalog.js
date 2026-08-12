document.addEventListener('DOMContentLoaded',()=>{
  const area=document.querySelector('textarea[name="rows"]');
  const box=document.querySelector('[data-bulk-preview]');
  if(!area||!box)return;
  area.addEventListener('input',()=>{
    const rows=area.value.trim().split(/\r?\n/).filter(Boolean);
    const columns=rows[0]?.split(rows[0].includes('\t')?'\t':';').length||0;
    box.replaceChildren();
    const title=document.createElement('strong');
    const detail=document.createElement('span');
    title.textContent=rows.length?`${rows.length} rader funnet`:'Ingen rader limt inn ennå';
    detail.textContent=rows.length?`${columns} kolonner på første rad · forventet 9`:'Du får en kontroll før innsending.';
    box.append(title,detail);
    box.classList.toggle('ready',rows.length>0&&columns>=8);
  });
});
