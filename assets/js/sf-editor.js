(function(){
  'use strict';

  function qsAll(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
// ---- Embed-to-token conversion (YouTube/Vimeo) ----
// Some hosts/WAF rules block POST bodies containing <iframe> or CKEditor iframe placeholders.
// We convert embeds into short tokens before submit, then render them back to safe iframes on output.
function decodeEntities(str){
  try {
    var ta = document.createElement('textarea');
    ta.innerHTML = str;
    return ta.value;
  } catch(e) { return str; }
}

function ytIdFromUrl(url){
  url = String(url || '').trim();
  if(!url) return '';
  var m = url.match(/youtube(?:-nocookie)?\.com\/embed\/([A-Za-z0-9_-]{6,})/i);
  if(m && m[1]) return m[1];
  m = url.match(/youtu\.be\/([A-Za-z0-9_-]{6,})/i);
  if(m && m[1]) return m[1];
  m = url.match(/[?&]v=([A-Za-z0-9_-]{6,})/i);
  if(m && m[1]) return m[1];
  return '';
}

function vimeoIdFromUrl(url){
  url = String(url || '').trim();
  if(!url) return '';
  var m = url.match(/player\.vimeo\.com\/video\/(\d{6,})/i);
  if(m && m[1]) return m[1];
  m = url.match(/vimeo\.com\/(\d{6,})/i);
  if(m && m[1]) return m[1];
  return '';
}

function tokenForEmbedUrl(url){
  var y = ytIdFromUrl(url);
  if(y) return '[sfyt:' + y + ']';
  var v = vimeoIdFromUrl(url);
  if(v) return '[sfvimeo:' + v + ']';
  return '';
}

function convertEmbedsToTokens(html){
  html = String(html || '');
  if(!html) return html;

  // 1) Real iframe blocks
  html = html.replace(/<iframe\b[\s\S]*?<\/iframe>/gi, function(block){
    try {
      var m = block.match(/\bsrc\s*=\s*(["'])([^"']+)\1/i);
      var src = m && m[2] ? m[2] : '';
      var tok = tokenForEmbedUrl(src);
      return tok || '';
    } catch(e) { return ''; }
  });

  // 2) Escaped iframe blocks (source mode pasted as text)
  html = html.replace(/&lt;iframe\b[\s\S]*?&lt;\/iframe&gt;/gi, function(block){
    try {
      var decoded = decodeEntities(block);
      var m = decoded.match(/\bsrc\s*=\s*(["'])([^"']+)\1/i);
      var src = m && m[2] ? m[2] : '';
      var tok = tokenForEmbedUrl(src);
      return tok || '';
    } catch(e) { return ''; }
  });

  // 3) CKEditor fake objects: <img ... data-cke-realelement="&lt;iframe ...&gt;">
  html = html.replace(/<img\b[^>]*\bdata-cke-realelement\s*=\s*(["'])([\s\S]*?)\1[^>]*>/gi, function(tag, q, val){
    try {
      var real = decodeEntities(val || '');
      var m = real.match(/\bsrc\s*=\s*(["'])([^"']+)\1/i);
      var src = m && m[2] ? m[2] : '';
      var tok = tokenForEmbedUrl(src);
      return tok || '';
    } catch(e) { return ''; }
  });

  // 4) Remove CKEditor iframe placeholder images (contain cke_iframe)
  html = html.replace(/<img\b[^>]*\bcke_iframe\b[^>]*>/gi, '');

  // 5) Strip any literal iframe tags left behind
  html = html.replace(/<\/?iframe\b[^>]*>/gi, '');

  return html;
}


  // Width declaration helper: matches "width:" but never "max-width:" / "min-width:".
  function styleWidth(style){
    var m = String(style || '').match(/(?:^|;)\s*width\s*:\s*([^;]+)/i);
    return m && m[1] ? String(m[1]).replace(/!important/i, '').trim() : '';
  }

  function initCk4(textarea){
    if(!window.CKEDITOR) return null;
    if(textarea.dataset.sfCkInited) return null;
    textarea.dataset.sfCkInited = '1';
    if(!textarea.id) textarea.id = 'sf-ck-' + Math.random().toString(36).slice(2);

    window.CKEDITOR.disableAutoInline = true;
    try { window.CKEDITOR.config.versionCheck = false; } catch(e) {}

    // Toolbar presets (controlled from NativeForum settings)
    var preset = (window.SF_CK_TOOLBAR || 'standard');
    if(typeof preset === 'string') preset = preset.toLowerCase();

    var toolbars = {
      basic: [
        { name: 'basicstyles', items: ['Bold','Italic','Underline','Strike'] },
        { name: 'links', items: ['Link','Unlink'] },
        { name: 'paragraph', items: ['NumberedList','BulletedList','Blockquote'] },
        { name: 'insert', items: ['Image','Smiley','HorizontalRule'] }
      ],
      standard: [
        { name: 'basicstyles', items: ['Bold','Italic','Underline','Strike'] },
        { name: 'links', items: ['Link','Unlink'] },
        { name: 'paragraph', items: ['NumberedList','BulletedList','Blockquote','JustifyLeft','JustifyCenter','JustifyRight'] },
        { name: 'insert', items: ['Image','Smiley','HorizontalRule','Table'] },
        { name: 'colors', items: ['TextColor','BGColor'] },
        { name: 'styles', items: ['FontSize','Font','Format','RemoveFormat'] }
      ],
      full: [
        { name: 'document', items: ['Source','-','Maximize'] },
        { name: 'basicstyles', items: ['Bold','Italic','Underline','Strike','Subscript','Superscript'] },
        { name: 'clipboard', items: ['Undo','Redo'] },
        { name: 'links', items: ['Link','Unlink','Anchor'] },
        { name: 'paragraph', items: ['NumberedList','BulletedList','Outdent','Indent','Blockquote','JustifyLeft','JustifyCenter','JustifyRight','JustifyBlock'] },
        { name: 'insert', items: ['Image','Smiley','HorizontalRule','Table','SpecialChar'] },
        { name: 'colors', items: ['TextColor','BGColor'] },
        { name: 'styles', items: ['FontSize','Font','Format','RemoveFormat'] }
      ]
    };

    var cfg = {
      allowedContent: true,
      versionCheck: false,
      extraAllowedContent: '*(*){*}[*];',
      height: 240,
      // image2 is deliberately NOT used: its widget keeps its own size model and dropped
      // sizes made with our handle when a post was edited again. One resizer = one truth.
      removePlugins: 'elementspath,about,versioncheck,exportpdf,image2',
      resize_enabled: true,
      // Native browser handles (Firefox) would compete with ours.
      disableObjectResizing: true,
      toolbarCanCollapse: true,
      toolbar: toolbars[preset] || toolbars.standard
    };

    try {
      var inst = window.CKEDITOR.replace(textarea.id, cfg);
      inst.on('instanceReady', function(){
        try {
          inst.document.appendStyleText(
            'img{max-width:100%;height:auto;}' +
            'img.sf-embed-img,a.sf-img-link img{cursor:default;}' +
            'img.sf-img-active{outline:2px solid #3b82f6;outline-offset:1px;}' +
            'figure.sf-embed-img-wrap,figure.image{display:inline-block;max-width:100%;margin:.25em 0;padding:0;border:0;background:transparent;line-height:0;}' +
            'figure.sf-embed-img-wrap img{display:block;width:100%;height:auto;}' +
            'span.sf-embed-file{display:inline-block;margin:.25em 0;}'
          );
          // Dark site themes (e.g. Obsidian preset): editor area in the forum colours
          var wrap = textarea.closest ? textarea.closest('.sf-wrap') : null;
          if(wrap && window.getComputedStyle) {
            var cs = getComputedStyle(wrap);
            var bg = (cs.getPropertyValue('--sf-editor-bg') || '').trim();
            if(bg) {
              var tx = (cs.getPropertyValue('--sf-editor-text') || '').trim() || '#eee';
              var ln = (cs.getPropertyValue('--sf-editor-link') || '').trim() || tx;
              var bd = (cs.getPropertyValue('--sf-border') || '').trim() || 'rgba(255,255,255,.2)';
              // toolbar icons are inverted only when the editor bar is dark
              var barCol = (cs.getPropertyValue('--sf-editor-bar-bg') || '').trim() || bg;
              var dark = true;
              try {
                var probe = document.createElement('span'); probe.style.color = barCol; probe.style.display = 'none';
                wrap.appendChild(probe);
                var m = (getComputedStyle(probe).color || '').match(/\d+(\.\d+)?/g); probe.parentNode.removeChild(probe);
                if(m && m.length >= 3) dark = (0.299 * m[0] + 0.587 * m[1] + 0.114 * m[2]) < 140;
              } catch(e2) {}
              wrap.classList.add(dark ? 'sf-editor-dark' : 'sf-editor-light');
              inst.document.appendStyleText(
                'html,body{background:' + bg + ';color:' + tx + ';}' +
                'body{font-family:inherit;}a{color:' + ln + ';}' +
                'blockquote{border-left:3px solid ' + bd + ';padding:4px 12px;margin:8px 0;font-style:normal;font-family:inherit;background:' + (dark ? 'rgba(255,255,255,.04)' : 'rgba(0,0,0,.03)') + ';}' +
                'table td,table th{border-color:' + bd + ';}'
              );
            }
          }
        } catch(e) {}
      });
      attachSfImageResize(inst);
      // typing happens inside the editor iframe: tell the anti-spam script the form is in use
      inst.on('focus', function(){ try { if(window.sfWarmForm && textarea.form) window.sfWarmForm(textarea.form); } catch(e) {} });
      // Convert editor placeholders to tokens whenever data is read for a submit
      // (also covers CKEditor's own form-submit updateElement(), which runs after our listeners).
      inst.on('getData', function(evt){
        if(!inst._sfSubmitting) return;
        try { evt.data.dataValue = sfEditorHtmlToTokens(evt.data.dataValue); } catch(e) {}
      }, null, null, 999);
      return inst;
    } catch(e){
      if(window.console && console.warn) console.warn('SF CKEditor4 init failed', e);
      return null;
    }
  }

  // ---- Image resize handle (works in every browser, for new and already-saved images) ----
  function attachSfImageResize(editor){
    if(!editor || !window.CKEDITOR) return;
    var doc = null, win = null, handle = null, badge = null, img = null;
    var drag = null; // {startX, startW, maxW}

    function isImg(el){ return el && el.nodeType === 1 && el.nodeName === 'IMG' && !el.getAttribute('data-cke-realelement') && !/\bcke_/.test(el.className || ''); }

    function build(){
      doc = editor.document ? editor.document.$ : null;
      win = editor.window ? editor.window.$ : null;
      if(!doc || !doc.body) return;
      // handle + size badge live in the editor document but are never part of the data
      handle = doc.createElement('span');
      handle.setAttribute('data-cke-temp', '1');
      handle.setAttribute('contenteditable', 'false');
      handle.className = 'sf-img-resize-handle';
      handle.style.cssText = 'position:fixed;display:none;width:16px;height:16px;margin:0;padding:0;box-sizing:border-box;border:2px solid #fff;border-radius:3px;background:#3b82f6;box-shadow:0 0 0 1px rgba(0,0,0,.35);cursor:nwse-resize;z-index:2147483646;touch-action:none;user-select:none;';
      badge = doc.createElement('span');
      badge.setAttribute('data-cke-temp', '1');
      badge.setAttribute('contenteditable', 'false');
      badge.style.cssText = 'position:fixed;display:none;padding:1px 6px;font:12px/1.5 sans-serif;color:#fff;background:rgba(0,0,0,.75);border-radius:3px;pointer-events:none;z-index:2147483646;';
      doc.documentElement.appendChild(handle);
      doc.documentElement.appendChild(badge);

      handle.addEventListener('pointerdown', startDrag);
      handle.addEventListener('mousedown', function(e){ e.preventDefault(); e.stopPropagation(); });
      doc.addEventListener('mousedown', onDocDown, true);
      doc.addEventListener('keyup', function(){ refresh(); }, true);
      if(win) {
        win.addEventListener('scroll', place, true);
        win.addEventListener('resize', place);
      }
    }

    function select(el){
      if(img && img !== el) { try { img.classList.remove('sf-img-active'); } catch(e) {} }
      img = el;
      if(img) { try { img.classList.add('sf-img-active'); } catch(e) {} }
      place();
    }

    function hide(){
      if(handle) handle.style.display = 'none';
      if(badge) badge.style.display = 'none';
    }

    function place(){
      if(!handle) return;
      if(!img || !img.isConnected || (doc && !doc.body.contains(img))) { img = null; hide(); return; }
      var r = img.getBoundingClientRect();
      var vh = win ? win.innerHeight : 99999, vw = win ? win.innerWidth : 99999;
      if(r.width < 4 || r.height < 4) { hide(); return; }
      // keep the handle reachable even when the image is taller/wider than the editor viewport
      var x = Math.min(r.right, vw - 2) - 14;
      var y = Math.min(r.bottom, vh - 2) - 14;
      if(r.bottom < 0 || r.top > vh) { hide(); return; }
      handle.style.left = Math.max(r.left, x) + 'px';
      handle.style.top = Math.max(r.top, y) + 'px';
      handle.style.display = 'block';
    }

    function refresh(){
      try {
        var sel = editor.getSelection();
        var el = sel && sel.getSelectedElement ? sel.getSelectedElement() : null;
        if(el && isImg(el.$)) { select(el.$); return; }
      } catch(e) {}
      if(img && !drag) place();
    }

    function onDocDown(e){
      if(e.target === handle) return;
      if(isImg(e.target)) {
        select(e.target);
        // make CKEditor select the image too (Chrome does not do that on its own)
        try { editor.getSelection().selectElement(new window.CKEDITOR.dom.element(e.target)); } catch(err) {}
        if(e.target.closest && e.target.closest('a')) e.preventDefault();
        return;
      }
      select(null);
    }

    function wrapperOf(el){
      var p = el.parentNode;
      if(p && p.nodeType === 1 && /^(FIGURE|SPAN)$/.test(p.nodeName) && /\b(sf-img-box|sf-embed-img-wrap|image)\b/.test(p.className || '')) return p;
      return null;
    }

    function applyWidth(px){
      if(!img) return;
      px = Math.max(40, Math.round(px));
      var wrap = wrapperOf(img);
      if(wrap) {
        // legacy wrapper markup: drop it so the image itself carries the size
        try { wrap.parentNode.insertBefore(img, wrap); if(!wrap.textContent.trim() && !wrap.querySelector('img')) wrap.parentNode.removeChild(wrap); } catch(e) {}
      }
      img.removeAttribute('height');
      img.setAttribute('width', String(px));
      img.style.width = px + 'px';
      img.style.maxWidth = '100%';
      img.style.height = 'auto';
      if(img.style.display === 'block' && !wrap) { /* keep */ }
      // keep the upload token in sync (new uploads carry their token in alt)
      var alt = img.getAttribute('alt') || '';
      if(alt.indexOf('[sfimg:') === 0) {
        alt = /\|w=[^\]|]+/.test(alt) ? alt.replace(/\|w=[^\]|]+/, '|w=' + px) : alt.replace(/\]$/, '|w=' + px + ']');
        img.setAttribute('alt', alt);
      }
      if(img.hasAttribute('data-cke-saved-src') === false && img.getAttribute('src')) { /* nothing */ }
      if(badge) {
        var r = img.getBoundingClientRect();
        badge.textContent = Math.round(r.width) + ' × ' + Math.round(r.height);
        badge.style.left = r.left + 4 + 'px';
        badge.style.top = r.top + 4 + 'px';
        badge.style.display = 'block';
      }
      place();
    }

    function startDrag(e){
      if(!img) return;
      e.preventDefault(); e.stopPropagation();
      var r = img.getBoundingClientRect();
      var bodyW = doc.body.clientWidth || r.width;
      drag = { startX: e.clientX, startW: (img.clientWidth || r.width), maxW: Math.max(60, bodyW) };
      try { editor.fire('saveSnapshot'); } catch(err) {}
      try { handle.setPointerCapture(e.pointerId); } catch(err) {}
      handle.addEventListener('pointermove', onMove);
      handle.addEventListener('pointerup', endDrag);
      handle.addEventListener('pointercancel', endDrag);
    }

    function onMove(e){
      if(!drag) return;
      e.preventDefault();
      applyWidth(Math.min(drag.maxW, drag.startW + (e.clientX - drag.startX)));
    }

    function endDrag(e){
      if(!drag) return;
      drag = null;
      try { handle.releasePointerCapture(e.pointerId); } catch(err) {}
      handle.removeEventListener('pointermove', onMove);
      handle.removeEventListener('pointerup', endDrag);
      handle.removeEventListener('pointercancel', endDrag);
      if(badge) setTimeout(function(){ if(!drag) badge.style.display = 'none'; }, 700);
      try { editor.fire('saveSnapshot'); editor.fire('change'); } catch(err) {}
      place();
    }

    // contentDom fires again after setData(), Source mode, undo... -> rebuild every time
    editor.on('contentDom', function(){ img = null; drag = null; build(); });
    editor.on('contentDomUnload', function(){ img = null; drag = null; handle = null; badge = null; });
    editor.on('selectionChange', refresh);
    editor.on('afterCommandExec', function(){ setTimeout(place, 0); });
    editor.on('change', function(){ if(!drag) setTimeout(place, 0); });
    editor.on('blur', function(){ setTimeout(function(){ if(!drag) { select(null); } }, 150); });
    editor.on('resize', function(){ setTimeout(place, 0); });
    editor.on('maximize', function(){ setTimeout(place, 50); });
    // never let our temp markers or the selection outline leak into saved data
    editor.on('toDataFormat', function(evt){
      try {
        var v = evt.data.dataValue;
        if(typeof v === 'string') evt.data.dataValue = v.replace(/\s*sf-img-active/g, '').replace(/ class=""/g, '');
      } catch(err) {}
    }, null, null, 20);
    editor.on('getData', function(evt){
      try { evt.data.dataValue = String(evt.data.dataValue || '').replace(/\s*\bsf-img-active\b/g, '').replace(/ class=""/g, ''); } catch(err) {}
    }, null, null, 1);
  }

  // Convert editor HTML (upload previews, embeds) into the short tokens the server expects.
  function sfEditorHtmlToTokens(html){
    html = String(html || '');
    html = convertEmbedsToTokens(html);
    html = html.replace(/&lt;iframe\b[\s\S]*?&gt;/gi, '').replace(/&lt;\/iframe&gt;/gi, '');
    html = html.replace(/<\/?html[^>]*>/gi, '').replace(/<\/?body[^>]*>/gi, '');
    if(!html) return html;

    var wrap = document.createElement('div');
    wrap.innerHTML = html;
    function replaceNode(node, text){
      try { if(node && node.parentNode) node.parentNode.replaceChild(document.createTextNode(text), node); } catch(e) {}
    }

    // iframes (YouTube / Vimeo)
    qsAll('iframe', wrap).forEach(function(ifr){
      var src = (ifr.getAttribute('src') || '').trim();
      var tok = tokenForEmbedUrl(src);
      replaceNode(ifr, tok || '');
    });

    // anything carrying data-token
    qsAll('[data-token]', wrap).forEach(function(el){
      var tok = el.getAttribute('data-token') || '';
      if(tok) replaceNode(el, tok);
    });

    // uploaded images: token lives in alt, size on the img (or a legacy wrapper)
    qsAll('img[alt]', wrap).forEach(function(img){
      var tok = img.getAttribute('alt') || '';
      if(tok.indexOf('[sfimg:') !== 0) return;
      var fig = img.closest ? img.closest('figure') : null;
      var box = img.closest ? img.closest('span.sf-img-box') : null;
      var anc = img.closest ? img.closest('a.sf-img-link') : null;
      var w = '';
      var mw = tok.match(/\|w=([^\]|]+)\]/i);
      if(mw && mw[1]) w = mw[1];
      if(!w) w = styleWidth(img.getAttribute('style'));
      if(!w) w = img.getAttribute('width') || '';
      if(!w && (fig || box)) w = styleWidth((fig || box).getAttribute('style'));
      w = String(w || '').replace(/px$/i, '').trim();
      if(!/^\d{1,4}$/.test(w) && !/^\d{1,3}%$/.test(w)) w = '';
      if(/^\d{1,3}%$/.test(w) && parseInt(w, 10) >= 100) w = '';
      tok = tok.replace(/\|w=[^\]|]+/i, '');
      if(w) tok = tok.replace(/\]$/, '|w=' + w + ']');
      replaceNode(anc || fig || box || img, tok);
    });

    // file placeholders
    qsAll('a[title]', wrap).forEach(function(a){
      var t = a.getAttribute('title') || '';
      if(t.indexOf('[sffile:') === 0) replaceNode(a, t);
    });
    qsAll('a[href*="#sfTok="]', wrap).forEach(function(a){
      var href = a.getAttribute('href') || '';
      var enc = href.substring(href.indexOf('#sfTok=') + 7);
      var tok = '';
      try { tok = decodeURIComponent(enc); } catch(e) { tok = enc; }
      if(tok) replaceNode(a, tok);
    });

    // never submit blob URLs or orphaned placeholders
    qsAll('img[src^="blob:"], img.sf-embed-img[alt^="[sfimg:"]', wrap).forEach(function(img){ if(img.parentNode) img.parentNode.removeChild(img); });
    qsAll('.sf-img-resize-handle, [data-cke-temp]', wrap).forEach(function(n){ if(n.parentNode) n.parentNode.removeChild(n); });

    // stray fragments of older/broken embed markup
    try {
      var tw = document.createTreeWalker(wrap, NodeFilter.SHOW_TEXT, null, false), tn;
      while((tn = tw.nextNode())) {
        var v = tn.nodeValue || '';
        if(/sf-embed-(img|file)/i.test(v) || /blob:/i.test(v) || /#sfTok=/i.test(v) || /<\/?(html|body)[^>]*>/i.test(v)) tn.nodeValue = '';
      }
    } catch(e) {}

    return wrap.innerHTML;
  }
  window.sfEditorHtmlToTokens = sfEditorHtmlToTokens;

  function editorForTextarea(ta){
    if(!window.CKEDITOR || !ta) return null;
    for(var k in (window.CKEDITOR.instances || {})) {
      var inst = window.CKEDITOR.instances[k];
      if(inst && inst.element && inst.element.$ === ta) return inst;
    }
    return null;
  }

  function initEditors(){
    if(!document.querySelectorAll) return;
    var areas = qsAll('textarea.sf-wysiwyg');
    if(!areas.length) return;
    if(!window.CKEDITOR) return; // script not loaded
    areas.forEach(initCk4);
  }

  // Capture phase: runs before inline onsubmit handlers and before CKEditor's own submit hook,
  // so every getData()/updateElement() during this submit returns token-converted HTML.
  document.addEventListener('submit', function(e){
    var f = e.target;
    if(!f || !f.querySelectorAll || !window.CKEDITOR) return;
    qsAll('textarea.sf-wysiwyg', f).forEach(function(ta){
      var inst = editorForTextarea(ta);
      if(!inst) return;
      inst._sfSubmitting = true;
      try { inst.updateElement(); } catch(err) {}
      setTimeout(function(){ inst._sfSubmitting = false; }, 1500);
    });
  }, true);
  // Bubble phase (after inline handlers): make sure the textarea holds the converted value.
  document.addEventListener('submit', function(e){
    var f = e.target;
    if(!f || !f.querySelectorAll || !window.CKEDITOR || e.defaultPrevented) return;
    qsAll('textarea.sf-wysiwyg', f).forEach(function(ta){
      var inst = editorForTextarea(ta);
      if(!inst) return;
      inst._sfSubmitting = true;
      try { ta.value = sfEditorHtmlToTokens(inst.getData()); } catch(err) {}
    });
  });


  // Load CKEditor lazily: never blocks page load (site preloaders wait for window.onload),
  // and is not downloaded at all on pages without an editor.
  var ckLoading = false, ckWaiters = [];
  function loadCk(cb){
    if(window.CKEDITOR) { cb && cb(); return; }
    if(cb) ckWaiters.push(cb);
    if(ckLoading) return;
    if(!window.SF_CK_SRC) return;
    ckLoading = true;
    var s = document.createElement('script');
    s.src = window.SF_CK_SRC; s.async = true;
    s.onload = function(){ var w = ckWaiters; ckWaiters = []; w.forEach(function(f){ try{ f(); }catch(e){} }); };
    document.head.appendChild(s);
  }
  window.sfLoadEditor = loadCk;

  function boot(){
    if(!qsAll('textarea.sf-wysiwyg').length) return;
    if(window.CKEDITOR) { initEditors(); return; }
    var go = function(){ loadCk(initEditors); };
    // the visitor wants to write now
    qsAll('textarea.sf-wysiwyg').forEach(function(ta){
      ta.addEventListener('focus', go, { once: true });
      ta.addEventListener('pointerdown', go, { once: true });
    });
    // otherwise right after the page has loaded (when the browser is idle)
    var later = function(){ (window.requestIdleCallback || function(f){ setTimeout(f, 200); })(go, { timeout: 1500 }); };
    if(document.readyState === 'complete') later(); else window.addEventListener('load', later, { once: true });
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  // Expose a tiny helper for quote insertion.
  window.__sfInsertIntoEditor = function(textareaId, html){
    try {
      if(window.CKEDITOR && window.CKEDITOR.instances && window.CKEDITOR.instances[textareaId] && window.CKEDITOR.instances[textareaId].status === 'ready') {
        window.CKEDITOR.instances[textareaId].focus();
        window.CKEDITOR.instances[textareaId].insertHtml(html);
        return true;
      }
      // editor not loaded/created yet: load it now and insert when ready
      var ta = document.getElementById(textareaId);
      if(ta && ta.classList.contains('sf-wysiwyg') && window.SF_CK_SRC) {
        loadCk(function(){
          initEditors();
          var inst = window.CKEDITOR.instances[textareaId];
          if(!inst) return;
          var put = function(){ try { inst.focus(); inst.insertHtml(html); } catch(e){} };
          if(inst.status === 'ready') put();
          else inst.on('instanceReady', function(ev){ ev.removeListener(); setTimeout(put, 0); });
        });
        return true;
      }
    } catch(e){}
    return false;
  };
})();
