<?php namespace ProcessWire;

/**
 * Sanitize a CSS color value while keeping support for transparency and modern formats.
 * Allows: hex (#RGB/#RGBA/#RRGGBB/#RRGGBBAA), rgb()/rgba(), hsl()/hsla(),
 * CSS variables (var(--x)), and common keywords like transparent/currentColor.
 * Blocks obvious CSS injection characters.
 */
if(!function_exists(__NAMESPACE__ . '\\sf_sanitize_css_color')) {
  function sf_sanitize_css_color($value, $fallback = '') {
    $v = trim((string) $value);
    if($v === '') return $fallback;
    // block obvious injection vectors
    if(preg_match('/[;{}<>]/', $v)) return $fallback;

    // keywords
    $kw = strtolower($v);
    $allowedKw = [
      'transparent','currentcolor','inherit','initial','unset','revert','revert-layer'
    ];
    if(in_array($kw, $allowedKw, true)) return $v;

    // CSS variable
    if(preg_match('/^var\(\s*--[a-z0-9_-]+\s*(?:,\s*[^)]+)?\)$/i', $v)) return $v;

    // hex (#RGB/#RGBA/#RRGGBB/#RRGGBBAA)
    if(preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v)) return $v;

    // rgb/rgba/hsl/hsla (keep permissive but safe-ish)
    if(preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[-0-9.%\s,\/]+\)$/i', $v)) return $v;

    return $fallback;
  }
}

class NativeForum extends WireData implements Module, ConfigurableModule {

  /**
   * Prevent recursive tree-preset saves (saveConfig inside saveConfig hook).
   */
  protected bool $sfApplyingTreePreset = false;

  public static function getModuleInfo() {
    return [
      'title'    => 'Native Forum',
      'version'  => 107,
      'summary'  => 'A complete community forum for ProcessWire: topics, replies, private messages, moderation, anti-spam, SEO/GEO and ready-made designs.',
      'author'   => 'Gregor Rojko (Pyxios)',
      'icon'     => 'comments',
      'installs' => ['ProcessNativeForum'], // installing Native Forum also installs its admin
      'autoload' => true, // autoload needed for reliable AJAX uploads
      'singular' => true,
      'requires' => 'ProcessWire>=3.0.200',
    ];
  }
  /**
   * Autoload hook: catch attachment uploads before any template output.
   */
  public function init() {
    // Code/templates written for the module's former name keep working
    $this->wire('modules')->setSubstitutes(['Simple' . 'Forum' => 'NativeForum', 'ProcessSimple' . 'Forum' => 'ProcessNativeForum']);
    // NOTE: init() runs on EVERY request (front + admin). Keep it cheap:
    // no filesystem writes, no field/template creation (that lives in install/upgrade).
    $this->ensurePurifierCachePath();

    // Apply forum tree preset on settings save
    $this->addHookAfter('Modules::saveConfig', $this, 'hookAfterSaveConfig');
    $this->addHookBefore('Modules::saveConfig', $this, 'hookBeforeSaveConfig');

    // Front-end: upload endpoint, layout mode, SEO/GEO head, feeds, sitemap, llms.txt
    $this->addHookBefore('Page::render', $this, 'hookBeforePageRender');
    $this->addHookAfter('Page::render', $this, 'hookAfterPageRender', ['priority' => 200]);
    $this->addHookBefore('TemplateFile::render', $this, 'hookBeforeTemplateFileRender');

    // Keep reply counters / last activity fresh + clean up on delete
    $this->addHookAfter('Pages::saved', $this, 'hookPageSaved');
    // members imported from another site: their old password works once, then it is re-hashed for this site
    $this->addHookAfter('Session::authenticate', $this, 'hookLegacyPassword');
    $this->addHookAfter('Pages::deleted', $this, 'hookPageDeleted');

    $this->registerUrlHooks();
  }

  /**
   * Boolean setting that defaults to ON when never saved.
   */
  public function cfgOn(string $key, bool $default = true): bool {
    $v = $this->get($key);
    if($v === null || $v === '') return $default;
    return (int) $v === 1;
  }

  /**
   * Is this page rendered by one of our front-end templates?
   */
  public function isForumPage($page): bool {
    if(!$page instanceof Page || !$page->id || !$page->template) return false;
    return in_array((string) $page->template->name, $this->sfForumTemplateNames(), true);
  }

  /**
   * Layout mode: 'standalone' = forum renders its own full HTML document (default, works everywhere),
   * 'site' = forum output is wrapped by the site's _main.php (delayed output / markup regions).
   */
  public function layoutMode(): string {
    $m = (string) $this->get('layoutMode');
    if($m === 'standalone') return 'standalone';
    // 'site' or 'auto' (default): use the site's own layout (_main.php) when there is one.
    $config = $this->wire('config');
    $main = (string) $config->appendTemplateFile;
    if($main === '' || !is_file($config->paths->templates . $main)) return 'standalone';
    return 'site';
  }

  /**
   * Before a forum page renders: AJAX upload endpoint, markdown view, layout switches.
   */
  public function hookBeforePageRender(HookEvent $event) {
    $page = $event->object;
    if(!$this->isForumPage($page)) return;
    // Only the main request page (not $page->render() of other pages inside templates)
    if($page !== $this->wire('page')) return;

    $input = $this->wire('input');

    // 0a) Topic view counter (beacon/pixel): answered before any template/_init.php runs
    if($page->template->name === 'forum-topic' && (int) $input->get('sf_view') === 1) {
      $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
      if($ua !== '' && !preg_match('~bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless~i', $ua)) {
        $session = $this->wire('session');
        $k = 'sf_topic_view_' . (int) $page->id;
        $last = (int) $session->get($k);
        if(!$last || (time() - $last) > 3600) { $session->set($k, time()); $this->bumpTopicViews($page); }
      }
      header('Content-Type: image/gif');
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
      header('X-Robots-Tag: noindex');
      echo "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
      exit;
    }

    // 0) Anti-spam form token (JSON, never cached). Forms on cacheable pages (ProCache) carry no
    //    session data; the browser fetches a fresh token when the visitor starts using the form.
    if($input->get('sf_token')) {
      $this->respondFormToken((string) $input->get('sf_token'));
    }

    // 1) Attachment upload endpoint (JSON)
    if((int) $input->post('sf_upload_attachment') === 1) {
      $this->respondUpload();
    }

    // 2) GEO: clean markdown version of a topic for AI crawlers / LLM tools  (?format=md)
    if($page->template->name === 'forum-topic' && $input->get('format') === 'md' && $this->cfgOn('seoMarkdown')) {
      if($this->canReadForum($this->wire('user'), $page) && $page->isPublic()) {
        $event->replace = true;
        $this->sfRawOutput = true;
        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Robots-Tag: noindex');
        $event->return = $this->seo()->topicMarkdown($page);
        return;
      }
    }

    // 2b) E-mail confirmation link (forum-register?verify=TOKEN)
    if($page->template->name === 'forum-register' && $input->get('verify')) {
      list($u, $ret, $err) = $this->confirmRegistration((string) $input->get('verify'));
      $session = $this->wire('session');
      if($u) {
        $dest = $ret && strpos($ret, '/') === 0 && strpos($ret, '//') !== 0 ? $ret : $this->forumRoot()->url;
        $session->setFor('NativeForum', 'flash', 'welcome');
        $session->redirect($dest, false);
      }
      $session->redirect($page->url . '?verify_status=' . $err, false);
    }

    // 3) Replies have no page of their own: 301 to the post inside its topic
    if($page->template->name === 'forum-reply') {
      $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
      if(preg_match('~facebookexternalhit|facebookcatalog|Facebot|Twitterbot|LinkedInBot|Slackbot|Discordbot|TelegramBot|WhatsApp|Viber|SkypeUriPreview|Pinterest|redditbot|Applebot|vkShare|Embedly|Iframely~i', $ua)
        && $this->canReadForum($this->wire('user'), $page->parent)) {
        // link preview card for this reply (title, the reply's text, image)
        // sent directly: never stored by ProCache/other page caches (it must always show the current post)
        $html = $this->seo()->replySharePage($page, $this->replyUrl($page, true));
        while(ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
        echo $html;
        exit;
      }
      $this->wire('session')->redirect($this->replyUrl($page), true);
    }

    // 4) Standalone layout: do not let the site's _init/_main wrap the forum.
    // Runtime only (never saved): the forum template renders alone; in "site" mode the
    // site's _main.php is rendered afterwards around it (see hookAfterPageRender).
    $page->template->noAppendTemplateFile = 1;
    $page->template->noPrependTemplateFile = 1; // _init.php runs once, together with _main.php
  }

  /**
   * Inject fallback closures into forum-root.php scope for old site copies (prevents fatals).
   */
  public function hookBeforeTemplateFileRender(HookEvent $event) {
    $tf = $event->object;
    if(!$tf || basename((string) $tf->filename) !== 'forum-root.php') return;
    $vars = $event->arguments(0);
    if(!is_array($vars)) $vars = [];
    if(!isset($vars['renderBlock']) || !is_callable($vars['renderBlock'])) {
      $sanitizer = $this->wire('sanitizer');
      $vars['renderBlock'] = function($title, $cats, $isVipGroup = false) use ($sanitizer) {
        echo "<div class='sf-panel sf-group'><div class='sf-card'><div class='sf-card-h'>" . $sanitizer->entities((string) $title) . "</div><div class='sf-card-b'>";
        if($cats) foreach($cats as $cat) echo "<div class='sf-cat'><a class='sf-link-title' href='{$cat->url}'>" . $sanitizer->entities((string) $cat->title) . "</a></div>";
        echo "</div></div></div>";
      };
      $event->setArgument(0, $vars);
    }
  }

  /**
   * After render: build a complete HTML document (standalone) and inject SEO/GEO head tags.
   */
  public function hookAfterPageRender(HookEvent $event) {
    $page = $event->object;
    if(!$this->isForumPage($page) || $page !== $this->wire('page')) return;
    if($this->sfRawOutput) return;
    $html = (string) $event->return;
    if($html === '' || strpos(ltrim($html), '{') === 0) return; // JSON responses
    try { $html = $this->applyTextOverrides($html); } catch(\Throwable $e) {}
    if($this->layoutMode() === 'site' && stripos($html, '<html') === false) {
      $html = $this->wrapInSiteLayout($page, $html);
    }
    if($html === '' || strpos(ltrim($html), '{') === 0) return; // JSON responses
    $ct = (string) $page->template->contentType;
    if($ct !== '' && stripos($ct, 'html') === false) return;
    // One-time welcome message after confirming the e-mail
    try {
      $flash = $this->wire('session')->getFor('NativeForum', 'flash');
      if($flash === 'welcome') {
        $this->wire('session')->removeFor('NativeForum', 'flash');
        $name = $this->wire('sanitizer')->entities($this->displayName($this->wire('user')));
        $alert = "<div class='sf-alert sf-alert-success sf-flash' role='status'>Welcome, <strong>{$name}</strong>! Your account is confirmed and you are logged in.</div>";
        $html = preg_replace("~(class=['\"]sf-container['\"][^>]*>)~", '$1' . str_replace('$', '\\$', $alert), $html, 1);
      }
    } catch(\Throwable $e) {}
    // Bootstrap theme on a site without Bootstrap ("Auto" mode): load Bootstrap 5 so the forum looks right
    try { $html = $this->ensureBootstrapAssets($html); } catch(\Throwable $e) {}
    // No site loader on forum pages: hide it from the very first paint (in <head>)
    if((int) $this->get('disableSitePreloaderOnForum')) {
      $pre = $this->preloaderBypassHtml();
      if($pre !== '') {
        if(preg_match('~<head\b[^>]*>~i', $html)) $html = preg_replace('~(<head\b[^>]*>)~i', '$1' . str_replace('$', '\\$', $pre), $html, 1);
        else $html = $pre . $html;
      }
    }
    try {
      $html = $this->seo()->processDocument($page, $html, $this->layoutMode());
    } catch(\Throwable $e) {
      $this->wire('log')->save('native-forum', 'SEO: ' . $e->getMessage());
    }
    // Server timing (visible in the browser's DevTools → Network → Timing)
    $ms = isset($_SERVER['REQUEST_TIME_FLOAT']) ? round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000) : 0;
    if($ms && !headers_sent()) header('Server-Timing: sf;desc="Native Forum page";dur=' . $ms, false);
    if($ms && $this->wire('user')->isSuperuser()) $html .= "\n<!-- NativeForum: page built in {$ms} ms -->";
    $event->return = $html;
  }

  /**
   * Bootstrap theme, integration "Auto": when the page loads no Bootstrap stylesheet of its own,
   * add Bootstrap 5 (CSS at the top of <head>, so site and forum styles still win; JS bundle at the end).
   * Pages that already include Bootstrap (any version) are left alone. "Off" never loads anything.
   */
  protected function ensureBootstrapAssets(string $html): string {
    if((string) $this->get('frontendTheme') !== 'bootstrap') return $html;
    $mode = (string) $this->get('bootstrapIntegration');
    if($mode === '') $mode = ((int) $this->get('bootstrapAutoLoad')) ? 'force' : 'auto';
    if($mode !== 'auto') return $html;
    if(stripos($html, '<head') === false) return $html;
    $cssUrl = trim((string) ($this->get('bootstrapCssUrl') ?: 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'));
    $jsUrl  = trim((string) ($this->get('bootstrapJsUrl') ?: 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'));
    $isOurs = function($u) { return stripos($u, 'native-forum-bootstrap') !== false || stripos($u, 'sf-bs-compat') !== false || stripos($u, '/NativeForum/') !== false; };
    $hasCss = false;
    if(preg_match_all('~<link\b[^>]*href=["\']([^"\']*bootstrap[^"\']*\.css[^"\']*)~i', $html, $mm)) {
      foreach($mm[1] as $u) if(!$isOurs($u)) { $hasCss = true; break; }
    }
    if(!$hasCss && $cssUrl !== '') {
      $tag = "<link rel='stylesheet' id='sf-bootstrap-css' href='" . htmlspecialchars($cssUrl, ENT_QUOTES) . "' />";
      if(preg_match('~<head\b[^>]*>(\s*<meta\b[^>]*>)*~i', $html)) $html = preg_replace('~(<head\b[^>]*>(\s*<meta\b[^>]*>)*)~i', '$1' . str_replace('$', '\\$', $tag), $html, 1);
    }
    $hasJs = false;
    if(preg_match_all('~<script\b[^>]*src=["\']([^"\']*bootstrap[^"\']*\.js[^"\']*)~i', $html, $mm)) {
      foreach($mm[1] as $u) if(!$isOurs($u)) { $hasJs = true; break; }
    }
    if(!$hasJs && !$hasCss && $jsUrl !== '') {
      $tag = "<script src='" . htmlspecialchars($jsUrl, ENT_QUOTES) . "' defer></script>";
      $pos = strripos($html, '</body>');
      $html = $pos !== false ? substr($html, 0, $pos) . $tag . substr($html, $pos) : $html . $tag;
    }
    return $html;
  }

  /** CSS + JS that hide/remove the site's loader on forum pages. */
  protected function preloaderBypassHtml(): string {
    $raw = (string) ($this->get('preloaderHideSelectors') ?? '');
    if(trim($raw) === '') $raw = "#preloader\n.preloader\n.page-loader\n#loader-wrapper";
    $sels = [];
    foreach(preg_split('/[\r\n,]+/', $raw) as $l) {
      $l = trim($l);
      if($l === '' || preg_match('~[{}<>;]~', $l)) continue;
      if(preg_match('~^[A-Za-z][\w-]*$~', $l)) { $sels[] = '#' . $l; $sels[] = '.' . $l; } // bare name: id or class
      else $sels[] = $l;
    }
    if(!$sels) return '';
    $css = implode(',', $sels);
    $js = json_encode(array_values(array_unique($sels)));
    return "<style id='sf-no-loader'>{$css}{display:none!important;opacity:0!important;visibility:hidden!important;pointer-events:none!important}</style>"
      . "<script>window.SF_DISABLE_SITE_PRELOADER=true;document.documentElement.classList.add('sf-disable-preloader');(function(){var s={$js};"
      . "function k(){s.forEach(function(q){try{document.querySelectorAll(q).forEach(function(n){n.parentNode&&n.parentNode.removeChild(n);});}catch(e){}});"
      . "var b=document.body;if(b&&getComputedStyle(b).overflow==='hidden'&&!document.querySelector('dialog[open]'))b.style.overflow='';}"
      . "document.addEventListener('DOMContentLoaded',function(){k();try{var o=new MutationObserver(k);o.observe(document.body,{childList:true});setTimeout(function(){o.disconnect();},4000);}catch(e){}});window.addEventListener('load',k);})();</script>";
  }

  /**
   * Render the site's own layout around the forum, exactly like ProcessWire would:
   * _init.php (prepend) + a tiny stub template + _main.php (append), in ONE scope.
   * Works with Markup Regions and with delayed output ($content). The forum markup is
   * never parsed by Markup Regions and never touches the site's variables/functions;
   * it is inserted at the marker afterwards.
   */
  protected function wrapInSiteLayout(Page $page, string $forumHtml): string {
    $config = $this->wire('config');
    $marker = 'SFFORUMOUTPUT9c41x';
    // Let CSS know the forum lives inside the site's layout (blend: transparent background, inherited font)
    $forumHtml = preg_replace("~class=(['\"])sf-wrap ~", 'class=$1sf-wrap sf-in-site' . (trim((string) $this->get('designForumBg')) === '' && trim((string) $this->get('ds_global_colors_bg')) === '' && !(int) $this->get('designForumBgTransparent') ? ' sf-blend' : '') . ' ', $forumHtml, 1);
    // Inside the site's layout the forum often sits in a narrower column than the screen: switch its own
    // mobile layout by the forum's width (sf-container.css + a tiny width observer right after the markup).
    $cqFile = __DIR__ . '/assets/css/sf-container.css';
    if(is_file($cqFile)) {
      $cqUrl = $config->urls($this) . 'assets/css/sf-container.css?v=' . (int) @filemtime($cqFile);
      $bp = [];
      if(preg_match('~Breakpoints: (\[[\d, ]*\])~', (string) @file_get_contents($cqFile, false, null, 0, 600), $bm)) $bp = (array) json_decode($bm[1], true);
      if($bp) {
        $forumHtml = "<link rel='stylesheet' href='" . $this->wire('sanitizer')->entities($cqUrl) . "' />" . $forumHtml
          . "<script>(function(){var B=" . json_encode(array_values(array_map('intval', $bp))) . ";"
          . "function f(w){var x=w.getBoundingClientRect().width;if(!x)return;B.forEach(function(n){w.classList.toggle('sf-cq-'+n,x<=Math.round(n*0.8));});}"
          . "document.querySelectorAll('.sf-wrap.sf-in-site').forEach(function(w){f(w);if(window.ResizeObserver)new ResizeObserver(function(){f(w);}).observe(w);else window.addEventListener('resize',function(){f(w);});});})();</script>";
      }
    }
    $rid = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($this->get('siteRegionId') ?: 'content')) ?: 'content';
    $var = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($this->get('siteContentVar') ?: 'content')) ?: 'content';

    // Layouts that print the page body directly ($page->body) get the marker there too.
    $bodyField = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($this->get('siteBodyField') ?: 'body')) ?: 'body';
    $prevBody = $page->getUnformatted($bodyField);
    $page->setQuietly($bodyField, $marker);

    // The site's _init.php/_main.php use paths relative to /site/templates/ (e.g. include("./_func.php")),
    // exactly like ProcessWire renders them: run them from that directory. Any partial output of a
    // failing layout is discarded so it never leaks into the page.
    $obLevel = ob_get_level();
    $cwd = getcwd();
    try {
      $tf = new TemplateFile(__DIR__ . '/template-files/sf-site-stub.php');
      $this->wire($tf);
      $tpl = $config->paths->templates;
      if(method_exists($tf, 'setChdir')) $tf->setChdir(rtrim($tpl, '/'));
      @chdir($tpl);
      if($config->prependTemplateFile && is_file($tpl . $config->prependTemplateFile)) $tf->setPrependFilename($tpl . $config->prependTemplateFile);
      $tf->setAppendFilename($tpl . $config->appendTemplateFile);
      $tf->set('page', $page);
      $tf->set('sfMarker', $marker);
      $tf->set('sfRegionId', $rid);
      $tf->set('sfContentVar', $var);
      $tf->set('sfRegions', (bool) $config->useMarkupRegions);
      $tf->setThrowExceptions(true);
      $doc = (string) $tf->render();
      if($cwd) @chdir($cwd);
    } catch(\Throwable $e) {
      while(ob_get_level() > $obLevel) @ob_end_clean();
      if($cwd) @chdir($cwd);
      $page->setQuietly($bodyField, $prevBody);
      $this->wire('log')->save('native-forum', 'site layout: ' . $e->getMessage());
      return $forumHtml;
    }
    $page->setQuietly($bodyField, $prevBody);

    if($config->useMarkupRegions) {
      $pos = stripos($doc, '<!DOCTYPE');
      if($pos === false) $pos = stripos($doc, '<html');
      if($pos !== false && $pos > 0) {
        $before = substr($doc, 0, $pos);
        $doc = substr($doc, $pos);
        $mr = new WireMarkupRegions();
        $this->wire($mr);
        $mr->populate($doc, $before, ['useClassActions' => $config->installed < 1498132609]);
      }
    }
    if(stripos($doc, '<html') === false) return $forumHtml;
    return $this->placeForumInDocument($doc, $forumHtml, $marker, $rid);
  }

  /**
   * Put the forum where the page content belongs:
   * 1) where the layout printed the marker ($content / $page->body / region) — inside <body> only
   * 2) inside the content region (configured id, then common ids, then <main>)
   * 3) between </header> and <footer>
   * Never above the site's header.
   */
  protected function placeForumInDocument(string $doc, string $forumHtml, string $marker, string $rid): string {
    $bodyPos = stripos($doc, '<body');
    $bodyPos = $bodyPos === false ? 0 : $bodyPos;
    $head = substr($doc, 0, $bodyPos);
    $body = substr($doc, $bodyPos);
    // Marker may also end up in <title>/meta (layouts that excerpt $page->body): remove it there.
    $head = str_replace($marker, '', $head);

    $p = strpos($body, $marker);
    if($p !== false) {
      // Use the first occurrence that is not inside an HTML attribute/tag
      $ok = false;
      while($p !== false) {
        $lt = strrpos(substr($body, 0, $p), '<');
        $gt = strrpos(substr($body, 0, $p), '>');
        if($lt === false || ($gt !== false && $gt > $lt)) { $ok = true; break; }
        $p = strpos($body, $marker, $p + 1);
      }
      if($ok) {
        $body = substr($body, 0, $p) . $forumHtml . substr($body, $p + strlen($marker));
        return $head . str_replace($marker, '', $body);
      }
    }
    $body = str_replace($marker, '', $body);

    // Content container by id (configured first), then the <main> element
    $ids = array_unique([$rid, 'content', 'main', 'main-content', 'maincontent', 'page-content', 'primary', 'site-content', 'body-content']);
    foreach($ids as $id) {
      if(preg_match('~<([a-z][a-z0-9]*)\b[^>]*\bid=["\']' . preg_quote($id, '~') . '["\'][^>]*>~i', $body, $m, PREG_OFFSET_CAPTURE)) {
        $res = $this->replaceElementInner($body, (int) $m[0][1], strtolower($m[1][0]), strlen($m[0][0]), $forumHtml);
        if($res !== null) return $head . $res;
      }
    }
    if(preg_match('~<main\b[^>]*>~i', $body, $m, PREG_OFFSET_CAPTURE)) {
      $res = $this->replaceElementInner($body, (int) $m[0][1], 'main', strlen($m[0][0]), $forumHtml);
      if($res !== null) return $head . $res;
    }
    // Between header and footer
    $hp = stripos($body, '</header>');
    if($hp !== false) {
      $hp += 9;
      return $head . substr($body, 0, $hp) . $forumHtml . substr($body, $hp);
    }
    $fp = stripos($body, '<footer');
    if($fp !== false) return $head . substr($body, 0, $fp) . $forumHtml . substr($body, $fp);
    return $head . preg_replace_callback('~<body\b[^>]*>~i', function($m) use ($forumHtml) { return $m[0] . $forumHtml; }, $body, 1);
  }

  /**
   * Replace the inner HTML of the element whose opening tag starts at $openPos.
   * Returns null when the closing tag cannot be found.
   */
  protected function replaceElementInner(string $html, int $openPos, string $tag, int $openLen, string $inner): ?string {
    $start = $openPos + $openLen;
    $depth = 1;
    $offset = $start;
    $re = '~<(/?)' . preg_quote($tag, '~') . '\b[^>]*>~i';
    while($depth > 0 && preg_match($re, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
      $depth += $m[1][0] === '/' ? -1 : 1;
      $offset = $m[0][1] + strlen($m[0][0]);
      if($depth === 0) {
        return substr($html, 0, $start) . $inner . substr($html, $m[0][1]);
      }
    }
    return null;
  }

  /**
   * URL hooks: {forum}/sitemap.xml, {forum}/feed.xml (RSS), /llms.txt (GEO)
   */
  protected function registerUrlHooks(): void {
    if(!method_exists($this->wire(), 'addHook')) return;
    $self = $this;
    $this->wire()->addHook('/(.*)/sitemap\.xml', function($e) use ($self) {
      return $self->seo()->routeIfForum((string) $e->arguments(1), 'sitemap');
    });
    $this->wire()->addHook('/(.*)/feed\.xml', function($e) use ($self) {
      return $self->seo()->routeIfForum((string) $e->arguments(1), 'feed');
    });
    if($this->cfgOn('seoLlmsTxt')) {
      $this->wire()->addHook('/llms\.txt', function($e) use ($self) {
        return $self->seo()->llmsTxt();
      });
    }
  }

  /** @var string|null Forum output held back from Markup Regions */
  protected $sfStash = null;

  /** @var bool Output is not HTML (markdown etc.) */
  protected $sfRawOutput = false;

  /** true while a bulk import runs (skips per-save cache clearing) */
  public $sfBulk = false;
  /** @var NativeForumTransfer|null */
  protected $sfTransfer = null;
  public function transfer(): NativeForumTransfer {
    if(!$this->sfTransfer) {
      require_once __DIR__ . '/NativeForumTransfer.php';
      $this->sfTransfer = new NativeForumTransfer($this);
      $this->wire($this->sfTransfer);
    }
    return $this->sfTransfer;
  }

  /** @var NativeForumSeo|null */
  protected $sfSeo = null;

  public function seo(): NativeForumSeo {
    if(!$this->sfSeo) {
      require_once __DIR__ . '/NativeForumSeo.php';
      $this->sfSeo = new NativeForumSeo($this);
      $this->wire($this->sfSeo);
    }
    return $this->sfSeo;
  }

  /** Per-request memo for the batched helpers below. */
  protected $sfMemo = ['ustats' => [], 'tstats' => [], 'rurl' => [], 'users' => []];

  protected function sfTplId(string $name): int {
    static $ids = [];
    if(!isset($ids[$name])) {
      $t = $this->wire('templates')->get($name);
      $ids[$name] = ($t && $t->id) ? (int) $t->id : 0;
    }
    return $ids[$name];
  }

  protected function sfFieldTable(string $name): string {
    $f = $this->wire('fields')->get($name);
    return ($f && $f->id) ? $f->getTable() : '';
  }

  /**
   * Forum statistics for several users with a few aggregate SQL queries (no page loading).
   * Returns [uid => ['messages','topics','replies','reaction','points','ratings']].
   */
  public function userStats(array $uids): array {
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
    $out = [];
    $need = [];
    foreach($uids as $id) {
      if(isset($this->sfMemo['ustats'][$id])) $out[$id] = $this->sfMemo['ustats'][$id];
      else $need[] = $id;
    }
    if(!$need) return $out;
    $empty = ['messages' => 0, 'topics' => 0, 'replies' => 0, 'reaction' => 0, 'points' => 0, 'ratings' => 0];
    $res = array_fill_keys($need, $empty);
    $tt = $this->sfTplId('forum-topic');
    $rt = $this->sfTplId('forum-reply');
    $aT = $this->sfFieldTable('forum_author');
    if($aT && ($tt || $rt)) {
      $db = $this->wire('database');
      $in = implode(',', $need);
      $tpl = implode(',', array_filter([$tt, $rt]));
      $base = "FROM `$aT` a JOIN pages p ON p.id=a.pages_id WHERE a.data IN ($in) AND p.templates_id IN ($tpl) AND p.status<1024";
      try {
        $vT = $this->sfFieldTable('forum_vote_score');
        $sql = "SELECT a.data AS uid, SUM(p.templates_id=$tt) AS t, SUM(p.templates_id=$rt) AS r" . ($vT ? ", COALESCE(SUM(v.data),0) AS vs" : ", 0 AS vs")
          . " FROM `$aT` a JOIN pages p ON p.id=a.pages_id" . ($vT ? " LEFT JOIN `$vT` v ON v.pages_id=p.id" : "")
          . " WHERE a.data IN ($in) AND p.templates_id IN ($tpl) AND p.status<1024 GROUP BY a.data";
        foreach($db->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
          $u = (int) $row['uid'];
          $res[$u]['topics'] = (int) $row['t'];
          $res[$u]['replies'] = (int) $row['r'];
          $res[$u]['messages'] = (int) $row['t'] + (int) $row['r'];
          $res[$u]['reaction'] = (int) $row['vs'];
        }
        foreach(['points' => 'forum_like_users', 'ratings' => 'forum_vote_users'] as $key => $fname) {
          $xT = $this->sfFieldTable($fname);
          if(!$xT) continue;
          $sql = "SELECT a.data AS uid, COUNT(x.data) AS n FROM `$aT` a JOIN pages p ON p.id=a.pages_id JOIN `$xT` x ON x.pages_id=p.id"
            . " WHERE a.data IN ($in) AND p.templates_id IN ($tpl) AND p.status<1024 GROUP BY a.data";
          foreach($db->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row) $res[(int) $row['uid']][$key] = (int) $row['n'];
        }
      } catch(\Throwable $e) {
        // leave zeros
      }
      unset($base);
    }
    foreach($res as $id => $s) { $this->sfMemo['ustats'][$id] = $s; $out[$id] = $s; }
    return $out;
  }

  /**
   * Reply count + newest reply for many topics in two SQL queries.
   * Returns [topicId => ['replies' => int, 'last_id' => int, 'last_created' => int]].
   * Visible replies only (same as a normal $pages->count() for guests).
   */
  public function topicStats(array $topicIds, bool $includeHidden = false): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $topicIds))));
    $memoKey = $includeHidden ? 'h' : 'v';
    $out = [];
    $need = [];
    foreach($ids as $id) {
      if(isset($this->sfMemo['tstats'][$memoKey][$id])) $out[$id] = $this->sfMemo['tstats'][$memoKey][$id];
      else $need[] = $id;
    }
    if(!$need) return $out;
    $res = array_fill_keys($need, ['replies' => 0, 'last_id' => 0, 'last_created' => 0]);
    $rt = $this->sfTplId('forum-reply');
    if($rt) {
      $db = $this->wire('database');
      $in = implode(',', $need);
      $st = $includeHidden ? 'p.status<8192' : 'p.status<1024';
      try {
        $sql = "SELECT p.parent_id AS tid, COUNT(*) AS n, MAX(p.created) AS mc FROM pages p WHERE p.parent_id IN ($in) AND p.templates_id=$rt AND $st GROUP BY p.parent_id";
        $mc = [];
        foreach($db->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
          $res[(int) $row['tid']]['replies'] = (int) $row['n'];
          $res[(int) $row['tid']]['last_created'] = strtotime((string) $row['mc']) ?: 0;
          $mc[(int) $row['tid']] = (string) $row['mc'];
        }
        if($mc) {
          $sql = "SELECT p.parent_id AS tid, MAX(p.id) AS id FROM pages p JOIN (SELECT parent_id, MAX(created) AS mc FROM pages p WHERE p.parent_id IN ($in) AND p.templates_id=$rt AND $st GROUP BY parent_id) m"
            . " ON m.parent_id=p.parent_id AND m.mc=p.created WHERE p.templates_id=$rt AND $st GROUP BY p.parent_id";
          foreach($db->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row) $res[(int) $row['tid']]['last_id'] = (int) $row['id'];
        }
      } catch(\Throwable $e) {}
    }
    foreach($res as $id => $s) { $this->sfMemo['tstats'][$memoKey][$id] = $s; $out[$id] = $s; }
    return $out;
  }

  /**
   * Load many users at once (one query, forum fields joined) and keep them for the request.
   * @return User[] keyed by id
   */
  public function preloadUsers(array $uids): array {
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
    $need = array_values(array_diff($uids, array_keys($this->sfMemo['users'])));
    if($need) {
      $join = [];
      foreach(['roles', 'forum_nickname', 'forum_rank', 'forum_post_count', 'forum_rank_manual_override', 'forum_signature', 'forum_country'] as $fn) {
        if($this->wire('fields')->get($fn)) $join[] = $fn;
      }
      $pagesApi = $this->wire('pages');
      $todo = [];
      foreach($need as $id) {
        $c = $pagesApi->getCache($id); // already in memory: no query
        if($c instanceof User && $c->id) $this->sfMemo['users'][$id] = $c; else $todo[] = $id;
      }
      $found = [];
      if($todo) {
        try {
          $found = $pagesApi->getById($todo, ['joinFields' => $join]);
        } catch(\Throwable $e) {
          $found = $this->wire('users')->find('id=' . implode('|', $todo) . ', include=all, check_access=0');
        }
      }
      foreach($found as $u) if($u instanceof User) $this->sfMemo['users'][(int) $u->id] = $u;
      foreach($need as $id) if(!isset($this->sfMemo['users'][$id])) $this->sfMemo['users'][$id] = null;
      if(count($need) > 1) $this->userStats($need); // prime stats for user cards in one go
    }
    $out = [];
    foreach($uids as $id) if(!empty($this->sfMemo['users'][$id])) $out[$id] = $this->sfMemo['users'][$id];
    return $out;
  }

  /** [pageId => authorUserId] for many topics/replies, straight from the field table. */
  public function authorIds(array $pageIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $pageIds))));
    $t = $this->sfFieldTable('forum_author');
    if(!$ids || !$t) return [];
    $out = [];
    try {
      foreach($this->wire('database')->query("SELECT pages_id, data FROM `$t` WHERE pages_id IN (" . implode(',', $ids) . ")")->fetchAll(\PDO::FETCH_NUM) as $r) $out[(int) $r[0]] = (int) $r[1];
    } catch(\Throwable $e) {}
    return $out;
  }

  /** Preload the authors of these topics/replies (one query each for ids and users). */
  public function preloadAuthors($pages): void {
    $ids = [];
    foreach($pages as $p) if($p instanceof Page) $ids[] = (int) $p->id;
    $map = $this->authorIds($ids);
    if($map) $this->preloadUsers(array_values($map));
  }

  /** Get one user by id (request cache, no access checks). */
  public function userById(int $id): ?User {
    if($id <= 0) return null;
    if(!array_key_exists($id, $this->sfMemo['users'])) $this->preloadUsers([$id]);
    $u = $this->sfMemo['users'][$id] ?? null;
    return ($u instanceof User && $u->id) ? $u : null;
  }

  /**
   * +1 topic view without a page save (no hooks, no cache clearing, no ProCache flush).
   */
  public function bumpTopicViews(Page $topic): void {
    $t = $this->sfFieldTable('forum_views');
    if(!$t || !$topic->id || !$topic->template->hasField('forum_views')) return;
    try {
      $q = $this->wire('database')->prepare("INSERT INTO `$t` (pages_id, data) VALUES (:id, 1) ON DUPLICATE KEY UPDATE data=data+1");
      $q->execute([':id' => (int) $topic->id]);
    } catch(\Throwable $e) {}
  }

  /**
   * Store a user's read-tracking map without a full page save (it changes on almost every topic view).
   */
  public function saveReadMap(User $u, array $map): void {
    if(!$u->id || !$u->hasField('forum_read_topics')) return;
    if(count($map) > 3000) { arsort($map); $map = array_slice($map, 0, 3000, true); }
    $json = json_encode($map);
    $t = $this->sfFieldTable('forum_read_topics');
    try {
      if(!$t) throw new WireException('no table');
      $q = $this->wire('database')->prepare("INSERT INTO `$t` (pages_id, data) VALUES (:id, :d) ON DUPLICATE KEY UPDATE data=VALUES(data)");
      $q->execute([':id' => (int) $u->id, ':d' => $json]);
      $of = $u->of(); $u->of(false);
      $u->setQuietly('forum_read_topics', $json);
      $u->untrackChange('forum_read_topics');
      $u->of($of);
    } catch(\Throwable $e) {
      $u->of(false); $u->set('forum_read_topics', $json); $u->save('forum_read_topics', ['quiet' => true, 'noHooks' => true]);
    }
  }

  /** Templates can tell us a reply URL they already know (avoids position queries). */
  public function primeReplyUrl(int $replyId, string $url): void {
    $this->sfMemo['rurl'][$replyId] = $url;
  }

  /**
   * Canonical URL of a reply: topic URL + page number + anchor.
   */
  public function replyUrl(Page $reply, bool $http = false): string {
    $topic = $reply->parent;
    if(!$topic || !$topic->id) return $http ? $reply->httpUrl : $reply->url;
    if(isset($this->sfMemo['rurl'][(int) $reply->id])) {
      $rel = $this->sfMemo['rurl'][(int) $reply->id];
      return $http ? substr($topic->httpUrl, 0, -strlen($topic->url)) . $rel : $rel;
    }
    $per = $this->postsPerPage();
    // Position of the reply among replies (one SQL query; topic post itself is #1)
    $pos = 0;
    try {
      $rt = $this->sfTplId('forum-reply');
      $q = $this->wire('database')->prepare("SELECT COUNT(*) FROM pages WHERE parent_id=:t AND templates_id=:rt AND status<2048 AND (created<:c OR (created=:c2 AND id<:id))");
      $c = date('Y-m-d H:i:s', (int) $reply->created);
      $q->execute([':t' => (int) $topic->id, ':rt' => $rt, ':c' => $c, ':c2' => $c, ':id' => (int) $reply->id]);
      $pos = (int) $q->fetchColumn();
    } catch(\Throwable $e) { $pos = 0; }
    $postIndex = $pos + 2; // 1-based, topic post = 1
    $pageNum = (int) ceil($postIndex / $per);
    $url = ($http ? $topic->httpUrl : $topic->url) . ($pageNum > 1 ? '?p=' . $pageNum : '');
    $url .= '#reply-' . (int) $reply->id;
    if(!$http) $this->sfMemo['rurl'][(int) $reply->id] = $url;
    else $this->sfMemo['rurl'][(int) $reply->id] = $topic->url . ($pageNum > 1 ? '?p=' . $pageNum : '') . '#reply-' . (int) $reply->id;
    return $url;
  }

  public function postsPerPage(): int {
    $n = (int) ($this->get('postsPerPage') ?: 11);
    return max(3, min(200, $n));
  }

  /**
   * JSON upload endpoint (images/files attached in the editor).
   */
  protected function respondUpload(): void {
    while(ob_get_level()) ob_end_clean();
    if(class_exists('\\Tracy\\Debugger')) { \Tracy\Debugger::$showBar = false; }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $input = $this->wire('input');
    try {
      $this->wire('session')->CSRF->validate('sfupl');
      // nothing below needs the session: release its lock so parallel uploads don't queue
      if(session_status() === PHP_SESSION_ACTIVE) @session_write_close();
      if((int) $input->post('sf_upload_delete') === 1) {
        echo json_encode($this->deleteTempUpload($this->wire('user'), $input));
      } else {
        echo json_encode($this->handleTempUpload($this->wire('user'), $input));
      }
    } catch(\Throwable $e) {
      echo json_encode(['ok' => false, 'error' => 'Security check failed or the server rejected the upload. Refresh the page and try again.']);
    }
    exit;
  }

  /**
   * Keep topic "last activity" fresh (used for sorting + unread) and clear index caches.
   */
  /**
   * Login of a member imported with "Passwords" (Export / Import): ProcessWire's check failed because
   * the hash was made on the old site (with that site's userAuthSalt). Verify against the stored old
   * hash; on success save the password with this site's hashing and forget the old hash.
   */
  public function hookLegacyPassword(HookEvent $event) {
    $user = $event->arguments(0);
    $pass = (string) $event->arguments(1);
    if(!$user instanceof User || !$user->id) return;
    try {
      $legacy = $user->meta('nf_legacy_pass');
      if(!$legacy) return;
      if($event->return) { $user->meta()->remove('nf_legacy_pass'); return; } // already has a working password here
      if($pass === '' || !is_array($legacy) || empty($legacy['h']) || empty($legacy['s'])) return;
      if(!$this->legacyPassMatches($pass, $legacy)) return;
      $user->of(false);
      $user->pass = $pass;
      $this->wire('users')->save($user, ['quiet' => true]);
      $user->meta()->remove('nf_legacy_pass');
      $this->wire('log')->save('native-forum', 'Imported password upgraded for ' . $user->name);
      $event->return = true;
    } catch(\Throwable $e) {}
  }

  /** Same algorithm as ProcessWire's Password::hash(), with the old site's salt. */
  protected function legacyPassMatches(string $pass, array $d): bool {
    $salt1 = (string) $d['s'];
    $salt2 = (string) ($d['a'] ?? '');
    $stored = (string) $d['h'];
    $isBlowfish = strpos($salt1, '$2y$') === 0 || strpos($salt1, '$2a$') === 0;
    if($isBlowfish) {
      $full = crypt($pass . $salt2, $salt1);
      if(!is_string($full) || strlen($full) < 30) return false;
      return hash_equals($stored, substr($full, 29));
    }
    $type = (string) ($d['t'] ?? '');
    if($type === '') return hash_equals($stored, md5($pass));
    $split = str_split($pass, (int) (strlen($pass) / 2) + 1);
    return hash_equals($stored, hash($type, $salt1 . $split[0] . $salt2 . ($split[1] ?? ''), false));
  }

  public function hookPageSaved(HookEvent $event) {
    if($this->sfBulk) return; // import: caches are cleared once at the end
    $p = $event->arguments(0);
    if(!$p instanceof Page || !$p->template) return;
    $t = (string) $p->template->name;
    if(!in_array($t, ['forum-topic','forum-reply','forum-category','forum-group'], true)) return;
    // Render caches only (pending registrations / flood control live in the 'NativeForum' namespace)
    try { $this->wire('cache')->deleteFor('NativeForumView'); } catch(\Throwable $e) {}
    $this->clearProCacheFor($p);
  }

  /************************************************************
   * REPORTS (moderation queue)
   * Stored in their own small table: nativeforum_reports
   ************************************************************/

  public function ensureReportsTable(): bool {
    static $ok = null;
    if($ok !== null) return $ok;
    try {
      $this->wire('database')->exec("CREATE TABLE IF NOT EXISTS nativeforum_reports (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        topic_id INT UNSIGNED NOT NULL DEFAULT 0,
        post_author INT UNSIGNED NOT NULL DEFAULT 0,
        reporter_id INT UNSIGNED NOT NULL DEFAULT 0,
        reason VARCHAR(32) NOT NULL DEFAULT 'other',
        comment TEXT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'open',
        created INT UNSIGNED NOT NULL DEFAULT 0,
        handled_by INT UNSIGNED NOT NULL DEFAULT 0,
        handled_at INT UNSIGNED NOT NULL DEFAULT 0,
        action VARCHAR(32) NOT NULL DEFAULT '',
        KEY post_id (post_id), KEY status (status), KEY created (created)
      ) DEFAULT CHARSET=utf8mb4");
      $ok = true;
    } catch(\Throwable $e) {
      $ok = false;
    }
    return $ok;
  }

  public function reportReasons(): array {
    return ['spam' => 'Spam / advertising', 'offensive' => 'Offensive or abusive', 'offtopic' => 'Off-topic / wrong forum', 'illegal' => 'Illegal content / copyright', 'other' => 'Other'];
  }

  /** Save a report (one open report per member and post). Returns the report id or 0. */
  public function addReport(Page $post, User $reporter, string $reason, string $comment = ''): int {
    if(!$this->ensureReportsTable() || !$post->id) return 0;
    $reasons = $this->reportReasons();
    if(!isset($reasons[$reason])) $reason = 'other';
    $comment = trim($this->wire('sanitizer')->textarea($comment, ['maxLength' => 1000]));
    $topicId = $post->template->name === 'forum-topic' ? (int) $post->id : (int) $post->parent_id;
    $author = 0;
    try { $a = $post->getUnformatted('forum_author'); if($a instanceof PageArray) $a = $a->first(); if($a instanceof Page) $author = (int) $a->id; } catch(\Throwable $e) {}
    $db = $this->wire('database');
    try {
      $q = $db->prepare("SELECT id FROM nativeforum_reports WHERE post_id=:p AND reporter_id=:r AND status='open' LIMIT 1");
      $q->execute([':p' => (int) $post->id, ':r' => (int) $reporter->id]);
      $existing = (int) $q->fetchColumn();
      if($existing) {
        $q = $db->prepare("UPDATE nativeforum_reports SET reason=:reason, comment=:c, created=:t WHERE id=:id");
        $q->execute([':reason' => $reason, ':c' => $comment, ':t' => time(), ':id' => $existing]);
        return $existing;
      }
      $q = $db->prepare("INSERT INTO nativeforum_reports (post_id, topic_id, post_author, reporter_id, reason, comment, status, created) VALUES (:p,:t,:a,:r,:reason,:c,'open',:time)");
      $q->execute([':p' => (int) $post->id, ':t' => $topicId, ':a' => $author, ':r' => (int) $reporter->id, ':reason' => $reason, ':c' => $comment, ':time' => time()]);
      return (int) $db->lastInsertId();
    } catch(\Throwable $e) {
      return 0;
    }
  }

  /** Reports grouped by post: [post_id => ['post_id','topic_id','post_author','count','last','reasons'=>[], 'items'=>[rows]]] */
  public function reportsByPost(string $status = 'open', int $limit = 100): array {
    if(!$this->ensureReportsTable()) return [];
    $where = $status === 'all' ? '1' : ($status === 'closed' ? "status<>'open'" : "status='open'");
    $out = [];
    try {
      $rows = $this->wire('database')->query("SELECT * FROM nativeforum_reports WHERE $where ORDER BY created DESC LIMIT " . (int) max(1, $limit * 5))->fetchAll(\PDO::FETCH_ASSOC);
      foreach($rows as $r) {
        $pid = (int) $r['post_id'];
        if(!isset($out[$pid])) {
          if(count($out) >= $limit) continue;
          $out[$pid] = ['post_id' => $pid, 'topic_id' => (int) $r['topic_id'], 'post_author' => (int) $r['post_author'], 'count' => 0, 'last' => 0, 'reasons' => [], 'items' => [], 'status' => $r['status'], 'action' => $r['action'], 'handled_by' => (int) $r['handled_by'], 'handled_at' => (int) $r['handled_at']];
        }
        $out[$pid]['count']++;
        $out[$pid]['last'] = max($out[$pid]['last'], (int) $r['created']);
        $out[$pid]['reasons'][$r['reason']] = ($out[$pid]['reasons'][$r['reason']] ?? 0) + 1;
        $out[$pid]['items'][] = $r;
      }
    } catch(\Throwable $e) {}
    return $out;
  }

  public function openReportCount(): int {
    if(!$this->ensureReportsTable()) return 0;
    try { return (int) $this->wire('database')->query("SELECT COUNT(DISTINCT post_id) FROM nativeforum_reports WHERE status='open'")->fetchColumn(); }
    catch(\Throwable $e) { return 0; }
  }

  /**
   * Moderator action on all open reports of a post.
   * $action: resolve | dismiss | hide | restore | lock | unlock | delete
   */
  public function handleReport(int $postId, string $action, User $mod): string {
    if(!$this->isModerator($mod) && !$mod->isSuperuser()) throw new WireException('Not allowed.');
    $post = $this->wire('pages')->get($postId);
    $msg = '';
    if($post && $post->id && in_array($post->template->name, ['forum-topic', 'forum-reply'], true)) {
      $post->of(false);
      switch($action) {
        case 'hide':
          if($post->template->name === 'forum-reply' && $post->hasField('forum_is_deleted')) { $post->set('forum_is_deleted', 1); $post->save('forum_is_deleted'); }
          else { $post->addStatus(Page::statusUnpublished); $post->save(); }
          $msg = 'Post hidden.';
          break;
        case 'restore':
          if($post->template->name === 'forum-reply' && $post->hasField('forum_is_deleted')) { $post->set('forum_is_deleted', 0); $post->save('forum_is_deleted'); }
          $post->removeStatus(Page::statusUnpublished); $post->save();
          $msg = 'Post restored.';
          break;
        case 'lock':
        case 'unlock':
          $t = $post->template->name === 'forum-topic' ? $post : $post->parent;
          if($t && $t->id && $t->hasField('forum_is_locked')) { $t->of(false); $t->set('forum_is_locked', $action === 'lock' ? 1 : 0); $t->save('forum_is_locked'); }
          $msg = $action === 'lock' ? 'Topic locked.' : 'Topic unlocked.';
          break;
        case 'delete':
          $this->wire('pages')->trash($post);
          $msg = 'Post moved to the trash.';
          break;
      }
    }
    if(in_array($action, ['resolve', 'dismiss', 'hide', 'delete', 'lock'], true)) {
      try {
        $q = $this->wire('database')->prepare("UPDATE nativeforum_reports SET status=:s, handled_by=:u, handled_at=:t, action=:a WHERE post_id=:p AND status='open'");
        $q->execute([':s' => $action === 'dismiss' ? 'dismissed' : 'resolved', ':u' => (int) $mod->id, ':t' => time(), ':a' => $action, ':p' => $postId]);
      } catch(\Throwable $e) {}
      if($msg === '') $msg = $action === 'dismiss' ? 'Report dismissed.' : 'Report resolved.';
    }
    try { $this->wire('cache')->deleteFor('NativeForumView'); } catch(\Throwable $e) {}
    return $msg;
  }

  /** Clear the whole ProCache cache (after an update or a settings change, so guests and link-preview crawlers get the new HTML). */
  public function clearProCacheAll(): void {
    try {
      $m = $this->wire('modules');
      if(!$m->isInstalled('ProCache')) return;
      $pc = $m->get('ProCache');
      if($pc && method_exists($pc, 'clearAll')) $pc->clearAll();
    } catch(\Throwable $e) {}
  }

  /**
   * ProCache: a new/edited post must show up for guests right away. Clear the post's topic,
   * its category/group, the forum index and the feeds (only when ProCache is installed).
   */
  protected function clearProCacheFor(Page $p): void {
    static $done = [];
    $modules = $this->wire('modules');
    if(!$modules->isInstalled('ProCache')) return;
    try {
      $pc = $modules->get('ProCache');
      if(!$pc) return;
      $list = new PageArray();
      $cur = $p;
      for($i = 0; $i < 6 && $cur && $cur->id; $i++, $cur = $cur->parent) {
        if(!in_array((string) $cur->template->name, ['forum-reply', 'forum-topic', 'forum-category', 'forum-group', 'forum-root'], true)) break;
        if(empty($done[$cur->id])) { $list->add($cur); $done[$cur->id] = 1; } // incl. the reply URL (link previews)
      }
      foreach($list as $x) {
        if(method_exists($pc, 'clearPage')) $pc->clearPage($x);
        elseif(method_exists($pc, 'clearPages')) $pc->clearPages(new PageArray([$x]));
      }
    } catch(\Throwable $e) {}
  }

  public function hookPageDeleted(HookEvent $event) {
    $this->hookPageSaved($event);
  }

  /**
   * Ensure HTMLPurifier DefinitionCache serializer directory exists and is writable.
   * We point it to /site/assets/cache/HTMLPurifier/ (upgrade-safe) to prevent warnings.
   */
  protected function ensurePurifierCachePath() {
    $config = $this->wire('config');
    if(!$config) return;

    $dir = rtrim((string) $config->paths->cache, '/\\') . '/HTMLPurifier';
    if(!is_dir($dir)) {
      try {
        wireMkdir($dir, true);
      } catch(\Throwable $e) {
        return;
      }
    }

    $dir = rtrim($dir, '/\\');
    $config->set('nativeForumHtmlPurifierCachePath', $dir);
  }



  /**
   * Handle attachment uploads early (AJAX).
   */
  public function hookAjaxUpload(\ProcessWire\HookEvent $event) {
    $input = $this->wire('input');
    if((int)$input->post->sf_upload_attachment !== 1) return;

    // Only on frontend + AJAX
    $config = $this->wire('config');

    // ProcessWire versions differ: $input->server is typically a WireInputData property,
    // not a callable method. Use a safe/compatible lookup.
    $xhr = '';
    try {
      if(isset($input->server)) {
        if(is_object($input->server)) {
          if(method_exists($input->server, 'get')) {
            $xhr = (string) $input->server->get('HTTP_X_REQUESTED_WITH');
          } elseif(isset($input->server->HTTP_X_REQUESTED_WITH)) {
            $xhr = (string) $input->server->HTTP_X_REQUESTED_WITH;
          }
        } elseif(is_array($input->server) && isset($input->server['HTTP_X_REQUESTED_WITH'])) {
          $xhr = (string) $input->server['HTTP_X_REQUESTED_WITH'];
        }
      }
    } catch(\Throwable $e) {
      // ignore
    }
    $xhr = strtolower(trim($xhr));
    $isAjax = ($config->ajax || $xhr === 'xmlhttprequest');
    // Some hosts/proxies strip X-Requested-With; since this endpoint is
    // explicitly flagged by sf_upload_attachment=1, handle it even if ajax
    // detection fails.

    $page = $this->wire('page');
    if(!$page || !$page->template) return;
    $tpl = (string)$page->template->name;
    if(strpos($tpl, 'forum-') !== 0 && $tpl !== 'forum') {
      // Safety: don't let random pages become upload endpoints
      return;
    }

    // Disable Tracy bar for JSON responses
    if(class_exists('\\Tracy\\Debugger')) { \Tracy\Debugger::$showBar = false; }

    header('Content-Type: application/json; charset=utf-8');

    try {
      $this->wire('session')->CSRF->validate('sfupl');

      // Delete temp
      if((int)$input->post->sf_upload_delete === 1) {
        $res = $this->deleteTempUpload($this->wire('user'), $input);
        echo json_encode($res);
        exit;
      }

      $res = $this->handleTempUpload($this->wire('user'), $input);
      echo json_encode($res);
      exit;

    } catch(\Throwable $e) {
      echo json_encode([
        'ok' => false,
        'error' => 'Security check failed or server rejected the upload. Please refresh the page and try again.'
      ]);
      exit;
    }
  }


  /************************************************************
   * CONFIG
   ************************************************************/
  /**
   * Default configuration. Applied in __construct() so every setting has its documented
   * default at runtime even before the settings page was ever saved.
   */
  public static function sfDefaultConfig(): array {
    return [
      'forumRoot'            => 0,
      'messagesRoot'         => 0,
      'userProfileRoot'      => 0,
      'allowGuestsRead'      => 1,
      'allowGuestsPost'      => 0,
      'allowGuestsVote'      => 0,
      // Public profile visibility
      'allowGuestsViewProfiles' => 0,
      'allowUsersViewProfiles'  => 1,
      // Allow avatar upload in profile
      'allowAvatarUpload'      => 1,
      'allowPrivateMessages' => 1,
      'treeMode'             => 'flat',
      'frontendTheme'        => 'simple',
      'designPreset'        => 'obsidian',
      // Summary field names
      'field_forum_summary_category' => 'field_forum_summary_category',
      'field_forum_summary_topic'    => 'field_forum_summary_topic',
      // Design / colors (CSS variables). Leave empty to use theme defaults.
      'designForumBg'            => '',
      'designForumBgTransparent' => 0,
      'designForumText'          => '',
      'designPanelBg'            => '',
      'designPanelBgTransparent' => 0,
      'designPanelText'          => '',
      'designTopicRowBg'         => '',
      'designTopicRowBgTransparent' => 0,
      'designTopicRowText'       => '',
      'designTopicPostBg'        => '',
      'designTopicPostBgTransparent' => 0,
      'designTopicPostText'      => '',
      'designReplyBg'            => '',
      'designReplyBgTransparent' => 0,
      'designReplyText'          => '',

      // Typography + spacing compatibility (default: inherit)
      'designInheritTypography' => 1,
      'designFontFamily'        => '',
      'designFontSize'          => '',
      'designLineHeight'        => '',
      'designBreadcrumbSize'    => '',
      'designBreadcrumbWeight'  => '',

      // Text sizes & colors
      'designH1Size'            => '',
      'designH2Size'            => '',
      'designTopicTitleSize'    => '',
      'designTopicTitleColor'   => '',

      // Title weights (links + page titles)
      'designTitleWeight'       => '',

      'designIndexTitleSize'    => '',
      'designIndexTitleColor'   => '',
      'designPageTitleSize'     => '',
      'designPageTitleColor'    => '',
      'designSidebarTitleSize'  => '',

      // Popups (tooltips + user card)
      'designPopupsEnable'      => 1,
      'designPopupBg'           => '',
      'designPopupText'         => '',
      'designPopupBorder'       => '',
      'designPopupTitleSize'    => '',
      'designPopupTextSize'     => '',

      // Site preloader (forum pages)
      'disableSitePreloaderOnForum' => 0,
      // Anti-spam
      'spamNewMemberPosts'      => 3,
      'spamNewMemberMaxLinks'   => 1,
      'spamMaxLinks'            => 0,
      'spamRegPerIpDay'         => 3,
      'spamCheckMx'             => 1,
      'spamStopForumSpam'       => 0,
      'spamLog'                 => 1,
      'spamBannedWords'         => '',
      'spamBlockedEmailDomains' => '',
      'spamBlockedIps'          => '',
      'turnstileSiteKey'        => '',
      'turnstileSecret'         => '',
      'turnstileForms'          => ['register'],
      'preloaderHideSelectors'      => "#preloader
.preloader
.page-loader
#loader-wrapper",

      'designPostMetaSize'      => '',
      'designSmallTextSize'     => '',

      // Spacing
      'designBlockGap'          => '',

      // Sidebar (panel) tweaks
      'designSidebarBg'         => '',
      'designSidebarText'       => '',

      // User info block (author column)
      'designUserInfoBg'        => '',
      'designUserInfoText'      => '',
      'designUserInfoPad'       => '',
      'designUserInfoRadius'    => '',

      // Header / sidebar header colors (moved to Design & Colors)
      'headerBgColor'           => '',
      'headerTextColor'         => '',
      'vipHeaderBgColor'        => '',
      'vipHeaderTextColor'      => '',
      'sidebarHeaderBgColor'    => '',
      'sidebarHeaderTextColor'  => '',

      'designSidebarPad'        => '',
      'designSidebarInnerPad'   => '',
      'designSidebarPadX'       => '',
      'designSidebarPadY'       => '',

      // Buttons & controls design tokens
      'designButtonPreset'   => 'neutral', // neutral|inherit|pill
      'designButtonSize'     => 'sm',
      'designButtonRadius'   => 8,
      'designBtnPrimaryBg'   => '',
      'designBtnPrimaryText' => '',
      'designBtnBorder'      => '',
      'designBtnText'        => '',

      

      'designBtnPrimaryHoverBg'   => '',
      'designBtnPrimaryHoverText' => '',
      'designBtnSecondaryBg'      => '',
      'designBtnSecondaryText'    => '',
      'designBtnSecondaryHoverBg' => '',
      'designBtnFocusRing'        => '',

      // Inputs / forms
      'designInputBg'         => '',
      'designInputText'       => '',
      'designInputBorder'     => '',
      'designInputRadius'     => '',
      'designInputHeight'     => 'md', // sm|md|lg
      'designInputFocusBorder'=> '',
      'designInputFocusRing'  => '',

      // Badges / tags
      'designBadgeBg'      => '',
      'designBadgeText'    => '',
      'designBadgeRadius'  => '',
      'designBadgeVipBg'   => '',
      'designBadgeVipText' => '',

      // Popups additional
      'designPopupRadius' => '',
      'designPopupShadow' => '',
// Icons (topic action buttons)
      'iconSet'             => 'svg', // svg (bundled) or fontawesome
      'fontAwesomeAutoLoad' => 0,
      'fontAwesomeCssUrl'   => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css',
      'hideVipCategories'    => 1,
      'defaultVipReadRoles'  => [],
      'defaultVipWriteRoles' => [],
      // Optional: manual ranks list (one per line). Used as default if user has no forum_rank.
      'manualRanks'          => '',
      'enableAutoRanks'      => 1,
      'autoRankLadder'       => "0=Beginner
10=Member
100=Senior
500=Veteran
1000=Legend",

      // Date/time display style on frontend
      'dateTimeStyle'        => 'iso_dt',

      // Rich editor / content settings
      'enableFrontendWysiwyg' => 1,
      'renderRichHtml'        => 1,
      'allowRichImages'       => 1,
      'allowRichEmbeds'       => 1,
      'allowRichCode'         => 1,

      // Editing + topic following
      'postEditWindow'        => '1w', // 0=disabled, 15m, 1h, 1d, 1w, 1m, inf
      'enableTopicFollow'     => 1,
      'sendFollowEmails'      => 1,

      // Attachments / uploads (frontend)
      'attachmentsEnabled'       => 1,
      'attachmentsAllowImages'   => 1,
      'attachmentsAllowFiles'    => 1,
      'attachmentsMaxUploadMB'   => 20,
      'attachmentsMaxFiles'      => 10,
      'attachmentsAllowedExt'    => 'pdf zip rar 7z doc docx xls xlsx ppt pptx txt rtf mp3 wav ogg mp4 mov avi',
      'attachmentsImageMaxWidth' => 900,
      'attachmentsEnableLightbox'=> 1,
      'attachmentsLightboxLibrary' => 'fancybox',

      // Pagination (topic + replies per page)
      // NOTE: This number includes the topic post itself as #1.
      'postsPerPage'             => 11,
      'showTopicPostOnAllPages'  => 0,

      // Layout / theme settings declared by the UI below (kept here so the
      // module config form always shows the saved values consistently).
      'forumStyle'               => 'classic',
      'forumWidthPreset'         => 'container',
      'forumForceFullWidth'      => 0,
      'forumTopSpacePx'          => 0,
      'forumBottomSpacePx'       => 0,
      'forumPrependMarkup'       => '',
      'forumPrependPlacement'    => 'inside_container',
      'forumAppendMarkup'        => '',
      'forumAppendPlacement'     => 'inside_container',
      'layoutMainColsLg'         => '8-4',
      'sidebarDisplayMode'       => 'always',
      'sidebarShowTemplates'     => ['forum-root','forum-members'],
      'postAuthorSide'           => 'left',
      'frontendCkToolbar'        => 'standard',

      // Bootstrap integration (declared in the Frontend group)
      'bootstrapAutoLoad'        => 0,
      'bootstrapIntegration'     => 'auto',
      'bootstrapSiteVersion'     => 'auto',
      'bootstrapCompatShim'      => 1,
      'bootstrapCssUrl'          => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
      'bootstrapJsUrl'           => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',

      // Icon provider (FA version + style)
      'iconProvider'             => 'svg',
      'faStyle'                  => 'solid',

      // Tree layout preset
      'treePreset'               => 'classic',

      // Forum activity tracking (used by sf_activity_update)
      'activityEnabled'          => 1,
      'activityWindowMin'        => 5,


      // Settings added in v50+ (defaults also used at runtime)
      'showTopicSummaries'      => 1,
      'topicsPerPage'           => 20,
      'allowRegistration'       => 1,
      'requireEmailVerification'=> 1,
      'verifyExpireHours'       => 24,
      'floodSeconds'            => 15,
      'pmEmailNotify'           => 1,
      'seoMarkdown'             => 1,
      'seoLlmsTxt'              => 1,
      'seoOverrideTitle'        => 1,
      'defaultRole'             => 'forum-member',
    ];
  }

  public function __construct() {
    parent::__construct();
    foreach(self::sfDefaultConfig() as $k => $v) $this->set($k, $v);
  }

  /**
   * Settings layout: logical rows that always add up to 100 %, small controls grouped,
   * large text areas paired, related options next to each other (Settings page + module config).
   */
  protected function sfConfigLayout(InputfieldWrapper $form): void {
    try {
      $modules = $this->wire('modules');
      $all = [];
      $walk = function($w) use (&$walk, &$all) {
        foreach($w->children() as $f) {
          $n = (string) $f->attr('name');
          if($n !== '' && !isset($all[$n])) $all[$n] = $f;
          if($f instanceof InputfieldWrapper) $walk($f);
        }
      };
      $walk($form);
      $fs = function(string $name) use ($all) { return isset($all[$name]) && $all[$name] instanceof InputfieldWrapper ? $all[$name] : null; };

      // group => [ sub-section label (or '') => rows ]
      $plan = [
        'sf_general' => ['' => [
          ['forumName' => 50, 'htmlLang' => 25, 'faviconUrl' => 25],
          ['forumDescription' => 100],
          ['forumRoot' => 34, 'userProfileRoot' => 33, 'messagesRoot' => 33],
          ['treePreset' => 66, 'dateTimeStyle' => 34],
        ]],
        'sf_layout' => [
          'Integration with your site|columns' => [
            ['layoutMode' => 100],
            ['siteRegionId' => 34, 'siteContentVar' => 33, 'siteBodyField' => 33],
          ],
          'Width, style & sidebar|arrows-h' => [
            ['forumWidthPreset' => 34, 'forumStyle' => 33, 'sidebarDisplayMode' => 33],
            ['layoutMainColsLg' => 34, 'forumTopSpacePx' => 33, 'forumBottomSpacePx' => 33],
            ['forumForceFullWidth' => 50, 'sidebarShowTemplates' => 50],
          ],
          'Site loader|spinner' => [
            ['disableSitePreloaderOnForum' => 34, 'preloaderHideSelectors' => 66],
          ],
          'Extra markup|code' => [
            ['forumPrependMarkup' => 50, 'forumAppendMarkup' => 50],
            ['forumPrependPlacement' => 50, 'forumAppendPlacement' => 50],
            ['standaloneHeadMarkup' => 100],
            ['standaloneHeaderMarkup' => 50, 'standaloneFooterMarkup' => 50],
          ],
        ],
        'sf_frontend' => ['' => [
          ['frontendTheme' => 34, 'designPreset' => 33, 'themeColor' => 33],
          ['postAuthorSide' => 34, 'iconProvider' => 33, 'faStyle' => 33],
          ['fontAwesomeAutoLoad' => 34, 'fontAwesomeCssUrl' => 66],
          ['bootstrapIntegration' => 50, 'bootstrapCompatShim' => 50],
          ['bootstrapCssUrl' => 50, 'bootstrapJsUrl' => 50],
        ]],
        'sf_posting' => ['' => [
          ['postsPerPage' => 25, 'topicsPerPage' => 25, 'postEditWindow' => 25, 'floodSeconds' => 25],
          ['showTopicSummaries' => 50, 'showTopicPostOnAllPages' => 50],
          ['enableFrontendWysiwyg' => 34, 'frontendCkToolbar' => 33, 'renderRichHtml' => 33],
          ['allowRichImages' => 34, 'allowRichEmbeds' => 33, 'allowRichCode' => 33],
        ]],
        'sf_uploads' => ['' => [
          ['attachmentsEnabled' => 25, 'attachmentsAllowImages' => 25, 'attachmentsAllowFiles' => 25, 'attachmentsEnableLightbox' => 25],
          ['attachmentsMaxUploadMB' => 25, 'attachmentsMaxFiles' => 25, 'attachmentsImageMaxWidth' => 25, 'attachmentsLightboxLibrary' => 25],
          ['attachmentsAllowedExt' => 100],
        ]],
        'sf_members' => ['' => [
          ['allowRegistration' => 25, 'requireEmailVerification' => 25, 'verifyExpireHours' => 25, 'defaultRole' => 25],
          ['allowAvatarUpload' => 25, 'allowGuestsViewProfiles' => 25, 'allowUsersViewProfiles' => 25, 'enableAutoRanks' => 25],
          ['manualRanks' => 50, 'autoRankLadder' => 50],
        ]],
        'sf_messages' => ['' => [
          ['allowPrivateMessages' => 34, 'pmEmailNotify' => 33, 'mailFromEmail' => 33],
          ['enableTopicFollow' => 50, 'sendFollowEmails' => 50],
        ]],
        'sf_permissions' => ['' => [
          ['allowGuestsRead' => 50, 'allowGuestsPost' => 50],
          ['hideVipCategories' => 34, 'defaultVipReadRoles' => 33, 'defaultVipWriteRoles' => 33],
        ]],
        'sf_spam' => [
          'Limits|tachometer' => [
            ['spamNewMemberPosts' => 25, 'spamNewMemberMaxLinks' => 25, 'spamMaxLinks' => 25, 'spamRegPerIpDay' => 25],
            ['spamCheckMx' => 34, 'spamStopForumSpam' => 33, 'spamLog' => 33],
          ],
          'Block lists|ban' => [
            ['spamBannedWords' => 34, 'spamBlockedEmailDomains' => 33, 'spamBlockedIps' => 33],
          ],
          'Captcha (Cloudflare Turnstile, optional)|shield' => [
            ['turnstileSiteKey' => 34, 'turnstileSecret' => 33, 'turnstileForms' => 33],
          ],
        ],
        'sf_seo' => ['' => [
          ['seoDefaultImage' => 100],
          ['seoOverrideTitle' => 34, 'seoMarkdown' => 33, 'seoLlmsTxt' => 33],
        ]],
      ];

      foreach($plan as $group => $sections) {
        $g = $fs($group);
        if(!$g) continue;
        $placed = [];
        $blocks = [];
        foreach($sections as $label => $rows) {
          $items = [];
          foreach($rows as $row) {
            $present = array_filter($row, function($n) use ($all) { return isset($all[$n]); }, ARRAY_FILTER_USE_KEY);
            if(!count($present)) continue;
            // widths of the fields that exist must still fill the row
            $sum = array_sum($present); $k = 0; $acc = 0; $cnt = count($present);
            foreach($present as $n => $w) {
              $k++;
              $nw = $k === $cnt ? 100 - $acc : (int) round($w * 100 / $sum);
              $acc += $nw;
              $f = $all[$n];
              $f->columnWidth = $nw >= 100 ? 0 : $nw;
              if($f->parent) $f->parent->remove($f);
              $items[] = $f;
              $placed[$n] = 1;
            }
          }
          if(!count($items)) continue;
          $blocks[] = [$label, $items];
        }
        // whatever the plan does not know keeps its place after the planned rows
        $rest = [];
        foreach($g->children()->getArray() as $c) $rest[] = $c;
        foreach($rest as $c) $g->remove($c);
        foreach($blocks as $b) {
          list($label, $items) = $b;
          if($label === '') { foreach($items as $f) $g->add($f); continue; }
          list($title, $icon) = array_pad(explode('|', $label, 2), 2, '');
          $sub = $modules->get('InputfieldFieldset');
          $sub->label = $title;
          if($icon) $sub->icon = $icon;
          $sub->addClass('sf-cfg-sub', 'wrapClass');
          foreach($items as $f) $sub->add($f);
          $g->add($sub);
        }
        foreach($rest as $c) $g->add($c);
      }

      // short text areas in rows with small controls
      foreach(['preloaderHideSelectors' => 3, 'spamBannedWords' => 4, 'spamBlockedEmailDomains' => 4, 'spamBlockedIps' => 4, 'forumDescription' => 2, 'manualRanks' => 5, 'autoRankLadder' => 5] as $n => $r) {
        if(isset($all[$n])) $all[$n]->attr('rows', $r);
      }

      // Advanced: template field pickers two per row, translations four per row
      if($t = $fs('sf_tplfields')) {
        foreach($t->children() as $c) { $n = (string) $c->attr('name'); if(strpos($n, 'tplFields_') === 0) $c->columnWidth = 50; }
      }
      if($t = $fs('sf_tab_translations')) {
        // per sub-group: 4 per row (or 3 / all-in-one-row when that fills the rows evenly); the group description already explains "leave empty"
        $tw = function($w) use (&$tw) {
          $kids = [];
          foreach($w->children() as $c) {
            if($c instanceof InputfieldWrapper) { $tw($c); continue; }
            if(strpos((string) $c->attr('name'), 'tr__') === 0) $kids[] = $c;
          }
          $n = count($kids);
          if(!$n) return;
          $k = $n <= 5 ? $n : ($n % 4 === 0 ? 4 : ($n % 3 === 0 ? 3 : 4));
          foreach($kids as $c) { $c->columnWidth = $k > 1 ? (int) floor(100 / $k) : 0; $c->notes = ''; }
        };
        $tw($t);
      }

      // group order: setup → look → content → people → protection → SEO → advanced
      $order = ['sf_general', 'sf_layout', 'sf_frontend', 'sf_designstudio', 'sf_posting', 'sf_uploads', 'sf_members', 'sf_messages', 'sf_permissions', 'sf_spam', 'sf_seo', 'sf_advanced'];
      $top = $form->children()->getArray();
      $groups = [];
      foreach($top as $c) { $n = (string) $c->attr('name'); if(in_array($n, $order, true)) $groups[$n] = $c; }
      if(count($groups) > 1) {
        foreach($groups as $c) $form->remove($c);
        $after = [];
        foreach($form->children()->getArray() as $c) { $after[] = $c; $form->remove($c); }
        // keep non-group items (hidden fields etc.) first
        foreach($after as $c) $form->add($c);
        foreach($order as $n) if(isset($groups[$n])) $form->add($groups[$n]);
      }
    } catch(\Throwable $e) {
      // layout is cosmetic: never break the settings screen
      if($this->wire("config")->debug) $this->wire("log")->save("nativeforum-errors", "config layout: " . $e->getMessage() . " @" . $e->getLine());
    }
  }

  public function getModuleConfigInputfields(array $data) {
    $defaults = self::sfDefaultConfig();
    $data = array_merge($defaults, $data);

    // --- Reset design settings (Design Studio + legacy design*) ---
    try {
      $in = $this->wire('input');
      // Submit value can be a string label; treat any non-empty value as a reset request.
      if($in && isset($in->post->sf_reset_design) && (string)$in->post->sf_reset_design !== '') {
        $reset = $data;
        foreach($reset as $k => $v) {
          if(strpos($k, 'ds_') === 0) {
            // Design Studio overrides/settings
            $reset[$k] = (substr($k, -10) === '__override') ? 0 : '';
            continue;
          }
          if(strpos($k, 'design') === 0) {
            $reset[$k] = $defaults[$k] ?? '';
            continue;
          }
          // Legacy header/sidebar colors used by older templates
          if(in_array($k, ['headerBgColor','headerTextColor','vipHeaderBgColor','vipHeaderTextColor','sidebarHeaderBgColor','sidebarHeaderTextColor'], true)) {
            $reset[$k] = $defaults[$k] ?? '';
          }
        }
        $this->modules->saveConfig($this, $reset);
        $this->message('Forum design settings were reset to defaults.');
        $this->wire('session')->redirect($this->config->urls->admin . 'module/edit?name=NativeForum&sf_active_group=design');
      }
    } catch(\Throwable $e) {
      // ignore
    }

    // Wire the wrapper so nested Inputfields can access $input/$config/etc.
    // Without this, checkbox/toggle values can appear to never persist.
    $inputfields = $this->wire(new InputfieldWrapper());

    // Group settings for a cleaner module config UI
    // Order matters (requested):
    // General, Frontend & Theme, Design Studio, Private Messages, Permissions & Guests,
    // VIP Defaults, Template Custom Fields, Attachments (Uploads), Tools

    $fsGeneral = $this->modules->get('InputfieldFieldset');
    $fsGeneral->label = 'General';
    $fsGeneral->name = 'sf_general';
    $fsGeneral->collapsed = Inputfield::collapsedYes;

    $fsRanking = $this->modules->get('InputfieldFieldset');
    $fsRanking->label = 'Ranking';
    $fsRanking->name = 'sf_ranking';
    $fsRanking->collapsed = Inputfield::collapsedYes;

    $fsFrontend = $this->modules->get('InputfieldFieldset');
    $fsFrontend->label = 'Frontend & Theme';
    $fsFrontend->name = 'sf_frontend';
    $fsFrontend->collapsed = Inputfield::collapsedYes;



// New: Design Studio (token-based, with per-template overrides)
$fsDesignStudio = $this->modules->get('InputfieldFieldset');
$fsDesignStudio->label = 'Design Studio';
$fsDesignStudio->name = 'sf_designstudio';
$fsDesignStudio->icon = 'paint-brush';
$fsDesignStudio->collapsed = Inputfield::collapsedYes;
$fsDesignStudio->description = 'Configure frontend design using a global token set + optional per-template overrides. Choose a template and a section to edit.';

    $fsMessaging = $this->modules->get('InputfieldFieldset');
    $fsMessaging->label = 'Private Messages';
    $fsMessaging->name = 'sf_messages';
    $fsMessaging->collapsed = Inputfield::collapsedYes;

    $fsPermissions = $this->modules->get('InputfieldFieldset');
    $fsPermissions->label = 'Permissions & Guests';
    $fsPermissions->name = 'sf_permissions';
    $fsPermissions->collapsed = Inputfield::collapsedYes;

    $fsVip = $this->modules->get('InputfieldFieldset');
    $fsVip->label = 'VIP Defaults';
    $fsVip->name = 'sf_vip';
    $fsVip->collapsed = Inputfield::collapsedYes;

    $fsUploads = $this->modules->get('InputfieldFieldset');
    $fsUploads->label = 'Attachments (Uploads)';
    $fsUploads->name = 'sf_uploads';
    $fsUploads->collapsed = Inputfield::collapsedYes;

    // Remember which group user is working in, so it stays open after Save.
    $active = '';
    try {
      $in = $this->wire('input');
      if($in) {
        $active = (string)($in->post->sf_active_group ?? $in->get->sf_active_group ?? '');
      }
    } catch(\Throwable $e) {}

    $hidActive = $this->modules->get('InputfieldHidden');
    $hidActive->name = 'sf_active_group';
    $hidActive->value = $active;
    $inputfields->add($hidActive);

    // If user was working in a group, keep it open after save.
    if($active === 'general') $fsGeneral->collapsed = Inputfield::collapsedNo;
    if($active === 'ranking') $fsRanking->collapsed = Inputfield::collapsedNo;
    if($active === 'frontend') $fsFrontend->collapsed = Inputfield::collapsedNo;
    if($active === 'design') $fsDesignStudio->collapsed = Inputfield::collapsedNo;
    if($active === 'messages') $fsMessaging->collapsed = Inputfield::collapsedNo;
    if($active === 'permissions') $fsPermissions->collapsed = Inputfield::collapsedNo;
    if($active === 'vip') $fsVip->collapsed = Inputfield::collapsedNo;
    if($active === 'uploads') $fsUploads->collapsed = Inputfield::collapsedNo;



    // Forum root select
    $f = $this->modules->get('InputfieldSelect');
    $f->label = 'Forum root page';
    $f->name = 'forumRoot';
    $f->columnWidth = 50;
    $f->description = 'Pick the /forum/ root page (template forum-root).';
    $f->addOption(0, '— auto —');
    foreach($this->pages->find("template=forum-root, include=all") as $p) {
      $f->addOption($p->id, $p->path);
    }
    $f->value = (int) $data['forumRoot'];
    $fsGeneral->add($f);


    // Date/time display format
    $dt = $this->modules->get('InputfieldSelect');
    $dt->label = 'Date/time display format';
    $dt->name  = 'dateTimeStyle';
    $dt->columnWidth = 50;
    $dt->description = 'Choose how dates/times are displayed on the forum frontend.';
    $dt->addOption('iso_dt', '2025-12-22 18:04 (ISO date + time)');
    $dt->addOption('iso_d', '2025-12-22 (ISO date)');
    $dt->addOption('dmy_short', '22.12.25');
    $dt->addOption('dmy_short_time', '22.12.25 18:04');
    $dt->addOption('dmy_long', '22.12.2025');
    $dt->addOption('dmy_long_time', '22.12.2025 18:04');
    $dt->addOption('d_mon_abbr', '22. dec. 2025');
    $dt->addOption('d_mon_abbr_time', '22. dec. 2025 18:04');
    $dt->addOption('d_mon_full', '22. december 2025');
    $dt->addOption('d_mon_full_time', '22. december 2025 18:04');
    $dt->value = (string) ($data['dateTimeStyle'] ?? 'iso_dt');
    $fsGeneral->add($dt);

    // Posts per page (topic + replies)
    $pp = $this->modules->get('InputfieldInteger');
    $pp->label = 'Posts per page';
    $pp->name  = 'postsPerPage';
    $pp->columnWidth = 50;
    $pp->min = 3;
    $pp->max = 200;
    $pp->description = 'How many posts are shown on each topic page (this includes the topic post itself + replies).';
    $pp->value = (int) ($data['postsPerPage'] ?? 11);
    $fsGeneral->add($pp);

    $st = $this->modules->get('InputfieldCheckbox');
    $st->label = 'Show topic post on every pagination page';
    $st->name  = 'showTopicPostOnAllPages';
    $st->description = 'When enabled, the first (topic) post is shown above replies on every page. When disabled (default), it is only shown on page 1.';
    $st->attr('checked', ((int) ($data['showTopicPostOnAllPages'] ?? 0) ? 1 : 0) ? 'checked' : '');
    $fsGeneral->add($st);

    // Post author column position (topic/replies)
    $pa = $this->modules->get('InputfieldSelect');
    $pa->label = 'Post author column position';
    $pa->name  = 'postAuthorSide';
    $pa->columnWidth = 50;
    $pa->description = 'Choose where the author/user-info column appears on topic/reply cards (desktop). Mobile stays stacked.';
    $pa->addOption('left', 'Left (classic)');
    $pa->addOption('right', 'Right');
    $pa->value = (string) ($data['postAuthorSide'] ?? 'left');
    $fsGeneral->add($pa);


    // Rich editor / content
    $re = clone $this->modules->get('InputfieldToggle');
    $re->label = 'Enable rich editor (CKEditor) on frontend';
    $re->name  = 'enableFrontendWysiwyg';
    $re->attr('value', 1);
    $re->attr('uncheckedValue', 0);
    $re->columnWidth = 50;
    $re->description = 'If enabled, topic creation and replies use a rich text editor (WYSIWYG) instead of a plain textarea.';
    $re->checked = (int)($data['enableFrontendWysiwyg'] ?? 1) ? 1 : 0;
    $re->val($re->checked ? 1 : 0);
    $fsGeneral->add($re);

    // Frontend CKEditor toolbar preset (applies when WYSIWYG is enabled)
    $tb = $this->modules->get('InputfieldSelect');
    $tb->label = 'Frontend editor toolbar';
    $tb->name  = 'frontendCkToolbar';
    $tb->columnWidth = 50;
    $tb->description = 'Choose how many tools are shown in the frontend editor toolbar.';
    $tb->addOption('basic', 'Basic (minimal)');
    $tb->addOption('standard', 'Standard (recommended)');
    $tb->addOption('full', 'Full');
    $tb->value = (string) ($data['frontendCkToolbar'] ?? 'standard');
    $fsGeneral->add($tb);

    $rh = clone $this->modules->get('InputfieldToggle');
    $rh->label = 'Render rich HTML in posts';
    $rh->name  = 'renderRichHtml';
    $rh->attr('value', 1);
    $rh->attr('uncheckedValue', 0);
    $rh->columnWidth = 50;
    $rh->description = 'If disabled, posts/replies are shown as plain text (HTML stripped).';
    $rh->checked = (int)($data['renderRichHtml'] ?? 1) ? 1 : 0;
    $rh->val($rh->checked ? 1 : 0);
    $fsGeneral->add($rh);

    $ai = clone $this->modules->get('InputfieldToggle');
    $ai->label = 'Allow images in posts';
    $ai->name  = 'allowRichImages';
    $ai->attr('value', 1);
    $ai->attr('uncheckedValue', 0);
    $ai->columnWidth = 33;
    $ai->description = 'Allows <img> tags (image URLs) in posts.';
    $ai->checked = (int)($data['allowRichImages'] ?? 1) ? 1 : 0;
    $ai->val($ai->checked ? 1 : 0);
    $fsGeneral->add($ai);

    $ae = clone $this->modules->get('InputfieldToggle');
    $ae->label = 'Allow embeds (YouTube/Vimeo iframes)';
    $ae->name  = 'allowRichEmbeds';
    $ae->attr('value', 1);
    $ae->attr('uncheckedValue', 0);
    $ae->columnWidth = 34;
    $ae->description = 'Allows safe iframes from common providers.';
    $ae->checked = (int)($data['allowRichEmbeds'] ?? 1) ? 1 : 0;
    $ae->val($ae->checked ? 1 : 0);
    $fsGeneral->add($ae);

    $ac = clone $this->modules->get('InputfieldToggle');
    $ac->label = 'Allow code blocks';
    $ac->name  = 'allowRichCode';
    $ac->attr('value', 1);
    $ac->attr('uncheckedValue', 0);
    $ac->columnWidth = 33;
    $ac->description = 'Allows <pre><code> formatting in posts.';
    $ac->checked = (int)($data['allowRichCode'] ?? 1) ? 1 : 0;
    $ac->val($ac->checked ? 1 : 0);
    $fsGeneral->add($ac);


    /************************************************
     * Attachments / Uploads (frontend)
     ************************************************/
    $up = clone $this->modules->get('InputfieldToggle');
    $up->label = 'Enable attachments (uploads) on frontend';
    $up->name  = 'attachmentsEnabled';
    $up->attr('value', 1);
    $up->attr('uncheckedValue', 0);
    $up->description = 'Allows users to upload images/files to topics and replies (with an insert button).';
    $up->checked = (int)($data['attachmentsEnabled'] ?? 1) ? 1 : 0;
    $up->val($up->checked ? 1 : 0);
    $fsUploads->add($up);

    $upi = clone $this->modules->get('InputfieldToggle');
    $upi->label = 'Allow image uploads';
    $upi->name  = 'attachmentsAllowImages';
    $upi->attr('value', 1);
    $upi->attr('uncheckedValue', 0);
    $upi->columnWidth = 33;
    $upi->checked = (int)($data['attachmentsAllowImages'] ?? 1) ? 1 : 0;
    $upi->val($upi->checked ? 1 : 0);
    $fsUploads->add($upi);

    $upf = clone $this->modules->get('InputfieldToggle');
    $upf->label = 'Allow file uploads';
    $upf->name  = 'attachmentsAllowFiles';
    $upf->attr('value', 1);
    $upf->attr('uncheckedValue', 0);
    $upf->columnWidth = 33;
    $upf->checked = (int)($data['attachmentsAllowFiles'] ?? 1) ? 1 : 0;
    $upf->val($upf->checked ? 1 : 0);
    $fsUploads->add($upf);

    $uplb = clone $this->modules->get('InputfieldToggle');
    $uplb->label = 'Lightbox for uploaded images';
    $uplb->name  = 'attachmentsEnableLightbox';
    $uplb->attr('value', 1);
    $uplb->attr('uncheckedValue', 0);
    $uplb->columnWidth = 33;
    $uplb->checked = (int)($data['attachmentsEnableLightbox'] ?? 1) ? 1 : 0;
    $uplb->val($uplb->checked ? 1 : 0);
    $fsUploads->add($uplb);

    $lbLib = $this->modules->get('InputfieldSelect');
    $lbLib->label = 'Lightbox library (for uploaded images)';
    $lbLib->name  = 'attachmentsLightboxLibrary';
    $lbLib->columnWidth = 33;
    $lbLib->description = 'Native Forum does not bundle a lightbox script. This setting only changes emitted classes/attributes so your theme can hook Fancybox/GLightbox.';
    $lbLib->addOption('fancybox', 'Fancybox (emit data-fancybox)');
    $lbLib->addOption('glightbox', 'GLightbox (emit .glightbox class)');
    $lbLib->addOption('none', 'None (no lightbox classes/attrs)');
    $lbLib->value = (string)($data['attachmentsLightboxLibrary'] ?? 'fancybox');
    $lbLib->showIf = 'attachmentsEnableLightbox=1';
    $fsUploads->add($lbLib);

    $mmb = $this->modules->get('InputfieldInteger');
    $mmb->label = 'Max upload size (MB)';
    $mmb->name  = 'attachmentsMaxUploadMB';
    $mmb->columnWidth = 33;
    $mmb->min = 1;
    $mmb->max = 512;
    $mmb->value = (int)($data['attachmentsMaxUploadMB'] ?? 20);
    $mmb->description = 'Applies per file.';
    $fsUploads->add($mmb);

    $mct = $this->modules->get('InputfieldInteger');
    $mct->label = 'Max uploads per post';
    $mct->name  = 'attachmentsMaxFiles';
    $mct->columnWidth = 33;
    $mct->min = 1;
    $mct->max = 50;
    $mct->value = (int)($data['attachmentsMaxFiles'] ?? 10);
    $fsUploads->add($mct);

    $mw = $this->modules->get('InputfieldInteger');
    $mw->label = 'Max image width in posts (px)';
    $mw->name  = 'attachmentsImageMaxWidth';
    $mw->columnWidth = 33;
    $mw->min = 200;
    $mw->max = 2500;
    $mw->value = (int)($data['attachmentsImageMaxWidth'] ?? 900);
    $mw->description = 'Images are resized to fit this width (keeps aspect ratio) and open full size on click.';
    $fsUploads->add($mw);

    $ext = $this->modules->get('InputfieldText');
    $ext->label = 'Allowed file extensions (non-images)';
    $ext->name  = 'attachmentsAllowedExt';
    $ext->description = 'Space-separated list (example: pdf zip docx mp3). Images are controlled separately.';
    $ext->value = (string)($data['attachmentsAllowedExt'] ?? 'pdf zip rar 7z doc docx xls xlsx ppt pptx txt rtf mp3 wav ogg mp4 mov avi');
    $fsUploads->add($ext);


    // Post editing window
    $ew = $this->modules->get('InputfieldSelect');
    $ew->label = 'Users can edit their own posts for';
    $ew->name  = 'postEditWindow';
    $ew->columnWidth = 50;
    $ew->description = 'Controls how long after posting a user can edit their own topics/replies (moderators can always edit).';
    $ew->addOption('0', 'Disabled');
    $ew->addOption('15m', '15 minutes');
    $ew->addOption('1h', '1 hour');
    $ew->addOption('1d', '1 day');
    $ew->addOption('1w', '1 week');
    $ew->addOption('1m', '1 month');
    $ew->addOption('inf', 'Indefinitely');
    $ew->value = (string)($data['postEditWindow'] ?? '1w');
    $fsGeneral->add($ew);

    // Topic following
    $tf = clone $this->modules->get('InputfieldToggle');
    $tf->label = 'Enable topic following (email notifications)';
    $tf->name  = 'enableTopicFollow';
    $tf->attr('value', 1);
    $tf->attr('uncheckedValue', 0);
    $tf->columnWidth = 50;
    $tf->checked = (int)($data['enableTopicFollow'] ?? 1) ? 1 : 0;
    $tf->val($tf->checked ? 1 : 0);
    $tf->description = 'Allows users to follow/unfollow topics. Followers get notified when someone replies.';
    $fsGeneral->add($tf);

    $tfe = clone $this->modules->get('InputfieldToggle');
    $tfe->label = 'Send email notifications to followers';
    $tfe->name  = 'sendFollowEmails';
    $tfe->attr('value', 1);
    $tfe->attr('uncheckedValue', 0);
    $tfe->columnWidth = 50;
    $tfe->checked = (int)($data['sendFollowEmails'] ?? 1) ? 1 : 0;
    $tfe->val($tfe->checked ? 1 : 0);
    $tfe->description = 'If disabled, users can still follow topics, but no emails are sent.';
    $fsGeneral->add($tfe);


// Messages root select
    $m = $this->modules->get('InputfieldSelect');
    $m->label = 'Messages root page';
    $m->name = 'messagesRoot';
    $m->columnWidth = 50;
    $m->description = 'Pick /messages/ root page (template forum-messages-root).';
    $m->addOption(0, '— auto —');
    foreach($this->pages->find("template=forum-messages-root, include=all") as $p) {
      $m->addOption($p->id, $p->path);
    }
    $m->value = (int) $data['messagesRoot'];
    $fsMessaging->add($m);
// Public user profile root select
    $up = $this->modules->get('InputfieldSelect');
    $up->label = 'User profile root page';
    $up->name = 'userProfileRoot';
    $up->description = 'Pick /forum-user/ root page (template forum-user). Used for /forum-user/username/ profile URLs.';
    $up->addOption(0, '— auto —');
    foreach($this->pages->find("template=forum-user, include=all") as $p) {
      $up->addOption($p->id, $p->path);
    }
    $up->value = (int) $data['userProfileRoot'];
    // Keep this in General (requested)
    $fsGeneral->add($up);

    // Allow guests read
    $g = clone $this->modules->get('InputfieldToggle');
    $g->label = 'Allow guests to read forum';
    $g->name = 'allowGuestsRead';
    
    $g->attr('value', 1);
    $g->attr('uncheckedValue', 0);
$g->columnWidth = 34;
    $g->checked = (int) $data['allowGuestsRead'] ? 1 : 0;
    $g->val($g->checked ? 1 : 0);
    $fsPermissions->add($g);

    // Profile visibility controls
    $pvG = clone $this->modules->get('InputfieldToggle');
    $pvG->label = 'Allow guests to view user profiles';
    $pvG->name = 'allowGuestsViewProfiles';
    $pvG->attr('value', 1);
    $pvG->attr('uncheckedValue', 0);
    $pvG->columnWidth = 34;
    $pvG->checked = (int) $data['allowGuestsViewProfiles'] ? 1 : 0;
    $pvG->val($pvG->checked ? 1 : 0);
    $pvG->description = 'If disabled, guests can still see the forum (if allowed) but user profiles will be hidden.';
    $fsPermissions->add($pvG);

    $pvU = clone $this->modules->get('InputfieldToggle');
    $pvU->label = 'Allow logged-in users to view other user profiles';
    $pvU->name = 'allowUsersViewProfiles';
    $pvU->attr('value', 1);
    $pvU->attr('uncheckedValue', 0);
    $pvU->columnWidth = 34;
    $pvU->checked = (int) $data['allowUsersViewProfiles'] ? 1 : 0;
    $pvU->val($pvU->checked ? 1 : 0);
    $pvU->description = 'If disabled, logged-in users can only see their own profile.';
    $fsPermissions->add($pvU);

// Allow avatar upload in profile
    $au = clone $this->modules->get('InputfieldToggle');
    $au->label = 'Allow avatar upload (profile image)';
    $au->name  = 'allowAvatarUpload';
    $au->attr('value', 1);
    $au->attr('uncheckedValue', 0);
    $au->columnWidth = 33;
    $au->description = 'If disabled, users cannot upload/change their profile image from the forum profile settings.';
    $au->checked = (int) ($data['allowAvatarUpload'] ?? 1) ? 1 : 0;
    $au->val($au->checked ? 1 : 0);
    $fsPermissions->add($au);

// Allow guests post
    $gp = clone $this->modules->get('InputfieldToggle');
    $gp->label = 'Allow guests to post topics and replies';
    $gp->name = 'allowGuestsPost';
    
    $gp->attr('value', 1);
    $gp->attr('uncheckedValue', 0);
$gp->columnWidth = 33;
    $gp->description = 'If checked, guests can post as the guest user. You may want to moderate more heavily.';
    $gp->checked = (int) $data['allowGuestsPost'] ? 1 : 0;
    $gp->val($gp->checked ? 1 : 0);
    $fsPermissions->add($gp);
// Allow guests vote (likes & up/down)
    $gv = clone $this->modules->get('InputfieldToggle');
    $gv->label = 'Allow guests to like / vote';
    $gv->name = 'allowGuestsVote';
    
    $gv->attr('value', 1);
    $gv->attr('uncheckedValue', 0);
$gv->columnWidth = 33;
    $gv->description = 'If checked, guests are allowed to like topics and vote on posts.';
    $gv->checked = (int) $data['allowGuestsVote'] ? 1 : 0;
    $gv->val($gv->checked ? 1 : 0);
    $fsPermissions->add($gv);
// Allow private messages
    $pm = clone $this->modules->get('InputfieldToggle');
    $pm->label = 'Enable private messages';
    $pm->name = 'allowPrivateMessages';
    
    $pm->attr('value', 1);
    $pm->attr('uncheckedValue', 0);
$pm->columnWidth = 50;
    $pm->description = 'If unchecked, private messages and related links are disabled.';
    $pm->checked = (int) $data['allowPrivateMessages'] ? 1 : 0;
    $pm->val($pm->checked ? 1 : 0);
    $fsMessaging->add($pm);
// Forum tree layout (applies by moving pages on save)
    $t = $this->modules->get('InputfieldSelect');
    $t->label = 'Forum page tree layout';
    $t->name  = 'treePreset';
    $t->columnWidth = 50;
    $t->description = "Choose how the forum-related pages are arranged in the ProcessWire page tree. When you save module settings, Native Forum will move the forum system pages to match the selected layout. This will change URLs. Install/enable the core PagePathHistory module (recommended) to keep old links working via redirects.";
    $t->addOption('classic', 'Classic: /forum under Home + system pages under Home (default)');
    $t->addOption('singleRoot', 'Single root: everything (messages/profile/login/register/members/user) under /forum');
    $t->addOption('singleRootSplitMessages', 'Single root (split messages): system pages under /forum, but /messages stays under Home');
    $t->addOption('forumRootAccountsOnly', 'Forum root (accounts only): login/register/profile under /forum, members/user + messages under Home');
$t->addOption('forumRootUsersOnly', 'Forum root (users only): members/user/profile under /forum, login/register + messages under Home');
$t->addOption('forumRootMessagingOnly', 'Forum root (messaging only): /messages under /forum, other system pages under Home');
$t->value = (string)($data['treePreset'] ?? 'classic');
    $fsGeneral->add($t);

// Frontend theme / CSS preset
    $th = $this->modules->get('InputfieldSelect');
    $th->label = 'Frontend theme';
    $th->name  = 'frontendTheme';
    $th->columnWidth = 50;
    $th->description = 'Choose which bundled CSS to output for forum templates.';
    $th->addOption('simple', 'Simple built-in theme');
    $th->addOption('bootstrap', 'Bootstrap 5 theme (optionally auto-loadable)');
    $th->value = $data['frontendTheme'];
    $fsFrontend->add($th);

// Design preset (overrides Design Studio colors; Design Studio settings remain saved and apply when switching back to Custom)
$pr = $this->modules->get('InputfieldSelect');
$pr->label = 'Design preset';
$pr->name  = 'designPreset';
$pr->columnWidth = 50;
$pr->description = 'Complete, ready-to-use looks (colours, buttons, forms, editor, messages). Every value you set in Design Studio is applied on top of the chosen preset; empty Design Studio fields keep the preset look.';
$pr->addOption('obsidian', 'Obsidian (dark slate, crimson accent — for dark sites)');
$pr->addOption('light', 'Light (white cards, blue accent)');
$pr->addOption('dark', 'Dark (deep grey, blue accent)');
$pr->addOption('auto', 'Auto (Light or Dark by the visitor\'s system setting)');
$pr->addOption('mono', 'Monochrome (black & white)');
$pr->addOption('custom', 'Custom (teal starting palette — make it yours in Design Studio)');
$pr->value = (string)($data['designPreset'] ?? 'obsidian');
if($pr->value === 'pekarna') $pr->value = 'obsidian';
$fsFrontend->add($pr);

// Legacy setting (hidden): Auto-load Bootstrap 5 (replaced by Bootstrap integration mode)
$ba = clone $this->modules->get('InputfieldToggle');
$ba->label = 'Auto-load Bootstrap 5 (legacy)';
$ba->name  = 'bootstrapAutoLoad';
$ba->columnWidth = 50;
$ba->description = 'Legacy setting kept for backward compatibility. Use Bootstrap integration mode instead.';
$ba->attr('value', 1);
$ba->attr('uncheckedValue', 0);
$ba->checked = (int) ($data['bootstrapAutoLoad'] ?? 0) ? 1 : 0;
$ba->val($ba->checked ? 1 : 0);
$ba->showIf = 'frontendTheme=__legacy__';
$fsFrontend->add($ba);

// Compatibility: Bootstrap integration
$bsInt = $this->modules->get('InputfieldSelect');
$bsInt->label = 'Bootstrap integration mode';
$bsInt->name  = 'bootstrapIntegration';
$bsInt->columnWidth = 50;
$bsInt->description = "Controls how the Bootstrap theme integrates with your site. 'Auto' uses your site's Bootstrap when the page already loads it, otherwise it loads Bootstrap 5 from a CDN (so the forum always looks right). 'Force' always loads Bootstrap 5 from a CDN. 'Off' never loads Bootstrap assets.";
$bsInt->addOption('auto', 'Auto (use site Bootstrap, load it when missing)');
$bsInt->addOption('force', 'Force load Bootstrap 5 from CDN');
$bsInt->addOption('off', 'Off (never load Bootstrap assets)');
$bsInt->value = (string)($data['bootstrapIntegration'] ?? ((int)($data['bootstrapAutoLoad'] ?? 0) ? 'force' : 'auto'));
$bsInt->showIf = 'frontendTheme=bootstrap';
$fsFrontend->add($bsInt);

$bsSite = $this->modules->get('InputfieldSelect');
$bsSite->label = 'Site Bootstrap version';
$bsSite->name  = 'bootstrapSiteVersion';
$bsSite->columnWidth = 25;
$bsSite->description = "Used only for compatibility shims when Bootstrap integration is Auto/Off. If your site runs Bootstrap 4, select '4' to improve spacing/utilities.";
$bsSite->addOption('auto', 'Auto / unknown');
$bsSite->addOption('5', 'Bootstrap 5');
$bsSite->addOption('4', 'Bootstrap 4');
$bsSite->addOption('none', 'None');
$bsSite->value = (string)($data['bootstrapSiteVersion'] ?? 'auto');
$bsSite->showIf = 'frontendTheme=bootstrap';
$fsFrontend->add($bsSite);

$bsShim = clone $this->modules->get('InputfieldToggle');
$bsShim->label = 'Enable Bootstrap compatibility shim';
$bsShim->name  = 'bootstrapCompatShim';
$bsShim->columnWidth = 25;
$bsShim->description = 'Adds a small CSS shim for missing Bootstrap 5 utility classes (safe on BS5, helpful on BS4).';
$bsShim->attr('value', 1);
$bsShim->attr('uncheckedValue', 0);
$bsShim->checked = (int)($data['bootstrapCompatShim'] ?? 1) ? 1 : 0;
$bsShim->val($bsShim->checked ? 1 : 0);
$bsShim->showIf = 'frontendTheme=bootstrap';
$fsFrontend->add($bsShim);

$bsCssUrl = $this->modules->get('InputfieldText');
$bsCssUrl->label = 'Bootstrap 5 CSS URL (Force mode)';
$bsCssUrl->name  = 'bootstrapCssUrl';
$bsCssUrl->columnWidth = 50;
$bsCssUrl->description = 'Used when Bootstrap integration mode is Force.';
$bsCssUrl->value = (string)($data['bootstrapCssUrl'] ?? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');
$bsCssUrl->showIf = 'frontendTheme=bootstrap, bootstrapIntegration=force';
$fsFrontend->add($bsCssUrl);

$bsJsUrl = $this->modules->get('InputfieldText');
$bsJsUrl->label = 'Bootstrap 5 JS URL (Force mode)';
$bsJsUrl->name  = 'bootstrapJsUrl';
$bsJsUrl->columnWidth = 50;
$bsJsUrl->description = 'Used when Bootstrap integration mode is Force.';
$bsJsUrl->value = (string)($data['bootstrapJsUrl'] ?? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js');
$bsJsUrl->showIf = 'frontendTheme=bootstrap, bootstrapIntegration=force';
$fsFrontend->add($bsJsUrl);

// Legacy setting (hidden): Action button icons (replaced by Icon provider)
$ic = $this->modules->get('InputfieldSelect');
$ic->label = 'Action button icons (legacy)';
$ic->name  = 'iconSet';
$ic->columnWidth = 50;
$ic->description = 'Legacy setting kept for backward compatibility. Use Icon provider / Font Awesome version instead.';
$ic->addOption('svg', 'SVG');
$ic->addOption('fontawesome', 'Font Awesome');
$ic->value = (string) ($data['iconSet'] ?? 'svg');
$ic->showIf = 'frontendTheme=__legacy__';
$fsFrontend->add($ic);

// Icon compatibility layer
$ip = $this->modules->get('InputfieldSelect');
$ip->label = 'Icon provider / Font Awesome version';
$ip->name  = 'iconProvider';
$ip->columnWidth = 50;
$ip->description = "Controls icon output and optional Font Awesome loading. Use 'SVG' for maximum compatibility. If your site already loads Font Awesome, choose the matching version and disable auto-load.";
$ip->addOption('svg', 'SVG (built-in, recommended)');
$ip->addOption('fa4', 'Font Awesome 4');
$ip->addOption('fa5', 'Font Awesome 5');
$ip->addOption('fa6', 'Font Awesome 6');
$ip->addOption('site', 'Use SVG (site handles icons)');
$ip->value = (string)($data['iconProvider'] ?? ((string)($data['iconSet'] ?? 'svg') === 'fontawesome' ? 'fa6' : 'svg'));
$fsFrontend->add($ip);

$fs = $this->modules->get('InputfieldSelect');
$fs->label = 'Font Awesome style (FA5/FA6)';
$fs->name  = 'faStyle';
$fs->columnWidth = 50;
$fs->description = "Used only for FA5/FA6. Most icons are 'solid'.";
$fs->addOption('solid', 'Solid');
$fs->addOption('regular', 'Regular');
$fs->addOption('brands', 'Brands (only for brand icons)');
$fs->value = (string)($data['faStyle'] ?? 'solid');
$fs->showIf = 'iconProvider=fa5|iconProvider=fa6';
$fsFrontend->add($fs);



$fa = clone $this->modules->get('InputfieldToggle');
$fa->label = 'Auto-load Font Awesome CSS';
$fa->name  = 'fontAwesomeAutoLoad';
$fa->columnWidth = 50;
$fa->description = 'If enabled and Action button icons is set to Font Awesome, the module will output the Font Awesome CSS on forum pages. Disable this if your site already loads Font Awesome.';
$fa->attr('value', 1);
$fa->attr('uncheckedValue', 0);
$fa->checked = (int) ($data['fontAwesomeAutoLoad'] ?? 0) ? 1 : 0;
$fa->val($fa->checked ? 1 : 0);
$fa->showIf = 'iconProvider=fa4|iconProvider=fa5|iconProvider=fa6';
$fsFrontend->add($fa);

$fau = $this->modules->get('InputfieldText');
$fau->label = 'Font Awesome CSS URL';
$fau->name  = 'fontAwesomeCssUrl';
$fau->columnWidth = 100;
$fau->description = 'Optional. If Auto-load is enabled, this URL is used. Default points to the latest version available on cdnjs at the time this module build was made.';
$fau->value = (string) ($data['fontAwesomeCssUrl'] ?? 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css');
$fau->showIf = 'iconProvider=fa4|iconProvider=fa5|iconProvider=fa6';
$fsFrontend->add($fau);


// Layout presets (both themes)
$fw = $this->modules->get('InputfieldSelect');
$fw->label = 'Forum width';
$fw->name  = 'forumWidthPreset';
$fw->columnWidth = 50;
$fw->description = 'Maximum width of the forum (both themes). "Inherit" uses the full width of your page content area.';
$fw->addOption('container', 'Contained (container)');
$fw->addOption('container-xl', 'Wide (container-xl)');
$fw->addOption('container-fluid', 'Full width (container-fluid)');
$fw->addOption('inherit', 'Inherit template width (no container wrapper)');
$fw->value = $data['forumWidthPreset'] ?? 'container';
$fsFrontend->add($fw);


// Forum style (layout density / compact look)
$stl = $this->modules->get('InputfieldSelect');
$stl->label = 'Forum style';
$stl->name  = 'forumStyle';
$stl->columnWidth = 50;
$stl->description = 'Density of the layout (works with every design preset). "Compact" shows about a third more on screen: slim bars, table-like category and topic lists, smaller avatars and paddings.';
$stl->addOption('classic', 'Classic (roomy, default)');
$stl->addOption('compact', 'Compact (dense, classic-forum look)');
$stl->value = $data['forumStyle'] ?? 'classic';
$fsFrontend->add($stl);

$lc = $this->modules->get('InputfieldSelect');
$lc->label = 'Content / Sidebar widths (desktop)';
$lc->name  = 'layoutMainColsLg';
$lc->columnWidth = 50;
$lc->description = 'Width of the main column vs. the sidebar on desktop (992px and wider). On smaller screens the sidebar goes below the content.';
$lc->addOption('7-5', 'Main 7 / Sidebar 5');
$lc->addOption('8-4', 'Main 8 / Sidebar 4 (recommended)');
$lc->addOption('9-3', 'Main 9 / Sidebar 3');
$lc->addOption('10-2', 'Main 10 / Sidebar 2');
$lc->value = $data['layoutMainColsLg'] ?? '8-4';
$fsFrontend->add($lc);


// Spacing and custom markup wrappers
$ts = $this->modules->get('InputfieldInteger');
$ts->label = 'Top spacing (px)';
$ts->name  = 'forumTopSpacePx';
$ts->columnWidth = 25;
$ts->min = 0;
$ts->max = 2000;
$ts->description = 'Adds extra space above the forum wrapper (useful with sticky headers). Set 0 to disable.';
$ts->value = isset($data['forumTopSpacePx']) ? (int)$data['forumTopSpacePx'] : 0;
$fsFrontend->add($ts);

$bs = $this->modules->get('InputfieldInteger');
$bs->label = 'Bottom spacing (px)';
$bs->name  = 'forumBottomSpacePx';
$bs->columnWidth = 25;
$bs->min = 0;
$bs->max = 2000;
$bs->description = 'Adds extra space below the forum wrapper. Set 0 to disable.';
$bs->value = isset($data['forumBottomSpacePx']) ? (int)$data['forumBottomSpacePx'] : 0;
$fsFrontend->add($bs);

$fb = $this->modules->get('InputfieldCheckbox');
$fb->label = 'Force full width breakout';
$fb->name  = 'forumForceFullWidth';
$fb->columnWidth = 50;
$fb->description = 'With "Full width": stretch the forum to the full browser width (100vw), even when your page wraps content in a narrower container.';
$fb->attr('checked', !empty($data['forumForceFullWidth']) ? 'checked' : '');
$fb->showIf = 'forumWidthPreset=container-fluid';
$fsFrontend->add($fb);

$pm = $this->modules->get('InputfieldTextarea');
$pm->label = 'Prepend markup (optional)';
$pm->name  = 'forumPrependMarkup';
	$pm->description = 'Custom markup you want to output before the forum. Use together with the placement setting below.';
$pm->rows = 4;
$pm->value = $data['forumPrependMarkup'] ?? '';
$fsFrontend->add($pm);

	$pp = $this->modules->get('InputfieldSelect');
	$pp->label = 'Prepend placement';
	$pp->name  = 'forumPrependPlacement';
	$pp->columnWidth = 50;
	$pp->description = 'Where to output the prepend markup. Use "Outside forum" if you want to open your own wrapper/container before the forum.';
	$pp->addOption('inside_container', 'Inside forum container (default)');
	$pp->addOption('inside_wrap', 'Inside forum wrapper (outside container)');
	$pp->addOption('outside_forum', 'Outside forum (before wrapper)');
	$pp->value = $data['forumPrependPlacement'] ?? 'inside_container';
	$fsFrontend->add($pp);

$am = $this->modules->get('InputfieldTextarea');
$am->label = 'Append markup (optional)';
$am->name  = 'forumAppendMarkup';
	$am->description = 'Custom markup you want to output after the forum. Use together with the placement setting below.';
$am->rows = 4;
$am->value = $data['forumAppendMarkup'] ?? '';
$fsFrontend->add($am);

	$ap = $this->modules->get('InputfieldSelect');
	$ap->label = 'Append placement';
	$ap->name  = 'forumAppendPlacement';
	$ap->columnWidth = 50;
	$ap->description = 'Where to output the append markup. Use "Outside forum" if you want to close wrappers you opened in the prepend.';
	$ap->addOption('inside_container', 'Inside forum container (default)');
	$ap->addOption('inside_wrap', 'Inside forum wrapper (outside container)');
	$ap->addOption('outside_forum', 'Outside forum (after wrapper)');
	$ap->value = $data['forumAppendPlacement'] ?? 'inside_container';
	$fsFrontend->add($ap);

// Sidebar display controls
$sd = $this->modules->get('InputfieldSelect');
$sd->label = 'Sidebar display';
$sd->name  = 'sidebarDisplayMode';
$sd->columnWidth = 50;
$sd->description = 'Control when the sidebar is visible in the Bootstrap theme.';
$sd->addOption('always', 'Show sidebar everywhere');
$sd->addOption('never', 'Hide sidebar everywhere');
$sd->addOption('custom', 'Show only on selected views');
$sd->value = $data['sidebarDisplayMode'] ?? 'always';
$fsFrontend->add($sd);

$st = $this->modules->get('InputfieldCheckboxes');
$st->label = 'Show sidebar on these views';
$st->name  = 'sidebarShowTemplates';
$st->description = 'Only used when Sidebar display is set to "Show only on selected views".';
$st->showIf = 'sidebarDisplayMode=custom';
$st->addOption('forum-root', 'Forum index');
$st->addOption('forum-group', 'Group');
$st->addOption('forum-category', 'Category');
$st->addOption('forum-topic', 'Topic');
$st->addOption('forum-members', 'Members');
$st->addOption('forum-profile', 'Profile');
$st->addOption('forum-messages-root', 'Messages (root)');
$st->addOption('forum-message', 'Message thread');
$st->addOption('forum-user', 'User');
$st->value = $data['sidebarShowTemplates'] ?? ['forum-root','forum-members'];
$fsFrontend->add($st);

    // -----------------------------
    // Design Studio UI (new system)
    // -----------------------------
    try {
      $modUrl = $this->config->urls($this);
      if($modUrl) {
        // Reuse existing admin color picker for Design Studio inputs
        $this->config->styles->add($modUrl . 'assets/admin-colorpicker/sf-admin-colorpicker.css');
        $this->config->scripts->add($modUrl . 'assets/admin-colorpicker/sf-admin-colorpicker.js');

        // Help tooltips for Design Studio settings (show selectors + description)
        $this->config->styles->add($modUrl . 'assets/admin-help/sf-admin-help.css?v=106b');
        $this->config->scripts->add($modUrl . 'assets/admin-help/sf-admin-help.js?v=106b');
      }
    } catch(\Throwable $e) {}

    $tplOptions = [
      'global' => 'Global (all templates)',
      'forum-root' => 'Forum index (forum-root)',
      'forum-group' => 'Group (forum-group)',
      'forum-category' => 'Category (forum-category)',
      'forum-topic' => 'Topic (forum-topic)',
      'forum-reply' => 'Reply (forum-reply)',
      'forum-members' => 'Members (forum-members)',
      'forum-user' => 'User (forum-user)',
      'forum-profile' => 'Profile (forum-profile)',
      'forum-login' => 'Login (forum-login)',
      'forum-register' => 'Register (forum-register)',
      'forum-messages-root' => 'Messages root (forum-messages-root)',
      'forum-message' => 'Message thread (forum-message)',
    ];

    $sectionOptions = [
      'colors'     => 'Colors (tokens)',
      'typography' => 'Typography',
      'layout'     => 'Layout & spacing',
      'header'     => 'Header',
      'sidebar'    => 'Sidebar',
      'topic'      => 'Topic & Posts',
      'tags'       => 'Tags',
      'buttons'    => 'Buttons',
      'forms'      => 'Forms (inputs)',
      'vipbadges'  => 'VIP & Badges',
      'popups'     => 'Popups (usercard & tooltips)',
      'customcss'  => 'Custom CSS',
    ];

    $uiTpl = (string)($data['dsUiTpl'] ?? 'global');
    $uiSec = (string)($data['dsUiSection'] ?? 'typography');

    // If admin changed dropdowns (form submit), prefer current request value
    try {
      $in = $this->wire('input');
      if($in) {
        $gt = (string)($in->get->dsUiTpl ?? $in->post->dsUiTpl ?? '');
        $gs = (string)($in->get->dsUiSection ?? $in->post->dsUiSection ?? '');
        if($gt !== '') $uiTpl = $gt;
        if($gs !== '') $uiSec = $gs;
      }
    } catch(\Throwable $e) {}

    if(!isset($tplOptions[$uiTpl])) $uiTpl = 'global';
    if(!isset($sectionOptions[$uiSec])) $uiSec = 'typography';

    $mInfo = $this->modules->get('InputfieldMarkup');
    $mInfo->label = 'Design Studio';
    $mInfo->value = "<p style='margin:0'>Edit <b>Global</b> values (defaults), then optionally enable <b>override</b> per template. Disabled overrides fall back to Global.</p>";
    $fsDesignStudio->add($mInfo);

    // Reset group (kept separate so it doesn't look like it's part of a section)
    $fsReset = $this->modules->get('InputfieldFieldset');
    $fsReset->label = 'Reset'
      ;
    $fsReset->name = 'sf_ds_reset';
    $fsReset->collapsed = Inputfield::collapsedYes;
    $fsReset->description = 'Use this if you experimented with colors/layout and want to go back to the default look.';

    $resetBtn = $this->modules->get('InputfieldSubmit');
    $resetBtn->name = 'sf_reset_design';
    $resetBtn->value = 'Reset all styling';
    $resetBtn->label = '';
    $resetBtn->description = 'This will clear all Design Studio values and older style settings, so the forum inherits defaults again.';
    $resetBtn->attr('class', 'ui-button ui-state-error');
    $resetBtn->notes = 'Tip: After reset, refresh the page with Ctrl+F5 to ensure cached CSS is cleared.';
    $fsReset->add($resetBtn);
    $fsDesignStudio->add($fsReset);

    $selTpl = $this->modules->get('InputfieldSelect');
    $selTpl->label = 'Template';
    $selTpl->name  = 'dsUiTpl';
    $selTpl->columnWidth = 50;
    // Refresh config screen when selection changes, but keep Design Studio open
    $selTpl->addClass('sf-ds-tpl-select');
    foreach($tplOptions as $k => $lab) $selTpl->addOption($k, $lab);
    $selTpl->value = $uiTpl;
    $fsDesignStudio->add($selTpl);

    $selSec = $this->modules->get('InputfieldSelect');
    $selSec->label = 'Section';
    $selSec->name  = 'dsUiSection';
    $selSec->columnWidth = 50;
    // Refresh config screen when selection changes, but keep Design Studio open
    $selSec->addClass('sf-ds-sec-select');
    foreach($sectionOptions as $k => $lab) $selSec->addOption($k, $lab);
    $selSec->value = $uiSec;
    $fsDesignStudio->add($selSec);

    // Section definitions: key => [label, type, placeholder]
    $sectionDefs = [
      'typography' => [
        'fontFamily' => ['Font family', 'text', 'inherit / system-ui / "Poppins"'],
        'baseSize'   => ['Base font size', 'text', '16px'],
        'lineHeight' => ['Line height', 'text', '1.5'],
        'h1Size'     => ['H1 size', 'text', '22px'],
        'h2Size'     => ['H2 size', 'text', '18px'],
        'smallSize'  => ['Small text size', 'text', '13px'],
        'metaSize'   => ['Meta text size', 'text', '12px'],
        'labelSize'  => ['Form label size', 'text', '14px'],
        'labelColor' => ['Form label color', 'color', '#222222ff'],

        'indexTitleSize'  => ['Index/category title size', 'text', '16px'],
        'indexTitleColor' => ['Index/category title color', 'color', '#222222ff'],
        'indexTitleHoverColor' => ['Index/category title hover', 'color', '#0d6efdff'],
        'titleWeight' => ['Title font weight', 'text', 'inherit (blank) / 400 / 500 / 600 / 700'],
        'lastTitleSize'  => ['Last post title size', 'text', '13px'],
        'lastTitleColor' => ['Last post title color', 'color', '#0d6efdff'],
      ],
      'colors' => [
        'bg'          => ['Background', 'color', '#ffffffff'],
        'surface'     => ['Surface', 'color', '#ffffffff'],
        'surface2'    => ['Surface 2', 'color', '#ffffffff'],
        'border'      => ['Border', 'color', '#00000014'],
        'text'        => ['Text', 'color', '#222222ff'],
        'muted'       => ['Muted', 'color', '#6c757dff'],
        'primary'     => ['Primary', 'color', '#0d6efdff'],
        'primaryText' => ['Primary text', 'color', '#ffffffff'],
        'accent'      => ['Accent', 'color', '#6610f2ff'],
        'success'     => ['Success', 'color', '#198754ff'],
        'warning'     => ['Warning', 'color', '#ffc107ff'],
        'danger'      => ['Danger', 'color', '#dc3545ff'],
        'radius'      => ['Radius', 'text', '12px'],
        'space'       => ['Spacing (gap)', 'text', '16px'],
      ],
      'layout' => [
        'pagePadX' => ['Page padding X', 'text', '24px'],
        'pagePadY' => ['Page padding Y', 'text', '24px'],
        'indexRowPad' => ['Index/category row padding', 'text', '12px 14px'],
        'indexRowBorder' => ['Index/category row divider', 'text', '1px solid rgba(0,0,0,.12)'],
        'indexGroupGap' => ['Index group spacing', 'text', '16px'],
        'groupInnerGap' => ['Group header inner spacing', 'text', '10px'],
        'sidebarBlockGap' => ['Sidebar block spacing', 'text', '12px'],
'indexRowGap' => ['Index row spacing', 'text', '6px'],
        'topicRowGap' => ['Topic row spacing', 'text', '6px'],
        'postRowGap' => ['Post spacing', 'text', '12px'],
        'pageheadGap' => ['Page header spacing', 'text', '12px'],
      ],
      'header' => [
        'headerBg'       => ['Header background', 'color', '#ffffffff'],
        'headerText'     => ['Header text', 'color', '#222222ff'],
        'headerBorder'   => ['Header border', 'text', '1px solid rgba(0,0,0,.08)'],
        'headerPad'      => ['Header padding', 'text', '12px 14px'],
        'pageTitleSize'  => ['Page title size', 'text', '20px'],
        'pageTitleColor' => ['Page title color', 'color', '#222222ff'],
      ],
      'sidebar' => [
        'sideBg'         => ['Sidebar background', 'color', '#ffffffff'],
        'sideText'       => ['Sidebar text', 'color', '#222222ff'],
        'sidePad'        => ['Sidebar padding', 'text', '12px'],
        'sideInnerPad'   => ['Sidebar inner padding', 'text', '12px'],
        'sidebarTitleSize' => ['Sidebar title size', 'text', '15px'],
      ],
      'topic' => [
        'topicRowBg'     => ['Topic row background', 'color', '#ffffffff'],
        'topicRowText'   => ['Topic row text', 'color', '#222222ff'],
        'topicPostBg'    => ['Topic post background', 'color', '#ffffffff'],
        'topicPostText'  => ['Topic post text', 'color', '#222222ff'],
        'replyBg'        => ['Reply background', 'color', '#ffffffff'],
        'replyText'      => ['Reply text', 'color', '#222222ff'],
        'pinnedRowBg'   => ['Pinned topic row background', 'color', '#fff3cdff'],
        'pinnedRowText' => ['Pinned topic row text', 'color', '#111111ff'],
        'pinnedRowPad'  => ['Pinned topic row padding', 'text', 'inherit'],
        'pinnedRowRadius' => ['Pinned topic row radius', 'text', 'inherit'],
        'pinnedTitleWeight' => ['Pinned topic title weight', 'text', 'inherit'],
        'userInfoBg'     => ['User info background', 'color', '#ffffffff'],
        'userInfoText'   => ['User info text', 'color', '#222222ff'],
        'signatureSepColor' => ['Signature separator color', 'color', '#00000026'],
        'signatureSepSize' => ['Signature separator thickness', 'text', '1px'],
      ],
      'tags' => [
        'tagBg'        => ['Tag background', 'color', '#00000014'],
        'tagText'      => ['Tag text', 'color', '#111111ff'],
        'tagBorder'    => ['Tag border', 'text', '0'],
        'tagRadius'    => ['Tag radius', 'text', '999px'],
        'tagPad'       => ['Tag padding', 'text', '.15em .55em'],
        'tagSize'      => ['Tag font size', 'text', '12px'],
        'tagWeight'    => ['Tag font weight', 'text', '500'],
        'tagGap'       => ['Tag gap', 'text', '6px'],
        'tagHoverBg'   => ['Tag hover background', 'color', '#0000001f'],
        'tagHoverText' => ['Tag hover text', 'color', '#111111ff'],
      ],

      'buttons' => [
        // Global
        'btnRadius'      => ['Button radius', 'text', '10px'],
        'btnPadding'     => ['Button padding', 'text', '8px 12px'],
        'btnBorder'      => ['Button border color', 'color', '#00000026'],
        'btnText'        => ['Default button text color', 'color', '#222222ff'],
        'btnFocusRing'   => ['Focus ring color', 'color', '#0d6efd55'],

        // Primary action
        'btnPrimaryBg'        => ['Primary button background', 'color', '#0d6efdff'],
        'btnPrimaryText'      => ['Primary button text', 'color', '#ffffffff'],
        'btnPrimaryHoverBg'   => ['Primary button hover background', 'color', '#0b5ed7ff'],
        'btnPrimaryHoverText' => ['Primary button hover text', 'color', '#ffffffff'],

        // Secondary/tool
        'btnSecondaryBg'      => ['Secondary/tool button background', 'color', '#6c757dff'],
        'btnSecondaryText'    => ['Secondary/tool button text', 'color', '#ffffffff'],
        'btnSecondaryHoverBg' => ['Secondary/tool button hover background', 'color', '#5c636aff'],
      ],
      'forms' => [
        'inputRadius'    => ['Input radius', 'text', '10px'],
        'inputPadding'   => ['Input padding', 'text', '8px 12px'],
      ],
      'vip' => [
        'vipHeaderBg'    => ['VIP header background', 'color', '#dc3545ff'],
        'vipHeaderText'  => ['VIP header text', 'color', '#ffffffff'],
        'vipHeaderSize'  => ['VIP header text size', 'text', '14px'],
      ],

      'badges' => [
        'badgeFontSize'   => ['Badge text size', 'text', '12px'],
        'badgeRadius'     => ['Badge radius', 'text', '10px'],
        'badgePad'        => ['Badge padding', 'text', '.25em .5em'],
        'pinnedBadgeBg'   => ['Pinned badge background', 'color', '#ffc107ff'],
        'pinnedBadgeText' => ['Pinned badge text', 'color', '#111111ff'],
        'vipBadgeBg'      => ['VIP badge background', 'color', '#dc3545ff'],
        'vipBadgeText'    => ['VIP badge text', 'color', '#ffffffff'],
      ],

      'popups' => [
        'usercardBg'     => ['User info popup background', 'color', '#ffffffff'],
        'usercardText'   => ['User info popup text', 'color', '#222222ff'],
        'usercardBorder' => ['User info popup border', 'color', '#0000001f'],
        'usercardRadius' => ['User info popup radius', 'text', '12px'],
        'usercardShadow' => ['User info popup shadow', 'text', '0 10px 30px rgba(0,0,0,.15)'],
        'usercardHeaderBg'   => ['User info popup header background', 'color', '#00000024'],
        'usercardTitleSize'  => ['User info popup name size', 'text', '16px'],
        'usercardRankSize'   => ['User info popup rank size', 'text', '12px'],
        'usercardMetaSize'   => ['User info popup meta size', 'text', '12px'],
        'usercardStatNumSize'=> ['User info popup stat number size', 'text', '16px'],
        'usercardStatLblSize'=> ['User info popup stat label size', 'text', '12px'],
        'usercardBtnBg'      => ['User info popup button background', 'color', '#00000000'],
        'usercardBtnText'    => ['User info popup button text', 'color', '#0d6efdff'],
        'usercardBtnBorder'  => ['User info popup button border', 'color', '#0000002e'],
        'usercardBtnSize'    => ['User info popup button text size', 'text', '13px'],
        'usercardBtnRadius'  => ['User info popup button radius', 'text', '10px'],
        'tooltipBg'      => ['Hover tooltip background', 'color', '#111111ff'],
        'tooltipText'    => ['Hover tooltip text', 'color', '#ffffffff'],
        'tooltipRadius'  => ['Hover tooltip radius', 'text', '8px'],
        'tooltipShadow'  => ['Hover tooltip shadow', 'text', '0 10px 24px rgba(0,0,0,.25)'],
      ],
      'customcss' => [
        'customCss' => ['Custom CSS (global)', 'textarea', '/* your css */'],
      ],
    ];

    // 1.0.6: every section is rendered at once and switched in the browser (no page reload, open
    // panels stay open). Each section lives in its own fieldset; the Section dropdown only shows/hides.
    $sfDsActiveSec = $uiSec;
    $sfDsRoot = $fsDesignStudio;
    $sfDsAll = $this->dsPresetValues();
    $sfDsKeyNow = $this->dsPresetKey($data);
    $sfDsPresetNow = $sfDsAll[$sfDsKeyNow] ?? [];
    $sfDsJs = $this->modules->get('InputfieldMarkup');
    $sfDsJs->attr('name', 'sf_ds_preset_info');
    $sfDsJs->label = 'Preset values';
    $sfDsJs->skipLabel = Inputfield::skipLabelHeader;
    $sfDsJs->value = "<p class='sf-ds-preset-note'>Fields show the values of the selected preset (<b class='sf-ds-preset-name'>" . $this->sanitizer->entities(ucfirst(explode('|', $sfDsKeyNow)[1]) . ' · ' . (explode('|', $sfDsKeyNow)[0] === 'bootstrap' ? 'Bootstrap' : 'Simple') . ' theme') . "</b>). Change any of them to customise; fields you changed are marked and can be reset to the preset value. Values equal to the preset are not stored, so switching presets keeps working. Empty fields inherit from your site.</p>"
      . "<script>window.sfDsPresetValues=" . json_encode($sfDsAll, JSON_UNESCAPED_SLASHES) . ";window.sfDsPresetKey=" . json_encode($sfDsKeyNow) . ";</script>";
    $sfDsRoot->add($sfDsJs);
    foreach(array_keys($sectionOptions) as $uiSec) {
    $fsDesignStudio = $this->modules->get('InputfieldFieldset');
    $fsDesignStudio->attr('name', 'sf_ds_sec_' . $uiSec);
    $fsDesignStudio->label = $sectionOptions[$uiSec];
    $fsDesignStudio->collapsed = Inputfield::collapsedNo;
    $fsDesignStudio->addClass('sf-ds-sec', 'wrapClass');
    if($uiSec !== $sfDsActiveSec) $fsDesignStudio->addClass('sf-ds-sec-hidden', 'wrapClass');
    $sfDsRoot->add($fsDesignStudio);
    if($uiSec === 'vipbadges') {
      $defs = [];
      foreach(($sectionDefs['vip'] ?? []) as $k => $meta)   { $defs['vip__' . $k] = $meta; }
      foreach(($sectionDefs['badges'] ?? []) as $k => $meta){ $defs['badges__' . $k] = $meta; }
    } else {
      $defs = $sectionDefs[$uiSec] ?? [];
    }

    // Grouped UI containers for VIP & Badges
    $fsVipBadgesVip = null;
    $fsVipBadgesBadges = null;
    if($uiSec === 'vipbadges') {
      $fsVipBadgesVip = $this->modules->get('InputfieldFieldset');
      $fsVipBadgesVip->label = $this->_('VIP Sections / Topics');
      $fsVipBadgesVip->name = 'sf_ds_vip_sections';
      $fsVipBadgesVip->collapsed = Inputfield::collapsedNo;
      $fsVipBadgesVip->description = $this->_('Styling for VIP headers and VIP labels.');
      $fsDesignStudio->add($fsVipBadgesVip);

      $fsVipBadgesBadges = $this->modules->get('InputfieldFieldset');
      $fsVipBadgesBadges->label = $this->_('Badges');
      $fsVipBadgesBadges->name = 'sf_ds_badges';
      $fsVipBadgesBadges->collapsed = Inputfield::collapsedNo;
      $fsVipBadgesBadges->description = $this->_('Global badge style + pinned/VIP badge colors.');
      $fsDesignStudio->add($fsVipBadgesBadges);
    }


    // Buttons: show settings inside clear, collapsible groups (so users can see what affects what).
    $btnFsGlobal = $btnFsPrimary = $btnFsSecondary = null;
    if($uiSec === 'buttons') {
      $btnFsGlobal = $this->modules->get('InputfieldFieldset');
      $btnFsGlobal->label = 'Global button style';
      $btnFsGlobal->description = 'Affects all forum buttons (New topic, Reply, Search, Settings/gear, Login/Logout, Lock/Pin…).';
      $btnFsGlobal->collapsed = Inputfield::collapsedNo;

      $btnFsPrimary = $this->modules->get('InputfieldFieldset');
      $btnFsPrimary->label = 'Primary action buttons';
      $btnFsPrimary->description = 'Main action buttons (example: New topic).';
      $btnFsPrimary->collapsed = Inputfield::collapsedYes;

      $btnFsSecondary = $this->modules->get('InputfieldFieldset');
      $btnFsSecondary->label = 'Secondary / tool buttons';
      $btnFsSecondary->description = 'Smaller actions (example: Reply, View posts, Settings/gear, Login/Logout, Lock/Pin…).';
      $btnFsSecondary->collapsed = Inputfield::collapsedYes;

      $fsDesignStudio->add($btnFsGlobal);
      $fsDesignStudio->add($btnFsPrimary);
      $fsDesignStudio->add($btnFsSecondary);
    }

    $cfgGet = function(string $name, $fallback = '') use ($data) {
      return array_key_exists($name, $data) ? $data[$name] : $fallback;
    };

    foreach($defs as $k => $def) {
      // Some definitions (like markup headings) may omit placeholder.
      // Be defensive to avoid PHP warnings.
      $lab  = $def[0] ?? '';
      $type = $def[1] ?? 'text';
      $ph   = $def[2] ?? '';

      // Section headings / helper text
      if($type === 'markup') {
        $mk = $this->modules->get('InputfieldMarkup');
        $mk->name = "dsUiMarkup_{$uiSec}_{$k}";
        $mk->label = '';
        $mk->collapsed = Inputfield::collapsedNo;
        $mk->value = (string)$lab;
        $mk->columnWidth = 100;
        $fsDesignStudio->add($mk);
        continue;
      }

      // Help tooltips for settings (shown on hover over label)
      // Map keys use "section.key".
      $helpMap = [
        // Colors / tokens
        'colors.bg' => [
          'affects' => ['.sf-wrap', '.sf-container'],
          'desc' => 'Overall forum background behind everything. Set to transparent to show your site background.',
        ],
        'colors.surface' => [
          'affects' => ['.sf-panel.card', '.sf-sidecard.card', '.list-group-item', '.card-body'],
          'desc' => 'Surface (cards/panels) background. This is the main visible background in the Bootstrap theme.',
        ],
        'colors.surface2' => [
          'affects' => ['.sf-card-header', '.sf-sidecard-h', '.card-header'],
          'desc' => 'Secondary surface (headers/secondary blocks).',
        ],
        'colors.border' => [
          'affects' => ['.sf-panel', '.sf-sidecard', '.sf-forum-row', '.card', '.list-group-item'],
          'desc' => 'Default border color for forum blocks (also maps to Bootstrap border variables in Bootstrap theme).',
        ],
        'colors.text' => [
          'affects' => ['.sf-wrap', '.sf-panel', '.sf-sidecard'],
          'desc' => 'Base text color used across forum UI.',
        ],
        'colors.muted' => [
          'affects' => ['.sf-muted-sm', '.text-muted'],
          'desc' => 'Muted text color (meta info, timestamps, helpers).',
        ],
        'colors.primary' => [
          'affects' => ['.btn-primary', '.sf-link-title'],
          'desc' => 'Primary accent color used for links and primary actions.',
        ],
        'colors.primaryText' => [
          'affects' => ['.btn-primary', '.badge'],
          'desc' => 'Text color used on top of primary backgrounds.',
        ],

        // Typography
        'typography.fontFamily' => [
          'affects' => ['.sf-wrap'],
          'desc' => 'Font family for the whole forum wrapper.',
        ],
        'typography.baseSize' => [
          'affects' => ['.sf-wrap'],
          'desc' => 'Base font size used across the forum.',
        ],
        'typography.lineHeight' => [
          'affects' => ['.sf-wrap'],
          'desc' => 'Line height for text inside the forum.',
        ],
        'typography.h1Size' => [
          'affects' => ['.sf-page-title', '.sf-title'],
          'desc' => 'Main page title size.',
        ],
        'typography.h2Size' => [
          'affects' => ['.sf-card-header', '.sf-sidecard-h'],
          'desc' => 'Section header size (panel headers, sidebar card headers).',
        ],
        'typography.smallSize' => [
          'affects' => ['.small', '.sf-muted-sm'],
          'desc' => 'Small text size (helper text).',
        ],
        'typography.metaSize' => [
          'affects' => ['.sf-muted-sm', '.sf-post-meta'],
          'desc' => 'Meta text size (author/date rows).',
        ],

        'typography.labelSize' => [
          'affects' => ['.form-label', 'label'],
          'desc' => 'Font size for form labels (topic form, reply form, profile forms). Use px (example: 14px).',
        ],
        'typography.labelColor' => [
          'affects' => ['.form-label', 'label'],
          'desc' => 'Text color for form labels (topic form, reply form, profile forms).',
        ],

        'typography.titleWeight' => [
          'affects' => ['.sf-h1', '.sf-link-title', '.sf-lastpost-title'],
          'desc' => 'Font weight for main forum titles/links (category titles on index, topic links, and the page title). Leave empty to inherit your site theme.',
        ],

        // Index/category titles
        'typography.indexTitleSize' => [
          'affects' => ['.sf-link-title.sf-index-title'],
          'desc' => 'Font size for category titles on index and similar lists.',
        ],
        'typography.indexTitleColor' => [
          'affects' => ['.sf-link-title.sf-index-title'],
          'desc' => 'Text color for category titles on index and similar lists.',
        ],
        'typography.indexTitleHoverColor' => [
          'affects' => ['.sf-link-title.sf-index-title:hover'],
          'desc' => 'Hover color for category titles on index.',
        ],

        // Sidebar
        'sidebar.sideBg' => [
          'affects' => ['.sf-sidecard', '.sf-sidebar'],
          'desc' => 'Sidebar blocks background (cards).',
        ],
        'sidebar.sideText' => [
          'affects' => ['.sf-sidecard', '.sf-sidebar'],
          'desc' => 'Sidebar text color.',
        ],
        'sidebar.sidePad' => [
          'affects' => ['.sf-sidebar'],
          'desc' => 'Padding around sidebar column.',
        ],
        'sidebar.sideInnerPad' => [
          'affects' => ['.sf-sidecard-b', '.card-body.sf-sidecard-b'],
          'desc' => 'Padding inside sidebar cards.',
        ],
        'sidebar.sidebarTitleSize' => [
          'affects' => ['.sf-sidecard-h', '.card-header.sf-sidecard-h'],
          'desc' => 'Sidebar card header font size.',
        ],

        // Topic / Posts
        'topic.topicRowBg' => [
          'affects' => ['.sf-topic-rowitem'],
          'desc' => 'Background of topic rows in category listings.',
        ],
        'topic.topicRowText' => [
          'affects' => ['.sf-topic-rowitem'],
          'desc' => 'Text color used in topic rows.',
        ],
        'topic.topicPostBg' => [
          'affects' => ['.sf-post-card[data-sf-kind=topic]'],
          'desc' => 'Background of the first (topic) post card.',
        ],
        'topic.topicPostText' => [
          'affects' => ['.sf-post-card[data-sf-kind=topic]'],
          'desc' => 'Text color of the first (topic) post card.',
        ],
        'topic.replyBg' => [
          'affects' => ['.sf-post-card[data-sf-kind=reply]'],
          'desc' => 'Background of reply cards.',
        ],
        'topic.replyText' => [
          'affects' => ['.sf-post-card[data-sf-kind=reply]'],
          'desc' => 'Text color of reply cards.',
        ],
        'topic.signatureSepColor' => [
          'affects' => ['.sf-signature'],
          'desc' => 'Border line color above the user signature inside posts/replies (separate from overall post border).',
        ],
        'topic.signatureSepSize' => [
          'affects' => ['.sf-signature'],
          'desc' => 'Border thickness above the user signature (example: 1px).',
        ],
        'topic.userInfoBg' => [
          'affects' => ['.sf-userinfo'],
          'desc' => 'Background of the user info column inside posts.',
        ],
        'topic.userInfoText' => [
          'affects' => ['.sf-userinfo'],
          'desc' => 'Text color of the user info column inside posts.',
        ],

        // Popups
        'popups.usercardBg' => [
          'affects' => ['.sf-usercard'],
          'desc' => 'Background of the user hover popup (hover over username).',
        ],
        'popups.usercardText' => [
          'affects' => ['.sf-usercard'],
          'desc' => 'Text color inside the user hover popup.',
        ],
        'popups.usercardHeaderBg' => [
          'affects' => ['.sf-usercard .sf-usercard-top'],
          'desc' => 'Background of the top header area in the user hover popup.',
        ],
        'popups.usercardTitleSize' => [
          'affects' => ['.sf-usercard .sf-usercard-name'],
          'desc' => 'Font size of the username in the user hover popup.',
        ],
        'popups.usercardRankSize' => [
          'affects' => ['.sf-usercard .sf-usercard-rank'],
          'desc' => 'Font size of the rank line in the user hover popup.',
        ],
        'popups.usercardMetaSize' => [
          'affects' => ['.sf-usercard .sf-usercard-meta'],
          'desc' => 'Font size of the meta lines (Joined / Last seen).',
        ],
        'popups.usercardStatNumSize' => [
          'affects' => ['.sf-usercard .sf-usercard-stat-num'],
          'desc' => 'Font size of the numbers in the stats row.',
        ],
        'popups.usercardStatLblSize' => [
          'affects' => ['.sf-usercard .sf-usercard-stat-lbl'],
          'desc' => 'Font size of the labels in the stats row.',
        ],
        'popups.usercardBtnBg' => [
          'affects' => ['.sf-usercard .sf-usercard-btn'],
          'desc' => 'Background color of buttons in the user hover popup.',
        ],
        'popups.usercardBtnText' => [
          'affects' => ['.sf-usercard .sf-usercard-btn'],
          'desc' => 'Text color of buttons in the user hover popup.',
        ],
        'popups.usercardBtnBorder' => [
          'affects' => ['.sf-usercard .sf-usercard-btn'],
          'desc' => 'Border color of buttons in the user hover popup.',
        ],
        'popups.usercardBtnSize' => [
          'affects' => ['.sf-usercard .sf-usercard-btn'],
          'desc' => 'Font size of buttons in the user hover popup.',
        ],
        'popups.usercardBtnRadius' => [
          'affects' => ['.sf-usercard .sf-usercard-btn'],
          'desc' => 'Border radius of buttons in the user hover popup.',
        ],
        'popups.tooltipBg' => [
          'affects' => ['.sf-tooltip'],
          'desc' => 'Background of small hover tooltips (like long title hover).',
        ],
        'popups.tooltipText' => [
          'affects' => ['.sf-tooltip'],
          'desc' => 'Text color of hover tooltips.',
        ],
      
        'layout.sidebarBlockGap' => [
          'affects' => ['.sf-sidecard', '.sf-side (sidebar blocks)'],
          'desc' => 'Spacing (vertical gap) between sidebar blocks/cards. Increase it to separate sidebar widgets more clearly.',
        ],

        'vip.vipHeaderBg' => [
          'affects' => ['.sf-vip .card-header', '.sf-vip-header'],
          'desc' => 'Background color for VIP headers (VIP categories/topics).',
        ],
        'vip.vipHeaderText' => [
          'affects' => ['.sf-vip .card-header', '.sf-vip-header'],
          'desc' => 'Text color for VIP headers (VIP categories/topics).',
        ],
        'vip.vipHeaderSize' => [
          'affects' => ['.sf-vip .card-header', '.sf-vip-header'],
          'desc' => 'Font size for VIP header text. Use px (example: 14px).',
        ],

        'badges.badgeFontSize' => [
          'affects' => ['.sf-pinned-badge', '.sf-vip-badge'],
          'desc' => 'Base font size for forum badges. Use px (example: 12px).',
        ],
        'badges.badgeRadius' => [
          'affects' => ['.sf-pinned-badge', '.sf-vip-badge'],
          'desc' => 'Badge border radius (example: 10px).',
        ],
        'badges.badgePad' => [
          'affects' => ['.sf-pinned-badge', '.sf-vip-badge'],
          'desc' => 'Badge padding (example: .25em .5em).',
        ],
        'badges.pinnedBadgeBg' => [
          'affects' => ['.sf-pinned-badge'],
          'desc' => 'Background color for the Pinned badge.',
        ],
        'badges.pinnedBadgeText' => [
          'affects' => ['.sf-pinned-badge'],
          'desc' => 'Text color for the Pinned badge.',
        ],
        'badges.vipBadgeBg' => [
          'affects' => ['.sf-vip-badge'],
          'desc' => 'Background color for the VIP badge.',
        ],
        'badges.vipBadgeText' => [
          'affects' => ['.sf-vip-badge'],
          'desc' => 'Text color for the VIP badge.',
        ],

];

      $realSec = $uiSec;
      $realKey = $k;
      if($uiSec === 'vipbadges' && strpos($k, '__') !== false) {
        list($realSec, $realKey) = explode('__', $k, 2);
      }

      $helpKey = $realSec . '.' . $realKey;
      $helpInfo = $helpMap[$helpKey] ?? null;

      // Ensure every field gets the small help icon tooltip.
      // If we don't have a curated help entry, provide a safe generic one.
      if(!$helpInfo) {
        $helpInfo = [
          'affects' => ['Forum UI'],
          'desc' => "Changes the setting: {$lab}.",
        ];
      }

      $keyBase = "ds_{$uiTpl}_{$realSec}_{$realKey}";
      $globalBase = "ds_global_{$realSec}_{$realKey}";
      $isTemplate = ($uiTpl !== 'global');

      $overrideName = $keyBase . '__override';

      if($isTemplate) {
        $ov = $this->modules->get('InputfieldCheckbox');
        $ov->label = "Override: {$lab}";
        //
        $ov->entityEncodeLabel = false;
        $ov->name  = $overrideName;
        $ov->columnWidth = 30;
        $ov->attr('checked', ((int)$cfgGet($overrideName, 0) ? 1 : 0) ? 'checked' : '');
        if($helpInfo) {
          $aff = implode(', ', (array)($helpInfo['affects'] ?? []));
          $desc = (string)($helpInfo['desc'] ?? '');
          // Native PW admin tooltip (UIkit)
                    $tip = trim("Affects: {$aff} — {$desc} (Override)");
          try {
            $ov->addHeaderAction([
              'icon' => 'fa-question-circle',
              'tooltip' => $tip,
              'href' => 'javascript:void(0)',
            ]);
          } catch(\Throwable $e) {
            // ignore
          }
        }
        $fsDesignStudio->add($ov);
      }

      if($uiSec === 'preloader' && $k === 'disablePreloader') {
        $cb = $this->modules->get('InputfieldCheckbox');
        $cb->label = $lab;
        //
        $cb->entityEncodeLabel = false;
        $cb->name  = $keyBase;
        $cb->attr('checked', ((int)$cfgGet($keyBase, (int)$cfgGet('disableSitePreloaderOnForum', 0)) ? 1 : 0) ? 'checked' : '');
        if($isTemplate) { $cb->disabled = 1; }
        if($helpInfo) {
          $payload = [
            'title' => $lab,
            'affects' => $helpInfo['affects'] ?? [],
            'desc' => $helpInfo['desc'] ?? '',
          ];
                    $tip = trim('Affects: ' . implode(', ', (array)($helpInfo['affects'] ?? [])) . ' — ' . (string)($helpInfo['desc'] ?? '') );
          try {
            $cb->addHeaderAction([
              'icon' => 'fa-question-circle',
              'tooltip' => $tip,
              'href' => 'javascript:void(0)',
            ]);
          } catch(\Throwable $e) {
            // ignore
          }

        }
        $fsDesignStudio->add($cb);
        continue;
      }

      if($type === 'textarea') {
        $field = $this->modules->get('InputfieldTextarea');
        $field->rows = ($uiSec === 'customcss') ? 10 : 4;
      } else {
        $field = $this->modules->get('InputfieldText');
      }

      $field->label = $lab;
      //
      $field->entityEncodeLabel = false;
      $field->name  = $keyBase;
      $field->columnWidth = $isTemplate ? 70 : 50;

      if($helpInfo) {
        $aff = implode(', ', (array)($helpInfo['affects'] ?? []));
        $desc = (string)($helpInfo['desc'] ?? '');
        $scope = ($uiTpl === 'global') ? 'Global' : ('Template: ' . $uiTpl);
                $tip = trim("Affects: {$aff} — {$desc} ({$scope})");
        try {
          $field->addHeaderAction([
            'icon' => 'fa-question-circle',
            'tooltip' => $tip,
            'href' => 'javascript:void(0)',
          ]);
        } catch(\Throwable $e) {
          // ignore
        }
      }

      if($type === 'color') {
        $field->addClass('sf-colorpicker');
        $field->attr('placeholder', $ph ?: '#RRGGBBAA');
      } elseif($type === 'text' && $ph) {
        $field->attr('placeholder', $ph);
      }

      // 1.0.6: empty values show what the selected preset really uses (measured per preset/theme),
      // so the admin sees the preset's colours/sizes and can tweak from there. Values equal to the
      // preset are not stored on save (hookBeforeSaveConfig), so switching presets keeps working.
      $presetVal = (string)($sfDsPresetNow["{$realSec}.{$realKey}"] ?? '');
      $fallback = (string)$cfgGet($globalBase, '');
      if(trim($fallback) === '') $fallback = $presetVal;
      $val = (string)$cfgGet($keyBase, $fallback);
      if(trim($val) === '') $val = $fallback;
      if($type !== 'textarea') {
        $field->attr('data-sf-ds', "{$realSec}.{$realKey}");
        if(!$isTemplate) $field->attr('data-sf-preset', $presetVal);
      }

      // If user previously entered padding in rem, show it as px to make it easier to imagine sizing.
      // (Only for padding fields; base is 16px.)
      if(($uiSec === 'buttons' && $k === 'btnPadding') || ($uiSec === 'forms' && $k === 'inputPadding')) {
        if(stripos($val, 'rem') !== false) {
          $val = preg_replace_callback('/([0-9]*\.?[0-9]+)rem/i', function($m) {
            $px = (float)$m[1] * 16.0;
            // keep 1 decimal only when needed
            $out = (abs($px - round($px)) < 0.01) ? (string)round($px) : rtrim(rtrim(number_format($px, 1, '.', ''), '0'), '.');
            return $out . 'px';
          }, $val);
        }
        // Hint in description
        $field->description = trim(((string)$field->description) . ' Use px values (example: 8px 12px).');
      }

      $field->value = $val;

      // (showIf cannot parse field names with "-" like ds_forum-topic_…; the browser toggles it instead)
      if($isTemplate) $field->attr('data-sf-ov', $overrideName);

      // Buttons: place fields into collapsible groups.
      if($uiSec === 'buttons' && $btnFsGlobal && $btnFsPrimary && $btnFsSecondary) {
        if(strpos($k, 'btnPrimary') === 0) {
          $btnFsPrimary->add($field);
        } elseif(strpos($k, 'btnSecondary') === 0) {
          $btnFsSecondary->add($field);
        } else {
          $btnFsGlobal->add($field);
        }
      } else {
        if($uiSec === 'vipbadges') {
          $target = $fsDesignStudio;
          if(isset($fsVipBadgesVip) && $fsVipBadgesVip && isset($realSec) && $realSec === 'vip') $target = $fsVipBadgesVip;
          if(isset($fsVipBadgesBadges) && $fsVipBadgesBadges && isset($realSec) && $realSec === 'badges') $target = $fsVipBadgesBadges;
          $target->add($field);
        } else {
          $fsDesignStudio->add($field);
        }
      }
    }
    }
    $fsDesignStudio = $sfDsRoot;
    $uiSec = $sfDsActiveSec;

    $cssPrev = $this->modules->get('InputfieldTextarea');
    $cssPrev->label = 'Generated CSS (preview)';
    $cssPrev->name  = 'dsCssPreview';
    $cssPrev->collapsed = Inputfield::collapsedYes;
    $cssPrev->attr('readonly', 'readonly');
    $cssPrev->rows = 6;
        // Build a quick CSS preview for the currently selected Template/Section
    $cssVarMap = [
      'colors' => [
        'bg'=>'--sf-bg','surface'=>'--sf-surface','surface2'=>'--sf-surface-2','border'=>'--sf-border','text'=>'--sf-text','muted'=>'--sf-muted',
        'primary'=>'--sf-primary','primaryText'=>'--sf-primary-contrast','accent'=>'--sf-accent','success'=>'--sf-success','warning'=>'--sf-warning','danger'=>'--sf-danger',
        'radius'=>'--sf-radius','space'=>'--sf-space',
      ],
      'typography' => [
        'fontFamily'=>'--sf-font-family','baseSize'=>'--sf-font-size','lineHeight'=>'--sf-line-height','h1Size'=>'--sf-h1-size','h2Size'=>'--sf-h2-size',
        'smallSize'=>'--sf-small-text-size','metaSize'=>'--sf-post-meta-size',
        'indexTitleSize'=>'--sf-index-title-size','indexTitleColor'=>'--sf-index-title-color','indexTitleHoverColor'=>'--sf-index-title-hover-color',
      ],
      'layout' => [ 'pagePadX'=>'--sf-page-pad-x','pagePadY'=>'--sf-page-pad-y','indexRowPad'=>'--sf-index-row-pad','indexRowBorder'=>'--sf-index-row-border' ],
      'header' => [
        'headerBg'=>'--sf-header-bg','headerText'=>'--sf-header-color','headerBorder'=>'--sf-header-border','headerPad'=>'--sf-header-pad',
        'pageTitleSize'=>'--sf-page-title-size','pageTitleColor'=>'--sf-page-title-color',
      ],
      'sidebar' => [
        'sideBg'=>'--sf-side-bg','sideText'=>'--sf-side-text','sidePad'=>'--sf-side-pad','sideInnerPad'=>'--sf-side-inner-pad','sidebarTitleSize'=>'--sf-sidebar-title-size',
      ],
      'topic' => [
        'topicRowBg'=>'--sf-bg-topicrow','topicRowText'=>'--sf-text-topicrow','topicPostBg'=>'--sf-bg-topicpost','topicPostText'=>'--sf-text-topicpost',
        'replyBg'=>'--sf-bg-reply','replyText'=>'--sf-text-reply','userInfoBg'=>'--sf-userinfo-bg','userInfoText'=>'--sf-userinfo-text',
        'signatureSepColor'=>'--sf-signature-sep-color',
        'signatureSepSize'=>'--sf-signature-sep-size',
      ],
      'buttons' => [
        'btnRadius'=>'--sf-btn-radius',
        'btnPadding'=>'--sf-btn-pad',
        'btnPrimaryBg'=>'--sf-btn-primary-bg',
        'btnPrimaryText'=>'--sf-btn-primary-text',
        'btnPrimaryHoverBg'=>'--sf-btn-primary-hover-bg',
        'btnPrimaryHoverText'=>'--sf-btn-primary-hover-text',
        'btnSecondaryBg'=>'--sf-btn-secondary-bg',
        'btnSecondaryText'=>'--sf-btn-secondary-text',
        'btnSecondaryHoverBg'=>'--sf-btn-secondary-hover-bg',
        'btnBorder'=>'--sf-btn-border',
        'btnText'=>'--sf-btn-text',
        'btnFocusRing'=>'--sf-btn-focus-ring',
      ],
      'forms'   => [ 'inputRadius'=>'--sf-input-radius','inputPadding'=>'--sf-input-pad' ],
      'vip'     => [ 'vipHeaderBg'=>'--sf-vip-header-bg','vipHeaderText'=>'--sf-vip-header-color','vipHeaderSize'=>'--sf-vip-header-size' ],
      'badges'  => [
        'badgeFontSize'=>'--sf-badge-font-size','badgeRadius'=>'--sf-badge-radius','badgePad'=>'--sf-badge-pad',
        'pinnedBadgeBg'=>'--sf-badge-pinned-bg','pinnedBadgeText'=>'--sf-badge-pinned-color',
        'vipBadgeBg'=>'--sf-badge-vip-bg','vipBadgeText'=>'--sf-badge-vip-color',
      ],
      'popups'  => [
        'usercardBg'=>'--sf-usercard-bg','usercardText'=>'--sf-usercard-text','usercardBorder'=>'--sf-usercard-border',
        'usercardRadius'=>'--sf-usercard-radius','usercardShadow'=>'--sf-usercard-shadow',
        'tooltipBg'=>'--sf-tooltip-bg','tooltipText'=>'--sf-tooltip-text','tooltipRadius'=>'--sf-tooltip-radius','tooltipShadow'=>'--sf-tooltip-shadow',
      ],
    ];

    $preview = "/* Saved values become CSS variables on the frontend (.sf-wrap + optional per-template overrides). */\n";
    if(isset($cssVarMap[$uiSec])) {
      $pairs = [];
      foreach($cssVarMap[$uiSec] as $k => $varName) {
        $gKey = "ds_global_{$uiSec}_{$k}";
        $tKey = "ds_{$uiTpl}_{$uiSec}_{$k}";
        $ovKey = $tKey . '__override';

        $val = (string) $cfgGet($gKey, '');
        if($uiTpl !== 'global' && (int) $cfgGet($ovKey, 0) === 1) {
          $val = (string) $cfgGet($tKey, $val);
        } elseif($uiTpl === 'global') {
          $val = (string) $cfgGet($tKey, $val);
        }
        $val = trim($val);
        if($val === '') continue;
        $pairs[] = "  {$varName}: {$val};";
      }

      if(count($pairs)) {
        $preview .= ".sf-wrap{\n" . implode("\n", $pairs) . "\n}\n";
      }
    }

    $cssPrev->value = $preview;

    $fsDesignStudio->add($cssPrev);
    // (Legacy design fieldsets removed: all design is now configured in Design Studio above.)



// --- Site preloader bypass on forum pages ---
// Requested: move to General group (and make it persist reliably)
$fsPre = $this->modules->get('InputfieldFieldset');
$fsPre->label = 'Preloader';
$fsPre->collapsed = Inputfield::collapsedYes;
$fsPre->description = 'Optional helper to disable/hide your site preloader on forum pages.';

$pp = clone $this->modules->get('InputfieldToggle');
$pp->label = 'Disable the site loader on forum pages';
$pp->name  = 'disableSitePreloaderOnForum';
$pp->attr('value', 1);
$pp->attr('uncheckedValue', 0);
$pp->description = 'Forum pages show no loader/preloader (the rest of the site keeps it). The loader elements listed on the right are hidden and removed; window.SF_DISABLE_SITE_PRELOADER=true and the class sf-disable-preloader on <html> are set for your own scripts.';
$pp->checked = (int)($data['disableSitePreloaderOnForum'] ?? 0) ? 1 : 0;
$pp->val($pp->checked ? 1 : 0);
$fsPre->add($pp);

$psel = $this->modules->get('InputfieldTextarea');
$psel->label = 'Your loader: CSS class / ID (one per line)';
$psel->name  = 'preloaderHideSelectors';
$psel->rows  = 4;
$psel->description = 'Selectors of your site loader, e.g. #preloader, .loader, .page-loader, #loader-wrapper, .preloader-wrap. A class or ID without # or . is accepted too.';
$psel->value = (string)($data['preloaderHideSelectors'] ?? "#preloader\n.preloader\n.page-loader\n#loader-wrapper");
$fsPre->add($psel);

$fsGeneral->add($fsPre);

// Ranking group
$rk = $this->modules->get('InputfieldTextarea');
$rk->label = 'Manual ranks (one per line)';
$rk->name  = 'manualRanks';
$rk->description = 'Optional. If a user has no forum_rank set, the first rank here is used as default.';
$rk->value = (string) ($data['manualRanks'] ?? '');
$fsRanking->add($rk);

$ar = clone $this->modules->get('InputfieldToggle');
$ar->label = 'Enable auto-ranks by post count';
$ar->name  = 'enableAutoRanks';
$ar->attr('value', 1);
$ar->attr('uncheckedValue', 0);
$ar->description = 'If enabled, the module will automatically set forum_rank based on forum post count (topics + replies), unless a user has manual override enabled.';
$ar->checked = (int)($data['enableAutoRanks'] ?? 1) ? 1 : 0;
$ar->val($ar->checked ? 1 : 0);
$fsRanking->add($ar);

$lad = $this->modules->get('InputfieldTextarea');
$lad->label = 'Auto-rank ladder (minPosts=RankLabel, one per line)';
$lad->name  = 'autoRankLadder';
$lad->description = "Example:\n0=Beginner\n10=Member\n100=Senior\n500=Veteran\n1000=Legend\n\nThe highest matching minPosts wins.";
$lad->value = (string) ($data['autoRankLadder'] ?? '');
$fsRanking->add($lad);



// VIP display / defaults


$hv = $this->modules->get('InputfieldRadios');
$hv->label = 'Hide VIP / restricted categories from non-members';
$hv->name  = 'hideVipCategories';
$hv->description = 'When enabled, VIP categories that the current user cannot read are completely hidden from the forum index.';
$hv->addOption(1, 'Yes — hide VIP sections');
$hv->addOption(0, 'No — show VIP sections with badges');
$hv->value = isset($data['hideVipCategories']) ? (int) $data['hideVipCategories'] : 1;
$hv->columnWidth = 100;
$fsVip->add($hv);

$rr = $this->modules->get('InputfieldAsmSelect');
$rr->label = 'Default VIP read roles';
$rr->name  = 'defaultVipReadRoles';
    $rr->columnWidth = 50;
$rr->description = 'Used for VIP categories when no explicit read roles are set on that category.';
foreach($this->roles as $role) {
  if(!$role || !$role->id) continue;
  $rr->addOption($role->name, $role->name);
}
$rr->value = is_array($data['defaultVipReadRoles'] ?? null) ? $data['defaultVipReadRoles'] : [];
$fsVip->add($rr);
$wr = $this->modules->get('InputfieldAsmSelect');
$wr->label = 'Default VIP write roles';
$wr->name  = 'defaultVipWriteRoles';
    $wr->columnWidth = 50;
$wr->description = 'Used for VIP categories when no explicit write roles are set on that category.';
foreach($this->roles as $role) {
  if(!$role || !$role->id) continue;
  $wr->addOption($role->name, $role->name);
}
$wr->value = is_array($data['defaultVipWriteRoles'] ?? null) ? $data['defaultVipWriteRoles'] : [];
$fsVip->add($wr);

// Add grouped fieldsets to wrapper (requested order)
$inputfields->add($fsGeneral);
$inputfields->add($fsRanking);
$inputfields->add($fsFrontend);
$inputfields->add($fsDesignStudio);
$inputfields->add($fsMessaging);
$inputfields->add($fsPermissions);
$inputfields->add($fsVip);

// --- SEO, GEO & layout ---
$fsSeo = $this->modules->get('InputfieldFieldset');
$fsSeo->attr('name', 'sf_seo');
$fsSeo->label = 'SEO, GEO & page layout';
$fsSeo->icon = 'search';
$fsSeo->collapsed = Inputfield::collapsedYes;
$sfAdd = function($type, $name, $label, $desc = '', $width = 50, array $opts = []) use ($fsSeo, $data) {
  $f = $this->modules->get($type);
  $f->name = $name; $f->label = $label; $f->columnWidth = $width;
  if($desc) $f->description = $desc;
  if($type === 'InputfieldCheckbox') {
    $v = $data[$name] ?? null;
    $f->attr('checked', ($v === null || $v === '' || (int) $v === 1) ? 'checked' : '');
  } elseif($type === 'InputfieldSelect' || $type === 'InputfieldRadios') {
    foreach($opts as $k => $l) $f->addOption($k, $l);
    $f->value = (string) ($data[$name] ?? array_key_first($opts));
  } else {
    $f->value = (string) ($data[$name] ?? '');
  }
  $fsSeo->add($f);
  return $f;
};
$sfAdd('InputfieldRadios', 'layoutMode', 'Page layout', 'Inside site layout (recommended): the forum is shown inside your own _main.php (header, menus, footer, scripts) and blends with your design — works with Markup Regions and with delayed output ($content). Standalone: the forum renders its own HTML document (use when the site has no _main.php).', 50, ['auto' => 'Inside site layout (_init.php + _main.php)', 'standalone' => 'Standalone document'])->optionColumns = 1;
$sfAdd('InputfieldText', 'siteRegionId', 'Content region id', 'Markup Regions: id of the element in _main.php that receives the forum (default: content).', 25)->showIf = 'layoutMode!=standalone';
$sfAdd('InputfieldText', 'siteContentVar', 'Content variable', 'Delayed output: variable your _main.php echoes (default: content).', 25)->showIf = 'layoutMode!=standalone';
$sfAdd('InputfieldText', 'siteBodyField', 'Body field', 'If your _main.php prints the page body directly ($page->body), the forum appears there (default: body). Otherwise the forum is placed in the content region, then <main>, then between header and footer.', 50)->showIf = 'layoutMode!=standalone';
$sfAdd('InputfieldText', 'forumName', 'Forum name', 'Used in titles, Open Graph, RSS and llms.txt. Empty = title of the forum root page.', 50);
$sfAdd('InputfieldText', 'htmlLang', 'Document language', 'lang attribute for standalone pages, e.g. sl, en, de.', 25);
$sfAdd('InputfieldText', 'themeColor', 'Theme color', 'Mobile browser UI color, e.g. #1f2937.', 25);
$sfAdd('InputfieldTextarea', 'forumDescription', 'Forum description', 'Meta description for the forum index, RSS and llms.txt (max ~160 characters).', 100);
$sfAdd('InputfieldText', 'seoDefaultImage', 'Default share image URL', 'Open Graph/Twitter image when a topic has no image (1200×630 recommended).', 50);
$sfAdd('InputfieldText', 'faviconUrl', 'Favicon URL', 'Standalone layout only.', 50);
$sfAdd('InputfieldCheckbox', 'seoOverrideTitle', 'Override site <title>', 'In "Site" layout, replace the site title with the optimized forum title.', 33);
$sfAdd('InputfieldCheckbox', 'seoMarkdown', 'Markdown version of topics (GEO)', 'Topics are available as clean Markdown at ?format=md for AI assistants.', 33);
$sfAdd('InputfieldCheckbox', 'seoLlmsTxt', 'Serve /llms.txt (GEO)', 'Forum map for LLM crawlers. A physical /llms.txt file takes precedence.', 34);
$sfAdd('InputfieldTextarea', 'standaloneHeadMarkup', 'Extra <head> markup', 'Standalone layout: analytics, fonts, verification meta tags…', 100);
$sfAdd('InputfieldTextarea', 'standaloneHeaderMarkup', 'Site header markup', 'Standalone layout: HTML shown above the forum (logo, navigation).', 50);
$sfAdd('InputfieldTextarea', 'standaloneFooterMarkup', 'Site footer markup', 'Standalone layout: HTML shown below the forum.', 50);
$inputfields->add($fsSeo);

// --- Security & registration ---
$fsSec = $this->modules->get('InputfieldFieldset');
$fsSec->attr('name', 'sf_security');
$fsSec->label = 'Security, spam & registration';
$fsSec->icon = 'shield';
$fsSec->collapsed = Inputfield::collapsedYes;
$f = $this->modules->get('InputfieldCheckbox');
$f->name = 'allowRegistration'; $f->label = 'Allow front-end registration'; $f->columnWidth = 33;
$v = $data['allowRegistration'] ?? null; $f->attr('checked', ($v === null || $v === '' || (int) $v === 1) ? 'checked' : '');
$fsSec->add($f);
$f = $this->modules->get('InputfieldSelect');
$f->name = 'defaultRole'; $f->label = 'Role for new members'; $f->columnWidth = 33;
$f->description = 'Privileged roles (superuser, page-edit, user-admin) are ignored for safety.';
foreach($this->wire('roles') as $r) { if($r->name !== 'superuser' && $r->name !== 'guest') $f->addOption($r->name, $r->name); }
$f->value = (string) ($data['defaultRole'] ?? 'forum-member');
$fsSec->add($f);
$f = $this->modules->get('InputfieldInteger');
$f->name = 'floodSeconds'; $f->label = 'Seconds between posts'; $f->columnWidth = 34;
$f->description = 'Flood protection for non-moderators. 0 = off.';
$f->value = (isset($data['floodSeconds']) && $data['floodSeconds'] !== '') ? (int) $data['floodSeconds'] : 15;
$fsSec->add($f);
$f = $this->modules->get('InputfieldCheckbox');
$f->name = 'pmEmailNotify'; $f->label = 'E-mail recipients about new private messages'; $f->columnWidth = 50;
$v = $data['pmEmailNotify'] ?? null; $f->attr('checked', ($v === null || $v === '' || (int) $v === 1) ? 'checked' : '');
$fsSec->add($f);
$inputfields->add($fsSec);

// Tools: sync module templates into /site/templates
$fsTools = $this->modules->get('InputfieldFieldset');
$fsTools->label = 'Tools';
$fsTools->icon = 'wrench';
$fsTools->collapsed = Inputfield::collapsedYes;
$fsTools->description = 'Utilities for keeping your site templates aligned with the module template-files.';

// Handle sync action (runs when you press the button)
try {
  $in = $this->wire('input');
  $u  = $this->wire('user');
  if($in && $u && $u->isSuperuser() && $in->post->sf_sync_templates) {
    $mode = (string) $in->post->sf_sync_mode;
    if(!in_array($mode, ['copy_missing','overwrite_selected','overwrite_all'], true)) $mode = 'copy_missing';
    $selected = $in->post->sf_sync_selected;
    if(!is_array($selected)) $selected = $selected ? [(string)$selected] : [];
    $result = $this->sfSyncTemplateFiles($mode, $selected);
    $this->message("Template sync complete: copied {$result['copied']}, skipped {$result['skipped']}, errors {$result['errors']}.");
    if(!empty($result['errorList'])) {
      foreach($result['errorList'] as $err) $this->warning($err);
    }
  }
} catch(\Throwable $e) {
  // Do not break module config UI
  $this->warning('Template sync failed: ' . $e->getMessage());
}

// Status view
$tplFiles = $this->sfListTemplateFiles();
$siteTplPath = (string) $this->wire('config')->paths->templates;
$rows = [];
foreach($tplFiles as $f) {
  $dest = $siteTplPath . $f;
  $exists = is_file($dest);
  $same = false;
  if($exists) {
    // Compare size+mtime-ish quickly; if equal size then compare hash
    $src = __DIR__ . '/template-files/' . $f;
    if(is_file($src) && filesize($src) === filesize($dest)) {
      $same = (md5_file($src) === md5_file($dest));
    }
  }
  $status = !$exists ? "<span class='ui-state-error-text'>Missing</span>" : ($same ? "<span class='ui-state-highlight-text'>Up to date</span>" : "<span class='ui-state-error-text'>Different</span>");
  $rows[] = "<tr><td><code>{$f}</code></td><td>{$status}</td></tr>";
}
$modTplDir = __DIR__ . '/template-files/';
$statusHtml = "<p>Module template-files live in <code>{$modTplDir}</code>. Site templates live in <code>{$siteTplPath}</code>.</p>";
$statusHtml .= "<table class='AdminDataTable'><thead><tr><th>Template file</th><th>Status in /site/templates</th></tr></thead><tbody>" . implode('', $rows) . "</tbody></table>";

$mk = $this->modules->get('InputfieldMarkup');
$mk->label = 'Template sync status';
$mk->value = $statusHtml;
$fsTools->add($mk);

$mode = $this->modules->get('InputfieldRadios');
$mode->name = 'sf_sync_mode';
$mode->label = 'Sync mode';
$mode->addOption('copy_missing', 'Copy missing only (safe)');
$mode->addOption('overwrite_selected', 'Overwrite selected files');
$mode->addOption('overwrite_all', 'Overwrite ALL forum templates');
$mode->value = 'copy_missing';
$mode->description = 'Use overwrite options only if you want the module template-files to replace your customized /site/templates versions.';
$fsTools->add($mode);

$sel = $this->modules->get('InputfieldAsmSelect');
$sel->name = 'sf_sync_selected';
$sel->label = 'Select files to overwrite (used when mode = overwrite selected)';
$sel->collapsed = Inputfield::collapsedYes;
foreach($tplFiles as $f) $sel->addOption($f, $f);
$fsTools->add($sel);

$btn = $this->modules->get('InputfieldSubmit');
$btn->name = 'sf_sync_templates';
$btn->value = 'Sync templates now';
$btn->icon = 'refresh';
$btn->description = 'Copies module template-files to /site/templates based on the selected mode.';
$fsTools->add($btn);

// Tools are added at the very end (requested order)

// Custom fields on forum templates (for SeoMaestro, custom meta, etc.)
    $fsTplFields = $this->modules->get('InputfieldFieldset');
    $fsTplFields->label = 'Template custom fields';
    $fsTplFields->attr('name', 'sf_tplfields');
    $fsTplFields->icon = 'th-large';
    $fsTplFields->description = 'Attach existing ProcessWire fields to forum templates. Useful for SEO modules (e.g. SeoMaestro) or any site-specific fields.';

    // Global fields for all forum templates
    $all = $this->modules->get('InputfieldAsmSelect');
    $all->label = 'Fields to add to ALL forum templates';
    $all->name  = 'tplFieldsGlobal';
    $all->description = 'Selected fields will be added to every forum template (topics, categories, members, user/profile, messages, etc.).';

    // Build field options
    $fopts = [];
    try {
      $fieldsApi = $this->wire('fields');
      if($fieldsApi) {
        foreach($fieldsApi as $f) {
          /** @var Field $f */
          $fn = (string)$f->name;
          // Skip core/system fields that cannot be attached
          if(in_array($fn, ['id','name','title','status','parent','parent_id','templates_id','sort','sortfield','created','modified'], true)) continue;
          $fopts[$fn] = $fn;
        }
      }
    } catch(\Throwable $e) { $fopts = []; }

    foreach($fopts as $k => $lab) $all->addOption($k, $lab);
    $all->value = $data['tplFieldsGlobal'] ?? [];
    $fsTplFields->add($all);

    // Per-template field assignments
    $tplNames = $this->sfForumTemplateNames();
    foreach($tplNames as $tplName) {
      $key = 'tplFields_' . str_replace('-', '_', $tplName);
      $as = $this->modules->get('InputfieldAsmSelect');
      $as->label = "Fields for template: {$tplName}";
      $as->name  = $key;
      $as->description = 'These fields will be added to this template in addition to the global fields above.';
      foreach($fopts as $k => $lab) $as->addOption($k, $lab);
      $as->value = $data[$key] ?? [];
      $fsTplFields->add($as);
    }

    $fsTplFields->collapsed = Inputfield::collapsedYes;
    $inputfields->add($fsTplFields);

$inputfields->add($fsUploads);

// Finally: Tools (requested)

// --- Import / Export settings (JSON) ---
try {
  $in = $this->wire('input');
  $u  = $this->wire('user');

  // Export payload (always available, but only superusers see it)
  if($u && $u->isSuperuser()) {
    $export = $data;
    // Remove UI-only state keys
    unset($export['sf_active_group'], $export['dsUiTpl'], $export['dsUiSection'], $export['sf_export_settings'], $export['sf_export_settings_full'], $export['sf_import_json'], $export['dsCssPreview'], $export['sf_sync_mode'], $export['sf_sync_selected']);
    // Optional: avoid exporting site-specific root page IDs by default
    $exportNoRoots = $export;
    unset($exportNoRoots['forumRoot'], $exportNoRoots['messagesRoot'], $exportNoRoots['userProfileRoot']);

    $exWrap = $this->modules->get('InputfieldFieldset');
    $exWrap->label = 'Import / Export settings';
    $exWrap->collapsed = Inputfield::collapsedYes;
    $exWrap->description = 'Copy settings as JSON to migrate the forum configuration to another site, or paste/import JSON to restore settings.';

    $ex1 = $this->modules->get('InputfieldTextarea');
    $ex1->label = 'Export (recommended: without root page IDs)';
    $ex1->name  = 'sf_export_settings';
    $ex1->attr('rows', 10);
    $ex1->value = json_encode($exportNoRoots, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);

    $ex2 = $this->modules->get('InputfieldTextarea');
    $ex2->label = 'Export (full, includes root page IDs)';
    $ex2->name  = 'sf_export_settings_full';
    $ex2->attr('rows', 8);
    $ex2->value = json_encode($export, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);

    $im = $this->modules->get('InputfieldTextarea');
    $im->label = 'Import settings JSON';
    $im->name  = 'sf_import_json';
    $im->attr('rows', 10);
    $im->description = 'Paste JSON from another site and click “Import settings”. Tip: import first, then adjust root pages (Forum root, Messages root, Profile root) if needed.';

    $imBtn = $this->modules->get('InputfieldSubmit');
    $imBtn->name = 'sf_import_settings';
    $imBtn->value = 'Import settings';
    $imBtn->attr('class', 'ui-button ui-state-highlight');

    // Handle import
    if($in && $in->post->sf_import_settings) {
      $raw = (string)($in->post->sf_import_json ?? '');
      $decoded = json_decode($raw, true);
      if(!is_array($decoded)) {
        $this->error('Import failed: JSON could not be parsed.');
      } else {
        // Merge with defaults so missing keys keep safe values
        $merged = array_merge($defaults, $data, $decoded);

        // Never allow importing UI-only state
        unset($merged['sf_active_group'], $merged['dsUiTpl'], $merged['dsUiSection']);

        $this->modules->saveConfig($this, $merged);
        $this->message('Settings imported. Review root pages and save again if needed.');
        $this->wire('session')->redirect($this->config->urls->admin . 'module/edit?name=NativeForum&sf_active_group=tools');
      }
    }

    $exWrap->add($ex1);
    $exWrap->add($ex2);
    $exWrap->add($im);
    $exWrap->add($imBtn);
    $fsTools->add($exWrap);
  }
} catch(\Throwable $e) {
  // ignore
}

$fsTools->attr('name', 'sf_tools');
$inputfields->add($fsTools);


    // --- Translations (frontend text overrides; falls back to Language Translator) ---
    // (Plain fieldset: InputfieldFieldsetTabOpen only exists when FieldtypeFieldsetTabOpen is installed)
    $tab = $this->modules->get('InputfieldFieldset');
    $tab->attr('name', 'sf_tab_translations');
    $tab->label = $this->_('Translations');
    $tab->collapsed = Inputfield::collapsedYes;
    $tab->icon = 'language';
    $inputfields->add($tab);

    $fs = $this->modules->get('InputfieldFieldset');
    $fs->label = $this->_('Frontend text');
    $fs->icon = 'comment';
    $fs->description = $this->_('Set custom wording per language. Leave empty to use defaults + Language Translator.');
    $tab->add($fs);

    $isMulti = $this->modules->isInstalled('LanguageSupport');
    $textClass = $isMulti ? 'InputfieldTextLanguage' : 'InputfieldText';

    $trFields = $this->trFieldDefs();

        // Keep the overall Translations section tidy: collapse by default + group fields.
    $fs->collapsed = Inputfield::collapsedYes;

    $trGroups = [
      $this->_('General') => ['forums','board_index','pinned_topics','forum_statistics','latest_topics','newest_members','quick_links'],
      $this->_('Search & scope') => ['search','search_placeholder','search_results','everywhere','across_forum','this_category','this_group','this_forum'],
      $this->_('Labels & counters') => ['topics','posts','categories','members','replies','views','by','joined'],
      $this->_('Account & auth') => ['account','my_profile','login','logout','register'],
      $this->_('Private message notices') => ['pm_new_one','pm_new_many','pm_new_short','pm_latest_from','pm_click_to_read','pm_from','pm_read','pm_later'],
      $this->_('Topic & post actions') => ['new_topic','start_new_topic','write_reply','follow_topic','edit','share','report','drag_files_here','upload_tip'],
      $this->_('Forms') => ['title','summary_optional','message','username','signature'],
      $this->_('Profile tabs') => ['overview','recent_posts','activity','private_messages','settings','followed_topics','followed_none','followed_new','unfollow','followed_last'],
    ];

    $trGroupFsByKey = [];
    foreach($trGroups as $gLabel => $gKeys) {
      $gFs = $this->modules->get('InputfieldFieldset');
      $gFs->label = $gLabel;
      $gFs->collapsed = Inputfield::collapsedYes;
      $gFs->description = $this->_('Leave empty to use defaults + Language Translator.');
      $fs->add($gFs);
      foreach($gKeys as $k) $trGroupFsByKey[$k] = $gFs;
    }

    // Anything not explicitly grouped ends up here (kept collapsed).
    $fsOther = $this->modules->get('InputfieldFieldset');
    $fsOther->label = $this->_('Other');
    $fsOther->collapsed = Inputfield::collapsedYes;
    $fs->add($fsOther);

    foreach($trFields as $key => $meta) {
      $targetFs = $trGroupFsByKey[$key] ?? $fsOther;
      // Prefer multi-language input when available, but fall back safely.
      $f = $this->modules->get($textClass);
      if(!$f) $f = $this->modules->get('InputfieldText');
      if(!$f) throw new WireException('NativeForum: Missing Inputfield module for translations (expected ' . $textClass . ' or InputfieldText)');

      // Determine if this is a multi-language inputfield (InputfieldTextLanguage)
      $isMultiField = (stripos(get_class($f), 'Language') !== false);

      $f->attr('name', "tr__{$key}");
      $f->label = $meta[0];
      $f->description = $meta[1];
      $f->notes = $this->_('Leave empty to use default text / Language Translator.');
      $f->collapsed = Inputfield::collapsedNo;

      // Show default text as placeholder (helps avoid confusing "array-0" displays)
      $f->placeholder = $meta[2];

      $val = $data["tr__{$key}"] ?? null;
      if($isMultiField) {
        // Expect array of language values; ensure array type
        if(!is_array($val)) $val = [];
        $f->value = $val;
      } else {
        // Scalar value only
        if(is_array($val)) $val = '';
        $f->value = (string) $val;
      }

      $targetFs->add($f);
    }

    // Close the Translations tab so the module's Uninstall action (button) is not rendered
    // inside the last fieldset/tab group.
// ---------------------------------------------------------------------------
// Final settings layout: logical groups, consistent column widths.
// Fields are MOVED (never re-created), so saving/values are untouched; any field
// not listed below lands in "Advanced" so nothing can get lost.
// ---------------------------------------------------------------------------
$sfMk = function($type, $name, $label, $desc, $width, $value, array $opts = []) {
  $f = $this->modules->get($type);
  $f->name = $name; $f->label = $label; $f->columnWidth = $width;
  if($desc) $f->description = $desc;
  if($type === 'InputfieldCheckbox') $f->attr('checked', $value ? 'checked' : '');
  elseif($opts) { foreach($opts as $k => $l) $f->addOption($k, $l); $f->value = $value; }
  else $f->value = $value;
  return $f;
};
$sfOn = function($k, $def = 1) use ($data) { $v = $data[$k] ?? null; return ($v === null || $v === '') ? (bool) $def : ((int) $v === 1); };

// New settings
$sfNew = [
  'topicsPerPage' => $sfMk('InputfieldInteger', 'topicsPerPage', 'Topics per page', 'Topic list in a category.', 25, (int) ($data['topicsPerPage'] ?? 20) ?: 20),
  'showTopicSummaries' => $sfMk('InputfieldCheckbox', 'showTopicSummaries', 'Show topic summaries in lists', 'Summary text under topic titles (index, category, search, latest topics).', 25, $sfOn('showTopicSummaries')),
  'requireEmailVerification' => $sfMk('InputfieldCheckbox', 'requireEmailVerification', 'Require e-mail confirmation', 'The account is created only after the user clicks the link in the confirmation e-mail.', 33, $sfOn('requireEmailVerification')),
  'verifyExpireHours' => $sfMk('InputfieldInteger', 'verifyExpireHours', 'Confirmation link valid (hours)', 'Unconfirmed registrations are discarded after this time.', 33, (int) ($data['verifyExpireHours'] ?? 24) ?: 24),
  'mailFromEmail' => $sfMk('InputfieldEmail', 'mailFromEmail', 'Sender e-mail', 'From address for forum e-mails. Empty = $config->adminEmail or no-reply@your-domain.', 34, (string) ($data['mailFromEmail'] ?? '')),
  // Anti-spam
  'spamNewMemberPosts' => $sfMk('InputfieldInteger', 'spamNewMemberPosts', 'New member until (posts)', 'Until a member has written this many posts the link limit below applies. 0 = off.', 25, (int) ($data['spamNewMemberPosts'] ?? 3)),
  'spamNewMemberMaxLinks' => $sfMk('InputfieldInteger', 'spamNewMemberMaxLinks', 'Links allowed for new members', 'Per post. 0 = no links until they reach the post count.', 25, (int) ($data['spamNewMemberMaxLinks'] ?? 1)),
  'spamMaxLinks' => $sfMk('InputfieldInteger', 'spamMaxLinks', 'Links per post (everyone)', '0 = no limit. Moderators are never limited.', 25, (int) ($data['spamMaxLinks'] ?? 0)),
  'spamRegPerIpDay' => $sfMk('InputfieldInteger', 'spamRegPerIpDay', 'Registrations per IP per day', '0 = no limit.', 25, (int) ($data['spamRegPerIpDay'] ?? 3)),
  'spamCheckMx' => $sfMk('InputfieldCheckbox', 'spamCheckMx', 'Check that the e-mail domain can receive mail', 'DNS (MX) lookup when someone registers.', 33, $sfOn('spamCheckMx')),
  'spamStopForumSpam' => $sfMk('InputfieldCheckbox', 'spamStopForumSpam', 'Check registrations with StopForumSpam.org', 'Looks up the e-mail and IP in the public spammer database (max. 4 s, only on registration).', 33, $sfOn('spamStopForumSpam', 0)),
  'spamLog' => $sfMk('InputfieldCheckbox', 'spamLog', 'Log blocked attempts', 'Setup → Logs → nativeforum-spam', 34, $sfOn('spamLog')),
  'spamBannedWords' => $sfMk('InputfieldTextarea', 'spamBannedWords', 'Banned words / phrases', 'One per line. Posts, messages, usernames and e-mails containing them are rejected. Regular expressions: /casino\\s*bonus/', 34, (string) ($data['spamBannedWords'] ?? '')),
  'spamBlockedEmailDomains' => $sfMk('InputfieldTextarea', 'spamBlockedEmailDomains', 'Blocked e-mail domains', 'One per line, in addition to the built-in list of ~70 throw-away mail providers.', 33, (string) ($data['spamBlockedEmailDomains'] ?? '')),
  'spamBlockedIps' => $sfMk('InputfieldTextarea', 'spamBlockedIps', 'Blocked IP addresses', 'One per line: 1.2.3.4, 1.2.3.* or 1.2.3.0/24', 33, (string) ($data['spamBlockedIps'] ?? '')),
  'turnstileSiteKey' => $sfMk('InputfieldText', 'turnstileSiteKey', 'Cloudflare Turnstile site key', 'Optional free captcha (dash.cloudflare.com → Turnstile). Leave empty to not use a captcha.', 34, (string) ($data['turnstileSiteKey'] ?? '')),
  'turnstileSecret' => $sfMk('InputfieldText', 'turnstileSecret', 'Turnstile secret key', '', 33, (string) ($data['turnstileSecret'] ?? '')),
  'turnstileForms' => (function() use ($data) {
    $f = $this->modules->get('InputfieldCheckboxes');
    $f->name = 'turnstileForms'; $f->label = 'Use the captcha on'; $f->columnWidth = 33;
    $f->addOption('register', 'Registration'); $f->addOption('login', 'Login'); $f->addOption('guestposts', 'Guest posts');
    $f->optionColumns = 1;
    $f->value = (array) ($data['turnstileForms'] ?? ['register']);
    return $f;
  })(),
];

$sfAll = [];
$sfCollect = function($w) use (&$sfCollect, &$sfAll) {
  foreach($w->children() as $c) {
    // recurse into the old groups and into unnamed helper fieldsets inside them (e.g. "Preloader")
    if($c instanceof InputfieldWrapper && ($c->name === '' || in_array($c->name, ['sf_general','sf_ranking','sf_frontend','sf_messages','sf_permissions','sf_vip','sf_seo','sf_security'], true))) { $sfCollect($c); continue; }
    if($c->name) $sfAll[$c->name] = $c;
  }
};
$sfCollect($inputfields);

$sfGroups = [
  ['sf_general', 'General', 'cog', [
    'forumRoot' => 50, 'userProfileRoot' => 50, 'forumName' => 50, 'treePreset' => 50, 'forumDescription' => 100,
    'dateTimeStyle' => 34, 'htmlLang' => 33, 'faviconUrl' => 33,
  ]],
  ['sf_layout', 'Page layout & integration', 'columns', [
    'layoutMode' => 34, 'siteRegionId' => 33, 'siteContentVar' => 33, 'siteBodyField' => 100,
    'forumWidthPreset' => 50, 'forumStyle' => 50, 'forumForceFullWidth' => 100,
    'layoutMainColsLg' => 25, 'sidebarDisplayMode' => 25, 'forumTopSpacePx' => 25, 'forumBottomSpacePx' => 25,
    'sidebarShowTemplates' => 100,
    'disableSitePreloaderOnForum' => 34, 'preloaderHideSelectors' => 66,
    'forumPrependMarkup' => 50, 'forumAppendMarkup' => 50, 'forumPrependPlacement' => 50, 'forumAppendPlacement' => 50,
    'standaloneHeadMarkup' => 100, 'standaloneHeaderMarkup' => 50, 'standaloneFooterMarkup' => 50,
  ]],
  ['sf_frontend', 'Theme & icons', 'paint-brush', [
    'frontendTheme' => 33, 'designPreset' => 33, 'themeColor' => 34,
    'postAuthorSide' => 50, 'iconProvider' => 50,
    'faStyle' => 50, 'fontAwesomeAutoLoad' => 50, 'fontAwesomeCssUrl' => 100,
    'bootstrapIntegration' => 50, 'bootstrapCompatShim' => 50,
    'bootstrapCssUrl' => 50, 'bootstrapJsUrl' => 50,
  ]],
  ['sf_posting', 'Topics, posts & editor', 'pencil', [
    'postsPerPage' => 25, 'topicsPerPage' => 25, 'showTopicSummaries' => 25, 'showTopicPostOnAllPages' => 25,
    'enableFrontendWysiwyg' => 33, 'frontendCkToolbar' => 33, 'renderRichHtml' => 34,
    'allowRichImages' => 33, 'allowRichEmbeds' => 33, 'allowRichCode' => 34,
    'postEditWindow' => 50, 'floodSeconds' => 50,
  ]],
  ['sf_members', 'Members, registration & ranks', 'users', [
    'allowRegistration' => 33, 'requireEmailVerification' => 33, 'verifyExpireHours' => 34,
    'defaultRole' => 50, 'allowAvatarUpload' => 50,
    'allowGuestsViewProfiles' => 50, 'allowUsersViewProfiles' => 50,
    'enableAutoRanks' => 100, 'manualRanks' => 50, 'autoRankLadder' => 50,
  ]],
  ['sf_messages', 'Notifications & private messages', 'envelope', [
    'mailFromEmail' => 50, 'messagesRoot' => 50,
    'allowPrivateMessages' => 33, 'pmEmailNotify' => 33, 'enableTopicFollow' => 34, 'sendFollowEmails' => 50,
  ]],
  ['sf_permissions', 'Permissions, guests & VIP', 'lock', [
    'allowGuestsRead' => 50, 'allowGuestsPost' => 50,
    'hideVipCategories' => 100, 'defaultVipReadRoles' => 50, 'defaultVipWriteRoles' => 50,
  ]],
  ['sf_spam', 'Anti-spam & security', 'shield', [
    'spamNewMemberPosts' => 25, 'spamNewMemberMaxLinks' => 25, 'spamMaxLinks' => 25, 'spamRegPerIpDay' => 25,
    'spamCheckMx' => 33, 'spamStopForumSpam' => 33, 'spamLog' => 34,
    'spamBannedWords' => 34, 'spamBlockedEmailDomains' => 33, 'spamBlockedIps' => 33,
    'turnstileSiteKey' => 34, 'turnstileSecret' => 33, 'turnstileForms' => 33,
  ]],
  ['sf_seo', 'SEO & GEO (AI search)', 'search', [
    'seoDefaultImage' => 100, 'seoOverrideTitle' => 33, 'seoMarkdown' => 33, 'seoLlmsTxt' => 34,
  ]],
];

$sfNewForm = $this->wire(new InputfieldWrapper());
$sfUsed = [];
// Legacy settings superseded by others (kept in config for back-compat, not shown):
// iconSet -> iconProvider, bootstrapAutoLoad -> bootstrapIntegration, bootstrapSiteVersion (unused),
// allowGuestsVote (votes need a member account to be counted once)
foreach(['iconSet', 'bootstrapAutoLoad', 'bootstrapSiteVersion', 'allowGuestsVote'] as $sfDrop) $sfUsed[$sfDrop] = 1;
// keep hidden state field first
if(isset($sfAll['sf_active_group'])) { $sfNewForm->add($sfAll['sf_active_group']); $sfUsed['sf_active_group'] = 1; }
$sfActive = (string) ($hidActive->value ?? '');
foreach($sfGroups as $gi => $g) {
  list($gname, $glabel, $gicon, $gfields) = $g;
  $fs = $this->modules->get('InputfieldFieldset');
  $fs->attr('name', $gname); $fs->label = $glabel; $fs->icon = $gicon;
  $fs->collapsed = ($gi === 0 || $sfActive === str_replace('sf_', '', $gname)) ? Inputfield::collapsedNo : Inputfield::collapsedYes;
  foreach($gfields as $fname => $w) {
    $f = $sfAll[$fname] ?? ($sfNew[$fname] ?? null);
    if(!$f) continue;
    $f->columnWidth = $w;
    if($f->showIf === 'layoutMode=site') $f->showIf = 'layoutMode!=standalone';
    $fs->add($f);
    $sfUsed[$fname] = 1;
  }
  if(count($fs->children())) $sfNewForm->add($fs);
}
// Whole sub-sections that stay as they are
foreach($inputfields->children() as $c) {
  if($c instanceof InputfieldWrapper && in_array($c->name, ['sf_designstudio', 'sf_uploads'], true)) {
    $c->collapsed = ($c->name === 'sf_designstudio' && $sfActive === 'design') || ($c->name === 'sf_uploads' && $sfActive === 'uploads') ? Inputfield::collapsedNo : Inputfield::collapsedYes;
    if($c->name === 'sf_uploads') {
      $c->label = 'Attachments (uploads)'; $c->icon = 'paperclip';
      $uw = ['attachmentsEnabled' => 100, 'attachmentsAllowImages' => 33, 'attachmentsAllowFiles' => 33, 'attachmentsEnableLightbox' => 34,
        'attachmentsLightboxLibrary' => 25, 'attachmentsMaxUploadMB' => 25, 'attachmentsMaxFiles' => 25, 'attachmentsImageMaxWidth' => 25, 'attachmentsAllowedExt' => 100];
      foreach($c->getAll() as $uf) if(isset($uw[$uf->name])) $uf->columnWidth = $uw[$uf->name];
    }
    $sfNewForm->add($c);
    $sfUsed[$c->name] = 1;
  }
}
// Advanced: template fields, tools, translations + anything not placed above
$adv = $this->modules->get('InputfieldFieldset');
$adv->attr('name', 'sf_advanced'); $adv->label = 'Advanced'; $adv->icon = 'wrench'; $adv->collapsed = Inputfield::collapsedYes;
foreach($inputfields->children() as $c) {
  if($c instanceof InputfieldWrapper && !in_array($c->name, ['sf_designstudio', 'sf_uploads', 'sf_general','sf_ranking','sf_frontend','sf_messages','sf_permissions','sf_vip','sf_seo','sf_security'], true)) {
    if(!count($c->children()) && !$c->name) continue;
    if(!$c->label) continue;
    $c->collapsed = Inputfield::collapsedYes;
    $adv->add($c);
    $sfUsed[$c->name] = 1;
  }
}
foreach($sfAll as $n => $f) {
  if(isset($sfUsed[$n]) || $f->getParent() === $adv) continue;
  if($f instanceof InputfieldHidden) { $sfNewForm->add($f); continue; }
  $adv->prepend($f);
}
$sfNewForm->add($adv);
$this->sfConfigLayout($sfNewForm);
// Modules > Native Forum: clear warning about what uninstalling deletes (+ a confirm on the Uninstall checkbox)
try {
  $proc = (string) $this->wire('page')->process;
  if($proc === 'ProcessModule') {
    $exportUrl = $this->wire('config')->urls->admin . 'native-forum/transfer/';
    $w = $this->modules->get('InputfieldMarkup');
    $w->label = 'Uninstalling deletes ALL forum data';
    $w->icon = 'exclamation-triangle';
    $w->value = "<div class='uk-alert-danger uk-alert' style='margin:0;padding:12px 16px;border-radius:6px'>"
      . "<strong>Uninstall removes everything Native Forum created:</strong> all groups, categories, topics, replies and private messages "
      . "(with uploaded images and files), the forum pages, templates and fields (also the forum fields on users), the forum roles, reports, "
      . "settings and the forum template files in /site/templates. Site user accounts are kept. This cannot be undone. "
      . "<br><a href='{$exportUrl}'><i class='fa fa-download'></i> Export everything first</a> if you may need it later.</div>"
      . "<script>document.addEventListener('change',function(e){var t=e.target;if(!t||t.name!=='uninstall'||!t.checked)return;"
      . "if(!confirm('Uninstall Native Forum?\\n\\nThis deletes ALL forum topics, replies, private messages, uploaded files, forum templates, fields, roles and settings. This cannot be undone.\\n\\nExport first (Native Forum > Export / Import) if you want to keep them.'))t.checked=false;});</script>";
    $sfNewForm->prepend($w);
    if(!$this->demoChoiceMade()) {
      $dashUrl = $this->wire('config')->urls->admin . 'native-forum/';
      $d = $this->modules->get('InputfieldMarkup');
      $d->label = 'Next step: demo content?';
      $d->icon = 'magic';
      $d->value = "<p style='margin:0'>Native Forum is installed. Open the <a href='{$dashUrl}'><strong>Native Forum dashboard</strong></a> to add a little demo content "
        . "(group, categories, pinned forum rules, a few topics and replies) – or start with an empty forum. Demo content can be removed with one click.</p>";
      $sfNewForm->prepend($d);
    }
  }
} catch(\Throwable $e) {}
return $sfNewForm;
  }


  /**
   * Return user's forum rank label (manual rank stored on user) or a default.
   * Works on older ProcessWire too.
   *
   * @param User|Page|null $u
   * @return string
   */
  public function getUserRank($u) {
    if(!$u || !is_object($u) || !isset($u->id) || !$u->id) return '';

    // If the user has a manual rank field, use it.
    if(method_exists($u, 'hasField') && $u->hasField('forum_rank')) {
      $rank = trim((string) $u->get('forum_rank'));
      if($rank !== '') return $rank;
    } else {
      // Older setups without hasField()
      if(isset($u->forum_rank)) {
        $rank = trim((string) $u->forum_rank);
        if($rank !== '') return $rank;
      }
    }


// Auto ranks fallback (based on post count) when no manual rank is set
if($this->autoRanksEnabled() && !$this->isUserRankOverridden($u)) {
  $count = $this->getUserPostCount($u);
  $auto  = $this->getAutoRankForCount($count);
  if($auto !== '') return $auto;
}

    // Otherwise, try module config "manualRanks" or legacy "ranks" (one per line)
    $list = (string) ($this->get('manualRanks') ?: $this->get('ranks') ?: '');
    $list = trim($list);
    if($list !== '') {
      $lines = preg_split('/\R+/', $list);
      foreach($lines as $line) {
        $line = trim($line);
        if($line !== '') return $line; // default to first rank
      }
    }

    return '';
  }

  /**
   * Return list of available ranks (one per line from config), plus any ranks already used by users.
   * @return array<int,string>
   */
/**
 * Normalize a rank value into a safe, canonical string.
 * Accepts raw input (from POST/select), trims, collapses whitespace,
 * and validates against the configured rank list when available.
 *
 * @param string $rank
 * @return string
 */
public function normalizeRank(string $rank): string {
  $rank = trim((string) $rank);
  // collapse internal whitespace
  $rank = preg_replace('/\s+/u', ' ', $rank) ?? $rank;

  if($rank === '') return '';

  // If we have a rank list, only allow those values (prevents saving junk)
  if(method_exists($this, 'getRankList')) {
    $list = $this->getRankList();
    if(is_array($list) && count($list)) {
      foreach($list as $allowed) {
        if((string)$allowed === $rank) return $rank;
      }
      // If not found, keep the first configured rank as a safe fallback
      return (string) reset($list);
    }
  }

  return $rank;
	}

	/**
 * Patch site template forum-root.php to define helper closures ($countCounts, $renderBlock) if missing.
 * This prevents fatal errors when the site template is an older copy.
 */
protected function sf_patchForumRootTemplate(): void {
  $config = $this->wire('config');
  $files  = $this->wire('files');

  $path = $config->paths->templates . 'forum-root.php';
  if(!is_file($path) || !is_readable($path) || !is_writable($path)) return;

  $src = (string) $files->fileGetContents($path);

  // Only patch if template calls $countCounts($cat) but doesn't define it.
  if(strpos($src, '$countCounts(') === false) return;
  if(strpos($src, '$countCounts = function') !== false) return;

  $inject = "\n\n// --- NativeForum: injected helper closures to prevent null-callable fatals ---\n"
    . "if(!isset($countCounts) || !is_callable($countCounts)) {\n"
    . "  $countCounts = function(\\ProcessWire\\Page $cat) use($pages, $user) : array {\n"
    . "    $topics = (int) $cat->children(\"template=forum-topic, include=all, status<hidden\")->count();\n"
    . "    $replies = 0;\n"
    . "    if($topics) {\n"
    . "      $topicIds = $cat->children(\"template=forum-topic, include=all, status<hidden\")->explode(\"id\");\n"
    . "      $topicIds = array_filter(array_map(\"intval\", $topicIds));\n"
    . "      if(count($topicIds)) {\n"
    . "        $replies = (int) $pages->count(\"template=forum-reply, parent_id=\" . implode(\"|\", $topicIds) . \", include=all, status<hidden\");\n"
    . "      }\n"
    . "    }\n"
    . "    return [$topics, $topics + $replies];\n"
    . "  };\n"
    . "}\n";

  // Also patch $renderBlock if it's used but not defined.
  if(strpos($src, '$renderBlock(') !== false && strpos($src, '$renderBlock = function') === false) {
    $inject .= "\nif(!isset($renderBlock) || !is_callable($renderBlock)) {\n"
      . "  $renderBlock = function($titleHtml, $cats, $isVipGroup=false) {\n"
      . "    // Fallback: do nothing (prevents fatal).\n"
      . "  };\n"
      . "}\n";
  }

  // Insert after the opening PHP tag and namespace line if present.
  $patched = $src;
  if(preg_match('/^<\?php\s+namespace\s+ProcessWire;\s*/', $src, $m)) {
    $patched = preg_replace('/^<\?php\s+namespace\s+ProcessWire;\s*/', $m[0] . $inject, $src, 1);
  } else {
    // Insert after opening tag
    $patched = preg_replace('/^<\?php\s*/', '$0' . $inject, $src, 1);
  }

  if($patched && $patched !== $src) {
    $files->filePutContents($path, $patched);
  }
}


	public function getRankList(): array {
    $out = [];

    $list = (string) ($this->get('manualRanks') ?: $this->get('ranks') ?: '');
    $list = trim($list);
    if($list !== '') {
      foreach(preg_split('/\R+/', $list) as $line) {
        $line = trim((string) $line);
        if($line === '') continue;
        if(!in_array($line, $out, true)) $out[] = $line;
      }
    }

    
// Include labels from auto-rank ladder too (so normalizeRank() allows them)
try {
  foreach($this->getAutoRankLadder() as $row) {
    $lab = trim((string) ($row['label'] ?? ''));
    if($lab !== '' && !in_array($lab, $out, true)) $out[] = $lab;
  }
} catch(\Throwable $e) {
  // ignore
}

// Add any ranks already assigned to users (keeps list in sync).
    try {
      $fields = $this->wire('fields');
      $users  = $this->wire('users');
      $f = $fields ? $fields->get('forum_rank') : null;
      if($f && $f->id) {
        foreach($users->find('forum_rank!=') as $u) {
          $r = trim((string) $u->get('forum_rank'));
          if($r !== '' && !in_array($r, $out, true)) $out[] = $r;
        }
      }
    } catch(\Throwable $e) {
      // ignore
    }

    return $out;
  }

/**
 * Whether auto-ranks are enabled in module settings.
 */
public function autoRanksEnabled(): bool {
  return (int) $this->get('enableAutoRanks') === 1;
}

/**
 * True if user has manual override enabled (so auto-ranks should not overwrite).
 */
public function isUserRankOverridden($u): bool {
  if(!$u || !is_object($u) || !isset($u->id) || !$u->id) return false;
  if(method_exists($u, 'hasField') && $u->hasField('forum_rank_manual_override')) {
    return (bool) $u->get('forum_rank_manual_override');
  }
  return isset($u->forum_rank_manual_override) ? (bool) $u->forum_rank_manual_override : false;
}

/**
 * Parse auto-rank ladder from settings.
 * Format: minPosts=Label (one per line). Also accepts ":" or "|" as separator.
 *
 * @return array<int, array{min:int,label:string}>
 */
public function getAutoRankLadder(): array {
  $raw = (string) $this->get('autoRankLadder');
  $raw = trim($raw);

  $out = [];
  if($raw !== '') {
    foreach(preg_split('/\R+/', $raw) as $line) {
      $line = trim((string) $line);
      if($line === '') continue;

      // allow "100=Senior", "100: Senior", "100|Senior"
      if(!preg_match('/^\s*(\d+)\s*(?:=|:|\|)\s*(.+?)\s*$/u', $line, $m)) continue;
      $min = (int) $m[1];
      $lab = trim((string) $m[2]);
      if($lab === '') continue;

      $out[] = ['min' => $min, 'label' => $lab];
    }
  }

  if(!count($out)) {
    $out[] = ['min' => 0, 'label' => 'Member'];
  }

  // sort ascending by min
  usort($out, function($a, $b) {
    return (int)$a['min'] <=> (int)$b['min'];
  });

  // de-dup mins (keep last label for same min)
  $dedup = [];
  foreach($out as $row) {
    $dedup[(int)$row['min']] = (string)$row['label'];
  }
  $final = [];
  foreach($dedup as $min => $label) $final[] = ['min' => (int)$min, 'label' => (string)$label];
  usort($final, function($a, $b) { return (int)$a['min'] <=> (int)$b['min']; });

  return $final;
}

/**
 * Get the auto-rank label for a given post count.
 */
public function getAutoRankForCount(int $count): string {
  $count = max(0, (int) $count);
  $ladder = $this->getAutoRankLadder();

  $winner = (string) $ladder[0]['label'];
  foreach($ladder as $row) {
    if($count >= (int)$row['min']) $winner = (string) $row['label'];
    else break;
  }
  return $winner;
}

/**
 * Get a user's forum post count. Uses cached forum_post_count if available.
 */
public function getUserPostCount($u, bool $recalc = false): int {
  if(!$u || !is_object($u) || !isset($u->id) || !$u->id) return 0;

  // prefer cached value
  if(!$recalc) {
    if(method_exists($u, 'hasField') && $u->hasField('forum_post_count')) {
      $val = (int) $u->get('forum_post_count');
      if($val > 0) return $val;
    } elseif(isset($u->forum_post_count)) {
      $val = (int) $u->forum_post_count;
      if($val > 0) return $val;
    }
  }

  // fallback: aggregate count (one SQL query, request-cached)
  $st = $this->userStats([(int) $u->id]);
  $count = (int) ($st[(int) $u->id]['messages'] ?? 0);

  // store cache if field exists (only when it actually changed: a user save on every page view is expensive)
  if($count > 0 && method_exists($u, 'hasField') && $u->hasField('forum_post_count') && (int) $u->getUnformatted('forum_post_count') !== $count) {
    $u->of(false);
    $u->set('forum_post_count', $count);
    $u->save('forum_post_count');
    $u->of(true);
  }

  return $count;
}

/**
 * Increment cached post count and (optionally) update auto rank.
 */
protected function incrementUserPostCount($u, int $delta = 1): int {
  if(!$u || !is_object($u) || !isset($u->id) || !$u->id) return 0;
  $delta = (int) $delta;
  if($delta === 0) return $this->getUserPostCount($u);

  $current = $this->getUserPostCount($u);
  $new = max(0, $current + $delta);

  if(method_exists($u, 'hasField') && $u->hasField('forum_post_count')) {
    $u->of(false);
    $u->set('forum_post_count', $new);
    $u->save('forum_post_count');
    $u->of(true);
  }

  return $new;
}

/**
 * Apply auto-rank to a user (writes forum_rank) unless overridden.
 * @return string The applied rank (or current rank if skipped)
 */
public function applyAutoRank($u, bool $force = false): string {
  if(!$u || !is_object($u) || !isset($u->id) || !$u->id) return '';
  if(!$force && !$this->autoRanksEnabled()) return (string) ($u->get('forum_rank') ?? '');

  if(!$force && $this->isUserRankOverridden($u)) {
    return (string) ($u->get('forum_rank') ?? '');
  }

  $count = $this->getUserPostCount($u);
  $rank  = $this->getAutoRankForCount($count);
  $rank  = $this->normalizeRank($rank);

  if(method_exists($u, 'hasField') && $u->hasField('forum_rank')) {
    $u->of(false);
    $u->set('forum_rank', $rank);
    // ensure override is off when auto applies
    if(method_exists($u, 'hasField') && $u->hasField('forum_rank_manual_override')) {
      $u->set('forum_rank_manual_override', 0);
      $u->save(['forum_rank','forum_rank_manual_override']);
    } else {
      $u->save('forum_rank');
    }
    $u->of(true);
  }

  return $rank;
}


  public function forumRoot(): ?Page {
    $id = (int) $this->get('forumRoot');
    if($id) {
      $p = $this->pages->get($id);
      if($p && $p->id) return $p;
    }

    // Prefer the conventional /forum/ name if present
    $p = $this->pages->get("template=forum-root, name=forum, include=all");
    if($p && $p->id) return $p;

    // Fallback: first forum-root found (supports custom names/paths)
    $p = $this->pages->get("template=forum-root, include=all, sort=path");
    return ($p && $p->id) ? $p : null;
  }

  public function messagesRoot(): ?Page {
    $id = (int) $this->get('messagesRoot');
    if($id) return $this->pages->get($id);
    $p = $this->pages->get("template=forum-messages-root, name=messages, include=all");
    return $p->id ? $p : null;
  }

  public function userProfileRoot(): ?Page {
    $id = (int) $this->get('userProfileRoot');
    if($id) {
      $p = $this->pages->get($id);
      if($p && $p->id) return $p;
    }

    // Prefer path
    $p = $this->pages->get('/forum-user/');
    if($p && $p->id) return $p;

    // Fallback
    $p = $this->pages->get("template=forum-user, name=forum-user, include=all");
    return ($p && $p->id) ? $p : null;
  }

  /**
   * Return public profile URL like /forum-user/username/
   * Requires forum-user template to have urlSegments enabled.
   * Falls back to /forum-user/?id=123 if URL segments disabled.
   */
  public function userProfileUrl(User $u): string {
    if(!$u || !$u->id) return '#';

    $root = $this->userProfileRoot();
    if(!$root || !$root->id) return '#';

    $name = $this->wire('sanitizer')->pageName($u->name, true);

    $tpl = $root->template;
    $segmentsEnabled = false;
    if($tpl && $tpl->id) {
      $segmentsEnabled = (bool) $tpl->get('urlSegments');
    }

    if($segmentsEnabled) {
      return $root->url . $name . '/';
    }

    return $root->url . '?id=' . (int) $u->id;
  }

  public function privateMessagesEnabled(): bool {
    return (int) $this->get('allowPrivateMessages') === 1;
  }

  /**
   * Count unread private messages for a given user.
   */
  public function unreadMessageCount(User $u): int {
    if(!$this->privateMessagesEnabled()) return 0;
    if(!$u || !$u->id) return 0;
    $root = $this->messagesRoot();
    if(!$root || !$root->id) return 0;

    try {
      // Use include=all so admins can see counts even when messages are hidden/unpublished.
      return (int) $this->pages->count(
        "template=forum-message, has_parent={$root->id}, forum_to_user={$u->id}, forum_is_read=0, include=all"
      );
    } catch(\Throwable $e) {
      return 0;
    }
  }

  /**
   * Newest unread private message for a user (used for the "new message" notice).
   */
  public function latestUnreadMessage(User $u): ?Page {
    if(!$this->privateMessagesEnabled() || !$u || !$u->id) return null;
    $root = $this->messagesRoot();
    if(!$root || !$root->id) return null;
    try {
      $m = $this->pages->findOne("template=forum-message, has_parent={$root->id}, forum_to_user={$u->id}, forum_is_read=0, sort=-created, include=all");
      return ($m && $m->id) ? $m : null;
    } catch(\Throwable $e) {
      return null;
    }
  }

  public function canReadForum(User $u = null, Page $context = null): bool {
  if(!$u) $u = $this->wire('user');

  // If a context is given, enforce category restrictions
  if($context && $context->id) {
    $cat = null;
    if($context->template && $context->template->name === 'forum-category') {
      $cat = $context;
    } elseif($context->template && in_array($context->template->name, ['forum-topic','forum-reply'], true)) {
      $cat = $context->template->name === 'forum-topic' ? $context->parent : ($context->parent ? $context->parent->parent : null);
    }
    if($cat && $cat->id) {
      return $this->canReadCategory($u, $cat);
    }
  }

  if((int)$this->get('allowGuestsRead')) return true;
  return $u && $u->id && !$u->isGuest();
}


  public function canPost(User $u = null): bool {
    if(!$u) $u = $this->wire('user');
    $allowGuestsPost = (int) $this->get('allowGuestsPost');

    // Guest
    if(!$u || !$u->id || $u->isGuest()) {
      return $allowGuestsPost ? true : false;
    }

    // Superuser always allowed
    if($u->isSuperuser()) return true;

    // Forum-specific roles if you want them
    if($u->hasRole('forum-admin') || $u->hasRole('forum-member')) return true;

    // Fallback: any registered (non-guest) user can post
    if(!$u->isGuest()) return true;

    return false;
  }

  public function canVote(User $u = null): bool {
    if(!$u) $u = $this->wire('user');
    $allowGuestsVote = (int) $this->get('allowGuestsVote');

    // Guest
    if(!$u || !$u->id || $u->isGuest()) {
      return $allowGuestsVote ? true : false;
    }



    // Any registered (non-guest) user may vote
    if(!$u->isGuest()) return true;

    return false;
  }

  /**
   * Is user a forum moderator/admin? (frontend moderation privileges)
   */
  public function isModerator(User $u = null): bool {
    if(!$u) $u = $this->wire('user');
    if(!$u || !$u->id) return false;
    if($u->isSuperuser()) return true;
    return $u->hasRole('forum-admin');
  }

  /************************************************************
   * POST EDITING
   ************************************************************/

  /**
   * Returns edit window in seconds.
   * 0 = disabled, -1 = indefinitely.
   */
  public function getPostEditWindowSeconds(): int {
    $w = (string) ($this->get('postEditWindow') ?? '1w');
    switch($w) {
      case '0':   return 0;
      case '15m': return 15 * 60;
      case '1h':  return 60 * 60;
      case '1d':  return 24 * 60 * 60;
      case '1w':  return 7 * 24 * 60 * 60;
      case '1m':  return 30 * 24 * 60 * 60;
      case 'inf': return -1;
      default:    return 7 * 24 * 60 * 60;
    }
  }

  /**
   * Can user edit the given topic/reply post?
   */
  public function canEditPost(Page $post, User $u = null): bool {
    if(!$u) $u = $this->wire('user');
    if(!$u || !$u->id) return false;
    if($this->isModerator($u)) return true;
    if(!$post || !$post->id) return false;
    if(!$post->hasField('forum_author') || !$post->forum_author || (int)$post->forum_author->id !== (int)$u->id) return false;

    $window = $this->getPostEditWindowSeconds();
    if($window === 0) return false;
    if($window < 0) return true;

    $created = (int) $post->created;
    if(!$created) return false;
    return (time() - $created) <= $window;
  }

  /**
   * Update post body (topic or reply). Returns true if saved.
   */
  public function updatePostBody(Page $post, User $u, string $body): bool {
    if(!$this->canEditPost($post, $u)) return false;
    if(!in_array($post->template->name, ['forum-topic','forum-reply'], true)) return false;
    $post->of(false);
    $post->set('forum_body', $this->sanitizePostBody($body));
    $post->save('forum_body');
    $post->of(true);
    return true;
  }

  /************************************************************
   * TOPIC FOLLOWING
   ************************************************************/

  public function topicFollowEnabled(): bool {
    return (int) $this->get('enableTopicFollow') === 1;
  }

  public function isFollowingTopic(User $u, Page $topic): bool {
    if(!$this->topicFollowEnabled()) return false;
    if(!$u || !$u->id) return false;
    if(!$topic || !$topic->id || $topic->template->name !== 'forum-topic') return false;
    if(!$u->hasField('forum_followed_topics')) return false;
    try {
      return $u->forum_followed_topics && $u->forum_followed_topics->has($topic);
    } catch(\Throwable $e) {
      return false;
    }
  }

  /**
   * Toggle follow state. Returns true if now following.
   */
  public function toggleFollowTopic(User $u, Page $topic): bool {
    if(!$this->topicFollowEnabled()) return false;
    if(!$u || !$u->id || $u->isGuest()) return false;
    if(!$topic || !$topic->id || $topic->template->name !== 'forum-topic') return false;
    if(!$u->hasField('forum_followed_topics')) return false;

    $u->of(false);
    $now = false;
    if($u->forum_followed_topics && $u->forum_followed_topics->has($topic)) {
      $u->forum_followed_topics->remove($topic);
      $now = false;
    } else {
      $u->forum_followed_topics->add($topic);
      $now = true;
    }
    $u->save('forum_followed_topics');
    $u->of(true);
    return $now;
  }

  /**
   * Topics a member follows, newest activity first, with the number of replies they have not read yet.
   * @return array[] each: topic (Page), replies, last_created, last_id, new (int), url (first unread post, else last post)
   */
  public function followedTopics(User $u, int $limit = 200): array {
    if(!$u->id || $u->isGuest() || !$u->hasField('forum_followed_topics')) return [];
    $mk = 'followed_' . (int) $u->id;
    if(isset($this->sfMemo[$mk])) return $this->sfMemo[$mk];
    $ids = [];
    try {
      $v = $u->getUnformatted('forum_followed_topics');
      if($v instanceof PageArray) $ids = $v->explode('id');
      elseif($v instanceof Page && $v->id) $ids = [$v->id];
    } catch(\Throwable $e) {}
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if(!$ids) return $this->sfMemo[$mk] = [];
    $topics = $this->wire('pages')->getById($ids, ['template' => $this->wire('templates')->get('forum-topic'), 'getNumChildren' => false]);
    $stats = $this->topicStats($ids);
    $read = json_decode((string) ($u->get('forum_read_topics') ?: '{}'), true);
    if(!is_array($read)) $read = [];
    $rows = [];
    $since = [];
    foreach($topics as $t) {
      if(!$t->id || $t->isTrash() || $t->isUnpublished() || !$this->canReadForum($u, $t->parent)) continue;
      $st = $stats[(int) $t->id] ?? ['replies' => 0, 'last_id' => 0, 'last_created' => 0];
      $seen = (int) ($read[(string) $t->id] ?? $read[(int) $t->id] ?? 0);
      $since[(int) $t->id] = $seen;
      $rows[(int) $t->id] = ['topic' => $t, 'replies' => (int) $st['replies'], 'last_created' => max((int) $t->created, (int) $st['last_created']),
        'last_id' => (int) $st['last_id'], 'new' => 0, 'seen' => $seen, 'url' => $t->url];
    }
    // unread replies per topic (one query)
    $rt = $this->sfTplId('forum-reply');
    if($rows && $rt) {
      $min = min($since);
      try {
        $in = implode(',', array_keys($rows));
        $sql = "SELECT id, parent_id, UNIX_TIMESTAMP(created) AS c FROM pages WHERE parent_id IN ($in) AND templates_id=$rt AND status<1024 AND created > FROM_UNIXTIME(" . (int) $min . ") ORDER BY created, id";
        foreach($this->wire('database')->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $r) {
          $tid = (int) $r['parent_id'];
          if(!isset($rows[$tid]) || (int) $r['c'] <= $rows[$tid]['seen']) continue;
          if($rows[$tid]['seen'] === 0) continue; // never opened: no "new" count, the topic itself is new to them
          if(!$rows[$tid]['new']) $rows[$tid]['first_new'] = (int) $r['id'];
          $rows[$tid]['new']++;
        }
      } catch(\Throwable $e) {}
    }
    foreach($rows as $tid => $r) {
      $jump = !empty($r['first_new']) ? $r['first_new'] : $r['last_id'];
      if($jump) {
        $rp = $this->wire('pages')->get($jump);
        if($rp && $rp->id) $rows[$tid]['url'] = $this->replyUrl($rp);
      }
    }
    usort($rows, function($a, $b) { return $b['last_created'] <=> $a['last_created']; });
    return $this->sfMemo[$mk] = array_slice($rows, 0, $limit);
  }

  /** How many followed topics have replies the member has not read yet. */
  public function followedNewCount(User $u): int {
    $n = 0;
    foreach($this->followedTopics($u) as $r) if($r['new'] > 0) $n++;
    return $n;
  }

  protected function notifyTopicFollowers(Page $topic, Page $reply, User $author): void {
    if(!$this->topicFollowEnabled()) return;
    if(!(int)$this->get('sendFollowEmails')) return;
    if(!$topic || !$topic->id || !$reply || !$reply->id) return;

    // Find followers (excluding the replying author)
    try {
      $followers = $this->users->find("template=user, forum_followed_topics={$topic->id}, id!={$author->id}, include=all");
    } catch(\Throwable $e) {
      return;
    }
    if(!$followers || !$followers->count()) return;

    // Use a site-owned FROM address to reduce spam/spoofing issues.
    // If you want replies to go somewhere specific, set $config->adminEmail.
    $from = (string) ($this->wire('config')->adminEmail ?: 'no-reply@' . $this->wire('config')->httpHost);
    $fromName = (string) ($this->wire('config')->pageName ?: 'Forum');
    $subject = 'New reply: ' . (string) $topic->title;

    $bodyRaw = $reply->hasField('forum_body') ? (string) $reply->forum_body : '';
    $preview = trim(strip_tags($bodyRaw));
    if(strlen($preview) > 240) $preview = substr($preview, 0, 240) . '...';

    $url = $topic->httpUrl . '#reply-' . (int) $reply->id;
    foreach($followers as $fu) {
      $email = (string) $fu->email;
      if(!$email) continue;

      $html = "<p>Hello {$fu->name},</p>"
        . "<p><strong>{$author->name}</strong> replied to a topic you follow:</p>"
        . "<p><strong>" . htmlspecialchars((string)$topic->title, ENT_QUOTES, 'UTF-8') . "</strong></p>"
        . ($preview ? "<blockquote style='margin:12px 0;padding:10px 12px;border-left:3px solid #ccc;'><p style='margin:0;'>" . htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') . "</p></blockquote>" : '')
        . "<p><a href='{$url}'>View reply</a></p>";

      $text = "{$author->name} replied to a topic you follow: {$topic->title}\n\n";
      if($preview) $text .= $preview . "\n\n";
      $text .= "View: {$url}\n";

      try {
        $m = wireMail();
        $m->to($email)->from($from, $fromName)->subject($subject);
        $m->body($text);
        $m->bodyHTML($html);
        $m->send();
      } catch(\Throwable $e) {
        // ignore
      }
    }
  }


/**
 * Category read permission based on optional role lists stored on category page.
 * If no roles are selected, falls back to module allowGuestsRead / logged-in.
 */
/**
 * Category read permission based on optional role lists stored on category page.
 * If no roles are selected, falls back to module allowGuestsRead / logged-in.
 * If category is marked VIP and has no explicit role lists, default VIP roles from module config apply.
 */
public function canReadCategory(User $u = null, Page $category = null): bool {
  if(!$u) $u = $this->wire('user');
  if(!$category || !$category->id) return $this->canReadForum($u);

  // superuser / forum-admin always allowed
  if($this->isModerator($u)) return true;

  // Explicit read roles on category
  if(method_exists($category, 'hasField') && $category->hasField('forum_read_roles')) {
    $roles = $category->get('forum_read_roles');
    if($roles instanceof PageArray && $roles->count()) {
      if(!$u || !$u->id) return false;
      foreach($roles as $r) {
        if($r && $r->name && $u->hasRole($r->name)) return true;
      }
      return false;
    }
  }

  // Default VIP roles (if category is VIP and no explicit read roles set)
if($this->isVipCategory($category)) {
  $defaults = $this->getDefaultVipRoleNames('read');
  if(count($defaults)) {
    if(!$u || !$u->id) return false;
    foreach($defaults as $rn) {
      if($rn !== '' && $u->hasRole($rn)) return true;
    }
    return false;
  } else {
    // VIP category but no roles configured => restrict it for everyone except moderators
    return false;
  }
}

  return $this->canReadForum($u);
}

/**
 * Category write permission based on optional role lists stored on category page.
 * If no roles are selected, falls back to module canPost (guest settings etc.).
 * If category is marked VIP and has no explicit role lists, default VIP roles from module config apply.
 */
public function canWriteCategory(User $u = null, Page $category = null): bool {
  if(!$u) $u = $this->wire('user');
  if(!$category || !$category->id) return $this->canPost($u);

  // superuser / forum-admin always allowed
  if($this->isModerator($u)) return true;

  // Explicit write roles on category
  if(method_exists($category, 'hasField') && $category->hasField('forum_write_roles')) {
    $roles = $category->get('forum_write_roles');
    if($roles instanceof PageArray && $roles->count()) {
      if(!$u || !$u->id) return false;
      foreach($roles as $r) {
        if($r && $r->name && $u->hasRole($r->name)) return true;
      }
      return false;
    }
  }

  // If read roles are set but write roles aren't, treat as read-only for those roles,
  // and use normal posting permissions for users who can read.
  if(method_exists($category, 'hasField') && $category->hasField('forum_read_roles')) {
    $readRoles = $category->get('forum_read_roles');
    if($readRoles instanceof PageArray && $readRoles->count()) {
      return $this->canPost($u);
    }
  }

  // Default VIP roles (write)
if($this->isVipCategory($category)) {
  $defaults = $this->getDefaultVipRoleNames('write');
  if(count($defaults)) {
    if(!$u || !$u->id) return false;
    foreach($defaults as $rn) {
      if($rn !== '' && $u->hasRole($rn)) return true;
    }
    return false;
  } else {
    // VIP category but no roles configured => no writing for non-moderators
    return false;
  }
}

  return $this->canPost($u);
}

protected function getDefaultVipRoleNames(string $which = 'read'): array {
  $key = $which === 'write' ? 'defaultVipWriteRoles' : 'defaultVipReadRoles';
  $val = $this->get($key);

  if(is_string($val)) {
    // tolerate comma-separated
    $parts = array_filter(array_map('trim', explode(',', $val)));
    return array_values($parts);
  }
  if(is_array($val)) return array_values(array_filter(array_map('trim', $val)));
  return [];
}

public function isVipCategory(Page $category): bool {
  if(!$category || !$category->id) return false;

  // Per-category VIP flag
  if(method_exists($category, 'hasField') && $category->hasField('forum_is_vip')) {
    if((int) $category->get('forum_is_vip') === 1) return true;
  }

  // Inherit VIP from parent group
  $p = $category->parent;
  if($p && $p->id && $p->template && $p->template->name === 'forum-group') {
    if(method_exists($p, 'hasField') && $p->hasField('forum_group_is_vip')) {
      return (int) $p->get('forum_group_is_vip') === 1;
    }
  }

  return false;
}

/**
 * Is the given group VIP? (group-level VIP, inherited by all categories/topics under it)
 */
public function isVipGroup(Page $group): bool {
  if(!$group || !$group->id) return false;
  if(!$group->template || $group->template->name !== 'forum-group') return false;
  if(method_exists($group, 'hasField') && $group->hasField('forum_group_is_vip')) {
    return (int) $group->get('forum_group_is_vip') === 1;
  }
  return false;
}

/**
 * Can the given user read/view this group page?
 * If the group is VIP, uses default VIP read roles from module settings.
 */
public function canReadGroup(User $u = null, Page $group = null): bool {
  if(!$u) $u = $this->wire('user');
  if(!$group || !$group->id) return $this->canReadForum($u);

  // superuser / forum-admin always allowed
  if($this->isModerator($u)) return true;

  // If not VIP, fall back to general forum read rules
  if(!$this->isVipGroup($group)) return $this->canReadForum($u);

  // VIP group: apply default VIP read roles
  $defaults = $this->getDefaultVipRoleNames('read');
  if(count($defaults)) {
    if(!$u || !$u->id) return false;
    foreach($defaults as $rn) {
      if($rn !== '' && $u->hasRole($rn)) return true;
    }
    return false;
  }

  // VIP group but no roles configured => restrict it for everyone except moderators
  return false;
}


  /**
   * Is the given group pinned?
   */
  public function isPinnedGroup(Page $group): bool {
    if(!$group || !$group->id) return false;
    if(!$group->template || $group->template->name !== 'forum-group') return false;
    if(method_exists($group, 'hasField') && $group->hasField('forum_group_is_pinned')) {
      return (int) $group->get('forum_group_is_pinned') === 1;
    }
    return false;
  }

  /**
   * Is the topic effectively pinned (topic pinned OR parent group pinned).
   */
  public function isPinnedTopic(Page $topic): bool {
    if(!$topic || !$topic->id) return false;
    if(!$topic->template || $topic->template->name !== 'forum-topic') return false;

    if(method_exists($topic, 'hasField') && $topic->hasField('forum_is_pinned')) {
      if((int) $topic->get('forum_is_pinned') === 1) return true;
    }

    $cat = $topic->parent;
    if($cat && $cat->id && $cat->template && $cat->template->name === 'forum-category') {
      $g = $cat->parent;
      if($g && $g->id && $g->template && $g->template->name === 'forum-group') {
        return $this->isPinnedGroup($g);
      }
    }

    return false;
  }


  /**
   * Can the given user post a reply to the given topic (takes topic lock into account).
   */
  public function canPostToTopic(Page $topic, User $u = null): bool {
    if(!$u) $u = $this->wire('user');
    if(!$this->canPost($u)) return false;
    if($topic->template->name !== 'forum-topic') return false;
    if($topic->hasField('forum_is_locked') && (int)$topic->forum_is_locked) {
      return $this->isModerator($u);
    }
    return true;
  }

  public function setTopicLocked(Page $topic, bool $locked, User $u = null): void {
    if(!$u) $u = $this->wire('user');
    if(!$this->isModerator($u)) throw new WireException('Not allowed.');
    if($topic->template->name !== 'forum-topic') throw new WireException('Not a topic.');
    $topic->of(false);
    $topic->set('forum_is_locked', $locked ? 1 : 0);
    $topic->save('forum_is_locked');
    $topic->of(true);
  }

  public function setTopicPinned(Page $topic, bool $pinned, User $u = null): void {
    if(!$u) $u = $this->wire('user');
    if(!$this->isModerator($u)) throw new WireException('Not allowed.');
    if($topic->template->name !== 'forum-topic') throw new WireException('Not a topic.');
    $topic->of(false);
    $topic->set('forum_is_pinned', $pinned ? 1 : 0);
    $topic->save('forum_is_pinned');
    $topic->of(true);
  }

  public function moveTopic(Page $topic, Page $newCategory, User $u = null): void {
    if(!$u) $u = $this->wire('user');
    if(!$this->isModerator($u)) throw new WireException('Not allowed.');
    if($topic->template->name !== 'forum-topic') throw new WireException('Not a topic.');
    if($newCategory->template->name !== 'forum-category') throw new WireException('Not a forum category.');
    if($topic->parent && $topic->parent->id === $newCategory->id) return;
    $topic->of(false);
    $topic->parent = $newCategory;
    $topic->save();
    $topic->of(true);
  }

  public function setReplyDeleted(Page $reply, bool $deleted, User $u = null): void {
    if(!$u) $u = $this->wire('user');
    if(!$this->isModerator($u)) throw new WireException('Not allowed.');
    if($reply->template->name !== 'forum-reply') throw new WireException('Not a reply.');
    $reply->of(false);
    $reply->set('forum_is_deleted', $deleted ? 1 : 0);
    $reply->save('forum_is_deleted');
    $reply->of(true);
  }



  /************************************************************
   * INSTALL / UNINSTALL / UPGRADE
   ************************************************************/
  /** Reports saved by the module's former name (SimpleForum) move into the Native Forum table. */
  protected function adoptOldReports(): void {
    $db = $this->wire('database');
    try {
      $this->ensureReportsTable();
      $oldTbl = 'simple' . 'forum_reports';
      $q = $db->query("SHOW TABLES LIKE '$oldTbl'");
      if(!$q || !$q->fetchColumn()) return;
      $cnt = (int) $db->query("SELECT COUNT(*) FROM nativeforum_reports")->fetchColumn();
      if(!$cnt) $db->exec("INSERT INTO nativeforum_reports SELECT * FROM `$oldTbl`");
      $db->exec("DROP TABLE `$oldTbl`");
    } catch(\Throwable $e) { $this->wire('log')->save('native-forum', 'reports migration: ' . $e->getMessage()); }
  }

  /** Field tags with exactly one "NativeForum-Fields" (removes duplicates and the former module's tag). */
  protected function forumFieldTags(string $tags): string {
    $out = [];
    foreach(preg_split('/\s+/', trim($tags)) as $t) {
      if($t === '' || $t === 'Simple' . 'Forum-Fields' || $t === 'NativeForum-Fields') continue;
      $out[$t] = $t;
    }
    $out['NativeForum-Fields'] = 'NativeForum-Fields';
    return implode(' ', $out);
  }

  /**
   * One-time takeover of an existing SimpleForum install (the module's former name).
   * Never calls the old module's uninstall (that would delete the forum pages and fields):
   * it copies the settings, renames the admin page/permission/field tags, keeps pending sign-ups,
   * updates the site's forum template files and moves the old module folder out of the way.
   */
  protected function migrateFromSimpleForum(): void {
    $db = $this->wire('database');
    $modules = $this->wire('modules');
    $config = $this->wire('config');
    $old = 'Simple' . 'Forum'; // old module name
    $oldProc = 'Process' . $old;
    $st = $db->prepare("SELECT id, class, data FROM modules WHERE class IN (:a, :b)");
    $st->execute([':a' => $old, ':b' => $oldProc]);
    $rows = [];
    foreach($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $rows[$r['class']] = $r;
    if(!isset($rows[$old])) return;

    // 1) settings
    $oldCfg = json_decode((string) $rows[$old]['data'], true);
    if(is_array($oldCfg) && $oldCfg) {
      $cur = $modules->getConfig($this);
      $modules->saveConfig($this, array_merge($oldCfg, is_array($cur) ? $cur : []));
    }
    // 2) permission simple-forum -> native-forum (roles keep it)
    $perms = $this->wire('permissions');
    $op = $perms->get('simple-forum');
    if($op && $op->id && !$perms->get('native-forum')->id) {
      $op->of(false); $op->name = 'native-forum'; $op->title = 'Access Native Forum admin'; $perms->save($op);
    }
    // 3) admin page simple-forum -> native-forum (process is set when the admin module installs)
    $ap = $this->wire('pages')->get('template=admin, name=simple-forum, include=all');
    if($ap && $ap->id) { $ap->of(false); $ap->name = 'native-forum'; $ap->title = 'Native Forum'; $ap->save(); }
    // 4) field tags
    foreach($this->wire('fields') as $f) {
      $tags = (string) $f->tags;
      if(strpos($tags, $old . '-Fields') === false) continue;
      $f->tags = $this->forumFieldTags($tags); $this->wire('fields')->save($f);
    }
    // 5) pending registrations, flood counters, upload state (WireCache namespace)
    try { $db->exec("UPDATE caches SET name=CONCAT('NativeForum__', SUBSTRING(name, " . (strlen($old) + 3) . ")) WHERE name LIKE '" . $old . "\\_\\_%'"); } catch(\Throwable $e) {}
    try { $db->exec("DELETE FROM caches WHERE name LIKE '" . $old . "View\\_\\_%'"); } catch(\Throwable $e) {}
    // 5b) reports of the old module
    $this->adoptOldReports();
    // 6) site templates: point them to the new module / file names
    $map = [$oldProc => 'ProcessNativeForum', $old . 'Seo' => 'NativeForumSeo', $old => 'NativeForum', 'simple-forum' => 'native-forum', 'Simple Forum' => 'Native Forum'];
    $tdir = rtrim((string) $config->paths->templates, '/\\') . '/';
    foreach(array_merge(glob($tdir . 'forum-*.php') ?: [], glob($tdir . 'simple-forum*') ?: []) as $file) {
      $c = @file_get_contents($file);
      if(!is_string($c)) continue;
      $n = str_replace(array_keys($map), array_values($map), $c);
      $base = basename($file);
      $dst = strpos($base, 'simple-forum') === 0 ? $tdir . str_replace('simple-forum', 'native-forum', $base) : $file;
      if($n !== $c || $dst !== $file) {
        if(@file_put_contents($dst, $n) !== false && $dst !== $file) @unlink($file);
      }
    }
    // 7) forget the old modules (rows only — their uninstall must never run) and park the old folder
    $del = $db->prepare("DELETE FROM modules WHERE class IN (:a, :b)");
    $del->execute([':a' => $old, ':b' => $oldProc]);
    $oldDir = rtrim((string) $config->paths->siteModules, '/\\') . '/' . $old;
    if(is_dir($oldDir) && is_writable(dirname($oldDir))) {
      $park = dirname($oldDir) . '/.' . $old . '-replaced-by-NativeForum';
      if(!file_exists($park)) @rename($oldDir, $park);
    }
    // module info caches are rebuilt on the next request
    try { $db->exec("DELETE FROM caches WHERE name LIKE 'Modules%'"); } catch(\Throwable $e) {}
    $this->message('Native Forum took over the existing SimpleForum install (settings, pages, admin and permission). The old module folder was moved to /site/modules/.' . $old . '-replaced-by-NativeForum/ — you can delete it.');
    $this->wire('log')->save('native-forum', 'Migrated from SimpleForum');
  }

  public function ___install() {
    $this->ensurePurifierCachePath();
    // Coming from the old "SimpleForum" module: take over its settings, admin page and permission
    try { $this->migrateFromSimpleForum(); } catch(\Throwable $e) { $this->wire('log')->save('native-forum', 'migration: ' . $e->getMessage()); }

$fields    = $this->wire('fields');
    $templates = $this->wire('templates');
    $pages     = $this->wire('pages');

	    /* 1) FIELDS
	     ****************/
    $f_body = $this->createField('forum_body', 'FieldtypeTextarea', 'Forum body', [
      'inputfieldClass' => 'InputfieldCKEditor',
      // No textformatters so simple HTML tags from the mini-toolbar are preserved
      'textformatters'  => []
    ]);

    $f_author = $this->createField('forum_author', 'FieldtypePage', 'Forum author', [
      'inputfieldClass'    => 'InputfieldSelect',
      'labelFieldName'     => 'name',
      'findPagesSelector'  => 'template=user',
      'allowUnpub'         => 0,
      'allowRoot'          => 0,
      'maxPages'           => 1,
    ]);

    $f_likes = $this->createField('forum_like_users', 'FieldtypePage', 'Forum likes', [
      'inputfieldClass'    => 'InputfieldAsmSelect',
      'labelFieldName'     => 'name',
      'findPagesSelector'  => 'template=user',
      'allowUnpub'         => 0,
      'allowRoot'          => 0,
      'maxPages'           => 0,
    ]);

    $f_vote = $this->createField('forum_vote_score', 'FieldtypeInteger', 'Forum vote score');

    $f_vote_users = $this->createField('forum_vote_users', 'FieldtypePage', 'Forum vote users', [
      'inputfieldClass'    => 'InputfieldAsmSelect',
      'labelFieldName'     => 'name',
      'findPagesSelector'  => 'template=user',
      'allowUnpub'         => 0,
      'allowRoot'          => 0,
      'maxPages'           => 0,
    ]);

    $f_from = $this->createField('forum_from_user', 'FieldtypePage', 'Message from user', [
      'inputfieldClass'    => 'InputfieldSelect',
      'labelFieldName'     => 'name',
      'findPagesSelector'  => 'template=user',
      'maxPages'           => 1,
    ]);

    $f_to = $this->createField('forum_to_user', 'FieldtypePage', 'Message to user', [
      'inputfieldClass'    => 'InputfieldSelect',
      'labelFieldName'     => 'name',
      'findPagesSelector'  => 'template=user',
      'maxPages'           => 1,
    ]);

    $f_is_read = $this->createField('forum_is_read', 'FieldtypeCheckbox', 'Message read?');

    // User profile fields
    $f_nick = $this->createField('forum_nickname', 'FieldtypeText', 'Forum nickname');
    // Optional: user country (free text or short code)
    $f_country = $this->createField('forum_country', 'FieldtypeText', 'Forum country');
    $f_avatar = $this->createField('forum_avatar', 'FieldtypeImage', 'Forum avatar', [
      'inputfieldClass' => 'InputfieldImage',
      'maxFiles'        => 1,
      'extensions'      => 'jpg jpeg png gif webp'
    ]);

    $f_signature = $this->createField('forum_signature', 'FieldtypeTextarea', 'Forum signature', [
      'inputfieldClass' => 'InputfieldTextarea'
    ]);

    $f_show_email = $this->createField('forum_show_email', 'FieldtypeCheckbox', 'Show email on forum');

    // Manual rank on user
    $f_rank = $this->createField('forum_rank', 'FieldtypeText', 'Forum rank');
    $f_post_count = $this->createField('forum_post_count', 'FieldtypeInteger', 'Forum post count');
    $f_rank_override = $this->createField('forum_rank_manual_override', 'FieldtypeCheckbox', 'Forum rank manual override');

    // Followed topics on user (used for topic follow/unfollow)
    $f_followed = $this->createField('forum_followed_topics', 'FieldtypePage', 'Followed forum topics', [
      'inputfieldClass'   => 'InputfieldAsmSelect',
      'labelFieldName'    => 'title',
      'findPagesSelector' => 'template=forum-topic, include=all, sort=title',
      'maxPages'          => 0,
    ]);


    // Per-user read tracking map (JSON)
    $f_read_topics = $this->createField('forum_read_topics', 'FieldtypeTextarea', 'Forum read topics map', [
      'inputfieldClass' => 'InputfieldTextarea',
      'textformatters'  => [],
      'rows'            => 2,
    ]);
    // Reply reference (replying to a specific reply)
    $f_reply_to = $this->createField('forum_reply_to', 'FieldtypePage', 'Reply to message', [
      'inputfieldClass'    => 'InputfieldSelect',
      'labelFieldName'     => 'title',
      'findPagesSelector'  => 'template=forum-reply',
      'maxPages'           => 1,
    ]);


// Moderation fields
$f_topic_locked = $this->createField('forum_is_locked', 'FieldtypeCheckbox', 'Topic locked?');
$f_topic_pinned = $this->createField('forum_is_pinned', 'FieldtypeCheckbox', 'Topic pinned?');
$f_reply_deleted = $this->createField('forum_is_deleted', 'FieldtypeCheckbox', 'Reply deleted?');

// View counter
$f_topic_views = $this->createField('forum_views', 'FieldtypeInteger', 'Topic views', [
  'inputfieldClass' => 'InputfieldInteger',
  'min'             => 0,
  'defaultValue'    => 0,
]);


// Topic tags (space-separated slugs, stored as plain text for speed + portability)
$f_topic_tags = $this->createField('forum_tags', 'FieldtypeText', 'Topic tags', [
  'inputfieldClass' => 'InputfieldText',
  'maxlength' => 255,
  'description' => 'Space-separated tag slugs (e.g. "processwire module help"). Stored without # for fast filtering.',
  'notes' => 'Users enter tags in the topic form; Native Forum normalizes them to lowercase slugs.',
]);




// VIP / role-based access fields (set per category)
$f_read_roles = $this->createField('forum_read_roles', 'FieldtypePage', 'Allowed read roles (optional)', [
  'inputfieldClass'   => 'InputfieldAsmSelect',
  'labelFieldName'    => 'name',
  'findPagesSelector' => 'template=role, include=all, sort=name',
  'maxPages'          => 0,
]);

$f_write_roles = $this->createField('forum_write_roles', 'FieldtypePage', 'Allowed write roles (optional)', [
  'inputfieldClass'   => 'InputfieldAsmSelect',
  'labelFieldName'    => 'name',
  'findPagesSelector' => 'template=role, include=all, sort=name',
  'maxPages'          => 0,
]);


    $f_is_vip = $this->createField('forum_is_vip', 'FieldtypeCheckbox', 'VIP category?');

    // Group-level VIP / pinned (applies to all categories/topics under the group)
    $f_group_vip = $this->createField('forum_group_is_vip', 'FieldtypeCheckbox', 'VIP group?');
    $f_group_pinned = $this->createField('forum_group_is_pinned', 'FieldtypeCheckbox', 'Pinned group?');


    // Summary fields (shown under title in lists)
    $f_sum_cat = $this->createField('field_forum_summary_category', 'FieldtypeTextarea', 'Category summary', [
      'inputfieldClass' => 'InputfieldTextarea',
      'rows'            => 1,
      'maxlength'       => 255,
      'description'     => 'Short summary shown under category title (max 255 chars).',
      'textformatters'  => []
    ]);

    $f_sum_topic = $this->createField('field_forum_summary_topic', 'FieldtypeTextarea', 'Topic summary', [
      'inputfieldClass' => 'InputfieldTextarea',
      'rows'            => 1,
      'maxlength'       => 255,
      'description'     => 'Short summary shown under topic title (max 255 chars).',
      'textformatters'  => []
    ]);

	
    // Post attachments
    $f_post_images = $this->createField('forum_post_images', 'FieldtypeImage', 'Forum post images', [
      'inputfieldClass' => 'InputfieldImage',
      'maxFiles'        => 20,
      'extensions'      => 'jpg jpeg png gif webp'
    ]);

    $f_post_files = $this->createField('forum_post_files', 'FieldtypeFile', 'Forum post files', [
      'inputfieldClass' => 'InputfieldFile',
      'maxFiles'        => 20,
      'extensions'      => 'pdf zip rar 7z doc docx xls xlsx ppt pptx txt rtf mp3 wav ogg mp4 mov avi'
    ]);

    /* 2) TEMPLATES
	     ****************/
    $tpl_root      = $this->createTemplate('forum-root', ['title']);
    $tpl_group     = $this->createTemplate('forum-group', ['title','forum_group_is_vip','forum_group_is_pinned']);
    $tpl_category  = $this->createTemplate('forum-category', ['title',
      'field_forum_summary_category',
      'forum_read_roles',
      'forum_write_roles',
      'forum_is_vip']);
    $tpl_topic     = $this->createTemplate('forum-topic', [
      'title', 'field_forum_summary_topic', 'forum_tags', 'forum_body', 'forum_author', 'forum_like_users', 'forum_vote_score', 'forum_vote_users',
      'forum_views',
      'forum_is_locked',
      'forum_is_pinned','forum_post_images','forum_post_files']);
    $tpl_reply     = $this->createTemplate('forum-reply', [
      'title', 'forum_body', 'forum_author', 'forum_like_users', 'forum_vote_score', 'forum_vote_users', 'forum_reply_to',
      'forum_is_deleted','forum_post_images','forum_post_files']);
    $tpl_msg_root  = $this->createTemplate('forum-messages-root', ['title']);
    $tpl_message   = $this->createTemplate('forum-message', [
      'title', 'forum_body', 'forum_from_user', 'forum_to_user', 'forum_is_read', 'forum_vote_score', 'forum_vote_users'
    ]);
    $tpl_profile   = $this->createTemplate('forum-profile', ['title']);
    $tpl_user      = $this->createTemplate('forum-user', ['title']);
    $tpl_login     = $this->createTemplate('forum-login', ['title']);
    $tpl_register  = $this->createTemplate('forum-register', ['title']);

    // Families
    
$tpl_root->noChildren = 0;
// Allow forum groups (preferred) and categories directly under root (backwards compatible)
$tpl_root->set('childTemplates', [$tpl_group->id, $tpl_category->id]);
$templates->save($tpl_root);


$tpl_group->noChildren = 0;
$tpl_group->set('childTemplates', [$tpl_category->id]);
$templates->save($tpl_group);

$tpl_category->noChildren = 0;
    $tpl_category->set('childTemplates', [$tpl_topic->id]);
    $templates->save($tpl_category);

    $tpl_topic->noChildren = 0;
    $tpl_topic->set('childTemplates', [$tpl_reply->id]);
    $templates->save($tpl_topic);

    $tpl_reply->noChildren = 1;
    $templates->save($tpl_reply);

    $tpl_msg_root->noChildren = 0;
    $tpl_msg_root->set('urlSegments', 1);
    $tpl_msg_root->set('childTemplates', [$tpl_message->id]);
    $templates->save($tpl_msg_root);

    $tpl_message->noChildren = 1;
    $templates->save($tpl_message);

    $tpl_profile->noChildren = 1;
    $templates->save($tpl_profile);

    $tpl_user->noChildren = 1;
    $tpl_user->set('urlSegments', 1);
    $templates->save($tpl_user);

    $tpl_login->noChildren = 1;
    $templates->save($tpl_login);

    $tpl_register->noChildren = 1;
    $templates->save($tpl_register);

	    /* 3) Add forum profile fields to user template
	     ****************/
    $tpl_pw_user = $templates->get('user');
    if($tpl_pw_user && $tpl_pw_user->id) {
      $fg = $tpl_pw_user->fieldgroup;
      if(!$fg->get('forum_nickname'))   $fg->add($f_nick);
      if(!$fg->get('forum_country'))    $fg->add($f_country);
      if(!$fg->get('forum_avatar'))     $fg->add($f_avatar);
      if(!$fg->get('forum_signature'))  $fg->add($f_signature);
      if(!$fg->get('forum_show_email')) $fg->add($f_show_email);
      if(!$fg->get('forum_rank'))       $fg->add($f_rank);
      if(!$fg->get('forum_post_count')) $fg->add($f_post_count);
      if(!$fg->get('forum_rank_manual_override')) $fg->add($f_rank_override);
      if(!$fg->get('forum_followed_topics')) $fg->add($f_followed);
      if(!$fg->get('forum_read_topics')) $fg->add($f_read_topics);
      $fg->save();
      $tpl_pw_user->fieldgroup = $fg;
      $templates->save($tpl_pw_user);
    }

	    /* 4) Root pages under Home
	     ****************/
    $home = $pages->get(1);

    $forumRoot = $pages->get("template=forum-root, parent_id={$home->id}, name=forum, include=all");
    if(!$forumRoot || !$forumRoot->id) {
      $forumRoot = new Page();
      $forumRoot->template = $tpl_root;
      $forumRoot->parent   = $home;
      $forumRoot->title    = 'Forum';
      $forumRoot->name     = 'forum';
      $forumRoot->save();
    }

    
// --- Forum Groups migration/compatibility ---
// If categories exist directly under /forum/, create a default group and move them under it.
// This keeps old installs working while enabling /forum/<group>/<category>/ structure.
try {
  $directCats = $forumRoot->children("template=forum-category, include=all");
  if($directCats->count()) {
    $defaultGroup = $forumRoot->child("template=forum-group, name=general, include=all");
    if(!$defaultGroup || !$defaultGroup->id) {
      $defaultGroup = new Page();
      $defaultGroup->template = $tpl_group;
      $defaultGroup->parent   = $forumRoot;
      $defaultGroup->title    = 'General';
      $defaultGroup->name     = 'general';
      $defaultGroup->save();
    }
    foreach($directCats as $cat) {
      // Avoid moving if already under a group (shouldn't happen here)
      if($cat->parent && $cat->parent->template && $cat->parent->template->name === 'forum-group') continue;
      $cat->of(false);
      $cat->parent = $defaultGroup;
      $cat->save();
      $cat->of(true);
    }
  }
} catch(\Throwable $e) {
  // keep install resilient
}

// Messages root (be tolerant if a site already has /messages/ created with another template)
    $msgRoot = $pages->get('/messages/');
    if($msgRoot && $msgRoot->id) {
      if($msgRoot->template->name !== 'forum-messages-root') {
        $msgRoot->of(false);
        $msgRoot->template = $tpl_msg_root;
        $msgRoot->title    = $msgRoot->title ?: 'Messages';
        $msgRoot->save();
        $msgRoot->of(true);
      }
    } else {
      $msgRoot = new Page();
      $msgRoot->template = $tpl_msg_root;
      $msgRoot->parent   = $home;
      $msgRoot->title    = 'Messages';
      $msgRoot->name     = 'messages';
      $msgRoot->save();
    }

    $profilePage = $pages->get("template=forum-profile, parent_id={$home->id}, name=forum-profile, include=all");
    if(!$profilePage || !$profilePage->id) {
      $profilePage = new Page();
      $profilePage->template = $tpl_profile;
      $profilePage->parent   = $home;
      $profilePage->title    = 'Forum profile';
      $profilePage->name     = 'forum-profile';
      $profilePage->save();
    }

    // Public user profile root (/forum-user/)
    $userRoot = $pages->get('/forum-user/');
    if($userRoot && $userRoot->id) {
      if($userRoot->template->name !== 'forum-user') {
        $userRoot->of(false);
        $userRoot->template = $tpl_user;
        $userRoot->title    = $userRoot->title ?: 'User';
        $userRoot->save();
        $userRoot->of(true);
      }
    } else {
      $userRoot = $pages->get("template=forum-user, parent_id={$home->id}, name=forum-user, include=all");
      if(!$userRoot || !$userRoot->id) {
        $userRoot = new Page();
        $userRoot->template = $tpl_user;
        $userRoot->parent   = $home;
        $userRoot->title    = 'User';
        $userRoot->name     = 'forum-user';
        $userRoot->save();
      }
    }

    $loginPage = $pages->get("template=forum-login, parent_id={$home->id}, name=forum-login, include=all");
    if(!$loginPage || !$loginPage->id) {
      $loginPage = new Page();
      $loginPage->template = $tpl_login;
      $loginPage->parent   = $home;
      $loginPage->title    = 'Forum login';
      $loginPage->name     = 'forum-login';
      $loginPage->save();
    }

    $registerPage = $pages->get("template=forum-register, parent_id={$home->id}, name=forum-register, include=all");
    if(!$registerPage || !$registerPage->id) {
      $registerPage = new Page();
      $registerPage->template = $tpl_register;
      $registerPage->parent   = $home;
      $registerPage->title    = 'Forum registration';
      $registerPage->name     = 'forum-register';
      $registerPage->save();
    }

    /****************
     * 5) Save config defaults (preserve user values)
     *****************/

    $defaults = [
      'allowGuestsRead' => 1,
      'allowGuestsPost' => 0,
      'allowGuestsRegisterLink' => 1,
      'allowGuestsSearch' => 1,
      'allowGuestsProfile' => 0,
      'enableSubscriptions' => 1,
      'enableReactions' => 0,
      'enableAvatars' => 1,
      'enableSignatures' => 0,
      'enableFrontendWysiwyg' => 1,
      'enableBBCode' => 0,
      // Includes the topic post itself (topic + replies per page)
      'postsPerPage' => 11,
      'showTopicPostOnAllPages' => 0,
      'topicsPerPage' => 20,
      'maxTitleLength' => 120,
      'maxBodyLength' => 15000,
      'guestCanEditMinutes' => 0,
      'memberCanEditMinutes' => 30,
      'moderatorRole' => 'superuser',
      'editorRole' => 'superuser',
    ];

    // Load stored config and only fill in missing keys.
    $stored = (array) $this->modules->getConfig($this);
    $cfg = $stored + $defaults;

    // Always update root IDs (safe to refresh on install/upgrade).
    $cfg['forumRoot'] = (int) $forumRoot->id;

    // Convert empty/missing toggles to 0/1 consistently.
    foreach(['allowGuestsRead','allowGuestsPost','allowGuestsRegisterLink','allowGuestsSearch','allowGuestsProfile','enableSubscriptions','enableReactions','enableAvatars','enableSignatures','enableFrontendWysiwyg','enableBBCode'] as $k) {
      $cfg[$k] = (int) (!empty($cfg[$k]));
    }

    // Save only if something changed.
    if($cfg != $stored) {
      $this->modules->saveConfig($this, $cfg);
    }

    // Forum roles (member = can post, admin = moderator). Safe if they already exist.
    try {
      $roles = $this->wire('roles');
      foreach(['forum-member', 'forum-admin'] as $rn) {
        if(!$roles->get($rn)->id) { $r = $roles->add($rn); $r->addPermission('page-view'); $r->save(); }
      }
    } catch(\Throwable $e) {
      $this->wire('log')->save('native-forum', 'roles: ' . $e->getMessage());
    }

    // Members list page (/forum-members/) – also on a fresh install
    try { $this->ensureMembersTemplateAndPage(); } catch(\Throwable $e) { $this->wire('log')->save('native-forum', 'members page: ' . $e->getMessage()); }

    $this->fixKnownFields();

    // Copy bundled frontend templates/CSS into /site/templates (overwrites only NativeForum-owned files)
    $this->installTemplateFiles();
  }

  /************************************************************
   * DEMO CONTENT (optional, offered right after install)
   ************************************************************/

  /** Demo content currently on the site: ['pages' => ids, 'users' => ids] or [] */
  public function demoContentInfo(): array {
    $d = $this->get('demoContent');
    if(is_string($d)) $d = json_decode($d, true);
    return is_array($d) && (!empty($d['pages']) || !empty($d['users'])) ? $d : [];
  }

  /** Has the admin already chosen (demo added / start empty)? */
  public function demoChoiceMade(): bool {
    return (string) $this->get('demoChoice') !== '' || (bool) $this->demoContentInfo();
  }

  protected function saveDemoState(array $info, string $choice): void {
    $modules = $this->wire('modules');
    $cfg = $modules->getConfig($this);
    if(!is_array($cfg)) $cfg = [];
    $cfg['demoContent'] = $info ? json_encode($info) : '';
    $cfg['demoChoice'] = $choice;
    $modules->saveConfig($this, $cfg);
    $this->set('demoContent', $cfg['demoContent']);
    $this->set('demoChoice', $choice);
  }

  /** Admin chose to start with an empty forum. */
  public function skipDemoContent(): void {
    $this->saveDemoState($this->demoContentInfo(), 'empty');
  }

  /**
   * A small, realistic sample forum: 1 group, 3 categories, 4 topics (incl. pinned forum rules),
   * a handful of replies and 2 demo members. Everything is remembered so it can be removed again.
   */
  public function addDemoContent(): array {
    if($this->demoContentInfo()) return $this->demoContentInfo();
    $pages = $this->wire('pages');
    $users = $this->wire('users');
    $san = $this->wire('sanitizer');
    $root = $this->forumRoot();
    if(!$root || !$root->id) throw new WireException('Forum root page not found.');
    $this->sfBulk = true;
    $made = ['pages' => [], 'users' => []];
    try {
      // authors: the current superuser + two demo members
      $admin = $this->wire('user');
      if(!$admin->isSuperuser()) $admin = $users->get('roles=superuser, sort=id');
      $member = $this->wire('roles')->get('forum-member');
      $people = [];
      foreach(['maya' => 'Maya', 'alex' => 'Alex'] as $slug => $nick) {
        $name = 'demo-' . $slug;
        $u = $users->get("name=$name");
        if(!$u || !$u->id) {
          $u = new User();
          $u->name = $name;
          $u->pass = bin2hex(random_bytes(16)) . 'Aa1!';
          if($member && $member->id) $u->addRole($member);
          if($u->template->hasField('forum_nickname')) $u->forum_nickname = $nick;
          if($u->template->hasField('forum_signature')) $u->forum_signature = $slug === 'maya' ? 'Coffee first, questions later.' : 'Have you tried turning it off and on again?';
          $users->save($u);
          $made['users'][] = (int) $u->id;
        }
        $people[$slug] = $u;
      }
      $people['admin'] = $admin;

      $now = time();
      $at = function(float $daysAgo) use ($now) { return (int) ($now - $daysAgo * 86400); };
      $save = function(Page $p, int $created, User $who) use ($pages, &$made) {
        $pages->save($p);
        $p->created = $created; $p->modified = $created; $p->created_users_id = (int) $who->id;
        $pages->save($p, ['quiet' => true]);
        $made['pages'][] = (int) $p->id;
        return $p;
      };
      $page = function(string $tpl, Page $parent, string $title, array $fields = []) use ($san) {
        $p = new Page();
        $p->template = $tpl; $p->parent = $parent; $p->of(false);
        $p->title = $title;
        $base = $san->pageName($title, true) ?: 'item'; $name = $base; $n = 1;
        while($parent->child("name=$name, include=all")->id) $name = $base . '-' . (++$n);
        $p->name = $name;
        foreach($fields as $k => $v) if($p->template->hasField($k)) $p->set($k, $v);
        return $p;
      };
      $topic = function(Page $cat, string $title, User $by, float $daysAgo, string $body, array $extra = []) use ($page, $save, $at) {
        $p = $page('forum-topic', $cat, $title, array_merge(['forum_body' => $this->sanitizePostBody($body), 'forum_author' => $by, 'forum_vote_score' => 0], $extra));
        return $save($p, $at($daysAgo), $by);
      };
      $reply = function(Page $t, User $by, float $daysAgo, string $body, ?Page $to = null) use ($save, $at) {
        $r = new Page();
        $r->template = 'forum-reply'; $r->parent = $t; $r->of(false);
        $r->title = 'Re: ' . $t->title;
        $r->name = date('YmdHis', $at($daysAgo)) . '-' . substr(md5(uniqid('', true)), 0, 10);
        $r->set('forum_body', $this->sanitizePostBody($body));
        $r->set('forum_author', $by);
        if($r->template->hasField('forum_vote_score')) $r->set('forum_vote_score', 0);
        if($to && $r->template->hasField('forum_reply_to')) $r->set('forum_reply_to', $to);
        return $save($r, $at($daysAgo), $by);
      };
      $quote = function(string $who, string $text) { return "<blockquote><p><strong>{$who} wrote:</strong></p><p>{$text}</p></blockquote>"; };

      // structure
      $group = $save($page('forum-group', $root, 'Community'), $at(6), $admin);
      $catNews = $save($page('forum-category', $group, 'Announcements', ['field_forum_summary_category' => 'News from the team and the house rules.']), $at(6), $admin);
      $catTalk = $save($page('forum-category', $group, 'General discussion', ['field_forum_summary_category' => 'Anything goes (well, almost – see the rules).']), $at(6), $admin);
      $catHelp = $save($page('forum-category', $group, 'Help & support', ['field_forum_summary_category' => 'Stuck? Ask here – someone usually knows.']), $at(6), $admin);

      // 1) the rules: pinned + locked
      $topic($catNews, 'Forum rules (the short version)', $admin, 5.9,
        "<p>Welcome! These rules keep this place friendly, useful and spam-free. They are short, we promise.</p>"
        . "<ol>"
        . "<li><strong>Be kind.</strong> Disagree with ideas, not with people. No insults, harassment or hate – ever.</li>"
        . "<li><strong>Stay on topic.</strong> Post in the category that fits and use a title that says what the topic is about. “Help!!!” is not a title, it is a mood.</li>"
        . "<li><strong>Search first.</strong> Your question may already have a great answer waiting for you.</li>"
        . "<li><strong>No spam, no ads.</strong> Self-promotion only where it is clearly welcome. Our spam filter has no sense of humour.</li>"
        . "<li><strong>Keep it legal and safe.</strong> No pirated stuff, no personal data of others, nothing NSFW.</li>"
        . "<li><strong>Quote with care.</strong> Quote the part you reply to, not the whole novel.</li>"
        . "<li><strong>Help others.</strong> Solved your problem? Post how – future you (and everyone else) will thank you.</li>"
        . "<li><strong>No SHOUTING.</strong> Caps lock is cruise control for cool, but only on the road.</li>"
        . "</ol>"
        . "<p>See something that breaks the rules? Use <em>Report</em> in the post menu and the moderators will take a look. Moderators may edit, move or remove posts and, if needed, lock accounts.</p>"
        . "<p>Have fun and enjoy the forum! 🎉</p>",
        ['field_forum_summary_topic' => 'Please read before posting – it takes one minute.', 'forum_is_pinned' => 1, 'forum_is_locked' => 1, 'forum_tags' => '#rules', 'forum_views' => 42]);

      // 2) welcome
      $w = $topic($catNews, 'Welcome to the forum! 👋', $admin, 5.8,
        "<p>Hi everyone and welcome! This is the place to ask questions, share what you are working on and meet people with the same interests.</p>"
        . "<p>A few tips to get started:</p>"
        . "<ul><li>Fill in your profile (nickname, avatar, signature) under <em>My profile</em>.</li>"
        . "<li>Follow a topic to get an e-mail when someone replies.</li>"
        . "<li>Say hi in <em>General discussion → Introduce yourself</em>.</li></ul>",
        ['field_forum_summary_topic' => 'Start here.', 'forum_tags' => '#welcome', 'forum_views' => 27]);
      $reply($w, $people['maya'], 5.5, "<p>Thanks for setting this up! Looks great already. ☕</p>");
      $reply($w, $people['alex'], 5.2, "<p>Happy to be here. Dark mode is very easy on the eyes. 😎</p>");

      // 3) introductions
      $i = $topic($catTalk, 'Introduce yourself', $people['maya'], 4.6,
        "<p>New here? Tell us a bit about yourself – where you are from, what you do and what brought you here.</p>"
        . "<p>I'll start: I'm Maya, I design websites, drink too much coffee and I'm here to learn and share tips.</p>",
        ['forum_tags' => '#introductions', 'forum_views' => 19]);
      $r1 = $reply($i, $people['alex'], 4.1, "<p>Hey Maya! I'm Alex, a developer who breaks things for a living (and then fixes them). Mostly here for the help section.</p>");
      $reply($i, $admin, 3.7, $quote('Alex', 'a developer who breaks things for a living') . "<p>Then you will feel right at home. Welcome both of you!</p>", $r1);

      // 4) formatting help
      $h = $topic($catHelp, 'How do I format posts, quote and add pictures?', $people['alex'], 2.4,
        "<p>Quick question: how do I make text bold, add a list or put a picture in my post? And how does quoting work?</p>",
        ['forum_tags' => '#help #howto', 'forum_views' => 12]);
      $a = $reply($h, $admin, 2.1,
        "<p>Good question! The editor toolbar has everything:</p>"
        . "<ul><li><strong>Bold</strong>, <em>italic</em> and <u>underline</u> – select text and click the button.</li>"
        . "<li>Lists and links – also in the toolbar.</li>"
        . "<li>Pictures and files – drag them onto the editor (or click <em>choose images</em>), then click <em>Insert</em>. You can resize a picture by dragging its corner.</li>"
        . "<li>Quoting – click the quote button on any post, the quote lands in your reply.</li></ul>"
        . "<p>Code works too:</p><pre><code>echo 'Hello forum!';</code></pre>");
      $reply($h, $people['alex'], 1.8, $quote('Admin', 'You can resize a picture by dragging its corner.') . "<p>That's neat, thanks! Problem solved. ✅</p>", $a);
    } finally {
      $this->sfBulk = false;
    }
    // post counters + ranks
    foreach($people as $u) { try { $this->getUserPostCount($u, true); } catch(\Throwable $e) {} }
    $this->saveDemoState($made, 'demo');
    try { $this->wire('cache')->deleteFor('NativeForumView'); } catch(\Throwable $e) {}
    $this->clearProCacheAll();
    return $made;
  }

  /** Remove the demo content again (only the pages/members it created). */
  public function removeDemoContent(): int {
    $info = $this->demoContentInfo();
    $pages = $this->wire('pages');
    $n = 0;
    $this->sfBulk = true;
    try {
      foreach(array_reverse((array) ($info['pages'] ?? [])) as $id) {
        $p = $pages->get('id=' . (int) $id . ', include=all');
        if(!$p->id) continue;
        try { $pages->delete($p, true); $n++; } catch(\Throwable $e) {}
      }
      foreach((array) ($info['users'] ?? []) as $id) {
        $u = $this->wire('users')->get((int) $id);
        if($u && $u->id && strpos((string) $u->name, 'demo-') === 0) { try { $this->wire('users')->delete($u); } catch(\Throwable $e) {} }
      }
    } finally { $this->sfBulk = false; }
    try { $this->getUserPostCount($this->wire('user'), true); } catch(\Throwable $e) {}
    $this->saveDemoState([], 'removed');
    try { $this->wire('cache')->deleteFor('NativeForumView'); } catch(\Throwable $e) {}
    $this->clearProCacheAll();
    return $n;
  }

  /** Templates, fields and roles that belong to Native Forum (used by uninstall and export). */
  public function forumTemplateNames(): array {
    $out = [];
    foreach($this->wire('templates') as $t) if(strpos((string) $t->name, 'forum-') === 0) $out[] = (string) $t->name;
    return $out;
  }
  public function forumFieldNames(): array {
    $out = [];
    foreach($this->wire('fields') as $f) {
      $n = (string) $f->name;
      if(strpos($n, 'forum_') === 0 || strpos($n, 'field_forum_') === 0) $out[] = $n;
    }
    return $out;
  }

  /**
   * Uninstall removes EVERYTHING Native Forum created: all forum pages (groups, categories,
   * topics, replies, private messages, system pages) with their uploaded files, all forum
   * templates and fields (also the forum fields on the user template), the forum roles,
   * the reports table, caches, logs, temp uploads and the forum template files it copied
   * to /site/templates. Settings are removed by ProcessWire with the module.
   * Site users themselves are kept. Use Export first if you want to keep the data.
   */
  public function ___uninstall() {
    if(!$this->wire('user')->isSuperuser()) throw new WireException('Only superusers may uninstall Native Forum.');
    @set_time_limit(0);
    $templates = $this->wire('templates');
    $fields    = $this->wire('fields');
    $pages     = $this->wire('pages');
    $config    = $this->wire('config');
    $log = function($m) { try { $this->wire('log')->save('native-forum-uninstall', $m); } catch(\Throwable $e) {} };

    $tplNames = $this->forumTemplateNames();
    $fieldNames = $this->forumFieldNames();

    // 1) every page that uses a forum template (incl. trash), with children and files
    $deleted = 0;
    if($tplNames) {
      $ids = $pages->findIDs('template=' . implode('|', $tplNames) . ', include=all');
      // shallow pages first: a recursive delete also removes their descendants
      $depth = [];
      foreach($ids as $id) { $pg = $pages->get((int) $id); if($pg->id) $depth[(int) $id] = count($pg->parents); }
      asort($depth);
      foreach(array_keys($depth) as $id) {
        $pg = $pages->get("id=$id, include=all");
        if(!$pg->id) continue;
        try { $pages->delete($pg, true); $deleted++; } catch(\Throwable $e) { $log("page $id: " . $e->getMessage()); }
      }
    }

    // 2) forum fields out of every fieldgroup (user template etc.), then delete the fields
    foreach($this->wire('fieldgroups') as $fg) {
      $changed = false;
      foreach($fieldNames as $fname) {
        $f = $fields->get($fname);
        if($f && $f->id && $fg->hasField($f)) { $fg->remove($f); $changed = true; }
      }
      if($changed) { try { $fg->save(); } catch(\Throwable $e) { $log("fieldgroup {$fg->name}: " . $e->getMessage()); } }
    }

    // 3) templates + their fieldgroups
    foreach($tplNames as $name) {
      $tpl = $templates->get($name);
      if(!$tpl || !$tpl->id) continue;
      $fg = $tpl->fieldgroup;
      try { $templates->delete($tpl); } catch(\Throwable $e) { $log("template $name: " . $e->getMessage()); continue; }
      if($fg && $fg->id && $fg->name === $name) { try { $this->wire('fieldgroups')->delete($fg); } catch(\Throwable $e) {} }
    }

    // 4) fields
    foreach($fieldNames as $fname) {
      $f = $fields->get($fname);
      if(!$f || !$f->id) continue;
      try { $fields->delete($f); } catch(\Throwable $e) { $log("field $fname: " . $e->getMessage()); }
    }

    // 5) forum roles (users keep their other roles)
    foreach(['forum-member', 'forum-admin'] as $rname) {
      $r = $this->wire('roles')->get($rname);
      if($r && $r->id) { try { $this->wire('roles')->delete($r); } catch(\Throwable $e) {} }
    }

    // 6) database table, caches
    $db = $this->wire('database');
    foreach(['nativeforum_reports', 'simple' . 'forum_reports'] as $tbl) { try { $db->exec("DROP TABLE IF EXISTS `$tbl`"); } catch(\Throwable $e) {} }
    try { $this->wire('cache')->deleteFor('NativeForum'); $this->wire('cache')->deleteFor('NativeForumView'); } catch(\Throwable $e) {}
    try { $db->exec("DELETE FROM caches WHERE name LIKE 'NativeForum%'"); } catch(\Throwable $e) {}
    try { $db->exec("DELETE FROM pages_meta WHERE name='nf_legacy_pass'"); } catch(\Throwable $e) {}

    // 7) files: forum template files copied to /site/templates, temp uploads, logs
    $tdir = rtrim((string) $config->paths->templates, '/\\') . '/';
    $own = [];
    foreach(glob(__DIR__ . '/template-files/*') ?: [] as $src) $own[] = basename($src);
    foreach($own as $b) { if(is_file($tdir . $b)) @unlink($tdir . $b); }
    $files = $this->wire('files');
    foreach([$config->paths->assets . 'cache/NativeForum/', $config->paths->assets . 'NativeForum/', $config->paths->assets . 'native-forum/'] as $d) {
      if(is_dir($d)) { try { $files->rmdir($d, true); } catch(\Throwable $e) {} }
    }
    try { $up = $this->tempUploadBasePath(); if($up && is_dir($up)) $files->rmdir($up, true); } catch(\Throwable $e) {}
    $logs = array_merge(glob($config->paths->logs . 'native-forum*.txt') ?: [], glob($config->paths->logs . 'simple-forum*.txt') ?: [], glob($config->paths->logs . 'simpleforum*.txt') ?: []);
    foreach($logs as $lf) { if(basename($lf) !== 'native-forum-uninstall.txt') @unlink($lf); }

    $this->message("Native Forum removed: $deleted forum page trees, " . count($tplNames) . ' templates, ' . count($fieldNames) . ' fields, roles, reports, caches and template files.');
  }

  /************************************************************
   * HELPERS
   ************************************************************/
  protected function createField(string $name, string $type, string $label, array $extra = []): Field {
    $fields  = $this->wire('fields');
    $modules = $this->wire('modules');

    $f = $fields->get($name);
    $neededType = $modules->get($type);

    if(!$f || !$f->id) {
      $f = new Field();
      $f->name  = $name;
      $f->label = $label;
      $f->type  = $neededType;
    } else {
      // Ensure correct fieldtype for existing fields
      if(!$f->type || get_class($f->type) !== get_class($neededType)) {
        $f->type = $neededType;
      }
    }

    foreach($extra as $k => $v) {
      $f->set($k, $v);
    }

    // Ensure tag grouping (one "NativeForum-Fields" tag, no duplicates, no leftover tag of the former module name)
    $f->tags = $this->forumFieldTags((string) $f->tags);

    $fields->save($f);
    return $f;
  }

  /**
   * Explicitly ensure correct fieldtypes for key forum fields.
   * Safety net for installs where fields existed without a type.
   */
  protected function fixKnownFields() {
    $fields    = $this->wire('fields');
    $modules   = $this->wire('modules');
    $templates = $this->wire('templates');
    $config    = $this->wire('config');

    $userTplIds = array_values(array_filter(array_map('intval', (array) $config->userTemplateIDs)));
    if(!count($userTplIds)) { $ut = $templates->get('user'); if($ut) $userTplIds = [(int) $ut->id]; }
    $tid = function($name) use ($templates) { $t = $templates->get($name); return $t ? (int) $t->id : 0; };
    $roleTplId = $tid('role');

    // name => [single?, template ids, parentId]
    // NOTE: user pages live in the admin tree and are not "findable" for guests, so a
    // findPagesSelector like "template=user" makes FieldtypePage reject every value
    // (author/likes/votes/PM users were silently never saved). Use template_id instead.
    $map = [
      'forum_author'          => [true,  $userTplIds, 0],
      'forum_from_user'       => [true,  $userTplIds, 0],
      'forum_to_user'         => [true,  $userTplIds, 0],
      'forum_like_users'      => [false, $userTplIds, 0],
      'forum_vote_users'      => [false, $userTplIds, 0],
      'forum_reply_to'        => [true,  [$tid('forum-reply')], 0],
      'forum_followed_topics' => [false, [$tid('forum-topic')], 0],
      'forum_read_roles'      => [false, [$roleTplId], (int) $config->rolesPageID],
      'forum_write_roles'     => [false, [$roleTplId], (int) $config->rolesPageID],
    ];
    $pageType = $modules->get('FieldtypePage');
    // the autocomplete input is a core module that is not installed by default
    try { if(!$modules->isInstalled('InputfieldPageAutocomplete')) $modules->install('InputfieldPageAutocomplete'); } catch(\Throwable $e) {}
    foreach($map as $name => $def) {
      $f = $fields->get($name);
      if(!$f || !$f->id || !$pageType) continue;
      list($single, $tplIds, $parentId) = $def;
      $tplIds = array_values(array_filter($tplIds));
      $f->type = $pageType;
      $f->derefAsPage = $single ? FieldtypePage::derefAsPageOrNullPage : FieldtypePage::derefAsPageArray;
      $f->findPagesSelector = '';
      $f->findPagesSelect = '';
      $f->template_id = count($tplIds) === 1 ? $tplIds[0] : 0;
      $f->template_ids = count($tplIds) > 1 ? $tplIds : [];
      $f->parent_id = $parentId;
      $isTitle = in_array($name, ['forum_reply_to','forum_followed_topics'], true);
      $isRole = in_array($name, ['forum_read_roles','forum_write_roles'], true);
      // FieldtypePage reads "inputfield" (not "inputfieldClass"): without it every field fell back to a
      // single <select>, so multi-value fields (followed topics, likes…) showed "needs setup" and kept 1 page.
      // Users and topics can be thousands of pages: autocomplete; roles: a small sortable list.
      $f->inputfield = $isRole ? 'InputfieldAsmSelect' : 'InputfieldPageAutocomplete';
      $f->labelFieldName = $isTitle ? 'title' : 'name';
      $f->searchFields = $isTitle ? 'title' : 'name';
      $f->operator = '%=';
      $f->allowUnpub = 0;
      $f->addable = 0;
      $f->remove('inputfieldClass');
      $fields->save($f);
    }
  }

  /**
   * Ensure forum_read_topics field exists and is added to the user template.
   */
  protected function ensureReadTopicsField(): void {
    $fields = $this->wire('fields');
    $templates = $this->wire('templates');

    // Create field if missing
    $f_read_topics = $this->createField('forum_read_topics', 'FieldtypeTextarea', 'Forum read topics map', [
      'inputfieldClass' => 'InputfieldTextarea',
      'textformatters'  => [],
      'rows'            => 2,
    ]);

    // Add to user template
    $tpl_user = $templates->get('user');
    if($tpl_user && $tpl_user->id) {
      $fg = $tpl_user->fieldgroup;
      if(!$fg->get('forum_read_topics')) {
        $fg->add($f_read_topics);
        $fg->save();
        $tpl_user->fieldgroup = $fg;
        $templates->save($tpl_user);
      }
    }
  }

  /**
   * Ensure optional user profile field exists even on sites that upgraded without re-running installer.
   * Adds a simple text field "forum_country" to the user template fieldgroup.
   */
  protected function ensureUserCountryField(): void {
    try {
      $fields = $this->wire('fields');
      $templates = $this->wire('templates');
      if(!$fields || !$templates) return;

      $f = $fields->get('forum_country');
      if(!$f || !$f->id) {
        if(method_exists($this, 'createField')) {
          $f = $this->createField('forum_country', 'FieldtypeText', 'Forum country');
        } else {
          $f = new \ProcessWire\Field();
          $f->name = 'forum_country';
          $f->type = $this->wire('modules')->get('FieldtypeText');
          $f->label = 'Forum country';
          $f->save();
        }
      }

      $ut = $templates->get('user');
      if($ut && $ut->id) {
        $fg = $ut->fieldgroup;
        if($fg && !$fg->hasField('forum_country')) {
          $fg->add($f);
          $fg->save();
          $ut->save();
        }
      }
    } catch(\Throwable $e) {
      // ignore
    }
  }

protected function createTemplate(string $name, array $fieldNames): Template {
    $templates = $this->wire('templates');
    $fields    = $this->wire('fields');

    $tpl = $templates->get($name);
    if($tpl && $tpl->id) {
      // Ensure fieldgroup has all requested fields
      $fg = $tpl->fieldgroup;
      foreach($fieldNames as $fname) {
        $field = $fields->get($fname);
        if($field && $field->id && !$fg->hasField($field)) {
          $fg->add($field);
        }
      }
      $fg->save();
      $tpl->fieldgroup = $fg;

      // Ensure a real template file is used (prevents "Basic page content" output)
      // Some API-created templates can end up as "no file" templates.
      if((int) $tpl->noFile === 1) $tpl->noFile = 0;
      $fn = (string) $tpl->filename;
      if($fn === '') $tpl->filename = $name . '.php';

      // Ensure tag
      $tags = trim((string) $tpl->tags);
      $tag  = 'NativeForum-Templates';
      if($tags === '') {
        $tpl->tags = $tag;
      } elseif(strpos($tags, $tag) === false) {
        $tpl->tags = $tags . ' ' . $tag;
      }

      $templates->save($tpl);
      return $tpl;
    }

    // New template
    $fg = new Fieldgroup();
    $fg->name = $name;
    foreach($fieldNames as $fname) {
      $field = $fields->get($fname);
      if($field && $field->id) $fg->add($field);
    }
    $fg->save();

    $tpl = new Template();
    $tpl->name       = $name;
    $tpl->fieldgroup = $fg;

    // Use real template file
    $tpl->noFile   = 0;
    $tpl->filename = $name . '.php';

    $tpl->tags       = 'NativeForum-Templates';
    $templates->save($tpl);

    return $tpl;
  }

  /**
   * Copy bundled frontend template files + CSS into /site/templates (only if missing).
   * This makes installs truly "zero manual files".
   */
/**
 * Copy bundled frontend template files + CSS into /site/templates/.
 * Overwrites only files that look like they belong to NativeForum (or are broken placeholders).
 * If you want to keep a customized template, add: // NativeForum Custom  (anywhere in the file)
 */
protected function installTemplateFiles(): void {
  $config = $this->wire('config');

  $srcDir = __DIR__ . '/template-files/';
  $dstDir = rtrim($config->paths->templates, '/\\') . DIRECTORY_SEPARATOR;

  if(!is_dir($srcDir) || !is_dir($dstDir)) return;

  foreach(glob($srcDir . '*') as $src) {
    if(!is_file($src)) continue;

    $base = basename($src);

    // Only copy known frontend assets from the bundle
    if(!preg_match('/\.(php|css)$/i', $base)) continue;
    if(strpos($base, 'sf-') === 0) continue; // internal module files (e.g. sf-site-stub.php)

    $dst = $dstDir . $base;

    // Decide whether we should overwrite an existing file
    $overwrite = true;
    if(is_file($dst)) {
      $existing = @file_get_contents($dst);
      $existing = is_string($existing) ? $existing : '';

      // Keep site customizations
      if(strpos($existing, 'NativeForum Custom') !== false || strpos($existing, 'Simple' . 'Forum Custom') !== false) {
        $overwrite = false;
      } else {
        // Special case: forum-layout.php must stay in sync because it contains shared helper functions.
        // If the site copy is missing required helpers, force overwrite.
        if($base === 'forum-layout.php') {
          $missingHelper = (strpos($existing, 'function sf_sanitize_css_color') === false);
          if($missingHelper) {
            // Force overwrite and skip the "looks like" heuristics.
            $overwrite = true;
            @copy($src, $dst);
            continue;
          }
        }

        // Overwrite only if it's clearly a NativeForum template OR a known broken placeholder
        $looksLikeForum =
          (strpos($existing, 'NativeForum Template') !== false) ||
          (strpos($existing, 'native-forum') !== false) ||
          (strpos($existing, 'NativeForum') !== false) ||
          (strpos($existing, 'Simple' . 'Forum') !== false);

        $looksBroken =
          (strpos($existing, 'Basic page content') !== false) ||
          (strpos($existing, 'Basic content page') !== false) ||
          (strpos($existing, "syntax error") !== false) ||
          (strpos($existing, "' />\";") !== false);

        $overwrite = ($looksLikeForum || $looksBroken);
      }
    }

    if($overwrite) {
      @copy($src, $dst);
    }
  }
}

  /************************************************************
   * FORUM API METHODS
   ************************************************************/
  
  /**
   * Sanitize rich post body HTML according to module settings (images/embeds/code).
   * Always returns a safe string.
   */

  /**
   * Some editor/host setups end up double-encoding HTML (e.g. &lt;p&gt;...)
   * which then renders as visible "</p><p>" text in posts.
   * Decode only when it looks like encoded HTML tags.
   */
  protected function sfMaybeDecodeHtmlEntities(string $html): string {
    $html = (string) $html;
    if($html === '') return $html;

    // Fast check
    if(stripos($html, '&lt;') === false && stripos($html, '&quot;') === false) return $html;

    // Only decode if it looks like encoded *tags* (not just user-typed entities)
    if(!preg_match('~&lt;/?(p|div|br|span|a|img|ul|ol|li|blockquote|h[2-6]|table|thead|tbody|tr|td|th|pre|code)\b~i', $html)) {
      return $html;
    }

    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Confirm we actually introduced tags
    if(preg_match('~</?(p|div|a|img|ul|ol|li|blockquote|h[2-6]|table|pre|code)\b~i', $decoded)) {
      return $decoded;
    }

    return $html;
  }


  /**
   * Wrap sized forum embed images in a width-constrained box so their chosen width
   * is preserved even when the site/theme forces img{width:100%}.
   *
   * We only touch images with class "sf-embed-img" that have an explicit width
   * (width attribute or inline style width).
   */
  protected function sfWrapSizedEmbedImages(string $html): string {
    $html = (string) $html;
    if($html === '' || stripos($html, 'sf-embed-img') === false) return $html;

    // We avoid wrapper spans here because they can break CKEditor drag-resize after saving.
    // Instead we copy the chosen width onto the <img> itself (inline style) so it survives
    // theme CSS like img{width:100%} and also remains editable on subsequent edits.
    $doc = new \DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $wrapperId = 'sf_wrap_' . mt_rand(1000, 9999);
    $ok = $doc->loadHTML('<?xml encoding="UTF-8"><div id="' . $wrapperId . '">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    if(!$ok) return $html;

    $xp = new \DOMXPath($doc);
    $imgs = $xp->query('//*[@id="' . $wrapperId . '"]//img[contains(concat(" ", normalize-space(@class), " "), " sf-embed-img ")]');
    if(!$imgs || $imgs->length < 1) return $html;

    foreach($imgs as $im) {
      if(!($im instanceof \DOMElement)) continue;

      // Determine width (prefer any inline width that came from editor)
      $w = '';

      // 1) img width attribute
      $wAttr = trim((string) $im->getAttribute('width'));
      if($wAttr !== '' && preg_match('/^\d+$/', $wAttr)) {
        $w = (int)$wAttr . 'px';
      }

      // 2) img style width:..%
      $style = (string) $im->getAttribute('style');
      if($w === '' && $style && preg_match('/\bwidth\s*:\s*([0-9]{1,3}%|[0-9]{1,4}px)\b/i', $style, $mm)) {
        $w = $mm[1];
      }

      // 3) CKEditor image2 wrapper stores width on parent <figure> or <span>
      if($w === '') {
        $p = $im->parentNode;
        $steps = 0;
        while($p && $steps < 2) {
          if($p instanceof \DOMElement) {
            $pStyle = (string) $p->getAttribute('style');
            if($pStyle && preg_match('/\bwidth\s*:\s*([0-9]{1,3}%|[0-9]{1,4}px)\b/i', $pStyle, $mm2)) {
              $w = $mm2[1];
              break;
            }
          }
          $p = $p->parentNode;
          $steps++;
        }
      }

      if($w === '') continue;

      // Normalize: remove width/height attributes so the editor can continue resizing
      $im->removeAttribute('width');
      $im->removeAttribute('height');

      // Apply width on the <img> itself.
      $style = (string) $im->getAttribute('style');
      // Remove existing width declarations
      $style = preg_replace('/\bwidth\s*:\s*[^;]+;?/i', '', $style);
      $style = trim($style);
      if($style !== '' && substr($style, -1) !== ';') $style .= ';';

      // Add width + max-width + auto height; use !important to survive theme CSS.
      $style .= 'width:' . htmlspecialchars($w, ENT_QUOTES, 'UTF-8') . ' !important;max-width:100%;height:auto;';
      $im->setAttribute('style', $style);
    }

    // Output inner HTML
    $container = $doc->getElementById($wrapperId);
    if(!$container) return $html;

    $out = '';
    foreach($container->childNodes as $child) {
      $out .= $doc->saveHTML($child);
    }
    return $out;
  }

  /**
   * Parse user-entered tags into normalized slugs.
   *
   * IMPORTANT: this is intentionally kept inside the module to avoid relying on
   * any older site-level helper functions that might parse tags differently.
   *
   * Rule:
   * - Each '#' starts a new tag.
   * - Spaces are allowed inside a tag until the next '#'.
   * - Without '#', we treat input as comma-separated tags.
   *
   * Examples:
   *   "#my tag #my another tag" => ["my-tag", "my-another-tag"]
   *   "tag one, tag two" => ["tag-one", "tag-two"]
   */
  public function parseTagsString(string $raw, int $maxTags = 12): array {
    $raw = trim((string) $raw);
    if($raw === '') return [];
    $raw = preg_replace('/[\r\n\t]+/u', ' ', $raw);

    $tags = [];
    if(strpos($raw, '#') !== false) {
      if(preg_match_all('/#([^#]+)/u', $raw, $m)) {
        foreach($m[1] as $chunk) {
          $t = trim((string) $chunk);
          if($t !== '') $tags[] = $t;
        }
      }
    } else {
      foreach(preg_split('/\s*,\s*/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
        $t = trim((string) $chunk);
        if($t !== '') $tags[] = $t;
      }
    }

    $out = [];
    foreach($tags as $t) {
      $t = preg_replace('/\s+/u', ' ', trim((string)$t));
      $t = ltrim($t, "#@ \t");
      $t = preg_replace('/[^\p{L}\p{N}\s\-_]+/u', '', $t);
      $t = preg_replace('/\s+/u', ' ', trim($t));
      if($t === '') continue;

      $slug = mb_strtolower($t, 'UTF-8');
      $slug = preg_replace('/\s+/u', '-', $slug);
      $slug = preg_replace('/-+/u', '-', $slug);
      $slug = trim($slug, '-_');

      if($slug === '' || mb_strlen($slug, 'UTF-8') > 60) continue;
      $out[$slug] = true;
      if(count($out) >= $maxTags) break;
    }

    return array_keys($out);
  }



  /** Same cleaning as the forum's own editor (used by the admin when editing posts). */
  public function cleanPostBody(string $body): string {
    return $this->sanitizePostBody($body);
  }

  /** Posts are stored as HTML (rich editor) or as plain text. */
  public function richBodies(): bool {
    return (int) $this->get('renderRichHtml') === 1;
  }

  protected function sanitizePostBody(string $body): string {
    $body = (string) $body;

    // Fix for double-encoded HTML coming from some editors/hosts
    $body = $this->sfMaybeDecodeHtmlEntities($body);

    // If rich HTML rendering is disabled, store as plain textarea text.
    if(!(int)$this->get('renderRichHtml')) {
      return (string) $this->sanitizer->textarea($body);
    }

    $allowImages = ((int)$this->get('allowRichImages') || ((int)$this->get('attachmentsEnabled') && (int)$this->get('attachmentsAllowImages'))) ? 1 : 0;

    // Important: do NOT wrap images in a sizing wrapper here.
    // Wrapping breaks CKEditor "drag corner to resize" after the post is saved
    // (users reported images jumping back to 100% width when editing again).
    // We preserve sizes via inline width attributes/styles instead.
    $allowEmbeds = (int)$this->get('allowRichEmbeds') ? 1 : 0;
    $allowCode   = (int)$this->get('allowRichCode') ? 1 : 0;

    // Some HTMLPurifier builds warn about iframe attributes like allow/allowfullscreen.
    // They are not required for basic YouTube playback and can be stripped safely.
    if($allowEmbeds && stripos($body, '<iframe') !== false) {
      // Strip attributes on iframes that commonly trigger purifier warnings.
      $body = preg_replace('/\s+allowfullscreen(\s*=\s*"[^"]*")?/i', '', $body);
      $body = preg_replace('/\s+allow\s*=\s*"[^"]*"/i', '', $body);
    }

    $allowed = [
      'p','br','div','span',
      'strong','b','em','i','u','s',
      'blockquote',
      'ul','ol','li',
      'a',
      'hr',
      'h2','h3','h4','h5','h6',
      'table','thead','tbody','tr','th','td',
    ];

    if($allowCode) { $allowed[] = 'pre'; $allowed[] = 'code'; }
    if($allowImages) { $allowed[] = 'img'; }
    if($allowEmbeds) { $allowed[] = 'iframe'; }

    // Allow safe class attributes on common elements.
    // Needed for forum UI helpers (e.g. quote collapse uses .sf-quote / .sf-quote-body classes).
    $allowedAttr = [
      'a.href','a.title','a.target','a.rel','a.class',
      'div.style','div.class',
      'span.style','span.class',
      'p.style','p.class',
      'blockquote.class',
      'table.border','table.cellpadding','table.cellspacing',
      'th.colspan','td.colspan','th.rowspan','td.rowspan',
    ];

    if($allowImages) {
      // Allow limited img styling so we can persist user-chosen sizes even when the site theme forces width:100%.
      // (Only safe CSS properties are allowed below.)
      $allowedAttr = array_merge($allowedAttr, ['img.src','img.alt','img.title','img.width','img.height','img.class','img.style']);
    }
    if($allowEmbeds) {
      // Keep iframe attributes minimal to avoid HTMLPurifier warnings and keep posts clean.
      $allowedAttr = array_merge($allowedAttr, ['iframe.src','iframe.width','iframe.height','iframe.frameborder']);
    }

    $opts = [
      'HTML.AllowedElements' => implode(',', $allowed),
      'HTML.AllowedAttributes' => implode(',', $allowedAttr),
      'Attr.AllowedFrameTargets' => ['_blank','_self'],
      'HTML.Nofollow' => true,
      'Attr.EnableID' => false,
      // Keep CSS tight: only allow properties we intentionally use.
      'CSS.AllowedProperties' => ['text-align','width','height','max-width'],
      'HTML.SafeIframe' => $allowEmbeds ? true : false,
      // Allow common safe iframe sources (YouTube and Vimeo). Accept with or without "www".
      'URI.SafeIframeRegexp' => $allowEmbeds ? '#^(https?:)?//((www\.)?youtube\.com/embed/|(www\.)?youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)#' : '#$#',
    ];

    try {
      return (string) $this->sanitizer->purify($body, $opts);
    } catch(\Throwable $e) {
      return (string) $this->sanitizer->textarea(strip_tags($body));
    }
  }

  /************************************************************
   * ATTACHMENTS / UPLOADS
   ************************************************************/

  public function attachmentsEnabled(): bool {
    return (int)$this->get('attachmentsEnabled') === 1;
  }

  public function attachmentsAllowImages(): bool {
    return $this->attachmentsEnabled() && (int)$this->get('attachmentsAllowImages') === 1;
  }

  public function attachmentsAllowFiles(): bool {
    return $this->attachmentsEnabled() && (int)$this->get('attachmentsAllowFiles') === 1;
  }

  protected function tempUploadBasePath(): string {
    // use /site/assets/cache/ (safe + writable)
    $base = rtrim((string) $this->config->paths->assets, '/') . '/cache/NativeForumUpload/';
    if(!is_dir($base)) @mkdir($base, 0777, true);
    return $base;
  }

  protected function tempUploadDir(string $ctx): string {
    $ctx = $this->sanitizer->name($ctx);
    $dir = $this->tempUploadBasePath() . $ctx . '/';
    if(!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
  }

  protected function tempUploadSessionKey(string $ctx): string {
    $ctx = $this->sanitizer->name($ctx);
    return 'sf_uploads_' . $ctx;
  }

  /*
   * Pending uploads are kept in WireCache (per user + form) instead of the PHP session, so the
   * upload request can release the session lock right away: several files upload in parallel
   * and never wait for other requests of the same visitor.
   */
  protected function tempUploadCacheKey(string $ctx): string {
    return 'upl_' . (int) $this->wire('user')->id . '_' . substr(md5($this->sanitizer->name($ctx)), 0, 16) . '_';
  }
  /** [key => meta] of pending uploads for this form (one cache row per file: safe for parallel uploads). */
  protected function getTempUploads(string $ctx): array {
    $pre = $this->tempUploadCacheKey($ctx);
    $out = [];
    try {
      $rows = $this->wire('cache')->getFor('NativeForum', $pre . '*');
      if(is_array($rows)) foreach($rows as $name => $val) {
        $meta = is_array($val) ? $val : json_decode((string) $val, true);
        $k = substr((string) $name, strrpos((string) $name, '_') + 1);
        if(is_array($meta) && $k !== '') $out[$k] = $meta;
      }
    } catch(\Throwable $e) {}
    if(!$out) {
      $old = $this->wire('session')->get($this->tempUploadSessionKey($ctx)); // uploads made before the update
      if(is_array($old)) $out = $old;
    }
    return $out;
  }
  protected function addTempUpload(string $ctx, string $key, array $meta): void {
    $this->wire('cache')->saveFor('NativeForum', $this->tempUploadCacheKey($ctx) . $key, json_encode($meta), 86400);
  }
  protected function removeTempUpload(string $ctx, string $key = ''): void {
    $this->wire('cache')->deleteFor('NativeForum', $this->tempUploadCacheKey($ctx) . ($key !== '' ? $key : '*'));
  }

  /**
   * AJAX temp upload handler. Stores uploaded file in /site/assets/cache/NativeForumUpload/<ctx>/
   * and remembers it in session until the user submits the post.
   *
   * Expects:
   * - POST sf_upload_attachment=1
   * - POST sf_upload_ctx=<token>
   * - POST sf_upload_kind=image|file|auto
   * - FILE sf_upload_file
   */
  public function handleTempUpload(User $u, $input): array {
    if(!$u || !$u->isLoggedin()) return ['ok' => false, 'error' => 'Login required.'];
    if(!$this->attachmentsEnabled()) return ['ok' => false, 'error' => 'Uploads are disabled.'];
    if(!$this->canPost($u)) return ['ok' => false, 'error' => 'Posting not allowed.'];

    $ctx  = (string) $input->post->sf_upload_ctx;
    $kind = (string) $input->post->sf_upload_kind; // image|file|auto
    if(!$ctx) return ['ok' => false, 'error' => 'Missing upload context.'];

    $kind = $kind ?: 'auto';
    if($kind === 'image' && !$this->attachmentsAllowImages()) return ['ok' => false, 'error' => 'Image uploads are disabled.'];
    if($kind === 'file' && !$this->attachmentsAllowFiles()) return ['ok' => false, 'error' => 'File uploads are disabled.'];

    // soft cleanup (older than 1 day)
    $this->cleanupTempUploads();

    $dest = $this->tempUploadDir($ctx);

    $ctxSafe = $this->sanitizer->name($ctx);
    $ctxUrlBase = rtrim((string)$this->config->urls->assets, '/') . '/cache/NativeForumUpload/' . $ctxSafe . '/';

    // ensure writable destination
    if(!is_dir($dest) || !is_writable($dest)) {
      return ['ok' => false, 'error' => 'Upload folder is not writable (site/assets/cache).'];
    }

    $maxMB = (int) ($this->get('attachmentsMaxUploadMB') ?: 20);
    $maxBytes = $maxMB * 1024 * 1024;

    $upload = new WireUpload('sf_upload_file');
    $upload->setDestinationPath($dest);
    $upload->setOverwrite(false);
    $upload->setMaxFiles(1);
    $upload->setMaxFileSize($maxBytes);

    $imgExt = ['jpg','jpeg','png','gif','webp'];
    $fileExt = preg_split('/\\s+/', trim((string)$this->get('attachmentsAllowedExt')), -1, PREG_SPLIT_NO_EMPTY);
    $fileExt = array_map('strtolower', $fileExt ?: []);
    $allExt = array_unique(array_merge($imgExt, $fileExt));

    // accept both; we'll classify after upload
    $upload->setValidExtensions($allExt);

    $names = $upload->execute();
    if(!is_array($names) || !count($names)) {
      // ProcessWire versions differ: WireUpload may not implement getError().
// We try multiple approaches to retrieve a useful error message.
$err = null;
if(method_exists($upload, 'getErrors')) {
  try {
    $errs = $upload->getErrors();
    if(is_array($errs) && count($errs)) $err = implode(' ', array_map('strval', $errs));
    else if(is_string($errs) && $errs) $err = $errs;
  } catch(\Throwable $e) {}
}
if(!$err && method_exists($upload, 'errors')) {
  try {
    $errs = $upload->errors();
    if(is_array($errs) && count($errs)) $err = implode(' ', array_map('strval', $errs));
    else if(is_string($errs) && $errs) $err = $errs;
  } catch(\Throwable $e) {}
}

// If WireUpload gave no details, fall back to PHP upload error codes.
      if(!$err) {
        $fe = $_FILES['sf_upload_file']['error'] ?? null;
        $map = [
          UPLOAD_ERR_INI_SIZE   => 'File is too large for server limits.',
          UPLOAD_ERR_FORM_SIZE  => 'File is too large.',
          UPLOAD_ERR_PARTIAL    => 'Upload was interrupted (partial upload).',
          UPLOAD_ERR_NO_FILE    => 'No file was received by the server.',
          UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary upload folder.',
          UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file.',
          UPLOAD_ERR_EXTENSION  => 'Upload was blocked by a server extension.',
        ];
        if(is_int($fe) && isset($map[$fe])) $err = $map[$fe];
      }

      $err = $err ?: 'Upload failed.';
      return ['ok' => false, 'error' => $err];
    }

    $saved = [];
    $keyed = $this->getTempUploads($ctx);

    $maxCount = (int) ($this->get('attachmentsMaxFiles') ?: 10);
    if(count($keyed) >= $maxCount) {
      // delete just-uploaded file
      foreach($names as $nm) { @unlink($dest . $nm); }
      return ['ok' => false, 'error' => "You can upload at most {$maxCount} files per post."];
    }

    foreach($names as $nm) {
      $path = $dest . $nm;
      $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
      $isImg = in_array($ext, $imgExt, true);

      $finalKind = $isImg ? 'image' : 'file';
      if($finalKind === 'image' && !$this->attachmentsAllowImages()) { @unlink($path); continue; }
      if($finalKind === 'file' && !$this->attachmentsAllowFiles()) { @unlink($path); continue; }

      // when caller forced kind=image/file, enforce it
      if($kind === 'image' && !$isImg) { @unlink($path); continue; }
      if($kind === 'file' && $isImg) { @unlink($path); continue; }

      $key = $this->randomName() . $this->randomName();
      $key = preg_replace('/[^a-z0-9]/i', '', $key);
      $keyed[$key] = [
        'path' => $path,
        'name' => $nm,
        'ext'  => $ext,
        'kind' => $finalKind,
        'time' => time(),
      ];

      if($finalKind === 'image') {
        $saved[] = [
          'key' => $key,
          'kind'=> 'image',
          'name'=> $nm,
          'insert' => "[sfimg:{$key}]",
          'tempUrl' => $ctxUrlBase . rawurlencode($nm)
        ];
      } else {
        $label = $this->sanitizer->entities($nm);
        $saved[] = [
          'key' => $key,
          'kind'=> 'file',
          'name'=> $nm,
          'insert' => "[sffile:{$key}|{$nm}]",
          'tempUrl' => $ctxUrlBase . rawurlencode($nm)
        ];
      }
    }

    foreach($keyed as $k => $meta) if(!empty($meta['time']) && empty($meta['_stored'])) { $meta['_stored'] = 1; $this->addTempUpload($ctx, (string) $k, $meta); }

    if(!count($saved)) {
      return ['ok' => false, 'error' => 'File type not allowed.'];
    }

    return ['ok' => true, 'files' => $saved];
  }

  public function deleteTempUpload(User $u, $input): array {
    if(!$u || !$u->isLoggedin()) return ['ok' => false, 'error' => 'Login required.'];
    $ctx = (string) $input->post->sf_upload_ctx;
    $key = (string) $input->post->sf_upload_key;
    if(!$ctx || !$key) return ['ok' => false, 'error' => 'Missing data.'];
    $arr = $this->getTempUploads($ctx);
    if(!isset($arr[$key])) return ['ok' => false];

    $path = (string) ($arr[$key]['path'] ?? '');
    if($path) @unlink($path);
    unset($arr[$key]);
    $this->removeTempUpload($ctx, $key);
    return ['ok' => true];
  }

  /**
   * Normalize editor-inserted attachment placeholders back into token strings.
   * This is a server-side safety net in case client-side JS conversion fails.
   */
  protected function normalizeEditorEmbedsToTokens(string $body): string {
    $body = (string) $body;
    if($body === '') return $body;

    // Strip accidental document wrapper fragments
    $body = str_replace(['</body></html>', '</BODY></HTML>'], '', $body);
    $body = str_replace(['&lt;/body&gt;&lt;/html&gt;', '&lt;/BODY&gt;&lt;/HTML&gt;'], '', $body);
    $body = preg_replace('~</body>\s*</html>~i', '', $body);
    $body = preg_replace('~&lt;/body&gt;\s*&lt;/html&gt;~i', '', $body);

    // If editor content is double-encoded (e.g. &lt;p&gt;), decode it first.
    $body = $this->sfMaybeDecodeHtmlEntities($body);
    if($body === '') return $body;

    // Try DOM-based normalization (handles valid HTML placeholders).
    try {
      $dom = new \DOMDocument('1.0', 'UTF-8');
      libxml_use_internal_errors(true);
      $dom->loadHTML('<?xml encoding="utf-8" ?>' . '<!doctype html><html><body>' . $body . '</body></html>');
      $xp = new \DOMXPath($dom);
      $bodyNode = $dom->getElementsByTagName('body')->item(0);
      if(!$bodyNode) return $body;

      // img placeholders: token stored in alt
      // Also persist the current editor size into the token as |w=...
      foreach($xp->query('//img[@alt]') as $img) {
        /** @var \DOMElement $img */
        $alt = (string) $img->getAttribute('alt');
        if(strpos($alt, '[sfimg:') !== 0) continue;

        $tok = $alt;
        $widthVal = '';
        $wRe = '/(?:^|;)\s*width\s*:\s*([^;]+)/i'; // never matches max-width/min-width

        // 1) size already carried by the token (set by the editor's resize handle)
        if(preg_match('/\|w=([^\]|]+)\]/i', $tok, $mt)) $widthVal = trim((string) $mt[1]);
        // 2) the image itself
        if(!$widthVal && preg_match($wRe, (string) $img->getAttribute('style'), $m3)) $widthVal = trim((string) $m3[1]);
        if(!$widthVal) {
          $wAttr = trim((string) $img->getAttribute('width'));
          if($wAttr !== '') $widthVal = $wAttr;
        }
        // 3) legacy wrapper (figure/span) or its parent
        $p = $img->parentNode;
        for($i = 0; !$widthVal && $i < 2 && $p instanceof \DOMElement; $i++, $p = $p->parentNode) {
          if(preg_match($wRe, (string) $p->getAttribute('style'), $m)) $widthVal = trim((string) $m[1]);
        }
        $widthVal = str_ireplace('!important', '', $widthVal);
        if(preg_match('/^(\d{1,3})%$/', trim($widthVal), $pm) && (int) $pm[1] >= 100) $widthVal = '';
        $tok = preg_replace('/\|w=[^\]|]+/i', '', $tok);

        // Normalize to NN (px) or NN%.
        if($widthVal) {
          $widthVal = preg_replace('/\s+/', '', $widthVal);
          // strip units we don't support
          if(preg_match('/^(\d{1,4})px$/i', $widthVal, $mm)) $widthVal = $mm[1];
          if(!preg_match('/^(\d{1,4}|\d{1,3}%)$/', $widthVal)) $widthVal = '';
        }

        if($widthVal) {
          if(preg_match('/\|w=[^\]|]+/i', $tok)) {
            $tok = preg_replace('/\|w=[^\]|]+/i', '|w=' . $widthVal, $tok);
          } else {
            $tok = preg_replace('/\]$/', '|w=' . $widthVal . ']', $tok);
          }
        }

        // Replace the whole wrapper if possible (prevents stray <img> markup)
        $replNode = $img;
        if($img->parentNode instanceof \DOMElement) {
          $tag = strtolower((string) $img->parentNode->tagName);
          if(($tag === 'figure' || $tag === 'span') && trim((string) $img->parentNode->textContent) === '' && $img->parentNode->getElementsByTagName('img')->length === 1) $replNode = $img->parentNode;
        }
        if($replNode->parentNode) $replNode->parentNode->replaceChild($dom->createTextNode($tok), $replNode);
      }

      // file placeholders: token stored in title OR href marker
      foreach($xp->query('//a[@title]') as $a) {
        $t = (string) $a->getAttribute('title');
        if(strpos($t, '[sffile:') === 0) {
          $a->parentNode->replaceChild($dom->createTextNode($t), $a);
        }
      }

      foreach($xp->query('//a[contains(@href, "#sfTok=")]') as $a) {
        $href = (string) $a->getAttribute('href');
        $pos = strpos($href, '#sfTok=');
        if($pos === false) continue;
        $enc = substr($href, $pos + strlen('#sfTok='));
        $tok = urldecode($enc);
        if(strpos($tok, '[sffile:') === 0) {
          $a->parentNode->replaceChild($dom->createTextNode($tok), $a);
        }
      }

      // Extract body innerHTML
      $out = '';
      foreach($bodyNode->childNodes as $child) {
        $out .= $dom->saveHTML($child);
      }
      $out = trim((string) $out);
      // FINAL_CLEANUP_P_TAGS
      $out = str_replace(['&lt;/p&gt; &lt;p&gt;', '&lt;/p&gt;&lt;p&gt;', '&lt;p&gt;', '&lt;/p&gt;'], ['', '', '', ''], $out);
      $out = str_replace(['</p> <p>'], '</p><p>', $out);
      return $out === '' ? $body : $out;

    } catch(\Throwable $e) {
      return $body;
    }
  }

  /**
   * Replace [sfimg:key] and [sffile:key|label] tokens with real HTML
   * and move temp uploads into the post's file/image fields.
   */
protected function applyTempUploadsToBody(Page $post, string $body, string $ctx): string {
    // Server-side safety: normalize any editor placeholders into tokens
    $body = $this->normalizeEditorEmbedsToTokens($body);
    if(!$this->attachmentsEnabled() || !$ctx) return $body;

    $skey = $this->tempUploadSessionKey($ctx);
    $uploads = $this->getTempUploads($ctx);
    if(!count($uploads)) return $body;

    $post->of(false);

    $imageField = 'forum_post_images';
    $fileField  = 'forum_post_files';

    $maxW = (int) ($this->get('attachmentsImageMaxWidth') ?: 900);
    if($maxW < 200) $maxW = 900;

    $usedKeys = [];

    // Lightbox output mode (library itself is provided by the site/theme; NativeForum only emits attributes/classes)
    $lbEnabled = (int)($this->get('attachmentsEnableLightbox') ?? 1) ? 1 : 0;
    $lbLib = (string)($this->get('attachmentsLightboxLibrary') ?? 'fancybox'); // fancybox|glightbox|none
    if($lbLib === '') $lbLib = 'fancybox';

    // images
    // Optional width: [sfimg:key|w=300] or [sfimg:key|w=50%]
    $body = preg_replace_callback('/\\[sfimg:([a-z0-9_-]+)(\\|[^\\]]+)?\\]/i', function($m) use (&$uploads, &$usedKeys, $post, $imageField, $maxW, $lbEnabled, $lbLib) {
      $key = (string) $m[1];
      $params = isset($m[2]) ? (string)$m[2] : '';
      $w = '';
      if($params && preg_match('/\\bw\\s*=\\s*([0-9]{1,4}%?)/i', $params, $mm)) {
        $w = trim((string)$mm[1]);
      }
      if(!isset($uploads[$key])) return '';
      $meta = $uploads[$key];
      if(($meta['kind'] ?? '') !== 'image') return '';
      $tmp = (string) ($meta['path'] ?? '');
      if(!$tmp || !is_file($tmp)) return '';

      try {
        if($post->hasField($imageField)) {
          $post->$imageField->add($tmp);
          $post->save($imageField);
          $img = $post->$imageField->last();
          if($img && $img->url) {
            $full  = $img->url;
            $turl  = $full;
            $usedKeys[] = $key;
            // Preserve chosen size via the HTML width attribute (supported by our purifier rules).
            $widthAttr = '';
            $styleAttr = '';
            if($w !== '') {
              // If it's numeric, treat as px
              if(ctype_digit($w)) {
                $widthAttr = ' width="' . (int)$w . '"';
                $styleAttr = ' style="width:' . (int)$w . 'px;max-width:100%;height:auto;"';
              } elseif(preg_match('/^(\d{1,3})%$/', $w)) {
                // Allow % widths too (use style)
                $styleAttr = ' style="width:' . htmlspecialchars($w, ENT_QUOTES, 'UTF-8') . ';max-width:100%;height:auto;"';
              }
            }
            // Build final HTML.
            // IMPORTANT: keep the sizing on the <img> (inline) so CKEditor resizing survives save/edit cycles.
            // (Wrapping images in a sizing span caused the resizer handles to stop working after re-edit.)
            $imgHtml = "<img src=\"" . htmlspecialchars($turl, ENT_QUOTES, 'UTF-8') . "\" alt=\"\" class=\"sf-embed-img\"" . $widthAttr . $styleAttr . " />";

            $aClass = "sf-img-link sf-embed-link";
            $aExtra = '';
            if($lbEnabled && $lbLib !== 'none') {
              if($lbLib === 'fancybox') {
                $aExtra = ' data-fancybox="sf"';
              } else {
                $aClass .= ' glightbox';
              }
            }

            return "<a href=\"" . htmlspecialchars($full, ENT_QUOTES, 'UTF-8') . "\" class=\"" . $aClass . "\"" . $aExtra . ">" .
                   $imgHtml .
                   "</a>";
          }
        }
      } catch(\Throwable $e) {}

      return '';
    }, $body);

    // files
    $body = preg_replace_callback('/\\[sffile:([a-z0-9_-]+)(\\|([^\\]]+))?\\]/i', function($m) use (&$uploads, &$usedKeys, $post, $fileField) {
      $key = (string) $m[1];
      $label = isset($m[3]) ? (string)$m[3] : '';
      if(!isset($uploads[$key])) return '';
      $meta = $uploads[$key];
      if(($meta['kind'] ?? '') !== 'file') return '';
      $tmp = (string) ($meta['path'] ?? '');
      if(!$tmp || !is_file($tmp)) return '';

      try {
        if($post->hasField($fileField)) {
          $post->$fileField->add($tmp);
          $post->save($fileField);
          $f = $post->$fileField->last();
          if($f && $f->url) {
            $usedKeys[] = $key;
            $txt = $label ?: ($meta['name'] ?? 'Download');
            $txt = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
            return "<a class=\"sf-file-btn\" href=\"" . htmlspecialchars($f->url, ENT_QUOTES, 'UTF-8') . "\" target=\"_blank\" rel=\"nofollow noopener\"><span class=\"sf-file-icon\">⬇</span> " . $txt . "</a>";
          }
        }
      } catch(\Throwable $e) {}

      return '';
    }, $body);

    // NOTE: Do not auto-append non-inserted uploads to the end of the post.
    // Users must explicitly click "Insert" to place uploads where they want them.

    // cleanup used uploads
    foreach(array_unique($usedKeys) as $k) {
      $p = (string)($uploads[$k]['path'] ?? '');
      if($p) @unlink($p);
      unset($uploads[$k]);
    }
    // remove remaining temp uploads (not inserted)
    foreach($uploads as $k => $meta) {
      $p = (string)($meta['path'] ?? '');
      if($p) @unlink($p);
      unset($uploads[$k]);
    }

    $this->wire('session')->remove($skey);
    $this->removeTempUpload($ctx);

    // attempt to remove context directory
    $dir = $this->tempUploadDir($ctx);
    @rmdir($dir);

    $post->of(true);

    return $body;
  }

  /**
   * Remove old temp upload folders/files to avoid buildup.
   */
  public function cleanupTempUploads(): void {
    $base = $this->tempUploadBasePath();
    if(!is_dir($base)) return;
    $now = time();
    foreach(@scandir($base) ?: [] as $name) {
      if($name === '.' || $name === '..') continue;
      $dir = $base . $name;
      if(!is_dir($dir)) continue;
      // delete dirs older than 1 day
      $mtime = @filemtime($dir) ?: $now;
      if(($now - $mtime) < 86400) continue;

      foreach(glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
      }
      @rmdir($dir);
    }
  }


public function createTopic(Page $category, User $author, string $title, string $body, string $summary = '', string $uploadCtx = '', string $tagsRaw = ''): Page {
    if(!$this->canPost($author)) throw new WireException("Posting not allowed.");
    if($category->template->name !== 'forum-category') throw new WireException("Not a forum category.");

    
    if(!$this->canWriteCategory($author, $category)) throw new WireException("Not allowed to post in this category.");
    $wait = $this->floodWait($author);
    if($wait > 0) throw new WireException("Please wait {$wait} seconds before posting again.");
    if(trim($this->sanitizer->text($title)) === '') throw new WireException("Title is required.");
    if(($spam = $this->spamCheckContent($author, $body, $title)) !== '') throw new WireException($spam);
    $p = new Page();
    $p->template = 'forum-topic';
    $p->parent   = $category;
    $p->title    = $this->sanitizer->text($title);
    $p->name     = $this->sanitizer->pageName($title, true);
    $p->of(false);
    // Save first to get an ID (needed for file/image fields)
    $bodyNorm = $this->normalizeEditorEmbedsToTokens($body);
    $p->set('forum_body', $this->sanitizePostBody($bodyNorm));
    if($p->hasField('field_forum_summary_topic')) $p->set('field_forum_summary_topic', $this->sanitizer->text($summary));
    $p->set('forum_author', $author);
    $p->set('forum_vote_score', 0);
    // Tags: always use the module parser (do NOT rely on a potentially old site-level helper)
    // Rule: each '#' starts a new tag; spaces allowed until the next '#'.
    if($tagsRaw && $p->hasField('forum_tags')) {
      $tagsArr = $this->parseTagsString((string) $tagsRaw);
      if(!empty($tagsArr)) $p->set('forum_tags', implode(' ', $tagsArr));
    }
    $p->save();
    // Inline temp uploads (images/files) from the frontend widget
    if($uploadCtx) {
      $processed = $this->applyTempUploadsToBody($p, $bodyNorm, $uploadCtx);
      $p->of(false);
      $p->set('forum_body', $this->sanitizePostBody($processed));
      $p->save('forum_body');
      $p->of(true);
    }
    // update cached post count + auto-rank
    $this->floodMark($author);
    if($author && $author->id && !$author->isGuest()) {
      $this->incrementUserPostCount($author, 1);
      $this->applyAutoRank($author);
    }
    return $p;
  }

  public function createReply(Page $topic, User $author, string $body, Page $replyTo = null, string $uploadCtx = ''): Page {
    if(!$this->canPost($author)) throw new WireException("Posting not allowed.");
    if($topic->template->name !== 'forum-topic') throw new WireException("Not a forum topic.");
$cat = $topic->parent;
if($cat && $cat->id && $cat->template->name === 'forum-category') {
  if(!$this->canReadCategory($author, $cat) || !$this->canWriteCategory($author, $cat)) throw new WireException("Not allowed to post in this category.");
}
$wait = $this->floodWait($author);
if($wait > 0) throw new WireException("Please wait {$wait} seconds before posting again.");
if(($spam = $this->spamCheckContent($author, $body)) !== '') throw new WireException($spam);
// Respect topic lock (only moderators can reply when locked)
if($topic->hasField('forum_is_locked') && (int)$topic->forum_is_locked && !$this->isModerator($author)) {
  throw new WireException("Topic is locked.");
}


    $r = new Page();
    $r->template = 'forum-reply';
    $r->parent   = $topic;
    $r->title    = 'Reply';
    $r->name     = date('YmdHis') . '-' . $this->randomName();
    $r->of(false);
    $bodyNorm = $this->normalizeEditorEmbedsToTokens($body);
    $r->set('forum_body', $this->sanitizePostBody($bodyNorm));
    $r->set('forum_author', $author);
    $r->set('forum_vote_score', 0);
    if($replyTo && $replyTo->id && $replyTo->template->name === 'forum-reply') {
      $r->set('forum_reply_to', $replyTo);
    }
    $r->save();
    // Inline temp uploads (images/files) from the frontend widget
    if($uploadCtx) {
      $processed = $this->applyTempUploadsToBody($r, $bodyNorm, $uploadCtx);
      $r->of(false);
      $r->set('forum_body', $this->sanitizePostBody($processed));
      $r->save('forum_body');
      $r->of(true);
    }
    // Notify followers
    try {
      $this->notifyTopicFollowers($topic, $r, $author);
    } catch(\Throwable $e) { /* ignore */ }
    // update cached post count + auto-rank
    $this->floodMark($author);
    if($author && $author->id && !$author->isGuest()) {
      $this->incrementUserPostCount($author, 1);
      $this->applyAutoRank($author);
    }

    return $r;
  }

  public function toggleLike(Page $post, User $u): array {
    if(!$u || $u->isGuest() || !$this->canVote($u)) throw new WireException("Login required.");
    if(!$this->canReadForum($u, $post)) throw new WireException("Not allowed.");
    $own = $post->getUnformatted('forum_author');
    if($own instanceof Page && (int) $own->id === (int) $u->id) throw new WireException("You cannot like your own post.");
    if(!in_array($post->template->name, ['forum-topic', 'forum-reply'])) {
      throw new WireException("Not likeable.");
    }

    $post->of(false);
    $likes = $post->get('forum_like_users');
    if(!$likes instanceof PageArray) $likes = new PageArray();

    $liked = $likes->has($u);
    if($liked) $likes->remove($u);
    else $likes->add($u);

    $post->set('forum_like_users', $likes);
    $post->save('forum_like_users');
    $post->of(true);

    return [
      'liked' => !$liked,
      'count' => (int) $post->forum_like_users->count()
    ];
  }

  public function vote(Page $post, User $u, int $delta): int {
    if(!$u || $u->isGuest() || !$this->canVote($u)) throw new WireException("Login required.");
    if(!$this->canReadForum($u, $post)) throw new WireException("Not allowed.");
    $own = $post->hasField('forum_author') ? $post->getUnformatted('forum_author') : null;
    if($own instanceof Page && (int) $own->id === (int) $u->id) return (int) $post->get('forum_vote_score');
    if(!in_array($post->template->name, ['forum-topic', 'forum-reply', 'forum-message'])) {
      throw new WireException("Not votable.");
    }

    $delta = $delta > 0 ? 1 : -1;

    $post->of(false);
    $voters = $post->get('forum_vote_users');
    if(!$voters instanceof PageArray) $voters = new PageArray();

    if($voters->has($u)) {
      $post->of(true);
      return (int) $post->get('forum_vote_score');
    }

    $voters->add($u);
    $post->set('forum_vote_users', $voters);
    $post->set('forum_vote_score', (int)$post->get('forum_vote_score') + $delta);
    $post->save(['forum_vote_users','forum_vote_score']);
    $post->of(true);

    return (int) $post->get('forum_vote_score');
  }

  public function sendMessage(User $from, User $to, string $subject, string $body): Page {
    if(!$this->privateMessagesEnabled()) {
      throw new WireException("Private messages are disabled.");
    }
    if(!$this->canPost($from) || $from->isGuest()) throw new WireException("Posting not allowed.");
    if(!$to || !$to->id || $to->isGuest()) throw new WireException("Invalid recipient.");
    if((int) $to->id === (int) $from->id) throw new WireException("You cannot message yourself.");
    $root = $this->messagesRoot();
    if(!$root || !$root->id) throw new WireException("Messages root not configured.");

    $subject = trim($this->sanitizer->text($subject, ['maxLength' => 200]));
    if($subject === '') $subject = '(no subject)';
    if(($spam = $this->spamCheckContent($from, $body, $subject)) !== '') throw new WireException($spam);
    // PM flood: max 10 new conversations per 10 minutes for members with few posts
    if(!$this->isModerator($from) && $this->getUserPostCount($from) < 5) {
      $k = 'sf_pmflood_' . (int) $from->id;
      $n = (int) $this->wire('cache')->getFor('NativeForum', $k);
      if($n >= 10) { $this->spamLog("PM flood by {$from->name}"); throw new WireException('You are sending messages too quickly. Please wait a few minutes.'); }
      $this->wire('cache')->saveFor('NativeForum', $k, (string) ($n + 1), 600);
    }
    $cleanBody = $this->sanitizePostBody($body);
    if(trim(strip_tags($cleanBody, '<img><iframe>')) === '') throw new WireException("Message is empty.");

    $msg = new Page();
    $msg->template = 'forum-message';
    $msg->parent   = $root;
    $msg->title    = $subject;
    $msg->name     = date('YmdHis') . '-' . $this->randomName();
    $msg->of(false);
    $msg->set('forum_from_user', $from);
    $msg->set('forum_to_user',   $to);
    $msg->set('forum_body',      $cleanBody);
    $msg->set('forum_is_read',   0);
    $msg->set('forum_vote_score', 0);
    $msg->save();
    $msg->of(true);

    // Optional e-mail notification (never let a mail failure break sending)
    $toEmail = (string) $to->email;
    if($toEmail && $this->cfgOn('pmEmailNotify')) {
      try {
        $config = $this->wire('config');
        $host = preg_replace('/:\d+$/', '', (string) $config->httpHost);
        $fromEmail = (string) ($config->adminEmail ?: ('no-reply@' . $host));
        $fromName  = (string) ($this->get('forumName') ?: 'Forum');
        $replyUrl  = $root->httpUrl . '?to=' . (int) $from->id;
        $plain = trim(html_entity_decode(strip_tags(str_replace(['<br>','<br/>','<br />','</p>'], "\n", $cleanBody)), ENT_QUOTES, 'UTF-8'));
        $fromLabel = $this->displayName($from);
        $bodyMail = "You received a new private message from {$fromLabel}.\n\n" .
          "Subject: {$subject}\n\n" . $plain . "\n\n" .
          "Read it in the forum: " . $msg->httpUrl . "\n" .
          "Reply: " . $replyUrl . "\n";
        $mail = wireMail();
        $mail->to($toEmail)->from($fromEmail, $fromName)->subject('[' . $fromName . '] ' . $subject)->body($bodyMail);
        if($from->email) $mail->replyTo((string) $from->email);
        $mail->send();
      } catch(\Throwable $e) {
        $this->wire('log')->save('native-forum', 'PM mail failed: ' . $e->getMessage());
      }
    }

    return $msg;
  }

  /************************************************************
   * ANTI-SPAM / FLOOD
   ************************************************************/

  /**
   * Hidden fields for public forms: CSRF + honeypot + render timestamp.
   */
  public function antiSpamFields(string $key): string {
    $key = preg_replace('/[^a-z0-9_]/i', '', $key);
    $out = "<div class='sf-hp' aria-hidden='true'><label>Website<input type='text' name='sf_website' value='' tabindex='-1' autocomplete='off'></label></div>"
      . "<input type='hidden' name='sf_ts' value=''><input type='hidden' name='sf_sig' value=''><input type='hidden' name='sf_js' value=''>"
      . "<span class='sf-tokslot' data-sf-tok='{$key}' hidden></span>"
      . "<noscript><p class='sf-alert sf-alert-error'>Please enable JavaScript to use this form.</p></noscript>"
      . $this->turnstileWidget($key);
    static $jsDone = false;
    if(!$jsDone) { $jsDone = true; $out .= $this->antiSpamJs(); }
    return $out;
  }

  /** JSON endpoint: CSRF token + signed timestamp + JS challenge for one form. */
  protected function respondFormToken(string $key): void {
    $key = preg_replace('/[^a-z0-9_]/i', '', $key);
    $session = $this->wire('session');
    $ts = time();
    $sig = hash_hmac('sha256', $key . '|' . $ts, (string) $this->wire('config')->userAuthSalt);
    $data = [
      'n' => $session->CSRF->getTokenName('sf' . $key),
      'v' => $session->CSRF->getTokenValue('sf' . $key),
      'ts' => $ts,
      'sig' => $sig,
      'c' => substr($sig, 7, 10), // challenge: the page script answers with this string reversed
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('X-Robots-Tag: noindex');
    echo json_encode($data);
    exit;
  }

  protected function antiSpamJs(): string {
    $root = $this->forumRoot();
    $url = ($root && $root->id) ? $root->url : '/';
    $u = json_encode($url);
    return <<<JS
<script>(function(){if(window.__sfTok)return;window.__sfTok=1;var U={$u};
function slot(f){return f&&f.querySelector?f.querySelector('[data-sf-tok]'):null;}
function fill(f,cb){var s=slot(f);if(!s){cb&&cb();return;}if(f.__sfTokOk){cb&&cb();return;}if(f.__sfTokCbs){cb&&f.__sfTokCbs.push(cb);return;}f.__sfTokCbs=cb?[cb]:[];
var k=s.getAttribute('data-sf-tok'),x=new XMLHttpRequest();x.open('GET',U+(U.indexOf('?')<0?'?':'&')+'sf_token='+encodeURIComponent(k)+'&_='+Date.now(),true);x.setRequestHeader('X-Requested-With','XMLHttpRequest');
x.onload=function(){try{var d=JSON.parse(x.responseText);var i=f.querySelector('input[data-sf-csrf]');if(!i){i=document.createElement('input');i.type='hidden';i.setAttribute('data-sf-csrf','1');f.appendChild(i);}i.name=d.n;i.value=d.v;
f.querySelector('[name=sf_ts]').value=d.ts;f.querySelector('[name=sf_sig]').value=d.sig;f.querySelector('[name=sf_js]').value=String(d.c).split('').reverse().join('');f.__sfTokOk=1;}catch(e){}var c=f.__sfTokCbs||[];f.__sfTokCbs=null;c.forEach(function(fn){try{fn();}catch(e){}});};
x.onerror=x.onload;x.send();}
function warm(e){var f=e.target&&e.target.closest?e.target.closest('form'):null;if(f&&slot(f))fill(f);}window.sfWarmForm=function(f){if(f&&slot(f))fill(f);};
document.addEventListener('focusin',warm,true);document.addEventListener('pointerdown',warm,true);
document.addEventListener('submit',function(e){var f=e.target;if(!slot(f)||f.__sfTokOk)return;e.preventDefault();var sb=e.submitter||null;fill(f,function(){if(sb&&sb.name){var h=document.createElement('input');h.type='hidden';h.name=sb.name;h.value=sb.value||'1';f.appendChild(h);}HTMLFormElement.prototype.submit.call(f);});},true);
document.addEventListener('click',function(e){var t=e.target&&e.target.closest?e.target.closest('[data-sf-auth],a[href*="forum-login"],a[href*="forum-register"]'):null;if(!t)return;setTimeout(function(){document.querySelectorAll('dialog[open] form').forEach(function(f){if(slot(f))fill(f);});},50);},true);
})();</script>
JS;
  }

  /**
   * Validate a public form. Returns '' when OK, otherwise an error message.
   */
  public function antiSpamCheck(string $key, int $minSeconds = 3): string {
    $input = $this->wire('input');
    $session = $this->wire('session');
    $key = preg_replace('/[^a-z0-9_]/i', '', $key);
    $ip = (string) $session->getIP();
    $reject = function(string $why, string $msg = 'Request rejected.') use ($key, $ip) {
      $this->spamLog("$key: $why");
      return $msg;
    };
    if($this->ipBlocked($ip)) return $reject('blocked IP', 'Request rejected.');
    try {
      if(!$session->CSRF->hasValidToken('sf' . $key)) return 'Your session expired. Please reload the page and try again.';
    } catch(\Throwable $e) {
      return 'Your session expired. Please reload the page and try again.';
    }
    if(trim((string) $input->post('sf_website')) !== '') return $reject('honeypot filled');
    $ts = (int) $input->post('sf_ts');
    $sig = (string) $input->post('sf_sig');
    $expect = hash_hmac('sha256', $key . '|' . $ts, (string) $this->wire('config')->userAuthSalt);
    if(!$ts || !hash_equals($expect, $sig)) return $reject('bad signature', 'Your session expired. Please reload the page and try again.');
    if((string) $input->post('sf_js') !== strrev(substr($expect, 7, 10))) return $reject('no js', 'Please enable JavaScript and try again.');
    if(time() - $ts > 6 * 3600) return 'This form has expired. Please reload the page and try again.';
    if(time() - $ts < $minSeconds) return $reject('too fast', 'That was fast — please wait a moment and try again.');
    if($this->turnstileEnabled($key)) {
      $err = $this->turnstileVerify((string) $input->post('cf-turnstile-response'), $ip);
      if($err !== '') return $reject('captcha: ' . $err, 'Please confirm you are human (captcha) and try again.');
    }
    return '';
  }

  /************************************************************
   * ANTI-SPAM HELPERS
   ************************************************************/

  public function spamLog(string $msg): void {
    if(!$this->cfgOn('spamLog')) return;
    try {
      $ip = (string) $this->wire('session')->getIP();
      $this->wire('log')->save('nativeforum-spam', "[$ip] " . $msg);
    } catch(\Throwable $e) {}
  }

  protected function cfgLines(string $key): array {
    $raw = (string) $this->get($key);
    $out = [];
    foreach(preg_split('/[\r\n,]+/', $raw) as $l) { $l = trim($l); if($l !== '' && strpos($l, '#') !== 0) $out[] = $l; }
    return $out;
  }

  public function ipBlocked(string $ip): bool {
    if($ip === '') return false;
    foreach($this->cfgLines('spamBlockedIps') as $rule) {
      if($rule === $ip) return true;
      if(substr($rule, -1) === '*' && strpos($ip, rtrim($rule, '*')) === 0) return true;
      if(strpos($rule, '/') !== false && $this->ipInCidr($ip, $rule)) return true;
    }
    return false;
  }

  protected function ipInCidr(string $ip, string $cidr): bool {
    list($net, $bits) = array_pad(explode('/', $cidr, 2), 2, '');
    $ipB = @inet_pton($ip); $netB = @inet_pton($net);
    if($ipB === false || $netB === false || strlen($ipB) !== strlen($netB)) return false;
    $bits = (int) $bits; $bytes = intdiv($bits, 8); $rem = $bits % 8;
    if(substr($ipB, 0, $bytes) !== substr($netB, 0, $bytes)) return false;
    if($rem === 0) return true;
    $mask = chr((0xFF << (8 - $rem)) & 0xFF);
    return (($ipB[$bytes] & $mask) === ($netB[$bytes] & $mask));
  }

  /** Built-in list of throw-away e-mail providers (extend in settings). */
  protected function disposableDomains(): array {
    $builtin = ['mailinator.com','guerrillamail.com','guerrillamail.net','guerrillamail.org','sharklasers.com','grr.la','10minutemail.com','10minutemail.net',
      'tempmail.com','temp-mail.org','temp-mail.io','tempmailo.com','tempr.email','throwawaymail.com','yopmail.com','yopmail.net','trashmail.com','trashmail.de',
      'getnada.com','nada.email','dispostable.com','maildrop.cc','mailnesia.com','mintemail.com','mohmal.com','emailondeck.com','fakeinbox.com','spamgourmet.com',
      'mailcatch.com','moakt.com','burnermail.io','tempinbox.com','discard.email','dropmail.me','emltmp.com','mail.tm','mailpoof.com','spambox.us','tmpmail.org',
      'tmpmail.net','tempail.com','harakirimail.com','33mail.com','anonaddy.me','inboxkitten.com','mytemp.email','luxusmail.org','mailsac.com','owlymail.com',
      'fexpost.com','fexbox.org','byom.de','wegwerfmail.de','einrot.com','spam4.me','trbvm.com','cuvox.de','armyspy.com','dayrep.com','fleckens.hu','gustr.com',
      'jourrapide.com','rhyta.com','superrito.com','teleworm.us','emailfake.com','email-fake.com','generator.email','tempmail.plus','minuteinbox.com','linshiyouxiang.net'];
    return array_unique(array_merge($builtin, array_map('strtolower', $this->cfgLines('spamBlockedEmailDomains'))));
  }

  /** Words/phrases or /regex/ from settings found in text → the matched rule, else ''. */
  public function bannedMatch(string $text): string {
    $plain = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
    foreach($this->cfgLines('spamBannedWords') as $rule) {
      if(strlen($rule) > 2 && $rule[0] === '/' && substr($rule, -1) === '/') {
        if(@preg_match($rule . 'iu', $plain)) return $rule;
      } elseif(mb_strpos($plain, mb_strtolower($rule)) !== false) {
        return $rule;
      }
    }
    return '';
  }

  public function countLinks(string $html): int {
    $n = preg_match_all('~<a\s[^>]*href=~i', $html);
    $plain = preg_replace('~<a\s[^>]*>.*?</a>~is', ' ', $html);
    $n += preg_match_all('~(?:https?://|www\.)[^\s<]+~i', strip_tags((string) $plain));
    return (int) $n;
  }

  /**
   * Content rules for new posts/topics/messages. Returns '' when OK, else the message to show.
   */
  public function spamCheckContent(?User $u, string $body, string $title = ''): string {
    if($u && $u->id && !$u->isGuest() && $this->isModerator($u)) return '';
    $text = $title . "\n" . $body;
    if(($rule = $this->bannedMatch($text)) !== '') {
      $this->spamLog("post blocked (banned: $rule) by " . ($u && $u->id ? $u->name : 'guest'));
      return 'Your message contains words that are not allowed here.';
    }
    $links = $this->countLinks($body) + $this->countLinks($title);
    $isGuest = !$u || !$u->id || $u->isGuest();
    $newPosts = (int) ($this->get('spamNewMemberPosts') ?? 3);
    $maxNew = (int) ($this->get('spamNewMemberMaxLinks') ?? 1);
    if($this->get('spamNewMemberPosts') === null || $this->get('spamNewMemberPosts') === '') $newPosts = 3;
    if($this->get('spamNewMemberMaxLinks') === null || $this->get('spamNewMemberMaxLinks') === '') $maxNew = 1;
    if($isGuest) {
      if($links > 0) { $this->spamLog("guest post with $links link(s) blocked"); return 'Guests cannot post links. Please register to share links.'; }
    } elseif($newPosts > 0) {
      $posts = $this->getUserPostCount($u);
      if($posts < $newPosts && $links > $maxNew) {
        $this->spamLog("new member {$u->name} ($posts posts) tried $links links");
        return $maxNew > 0
          ? sprintf('New members can post at most %d link(s) until they have written %d posts.', $maxNew, $newPosts)
          : sprintf('New members can post links after %d posts.', $newPosts);
      }
    }
    $maxLinks = (int) ($this->get('spamMaxLinks') ?: 0);
    if($maxLinks > 0 && $links > $maxLinks) return sprintf('Please use at most %d links per post.', $maxLinks);
    return '';
  }

  /**
   * Extra checks when someone registers. Throws WireException with a user-facing message.
   */
  public function spamCheckRegistration(string $name, string $email): void {
    $session = $this->wire('session');
    $ip = (string) $session->getIP();
    if($this->ipBlocked($ip)) { $this->spamLog("register blocked IP: $email"); throw new WireException('Registration is not possible from your network.'); }
    if(preg_match('~(https?|www\.|\.(com|net|org|ru|xyz|top|info|biz|online|site|shop)$)~i', $name)) {
      $this->spamLog("register: url-like username $name"); throw new WireException('Please choose a normal username (no web addresses).');
    }
    if(($rule = $this->bannedMatch($name . ' ' . $email)) !== '') { $this->spamLog("register banned ($rule): $name $email"); throw new WireException('This username or e-mail is not allowed.'); }
    $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    if($domain === '') throw new WireException('Please enter a valid email address.');
    foreach($this->disposableDomains() as $d) {
      if($domain === $d || substr($domain, -strlen('.' . $d)) === '.' . $d) {
        $this->spamLog("register disposable email: $email");
        throw new WireException('Please use a permanent e-mail address (throw-away addresses are not accepted).');
      }
    }
    if($this->cfgOn('spamCheckMx') && function_exists('checkdnsrr')) {
      // fail-open: when this server cannot resolve DNS at all, do not block anyone
      if(!@checkdnsrr($domain, 'MX') && !@checkdnsrr($domain, 'A') && (@checkdnsrr('gmail.com', 'MX') || @checkdnsrr('outlook.com', 'MX'))) {
        $this->spamLog("register: domain without mail server: $email");
        throw new WireException('This e-mail domain cannot receive e-mail. Please check the address.');
      }
    }
    // Per-IP limit
    $limit = (int) ($this->get('spamRegPerIpDay') ?? 3);
    if($this->get('spamRegPerIpDay') === null || $this->get('spamRegPerIpDay') === '') $limit = 3;
    if($limit > 0 && $ip !== '') {
      $k = 'sf_regip_' . md5($ip);
      $n = (int) $this->wire('cache')->getFor('NativeForum', $k);
      if($n >= $limit) { $this->spamLog("register: IP limit reached ($n)"); throw new WireException('Too many registrations from your network today. Please try again tomorrow.'); }
    }
    if($this->cfgOn('spamStopForumSpam', false)) {
      $hit = $this->stopForumSpamHit($email, $ip);
      if($hit !== '') { $this->spamLog("register: StopForumSpam $hit ($email)"); throw new WireException('Registration rejected: this address or network is listed as a known spam source.'); }
    }
  }

  /** Count a successful registration for the per-IP daily limit. */
  protected function spamMarkRegistration(): void {
    $ip = (string) $this->wire('session')->getIP();
    if($ip === '') return;
    $k = 'sf_regip_' . md5($ip);
    try {
      $n = (int) $this->wire('cache')->getFor('NativeForum', $k);
      $this->wire('cache')->saveFor('NativeForum', $k, (string) ($n + 1), 86400);
    } catch(\Throwable $e) {}
  }

  /** StopForumSpam lookup (optional, 4 s timeout). Returns '' or what was listed. */
  protected function stopForumSpamHit(string $email, string $ip): string {
    try {
      $http = new WireHttp();
      $http->setTimeout(4);
      $q = http_build_query(['email' => $email, 'ip' => $ip, 'json' => 1, 'confidence' => 1]);
      $res = json_decode((string) $http->get('https://api.stopforumspam.org/api?' . $q), true);
      if(!is_array($res) || empty($res['success'])) return '';
      foreach(['email', 'ip'] as $t) {
        if(!empty($res[$t]['appears']) && (float) ($res[$t]['confidence'] ?? 0) >= 40) return $t;
      }
    } catch(\Throwable $e) {}
    return '';
  }

  /* Cloudflare Turnstile (optional): free, privacy friendly captcha. */
  public function turnstileEnabled(string $key = ''): bool {
    if(trim((string) $this->get('turnstileSiteKey')) === '' || trim((string) $this->get('turnstileSecret')) === '') return false;
    $forms = (array) ($this->get('turnstileForms') ?: ['register']);
    if($key === '') return true;
    if($key === 'reply' || $key === 'topic') return in_array('guestposts', $forms, true) && $this->wire('user')->isGuest();
    return in_array($key, $forms, true);
  }

  protected function turnstileWidget(string $key): string {
    if(!$this->turnstileEnabled($key)) return '';
    $sk = $this->wire('sanitizer')->entities((string) $this->get('turnstileSiteKey'));
    static $loaded = false;
    $js = '';
    if(!$loaded) { $loaded = true; $js = "<script src='https://challenges.cloudflare.com/turnstile/v0/api.js' async defer></script>"; }
    return "<div class='cf-turnstile sf-captcha' data-sitekey='{$sk}' data-theme='auto'></div>" . $js;
  }

  protected function turnstileVerify(string $token, string $ip): string {
    if($token === '') return 'missing';
    try {
      $http = new WireHttp();
      $http->setTimeout(6);
      $res = json_decode((string) $http->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'secret' => (string) $this->get('turnstileSecret'), 'response' => $token, 'remoteip' => $ip,
      ]), true);
      if(is_array($res) && !empty($res['success'])) return '';
      return is_array($res) ? implode(',', (array) ($res['error-codes'] ?? ['failed'])) : 'no answer';
    } catch(\Throwable $e) {
      return 'error';
    }
  }

  /**
   * Flood control: seconds a user must wait before the next post (0 = allowed).
   */
  public function floodWait(User $u): int {
    if($this->isModerator($u)) return 0;
    $limit = (int) ($this->get('floodSeconds') ?? 15);
    if($this->get('floodSeconds') === null || $this->get('floodSeconds') === '') $limit = 15;
    if($limit <= 0) return 0;
    $k = 'sf_lastpost_' . ($u && $u->id && !$u->isGuest() ? 'u' . $u->id : 'ip' . md5((string) $this->wire('session')->getIP()));
    $last = (int) $this->wire('cache')->getFor('NativeForum', $k);
    $wait = $last ? ($last + $limit) - time() : 0;
    return max(0, $wait);
  }

  protected function floodMark(User $u): void {
    $k = 'sf_lastpost_' . ($u && $u->id && !$u->isGuest() ? 'u' . $u->id : 'ip' . md5((string) $this->wire('session')->getIP()));
    $this->wire('cache')->saveFor('NativeForum', $k, (string) time(), 3600);
  }

  /**
   * Register a forum member (front-end). Returns the new User or throws WireException with a friendly message.
   */
  public function registerUser(string $name, string $email, string $pass1, string $pass2, string $return = ''): ?User {
    if(!$this->cfgOn('allowRegistration')) throw new WireException('Registration is currently closed.');
    $sanitizer = $this->wire('sanitizer');
    $users = $this->wire('users');
    $cleanName = $sanitizer->pageName($name, true);
    $email = $sanitizer->email($email);
    if(strlen($cleanName) < 3 || strlen($cleanName) > 40 || $cleanName !== strtolower(trim($name))) {
      throw new WireException('Username must be 3–40 characters: letters, numbers, dash or underscore.');
    }
    if(!$email) throw new WireException('Please enter a valid email address.');
    if(strlen($pass1) < 8) throw new WireException('Password must be at least 8 characters.');
    if($pass1 !== $pass2) throw new WireException('Passwords do not match.');
    $this->spamCheckRegistration(trim($name), $email);
    if($users->get("name=$cleanName, include=all")->id) throw new WireException('That username is already taken.');
    if($users->get("email=" . $sanitizer->selectorValue($email) . ", include=all")->id) throw new WireException('An account with this email already exists.');

    if($this->cfgOn('requireEmailVerification')) {
      // Name/e-mail reserved by an unconfirmed registration?
      foreach($this->pendingRegistrations() as $pend) {
        if(($pend['name'] ?? '') === $cleanName) throw new WireException('That username is already taken.');
        if(strtolower($pend['email'] ?? '') === strtolower($email)) {
          throw new WireException('A confirmation e-mail was already sent to this address. Please check your inbox (and spam folder).');
        }
      }
      $this->createPendingRegistration($cleanName, $email, $pass1, $return);
      $this->spamMarkRegistration();
      return null; // account is created after the e-mail link is clicked
    }
    $u = $this->createMember($cleanName, $email, $pass1);
    $this->spamMarkRegistration();
    return $u;
  }

  /**
   * Create the actual user account. $pass is either a plain password or a Password object (salt+hash).
   */
  protected function createMember(string $name, string $email, $pass): User {
    $roleName = (string) ($this->get('defaultRole') ?: 'forum-member');
    $role = $this->wire('roles')->get($roleName);
    // Never allow privileged roles as the self-registration role
    if($role && $role->id && ($role->name === 'superuser' || $role->hasPermission('page-edit') || $role->hasPermission('user-admin'))) $role = null;
    $u = new User();
    $u->name = $name;
    $u->email = $email;
    $u->pass = $pass;
    $u->addRole('guest');
    if($role && $role->id) $u->addRole($role);
    $u->save();
    return $u;
  }

  /************************************************************
   * E-MAIL CONFIRMATION OF REGISTRATIONS
   * Pending registrations live in WireCache (namespace NativeForum) and expire
   * automatically: no user account exists until the link is clicked.
   * Only a password hash is stored, never the password itself; the cache key is a
   * hash of the token, so the link cannot be reconstructed from the database.
   ************************************************************/

  public function verifyExpireSeconds(): int {
    $h = (int) $this->get('verifyExpireHours');
    if($h <= 0) $h = 24;
    return min(24 * 30, $h) * 3600;
  }

  protected function pendingRegistrations(): array {
    $out = [];
    try {
      $rows = $this->wire('cache')->getFor('NativeForum', 'sfreg-*');
      if(is_array($rows)) foreach($rows as $row) {
        $d = is_array($row) ? $row : json_decode((string) $row, true);
        if(is_array($d) && (int) ($d['expires'] ?? 0) > time()) $out[] = $d;
      }
    } catch(\Throwable $e) {}
    return $out;
  }

  protected function createPendingRegistration(string $name, string $email, string $plainPass, string $return = ''): void {
    $token = bin2hex(random_bytes(24));
    $pw = new Password();
    $pw->pass = $plainPass;
    $ttl = $this->verifyExpireSeconds();
    $data = [
      'name' => $name,
      'email' => $email,
      'salt' => (string) $pw->salt,
      'hash' => (string) $pw->hash,
      'return' => (string) $return,
      'created' => time(),
      'expires' => time() + $ttl,
      'ip' => (string) $this->wire('session')->getIP(),
    ];
    $this->wire('cache')->saveFor('NativeForum', 'sfreg-' . hash('sha256', $token), json_encode($data), $ttl);

    $reg = $this->wire('pages')->get('template=forum-register, include=hidden');
    $base = ($reg && $reg->id) ? $reg->httpUrl : $this->forumRoot()->httpUrl;
    $link = $base . '?verify=' . $token;
    $forumName = (string) ($this->get('forumName') ?: ($this->forumRoot() ? $this->forumRoot()->title : 'Forum'));
    $hours = (int) round($ttl / 3600);
    $text = "Hi {$name},\n\nplease confirm your registration on {$forumName} by opening this link:\n\n{$link}\n\n"
      . "The link is valid for {$hours} hours. If you did not register, simply ignore this e-mail — no account will be created.\n";
    $e = function($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $html = "<div style=\"font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1f2937;max-width:560px\">"
      . "<p>Hi <strong>{$e($name)}</strong>,</p><p>please confirm your registration on <strong>{$e($forumName)}</strong>.</p>"
      . "<p style=\"margin:24px 0\"><a href=\"{$e($link)}\" style=\"background:#1f2937;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block\">Confirm my account</a></p>"
      . "<p style=\"color:#6b7280;font-size:13px\">Or copy this link: {$e($link)}<br>The link is valid for {$hours} hours. If you did not register, ignore this e-mail — no account will be created.</p></div>";
    if(!$this->forumMail($email, "Confirm your registration – {$forumName}", $text, $html)) {
      $this->wire('cache')->deleteFor('NativeForum', 'sfreg-' . hash('sha256', $token));
      throw new WireException('We could not send the confirmation e-mail. Please try again later.');
    }
  }

  /**
   * Confirm a registration token: creates the account and logs the user in.
   * Returns [User|null, string $return, string $error]
   */
  public function confirmRegistration(string $token): array {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($token));
    if(strlen($token) !== 48) return [null, '', 'invalid'];
    $key = 'sfreg-' . hash('sha256', $token);
    $cache = $this->wire('cache');
    $raw = $cache->getFor('NativeForum', $key);
    $d = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if(!is_array($d) || (int) ($d['expires'] ?? 0) < time()) {
      $cache->deleteFor('NativeForum', $key);
      return [null, '', 'expired'];
    }
    $cache->deleteFor('NativeForum', $key); // single use
    $users = $this->wire('users');
    $sanitizer = $this->wire('sanitizer');
    if($users->get("name=" . $sanitizer->pageName($d['name']) . ", include=all")->id
      || $users->get("email=" . $sanitizer->selectorValue($d['email']) . ", include=all")->id) {
      return [null, '', 'exists'];
    }
    $pw = new Password();
    $pw->salt = (string) $d['salt'];
    $pw->hash = (string) $d['hash'];
    $u = $this->createMember((string) $d['name'], (string) $d['email'], $pw);
    $this->wire('session')->forceLogin($u);
    return [$u, (string) ($d['return'] ?? ''), ''];
  }

  /**
   * Send a forum e-mail (plain text + optional HTML). Never throws.
   */
  public function forumMail(string $to, string $subject, string $text, string $html = ''): bool {
    try {
      $config = $this->wire('config');
      $host = preg_replace('/:\d+$/', '', (string) $config->httpHost);
      $from = (string) ($this->get('mailFromEmail') ?: ($config->adminEmail ?: ''));
      if($from === '' || !$this->wire('sanitizer')->email($from)) {
        // no-reply@<domain>; IPs/localhost are not valid mail domains
        $from = preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host) ? 'no-reply@' . preg_replace('/^www\./i', '', $host) : 'no-reply@localhost.localdomain';
      }
      $fromName = (string) ($this->get('forumName') ?: ($this->forumRoot() ? $this->forumRoot()->title : 'Forum'));
      $m = wireMail();
      $m->to($to)->from($from, $fromName)->subject($subject)->body($text);
      if($html !== '') $m->bodyHTML($html);
      return (int) $m->send() > 0;
    } catch(\Throwable $e) {
      $this->wire('log')->save('native-forum', 'mail failed: ' . $e->getMessage());
      return false;
    }
  }

  /**
   * Frontend text overrides (Settings → Advanced → Translations): key => [label, description, default text].
   */
  public function trFieldDefs(): array {
    return [
      'pinned_topics' => [$this->_('Pinned topics'), $this->_('Heading shown above pinned topics list.'), $this->_('Pinned topics')],
      'account' => [$this->_('Account'), $this->_('Label for account/profile menu/link.'), $this->_('Account')],
      'search' => [$this->_('Search'), $this->_('Generic search label (buttons, headings).'), $this->_('Search')],
      'pm_new_one' => [$this->_('New PM notice (one)'), $this->_('Shown in the account box, title bar and popup when one private message is unread.'), $this->_('1 new private message')],
      'pm_new_many' => [$this->_('New PM notice (several)'), $this->_('Same, for several unread messages. {n} is replaced with the number.'), $this->_('{n} new private messages')],
      'pm_new_short' => [$this->_('New PM badge word'), $this->_('Short word next to the unread count in the title bar.'), $this->_('new')],
      'pm_latest_from' => [$this->_('New PM: latest from'), $this->_('Account box line under the notice, followed by the sender name.'), $this->_('Latest from')],
      'pm_click_to_read' => [$this->_('New PM: click to read'), $this->_('Account box line when the sender is unknown.'), $this->_('Click to read')],
      'pm_from' => [$this->_('New PM popup: from'), $this->_('Popup line, followed by the sender name and subject.'), $this->_('From')],
      'pm_read' => [$this->_('New PM popup: read button'), '', $this->_('Read')],
      'pm_later' => [$this->_('New PM popup: dismiss button'), '', $this->_('Later')],
      'search_placeholder' => [$this->_('Search placeholder'), $this->_('Placeholder text inside search input.'), $this->_('Search Forums...')],
      'forum_statistics' => [$this->_('Forum statistics'), $this->_('Sidebar widget title for statistics.'), $this->_('Forum statistics')],
      'latest_topics' => [$this->_('Latest topics'), $this->_('Sidebar widget title for latest topics.'), $this->_('Latest topics')],
      'newest_members' => [$this->_('Newest members'), $this->_('Sidebar widget title for newest members.'), $this->_('Newest members')],
      'quick_links' => [$this->_('Quick links'), $this->_('Sidebar widget title for quick links.'), $this->_('Quick links')],
      'new_topic' => [$this->_('New topic'), $this->_('Button label for creating a new topic.'), $this->_('New topic')],
      'start_new_topic' => [$this->_('Start new topic'), $this->_('Button label in toolbar for starting a new topic.'), $this->_('Start new topic')],
      'replies' => [$this->_('Replies'), $this->_('Column/label for replies count.'), $this->_('Replies')],
      'views' => [$this->_('Views'), $this->_('Column/label for views count.'), $this->_('Views')],
      'title' => [$this->_('Title'), $this->_('Form label for topic title.'), $this->_('Title')],
      'summary_optional' => [$this->_('Summary (optional)'), $this->_('Form label for optional summary.'), $this->_('Summary (optional)')],
      'message' => [$this->_('Message'), $this->_('Form label for message body.'), $this->_('Message')],
      'by' => [$this->_('by'), $this->_('Small label before author name.'), $this->_('by')],
      'across_forum' => [$this->_('Across the forum'), $this->_('Scope label for searching entire forum.'), $this->_('Across the forum')],
      'my_profile' => [$this->_('My profile'), $this->_('Link/label for profile page.'), $this->_('My profile')],
      'login' => [$this->_('Login'), $this->_('Login button/link label.'), $this->_('Login')],
      'logout' => [$this->_('Logout'), $this->_('Logout link label.'), $this->_('Logout')],
      'register' => [$this->_('Register'), $this->_('Register button/link label.'), $this->_('Register')],
      'joined' => [$this->_('joined'), $this->_('Label for user joined date.'), $this->_('joined')],
      'write_reply' => [$this->_('Write a reply'), $this->_('Heading/button label for reply editor.'), $this->_('Write a reply')],
      'drag_files_here' => [$this->_('Drag files here to attach'), $this->_('Uploader helper text.'), $this->_('Drag files here to attach, or')],
      'upload_tip' => [$this->_('Upload tip'), $this->_('Uploader helper tip text.'), $this->_('Tip: after upload, click Insert to place it at your cursor.')],
      'follow_topic' => [$this->_('Follow topic'), $this->_('Checkbox label for following a topic.'), $this->_('Follow topic (email me when someone replies)')],
      'edit' => [$this->_('Edit'), $this->_('Action label for editing post/topic.'), $this->_('Edit')],
      'share' => [$this->_('Share'), $this->_('Action label for share menu.'), $this->_('Share')],
      'report' => [$this->_('Report'), $this->_('Action label for reporting content.'), $this->_('Report')],
      'overview' => [$this->_('Overview'), $this->_('Profile tab label.'), $this->_('Overview')],
      'recent_posts' => [$this->_('Recent posts'), $this->_('Profile tab label.'), $this->_('Recent posts')],
      'activity' => [$this->_('Activity'), $this->_('Profile tab label.'), $this->_('Activity')],
      'private_messages' => [$this->_('Private messages'), $this->_('Profile tab label.'), $this->_('Private messages')],
      'settings' => [$this->_('Settings'), $this->_('Profile tab label.'), $this->_('Settings')],
      'followed_topics' => [$this->_('Followed topics'), $this->_('Profile tab + quick link label.'), $this->_('Followed topics')],
      'followed_none' => [$this->_('No followed topics'), $this->_('Shown when the member follows nothing.'), $this->_('You are not following any topics yet. Open a topic and switch on “Follow topic” to get notified about new replies.')],
      'followed_new' => [$this->_('Followed: new'), $this->_('Badge for unread replies, e.g. "3 new".'), $this->_('new')],
      'unfollow' => [$this->_('Unfollow'), $this->_('Button to stop following a topic.'), $this->_('Unfollow')],
      'followed_last' => [$this->_('Followed: last post'), $this->_('Column label.'), $this->_('Last post')],
      'username' => [$this->_('Username'), $this->_('Form label for username.'), $this->_('Username')],
      'signature' => [$this->_('Signature'), $this->_('Form label for signature.'), $this->_('Signature')],
      'forums' => [$this->_('Forums'), $this->_('Breadcrumb/home label.'), $this->_('Forums')],
      'board_index' => [$this->_('Board index'), $this->_('Breadcrumb label for index page.'), $this->_('Board index')],
      'search_results' => [$this->_('Search results'), $this->_('Heading for search results.'), $this->_('Search results')],
      'everywhere' => [$this->_('Everywhere'), $this->_('Search scope label.'), $this->_('Everywhere')],
      'this_category' => [$this->_('This Category'), $this->_('Search scope label for category context.'), $this->_('This Category')],
      'this_group' => [$this->_('This Group'), $this->_('Search scope label for group context.'), $this->_('This Group')],
      'this_forum' => [$this->_('This Forum'), $this->_('Search scope label for forum root context.'), $this->_('This Forum')],
      'topics' => [$this->_('Topics'), $this->_('Search scope label.'), $this->_('Topics')],
      'posts' => [$this->_('Posts'), $this->_('Search scope label.'), $this->_('Posts')],
      'categories' => [$this->_('Categories'), $this->_('Search scope label.'), $this->_('Categories')],
      'members' => [$this->_('Members'), $this->_('Search scope label.'), $this->_('Members')],
    ];
  }

  /**
   * Apply the admin's frontend text overrides to rendered forum markup. Templates call sfT() for
   * many strings; this catches every remaining place where the default English text is printed
   * (text nodes, placeholders, button values), so each translation works everywhere.
   */
  public function applyTextOverrides(string $html): string {
    $map = [];
    foreach($this->trFieldDefs() as $key => $def) {
      $raw = $this->get("tr__{$key}");
      if($raw === null || $raw === '' || $raw === []) continue;
      $val = $this->t($key, (string) $def[2]);
      $from = trim((string) $def[2]);
      if($from === '' || $val === '' || $val === $from) continue;
      $map[$from] = $val;
    }
    if(!$map) return $html;
    uksort($map, function($a, $b) { return mb_strlen($b) - mb_strlen($a); });
    // Protect scripts/styles
    $keep = [];
    $html = preg_replace_callback('~<(script|style|textarea)\b[^>]*>.*?</\1>~is', function($m) use (&$keep) {
      $k = "\x01SFK" . count($keep) . "\x01"; $keep[$k] = $m[0]; return $k;
    }, $html);
    $san = $this->wire('sanitizer');
    foreach($map as $from => $to) {
      $q = preg_quote($from, '~');
      $toE = $san->entities($to);
      // whole text node, or text node starting with the word ("by <a>…")
      $html = preg_replace('~(>\s*)' . $q . '(\s*<)~iu', '${1}' . str_replace(['\\','$'], ['\\\\','\\$'], $toE) . '${2}', $html);
      $html = preg_replace('~(>\s*)' . $q . '(\s+(?=[^<\s]))~u', '${1}' . str_replace(['\\','$'], ['\\\\','\\$'], $toE) . '${2}', $html);
      // attributes people see
      $html = preg_replace('~\b(placeholder|title|aria-label|value)=(["\'])' . $q . '\2~iu', '$1=$2' . str_replace(['\\','$'], ['\\\\','\\$'], $toE) . '$2', $html);
    }
    return strtr($html, $keep);
  }

  /**
   * Public display name for a user (nickname > name).
   */
  public function displayName(?User $u): string {
    if(!$u || !$u->id) return 'Guest';
    $n = '';
    try { if($u->hasField('forum_nickname')) $n = trim((string) $u->get('forum_nickname')); } catch(\Throwable $e) {}
    return $n !== '' ? $n : (string) $u->name;
  }

  /**
   * Upgrade hook: re-run installer and repair fieldtypes.
   */
  public function upgrade($fromVersion, $toVersion) {
    // Only ONE request may run the upgrade work. On busy sites (or hosts with CloudLinux
    // resource limits) several visitors hit the new version at the same moment; without a
    // lock every request ran the full upgrade in parallel.
    $lockFile = rtrim((string) $this->wire('config')->paths->cache, '/\\') . '/NativeForum-upgrade.lock';
    $lock = @fopen($lockFile, 'c');
    if(!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
      if($lock) @fclose($lock);
      return; // another request is upgrading right now
    }
    if(function_exists('ignore_user_abort')) @ignore_user_abort(true);
    if(function_exists('set_time_limit')) @set_time_limit(120);
    try {
      $this->runUpgradeTasks((int) $fromVersion, (int) $toVersion);
    } catch(\Throwable $e) {
      $this->wire('log')->save('native-forum', 'upgrade: ' . $e->getMessage());
    }
    @flock($lock, LOCK_UN);
    @fclose($lock);
  }

  protected function runUpgradeTasks(int $fromVersion, int $toVersion): void {
    $this->ensureReadTopicsField();
    $this->ensureUserCountryField();
    $this->___install();
    $this->fixKnownFields();
    $this->ensureMembersTemplateAndPage();
    $this->backfillAuthors();
    $this->ensureAutojoin();
    $this->ensureReportsTable();
    $this->adoptOldReports();
    $this->clearProCacheAll();
    // v59: until v56 the "Native Forum → Settings" screen showed these switches as OFF and
    // saved 0 on every save (checkbox→toggle conversion bug). Restore their defaults once.
    if((int) $fromVersion < 59) {
      $cfg = $this->wire('modules')->getConfig($this);
      $fixed = [];
      foreach(['allowRegistration', 'requireEmailVerification', 'showTopicSummaries', 'pmEmailNotify', 'seoMarkdown', 'seoLlmsTxt', 'seoOverrideTitle'] as $k) {
        if(array_key_exists($k, $cfg) && (string) $cfg[$k] === '0') { $cfg[$k] = 1; $fixed[] = $k; }
      }
      if($fixed) {
        $this->wire('modules')->saveConfig($this, $cfg);
        $this->message('NativeForum: re-enabled settings that an earlier version had switched off by mistake: ' . implode(', ', $fixed) . '. Please review them in the settings.');
      }
    }
    // v56: legacy iconSet=fontawesome -> iconProvider
    if((int) $fromVersion < 56) {
      $cfg = $this->wire('modules')->getConfig($this);
      if(($cfg['iconSet'] ?? '') === 'fontawesome' && (empty($cfg['iconProvider']) || $cfg['iconProvider'] === 'svg')) {
        $cfg['iconProvider'] = 'fa6';
        $this->wire('modules')->saveConfig($this, $cfg);
      }
    }
    // 1.0.4: topic following on for everyone (the "Followed topics" tab is part of every profile)
    if((int) $fromVersion < 104) {
      $cfg = $this->wire('modules')->getConfig($this);
      if(is_array($cfg) && isset($cfg['enableTopicFollow']) && !(int) $cfg['enableTopicFollow']) {
        $cfg['enableTopicFollow'] = 1;
        $this->wire('modules')->saveConfig($this, $cfg);
      }
    }
    // v73: preset "pekarna" renamed to "obsidian"
    if((int) $fromVersion < 73) {
      $cfg = $this->wire('modules')->getConfig($this);
      if(($cfg['designPreset'] ?? '') === 'pekarna') {
        $cfg['designPreset'] = 'obsidian';
        $this->wire('modules')->saveConfig($this, $cfg);
      }
    }
    // v54: the dark slate / crimson look (now "Obsidian") becomes the default preset
    if((int) $fromVersion < 54) {
      $cfg = $this->wire('modules')->getConfig($this);
      if(empty($cfg['designPreset']) || $cfg['designPreset'] === 'custom') {
        $cfg['designPreset'] = 'obsidian';
        $this->wire('modules')->saveConfig($this, $cfg);
      }
    }
  }

  /**
   * Small forum fields are loaded together with their page (one query instead of one per field
   * and page). Big or multi-value fields stay lazy.
   */
  protected function ensureAutojoin(): void {
    $names = ['forum_author', 'forum_views', 'forum_is_pinned', 'forum_is_locked', 'forum_tags', 'field_forum_summary_topic',
      'forum_vote_score', 'forum_is_deleted', 'forum_reply_to', 'forum_nickname', 'forum_rank', 'forum_post_count',
      'forum_rank_manual_override', 'forum_country', 'forum_is_read', 'forum_from_user', 'forum_to_user'];
    foreach($names as $n) {
      $f = $this->wire('fields')->get($n);
      if(!$f || !$f->id || ($f->flags & Field::flagAutojoin)) continue;
      try {
        $f->flags = $f->flags | Field::flagAutojoin;
        $this->wire('fields')->save($f);
      } catch(\Throwable $e) {}
    }
  }

  /**
   * Older builds could not store forum_author (page field rejected user pages).
   * Fill it from created_users_id so author display, ranks and profiles are correct.
   */
  protected function backfillAuthors(): void {
    // One SQL statement instead of loading + saving every page (which also fired all
    // page-save hooks and cache clears for each post on every upgrade).
    try {
      $f = $this->wire('fields')->get('forum_author');
      $tt = $this->sfTplId('forum-topic'); $rt = $this->sfTplId('forum-reply');
      if(!$f || !$f->id || (!$tt && !$rt)) return;
      $table = $f->getTable();
      $tpl = implode(',', array_filter([$tt, $rt]));
      $guestId = (int) $this->wire('config')->guestUserPageID;
      $userTpls = array_map('intval', (array) $this->wire('config')->userTemplateIDs);
      if(!$userTpls) $userTpls = [(int) $this->wire('templates')->get('user')->id];
      $sql = "INSERT INTO `$table` (pages_id, data) "
        . "SELECT p.id, p.created_users_id FROM pages p "
        . "JOIN pages u ON u.id=p.created_users_id AND u.templates_id IN (" . implode(',', $userTpls) . ") "
        . "LEFT JOIN `$table` a ON a.pages_id=p.id "
        . "WHERE p.templates_id IN ($tpl) AND a.pages_id IS NULL AND p.created_users_id<>$guestId";
      $n = $this->wire('database')->exec($sql);
      if($n) $this->wire('log')->save('native-forum', "backfillAuthors: $n posts");
    } catch(\Throwable $e) {
      $this->wire('log')->save('native-forum', 'backfillAuthors: ' . $e->getMessage());
    }
  }

  protected function randomName(): string {
    return strtolower(substr(bin2hex(random_bytes(8)), 0, 10));
  }

  /**
   * Never persist display-only/transient settings fields (export/import boxes, CSS preview,
   * one-shot tool actions). Their content was stored on every save and grew the config.
   */
  /** Measured preset values for Design Studio keys: ['simple|obsidian' => ['colors.bg' => '#...', ...]] */
  public function dsPresetValues(): array {
    static $cache = null;
    if($cache !== null) return $cache;
    $cache = [];
    $f = __DIR__ . '/assets/presets/ds-preset-values.json';
    if(is_file($f)) { $j = json_decode((string) @file_get_contents($f), true); if(is_array($j)) $cache = $j; }
    return $cache;
  }

  /** "theme|preset" key for the preset value table (unknown themes/presets fall back sensibly). */
  public function dsPresetKey(array $data): string {
    $th = (string)($data['frontendTheme'] ?? 'simple');
    if($th !== 'bootstrap') $th = 'simple';
    $pr = (string)($data['designPreset'] ?? 'obsidian');
    if($pr === 'pekarna' || $pr === '') $pr = 'obsidian';
    if(!in_array($pr, ['obsidian', 'light', 'dark', 'mono', 'auto', 'custom'], true)) $pr = 'obsidian';
    return "{$th}|{$pr}";
  }

  /** Normalise a Design Studio value for comparison (colour case/alpha, 0px, spacing). */
  public static function dsNorm($v): string {
    $v = strtolower(trim(preg_replace('/\s+/', ' ', (string) $v)));
    if(preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $v, $m)) $v = "#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}";
    if(preg_match('/^#[0-9a-f]{6}ff$/', $v)) $v = substr($v, 0, 7);
    $v = preg_replace('/(?<![\d.])0px\b/', '0', $v);
    return $v;
  }

  /** Drop Design Studio values that only repeat the selected preset (and template values that are not in use). */
  protected function dsStripPresetEqual(array $data): array {
    $all = $this->dsPresetValues();
    if(!$all) return $data;
    $pv = $all[$this->dsPresetKey($data)] ?? [];
    $effective = [];
    foreach($data as $k => $v) {
      if(!is_string($k) || strpos($k, 'ds_global_') !== 0 || is_array($v)) continue;
      $rest = substr($k, 10);
      $p = strpos($rest, '_'); if($p === false) continue;
      $dk = substr($rest, 0, $p) . '.' . substr($rest, $p + 1);
      if(!isset($pv[$dk])) continue;
      if(trim((string) $v) !== '' && self::dsNorm($v) === self::dsNorm($pv[$dk])) unset($data[$k]);
    }
    foreach($data as $k => $v) {
      if(!is_string($k) || strpos($k, 'ds_') !== 0 || strpos($k, 'ds_global_') === 0 || is_array($v) || substr($k, -10) === '__override') continue;
      if(!preg_match('/^ds_(forum-[a-z-]+)_([a-z]+)_(\w+)$/', $k, $m)) continue;
      $ov = (int)($data[$k . '__override'] ?? 0);
      $g = (string)($data["ds_global_{$m[2]}_{$m[3]}"] ?? '');
      if(trim($g) === '') $g = (string)($pv["{$m[2]}.{$m[3]}"] ?? '');
      if(!$ov || (trim((string) $v) !== '' && self::dsNorm($v) === self::dsNorm($g))) unset($data[$k]);
    }
    return $data;
  }

  public function hookBeforeSaveConfig(HookEvent $event) {
    $m = $event->arguments(0);
    $name = is_object($m) ? $m->className() : (string) $m;
    if($name !== 'NativeForum') return;
    $data = $event->arguments(1);
    if(!is_array($data)) return;
    foreach(['sf_export_settings', 'sf_export_settings_full', 'sf_import_json', 'dsCssPreview', 'sf_sync_mode', 'sf_sync_selected'] as $k) unset($data[$k]);
    $data = $this->dsStripPresetEqual($data);
    // internal state that is not part of the settings form: keep it when a settings form is saved
    $cur = null;
    foreach(['demoContent', 'demoChoice'] as $k) {
      if(array_key_exists($k, $data)) continue;
      if($cur === null) { $cur = $this->wire('modules')->getConfig('NativeForum'); if(!is_array($cur)) $cur = []; }
      if(array_key_exists($k, $cur)) $data[$k] = $cur[$k];
    }
    $event->arguments(1, $data);
  }

  function hookAfterSaveConfig(\ProcessWire\HookEvent $event): void {
      // Prevent recursion when we saveConfig() from inside this hook.
      if($this->sfApplyingTreePreset) return;
      $modules = $this->wire('modules');
      $savedModule = $event->arguments(0);
      if(!$savedModule) return;

      // Only react to this module's config saves
      // Modules::saveConfig() may pass a Module instance OR a string class name.
      if(is_string($savedModule)) {
        // Examples seen in the wild: 'NativeForum', 'NativeForum.module'
        $n = trim($savedModule);
        if($n !== 'NativeForum' && $n !== 'NativeForum.module.php' && $n !== 'NativeForum.module') return;
      } else {
        $savedName = is_object($savedModule) && method_exists($savedModule, 'className') ? $savedModule->className() : (is_object($savedModule) ? get_class($savedModule) : '');
        if($savedModule !== $this && $savedName !== 'NativeForum') return;
      }

      try { $this->wire('cache')->deleteFor('NativeForumView'); } catch(\Throwable $e) {}
      $this->clearProCacheAll();
      $data = (array) $event->arguments(1);
      $preset = (string)($data['treePreset'] ?? 'classic');

      // Current saved config (used both for tree preset and template-field application)
      $current = (array) $modules->getConfig($this);

      // Apply tree preset only when it actually changed, but DO NOT return early,
      // because other settings (like template custom fields) still need to apply.
      $applied = (string)($current['treePresetApplied'] ?? '');
      if($applied !== $preset) {
        try {
          $this->applyTreePreset($preset);
          $current['treePresetApplied'] = $preset;
          // store last run result for visibility
          $current['treePresetLastResult'] = 'ok';
          $current['treePresetLastError'] = '';
          $this->sfApplyingTreePreset = true;
          $modules->saveConfig($this, $current);
          $this->sfApplyingTreePreset = false;

          // Admin UI notification
          $this->message("NativeForum: Forum tree layout applied successfully ({$preset}).");
        } catch(\Throwable $e) {
          $msg = $e->getMessage();
          $this->wire('log')->save('nativeforum', 'Tree preset apply failed: ' . $msg);

          $current['treePresetLastResult'] = 'fail';
          $current['treePresetLastError'] = $msg;
          try {
            $this->sfApplyingTreePreset = true;
            $modules->saveConfig($this, $current);
            $this->sfApplyingTreePreset = false;
          } catch(\Throwable $e2) {
            $this->sfApplyingTreePreset = false;
          }

          $this->error("NativeForum: Forum tree layout apply failed ({$preset}). " . $msg);
        }
      }
    

      // Apply custom fields to forum templates (global + per-template)
      try {
        $stats = $this->applyTemplateCustomFields($data);
        if((int)$stats['templatesTouched'] > 0 || (int)$stats['fieldsAdded'] > 0) {
          $msg = "NativeForum: Template fields applied ({$stats['fieldsAdded']} field additions across {$stats['templatesTouched']} templates).";
          if(!empty($stats['missingFields'])) $msg .= " Missing fields: " . implode(', ', $stats['missingFields']) . ".";
          if(!empty($stats['missingTemplates'])) $msg .= " Missing templates: " . implode(', ', $stats['missingTemplates']) . ".";
          $this->message($msg);
        } else {
          // Only show a lightweight note if user configured fields but nothing changed
          $hasAny = !empty($data['tplFieldsGlobal']);
          foreach($this->sfForumTemplateNames() as $tn) {
            $k = 'tplFields_' . str_replace('-', '_', $tn);
            if(!empty($data[$k])) { $hasAny = true; break; }
          }
          if($hasAny) $this->message("NativeForum: Template fields already up to date.");
        }
      } catch(\Throwable $e) {
        $this->error("NativeForum: Template fields apply failed: " . $e->getMessage());
      }

}

  /**
   * Ensure a moved system page has a stable child name (and optionally title) under a given parent.
   * This keeps URLs predictable when the user applies a tree preset.
   */
  protected function ensureChildName(Page $parent, ?Page $child, string $desiredName, string $desiredTitle = ''): void {
    if(!$child || !$child->id || !$parent || !$parent->id) return;

    $pages = $this->wire('pages');
    $sanitizer = $this->wire('sanitizer');

    // If not under the intended parent yet, move it first.
    if((int)$child->parent_id !== (int)$parent->id) {
      $child->of(false);
      $child->parent = $parent;
      $this->sfSavePageSafe($child);
      $child->of(true);
    }

    $safeName = $sanitizer->pageName($desiredName, true);
    if(!$safeName) $safeName = $child->name;

    // If another child already uses the desired name, don't force a rename.
    $conflict = $parent->child("name={$safeName}, include=all");
    $hasConflict = ($conflict && $conflict->id && (int)$conflict->id !== (int)$child->id);

    $changed = false;
    $child->of(false);

    if(!$hasConflict && $safeName && $child->name !== $safeName) {
      $child->name = $safeName;
      $changed = true;
    }

    if($desiredTitle !== '' && trim((string)$child->title) === '') {
      $child->title = $desiredTitle;
      $changed = true;
    }

    if($changed) {
      $this->sfSavePageSafe($child);
    }
    $child->of(true);
  }
  /**
   * Ensure the forum-members template and at least one members page exist.
   * Older installs or custom trees may be missing it; tree presets rely on it.
   */
  protected function ensureMembersTemplateAndPage(): void {
    $templates   = $this->wire('templates');
    $pages       = $this->wire('pages');
    $fieldgroups = $this->wire('fieldgroups');

    try {
      $tpl = $templates->get('forum-members');
      if(!$tpl || !$tpl->id) {
        // Create a minimal fieldgroup for the template if needed
        $fg = $fieldgroups->get('forum-members');
        if(!$fg || !$fg->id) {
          $fg = new Fieldgroup();
          $fg->name = 'forum-members';
          $fg->save();
        }
        $tpl = new Template();
        $tpl->name = 'forum-members';
        $tpl->label = 'Forum members';
        $tpl->fieldgroup = $fg;
        // members page doesn't need children
        $tpl->noChildren = 1;
        $tpl->save();
      }

      // Template needs a title field (otherwise the page title is silently dropped)
      $titleField = $this->wire('fields')->get('title');
      if($titleField && !$tpl->fieldgroup->hasField($titleField)) {
        $tpl->fieldgroup->add($titleField);
        $tpl->fieldgroup->save();
      }

      // Ensure there is a members page somewhere
      $membersPage = $pages->get("template=forum-members, include=all");
      if($membersPage && $membersPage->id && trim((string) $membersPage->title) === '') {
        $membersPage->of(false);
        $membersPage->title = 'Members';
        $pages->save($membersPage, ['ignorePermissions' => true, 'quiet' => true]);
      }
      if(!$membersPage || !$membersPage->id) {
        $home = $pages->get('/');
        $p = new Page();
        $p->template = $tpl;
        $p->parent = $home;
        $p->name = 'forum-members';
        $p->title = 'Members';
        // save with ignore permissions to avoid admin config context issues
        $pages->save($p, ['ignorePermissions' => true]);
      }
    } catch(\Throwable $e) {
      // Non-fatal: if creation isn't possible on this site, presets can still move other pages.
      return;
    }
  }

  /**
   * Forum template names used by this module (for template field attachment, etc.)
   */
  protected function sfForumTemplateNames(): array {
    // Keep in sync with installer / template-files
    return [
      'forum-root',
      'forum-group',
      'forum-category',
      'forum-topic',
      'forum-reply',
      'forum-members',
      'forum-user',
      'forum-profile',
      'forum-login',
      'forum-register',
      'forum-message',
      'forum-messages-root',
    ];
  }

  /**
   * List PHP template files shipped with the module (template-files/*.php)
   *
   * @return string[] Basenames, e.g. ['forum-root.php', ...]
   */
  protected function sfListTemplateFiles(): array {
    // Use __DIR__ rather than $this->path because some PW contexts may not populate $this->path.
    $dir = rtrim(__DIR__, '/').'/template-files/';
    if(!is_dir($dir)) return [];
    $out = [];
    foreach(glob($dir . '*.php') as $f) {
      $bn = basename($f);
      if($bn) $out[] = $bn;
    }
    sort($out);
    return $out;
  }

  /**
   * Sync module template-files into /site/templates.
   *
   * Modes:
   * - copy_missing: copy only files that do not exist in /site/templates
   * - overwrite_selected: overwrite only selected basenames
   * - overwrite_all: overwrite all forum template basenames
   *
   * @param string $mode
   * @param string[] $selected
   * @return array{copied:int,skipped:int,errors:int,errorList:array}
   */
  protected function sfSyncTemplateFiles(string $mode, array $selected = []): array {
    $srcDir = rtrim(__DIR__, '/').'/template-files/';
    $dstDir = rtrim((string)$this->wire('config')->paths->templates, '/').'/';
    $filesApi = $this->wire('files');

    $res = ['copied'=>0,'skipped'=>0,'errors'=>0,'errorList'=>[]];
    if(!is_dir($srcDir)) {
      $res['errors']++;
      $res['errorList'][] = 'Module template-files directory not found: ' . $srcDir;
      return $res;
    }
    if(!is_dir($dstDir)) {
      $res['errors']++;
      $res['errorList'][] = 'Site templates directory not found: ' . $dstDir;
      return $res;
    }

    $allowed = $this->sfListTemplateFiles();
    $toProcess = [];
    if($mode === 'overwrite_all') {
      $toProcess = $allowed;
    } else if($mode === 'overwrite_selected') {
      $sel = array_values(array_filter(array_map('strval', $selected)));
      $toProcess = array_values(array_intersect($allowed, $sel));
    } else {
      // copy_missing
      $toProcess = $allowed;
    }

    foreach($toProcess as $bn) {
      $src = $srcDir . $bn;
      $dst = $dstDir . $bn;
      if(!is_file($src)) {
        $res['errors']++;
        $res['errorList'][] = "Source template missing: {$bn}";
        continue;
      }

      if($mode === 'copy_missing' && is_file($dst)) {
        $res['skipped']++;
        continue;
      }
      if($mode === 'overwrite_selected' && !in_array($bn, $selected, true)) {
        $res['skipped']++;
        continue;
      }

      try {
        // Prefer PW Files API if available (handles perms more consistently)
        if($filesApi && method_exists($filesApi, 'copy')) {
          $ok = (bool) $filesApi->copy($src, $dst);
        } else {
          $ok = @copy($src, $dst);
        }
        if(!$ok) {
          $res['errors']++;
          $res['errorList'][] = "Failed to copy {$bn} to /site/templates";
        } else {
          $res['copied']++;
        }
      } catch(\Throwable $e) {
        $res['errors']++;
        $res['errorList'][] = "Error copying {$bn}: " . $e->getMessage();
      }
    }

    return $res;
  }

  /**
   * Auto-sync a minimal set of template files when we detect that /site/templates contains
   * an older drifted copy that is missing key UI markers (summaries, search scope menus, page headers).
   *
   * We only overwrite files that look like NativeForum templates (contain "NativeForum Template")
   * and are missing expected markers.
   */
  protected function sf_autoSyncTemplatesIfMissingMarkers(): void {
    try {
      $cfg = $this->wire('config');
      $dstDir = rtrim((string)$cfg->paths->templates, '/').'/';
      $candidates = [
        'forum-layout.php'  => ['sf_csearch_form_html'],
        'forum-root.php'    => ['sf-index-summary'],
        'forum-group.php'   => ['sf-index-summary'],
        'forum-category.php'=> ['sf-pagehead-summary','sf_csearch_form_html','sf-csearch'],
        'forum-topic.php'   => ['sf-pagehead-summary','sf_csearch_form_html','sf-csearch'],
        'forum-reply.php'   => ['sf-pagehead-summary'],
      ];

      $toOverwrite = [];
      foreach($candidates as $file => $markers) {
        $path = $dstDir . $file;
        if(!is_file($path) || !is_readable($path)) continue;
        $txt = (string)@file_get_contents($path);
        if($txt === '') continue;
        // Only touch templates that appear to be NativeForum templates
        if(stripos($txt, 'NativeForum Template') === false) continue;
        $missing = false;
        foreach($markers as $m) {
          if(stripos($txt, $m) === false) { $missing = true; break; }
        }
        if($missing) $toOverwrite[] = $file;
      }

      if(!count($toOverwrite)) return;
      // Backup and overwrite selected files
      $this->sfSyncTemplateFiles('overwrite_selected', $toOverwrite);
    } catch(\Throwable $e) {
      // Silent: never break forum rendering because of auto-sync
      return;
    }
  }

  /**
   * Apply custom field selections to forum templates (global + per-template).
   * Returns stats: ['templatesTouched'=>int,'fieldsAdded'=>int,'missingFields'=>array,'missingTemplates'=>array]
   */
  protected function applyTemplateCustomFields(array $data): array {
    $stats = [
      'templatesTouched' => 0,
      'fieldsAdded' => 0,
      'missingFields' => [],
      'missingTemplates' => [],
    ];

    $templates = $this->wire('templates');
    $fieldsApi = $this->wire('fields');
    $fieldgroups = $this->wire('fieldgroups');
    if(!$templates || !$fieldsApi) return $stats;

    // Normalize selection value to array of field names
    $norm = function($v): array {
      if(is_array($v)) return array_values(array_filter(array_map('strval', $v)));
      if(is_string($v)) {
        $v = trim($v);
        if($v === '') return [];
        // Support CSV / pipe values
        $parts = preg_split('/[\s,|]+/', $v);
        return array_values(array_filter(array_map('strval', $parts)));
      }
      return [];
    };

    $globalFields = $norm($data['tplFieldsGlobal'] ?? []);

    foreach($this->sfForumTemplateNames() as $tplName) {
      $tpl = $templates->get($tplName);
      if(!$tpl || !$tpl->id) { $stats['missingTemplates'][] = $tplName; continue; }

      $key = 'tplFields_' . str_replace('-', '_', $tplName);
      $spec = $norm($data[$key] ?? []);
      $wanted = array_values(array_unique(array_filter(array_merge($globalFields, $spec))));

      if(!count($wanted)) continue;

      $fg = $tpl->fieldgroup;
      if(!$fg) { $stats['missingTemplates'][] = $tplName; continue; }

      $addedAny = false;
      foreach($wanted as $fname) {
        if(!$fname) continue;
        $f = $fieldsApi->get($fname);
        if(!$f || !$f->id) { $stats['missingFields'][] = $fname; continue; }
        if($fg->hasField($f)) continue;
        $fg->add($f);
        $stats['fieldsAdded']++;
        $addedAny = true;
      }

      if($addedAny) {
        try {
          // Save fieldgroup changes (works across PW versions)
          if($fieldgroups && method_exists($fieldgroups, 'save')) {
            // Some PW versions require saving via Fieldgroups API
            $fieldgroups->save($fg);
          } else {
            $fg->save();
          }
          // Also save the template to ensure cache/compiled field lists update
          try { $templates->save($tpl); } catch(\Throwable $e2) { /* ignore */ }
          $stats['templatesTouched']++;
        } catch(\Throwable $e) {
          // Collect a helpful error for admin UI
          $this->wire('log')->save('nativeforum', 'Template fields save failed for ' . $tplName . ': ' . $e->getMessage());
        }
      }
    }

    $stats['missingFields'] = array_values(array_unique($stats['missingFields']));
    $stats['missingTemplates'] = array_values(array_unique($stats['missingTemplates']));
    return $stats;
  }





  /**
   * Save a page while being compatible across ProcessWire versions.
   * Tries Pages::save() with options when supported, otherwise falls back to Page::save().
   */
  protected function sfSavePageSafe(Page $p): void {
    $pages = $this->wire('pages');
    try {
      // Newer PW: Pages::save($page, $options)
      $pages->save($p, ['ignorePermissions' => true, 'quiet' => true]);
      return;
    } catch(\TypeError $e) {
      // Older PW: no options argument
    } catch(\Throwable $e) {
      // Try fallback below
    }
    try {
      $p->save();
    } catch(\Throwable $e2) {
      // Re-throw with a clearer message for admin notification
      throw new WireException('Could not save/move page "' . $p->title . '" (#' . (int)$p->id . '): ' . $e2->getMessage());
    }
  }

  function applyTreePreset(string $preset): void {
      $pages = $this->wire('pages');
      $templates = $this->wire('templates');
      $modules = $this->wire('modules');

      $home = $pages->get(1);
      $forumRoot = $this->forumRoot();
      if(!$forumRoot || !$forumRoot->id) return;

      // Ensure core PagePathHistory installed (recommended for redirects on moves)
      try {
        if(!$modules->isInstalled('PagePathHistory')) {
          $modules->install('PagePathHistory');
        }
      } catch(\Throwable $e) {
        // ignore if not possible
      }

      // Ensure required templates/pages exist (older builds may miss forum-members)
      $this->ensureMembersTemplateAndPage();

      // Allow system pages under forum-root template (otherwise PW may block move)
      try {
        $tplRoot = $templates->get('forum-root');
        if($tplRoot && $tplRoot->id) {
          $child = $tplRoot->get('childTemplates');
          if(!is_array($child)) $child = [];
          $need = ['forum-group','forum-category','forum-messages-root','forum-profile','forum-login','forum-register','forum-user','forum-members'];
          $ids = [];
          foreach($need as $tn) {
            $t = $templates->get($tn);
            if($t && $t->id) $ids[] = (int)$t->id;
          }
          $new = array_values(array_unique(array_merge($child, $ids)));
          $tplRoot->set('childTemplates', $new);
          $templates->save($tplRoot);
        }
      } catch(\Throwable $e) { /* ignore */ }

      // Find system pages
      $msgRoot = $pages->get("template=forum-messages-root, include=all");
      if(!$msgRoot || !$msgRoot->id) $msgRoot = $pages->get('/messages/');

      $profilePage  = $pages->get("template=forum-profile, include=all");
      $loginPage    = $pages->get("template=forum-login, include=all");
      $registerPage = $pages->get("template=forum-register, include=all");
      $userRoot     = $pages->get("template=forum-user, include=all");
      $membersPage  = $pages->get("template=forum-members, include=all");

      $accountParent = $home;
      $usersParent   = $home;
      $msgParent     = $home;

      if($preset === 'singleRoot') {
        $accountParent = $forumRoot;
        $usersParent   = $forumRoot;
        $msgParent     = $forumRoot;
      } elseif($preset === 'singleRootSplitMessages') {
        $accountParent = $forumRoot;
        $usersParent   = $forumRoot;
        $msgParent     = $home;
      } elseif($preset === 'forumRootAccountsOnly') {
        $accountParent = $forumRoot;
        $usersParent   = $home;
        $msgParent     = $home;
      } elseif($preset === 'forumRootUsersOnly') {
        $accountParent = $home;
        $usersParent   = $forumRoot;
        $msgParent     = $home;
      } elseif($preset === 'forumRootMessagingOnly') {
        $accountParent = $home;
        $usersParent   = $home;
        $msgParent     = $forumRoot;
      } else {
        $accountParent = $home;
        $usersParent   = $home;
        $msgParent     = $home;
      }

      $move = function($p, $parent) {
        if(!$p || !$p->id || !$parent || !$parent->id) return;
        if((int)$p->parent_id === (int)$parent->id) return;
        $p->of(false);
        $p->parent = $parent;
        // Config saves may run under a user that cannot move pages; force-save when possible.
        $this->sfSavePageSafe($p);
        $p->of(true);
      };

      $move($profilePage,  $accountParent);
      $move($loginPage,    $accountParent);
      $move($registerPage, $accountParent);

      $move($userRoot,     $usersParent);
      $move($membersPage,  $usersParent);

      $move($msgRoot,      $msgParent);

      // Under presets that place pages under /forum, enforce stable names to match expectations
      if((int)$accountParent->id === (int)$forumRoot->id || (int)$usersParent->id === (int)$forumRoot->id || (int)$msgParent->id === (int)$forumRoot->id) {
        $this->ensureChildName($accountParent, $profilePage,  'forum-profile',  'Forum profile');
        $this->ensureChildName($accountParent, $loginPage,    'forum-login',    'Forum login');
        $this->ensureChildName($accountParent, $registerPage, 'forum-register', 'Forum registration');
        $this->ensureChildName($usersParent, $userRoot,     'forum-user',     'User');
        $this->ensureChildName($usersParent, $membersPage,  'forum-members',  'Members');
        if($preset === 'singleRoot') $this->ensureChildName($msgParent, $msgRoot, 'messages', 'Messages');
      }
    }

  /**
   * Translation helper (A+B approach)
   * - If a translation override is set in module settings (Translations tab), use it.
   * - Otherwise fall back to ProcessWire's language translator via __().
   *
   * @param string $key
   * @param string $default Default English text
   * @return string
   */
  public function t(string $key, string $default): string {
    $raw = $this->get("tr__{$key}");
    // If multilingual values are stored as arrays keyed by language ID
    if(is_array($raw)) {
      $langId = 0;
      $u = wire('user');
      if($u && isset($u->language) && is_object($u->language) && isset($u->language->id)) {
        $langId = (int) $u->language->id;
      }
      $val = '';
      if($langId && isset($raw[$langId])) $val = trim((string) $raw[$langId]);
      if($val === '' && isset($raw[0])) $val = trim((string) $raw[0]);
      if($val !== '') return $val;
      return __($default);
    }

    $val = trim((string) $raw);
    if($val !== '') return $val;
    return __($default);
  }

}