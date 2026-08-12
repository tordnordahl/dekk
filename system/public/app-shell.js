document.addEventListener('DOMContentLoaded', () => {
  const button=document.querySelector('[data-nav-toggle]');
  if(!button)return;
  const backdrop=document.querySelector('[data-nav-backdrop]');
  const closeButton=document.querySelector('[data-nav-close]');
  const key='dekkpilot.navigation.collapsed';
  const mobileQuery=window.matchMedia('(max-width:650px)');
  const isMobile=()=>mobileQuery.matches;
  const isTechnician=()=>document.body.classList.contains('ui-mode-technician');
  const applyDesktop=collapsed=>{document.body.classList.toggle('nav-collapsed',collapsed);button.setAttribute('aria-expanded',String(!collapsed));button.classList.toggle('is-open',!collapsed);button.title=collapsed?'Vis meny':'Skjul meny'};
  const applyMobile=open=>{document.body.classList.toggle('mobile-nav-open',open);button.setAttribute('aria-expanded',String(open));button.classList.toggle('is-open',open);button.title=open?'Lukk meny':'Vis meny'};
  let collapsed=false;try{collapsed=localStorage.getItem(key)==='1'}catch(e){}
  const sync=()=>{if(isMobile()){document.body.classList.remove('nav-collapsed');applyMobile(false);return}document.body.classList.remove('mobile-nav-open');applyDesktop(collapsed)};
  sync();
  button.addEventListener('click',()=>{
    if(isMobile()){if(!isTechnician())applyMobile(!document.body.classList.contains('mobile-nav-open'));return}
    collapsed=!document.body.classList.contains('nav-collapsed');applyDesktop(collapsed);try{localStorage.setItem(key,collapsed?'1':'0')}catch(e){}
  });
  if(closeButton)closeButton.addEventListener('click',()=>applyMobile(false));
  if(backdrop)backdrop.addEventListener('click',()=>applyMobile(false));
  document.querySelectorAll('#main-navigation nav a').forEach(link=>link.addEventListener('click',()=>{if(isMobile())applyMobile(false)}));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&isMobile())applyMobile(false)});
  if(mobileQuery.addEventListener)mobileQuery.addEventListener('change',sync);else mobileQuery.addListener(sync);
});
