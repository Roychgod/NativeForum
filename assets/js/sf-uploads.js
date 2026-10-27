(function() {
  function qs(el, sel){ return el.querySelector(sel); }
  function qsa(el, sel){ return Array.prototype.slice.call(el.querySelectorAll(sel)); }

  function escapeHtml(s){
    return String(s||'')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/\"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }

  function isEditable(el){
    return !!(el && el.isContentEditable);
  }

  // --- Contenteditable composer ---
  function insertNodeAtCaret(editor, node) {
    editor.focus();
    var sel = window.getSelection();
    if(!sel || !sel.rangeCount) {
      editor.appendChild(node);
      editor.appendChild(document.createTextNode(' '));
      return;
    }
    var range = sel.getRangeAt(0);
    // If selection isn't inside editor, append.
    var container = range.commonAncestorContainer;
    if(container && container.nodeType === 3) container = container.parentNode;
    if(container && editor !== container && !editor.contains(container)) {
      editor.appendChild(node);
      editor.appendChild(document.createTextNode(' '));
      return;
    }
    range.deleteContents();
    range.insertNode(node);
    // add a trailing space and move caret after it
    var space = document.createTextNode(' ');
    node.after(space);
    range.setStartAfter(space);
    range.setEndAfter(space);
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function makeEmbed(fileObj, previewInfo) {
    var token = String(fileObj.insert || '').trim();
    var wrap = document.createElement('span');
    wrap.className = 'sf-embed ' + (fileObj.kind === 'image' ? 'sf-embed-img' : 'sf-embed-file');
    wrap.setAttribute('contenteditable', 'false');
    wrap.dataset.token = token;
    wrap.dataset.key = fileObj.key || '';
    wrap.title = 'Click and press Backspace/Delete to remove.';

    // Small remove button
    var rm = document.createElement('button');
    rm.type = 'button';
    rm.className = 'sf-embed-rm';
    rm.textContent = '×';
    rm.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      wrap.remove();
    });

    if(fileObj.kind === 'image') {
      var img = document.createElement('img');
      img.className = 'sf-embed-thumb';
      var url = previewInfo && previewInfo.url ? previewInfo.url : '';
      if(url) img.src = url;
      else {
        // fallback placeholder
        img.alt = 'image';
      }
      wrap.appendChild(img);
      wrap.appendChild(rm);
      return wrap;
    }

    // file
    var name = (fileObj.name || (previewInfo && previewInfo.name) || 'Download');
    var btn = document.createElement('span');
    btn.className = 'sf-file-link';
    btn.textContent = name;
    wrap.appendChild(btn);
    wrap.appendChild(rm);
    return wrap;
  }

  function serializeEditor(editor) {
    function walk(node, out) {
      if(!node) return;
      if(node.nodeType === 3) { // text
        out.push(node.nodeValue || '');
        return;
      }
      if(node.nodeType !== 1) return;

      var tag = node.tagName;

      if(node.classList && node.classList.contains('sf-embed')) {
        out.push(node.dataset.token || '');
        return;
      }
      if(tag === 'BR') {
        out.push('\n');
        return;
      }

      var isBlock = (tag === 'DIV' || tag === 'P' || tag === 'LI');
      if(isBlock) {
        // start new line if not at beginning and last isn't newline
        if(out.length && out[out.length-1] !== '\n') out.push('\n');
      }

      Array.prototype.slice.call(node.childNodes || []).forEach(function(ch){ walk(ch, out); });

      if(isBlock) {
        if(out.length && out[out.length-1] !== '\n') out.push('\n');
      }
    }

    var out = [];
    Array.prototype.slice.call(editor.childNodes || []).forEach(function(ch){ walk(ch, out); });

    var s = out.join('');
    // normalize
    s = s.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    s = s.replace(/\n{3,}/g, '\n\n');
    return s.trim();
  }

  function hydrateEditor(editor, text) {
    editor.innerHTML = '';
    var t = String(text || '');
    if(!t) return;

    // We cannot preview old uploads here (no local blob), but we can keep inline placeholders.
    // Convert tokens to non-editable chips so users don't see raw codes.
    var parts = [];
    var re = /(\[sfimg:[a-z0-9_-]+\])|(\[sffile:[a-z0-9_-]+(\|[^\]]+)?\])/ig;
    var last = 0;
    var m;
    while((m = re.exec(t)) !== null) {
      if(m.index > last) parts.push({type:'text', v: t.slice(last, m.index)});
      parts.push({type:'token', v: m[0]});
      last = m.index + m[0].length;
    }
    if(last < t.length) parts.push({type:'text', v: t.slice(last)});

    parts.forEach(function(p){
      if(p.type === 'text') {
        // Preserve newlines
        var lines = String(p.v).split(/\n/);
        lines.forEach(function(line, idx){
          editor.appendChild(document.createTextNode(line));
          if(idx < lines.length - 1) editor.appendChild(document.createElement('br'));
        });
        return;
      }

      var token = p.v;
      var span = document.createElement('span');
      span.className = 'sf-embed sf-embed-token';
      span.setAttribute('contenteditable','false');
      span.dataset.token = token;
      span.textContent = token.indexOf('[sfimg:') === 0 ? '🖼 Image' : '⬇ File';
      span.title = token;
      editor.appendChild(span);
      editor.appendChild(document.createTextNode(' '));
    });
  }

  function setupComposerForEditor(editor) {
    if(!editor || !editor.getAttribute) return;
    var inputId = editor.getAttribute('data-input');
    if(!inputId) return;
    var ta = document.getElementById(inputId);
    if(!ta) return;

    // hydrate once
    if(ta.value) hydrateEditor(editor, ta.value);

    // keep textarea up-to-date on submit
    var form = editor.closest('form');
    if(form && !form.__sfComposerBound) {
      form.__sfComposerBound = true;
      form.addEventListener('submit', function(){
        // serialize any composers in this form
        qsa(form, '.sf-editor[data-input]').forEach(function(ed){
          var id = ed.getAttribute('data-input');
          var t = document.getElementById(id);
          if(t) t.value = serializeEditor(ed);
        });
      });
    }

    // Basic UX: paste as plain text
    editor.addEventListener('paste', function(e){
      try {
        e.preventDefault();
        var txt = (e.clipboardData || window.clipboardData).getData('text');
        document.execCommand('insertText', false, txt);
      } catch(err) {}
    });

    // Prevent dropping raw files into the editor (browser may insert base64/HTML)
    ['dragover','drop'].forEach(function(ev){
      editor.addEventListener(ev, function(e){
        try {
          if(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            e.preventDefault();
          }
        } catch(err) {}
      });
    });
  }

  // --- Upload widget ---
  function buildBaseFormData(widget) {
    var fd = new FormData();
    qsa(widget, "input[type='hidden']").forEach(function(inp){
      if(!inp.name) return;
      fd.append(inp.name, inp.value);
    });
    // Ensure flag exists (upload endpoint)
    if(!fd.get('sf_upload_attachment')) fd.append('sf_upload_attachment', '1');
    return fd;
  }

  function setStatus(widget, msg, isErr) {
    var st = qs(widget, '.sf-upload-status');
    if(!st) {
      st = document.createElement('div');
      st.className = 'sf-upload-status sf-muted-sm mt-1';
      widget.appendChild(st);
    }
    st.textContent = msg || '';
    st.classList.toggle('is-error', !!isErr);
  }

  // Big photos are scaled down in the browser before upload (faster, same result on the forum)
  function maybeShrink(file){
    return new Promise(function(resolve){
      try {
        if(!file || !/^image\/(jpeg|webp)$/i.test(file.type || '') || file.size < 900 * 1024 || !window.createImageBitmap || !document.createElement('canvas').toBlob) return resolve(file);
        createImageBitmap(file).then(function(bmp){
          var max = 2000, w = bmp.width, h = bmp.height;
          if(Math.max(w, h) <= max && file.size < 2.5 * 1048576) { try{ bmp.close(); }catch(e){} return resolve(file); }
          var r = Math.min(1, max / Math.max(w, h));
          var c = document.createElement('canvas');
          c.width = Math.round(w * r); c.height = Math.round(h * r);
          c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
          try{ bmp.close(); }catch(e){}
          c.toBlob(function(blob){
            if(!blob || blob.size >= file.size) return resolve(file);
            try { resolve(new File([blob], file.name.replace(/\.(webp)$/i, '.jpg'), {type: 'image/jpeg', lastModified: Date.now()})); }
            catch(e) { blob.name = file.name; resolve(blob); }
          }, 'image/jpeg', 0.86);
        }).catch(function(){ resolve(file); });
      } catch(e) { resolve(file); }
    });
  }

  function uploadOne(widget, file, kind, row) {
    return new Promise(function(resolve){
      var url = widget.getAttribute('data-upload-url') || window.location.href;
      var fd = buildBaseFormData(widget);
      fd.append('sf_upload_kind', kind || 'auto');
      fd.append('sf_upload_file', file, file.name || 'upload');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('Accept', 'application/json');
      if(xhr.upload) xhr.upload.onprogress = function(e){ if(e.lengthComputable) setRowProgress(row, e.loaded / e.total * 100); };
      xhr.onload = function(){
        var json = null;
        try { json = JSON.parse(xhr.responseText); } catch(e) { json = null; }
        if(xhr.status >= 200 && xhr.status < 300 && json && json.ok) return resolve(json.files || []);
        var msg = (json && json.error) ? json.error : ('Upload failed (HTTP ' + xhr.status + ').');
        setRowError(row, msg);
        resolve(null);
      };
      xhr.onerror = function(){ setRowError(row, 'Network error — please try again.'); resolve(null); };
      xhr.send(fd);
    });
  }

  async function deleteOne(widget, key) {
    var url = widget.getAttribute('data-upload-url') || window.location.href;
    var fd = buildBaseFormData(widget);
    fd.append('sf_upload_delete', '1');
    fd.append('sf_upload_key', key);

    var res = await fetch(url, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      credentials: 'same-origin'
    });
    var json = null;
    try { json = JSON.parse(await res.text()); } catch(e) { json = null; }
    return !!(json && json.ok);
  }

  function fmtSize(b){
    b = +b || 0;
    if(b < 1024) return b + ' B';
    if(b < 1048576) return Math.round(b / 1024) + ' KB';
    return (b / 1048576).toFixed(1) + ' MB';
  }

  // Row shown immediately (preview + progress) while the file uploads
  function makePendingRow(widget, file){
    var list = qs(widget, '.sf-upload-list');
    if(!list) return null;
    var row = document.createElement('div');
    row.className = 'sf-upload-row is-uploading';
    var isImg = file && file.type && file.type.indexOf('image/') === 0;
    var thumb = document.createElement('div');
    thumb.className = 'sf-upload-thumb' + (isImg ? '' : ' is-file');
    if(isImg) {
      try { var u = URL.createObjectURL(file); thumb.style.backgroundImage = 'url(' + u + ')'; row.__blob = u; } catch(e) {}
    } else {
      var ext = String((file && file.name || '').split('.').pop() || 'file').slice(0, 4).toUpperCase();
      thumb.textContent = ext;
    }
    var info = document.createElement('div');
    info.className = 'sf-upload-info';
    var nm = document.createElement('div');
    nm.className = 'sf-upload-name';
    nm.textContent = (file && file.name) || 'file';
    var meta = document.createElement('div');
    meta.className = 'sf-upload-meta';
    meta.innerHTML = '<span class="sf-upload-size">' + fmtSize(file && file.size) + '</span><span class="sf-upload-state">Uploading<span class="sf-dots"><i>.</i><i>.</i><i>.</i></span></span>';
    var bar = document.createElement('div');
    bar.className = 'sf-upload-bar';
    bar.innerHTML = '<span></span>';
    info.appendChild(nm); info.appendChild(meta); info.appendChild(bar);
    row.appendChild(thumb); row.appendChild(info);
    list.appendChild(row);
    return row;
  }

  function setRowProgress(row, pct){
    if(!row) return;
    var b = row.querySelector('.sf-upload-bar > span');
    if(b) b.style.width = Math.max(3, Math.min(100, pct)) + '%';
    var st = row.querySelector('.sf-upload-state');
    if(st && pct >= 100) st.innerHTML = 'Processing<span class="sf-dots"><i>.</i><i>.</i><i>.</i></span>';
    else if(st) st.innerHTML = 'Uploading ' + Math.round(pct) + '%<span class="sf-dots"><i>.</i><i>.</i><i>.</i></span>';
  }

  function setRowError(row, msg){
    if(!row) return;
    row.classList.remove('is-uploading');
    row.classList.add('is-error');
    var st = row.querySelector('.sf-upload-state');
    if(st) st.textContent = msg || 'Upload failed';
    var x = document.createElement('button');
    x.type = 'button'; x.className = 'sf-upload-x'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';
    x.addEventListener('click', function(){ row.remove(); });
    row.appendChild(x);
  }

  function addRow(widget, fileObj, previewInfo, pendingRow) {
    var list = qs(widget, '.sf-upload-list');
    if(!list) return;

    var row = pendingRow || makePendingRow(widget, {name: fileObj.name, size: 0, type: fileObj.kind === 'image' ? 'image/x' : ''});
    if(!row) return;
    row.classList.remove('is-uploading');
    row.classList.add('is-ready');
    var st0 = row.querySelector('.sf-upload-state');
    if(st0) st0.textContent = 'Ready — click Insert to place it in your text';
    var bar0 = row.querySelector('.sf-upload-bar');
    if(bar0) bar0.remove();
    if(fileObj.kind === 'image' && fileObj.tempUrl) {
      var th = row.querySelector('.sf-upload-thumb');
      if(th && !th.style.backgroundImage) th.style.backgroundImage = 'url(' + fileObj.tempUrl + ')';
    }

    var actions = document.createElement('div');
    actions.className = 'sf-upload-actions';

    var btnIns = document.createElement('button');
    btnIns.type = 'button';
    btnIns.className = 'btn btn-sm btn-primary';
    btnIns.textContent = 'Insert';
    btnIns.addEventListener('click', function insertHandler(){
      var targetId = widget.getAttribute('data-target');
      if(!targetId) return;
      var target = document.getElementById(targetId);
      if(!target) return;
      // editor is loaded lazily: load it first, then insert into it
      if(target.classList && target.classList.contains('sf-wysiwyg') && window.SF_CK_SRC && window.sfLoadEditor &&
         !(window.CKEDITOR && window.CKEDITOR.instances && window.CKEDITOR.instances[targetId] && window.CKEDITOR.instances[targetId].status === 'ready')) {
        btnIns.disabled = true;
        var tries = 0;
        window.sfLoadEditor(function(){
          var t = setInterval(function(){
            tries++;
            var inst = window.CKEDITOR && window.CKEDITOR.instances && window.CKEDITOR.instances[targetId];
            if(inst && inst.status === 'ready') { clearInterval(t); btnIns.disabled = false; insertHandler(); }
            else if(tries > 100) { clearInterval(t); btnIns.disabled = false; }
          }, 100);
        });
        return;
      }

      // If target is a textarea replaced by CKEditor, insert a preview element
// but keep a token in attributes we KNOW CKEditor preserves (img.alt / a.href marker).
// On submit, sf-editor.js converts these back into [sfimg:...] / [sffile:...] tokens.
try {
  if(window.CKEDITOR && window.CKEDITOR.instances && window.CKEDITOR.instances[targetId]) {
    var ed = window.CKEDITOR.instances[targetId];
    var tok = String(fileObj.insert || '').trim();
    if(!tok) return;

    ed.focus();

    if(fileObj.kind === 'image') {
      // Prefer a blob URL for editor preview (loads instantly). Fall back to server tempUrl.
      var url = '';
      if(previewInfo && previewInfo.blobUrl) url = previewInfo.blobUrl;
      if(!url) url = (fileObj.tempUrl || (previewInfo && previewInfo.url) || '');

      try {
        // Plain <img>: the editor's resize handle sizes the image itself (no wrapper markup),
        // so the same handle keeps working when the post is edited later.
        var img = new window.CKEDITOR.dom.element('img', ed.document);
        if(url) {
          img.setAttribute('src', url);
          img.setAttribute('data-cke-saved-src', url);
        }
        img.setAttribute('alt', tok);
        img.setAttribute('class', 'sf-embed-img');
        img.setAttribute('style', 'max-width:100%;height:auto;');
        ed.insertElement(img);
      } catch(e) {
        // Fallback: plain image
        var img2 = new window.CKEDITOR.dom.element('img', ed.document);
        if(url) img2.setAttribute('src', url);
        img2.setAttribute('alt', tok);
        img2.setAttribute('class', 'sf-embed-img');
        ed.insertElement(img2);
      }
      return;
    }

    // file
    var nm = (fileObj.name || (previewInfo && previewInfo.name) || 'Download');
    var a  = new window.CKEDITOR.dom.element('a', ed.document);
    var baseHref = (fileObj.tempUrl || (previewInfo && previewInfo.url) || '');
    var href;
    if(baseHref) {
      // keep real temp URL for preview, but add token in URL fragment so we can always recover it on submit
      href = baseHref + (baseHref.indexOf('#') === -1 ? '#sfTok=' : '&sfTok=') + encodeURIComponent(tok);
    } else {
      href = '#sfTok=' + encodeURIComponent(tok);
    }
    a.setAttribute('href', href);
    a.setAttribute('title', tok); // token lives here (preserved via title)
    a.setAttribute('class', 'sf-embed-file');
    var sp = new window.CKEDITOR.dom.element('span', ed.document);
    sp.setAttribute('class', 'sf-file-link');
    sp.setText('⬇ ' + nm);
    a.append(sp);
    ed.insertElement(a);
    return;
  }
} catch(e) {}


      // Contenteditable composer
      if(isEditable(target)) {
        var embed = makeEmbed(fileObj, previewInfo);
        insertNodeAtCaret(target, embed);
        return;
      }

      // Plain textarea fallback: insert token text
      var ta = target;
      if(ta && typeof ta.selectionStart === 'number') {
        var start = ta.selectionStart || 0;
        var end   = ta.selectionEnd || 0;
        var val   = ta.value || '';
        var tok3 = String(fileObj.insert || '');
        ta.value = val.substring(0, start) + tok3 + val.substring(end);
        var pos = start + tok3.length;
        ta.focus();
        ta.selectionStart = ta.selectionEnd = pos;
      }
    });
    actions.appendChild(btnIns);

    var btnDel = document.createElement('button');
    btnDel.type = 'button';
    btnDel.className = 'btn btn-sm btn-outline-secondary sf-upload-del';
    btnDel.textContent = 'Remove';
    btnDel.addEventListener('click', async function(){
      btnDel.disabled = true;
      var ok = await deleteOne(widget, fileObj.key);
      if(ok) row.remove();
      else {
        btnDel.disabled = false;
        setStatus(widget, 'Could not remove upload.', true);
      }
    });
    actions.appendChild(btnDel);

    row.appendChild(actions);
    list.appendChild(row);
  }

  function setupWidget(widget) {
    var input = qs(widget, '.sf-upload-input');
    var drop  = qs(widget, '.sf-upload-drop');
    var picks = qsa(widget, '.sf-btn-pick');
    if(!input || !drop) return;

    var targetId = widget.getAttribute('data-target') || '';

    // per-target preview map for blob URLs
    if(!window.__sfPreviewMap) window.__sfPreviewMap = {};
    if(targetId && !window.__sfPreviewMap[targetId]) window.__sfPreviewMap[targetId] = {};

    function openPicker(kind) {
      widget.__sf_kind = kind || 'auto';
      if(kind === 'image') input.setAttribute('accept', 'image/*');
      else if(kind === 'file') input.removeAttribute('accept');
      else input.removeAttribute('accept');
      input.value = '';
      input.click();
    }

    picks.forEach(function(btn){
      btn.addEventListener('click', function(){
        openPicker(btn.getAttribute('data-kind') || 'auto');
      });
    });

    function handleFiles(files, kindOverride) {
      var queue = files.map(function(file){ return { file: file, row: makePendingRow(widget, file) }; });
      var running = 0, idx = 0;
      function next(){
        while(running < 3 && idx < queue.length) {
          (function(item){
            running++;
            maybeShrink(item.file).then(function(f){
              return uploadOne(widget, f, kindOverride || widget.__sf_kind || 'auto', item.row).then(function(uploaded){
                if(uploaded && uploaded.length) uploaded.forEach(function(fo){
                  var previewInfo = null;
                  if(targetId && fo && fo.key) {
                    if(fo.kind === 'image') {
                      previewInfo = {kind:'image', name:(fo.name||item.file.name), url:(fo.tempUrl||''), blobUrl:(item.row && item.row.__blob) || ''};
                      if(!previewInfo.url) previewInfo.url = previewInfo.blobUrl;
                    } else {
                      previewInfo = {kind:'file', name:(fo.name||item.file.name), url:(fo.tempUrl||'')};
                    }
                    window.__sfPreviewMap[targetId][fo.key] = previewInfo;
                  }
                  addRow(widget, fo, previewInfo, item.row);
                });
              });
            }).then(function(){ running--; next(); }, function(){ running--; next(); });
          })(queue[idx++]);
        }
      }
      next();
    }

    input.addEventListener('change', function() {
      var files = Array.prototype.slice.call(input.files || []);
      if(!files.length) return;
      handleFiles(files);
      input.value = '';
    });

    // drag & drop
    ['dragenter','dragover'].forEach(function(ev){
      drop.addEventListener(ev, function(e){
        e.preventDefault(); e.stopPropagation();
        widget.classList.add('is-drag');
      });
    });
    ['dragleave','drop'].forEach(function(ev){
      drop.addEventListener(ev, function(e){
        e.preventDefault(); e.stopPropagation();
        widget.classList.remove('is-drag');
      });
    });

    drop.addEventListener('drop', function(e){
      var dt = e.dataTransfer;
      if(!dt || !dt.files || !dt.files.length) return;
      handleFiles(Array.prototype.slice.call(dt.files), 'auto');
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    // Composer editors
    document.querySelectorAll('.sf-editor[data-input]').forEach(setupComposerForEditor);

    // Upload widgets
    document.querySelectorAll('.sf-upload-widget').forEach(setupWidget);
  });
})();