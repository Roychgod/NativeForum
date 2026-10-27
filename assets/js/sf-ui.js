(function(){
  // Prevent double-initialization (can happen when scripts are concatenated/loaded twice)
  // Guard on both window and on <html data-*> (survives concatenation/reloads)
  try {
    if(document.documentElement && document.documentElement.dataset && document.documentElement.dataset.sfUiInited) return;
    if(document.documentElement && document.documentElement.dataset) document.documentElement.dataset.sfUiInited = '1';
  } catch(e) {}
  if(window.__SF_UI_INITED__) return;
  window.__SF_UI_INITED__ = true;
  function onReady(fn){
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function qs(sel, root){ return (root||document).querySelector(sel); }
  function qsa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

  // Find a ProcessWire CSRF token pair in a form/document (TOKENxxxx => value)
  function findCsrfPair(root){
    root = root || document;
    var inputs = root.querySelectorAll('input[type="hidden"]');
    for(var i=0;i<inputs.length;i++) {
      var n = inputs[i].name || '';
      if(n.indexOf('TOKEN') === 0 && inputs[i].value) return {name:n, value:inputs[i].value};
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

  // ---------- Dropdown fallback (works without Bootstrap JS) ----------
  function initDropdownFallback(){
    function closest(el, sel){
      while(el && el.nodeType===1) {
        if(el.matches && el.matches(sel)) return el;
        el = el.parentElement;
      }
      return null;
    }

    function findMenu(toggle){
      if(!toggle) return null;
      // Bootstrap-like: next sibling .dropdown-menu
      var sib = toggle.nextElementSibling;
      if(sib && sib.classList && sib.classList.contains('dropdown-menu')) return sib;
      // Or within parent
      var parent = closest(toggle, '.dropdown, .btn-group') || toggle.parentElement;
      if(parent) {
        var m = parent.querySelector('.dropdown-menu');
        if(m) return m;
      }
      // aria-controls
      var ac = toggle.getAttribute('aria-controls');
      if(ac) {
        var byId = document.getElementById(ac);
        if(byId) return byId;
      }
      return null;
    }

    function closeAll(exceptEl){
      qsa('.sf-dd-open').forEach(function(open){
        if(exceptEl && (open === exceptEl || (open.contains && open.contains(exceptEl)))) return;
        open.classList.remove('sf-dd-open');
        // Also remove Bootstrap's state classes if present
        try { open.classList.remove('show'); } catch(e) {}
        try { open.classList.remove('open'); } catch(e2) {}
        var btn = open.querySelector('.sf-dd-toggle,[data-sf-dd-toggle],.sf-dots-btn,[data-bs-toggle="dropdown"]');
        if(btn) btn.setAttribute('aria-expanded','false');
        var menu = open.querySelector('.dropdown-menu');
        if(menu) {
          try { menu.classList.remove('show'); } catch(e) {}
        }
      });
    }

    document.addEventListener('click', function(e){
      var t = e.target;
      if(!t) return;

      var toggle = closest(t, '.sf-dd-toggle,[data-sf-dd-toggle],.sf-dots-btn,[data-bs-toggle="dropdown"],.sf-search-scope-toggle');
      // Never touch dropdowns that belong to the host site (only forum markup)
      if(toggle && !closest(toggle, '.sf-wrap,.sf-modal,[id^="sfLogin"],[id^="sfRegister"]')) toggle = null;
      if(toggle) {
        e.preventDefault();
        e.stopPropagation();
        // Prevent Bootstrap (or other libs) from double-handling the same click
        if(e.stopImmediatePropagation) e.stopImmediatePropagation();

        var holder = closest(toggle, '.dropdown, .btn-group') || toggle.parentElement;
        if(!holder) return;

        var isOpen = holder.classList.contains('sf-dd-open') || holder.classList.contains('show') || holder.classList.contains('open');
        closeAll(holder);
        if(!isOpen) {
          holder.classList.add('sf-dd-open');
          // Compatibility: Bootstrap 3 uses .open, Bootstrap 4/5 uses .show
          try { holder.classList.add('show'); } catch(e2) {}
          try { holder.classList.add('open'); } catch(e2b) {}
          toggle.setAttribute('aria-expanded','true');
          var menu = findMenu(toggle) || (holder ? holder.querySelector('.dropdown-menu') : null);
          if(menu) {
            try { menu.classList.add('show'); } catch(e3) {}
          }
        } else {
          holder.classList.remove('sf-dd-open');
          try { holder.classList.remove('show'); } catch(e4) {}
          try { holder.classList.remove('open'); } catch(e4b) {}
          toggle.setAttribute('aria-expanded','false');
        }
        return;
      }

      // Click outside closes
      closeAll(t);
    }, true);

    document.addEventListener('keydown', function(e){
      if(e.key === 'Escape') closeAll(null);
    });
  }

  // ---------- Start New Topic modal (custom sf modal) ----------
  function initNewTopicModal(){
    function esc(t){ return String(t == null ? '' : t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    function buildModalFromIndex(){
      if(document.getElementById('sfNewTopicModal')) return document.getElementById('sfNewTopicModal');
      var groups = [];
      try { var j = document.getElementById('sfNewTopicCats'); if(j) groups = JSON.parse(j.textContent || '[]') || []; } catch(e) { groups = []; }
      if(!groups.length) {
        // fallback: category links on the index
        var seen = {}, cats = [];
        qsa('.sf-forum-titlewrap a, a.sf-index-title').forEach(function(a){
          var h = a.getAttribute('href') || '', t = (a.textContent || '').trim();
          if(h && t && !seen[h]) { seen[h] = 1; cats.push({t: t, u: h + '#new-topic-form', d: ''}); }
        });
        if(cats.length) groups = [{g: '', cats: cats}];
      }
      var total = 0; groups.forEach(function(g){ total += (g.cats || []).length; });
      var listHtml = groups.map(function(g){
        return (g.g ? "<div class='sf-ntm-group'>" + esc(g.g) + "</div>" : '') +
          (g.cats || []).map(function(c){
            return "<a class='sf-ntm-item' href='" + esc(c.u) + "' data-sf-ntm-text='" + esc((g.g + ' ' + c.t + ' ' + (c.d || '')).toLowerCase()) + "'>" +
              "<span class='sf-ntm-ic' aria-hidden='true'>+</span><span class='sf-ntm-txt'><strong>" + esc(c.t) + "</strong>" +
              (c.d ? "<small>" + esc(c.d) + "</small>" : '') + "</span><span class='sf-ntm-go' aria-hidden='true'>›</span></a>";
          }).join('');
      }).join('');
      var modal = document.createElement('div');
      modal.className = 'sf-modal sf-ntm';
      modal.id = 'sfNewTopicModal';
      modal.setAttribute('aria-hidden','true');
      modal.innerHTML =
        "<div class='sf-modal-backdrop' data-sf-close='1'></div>" +
        "<div class='sf-modal-dialog' role='dialog' aria-modal='true' aria-labelledby='sfNtmTitle'>" +
          "<div class='sf-modal-header'><div class='sf-modal-title' id='sfNtmTitle'>Start new topic</div>" +
          "<button type='button' class='sf-modal-close' data-sf-close='1' aria-label='Close'>×</button></div>" +
          "<div class='sf-modal-body'>" +
          (total ? ("<div class='sf-ntm-hint'>Where do you want to post?</div>" +
            (total > 6 ? "<input type='search' class='sf-ntm-filter' placeholder='Find a forum…' aria-label='Find a forum'>" : '') +
            "<div class='sf-ntm-list'>" + listHtml + "</div>")
            : "<div class='sf-ntm-hint'>There is no forum you can post in.</div>") +
          "</div>" +
        "</div>";
      // inside the forum wrapper so the forum theme applies (not the site's styles)
      var host = document.querySelector('.sf-wrap') || document.body;
      host.appendChild(modal);
      var f = modal.querySelector('.sf-ntm-filter');
      if(f) f.addEventListener('input', function(){
        var q = f.value.trim().toLowerCase();
        qsa('.sf-ntm-item', modal).forEach(function(it){ it.style.display = (!q || (it.getAttribute('data-sf-ntm-text') || '').indexOf(q) !== -1) ? '' : 'none'; });
        qsa('.sf-ntm-group', modal).forEach(function(gh){
          var n = gh.nextElementSibling, any = false;
          while(n && !n.classList.contains('sf-ntm-group')) { if(n.style.display !== 'none') any = true; n = n.nextElementSibling; }
          gh.style.display = any ? '' : 'none';
        });
      });
      return modal;
    }

    var modal = document.getElementById('sfNewTopicModal');
    // We'll still bind the button even if modal is missing; on click we create it.

    function openM(){
      if(!modal) modal = buildModalFromIndex();
      if(!modal) return;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden','false');
      try { document.documentElement.classList.add('sf-modal-lock'); } catch(e) {}
      setTimeout(function(){ var x = modal.querySelector('.sf-ntm-filter') || modal.querySelector('.sf-ntm-item'); if(x) x.focus(); }, 30);
    }
    function closeM(){
      if(!modal) return;
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden','true');
      try { document.documentElement.classList.remove('sf-modal-lock'); } catch(e) {}
    }

    // Open buttons: support both legacy id and a generic selector
    var openButtons = [];
    var b1 = document.getElementById('sfNewTopicIndexBtn');
    if(b1) openButtons.push(b1);
    openButtons = openButtons.concat(qsa('[data-sf-open="sfNewTopicModal"], .sf-newtopic-open'));

    openButtons.forEach(function(btn){
      btn.addEventListener('click', function(e){
        e.preventDefault();
        openM();
      });
    });

    // Close actions (delegate to handle dynamically-created modal)
    document.addEventListener('click', function(e){
      var t = e.target;
      if(!t) return;
      if(t.getAttribute && t.getAttribute('data-sf-close') === '1') {
        e.preventDefault();
        closeM();
      }
    });

    // Continue button
    document.addEventListener('click', function(e){
      var t = e.target;
      if(!t || !t.id || t.id !== 'sfNewTopicGo') return;
      var sel = document.getElementById('sfNewTopicForumSelect');
      if(!sel) return;
      var url = sel.value || '';
      if(url) window.location.href = url;
    });

    // ESC closes
    document.addEventListener('keydown', function(e){
      if(e.key !== 'Escape') return;
      var m = document.getElementById('sfNewTopicModal');
      if(m && m.classList.contains('is-open')) {
        modal = m;
        closeM();
      }
    });
  }

  // ---------- AJAX: mark category/topics read ----------
  function initMarkRead(){
    var btns = qsa('.sf-btn-markallread');
    if(!btns.length) return;

    btns.forEach(function(btn){
      btn.addEventListener('click', function(ev){
        ev.preventDefault();
        var form = btn.closest('form') || document;
        var csrf = findCsrfPair(form) || findCsrfPair(document);
        var url = (window.location.href || '').split('#')[0];
        var data = {sf_markallread: '1'};
        if(csrf) data[csrf.name] = csrf.value;

        btn.disabled = true;
        post(url, data)
          .then(function(r){ return r.json ? r.json() : r.text(); })
          .then(function(resp){
            // Clear unread UI
            qsa('.sf-topic-rowitem.sf-unread').forEach(function(row){
              row.classList.remove('sf-unread');
              // remove bold
              var a = row.querySelector('.sf-topic-title');
              if(a) a.classList.remove('fw-bold');
              var dot = row.querySelector('.sf-unread-dot');
              if(dot) dot.remove();
            });
            // remove next unread block
            var nu = qs('.sf-next-unread');
            if(nu) nu.remove();
          })
          .catch(function(err){
            console.warn('SF markread failed', err);
            btn.disabled = false;
          });
      });
    });
  }

  // ---------- User info hover card ----------
  var userCardEl = null;
  var userCardHideT = null;

  function ensureUserCard(){
    if(userCardEl) return userCardEl;
    // Reuse existing singleton element if present (avoids two stacked popups)
    var existing = document.getElementById('sf-usercard-pop');
    if(existing) {
      userCardEl = existing;
      return userCardEl;
    }
    userCardEl = document.createElement('div');
    userCardEl.id = 'sf-usercard-pop';
    userCardEl.className = 'sf-usercard';
    userCardEl.style.display = 'none';
    document.body.appendChild(userCardEl);

    userCardEl.addEventListener('mouseenter', function(){
      if(userCardHideT) { clearTimeout(userCardHideT); userCardHideT = null; }
    });
    userCardEl.addEventListener('mouseleave', function(){
      hideUserCard(80);
    });
    return userCardEl;
  }

  function placeFloating(el, target){
    var r = target.getBoundingClientRect();
    var x = r.left + window.scrollX;
    var y = r.bottom + window.scrollY + 8;
    var placedAbove = false;

    // keep in viewport
    var maxX = window.scrollX + document.documentElement.clientWidth - el.offsetWidth - 10;
    if(x > maxX) x = Math.max(10 + window.scrollX, maxX);
    var maxY = window.scrollY + document.documentElement.clientHeight - el.offsetHeight - 10;
    if(y > maxY) { y = r.top + window.scrollY - el.offsetHeight - 10; placedAbove = true; }
    if(y < window.scrollY + 10) y = window.scrollY + 10;

    el.style.left = x + 'px';
    el.style.top  = y + 'px';

    // Arrow direction
    el.classList.toggle('sf-usercard--arrow-bottom', placedAbove);
    el.classList.toggle('sf-usercard--arrow-top', !placedAbove);

    // Arrow position (CSS var)
    try {
      var center = (r.left + (r.width / 2)) + window.scrollX;
      var arrowX = center - x - 8; // 8px = half arrow width
      var maxArrow = Math.max(20, el.offsetWidth - 20);
      if(arrowX < 16) arrowX = 16;
      if(arrowX > maxArrow) arrowX = maxArrow;
      el.style.setProperty('--sf-arrow-x', arrowX + 'px');
    } catch(e) {}
  }

  function showUserCard(target){
    var el = ensureUserCard();

    // Hide any 3rd party tooltip bubbles that might otherwise overlap (tipso, etc.)
    try {
      document.querySelectorAll('.tipso_bubble').forEach(function(b){ b.style.display = 'none'; });
    } catch(e) {}

    var title = target.getAttribute('data-sf-usercard-title') || '';
    var rank = target.getAttribute('data-sf-user-rank') || '';
    var joined = target.getAttribute('data-sf-user-joined') || '';
    var lastSeen = target.getAttribute('data-sf-user-lastseen') || '';
    var messages = target.getAttribute('data-sf-user-messages') || '0';
    var reactions = target.getAttribute('data-sf-user-reactions') || '0';
    var points = target.getAttribute('data-sf-user-points') || '0';
    var ratings = target.getAttribute('data-sf-user-ratings') || '0';
    var pmUrl = target.getAttribute('data-sf-user-pm-url') || '';
    var avatarUrl = target.getAttribute('data-sf-user-avatar') || '';
    var initial = target.getAttribute('data-sf-user-initial') || (title ? title.trim().charAt(0) : '?');

    var avatarHtml = avatarUrl
      ? ("<img src='" + avatarUrl + "' alt='' loading='lazy'>")
      : ("<span aria-hidden='true'>" + (initial || '?') + "</span>");

    function esc(s){
      s = (s==null?'':String(s));
      return s.replace(/[&<>"']/g, function(ch){
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[ch] || ch);
      });
    }

    var metaHtml = "";
    if(joined) metaHtml += "<div class='sf-usercard-meta'><span class='sf-usercard-meta-lbl'>Joined:</span> <strong>" + esc(joined) + "</strong></div>";
    if(lastSeen) metaHtml += "<div class='sf-usercard-meta'><span class='sf-usercard-meta-lbl'>Last seen:</span> <strong>" + esc(lastSeen) + "</strong></div>";

    var actions = "";
    if(pmUrl) {
      actions += "<a class='sf-usercard-btn' href='" + esc(pmUrl) + "'>Start conversation</a>";
    }
    // Optional placeholders (enabled via custom code if you implement follow/ignore endpoints)
    var followUrl = target.getAttribute('data-sf-user-follow-url') || '';
    var ignoreUrl = target.getAttribute('data-sf-user-ignore-url') || '';
    if(followUrl) actions = "<a class='sf-usercard-btn sf-usercard-btn--ghost' href='" + esc(followUrl) + "'>Follow</a>" + actions;
    if(ignoreUrl) actions = "<a class='sf-usercard-btn sf-usercard-btn--ghost' href='" + esc(ignoreUrl) + "'>Ignore</a>" + actions;

    el.innerHTML = ""
      + "<div class='sf-usercard-top'>"
      + "  <div class='sf-usercard-toprow'>"
      + "    <div class='sf-usercard-avatar'>" + avatarHtml + "</div>"
      + "    <div class='sf-usercard-toptext'>"
      + (title ? ("<div class='sf-usercard-name'>" + esc(title) + "</div>") : "")
      + (rank ? ("<div class='sf-usercard-rank'>" + esc(rank) + "</div>") : "")
      + "      <div class='sf-usercard-metawrap'>" + metaHtml + "</div>"
      + "    </div>"
      + "  </div>"
      + "</div>"
      + "<div class='sf-usercard-stats'>"
      + "  <div class='sf-usercard-stat'><div class='sf-usercard-stat-lbl'>Messages</div><div class='sf-usercard-stat-num'>" + esc(messages) + "</div></div>"
      + "  <div class='sf-usercard-stat'><div class='sf-usercard-stat-lbl'>Reaction score</div><div class='sf-usercard-stat-num'>" + esc(reactions) + "</div></div>"
      + "  <div class='sf-usercard-stat'><div class='sf-usercard-stat-lbl'>Points</div><div class='sf-usercard-stat-num'>" + esc(points) + "</div></div>"
      + "  <div class='sf-usercard-stat'><div class='sf-usercard-stat-lbl'>Ratings received</div><div class='sf-usercard-stat-num'>" + esc(ratings) + "</div></div>"
      + "</div>"
      + (actions ? ("<div class='sf-usercard-actions'>" + actions + "</div>") : "");

    el.style.display = 'block';
    placeFloating(el, target);
  }

  function hideUserCard(delay){
    delay = typeof delay === 'number' ? delay : 120;
    if(userCardHideT) clearTimeout(userCardHideT);
    userCardHideT = setTimeout(function(){
      if(userCardEl) userCardEl.style.display = 'none';
    }, delay);
  }

  function initUserCards(){
    qsa('[data-sf-usercard="1"]').forEach(function(wrap){
      if(wrap.dataset && wrap.dataset.sfUsercardBound === '1') return;
      if(wrap.dataset) wrap.dataset.sfUsercardBound = '1';
      // Avoid native browser tooltips and third-party tooltip plugins duplicating our card
      try {
        wrap.removeAttribute('title');
        qsa('[title]', wrap).forEach(function(el){ el.removeAttribute('title'); });
        if(window.jQuery && window.jQuery.fn && window.jQuery.fn.tipso) {
          try { window.jQuery(wrap).tipso('destroy'); } catch(e) {}
        }
      } catch(e) {}
      wrap.addEventListener('mouseenter', function(){
        if(userCardHideT) { clearTimeout(userCardHideT); userCardHideT = null; }
        showUserCard(wrap);
      });
      wrap.addEventListener('mouseleave', function(){
        hideUserCard(120);
      });
    });
  }

  // ---------- Long title tooltip (works even without Bootstrap JS) ----------
  var tipEl = null, tipHideT = null;

  function ensureTip(){
    if(tipEl) return tipEl;
    var existing = document.getElementById('sf-tooltip-pop');
    if(existing) {
      tipEl = existing;
      return tipEl;
    }
    tipEl = document.createElement('div');
    tipEl.id = 'sf-tooltip-pop';
    tipEl.className = 'sf-tooltip';
    tipEl.style.display = 'none';
    document.body.appendChild(tipEl);
    tipEl.addEventListener('mouseenter', function(){
      if(tipHideT) { clearTimeout(tipHideT); tipHideT = null; }
    });
    tipEl.addEventListener('mouseleave', function(){ hideTip(60); });
    return tipEl;
  }

  function showTip(target, text){
    var el = ensureTip();
    el.textContent = text;
    el.style.display = 'block';
    placeFloating(el, target);
  }
  function hideTip(delay){
    delay = typeof delay === 'number' ? delay : 120;
    if(tipHideT) clearTimeout(tipHideT);
    tipHideT = setTimeout(function(){
      if(tipEl) tipEl.style.display = 'none';
    }, delay);
  }

  function initTooltips(){
    // Elements using title + data-bs-toggle="tooltip" OR explicit data-sf-tooltip
    // NOTE: .sf-last-link gets tooltip only when actually truncated.
    var els = qsa('[data-sf-tooltip], [data-bs-toggle="tooltip"], .sf-longtitle, .sf-last-link, .sf-side-link');
    els.forEach(function(el){
      // If element is a usercard trigger, we don't want an additional tooltip on hover
      // (otherwise you'll see two popups at once).
      if(el.getAttribute('data-sf-usercard') === '1' || el.hasAttribute('data-sf-usercard-content') || el.classList.contains('sf-usercard-trigger')) {
        return;
      }
      var txt = el.getAttribute('data-sf-tooltip') || el.getAttribute('data-sf-tip') || el.getAttribute('title') || '';
      if(!txt) return;
      // Keep native tooltip from showing
      el.setAttribute('data-sf-has-tip','1');
      function isTruncated(){
        // The ellipsis can sit on the link itself or on a wrapper (e.g. sidebar row label)
        try {
          var n = el;
          for(var i = 0; i < 3 && n; i++) {
            var cs = window.getComputedStyle(n);
            if(n.clientWidth > 0 && cs.overflow !== 'visible' && n.scrollWidth > n.clientWidth + 1) return true;
            if(cs.display !== 'inline' && n !== el) break;
            n = n.parentElement;
          }
        } catch(e) {}
        return false;
      }

      el.addEventListener('mouseenter', function(){
        if(tipHideT) { clearTimeout(tipHideT); tipHideT = null; }
        // For long-title / last-post titles, show popup only if truncated with ellipsis.
        if((el.classList.contains('sf-last-link') || el.classList.contains('sf-longtitle') || el.classList.contains('sf-side-link')) && !isTruncated()) return;
        showTip(el, txt);
      });
      el.addEventListener('mouseleave', function(){ hideTip(120); });
      el.addEventListener('focus', function(){ showTip(el, txt); });
      el.addEventListener('blur', function(){ hideTip(120); });
      // prevent browser native tooltip
      el.removeAttribute('title');
    });
  }

  // ---------- Search scope selector (sidebar/header) ----------
  function initSearchScope(){
    // Works for both:
    // - Bootstrap dropdown items (button.dropdown-item[data-sf-scope])
    // - Simple theme <details> menu items (.sf-scope-item[data-sf-scope])
    document.addEventListener('click', function(e){
      var t = e.target;
      if(!t || !t.getAttribute) return;
      var scope = t.getAttribute('data-sf-scope');
      if(!scope) return;

      // Only handle inside a forum search form
      var form = t.closest ? t.closest('form.sf-search-adv') : null;
      if(!form) return;

      e.preventDefault();
      e.stopPropagation();
      if(e.stopImmediatePropagation) e.stopImmediatePropagation();

      // Update hidden input
      var inp = form.querySelector('input[name="sf_scope"]');
      if(inp) inp.value = scope;

      // Update title on toggle button/summary for UX
      var toggle = form.querySelector('.sf-search-scope-toggle');
      if(toggle) {
        var txt = (t.textContent || '').trim();
        if(txt) toggle.setAttribute('title', txt);
      }

      // Close dropdown holder if we used dropdown fallback
      var holder = t.closest ? t.closest('.dropdown, .btn-group, .sf-dd') : null;
      if(holder) {
        holder.classList.remove('sf-dd-open');
        try { holder.classList.remove('show'); } catch(e2) {}
        try { holder.classList.remove('open'); } catch(e3) {}
      }

      // Close <details> if used
      var det = t.closest ? t.closest('details.sf-scope-details') : null;
      if(det) det.removeAttribute('open');
    }, true);
  }

  // ---------- Index group collapse (accordion-like) ----------
  function initGroupCollapse(){
    var toggles = qsa('[data-sf-group-toggle="1"], .sf-group-toggle');
    if(!toggles.length) return;

    function getKey(bodyId){ return bodyId ? ('sf_group_collapsed_' + bodyId) : null; }

    function setState(block, btn, collapsed){
      if(!block) return;
      block.classList.toggle('is-collapsed', !!collapsed);
      if(btn) btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    toggles.forEach(function(btn){
      if(btn.dataset && btn.dataset.sfGroupBound === '1') return;
      if(btn.dataset) btn.dataset.sfGroupBound = '1';
      var block = btn.closest ? btn.closest('.sf-groupblock') : null;
      var bodyId = btn.getAttribute('aria-controls') || '';
      var key = getKey(bodyId);

      // Restore state
      try {
        if(key && window.localStorage) {
          var v = localStorage.getItem(key);
          if(v === '1') setState(block, btn, true);
        }
      } catch(e) {}

      btn.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        var collapsed = block ? block.classList.contains('is-collapsed') : false;
        setState(block, btn, !collapsed);
        try {
          if(key && window.localStorage) localStorage.setItem(key, (!collapsed) ? '1' : '0');
        } catch(e2) {}
      });
    });
  }

  // ---------- Quotes in posts (display) ----------
  // Top-level quotes get a clean header; quotes inside quotes collapse to one line
  // ("Earlier quote"), long quotes are clipped with a "Show more" button.
  function initQuotes(){
    qsa('.sf-wrap .sf-post-body blockquote').forEach(function(q){
      if(q.getAttribute('data-sf-q')) return;
      q.setAttribute('data-sf-q', '1');
      q.classList.add('sf-quote');
      var parentQuote = q.parentElement ? q.parentElement.closest('blockquote') : null;
      // Header: first paragraph that is only bold text ("X wrote" / "On ... said:")
      var first = q.firstElementChild;
      if(first && first.tagName === 'P' && first.querySelector('strong') && first.textContent.trim() === (first.querySelector('strong').textContent || '').trim()) {
        first.classList.add('sf-quote-meta');
      }
      if(parentQuote) {
        // Nested quote -> collapsed
        var det = document.createElement('details');
        det.className = 'sf-quote-nested';
        var sum = document.createElement('summary');
        var metaEl = q.querySelector('.sf-quote-meta');
        sum.textContent = metaEl ? ('Earlier quote: ' + metaEl.textContent.replace(/\s+/g, ' ').trim()) : 'Earlier quote';
        q.parentNode.insertBefore(det, q);
        det.appendChild(sum);
        det.appendChild(q);
        return;
      }
    });
    // Long top-level quotes: clip + button (measured after nested ones collapsed)
    qsa('.sf-wrap .sf-post-body > blockquote.sf-quote, .sf-wrap .sf-post-body > * > blockquote.sf-quote').forEach(function(q){
      if(q.closest('details.sf-quote-nested') || q.getAttribute('data-sf-qlong')) return;
      q.setAttribute('data-sf-qlong', '1');
      if(q.scrollHeight <= 260) return;
      q.classList.add('sf-quote-clipped');
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'sf-quote-more';
      b.textContent = 'Show more';
      b.addEventListener('click', function(){
        var open = q.classList.toggle('is-open');
        b.textContent = open ? 'Show less' : 'Show more';
      });
      q.parentNode.insertBefore(b, q.nextSibling);
    });
  }


  // ---------- Post links: copy link, #N permalink, share dialog ----------
  function initPostLinks(){
    function toast(msg){
      var host = document.querySelector('.sf-wrap') || document.body;
      var t = document.getElementById('sf-mini-toast');
      if(!t) { t = document.createElement('div'); t.id = 'sf-mini-toast'; t.className = 'sf-mini-toast'; t.setAttribute('role','status'); t.setAttribute('aria-live','polite'); host.appendChild(t); }
      t.textContent = msg; t.classList.add('is-in');
      clearTimeout(t.__h); t.__h = setTimeout(function(){ t.classList.remove('is-in'); }, 1800);
    }
    function copyText(txt){
      return new Promise(function(res){
        try {
          if(navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(txt).then(function(){ res(true); }, function(){ res(fallback()); }); return; }
        } catch(e) {}
        res(fallback());
        function fallback(){
          try {
            var ta = document.createElement('textarea'); ta.value = txt; ta.setAttribute('readonly',''); ta.style.position = 'fixed'; ta.style.top = '-1000px';
            document.body.appendChild(ta); ta.select(); var ok = document.execCommand('copy'); document.body.removeChild(ta); return ok;
          } catch(e) { return false; }
        }
      });
    }
    function absUrl(u){ try { return new URL(u, location.href).href; } catch(e) { return u; } }
    function highlight(id){
      var el = id ? document.getElementById(id) : null;
      if(!el) return;
      el.classList.remove('sf-post-flash'); void el.offsetWidth; el.classList.add('sf-post-flash');
    }
    function closeMenus(){ try { document.dispatchEvent(new MouseEvent('click', {bubbles:true})); } catch(e) {} qsa('.dropdown-menu.show').forEach(function(m){ m.classList.remove('show'); }); }
    function esc(t){ return String(t == null ? '' : t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    var ICONS = {
      fb: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13.5 22v-8h2.7l.4-3.2h-3.1V8.8c0-.9.3-1.5 1.6-1.5h1.7V4.4c-.3 0-1.3-.1-2.5-.1-2.5 0-4.1 1.5-4.1 4.2v2.3H7.4V14h2.8v8h3.3z"/></svg>',
      x: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.8 3h3.1l-6.8 7.8L22 21h-6.2l-4.9-6.4L5.3 21H2.2l7.3-8.3L2 3h6.4l4.4 5.8L17.8 3zm-1.1 16.2h1.7L7.4 4.7H5.6l11.1 14.5z"/></svg>',
      wa: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2c-1.5 0-3-.4-4.3-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1-.7-.3-1.4-.7-2-1.3-.5-.5-1-1.1-1.3-1.7-.1-.2 0-.4.1-.5l.4-.4.3-.5v-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.6.6-.9 1.4-.9 2.2.1 1 .5 2 1.1 2.8 1.2 1.7 2.7 3 4.6 3.8.5.2 1 .4 1.5.5.6.2 1.2.1 1.7-.1.5-.3 1-.7 1.2-1.3.1-.3.1-.6 0-.9l-.5-.3z"/></svg>',
      tg: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M21.9 4.3 18.7 19.4c-.2 1-.9 1.3-1.7.8l-4.8-3.5-2.3 2.2c-.3.3-.5.5-1 .5l.3-4.9 8.9-8c.4-.3-.1-.5-.6-.2L6.5 13.2 1.8 11.7c-1-.3-1-1 .2-1.5L20.5 3.1c.9-.3 1.6.2 1.4 1.2z"/></svg>',
      in: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9.5h4v11H3v-11zm6.5 0h3.8v1.6h.1c.5-1 1.8-2 3.7-2 4 0 4.7 2.6 4.7 6v5.4h-4v-4.8c0-1.1 0-2.6-1.6-2.6s-1.8 1.2-1.8 2.5v4.9h-4v-11z"/></svg>',
      mail: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm9 7.2L4.2 7v10h15.6V7L12 12.2zM5.5 7l6.5 4.3L18.5 7h-13z"/></svg>',
      more: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18 16a3 3 0 0 0-2.4 1.2l-6.7-3.4a3 3 0 0 0 0-1.6l6.7-3.4A3 3 0 1 0 15 7l-6.7 3.4a3 3 0 1 0 0 3.2L15 17a3 3 0 1 0 3-1z"/></svg>'
    };

    function shareDialog(url, title, text){
      url = absUrl(url);
      var host = document.querySelector('.sf-wrap') || document.body;
      var m = document.getElementById('sfShareModal');
      if(m) m.parentNode.removeChild(m);
      var eu = encodeURIComponent(url), et = encodeURIComponent(title || document.title);
      var targets = [
        ['fb', 'Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' + eu],
        ['x', 'X', 'https://twitter.com/intent/tweet?url=' + eu + '&text=' + et],
        ['wa', 'WhatsApp', 'https://wa.me/?text=' + encodeURIComponent((title ? title + ' ' : '') + url)],
        ['tg', 'Telegram', 'https://t.me/share/url?url=' + eu + '&text=' + et],
        ['in', 'LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' + eu],
        ['mail', 'E-mail', 'mailto:?subject=' + et + '&body=' + encodeURIComponent((text ? text + '\n\n' : '') + url)]
      ];
      m = document.createElement('div');
      m.className = 'sf-modal sf-share-modal is-open';
      m.id = 'sfShareModal';
      m.innerHTML = "<div class='sf-modal-backdrop' data-sf-share-close='1'></div>" +
        "<div class='sf-modal-dialog' role='dialog' aria-modal='true' aria-labelledby='sfShareTitle'>" +
        "<div class='sf-modal-header'><div class='sf-modal-title' id='sfShareTitle'>Share</div><button type='button' class='sf-modal-close' data-sf-share-close='1' aria-label='Close'>×</button></div>" +
        "<div class='sf-modal-body'>" +
          "<div class='sf-share-preview'><strong>" + esc(title || '') + "</strong>" + (text ? "<span>" + esc(text) + "</span>" : '') + "<small>" + esc(url.replace(/^https?:\/\//, '')) + "</small></div>" +
          "<div class='sf-share-grid'>" +
            targets.map(function(t){ return "<a class='sf-share-tg sf-share-" + t[0] + "' href='" + esc(t[2]) + "' target='_blank' rel='noopener nofollow' data-sf-share-popup='" + (t[0] === 'mail' ? '0' : '1') + "'>" + ICONS[t[0]] + "<span>" + t[1] + "</span></a>"; }).join('') +
            (navigator.share ? "<button type='button' class='sf-share-tg sf-share-more' data-sf-share-native='1'>" + ICONS.more + "<span>More…</span></button>" : '') +
          "</div>" +
          "<div class='sf-share-copy'><input type='text' readonly value='" + esc(url) + "' aria-label='Link'><button type='button' class='btn btn-primary btn-sm' data-sf-share-copy='1'>Copy link</button></div>" +
        "</div></div>";
      host.appendChild(m);
      try { document.documentElement.classList.add('sf-modal-lock'); } catch(e) {}
      var inp = m.querySelector('.sf-share-copy input');
      function close(){ if(m.parentNode) m.parentNode.removeChild(m); try { document.documentElement.classList.remove('sf-modal-lock'); } catch(e) {} document.removeEventListener('keydown', onKey); }
      function onKey(e){ if(e.key === 'Escape') close(); }
      document.addEventListener('keydown', onKey);
      m.addEventListener('click', function(e){
        var t = e.target.closest ? e.target.closest('[data-sf-share-close],[data-sf-share-copy],[data-sf-share-native],[data-sf-share-popup]') : null;
        if(!t) return;
        if(t.hasAttribute('data-sf-share-close')) { e.preventDefault(); close(); return; }
        if(t.hasAttribute('data-sf-share-copy')) { e.preventDefault(); copyText(url).then(function(ok){ toast(ok ? 'Link copied' : 'Press Ctrl+C to copy'); if(!ok){ inp.focus(); inp.select(); } else close(); }); return; }
        if(t.hasAttribute('data-sf-share-native')) { e.preventDefault(); navigator.share({ title: title || document.title, text: text || '', url: url }).then(close, function(){}); return; }
        if(t.getAttribute('data-sf-share-popup') === '1') {
          e.preventDefault();
          var w = 640, h = 560, l = Math.max(0, (screen.width - w) / 2), tp = Math.max(0, (screen.height - h) / 3);
          // small share window; the forum page itself never navigates away
          var win = null;
          try { win = window.open(t.href, 'sfshare', 'width=' + w + ',height=' + h + ',left=' + l + ',top=' + tp + ',scrollbars=yes,resizable=yes'); } catch(er) { win = null; }
          if(win) { try { win.opener = null; win.focus(); } catch(er) {} }
          else { var a = document.createElement('a'); a.href = t.href; a.target = '_blank'; a.rel = 'noopener'; document.body.appendChild(a); a.click(); a.remove(); }
          close();
        }
      });
      inp.addEventListener('focus', function(){ inp.select(); });
      setTimeout(function(){ var b = m.querySelector('.sf-share-tg'); if(b) b.focus(); }, 30);
    }

    document.addEventListener('click', function(e){
      var t = e.target && e.target.closest ? e.target : null;
      if(!t) return;
      var cp = t.closest('.sf-copylink-btn,[data-sf-copy]');
      if(cp && cp.closest('.sf-wrap')) {
        e.preventDefault();
        var u = absUrl(cp.getAttribute('data-sf-copy') || location.href);
        copyText(u).then(function(ok){ toast(ok ? 'Link copied' : 'Could not copy the link'); });
        var pc = cp.closest('[id^=post-],[id^=reply-]'); if(pc) highlight(pc.id);
        return;
      }
      var pid = t.closest('a.sf-post-id[data-sf-permalink]');
      if(pid && !e.ctrlKey && !e.metaKey && !e.shiftKey && e.button === 0) {
        e.preventDefault();
        var anchor = pid.getAttribute('data-sf-anchor');
        try { history.replaceState(null, '', '#' + anchor); } catch(er) {}
        highlight(anchor);
        copyText(absUrl(pid.getAttribute('data-sf-permalink'))).then(function(ok){ toast(ok ? 'Link to this post copied' : 'Link: ' + pid.getAttribute('data-sf-permalink')); });
        return;
      }
      var sh = t.closest('.sf-share-btn');
      if(sh) {
        e.preventDefault(); e.stopPropagation();
        closeMenus();
        var url = sh.getAttribute('data-share-url') || location.href;
        var title = sh.getAttribute('data-share-title') || document.title;
        var text = sh.getAttribute('data-share-text') || '';
        shareDialog(url, title, text);
      }
    }, true);

    // Report: ask for a reason first (small dialog), then send the report form
    document.addEventListener('click', function(e){
      var rb = e.target && e.target.closest ? e.target.closest('.sf-report-btn') : null;
      if(!rb) return;
      var form = rb.closest('form');
      if(!form) return;
      e.preventDefault(); e.stopPropagation();
      closeMenus();
      var host = document.querySelector('.sf-wrap') || document.body;
      var old = document.getElementById('sfReportModal'); if(old) old.remove();
      var reasons = [['spam','Spam / advertising'],['offensive','Offensive or abusive'],['offtopic','Off-topic / wrong forum'],['illegal','Illegal content / copyright'],['other','Other']];
      var m = document.createElement('div');
      m.className = 'sf-modal sf-report-modal is-open'; m.id = 'sfReportModal';
      m.innerHTML = "<div class='sf-modal-backdrop' data-sf-rep-close='1'></div><div class='sf-modal-dialog' role='dialog' aria-modal='true' aria-labelledby='sfRepTitle'>" +
        "<div class='sf-modal-header'><div class='sf-modal-title' id='sfRepTitle'>Report this post</div><button type='button' class='sf-modal-close' data-sf-rep-close='1' aria-label='Close'>×</button></div>" +
        "<div class='sf-modal-body'><div class='sf-ntm-hint'>Moderators will look at it. Why are you reporting it?</div><div class='sf-rep-reasons'>" +
        reasons.map(function(r, i){ return "<label class='sf-rep-reason'><input type='radio' name='sf_rep_reason' value='" + r[0] + "'" + (i === 0 ? ' checked' : '') + "><span>" + r[1] + "</span></label>"; }).join('') +
        "</div><textarea class='sf-rep-comment' rows='3' maxlength='1000' placeholder='Details (optional)'></textarea>" +
        "<div class='sf-rep-actions'><button type='button' class='btn btn-outline-secondary btn-sm' data-sf-rep-close='1'>Cancel</button><button type='button' class='btn btn-primary btn-sm' data-sf-rep-send='1'>Send report</button></div></div></div>";
      host.appendChild(m);
      try { document.documentElement.classList.add('sf-modal-lock'); } catch(er) {}
      function close(){ m.remove(); try { document.documentElement.classList.remove('sf-modal-lock'); } catch(er) {} }
      m.addEventListener('click', function(ev){
        var t = ev.target.closest ? ev.target.closest('[data-sf-rep-close],[data-sf-rep-send]') : null;
        if(!t) return;
        ev.preventDefault();
        if(t.hasAttribute('data-sf-rep-close')) { close(); return; }
        var reason = (m.querySelector('input[name=sf_rep_reason]:checked') || {}).value || 'other';
        var add = function(n, v){ var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i); };
        add('sf_report_post', rb.value); add('sf_report_reason', reason); add('sf_report_comment', m.querySelector('.sf-rep-comment').value || '');
        t.disabled = true; t.textContent = 'Sending…';
        HTMLFormElement.prototype.submit.call(form);
      });
      document.addEventListener('keydown', function onK(ev){ if(ev.key === 'Escape'){ close(); document.removeEventListener('keydown', onK); } });
    }, true);

    // arriving via a post link: highlight that post
    if(/^#(post|reply)-\d+$/.test(location.hash)) setTimeout(function(){ highlight(location.hash.slice(1)); }, 150);
    window.addEventListener('hashchange', function(){ if(/^#(post|reply)-\d+$/.test(location.hash)) highlight(location.hash.slice(1)); });
  }

  onReady(function(){
    initQuotes();
    initPostLinks();
    initDropdownFallback();
    initSearchScope();
    initGroupCollapse();
    initNewTopicModal();
    initMarkRead();
    initUserCards();
    initTooltips();
  

  // Close/open forum search scope menus safely
  document.querySelectorAll('.sf-csearch-filter__text').forEach(function(sum){
    sum.addEventListener('click', function(ev){ ev.stopPropagation(); });
  });
  document.addEventListener('click', function(ev){
    document.querySelectorAll('details.sf-csearch-filter[open]').forEach(function(det){
      if(!det.contains(ev.target)) det.removeAttribute('open');
    });
  });

// Search scope (<details> filter)
document.querySelectorAll('.sf-csearch').forEach(function(form){
  var hidden = form.querySelector('input[name="sf_scope"]');
  var details = form.querySelector('.sf-csearch-filter');
  var summary = form.querySelector('.sf-csearch-filter__text');
  form.querySelectorAll('input[name="sf_scope_radio"]').forEach(function(r){
    r.addEventListener('change', function(){
      if(hidden) hidden.value = r.value;
      // Update summary text to selected label
      if(summary){
        var lab = r.closest('label');
        if(lab){
          var span = lab.querySelector('.sf-csearch-filter__menuText');
          summary.textContent = span ? span.textContent : lab.textContent.trim();
        }
      }
      // Close details
      if(details) details.removeAttribute('open');
    });
  });
});



// PW-style Quick Search (.sf-pwqs) - supports multiple instances (header + sidebar)
document.querySelectorAll('.sf-pwqs[data-sf-pwqs="1"]').forEach(function(root){
  var input = root.querySelector('.sf-pwqs-field');
  var filter = root.querySelector('.sf-pwqs-filter');
  var summary = filter ? filter.querySelector('summary') : null;
  var summaryText = filter ? filter.querySelector('.sf-pwqs-summaryText') : null;
  var hidden = root.querySelector('input[name="sf_scope"]');
  var radios = filter ? Array.from(filter.querySelectorAll('input[type="radio"][name="sf_scope_radio"]')) : [];
  if(!input || !filter || !summary || !summaryText) return;

  function syncSummary(){
    var checked = radios.find(function(r){ return r.checked; });
    if(!checked) return;
    var lab = checked.closest('label');
    var txt = lab ? (lab.querySelector('.sf-pwqs-menuText') || lab).textContent.trim() : '';
    if(txt) summaryText.textContent = txt;
    if(hidden) hidden.value = checked.value;
  }

  function setExpanded(isOpen){
    summary.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  }
  function closeFilter(){
    filter.open = false;
    setExpanded(false);
  }
  function openFilter(){
    filter.open = true;
    setExpanded(true);
    var checked = radios.find(function(r){ return r.checked; });
    (checked || radios[0] || input).focus({ preventScroll:true });
  }

  syncSummary();
  setExpanded(filter.open);

  summary.addEventListener('click', function(){
    requestAnimationFrame(function(){ setExpanded(filter.open); });
  });

  summary.addEventListener('keydown', function(e){
    if(e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' '){
      e.preventDefault();
      openFilter();
    }
    if(e.key === 'Escape'){
      e.preventDefault();
      closeFilter();
      input.focus({ preventScroll:true });
    }
  });

  radios.forEach(function(radio){
    radio.addEventListener('change', function(){
      syncSummary();
      closeFilter();
      input.focus({ preventScroll:true });
    });
    radio.addEventListener('keydown', function(e){
      if(e.key === 'Escape'){
        e.preventDefault();
        closeFilter();
        summary.focus({ preventScroll:true });
      }
    });
  });

  document.addEventListener('mousedown', function(e){
    if(!filter.open) return;
    if(!root.contains(e.target)) closeFilter();
  });
  document.addEventListener('focusin', function(e){
    if(!filter.open) return;
    if(!root.contains(e.target)) closeFilter();
  });

  root.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && filter.open){
      e.preventDefault();
      closeFilter();
      input.focus({ preventScroll:true });
    }
  });
});

});
})();


/* =========================================================
   NativeForum: PW-style Quick Search (EXACT) init
   Supports multiple instances (header + sidebar) on same page.
   ========================================================= */
(function () {
  function initOne(form) {
    const input = form.querySelector('.sfqs-field');
    const filter = form.querySelector('.cSearchFilter');
    const summary = filter ? filter.querySelector('summary') : null;
    const summaryText = filter ? filter.querySelector('.cSearchFilter__summaryText') : null;
    const radios = filter ? Array.from(filter.querySelectorAll('input[type="radio"]')) : [];
    const hiddenScope = form.querySelector('input.sf-csearch-scopeval');

    if(!input || !filter || !summary || !summaryText) return;

    function syncSummaryFromChecked() {
      const checked = radios.find(r => r.checked);
      if(!checked) return;
      const label = checked.closest('label');
      const text = label && label.querySelector('.cSearchFilter__menuText') ? label.querySelector('.cSearchFilter__menuText').textContent.trim() : '';
      if(text) summaryText.textContent = text;
      if(hiddenScope) hiddenScope.value = checked.value;
    }

    function setExpanded(isOpen) {
      summary.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    function closeFilter() {
      filter.open = false;
      setExpanded(false);
    }

    function openFilter() {
      filter.open = true;
      setExpanded(true);
      const checked = radios.find(r => r.checked);
      (checked || radios[0]) && (checked || radios[0]).focus({ preventScroll: true });
    }

    syncSummaryFromChecked();
    setExpanded(filter.open);

    summary.addEventListener('click', () => {
      requestAnimationFrame(() => setExpanded(filter.open));
    });

    summary.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openFilter();
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        closeFilter();
        input.focus({ preventScroll: true });
      }
    });

    radios.forEach(radio => {
      radio.addEventListener('change', () => {
        syncSummaryFromChecked();
        closeFilter();
        input.focus({ preventScroll: true });
      });
      radio.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          e.preventDefault();
          closeFilter();
          summary.focus({ preventScroll: true });
        }
      });
    });

    document.addEventListener('mousedown', (e) => {
      if(!filter.open) return;
      if(!form.contains(e.target)) closeFilter();
    });

    document.addEventListener('focusin', (e) => {
      if(!filter.open) return;
      if(!form.contains(e.target)) closeFilter();
    });

    form.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && filter.open) {
        e.preventDefault();
        closeFilter();
        input.focus({ preventScroll: true });
      }
    });
  }

  function initAll() {
    document.querySelectorAll('form.sf-csearch.sfqs[data-sfqs="1"]').forEach(initOne);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();

/* Readability guard (1.0.5): if a host theme or a Design Studio colour makes forum text
   unreadable on its own background (e.g. a dark label colour on a dark preset), switch that
   element to a readable colour. Only touches elements whose contrast is really broken (< 2:1). */
(function(){
  if(window.__SF_CONTRAST__) return; window.__SF_CONTRAST__ = 1;
  var SEL = 'label,.form-label,.sf-label,legend,.form-text,.sf-help,.form-check-label,small,.small,.sf-muted,.sf-muted-sm,.text-muted,'
    + 'h1,h2,h3,h4,h5,h6,.sf-card-title,.card-title,.sf-dialog-title,.modal-title,p,th,td,dt,dd,li,a,span,strong,b,em,button,.badge,.nav-link,'
    + 'input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):not([type=file]),select,textarea';
  function rgb(s){ var m = String(s||'').match(/[\d.]+/g); if(!m) return null; m = m.map(Number); if(m.length < 4) m[3] = 1; return m; }
  function lum(c){ var a = [c[0],c[1],c[2]].map(function(v){ v/=255; return v <= .03928 ? v/12.92 : Math.pow((v+.055)/1.055, 2.4); }); return .2126*a[0] + .7152*a[1] + .0722*a[2]; }
  function ratio(a, b){ var x = lum(a), y = lum(b); return (Math.max(x,y)+.05)/(Math.min(x,y)+.05); }
  function bgOf(el){
    while(el && el.nodeType === 1){
      var cs = getComputedStyle(el), c = rgb(cs.backgroundColor);
      if(c && c[3] > .5) return c;
      if(cs.backgroundImage && cs.backgroundImage !== 'none' && el !== document.body && el !== document.documentElement) return null; // image/gradient: cannot judge
      el = el.parentElement;
    }
    return rgb(getComputedStyle(document.body).backgroundColor) || [255,255,255,1];
  }
  function hasText(el){
    if(/^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName)) return true;
    for(var n = el.firstChild; n; n = n.nextSibling) if(n.nodeType === 3 && n.textContent.trim()) return true;
    return false;
  }
  function fix(el, bg){
    // Prefer the colour of the nearest ancestor that reads well on this background (the card/dialog text colour).
    for(var p = el.parentElement; p && p.nodeType === 1; p = p.parentElement){
      var c = rgb(getComputedStyle(p).color);
      if(c && ratio(c, bg) >= 4.5){ el.style.setProperty('color', 'rgb(' + c[0] + ',' + c[1] + ',' + c[2] + ')', 'important'); return; }
    }
    el.style.setProperty('color', lum(bg) > .4 ? '#1f2328' : '#e6e8eb', 'important');
  }
  function scan(root){
    var list; try { list = (root || document).querySelectorAll(SEL); } catch(e) { return; }
    var n = Math.min(list.length, 6000);
    for(var i = 0; i < n; i++){
      var el = list[i];
      if(el.dataset && el.dataset.sfCg) continue;
      if(!el.closest('.sf-wrap,.sf-dialog,.sf-modal,.modal,.sf-usercard')) continue;
      if(el.closest('.cke,.tox,.mce-content-body,[contenteditable=true]')) continue;
      if(!el.offsetWidth && !el.offsetHeight) continue;
      if(!hasText(el)) continue;
      var cs = getComputedStyle(el);
      if(cs.visibility === 'hidden' || parseFloat(cs.opacity) < .15) continue;
      var fg = rgb(cs.color), bg = bgOf(el);
      if(!fg || !bg || fg[3] < .3) continue;
      if(ratio(fg, bg) < 2){ fix(el, bg); el.dataset.sfCg = '1'; }
    }
  }
  function run(root){ try { scan(root); } catch(e) {} }
  function start(){
    run(document);
    setTimeout(function(){ run(document); }, 700);
    // dialogs / modals become visible later: check them when they open
    if(window.MutationObserver){
      new MutationObserver(function(ms){
        ms.forEach(function(m){
          var t = m.target;
          if(t && t.nodeType === 1 && (t.hasAttribute('open') || (t.classList && t.classList.contains('show')))) setTimeout(function(){ run(t); }, 60);
        });
      }).observe(document.documentElement, { subtree:true, attributes:true, attributeFilter:['open','class'] });
    }
  }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
