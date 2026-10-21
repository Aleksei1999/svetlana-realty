'use strict';
(() => {
    // Count the production website, not local previews or the GitHub design copy.
    if (!['xn--80ajklwp.site', 'www.xn--80ajklwp.site'].includes(location.hostname)) return;
    if (window.realtyMetrika113667245) return;
    window.realtyMetrika113667245 = true;

    // Keep application details and Telegram account confirmation out of Session Replay.
    document.querySelectorAll('form, #formDialog, #telegramAccess').forEach(el => el.classList.add('ym-hide-content'));
    document.querySelectorAll('input, textarea').forEach(el => el.classList.add('ym-disable-keys'));
    window.dataLayer = window.dataLayer || [];
    (function(m,e,t,r,i,k,a){
        m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
        m[i].l=1*new Date();
        for (var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r){return;}}
        k=e.createElement(t);a=e.getElementsByTagName(t)[0];k.async=1;k.src=r;a.parentNode.insertBefore(k,a);
    })(window,document,'script','https://mc.yandex.ru/metrika/tag.js?id=113667245','ym');
    window.ym(113667245,'init',{ssr:true,webvisor:true,clickmap:true,ecommerce:'dataLayer',referrer:document.referrer,url:location.href,accurateTrackBounce:true,trackLinks:true});
})();
