(function(){
  'use strict';

  function findCsrfPair(root){
    root = root || document;
    // ProcessWire CSRF inputs usually have a random name starting with 'TOKEN'
    var inputs = root.querySelectorAll('input[type="hidden"]');
    for(var i=0;i<inputs.length;i++){
      var n = inputs[i].name || '';
      if(n.indexOf('TOKEN') === 0 && inputs[i].value){
        return {name:n, value:inputs[i].value};
      }
    }
    return null;
  }

  function post(url, data){
    var body = new URLSearchParams();
    Object.keys(data).forEach(function(k){ body.append(k, data[k]); });
    return fetch(url, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString(),
      credentials:'same-origin'
    });
  }

  function boot(){
    var toggle = document.getElementById('sf-follow-toggle');
    if(!toggle) return;
    var url = toggle.getAttribute('data-url') || '';
    if(!url) return;

    var form = toggle.closest('form') || document;
    var csrf = findCsrfPair(form) || findCsrfPair(document);

    toggle.addEventListener('change', function(){
      var data = {sf_follow_toggle: '1', follow: toggle.checked ? '1' : '0'};
      if(csrf) data[csrf.name] = csrf.value;

      post(url, data)
        .then(function(r){ return r.text(); })
        .then(function(){
          // optional UI hook
          toggle.closest('.sf-follow-wrap')?.classList.toggle('is-following', toggle.checked);
        })
        .catch(function(err){
          console.warn('SF follow toggle failed', err);
        });
    });
  }

  if(document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
