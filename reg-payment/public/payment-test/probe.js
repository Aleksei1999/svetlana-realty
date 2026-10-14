'use strict';
const $=id=>document.getElementById(id);
let csrf='',available=false,orderId='',widget=null,widgetToken='',timer=null,checking=false,attempts=0;
const storage={get:k=>{try{return sessionStorage.getItem(k)}catch{return null}},set:(k,v)=>{try{sessionStorage.setItem(k,v)}catch{}}};
const hex=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
let requestId=storage.get('realty_probe_request')||hex();storage.set('realty_probe_request',requestId);
async function api(action,body){
 const r=await fetch('/admin/api.php?action='+action,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:body?JSON.stringify(body):undefined});
 const d=await r.json();if(!r.ok){if(r.status===401){available=false;$('fields').disabled=true;$('payButton').disabled=true;$('loginPrompt').hidden=false}throw new Error(d.error||'Не удалось получить ответ сервера.')}return d;
}
function destroyWidget(){if(widget)widget.destroy();widget=null;widgetToken=''}
function show(row){
 orderId=row.order_id;storage.set('realty_probe_order',orderId);$('orderLabel').textContent='Заказ № '+orderId;
 $('probeForm').hidden=true;$('refresh').hidden=true;$('checkButton').hidden=false;$('resumeButton').hidden=true;
 const terminal=['succeeded','canceled'].includes(row.status);$('newButton').hidden=!terminal;
 if(terminal){destroyWidget();clearTimeout(timer)}
 if(row.status==='succeeded'){
  $('notice').textContent='Оплата 10 ₽ подтверждена ЮKassa. Проверочный заказ сохранён в кабинете. Доступ к курсу не выдаётся.';
  const receipts={succeeded:'Чек: ЮKassa подтвердила регистрацию. Проверьте почту, указанную в форме.',pending:'Чек: регистрация ещё выполняется. Обновите статус немного позже.',canceled:'Чек: регистрация отменена. Требуется проверка в кабинете ЮKassa.'};
  $('receipt').textContent=receipts[row.receipt_status]||'Чек: подтверждения регистрации пока нет. Нажмите «Проверить оплату и чек» позже.';$('receipt').hidden=false;
 }else if(row.status==='canceled'){$('notice').textContent='Платёж отменён. Оплата не подтверждена.';$('checkButton').hidden=true}
 else{$('notice').textContent=row.status==='creating'?'Заказ создан, ответ ЮKassa ещё не получен. Не создавайте другой заказ.':'Ожидаем подтверждения оплаты от ЮKassa.'}
 return terminal;
}
let scriptPromise;
function loadWidget(){if(window.YooMoneyCheckoutWidget)return Promise.resolve();if(!scriptPromise)scriptPromise=new Promise((resolve,reject)=>{const s=document.createElement('script');s.src='https://yookassa.ru/checkout-widget/v1/checkout-widget.js';s.onload=resolve;s.onerror=()=>{scriptPromise=null;s.remove();reject(new Error('Не удалось загрузить форму ЮKassa. Нажмите «Открыть форму оплаты».'))};document.head.append(s)});return scriptPromise}
async function render(row){
 if(show(row)||!row.confirmation_token)return;
 if(widget&&widgetToken===row.confirmation_token)return;
 $('resumeButton').hidden=false;await loadWidget();destroyWidget();widgetToken=row.confirmation_token;
 widget=new window.YooMoneyCheckoutWidget({confirmation_token:row.confirmation_token,error_callback:()=>{$('notice').textContent='Форма ЮKassa недоступна. Проверьте статус перед повторной оплатой.';$('resumeButton').hidden=false}});
 widget.on('complete',()=>{destroyWidget();attempts=0;verify()});await widget.render('payment-form');$('resumeButton').hidden=true;
}
async function verify(){if(!orderId||checking)return;checking=true;$('checkButton').disabled=true;try{const row=await api('probe-status',{order_id:orderId});const done=show(row);if(!done&&attempts++<24)timer=setTimeout(verify,5000)}catch(e){$('notice').textContent=e.message}finally{checking=false;$('checkButton').disabled=false}}
async function boot(){
 clearTimeout(timer);$('refresh').disabled=true;$('fields').disabled=true;$('payButton').disabled=true;$('loginPrompt').hidden=true;
 try{const s=await api('session');csrf=s.csrf;if(!s.authenticated){$('loginPrompt').hidden=false;$('notice').textContent='Проверка доступна администратору. Войдите в кабинет. До подтверждения настроек чека оплата закрыта.';return}
  const c=await api('probe-bootstrap',{});available=c.available;$('notice').textContent=c.message;
  const previous=storage.get('realty_probe_order');if(previous){await render(await api('probe-status',{order_id:previous}));return}
  $('fields').disabled=!available;$('payButton').disabled=!available;
 }catch(e){$('notice').textContent=e.message}finally{$('refresh').disabled=false}
}
$('probeForm').onsubmit=async e=>{e.preventDefault();if(!available||$('payButton').disabled)return;$('payButton').disabled=true;const input=Object.fromEntries(new FormData(e.target));input.consent=e.target.elements.consent.checked;input.real_payment_ack=e.target.elements.real_payment_ack.checked;input.request_id=requestId;
 try{const row=await api('probe-create',input);await render(row)}catch(e){$('notice').textContent=e.message}finally{$('payButton').disabled=!available}};
$('checkButton').onclick=()=>{clearTimeout(timer);attempts=0;verify()};
$('resumeButton').onclick=async()=>{try{await render(await api('probe-status',{order_id:orderId}))}catch(e){$('notice').textContent=e.message}};
$('newButton').onclick=()=>{destroyWidget();clearTimeout(timer);storage.set('realty_probe_order','');storage.set('realty_probe_request',hex());location.reload()};
$('refresh').onclick=boot;
boot();
