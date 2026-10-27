(function(){
  'use strict';

  function $(sel, root){ return (root||document).querySelector(sel); }
  function $all(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

  function escapeHtml(s){
    return (s||'').replace(/[&<>"']/g, function(c){
      switch(c){
        case '&': return '&amp;';
        case '<': return '&lt;';
        case '>': return '&gt;';
        case '"': return '&quot;';
        case "'": return '&#39;';
        default: return c;
      }
    });
  }

  // Convert HTML to plain text with newlines (for textarea fallback)
  function htmlToText(html){
    var div = document.createElement('div');
    div.innerHTML = html || '';
    return (div.innerText || div.textContent || '').trim();
  }

  // Insert HTML into CKEditor4 instance if available; else fallback to textarea value.
  function insertIntoEditor(textarea, html){
    try{
      if(window.CKEDITOR && textarea && textarea.id && window.CKEDITOR.instances && window.CKEDITOR.instances[textarea.id] && window.CKEDITOR.instances[textarea.id].status === 'ready'){
        var inst = window.CKEDITOR.instances[textarea.id];
        inst.focus();
        inst.insertHtml(html);
        return true;
      }
    }catch(e){}

    // CKEditor is loaded lazily: ask sf-editor.js to load it now and insert when ready
    try{
      if(textarea && textarea.id && window.__sfInsertIntoEditor && textarea.classList.contains('sf-wysiwyg') && !(window.CKEDITOR && window.CKEDITOR.instances && window.CKEDITOR.instances[textarea.id])){
        if(window.__sfInsertIntoEditor(textarea.id, html)) return true;
      }
    }catch(e){}

    // CKEditor is enabled but instance is not ready yet (async init)
    // Queue the insert and retry briefly so quote works even if clicked quickly.
    try{
      if(window.CKEDITOR && textarea && textarea.id){
        textarea.__sfPendingQuote = textarea.__sfPendingQuote || [];
        textarea.__sfPendingQuote.push(html);

        if(!textarea.__sfPendingQuoteTimer){
          textarea.__sfPendingQuoteTimer = setInterval(function(){
            try{
              if(window.CKEDITOR.instances && window.CKEDITOR.instances[textarea.id] && window.CKEDITOR.instances[textarea.id].status === 'ready'){
                clearInterval(textarea.__sfPendingQuoteTimer);
                textarea.__sfPendingQuoteTimer = null;
                var inst = window.CKEDITOR.instances[textarea.id];
                inst.focus();
                var q = textarea.__sfPendingQuote || [];
                textarea.__sfPendingQuote = [];
                for(var i=0;i<q.length;i++) inst.insertHtml(q[i]);
              }
            }catch(e){}
          }, 120);
          // stop trying after ~15 seconds (slow connections)
          setTimeout(function(){
            if(textarea.__sfPendingQuoteTimer){
              clearInterval(textarea.__sfPendingQuoteTimer);
              textarea.__sfPendingQuoteTimer = null;
            }
          }, 15000);
        }
        return true;
      }
    }catch(e){}
    try{
      // fallback: add as BB-like quote (plain)
      var txt = htmlToText(html);
      var quoteBlock = "\n\n> " + txt.replace(/\n/g, "\n> ") + "\n\n";
      textarea.value = (textarea.value || '') + quoteBlock;
      textarea.focus();
      return true;
    }catch(e){}
    return false;
  }

  // Only the quoted post's OWN words: drop the quotes it contains (no quote pyramids),
  // the reply-to box, signatures and UI bits — like phpBB/Discourse/XenForo do.
  function cleanQuotedHtml(srcEl){
    if(!srcEl) return '';
    var c = srcEl.cloneNode(true);
    var junk = c.querySelectorAll('blockquote, .sf-replyto, .sf-signature, .sf-quote-toggle, .sf-quote-more, script, style, button, .sf-vote-slot');
    for(var i = 0; i < junk.length; i++) junk[i].parentNode && junk[i].parentNode.removeChild(junk[i]);
    // remove empty paragraphs left behind
    var ps = c.querySelectorAll('p');
    for(var j = 0; j < ps.length; j++) {
      if(!ps[j].textContent.trim() && !ps[j].querySelector('img,iframe,video')) ps[j].parentNode.removeChild(ps[j]);
    }
    return c.innerHTML.trim();
  }

  function buildQuoteHtml(metaText, bodyHtml, url){
    var meta = escapeHtml(metaText || '');
    var head = url ? ("<a href='" + escapeHtml(url) + "'>" + meta + "</a>") : meta;
    return (
      "<blockquote class='sf-quote'>" +
        "<p class='sf-quote-meta'><strong>" + head + "</strong></p>" +
        (bodyHtml || '') +
      "</blockquote><p></p>"
    );
  }

  function onQuoteClick(btn){
    try{
      var replyTo = btn.getAttribute('data-reply-to') || '0';
      var meta = btn.getAttribute('data-quote-meta') || '';
      var srcSel = btn.getAttribute('data-quote-source') || '';
      var srcEl = srcSel ? document.querySelector(srcSel) : null;

      var replyForm = document.getElementById('reply-form') || document.querySelector('form#reply-form');
      if(replyForm){
        var hidden = replyForm.querySelector("input[name='reply_to']");
        if(hidden) hidden.value = String(replyTo);
      }

      // Show "Replying to" helper
      var info = document.getElementById('sf-replying-to');
      if(info){
        info.style.display = 'block';
        info.textContent = meta ? ("Replying to: " + meta) : "Replying…";
      }

      var textarea = document.getElementById('sf-reply-body') || document.querySelector('textarea[name=body]');
      if(!textarea) return;

      // Quote the selected text if the user highlighted part of this post, else the whole post
      var bodyHtml = '';
      try {
        var sel = window.getSelection ? window.getSelection() : null;
        if(sel && sel.rangeCount && !sel.isCollapsed && srcEl && srcEl.contains(sel.anchorNode) && srcEl.contains(sel.focusNode)) {
          var box = document.createElement('div');
          box.appendChild(sel.getRangeAt(0).cloneContents());
          bodyHtml = cleanQuotedHtml(box);
        }
      } catch(e) {}
      if(!bodyHtml) bodyHtml = cleanQuotedHtml(srcEl);
      var qhtml = buildQuoteHtml(meta, bodyHtml, btn.getAttribute('data-quote-url') || '');

      insertIntoEditor(textarea, qhtml);

      // scroll to editor
      try{
        textarea.scrollIntoView({behavior:'smooth', block:'center'});
      }catch(e){}
    }catch(e){}
  }

  document.addEventListener('click', function(e){
    var t = e.target;
    if(!t) return;
    // allow clicking on icon inside button
    var btn = t.closest ? t.closest('.sf-quote-btn') : null;
    if(btn){
      e.preventDefault();
      onQuoteClick(btn);
    }
  }, true);

  // Display of quotes (collapse nested/long quotes) lives in sf-ui.js so guests get it too.

})();