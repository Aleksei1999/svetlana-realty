'use strict';
const byId=id=>document.getElementById(id);
const form=byId('checkoutForm'),notice=byId('notice'),pay=byId('payButton'),check=byId('checkButton');
let csrf='',orderId='',widget=null,checking=false,timer=null,attempts=0;
const storage={get:k=>{try{return sessionStorage.getItem(k)}catch{return null}},set:(k,v)=>{try{sessionStorage.setItem(k,v)}catch{}}};
const hexId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
let requestId=storage.get('realty_request')||hexId();storage.set('realty_request',requestId);
async function api(action,body){
 const r=await fetch('/api/index.php?action='+action,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:body?JSON.stringify(body):undefined});
 const data=await r.json();if(!r.ok)throw new Error(data.error||'Не удалось получить ответ сервера.');return data;
}
function showOrder(row){
 orderId=row.order_id;storage.set('realty_order',orderId);byId('orderLabel').textContent='Заказ № '+orderId;
 check.hidden=false;
 if(row.status==='succeeded'){
  notice.textContent=row.test?'Тестовая оплата подтверждена. Реальные деньги не списывались.':'Оплата подтверждена. Заказ сохранён. Доступ к обучению выдаётся через Telegram-бота.';
  showTelegram(row.telegram);check.hidden=true;form.hidden=true;clearTimeout(timer);if(widget){widget.destroy();widget=null}return true;
 }
 if(row.status==='canceled'){
  notice.textContent='Платёж отменён. Оплата не подтверждена.';form.hidden=true;check.hidden=true;clearTimeout(timer);if(widget){widget.destroy();widget=null}
  byId('retry').hidden=false;byId('retry').onclick=()=>{storage.set('realty_request',hexId());storage.set('realty_order','')};return true;
 }
 notice.textContent=row.test?'Тестовый платёж. Используйте только тестовые данные ЮKassa.':'Ожидаем подтверждение оплаты от ЮKassa.';return false;
}
async function verify(){
 if(!orderId||checking)return;checking=true;check.disabled=true;
 try{const row=await api('status',{order_id:orderId});const done=showOrder(row);if(!done&&attempts++<30)timer=setTimeout(verify,5000)}catch(e){notice.textContent=e.message+' Нажмите «Проверить оплату», чтобы повторить.'}finally{checking=false;check.disabled=false}
}
check.addEventListener('click',()=>{clearTimeout(timer);attempts=0;verify()});
let widgetScript;
function loadWidget(){
 if(window.YooMoneyCheckoutWidget)return Promise.resolve();
 if(!widgetScript)widgetScript=new Promise((resolve,reject)=>{const s=document.createElement('script');s.src='https://yookassa.ru/checkout-widget/v1/checkout-widget.js';s.onload=resolve;s.onerror=()=>{widgetScript=null;s.remove();reject(new Error('Не удалось загрузить форму ЮKassa. Повторите попытку.'))};document.head.append(s)});
 return widgetScript;
}
async function render(row){
 if(showOrder(row))return;
 if(!row.confirmation_token){notice.textContent='Заказ сохранён. Подтверждение оплаты ещё ожидается.';return}
 await loadWidget();if(widget)widget.destroy();
 widget=new window.YooMoneyCheckoutWidget({confirmation_token:row.confirmation_token,error_callback:()=>{notice.textContent='Форма оплаты недоступна. Проверьте статус заказа перед повторной оплатой.';check.hidden=false}});
 widget.on('complete',()=>{widget.destroy();widget=null;attempts=0;verify()});await widget.render('payment-form');form.hidden=true;
}
form.addEventListener('submit',async e=>{
 e.preventDefault();if(pay.disabled)return;pay.disabled=true;notice.textContent='Создаём заказ…';
 const values=Object.fromEntries(new FormData(form));values.consent=form.elements.consent.checked;values.request_id=requestId;
 try{await loadWidget();const row=await api('create',values);await render(row)}catch(e){notice.textContent=e.message}finally{pay.disabled=false}
});
(async()=>{
 try{
  const config=await api('bootstrap');csrf=config.csrf;
  const previous=storage.get('realty_order');
  if(previous){try{const row=await api('status',{order_id:previous});await render(row);return}catch(e){notice.textContent=e.message;return}}
  if(!config.available){notice.textContent='Приём оплаты ещё настраивается. Сейчас оформить покупку нельзя; деньги не списываются.';return}
  const docs=[['offer',config.offer_url],['privacy',config.privacy_url],['consentDoc',config.consent_url]];
  for(const[id,url]of docs){if(url)byId(id).href=url}
  byId('documents').hidden=!docs.every(([,url])=>url);
  if(config.test)byId('consentLabel').textContent='Понимаю, что это тестовая оплата без списания реальных денег.';
  notice.textContent=config.test?'Тестовый режим: реальные деньги не списываются.':'Укажите данные для заказа и получения чека.';
  form.hidden=false;
 }catch(e){notice.textContent=e.message||'Не удалось открыть оплату. Попробуйте позже.'}
})();

let candidateId='';
function showTelegram(data){
 const section=byId('telegramAccess');section.hidden=!data?.available;if(section.hidden)return;
 byId('telegramLink').href=data.bot_url;
 candidateId=data.candidate?.id||'';
 const done=data.state==='invited'||data.state==='joined';
 byId('telegramConfirm').hidden=!candidateId;
 byId('telegramReset').hidden=!candidateId;
 if(data.candidate){
  const who=data.candidate.username?'@'+data.candidate.username:data.candidate.name;
  byId('telegramInfo').textContent='Подтвердите, что это ваш аккаунт: '+who+' (ID '+candidateId+'). Если аккаунт чужой, нажмите «Это не мой аккаунт».';
 } else byId('telegramInfo').textContent=data.state==='joined'?'Доступ выдан. Вы вступили в чат.':done?'Ссылка отправлена в Telegram. Откройте бота и подайте заявку на вступление.':'Откройте бота, нажмите «Начать», затем вернитесь сюда и проверьте Telegram. Приглашение получит только подтверждённый аккаунт.';
}
async function telegramAction(action,button){
 button.disabled=true;
 try{const row=await api(action,{order_id:orderId,candidate_id:candidateId});showOrder(row)}
 catch(e){byId('telegramInfo').textContent=e.message}
 finally{button.disabled=false}
}
byId('telegramRefresh').addEventListener('click',()=>telegramAction('status',byId('telegramRefresh')));
byId('telegramConfirm').addEventListener('click',()=>telegramAction('telegram-confirm',byId('telegramConfirm')));
byId('telegramReset').addEventListener('click',()=>telegramAction('telegram-reset',byId('telegramReset')));
