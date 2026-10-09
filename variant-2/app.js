const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
const reduced = matchMedia('(prefers-reduced-motion: reduce)');
const mobile = matchMedia('(max-width: 700px)');
const clamp = (n,a=0,b=1) => Math.max(a,Math.min(b,n));
document.body.classList.add('motion-ready');
const observer = new IntersectionObserver(entries => entries.forEach(e => { if(e.isIntersecting){e.target.classList.add('visible');observer.unobserve(e.target);} }),{threshold:.12});
$$('.reveal').forEach(el => observer.observe(el));

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

const lessons=[
 ['Диагностика текущей ситуации','Определите свою точку старта: цели, текущие возможности и вопросы, с которыми вы приходите в недвижимость. Это основа для выбора дальнейшего пути.'],
 ['Виды недвижимости','Познакомьтесь с видами недвижимости и научитесь соотносить форматы объектов со своими целями и ресурсами.'],
 ['Три ингредиента успешной сделки','Разберите ключевые составляющие сделки и посмотрите, как они связаны между собой. Учитесь оценивать решение целиком.'],
 ['Поиск и оценка объекта','Перейдите от общего интереса к поиску и сравнению. Разберите, на какую информацию опираться при оценке объекта.'],
 ['Документы и безопасность','Изучите вопросы подготовки и проверки документов, которые важно учитывать до принятия решения о сделке.'],
 ['Заёмные средства и инструменты инвестирования','Познакомьтесь с заёмными средствами и инструментами инвестирования. Рассматривайте их в связи со своими задачами и рисками.']
];
const materials=[['Диагностика','Помогает зафиксировать текущую ситуацию, возможности и цели. Предусмотрена в программе «Старт» и служит основой дальнейшей работы.'],['Чек-листы и шпаргалки','Короткие опорные материалы для повторения и применения изученного. Входят в «Старт»; практические задания, чек-листы и памятки также предусмотрены в «Магии».'],['Дневник инвестора','Материал программы «Магия доходной недвижимости» для расчётов, записи критериев выбора, найденных объектов и собственного плана действий.'],['Стратегические сессии','В программе «Магия» предусмотрены три групповые и одна персональная стратегическая сессия. Расписание уточняется для соответствующего потока.']];
const info=$('#infoDialog');
function openInfo(label,title,text){$('#infoLabel').textContent=label;$('#infoTitle').textContent=title;$('#infoText').textContent=text;info.showModal();}
$$('[data-lesson]').forEach(b=>b.addEventListener('click',()=>{const i=Number(b.dataset.lesson);openInfo(`ПРОГРАММА «СТАРТ» / УРОК ${i+1}`, ...lessons[i]);}));
$$('[data-material]').forEach(b=>b.addEventListener('click',()=>openInfo('МАТЕРИАЛЫ ОБУЧЕНИЯ',...materials[Number(b.dataset.material)])));
$('#infoAction').addEventListener('click',()=>{info.close();$('#formats').scrollIntoView({behavior:reduced.matches?'instant':'smooth'});});
$$('[data-info]').forEach(b=>b.addEventListener('click',()=>openInfo('ПЕРЕД ОФОРМЛЕНИЕМ','Приём заявок готовится','Сейчас доступен предпросмотр программ и анкет. Оплата через ЮKassa, запись заявок в Google Таблицу, оферта и политика обработки персональных данных ещё не подключены. Обучение посвящено анализу недвижимости и не гарантирует инвестиционный результат.')));
const formDialog=$('#formDialog');
$$('[data-form]').forEach(b=>b.addEventListener('click',()=>{
 const magic=b.dataset.form==='magic';$('#leadForm').reset();$('#formResult').textContent='';
 $('#formTitle').textContent=magic?'Лист ожидания «Магии»':'Начать со «Старта»';
 $('#formLabel').textContent=magic?'ГЛУБИНА И СОПРОВОЖДЕНИЕ':'6 УРОКОВ / 4 990 ₽';
 $('#extraFields').innerHTML=magic?'<label>Опыт в недвижимости<select name="experience"><option>Пока нет опыта</option><option>Есть собственная недвижимость</option><option>Есть инвестиционный опыт</option></select></label><label>Цель участия<textarea name="goal" required placeholder="Чего хотите достичь"></textarea></label><label>Примерный капитал (необязательно)<input name="capital" placeholder="По желанию"></label><label>Главный вопрос<textarea name="question" placeholder="Что хотелось бы обсудить"></textarea></label>':'<label>Email<input name="email" type="email" autocomplete="email" required placeholder="you@example.com"></label>';
 formDialog.showModal();
}));
$$('dialog').forEach(d=>{d.querySelector('.close').addEventListener('click',()=>d.close());d.addEventListener('click',e=>{if(e.target!==d)return;const r=d.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)d.close();});});
$('#leadForm').addEventListener('submit',e=>{e.preventDefault();$('#formResult').textContent='Поля заполнены корректно. Данные не отправлены: приём заявок и оплата пока не открыты.';});

function reviewTab(index,focus=false){
 $$('[data-review]').forEach((b,i)=>{b.setAttribute('aria-selected',String(i===index));b.tabIndex=i===index?0:-1;if(focus&&i===index)b.focus();});
 $('#reviewPanel').setAttribute('aria-labelledby',index===0?'videoTab':'messageTab');
 $('#reviewNote').textContent=index===0?'Видеоистории участников готовятся к публикации.':'Отзывы в сообщениях появятся после получения материалов и согласия участников.';
}
$$('[data-review]').forEach(b=>{b.addEventListener('click',()=>reviewTab(Number(b.dataset.review)));b.addEventListener('keydown',e=>{if(['ArrowLeft','ArrowRight','Home','End'].includes(e.key)){e.preventDefault();reviewTab(e.key==='Home'?0:e.key==='End'?1:1-Number(b.dataset.review),true);}});});

const faqs=[['О программе и формате','Что такое «Старт»?','Базовая образовательная программа из шести видеоуроков: диагностика, виды недвижимости, поиск объектов, документы и инструменты инвестирования.'],['О выборе тарифа','Чем «Магия» отличается от «Старта»?','«Магия» включает 15 видеоуроков, практику, дневник инвестора, подбор индивидуальной стратегии и четыре стратегические сессии.'],['О деньгах и капитале','Нужен ли капитал, чтобы начать обучение?','Программа начинается с диагностики ваших текущих возможностей. Требования к капиталу для участия в конкретном формате уточняются на консультации.'],['О времени и доступе','Где проходит «Старт» и сколько действует доступ?','Обучение проходит в Telegram. Доступ к «Старту» предоставляется на три месяца.'],['О листе ожидания','Когда следующий поток «Магии»?','Дата пока не определена. Она будет сообщена при консультации.'],['О стоимости и оплате','Сколько стоит «Старт»?','Указанная текущая цена — 4 990 ₽. Следующая планируемая цена — 9 990 ₽; дата изменения должна быть подтверждена.'],['О стоимости и оплате','Сколько стоит «Магия»?','Стоимость фиксируется для участников соответствующего потока и сообщается лично на консультации.'],['О программе и формате','Какие темы входят в «Старт»?','Диагностика, виды недвижимости, составляющие успешной сделки, поиск и оценка объектов, документы и заёмные средства.'],['О программе и формате','Будут ли дополнительные материалы?','В «Старте» предусмотрены диагностика, чек-листы и шпаргалки. В «Магии» — также дневник инвестора.'],['О программе и формате','Что такое дневник инвестора?','Материал для расчётов, фиксации критериев, найденных объектов и плана действий в программе «Магия».'],['О программе и формате','Есть ли общение с инвестором?','В составе «Старта» предусмотрен чат с инвестором. Правила и график общения будут уточнены перед оформлением.'],['О выборе тарифа','Какой формат выбрать для первого знакомства?','«Старт» предназначен для тех, кто хочет разобраться в своих возможностях и понять, с чего начать.'],['О выборе тарифа','Кому подойдёт «Магия»?','Тем, кто хочет работать со своей стратегией и делать практические шаги во время обучения.'],['О программе и формате','Как проходят стратегические сессии?','В «Магии» предусмотрены три групповые и одна персональная сессия. Расписание уточняется для потока.'],['О рисках и безопасности','Гарантирует ли обучение доход?','Гарантированный инвестиционный результат не заявляется. Обучение посвящено системному анализу и обоснованным решениям.'],['О рисках и безопасности','Изучаются ли документы?','Подготовка документов и безопасность сделки входят в один из шести основных уроков «Старта».'],['О деньгах и капитале','Рассматриваются ли заёмные средства?','Да, в «Старте» есть урок о заёмных средствах и доступных инструментах инвестирования.'],['О листе ожидания','Что указать в анкете?','Имя, контакт, Telegram, опыт, цель участия и главный вопрос. Примерный капитал — необязательное поле.'],['О времени и доступе','Как получить доступ после покупки?','Обучение планируется в Telegram. Точная инструкция появится после подключения оплаты и системы выдачи доступа.'],['О стоимости и оплате','Можно ли оплатить сейчас?','Приём оплаты пока не открыт. Платёжный сервис, оферта и политика обработки данных ожидают подключения.']];
const faqMarkup = x => `<details class="faq-item"><summary>${x[1]}</summary><p>${x[2]}</p></details>`;
$('#shortFaq').innerHTML=faqs.slice(0,5).map(faqMarkup).join('');
$('#moreFaq').innerHTML=faqs.slice(5).map(faqMarkup).join('');
$('#moreFaqButton').addEventListener('click',()=>{const more=$('#moreFaq');more.hidden=!more.hidden;$('#moreFaqButton').setAttribute('aria-expanded',String(!more.hidden));$('#moreFaqButton').innerHTML=more.hidden?'Все 20 вопросов <span>+</span>':'Свернуть вопросы <span>−</span>';});
