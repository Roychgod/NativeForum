(function() {
  /**
   * NativeForum Lightbox
   * - Built-in lightbox works out of the box (no external libs required)
   * - If Fancybox is present, will use it automatically for forum images
   * - If GLightbox is present and enabled, will use it
   *
   * This script intentionally targets only links/images inside .sf-wrap.
   */
  function hasFancybox() {
    try { return !!(window.Fancybox && typeof window.Fancybox.bind === 'function'); } catch(e) { return false; }
  }
  function hasGLightbox() {
    try { return !!window.GLightbox; } catch(e) { return false; }
  }

  // If Fancybox exists, bind it for forum image links.
  if(hasFancybox()) {
    try {
      // Bind any anchor in forum posts that links to an image.
      window.Fancybox.bind('.sf-wrap a[href$=".jpg"],.sf-wrap a[href$=".jpeg"],.sf-wrap a[href$=".png"],.sf-wrap a[href$=".gif"],.sf-wrap a[href$=".webp"],.sf-wrap a[href$=".avif"],.sf-wrap a[href$=".svg"]', {
        groupAll : false
      });
    } catch(e) {}
    return;
  }

  // If GLightbox exists, initialize it for forum image links (non-invasive).
  if(hasGLightbox()) {
    try {
      if(!window.__sfGlightboxInited) {
        window.__sfGlightboxInited = true;
        window.GLightbox({
          selector: '.sf-wrap a[href$=".jpg"],.sf-wrap a[href$=".jpeg"],.sf-wrap a[href$=".png"],.sf-wrap a[href$=".gif"],.sf-wrap a[href$=".webp"],.sf-wrap a[href$=".avif"],.sf-wrap a[href$=".svg"]'
        });
      }
    } catch(e) {}
    return;
  }

  // Built-in overlay lightbox (fallback)
  function ensureOverlay() {
    var ov = document.getElementById('sf-lightbox');
    if(ov) return ov;

    ov = document.createElement('div');
    ov.id = 'sf-lightbox';
    ov.innerHTML = "<div class='sf-lightbox-backdrop'></div><div class='sf-lightbox-inner'><img class='sf-lightbox-img' alt=''></div>";
    document.body.appendChild(ov);

    function close() { ov.classList.remove('is-open'); }
    ov.addEventListener('click', close);
    document.addEventListener('keydown', function(e){
      if(e.key === 'Escape') close();
    });

    return ov;
  }

  function open(src) {
    var ov = ensureOverlay();
    var img = ov.querySelector('.sf-lightbox-img');
    img.src = src;
    ov.classList.add('is-open');
  }

  function isImageUrl(url) {
    if(!url) return false;
    url = String(url).split('?')[0].split('#')[0];
    return (/\.(jpe?g|png|gif|webp|avif|svg)$/i).test(url);
  }

  document.addEventListener('click', function(e){
    // Only inside NativeForum
    var wrap = e.target.closest && e.target.closest('.sf-wrap');
    if(!wrap) return;

    // Do not interfere with explicit lightbox libs
    var a = e.target.closest && e.target.closest('a');
    if(!a) {
      var img = e.target.closest && e.target.closest('img');
      if(img && img.getAttribute) {
        var src = img.getAttribute('src') || '';
        if(isImageUrl(src)) { e.preventDefault(); open(src); }
      }
      return;
    }
    if(a.hasAttribute('data-fancybox')) return;
    if((a.getAttribute('class') || '').match(/\bglightbox\b/i)) return;

    var href = a.getAttribute('href') || '';
    // Many editors embed <img> without a link; handle image click too.
    // If it's an <img> wrapped in <a> -> use href. Otherwise ignore.
    if(!isImageUrl(href)) return;

    // avoid hijacking downloads/attachments that are not images
    e.preventDefault();
    open(href);
  });
})();