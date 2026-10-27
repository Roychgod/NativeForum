(function(){
  "use strict";

  function b64ToJson(str){
    try {
      var decoded = atob(str || "");
      return JSON.parse(decoded);
    } catch(e){
      return null;
    }
  }

  function esc(s){
    return (s||"").replace(/[&<>"']/g, function(c){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'})[c] || c;
    });
  }

  function buildHtml(payload){
    var title = payload.title ? "<div style='font-weight:600;margin-bottom:4px'>" + esc(payload.title) + "</div>" : "";
    var desc  = payload.desc ? "<div style='margin-bottom:6px'>" + esc(payload.desc) + "</div>" : "";
    var affects = Array.isArray(payload.affects) ? payload.affects : [];
    var aff = affects.length ? "<div style='opacity:.9'><strong>Affects:</strong><br><code style='font-size:11px'>" + esc(affects.join(', ')) + "</code></div>" : "";
    return title + desc + aff;
  }

  function init(){
    // Design Studio help icons (added in PHP labels)
    document.querySelectorAll('.sf-help-icon[data-sf-tip]').forEach(function(icon){
      if(icon.getAttribute('data-sf-help-inited')) return;
      icon.setAttribute('data-sf-help-inited','1');
      var tip = icon.getAttribute('data-sf-tip') || '';
      icon.setAttribute('title', tip);
      if(typeof window.UIkit !== 'undefined' && window.UIkit.tooltip) {
        try {
          window.UIkit.tooltip(icon, {title: tip, pos: 'right', delay: 50});
        } catch(e) {}
      }
    });

    // Keep active fieldset open after save
    var activeInput = document.querySelector('input[name="sf_active_group"]');
    function setActive(val){ if(activeInput) activeInput.value = val; }
    document.querySelectorAll('#wrap_sf_general, #wrap_sf_ranking, #wrap_sf_frontend, #wrap_sf_designstudio, #wrap_sf_messages, #wrap_sf_permissions, #wrap_sf_vip, #wrap_sf_uploads').forEach(function(w){
      w.addEventListener('focusin', function(){
        var id = w.id || '';
        if(id==='wrap_sf_general') setActive('general');
        if(id==='wrap_sf_ranking') setActive('ranking');
        if(id==='wrap_sf_frontend') setActive('frontend');
        if(id==='wrap_sf_designstudio') setActive('design');
        if(id==='wrap_sf_messages') setActive('messages');
        if(id==='wrap_sf_permissions') setActive('permissions');
        if(id==='wrap_sf_vip') setActive('vip');
        if(id==='wrap_sf_uploads') setActive('uploads');
      });
    });

    // Section switch happens in the browser (all sections are rendered); no reload.
    var selTpl = document.querySelector('select[name="dsUiTpl"]');
    var selSec = document.querySelector('select[name="dsUiSection"]');
    function showSection(){
      if(!selSec) return;
      document.querySelectorAll('.sf-ds-sec').forEach(function(w){
        var on = /sf_ds_sec_(\w+)$/.test(w.id) && w.id.replace(/^.*sf_ds_sec_/, '') === selSec.value;
        w.classList.toggle('sf-ds-sec-hidden', !on);
      });
      setActive('design');
    }
    if(selSec && !selSec.getAttribute('data-sf-bound')) { selSec.setAttribute('data-sf-bound', '1'); selSec.addEventListener('change', showSection); }
    // Template switch needs the values of another template: save + reload, open panels and scroll are restored.
    if(selTpl && !selTpl.getAttribute('data-sf-bound')) {
      selTpl.setAttribute('data-sf-bound', '1');
      selTpl.addEventListener('change', function(){
        setActive('design');
        var f = selTpl.form; if(!f) return;
        saveUiState(f);
        var btn = f.querySelector('#submit_save_module, [name=submit_save_module]');
        if(btn && typeof f.requestSubmit === 'function') f.requestSubmit(btn); else f.submit();
      });
    }
  }

  /* ---------- keep open panels + scroll position across saves/reloads of the settings page ---------- */
  var STATE_KEY = 'nf-cfg-ui:' + location.pathname;
  function panels(){ return Array.prototype.slice.call(document.querySelectorAll('form .InputfieldFieldset[id], form .Inputfield.InputfieldStateCollapsed[id]')); }
  function isCollapsed(el){ return el.classList.contains('InputfieldStateCollapsed'); }
  function saveUiState(form){
    try {
      var open = [], closed = [];
      panels().forEach(function(el){ (isCollapsed(el) ? closed : open).push(el.id); });
      sessionStorage.setItem(STATE_KEY, JSON.stringify({ t: Date.now(), open: open, closed: closed, y: window.scrollY }));
    } catch(e) {}
  }
  function setOpen(el, open){
    if(isCollapsed(el) === !open) return;
    var $ = window.jQuery;
    try {
      if(open && typeof window.InputfieldOpen === 'function' && $) { window.InputfieldOpen($(el), 0); return; }
      if(!open && typeof window.InputfieldClose === 'function' && $) { window.InputfieldClose($(el), 0); return; }
    } catch(e) {}
    el.classList.toggle('InputfieldStateCollapsed', !open);
  }
  function restoreUiState(){
    var st = null;
    try { st = JSON.parse(sessionStorage.getItem(STATE_KEY) || 'null'); sessionStorage.removeItem(STATE_KEY); } catch(e) {}
    if(!st || !st.t || Date.now() - st.t > 5 * 60 * 1000) return;
    (st.open || []).forEach(function(id){ var el = document.getElementById(id); if(el) setOpen(el, true); });
    (st.closed || []).forEach(function(id){ var el = document.getElementById(id); if(el) setOpen(el, false); });
    if(typeof st.y === 'number') [60, 400, 900].forEach(function(ms){ setTimeout(function(){ window.scrollTo(0, st.y); }, ms); });
  }
  function bindStateKeeper(){
    var f = document.getElementById('ModuleEditForm') || (document.querySelector('input[name="sf_active_group"]') || {}).form;
    if(!f || f.getAttribute('data-sf-state')) return;
    f.setAttribute('data-sf-state', '1');
    f.addEventListener('submit', function(){ saveUiState(f); }, true);
    // the sticky header "Save" copy submits via script (no submit event): catch the click too
    document.addEventListener('click', function(e){ var b = e.target.closest && e.target.closest('button[name^="submit_save"], input[name^="submit_save"], #Inputfield_submit_save_module_copy'); if(b) saveUiState(f); }, true);
    // run after ProcessWire has initialised its own collapsed states
    if(document.readyState === 'complete') setTimeout(restoreUiState, 50);
    else window.addEventListener('load', function(){ setTimeout(restoreUiState, 50); });
  }

  /* ---------- Design Studio shows the selected preset's values ---------- */
  function norm(v){
    v = String(v == null ? '' : v).trim().toLowerCase().replace(/\s+/g, ' ');
    var m = v.match(/^#([0-9a-f])([0-9a-f])([0-9a-f])$/); if(m) v = '#' + m[1] + m[1] + m[2] + m[2] + m[3] + m[3];
    if(/^#[0-9a-f]{6}ff$/.test(v)) v = v.slice(0, 7);
    return v.replace(/(^|[^\d.])0px\b/g, '$10');
  }
  function presetKeyFromForm(){
    var th = document.querySelector('select[name="frontendTheme"]'), pr = document.querySelector('select[name="designPreset"]');
    var t = th ? th.value : 'simple'; if(t !== 'bootstrap') t = 'simple';
    var p = pr ? pr.value : 'obsidian'; if(p === 'pekarna' || !p) p = 'obsidian';
    return t + '|' + p;
  }
  function markField(inp){
    var w = inp.closest('.Inputfield'); if(!w) return;
    var pv = inp.getAttribute('data-sf-preset') || '';
    var changed = pv !== '' && norm(inp.value) !== norm(pv);
    w.classList.toggle('sf-ds-changed', changed);
    var r = w.querySelector('.sf-ds-reset');
    if(changed && !r){
      r = document.createElement('a'); r.href = '#'; r.className = 'sf-ds-reset'; r.textContent = 'Reset to preset';
      r.title = 'Use the preset value again';
      r.addEventListener('click', function(e){ e.preventDefault(); inp.value = inp.getAttribute('data-sf-preset') || ''; inp.dispatchEvent(new Event('input', {bubbles:true})); inp.dispatchEvent(new Event('change', {bubbles:true})); markField(inp); });
      (w.querySelector('.InputfieldContent') || inp.parentNode).appendChild(r);
    } else if(!changed && r) { r.remove(); }
  }
  function bindPresetSync(){
    var vals = window.sfDsPresetValues; if(!vals) return;
    var fields = Array.prototype.slice.call(document.querySelectorAll('[data-sf-preset]'));
    fields.forEach(function(inp){
      if(inp.getAttribute('data-sf-ds-bound')) return; inp.setAttribute('data-sf-ds-bound', '1');
      markField(inp);
      inp.addEventListener('input', function(){ markField(inp); });
      inp.addEventListener('change', function(){
        if(inp.value.trim() === '' && inp.getAttribute('data-sf-preset')) { inp.value = inp.getAttribute('data-sf-preset'); inp.dispatchEvent(new Event('input', {bubbles:true})); }
        markField(inp);
      });
    });
    var cur = window.sfDsPresetKey || presetKeyFromForm();
    function apply(){
      var next = presetKeyFromForm(); if(next === cur) return;
      var nv = vals[next] || {};
      fields.forEach(function(inp){
        var k = inp.getAttribute('data-sf-ds'), old = inp.getAttribute('data-sf-preset') || '';
        var untouched = inp.value.trim() === '' || (old !== '' && norm(inp.value) === norm(old));
        var nw = nv[k] || '';
        inp.setAttribute('data-sf-preset', nw);
        if(untouched){ inp.value = nw; inp.dispatchEvent(new Event('input', {bubbles:true})); }
        markField(inp);
      });
      // template-level fields that still show the old preset value follow too
      document.querySelectorAll('[data-sf-ds]:not([data-sf-preset])').forEach(function(inp){
        var k = inp.getAttribute('data-sf-ds'), ov = (vals[cur] || {})[k] || '';
        if(inp.value.trim() === '' || (ov !== '' && norm(inp.value) === norm(ov))) { inp.value = nv[k] || ''; inp.dispatchEvent(new Event('input', {bubbles:true})); }
      });
      document.querySelectorAll('.sf-ds-preset-name').forEach(function(b){ var a = next.split('|'); b.textContent = a[1].charAt(0).toUpperCase() + a[1].slice(1) + ' · ' + (a[0] === 'bootstrap' ? 'Bootstrap' : 'Simple') + ' theme'; });
      cur = next;
    }
    ['frontendTheme', 'designPreset'].forEach(function(n){
      var s = document.querySelector('select[name="' + n + '"]');
      if(s && !s.getAttribute('data-sf-ds-sync')) { s.setAttribute('data-sf-ds-sync', '1'); s.addEventListener('change', apply); }
    });
  }

  /* per-template fields: shown only while their "Override" checkbox is on */
  function bindOverrides(){
    document.querySelectorAll('[data-sf-ov]').forEach(function(inp){
      if(inp.getAttribute('data-sf-ov-bound')) return; inp.setAttribute('data-sf-ov-bound', '1');
      var cb = document.querySelector('input[type=checkbox][name="' + inp.getAttribute('data-sf-ov') + '"]');
      var w = inp.closest('.Inputfield'); if(!cb || !w) return;
      function sync(){ w.style.display = cb.checked ? '' : 'none'; }
      cb.addEventListener('change', sync); sync();
    });
  }

  function initAll(){ init(); bindStateKeeper(); bindPresetSync(); bindOverrides(); }

  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll);
  else initAll();
})();

/* Read the admin page background so native <option> lists match light/dark admin themes */
(function(){
  function bg(e){ while(e){ var c = getComputedStyle(e).backgroundColor; if(c && c !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(c)) return c; e = e.parentElement; } return ''; }
  function set(){
    var c = bg(document.getElementById('pw-content-body') || document.querySelector('#pw-content,main') || document.body); if(!c) return;
    var m = c.match(/[\d.]+/g) || [255,255,255], l = (0.299*m[0] + 0.587*m[1] + 0.114*m[2]) / 255, r = document.documentElement.style;
    r.setProperty('--sfa-scheme', l < .5 ? 'dark' : 'light'); r.setProperty('--sfa-opt-bg', c); r.setProperty('--sfa-opt-fg', l < .5 ? '#e9ecef' : '#1f2328');
  }
  if(document.readyState !== 'loading') set(); else document.addEventListener('DOMContentLoaded', set);
})();
