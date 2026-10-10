const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
const reduced = matchMedia('(prefers-reduced-motion: reduce)');
const mobile = matchMedia('(max-width: 700px)');
const clamp = (n,a=0,b=1) => Math.max(a,Math.min(b,n));
document.body.classList.add('motion-ready');

let sceneIndex = 0;
function showScene(index) {
 sceneIndex = index;
 $$('[data-scene]').forEach((b,i)=>{b.classList.toggle('active',i===index);b.setAttribute('aria-pressed',String(i===index));});
 $$('[data-panel]').forEach((el,i)=>{el.classList.toggle('active',i===index);el.inert=i!==index;});
 $('.scene-progress i').style.transform=`translateX(${index*100}%)`;
}
$$('[data-scene]').forEach(button => button.addEventListener('click',()=>{
 const i=Number(button.dataset.scene);showScene(i);
 if(!mobile.matches&&!reduced.matches){const el=$('#approach');window.scrollTo({top:el.offsetTop+(el.offsetHeight-innerHeight)*((i+.1)/4),behavior:'smooth'});}
}));

const manifest=$('.manifest-text');
const manifestCopy=manifest.textContent.trim();
manifest.setAttribute('aria-label',manifestCopy);
manifest.innerHTML=manifestCopy.split(' ').map(word=>`<span aria-hidden="true">${word} </span>`).join('');
const words=[...manifest.children];
let frame=0, heroValue=0, cardsValue=0;
function progress(el){const rect=el.getBoundingClientRect();return clamp(-rect.top/Math.max(1,el.offsetHeight-innerHeight));}
function updateMotion(){
 frame=0;
 if(reduced.matches){words.forEach(w=>{w.style.opacity=1;w.style.filter='none'});return;}
 const h=progress($('#hero')); heroValue+=(h-heroValue)*.14;
 $('.hero-art').style.transform=`scale(${1-heroValue*.12})`;
 $('.hero-art').style.borderRadius=`${heroValue*35}px`;
 $('.hero-person').style.transform=`translateY(${heroValue*4}%) scale(${1+heroValue*.025})`;
 if(!mobile.matches){
  const a=$('#approach'),r=a.getBoundingClientRect();
  if(r.top<=2&&r.bottom>=innerHeight-2){const i=Math.min(3,Math.floor(progress(a)*4));if(i!==sceneIndex)showScene(i);}
  const section=$('#program'), sticky=$('.cards-sticky'), track=$('.cards-track');
  const start=section.offsetTop+$('.services-heading').offsetHeight-innerHeight*.05;
  const range=Math.max(1,section.offsetHeight-$('.services-heading').offsetHeight-sticky.offsetHeight);
  const p=clamp((scrollY-start)/range);cardsValue+=(p-cardsValue)*.12;
  const travel=Math.max(0,track.scrollWidth-innerWidth+innerWidth*.04);
  track.style.transform=`translateX(${-cardsValue*travel}px)`;
  $$('.lesson-card').forEach((el,i)=>{const local=clamp((cardsValue-i*.12)*2,-1,1);el.style.transform=`rotate(${(i%2?3:-3)*(1-Math.abs(local)*.7)}deg) translateY(${Math.sin(i*1.2+cardsValue*3)*13}px)`;});
 }else{$('.cards-track').style.transform='';}
 const m=$('.manifest-track'),mp=progress(m);
 words.forEach((w,i)=>{const v=clamp((mp*.95+.12-i/words.length)*7);w.style.opacity=.18+v*.82;w.style.filter=`blur(${(1-v)*4}px)`;});
 if(Math.abs(h-heroValue)>.001||(!mobile.matches&&Math.abs(clamp((scrollY-$('#program').offsetTop-$('.services-heading').offsetHeight+innerHeight*.05)/Math.max(1,$('#program').offsetHeight-$('.services-heading').offsetHeight-$('.cards-sticky').offsetHeight))-cardsValue)>.001))schedule();
}
function schedule(){if(!frame)frame=requestAnimationFrame(updateMotion);}
addEventListener('scroll',schedule,{passive:true});addEventListener('resize',schedule);reduced.addEventListener('change',schedule);mobile.addEventListener('change',schedule);schedule();

// Individual translate composes with the existing scroll transforms.
const pointerDevice = matchMedia('(hover: hover) and (pointer: fine)');
const heroStage = $('.hero-stage');
const pointerLayers = [
 {el:$('.hero-person'),x:26,y:16},
 {el:$('.hero-art h1'),x:-18,y:-11},
 {el:$('.hero-meta'),x:-8,y:-5},
 {el:$('.glow-one'),x:42,y:24},
 {el:$('.glow-two'),x:-30,y:-18}
];
let pointerFrame=0, pointerTime=0, pointerX=0, pointerY=0, targetX=0, targetY=0, heroVisible=true;
const pointerEnabled=()=>pointerDevice.matches&&!mobile.matches&&!reduced.matches&&heroVisible&&document.visibilityState!=='hidden';
function paintPointer(){
 pointerLayers.forEach(({el,x,y})=>{el.style.translate=`${(pointerX*x).toFixed(3)}px ${(pointerY*y).toFixed(3)}px`;});
}
function animatePointer(time){
 pointerFrame=0;
 const dt=pointerTime?Math.min(64,time-pointerTime):16.7;
 pointerTime=time;
 const ease=1-Math.exp(-dt/140);
 pointerX+=(targetX-pointerX)*ease;pointerY+=(targetY-pointerY)*ease;
 const settled=Math.abs(targetX-pointerX)<.0005&&Math.abs(targetY-pointerY)<.0005;
 if(settled){pointerX=targetX;pointerY=targetY;pointerTime=0;}
 paintPointer();
 if(!settled)pointerFrame=requestAnimationFrame(animatePointer);
}
function schedulePointer(){if(!pointerFrame)pointerFrame=requestAnimationFrame(animatePointer);}
function resetPointer(immediate=false){
 targetX=0;targetY=0;
 if(immediate){cancelAnimationFrame(pointerFrame);pointerFrame=0;pointerTime=0;pointerX=0;pointerY=0;paintPointer();}
 else schedulePointer();
}
heroStage.addEventListener('pointermove',event=>{
 if(event.pointerType==='touch'||!pointerEnabled())return;
 const r=heroStage.getBoundingClientRect();
 targetX=clamp((event.clientX-r.left)/r.width*2-1,-1,1);
 targetY=clamp((event.clientY-r.top)/r.height*2-1,-1,1);
 schedulePointer();
},{passive:true});
heroStage.addEventListener('pointerleave',()=>resetPointer());
heroStage.addEventListener('pointercancel',()=>resetPointer(true));
addEventListener('blur',()=>resetPointer(true));
addEventListener('resize',()=>resetPointer(true),{passive:true});
document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden')resetPointer(true);});
[pointerDevice,mobile,reduced].forEach(query=>query.addEventListener('change',()=>resetPointer(true)));
new IntersectionObserver(entries=>{heroVisible=entries[0].isIntersecting;if(!heroVisible)resetPointer(true);}).observe(heroStage);

const materials=[["Диагностика", "Диагностика — прозрачная оценка текущей ситуации."], ["Чек-листы и шпаргалки", "Чек-листы и памятки для этапов работы с объектами."], ["Дневник инвестора", "Дневник инвестора с расчётами, критериями, найденными объектами и планом действий."], ["Стратегические сессии", "4 стратегические сессии: 3 групповые и 1 персональная."]];
const info=$('#infoDialog');
function openInfo(label,title,text){$('#infoLabel').textContent=label;$('#infoTitle').textContent=title;$('#infoText').textContent=text;info.showModal();}
$$('[data-material]').forEach(b=>b.addEventListener('click',()=>openInfo('МАТЕРИАЛЫ ОБУЧЕНИЯ',...materials[Number(b.dataset.material)])));
$('#infoAction').addEventListener('click',()=>{info.close();$('#formats').scrollIntoView({behavior:reduced.matches?'instant':'smooth'});});
$$('[data-info]').forEach(b=>b.addEventListener('click',()=>openInfo('ПЕРЕД ОФОРМЛЕНИЕМ','Приём заявок готовится','Сейчас доступен предпросмотр программ и анкет. Оплата через ЮKassa, запись заявок в Google Таблицу, оферта и политика обработки персональных данных ещё не подключены. Обучение посвящено анализу недвижимости и не гарантирует инвестиционный результат.')));
const formDialog=$('#formDialog');
$$('[data-form]').forEach(b=>b.addEventListener('click',()=>{
 const magic=b.dataset.form==='magic';$('#leadForm').reset();$('#formResult').textContent='';
 $('#formTitle').textContent=magic?'Встать в лист ожидания':'Старт';
 $('#formLabel').textContent=magic?'МАГИЯ ДОХОДНОЙ НЕДВИЖИМОСТИ':'7 ВИДЕОУРОКОВ / 4 990 ₽';
 $('#extraFields').innerHTML=magic?'<label>Опыт в недвижимости<select name="experience"><option>Пока нет опыта</option><option>Есть собственная недвижимость</option><option>Есть инвестиционный опыт</option></select></label><label>Цель участия<textarea name="goal" required placeholder="Чего хотите достичь"></textarea></label><label>Примерный капитал (необязательно)<input name="capital" placeholder="По желанию"></label><label>Главный вопрос<textarea name="question" placeholder="Что хотелось бы обсудить"></textarea></label>':'<label>Email<input name="email" type="email" autocomplete="email" required placeholder="you@example.com"></label>';
 formDialog.showModal();
}));
$$('dialog').forEach(d=>{d.querySelector('.close').addEventListener('click',()=>d.close());d.addEventListener('click',e=>{if(e.target!==d)return;const r=d.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)d.close();});});
$('#leadForm').addEventListener('submit',e=>{e.preventDefault();$('#formResult').textContent='Поля заполнены корректно. Данные не отправлены: приём заявок и оплата пока не открыты.';});

const reviewPlayers=$$('[data-review-video]');
reviewPlayers.forEach(card=>{
 const video=card.querySelector('video'),button=card.querySelector('.review-play'),status=card.querySelector('.video-status');
 const showError=()=>{card.classList.remove('is-playing');status.hidden=false;status.textContent='Не удалось загрузить видео. Попробуйте ещё раз.';};
 let stream = null, streamReady = false, loading = false;
 async function prepareStream() {
  const src = video.dataset.hlsSrc;
  if (!src || streamReady) return;
  if (video.canPlayType('application/vnd.apple.mpegurl')) {
   video.src = src; streamReady = true;
  } else if (window.Hls && Hls.isSupported()) {
   await new Promise((resolve, reject) => {
    stream = new Hls({maxBufferLength:30, maxMaxBufferLength:60, backBufferLength:30});
    stream.on(Hls.Events.MANIFEST_PARSED, () => {streamReady = true; resolve();});
    stream.on(Hls.Events.ERROR, (_, data) => {
     if (!data.fatal) return;
     stream.destroy(); stream = null; streamReady = false;
     video.pause(); showError(); reject(new Error('Video stream unavailable'));
    });
    stream.loadSource(src); stream.attachMedia(video);
   });
  } else { throw new Error('Video stream unsupported'); }
  video.controls = true;
 }
 button.addEventListener('click', async () => {
  if (loading) return;
  status.hidden = true; loading = true;
  button.disabled = true; card.setAttribute('aria-busy','true');
  try { await prepareStream(); await video.play(); }
  catch (_) { showError(); }
  finally { loading = false; button.disabled = false; card.removeAttribute('aria-busy'); }
 });
 video.addEventListener('play',()=>{
  reviewPlayers.forEach(other=>{const player=other.querySelector('video');if(player!==video)player.pause();});
  card.classList.add('is-playing');status.hidden=true;
 });
 video.addEventListener('pause',()=>card.classList.remove('is-playing'));
 video.addEventListener('ended',()=>card.classList.remove('is-playing'));
 video.addEventListener('error',showError);
});
document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden')reviewPlayers.forEach(card=>card.querySelector('video').pause());});

const faqs=[["О программе и формате", "Для тех, кто хочет разобраться в своих возможностях и понять, с чего начать."], ["О времени и доступе", "Обучение в Telegram. Доступ на 3 месяца."], ["Магия доходной недвижимости", "Для тех, кто хочет не просто разобраться, а работать со своей стратегией и делать практические шаги во время обучения."], ["Стоимость и дата старта", "Стоимость программы фиксирована для участников соответствующего потока и сообщается лично на консультации. Дата следующего старта пока не определена."]];
$('#shortFaq').innerHTML=faqs.map(x=>`<details class="faq-item"><summary>${x[0]}</summary><p>${x[1]}</p></details>`).join('');

// Re-entering the viewport replays the entrance, without touching scroll transforms.
const entranceSelectors = '.eyebrow, .gallery-card, .scene-window, .scene-nav button, .services-heading .lead, .material-list button, .price-card, .reviews-intro, .video-review, .faq-item, .footer-content h2, .footer-content > .pill, .footer-links';
$$(entranceSelectors).forEach(el => {
 if (!el.classList.contains('reveal') && !el.parentElement.closest('.reveal')) el.classList.add('reveal');
});
$$('.gallery-grid, .benefits, .price-grid, .video-reviews, .scene-nav, .material-list').forEach(group => {
 [...group.children].forEach((el, i) => el.style.setProperty('--reveal-delay', `${(i % 4) * 85}ms`));
});
const entranceElements = $$('.reveal');
const entranceObserver = new IntersectionObserver(entries => {
 entries.forEach(entry => entry.target.classList.toggle('visible', reduced.matches || entry.isIntersecting));
}, {threshold: 0, rootMargin: '0px 0px -24px 0px'});
function configureEntrances() {
 entranceObserver.disconnect();
 entranceElements.forEach(el => {
  if (reduced.matches) el.classList.add('visible');
  else entranceObserver.observe(el);
 });
}
configureEntrances();
reduced.addEventListener('change', configureEntrances);

// A small direction threshold prevents the glass header from flickering on trackpads.
const siteHeader = $('.header');
let headerLastY = Math.max(0, scrollY), headerDirection = 0, headerTravel = 0, headerFrame = 0;
siteHeader.classList.toggle('is-glass', headerLastY > 80);
siteHeader.classList.toggle('is-hidden', headerLastY > 160);
function updateHeader() {
 headerFrame = 0;
 const y = clamp(scrollY, 0, Math.max(0, document.documentElement.scrollHeight - innerHeight));
 const delta = y - headerLastY;
 siteHeader.classList.toggle('is-glass', y > 80);
 if (y < 80) { siteHeader.classList.remove('is-hidden'); headerTravel = 0; }
 else if (Math.abs(delta) > 0.5) {
  const direction = Math.sign(delta);
  if (direction !== headerDirection) headerTravel = 0;
  headerTravel += Math.abs(delta); headerDirection = direction;
  if (headerTravel > 24) siteHeader.classList.toggle('is-hidden', direction > 0 && y > 160);
 }
 headerLastY = y;
}
addEventListener('scroll', () => { if (!headerFrame) headerFrame = requestAnimationFrame(updateHeader); }, {passive: true});
