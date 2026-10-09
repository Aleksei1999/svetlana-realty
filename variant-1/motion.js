const toggle=document.querySelector('.menu-toggle');
const menu=document.querySelector('.mobile-menu');
toggle?.addEventListener('click',()=>{const open=toggle.getAttribute('aria-expanded')!=='true';toggle.setAttribute('aria-expanded',String(open));toggle.setAttribute('aria-label',open?'Закрыть меню':'Открыть меню');menu.hidden=!open;});
menu?.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{menu.hidden=true;toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-label','Открыть меню')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&menu){menu.hidden=true;toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-label','Открыть меню')}});
const progress=document.createElement('div');progress.className='scroll-progress';document.body.append(progress);
let queued=false;
const update=()=>{const total=document.documentElement.scrollHeight-innerHeight;progress.style.transform=`scaleX(${total>0?scrollY/total:0})`;queued=false;};
addEventListener('scroll',()=>{if(!queued){queued=true;requestAnimationFrame(update)}},{passive:true});update();
