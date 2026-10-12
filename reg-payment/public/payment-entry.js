'use strict';
document.addEventListener('click',event=>{
 const button=event.target instanceof Element?event.target.closest('[data-form="start"]'):null;
 if(!button)return;
 event.preventDefault();event.stopImmediatePropagation();
 location.assign('https://www.xn--80ajklwp.site/checkout/');
},true);
