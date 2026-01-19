/*! TWTGR Reviews Tracker – tracker.js */
(function () {
  // Compara URLs ignorando query/hash/barras finais
  function sameUrl(a, b) {
    function norm(u){ u=String(u||''); u=u.replace(/[#?].*$/,'').replace(/\/+$/,''); return u; }
    var A=norm(a), B=norm(b);
    return A===B || A.indexOf(B)===0 || B.indexOf(A)===0;
  }

  // Marca <a> com base nas URLs vindas do PHP (com post_id)
  function markMultiAnchors() {
    if (!window.TWTGR || !Array.isArray(TWTGR.multi) || !TWTGR.multi.length) return;
    var items = TWTGR.multi.filter(function(o){ return o && o.url; });

    var anchors = document.querySelectorAll('a[href]');
    outer: for (var i=0;i<anchors.length;i++){
      var a   = anchors[i];
      var href= (a.getAttribute('href')||'').trim();
      if (!href) continue;
      for (var u=0; u<items.length; u++){
        var it = items[u];
        if (sameUrl(href, it.url)) {
          a.setAttribute('data-twtgr','1');
          a.setAttribute('data-ask-user','yes');              // força formulário
          a.setAttribute('data-review-url', href);            // usar este href no log
          if (it.post_id) a.setAttribute('data-post-id', String(it.post_id));
          continue outer;
        }
      }
    }
  }

  // CSS.escape mínimo
  var cssEscape=(function(){ if(window.CSS&&typeof window.CSS.escape==='function')return window.CSS.escape; return function(s){return String(s).replace(/["'\\]/g,'\\$&');}; })();

  function getPostIdFrom(el){ var v=parseInt(el.getAttribute('data-post-id')||0,10); if(v) return v; if(window.TWTGR&&TWTGR.auto&&TWTGR.auto.post_id) return parseInt(TWTGR.auto.post_id,10)||0; return 0; }
  function getHrefFrom(el){ var href=el.getAttribute('data-review-url')||el.getAttribute('href')||''; if(!href&&window.TWTGR&&TWTGR.auto&&TWTGR.auto.review_url) href=TWTGR.auto.review_url; return href; }
  function askGoogleUserIfNeeded(el){ var ask=(el.getAttribute('data-ask-user')||'no').toLowerCase()==='yes'; if(!ask) return ''; var v=window.prompt('Qual é o teu nome de utilizador Google? (opcional)')||''; return v.trim(); }

  function buildPayload(el, googleUser){ var pid=getPostIdFrom(el); var p={post_id:pid}; if(googleUser) p.google_user=googleUser; return p; }

  function logClick(el, googleUser){
    var postId=getPostIdFrom(el), href=getHrefFrom(el);
    if(!postId||!href) return Promise.resolve();
    var base=(TWTGR&&TWTGR.rest&&TWTGR.rest.base)?String(TWTGR.rest.base):''; if(!base) return Promise.resolve();
    var url=base.replace(/\/+$/,'')+'/click', payload=buildPayload(el,googleUser);
    try{
      return fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':TWTGR.rest.nonce},credentials:'same-origin',keepalive:true,body:JSON.stringify(payload)}).catch(function(){});
    }catch(e){
      try{ var i=new Image(); i.src=url+'?post_id='+encodeURIComponent(payload.post_id)+(payload.google_user?('&google_user='+encodeURIComponent(payload.google_user)):'')+'&_='+Date.now(); }catch(e2){}
      return Promise.resolve();
    }
  }

  // Se é para pedir nome, intercepta mesmo com target=_blank
  function shouldOpenNew(e,a){
    if((a.getAttribute('data-ask-user')||'').toLowerCase()==='yes') return false;
    if(e&&(e.metaKey||e.ctrlKey||e.shiftKey||e.altKey||e.button===1)) return true;
    return (a.getAttribute('target')||'').toLowerCase()==='_blank';
  }
  function continueNav(e,a){ if(shouldOpenNew(e,a)) return; var href=getHrefFrom(a); if(!href) return; try{window.location.assign(href);}catch(err){window.location.href=href;} }

  function onClick(e){
    var a=e.currentTarget;
    if(!shouldOpenNew(e,a)) e.preventDefault();
    var gu=askGoogleUserIfNeeded(a);
    if(a._twtgrBusy){ continueNav(e,a); return; }
    a._twtgrBusy=true;
    var raced=false, navTimer=setTimeout(function(){ if(!raced){raced=true; continueNav(e,a);} },120);
    Promise.resolve().then(function(){ return logClick(a,gu); }).then(function(){ if(!raced){ raced=true; clearTimeout(navTimer); continueNav(e,a); } }).finally(function(){ setTimeout(function(){ a._twtgrBusy=false; },250); });
  }

  function collectTargets(){
    var list=[], marked=document.querySelectorAll('a[data-twtgr="1"], a.twtgr-link');
    for(var i=0;i<marked.length;i++) list.push(marked[i]);

    if(window.TWTGR&&TWTGR.auto&&TWTGR.auto.review_url){
      try{
        var sel='a[href="'+cssEscape(TWTGR.auto.review_url)+'"]', autos=document.querySelectorAll(sel);
        for(var j=0;j<autos.length;j++){ var a=autos[j]; if(!a.classList.contains('twtgr-link')) a.classList.add('twtgr-link'); if(!a.hasAttribute('data-twtgr')) a.setAttribute('data-twtgr','1'); if(!a.hasAttribute('data-ask-user')) a.setAttribute('data-ask-user','no'); list.push(a); }
      }catch(e){}
    }
    var out=[], seen=new WeakSet(); for(var k=0;k<list.length;k++){ var el=list[k]; if(!el||seen.has(el)) continue; seen.add(el); out.push(el); } return out;
  }

  function bindAll(){ markMultiAnchors(); var anchors=collectTargets(); for(var i=0;i<anchors.length;i++){ var a=anchors[i]; if(!a._twtgrBound){ a.addEventListener('click', onClick, {passive:false}); a._twtgrBound=true; } } }
  function isReady(){ return !!(window.TWTGR&&TWTGR.rest&&TWTGR.rest.base&&TWTGR.rest.nonce); }
  function tryBindUntilReady(maxMs,stepMs){ if(isReady()){ bindAll(); return; } var elapsed=0, t=setInterval(function(){ elapsed+=stepMs; if(isReady()){ clearInterval(t); bindAll(); } else if(elapsed>=maxMs){ clearInterval(t); } }, stepMs); }

  if(document.readyState==='complete'||document.readyState==='interactive') tryBindUntilReady(3000,150);
  else document.addEventListener('DOMContentLoaded', function(){ tryBindUntilReady(3000,150); });

  if(typeof MutationObserver!=='undefined'){ var mo=new MutationObserver(function(){ if(isReady()) bindAll(); }); mo.observe(document.documentElement,{childList:true,subtree:true}); }
})();
