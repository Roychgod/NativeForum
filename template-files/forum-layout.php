<?php namespace ProcessWire;

/**
 * Translation helper (A+B):
 * - If a translation override is set in module settings (Translations tab), use it.
 * - Otherwise fall back to ProcessWire Language Translator via __().
 */
if(!function_exists('sfT')) {
  function sfT(string $key, string $default): string {
    static $sf = null;
    if($sf === null) {
      try { $sf = wire('modules')->get('NativeForum'); } catch(\Exception $e) { $sf = false; }
    }
    $val = null;
    if($sf) {
      try { $val = $sf->get('tr__' . $key); } catch(\Exception $e) { $val = null; }
    }
    // Multi-language values (InputfieldTextLanguage returns array keyed by language id)
    if(is_array($val)) {
      $lang = wire('user')->language;
      $id = $lang ? (int)$lang->id : 0;
      if($id && isset($val[$id]) && is_string($val[$id]) && trim($val[$id]) !== '') return (string)$val[$id];
      // fallback: first non-empty
      foreach($val as $vv) {
        if(is_string($vv) && trim($vv) !== '') return (string)$vv;
      }
    } elseif(is_string($val) && trim($val) !== '') {
      return (string)$val;
    }
    return __($default);
  }
}



/**
 * Parse user-entered tags (e.g. "#processwire, module help") into normalized slugs.
 * Returns an array of tag slugs (lowercase, [a-z0-9_-]).
 */
if(!function_exists('sf_parse_tags_string')) {
  /**
   * Parse tags from user input.
   * Rule: each '#' starts a new tag; the tag continues (spaces allowed) until the next '#', or end of string.
   * Examples:
   *   "#My new tag #This is my second tag" => ["my-new-tag", "this-is-my-second-tag"]
   *   "tag one, tag two" => ["tag-one", "tag-two"]  (fallback without '#')
   *
   * Stored form: slugs (lowercase, [a-z0-9_-] with dashes for spaces).
   */
  function sf_parse_tags_string(string $raw, int $maxTags = 12): array {
    $raw = trim((string) $raw);
    if($raw === '') return [];

    // normalize whitespace
    $raw = preg_replace('/[\r\n\t]+/u', ' ', $raw);

    $tags = [];

    // Hashtag phrase mode
    if(strpos($raw, '#') !== false) {
      if(preg_match_all('/#([^#]+)/u', $raw, $m)) {
        foreach($m[1] as $chunk) {
          $t = trim($chunk);
          if($t !== '') $tags[] = $t;
        }
      }
    } else {
      // Fallback: comma-separated tags (no '#')
      foreach(preg_split('/\s*,\s*/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
        $t = trim($chunk);
        if($t !== '') $tags[] = $t;
      }
    }

    $out = [];
    foreach($tags as $t) {
      $t = preg_replace('/\s+/u', ' ', trim($t));
      $t = ltrim($t, "#@ 	");

      // keep letters/numbers/spaces/dash/underscore; strip other punctuation
      $t = preg_replace('/[^\p{L}\p{N}\s\-_]+/u', '', $t);
      $t = preg_replace('/\s+/u', ' ', trim($t));
      if($t === '') continue;

      // slugify
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
}


/**
 * Normalize CSS size values. If a pure number is given, append "px".
 * Accepts values like "12", "12px", "1rem", "10%", "8px 12px".
 */
if(!function_exists('sf_css_size')) {
  function sf_css_size($val, $fallback = '') {
    $val = trim((string)$val);
    if($val === '') return $fallback;
    $parts = preg_split('/\s+/', $val);
    $out = [];
    foreach($parts as $p) {
      $p = trim($p);
      if($p === '') continue;
      if(preg_match('/^\d+(?:\.\d+)?$/', $p)) $p .= 'px';
      $out[] = $p;
    }
    return $out ? implode(' ', $out) : $fallback;
  }
}

// --- NativeForum helper: "Followed topics" tab (My profile + own public profile, every preset/theme)
if(!function_exists(__NAMESPACE__ . '\\sf_followed_handle')) {
  /** Unfollow button of the tab (POST sf_unfollow); redirects back to $backUrl. */
  function sf_followed_handle($forum, User $user, string $backUrl): void {
    $input = wire('input'); $session = wire('session');
    if(!$input->post('sf_unfollow') || !$user->isLoggedin() || !$user->hasField('forum_followed_topics')) return;
    try { $session->CSRF->validate('sfunfollow'); } catch(\Throwable $e) { $session->redirect($backUrl, false); }
    $tid = (int) $input->post('sf_unfollow');
    $user->of(false);
    $list = $user->getUnformatted('forum_followed_topics');
    if($list instanceof PageArray && $list->has("id=$tid")) {
      $list->remove($list->get("id=$tid"));
      $user->set('forum_followed_topics', $list);
      $user->save('forum_followed_topics');
    }
    $session->redirect($backUrl, false);
  }
  /** Tab label incl. the "new replies" counter */
  function sf_followed_label($forum): string {
    $n = (int) (method_exists($forum, 'followedNewCount') ? $forum->followedNewCount(wire('user')) : 0);
    return wire('sanitizer')->entities(sfT('followed_topics', 'Followed topics')) . ($n ? " <span class='sf-badge sf-badge-new'>{$n}</span>" : '');
  }
  /** The list of followed topics with new replies, last post and Unfollow */
  function sf_followed_html($forum, User $user): string {
    $san = wire('sanitizer');
    $rows = method_exists($forum, 'followedTopics') ? $forum->followedTopics($user) : [];
    $out = '';
    if(method_exists($forum, 'topicFollowEnabled') && !$forum->topicFollowEnabled()) {
      $out .= "<p class='sf-muted-sm mb-2'>" . $san->entities(sfT('followed_off', 'Following new topics is currently turned off on this forum.')) . "</p>";
    }
    if(!count($rows)) {
      return $out . "<p class='sf-muted mb-0'>" . $san->entities(sfT('followed_none', 'You are not following any topics yet. Open a topic and switch on “Follow topic” to get notified about new replies.')) . "</p>";
    }
    $tok = wire('session')->CSRF->renderInput('sfunfollow');
    $out .= "<ul class='sf-followed'>";
    foreach($rows as $r) {
      $t = $r['topic'];
      $cat = $t->parent;
      $lastU = $r['last_id'] ? $forum->userById((int) ($forum->authorIds([$r['last_id']])[$r['last_id']] ?? 0)) : null;
      if(!$lastU) { $a = $t->getUnformatted('forum_author'); if($a instanceof PageArray) $a = $a->first(); if($a instanceof User) $lastU = $a; }
      $who = $lastU ? $san->entities($forum->displayName($lastU)) : '';
      $out .= "<li class='sf-followed-item" . ($r['new'] ? ' is-new' : '') . "'>"
        . "<div class='sf-followed-main'><a class='sf-followed-title' href='" . $san->entities($r['url']) . "'>" . $san->entities(sf_title_plain((string) $t->title)) . "</a>"
        . ($r['new'] ? " <span class='sf-badge sf-badge-new'>" . (int) $r['new'] . ' ' . $san->entities(sfT('followed_new', 'new')) . "</span>" : '')
        . "<div class='sf-muted-sm'>" . $san->entities(sf_title_plain((string) $cat->title)) . " · " . (int) $r['replies'] . " " . $san->entities(sfT('replies', 'Replies')) . "</div></div>"
        . "<div class='sf-followed-last sf-muted-sm'>" . $san->entities(sfT('followed_last', 'Last post')) . ": " . sf_format_dt((int) $r['last_created']) . ($who ? "<br>{$who}" : '') . "</div>"
        . "<form method='post' class='sf-followed-act'>{$tok}<button type='submit' name='sf_unfollow' value='" . (int) $t->id . "' class='btn btn-outline-secondary btn-sm sf-btn sf-btn-ghost sf-btn-sm'>" . $san->entities(sfT('unfollow', 'Unfollow')) . "</button></form>"
        . "</li>";
    }
    return $out . "</ul>";
  }
}

// --- NativeForum helper: decode possibly-encoded titles once (prevents &amp;amp; showing in UI)
if(!function_exists(__NAMESPACE__ . '\\sf_title_plain')) {
  function sf_title_plain($str) {
    if(!is_string($str)) return '';
    return html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }
}

/**
 * NativeForum helpers shared by all templates.
 * Marker: NativeForum Template
 */

// --- Safe helpers (must be defined at top, before any HTML output) ---

if(!function_exists(__NAMESPACE__ . '\\sf_sanitize_css_color')) {
  /**
   * Sanitize a CSS color-like value (hex/hex8, rgb/rgba, hsl/hsla, var(), keywords).
   * Prevents CSS injection by rejecting dangerous characters.
   */
  function sf_sanitize_css_color($value, string $fallback = ''): string {
    $v = trim((string) $value);
    if($v === '') return $fallback;
    if(preg_match('/[;{}<>]/', $v)) return $fallback;
    $kw = strtolower($v);
    if(in_array($kw, ['transparent','currentcolor','inherit','initial','unset'], true)) {
      return $kw === 'currentcolor' ? 'currentColor' : $kw;
    }
    if(preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v)) return $v;
    if(preg_match('/^(rgb|rgba|hsl|hsla)\([0-9a-zA-Z\s,\.\/%+-]+\)$/', $v)) return $v;
    if(preg_match('/^var\(--[A-Za-z0-9_-]+(\s*,\s*[^\)]+)?\)$/', $v)) return $v;
    return $fallback;
  }
}

// Module config helper (cached)
if(!function_exists(__NAMESPACE__ . '\\sf_cfg')) {
  function sf_cfg(string $key, $default = null) {
    static $cfg = null;
    if($cfg === null) {
      try {
        // Config may be stored under different module names depending on installation
        // (e.g. NativeForum vs ProcessNativeForum). Merge both with NativeForum taking precedence.
        $cfg1 = wire('modules')->getConfig('NativeForum');
        $cfg2 = wire('modules')->getConfig('ProcessNativeForum');
        if(!is_array($cfg1)) $cfg1 = [];
        if(!is_array($cfg2)) $cfg2 = [];
        $cfg = $cfg1 + $cfg2;
        // One source of truth for defaults: the module's default configuration
        if(method_exists(__NAMESPACE__ . '\\NativeForum', 'sfDefaultConfig')) $cfg = $cfg + NativeForum::sfDefaultConfig();
      } catch(\Throwable $e) {
        $cfg = [];
      }
    }
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
  }

  /**
   * Escape output but avoid double-encoding when stored titles already contain entities.
   */
  function sf_entities_once($value): string {
    $san = wire('sanitizer');
    // Some titles may end up double-encoded (e.g. "&amp;amp;").
    // Decode a couple of times until it stabilizes, then re-encode once.
    $plain = (string) $value;
    for($i = 0; $i < 3; $i++) {
      $dec = $san->unentities($plain);
      if($dec === $plain) break;
      $plain = $dec;
    }
    return $san->entities($plain);
  }
}

// Forum activity (online/viewing) tracking (lightweight, cache-based)
if(!function_exists('sf_activity_update')) {
  function sf_activity_update($topicId = 0) {
    $wire = wire();
    $cache = $wire->cache;
    $session = $wire->session;
    $user = $wire->user;

    $winMin = (int) sf_cfg('activityWindowMin', 10);
    if($winMin < 1) $winMin = 10;
    $ttl = $winMin * 60;

    $now = time();
    // Bots/crawlers are not "online visitors" (and must not cause writes)
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if($ua === '' || preg_match('~bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|headless|lighthouse~i', $ua)) return;
    // Throttle: at most one write per visitor and topic per minute (it is a DB write)
    $thKey = 'sf_act_' . (int) $topicId;
    try { if($session && ($now - (int) $session->get($thKey)) < 60) return; if($session) $session->set($thKey, $now); } catch(\Throwable $e) {}

    $sid = null;
    if(is_object($session) && method_exists($session, 'getId')) $sid = $session->getId();
    if(!$sid) $sid = session_id();

    // Online map
    $key = 'sf_online_map';
    $map = $cache->get($key);
    if(!is_array($map)) $map = ['g'=>[], 'u'=>[]];

    // prune
    foreach(['g','u'] as $k) {
      if(!isset($map[$k]) || !is_array($map[$k])) $map[$k] = [];
      foreach($map[$k] as $id => $ts) {
        if(!is_int($ts) || ($now - $ts) > $ttl) unset($map[$k][$id]);
      }
    }

    if($user && !$user->isGuest()) {
      $map['u'][(string)$user->id] = $now;
    } else {
      $map['g'][(string)$sid] = $now;
      if(count($map['g']) > 2000) $map['g'] = array_slice($map['g'], -2000, null, true);
    }
    $cache->save($key, $map, $ttl);

    // Topic viewing map
    if($topicId) {
      $tKey = 'sf_viewing_topic_' . (int)$topicId;
      $tMap = $cache->get($tKey);
      if(!is_array($tMap)) $tMap = ['g'=>[], 'u'=>[]];
      foreach(['g','u'] as $k) {
        if(!isset($tMap[$k]) || !is_array($tMap[$k])) $tMap[$k] = [];
        foreach($tMap[$k] as $id => $ts) {
          if(!is_int($ts) || ($now - $ts) > $ttl) unset($tMap[$k][$id]);
        }
      }
      if($user && !$user->isGuest()) $tMap['u'][(string)$user->id] = $now;
      else $tMap['g'][(string)$sid] = $now;
      $cache->save($tKey, $tMap, $ttl);
    }
  }
}

if(!function_exists('sf_activity_counts')) {
  function sf_activity_counts($topicId = 0) {
    $wire = wire();
    $cache = $wire->cache;
    $winMin = (int) sf_cfg('activityWindowMin', 10);
    if($winMin < 1) $winMin = 10;
    $ttl = $winMin * 60;
    $now = time();

    $map = $cache->get('sf_online_map');
    if(!is_array($map)) $map = ['g'=>[], 'u'=>[]];
    foreach(['g','u'] as $k) {
      if(!isset($map[$k]) || !is_array($map[$k])) $map[$k] = [];
      foreach($map[$k] as $id => $ts) {
        if(!is_int($ts) || ($now - $ts) > $ttl) unset($map[$k][$id]);
      }
    }
    $online = ['members'=>count($map['u']), 'guests'=>count($map['g'])];

    $view = null;
    if($topicId) {
      $tMap = $cache->get('sf_viewing_topic_' . (int)$topicId);
      if(!is_array($tMap)) $tMap = ['g'=>[], 'u'=>[]];
      foreach(['g','u'] as $k) {
        if(!isset($tMap[$k]) || !is_array($tMap[$k])) $tMap[$k] = [];
        foreach($tMap[$k] as $id => $ts) {
          if(!is_int($ts) || ($now - $ts) > $ttl) unset($tMap[$k][$id]);
        }
      }
      $view = ['members'=>count($tMap['u']), 'guests'=>count($tMap['g'])];
    }

    return [$online, $view];
  }
}


if(!function_exists('sf_activity_block_html')) {
  /**
   * Render the "Forum activity" block (online + viewing counts).
   * Placed at bottom like the Forum permissions block.
   */
  function sf_activity_block_html(int $topicId = 0): string {
    $san = wire('sanitizer');
    // update activity before counting
    sf_activity_update($topicId);
    list($online, $view) = sf_activity_counts($topicId);

    $title = sfT('forum_activity', 'Forum activity');
    $onlineLbl = sfT('currently_online', 'Currently online');
    $viewLbl   = sfT('currently_viewing', 'Currently viewing');

    $m = (int)($online['members'] ?? 0);
    $g = (int)($online['guests'] ?? 0);

    // Match the styling pattern used by the "Forum permissions" block (sf-card)
    $out  = "<div class='sf-card mt-4 sf-forum-activity'>";
    $out .= "<div class='sf-card-h'>" . $san->entities($title) . "</div>";
    $out .= "<div class='sf-card-b sf-muted-sm'>";

    $out .= "<div><strong>" . $san->entities($onlineLbl) . ":</strong> ";
    $out .= $m . " " . $san->entities(sfT('members', 'members')) . ", ";
    $out .= $g . " " . $san->entities(sfT('guests', 'guests')) . "</div>";

    if(is_array($view) && $topicId > 0) {
      $vm = (int)($view['members'] ?? 0);
      $vg = (int)($view['guests'] ?? 0);
      $out .= "<div class='mt-1'><strong>" . $san->entities($viewLbl) . ":</strong> ";
      $out .= $vm . " " . $san->entities(sfT('members', 'members')) . ", ";
      $out .= $vg . " " . $san->entities(sfT('guests', 'guests')) . "</div>";
    }

    $out .= "</div></div>";
    return $out;
  }
}



if(!function_exists(__NAMESPACE__ . '\\sf_ds_get')) {
  /**
   * Design Studio getter (flat config keys).
   * Reads global values and optional per-template overrides (override flag must be 1).
   */
  function sf_ds_get(string $tpl, string $section, string $key, $default = '') {
    $tpl = trim($tpl);
    $section = trim($section);
    $key = trim($key);
    if($tpl === '') $tpl = 'global';
    $base = "ds_{$tpl}_{$section}_{$key}";
    $overrideFlag = $base . "__override";

    // Global value
    $g = sf_cfg("ds_global_{$section}_{$key}", $default);

    if($tpl === 'global') return sf_cfg($base, $g);

    // If override is enabled, use template value, otherwise fall back to global
    $ov = (int) sf_cfg($overrideFlag, 0);
    if($ov) return sf_cfg($base, $g);
    return $g;
  }
}
if(!function_exists(__NAMESPACE__ . '\\sf_should_show_sidebar')) {
  function sf_should_show_sidebar(): bool {
    $mode = (string) sf_cfg('sidebarDisplayMode', 'always');
    if($mode === 'never') return false;
    if($mode === 'forum-root') {
      try { $p = wire('page'); return $p && $p->template && (string) $p->template->name === 'forum-root'; } catch(\Throwable $e) { return true; }
    }
    if($mode === 'custom') {
      $list = sf_cfg('sidebarShowTemplates', []);
      if(!is_array($list)) $list = [];
      try {
        $p = wire('page');
        $tpl = ($p && $p->template) ? (string)$p->template->name : '';
      } catch(\Throwable $e) { $tpl = ''; }
      return $tpl && in_array($tpl, $list, true);
    }
    return true;
  }
}

/**
 * Ensure core API vars exist even when this file is included from a function/closure.
 */
/** @var User $user */
$user = $user ?? wire('user');
/** @var Pages $pages */
$pages = $pages ?? wire('pages');
/** @var Config $config */
$config = $config ?? wire('config');
/** @var Sanitizer $sanitizer */
$sanitizer = $sanitizer ?? wire('sanitizer');
/** @var Input $input */
$input = $input ?? wire('input');
/** @var Session $session */
$session = $session ?? wire('session');

/**
 * NativeForum shared frontend layout helpers.
 * Marker: NativeForum Template
 */

// Upload endpoint is handled by NativeForum::hookBeforePageRender() (module).



if(!function_exists(__NAMESPACE__ . '\sf_registration_open')) {
  function sf_registration_open(): bool {
    $v = sf_cfg('allowRegistration', 1);
    return !($v === 0 || $v === '0');
  }
}


if(!function_exists(__NAMESPACE__ . '\sf_auth_dialogs')) {
  /**
   * Login / Register as modal dialogs (both themes). Native <dialog>: no Bootstrap JS needed.
   * Rendered inside .sf-wrap so the forum styles/preset apply. Links to the login/register
   * pages keep working without JavaScript.
   */
  function sf_auth_dialogs($forum): string {
    if(!empty($GLOBALS['sfAuthDialogsDone'])) return '';
    $user = wire('user');
    if(!$user || $user->isLoggedin()) return '';
    $GLOBALS['sfAuthDialogsDone'] = true;
    $pages = wire('pages'); $san = wire('sanitizer');
    $tpl = (string) (wire('page') ? wire('page')->template->name : '');
    if(in_array($tpl, ['forum-login', 'forum-register'], true)) return '';
    $lp = $pages->get("template=forum-login, include=all");
    $rp = $pages->get("template=forum-register, include=all");
    $loginUrl = ($lp && $lp->id) ? $lp->url : '/forum-login/';
    $regUrl = ($rp && $rp->id) ? $rp->url : '/forum-register/';
    $req = '';
    try { $req = (string) wire('input')->url(true); } catch(\Throwable $e) { $req = ''; }
    $ret = "<input type='hidden' name='return' value='" . $san->entities($req) . "'>";
    $x = "<button type='button' class='sf-dialog-x' data-sf-dialog-close aria-label='Close'>&times;</button>";
    $out = "<dialog class='sf-dialog' id='sf-dlg-login' aria-labelledby='sf-dlg-login-t'><div class='sf-dialog-box sf-card'>"
      . "<div class='sf-card-h sf-dialog-h'><h2 id='sf-dlg-login-t' class='sf-dialog-title'>" . $san->entities(sfT('login', 'Login')) . "</h2>{$x}</div>"
      . "<div class='sf-card-b'><form method='post' action='" . $san->entities($loginUrl) . "' class='sf-dialog-form'>"
      . $forum->antiSpamFields('login') . $ret
      . "<label class='sf-field'><span class='form-label'>" . $san->entities(sfT('username', 'Username')) . "</span><input class='form-control' type='text' name='name' autocomplete='username' required></label>"
      . "<label class='sf-field'><span class='form-label'>Password</span><input class='form-control' type='password' name='pass' autocomplete='current-password' required></label>"
      . "<div class='sf-dialog-actions'><button class='btn btn-primary' type='submit' name='login_submit' value='1'>" . $san->entities(sfT('login', 'Login')) . "</button>"
      . (sf_registration_open() ? "<a class='sf-dialog-switch' href='" . $san->entities($regUrl) . "' data-sf-auth='register'>No account? " . $san->entities(sfT('register', 'Register')) . "</a>" : '')
      . "</div></form></div></div></dialog>";
    if(sf_registration_open()) {
      $out .= "<dialog class='sf-dialog' id='sf-dlg-register' aria-labelledby='sf-dlg-register-t'><div class='sf-dialog-box sf-card'>"
        . "<div class='sf-card-h sf-dialog-h'><h2 id='sf-dlg-register-t' class='sf-dialog-title'>Create account</h2>{$x}</div>"
        . "<div class='sf-card-b'><form method='post' action='" . $san->entities($regUrl) . "' class='sf-dialog-form'>"
        . $forum->antiSpamFields('register') . $ret
        . "<label class='sf-field'><span class='form-label'>" . $san->entities(sfT('username', 'Username')) . "</span><input class='form-control' type='text' name='name' autocomplete='username' minlength='3' maxlength='40' pattern='[A-Za-z0-9_-]+' required></label>"
        . "<label class='sf-field'><span class='form-label'>Email</span><input class='form-control' type='email' name='email' autocomplete='email' required></label>"
        . "<div class='sf-field-row'><label class='sf-field'><span class='form-label'>Password</span><input class='form-control' type='password' name='pass1' autocomplete='new-password' minlength='8' required></label>"
        . "<label class='sf-field'><span class='form-label'>Repeat password</span><input class='form-control' type='password' name='pass2' autocomplete='new-password' minlength='8' required></label></div>"
        . "<div class='sf-dialog-actions'><button class='btn btn-primary' type='submit' name='register_submit' value='1'>Create account</button>"
        . "<a class='sf-dialog-switch' href='" . $san->entities($loginUrl) . "' data-sf-auth='login'>Already registered? " . $san->entities(sfT('login', 'Login')) . "</a>"
        . "</div></form></div></div></dialog>";
    }
    $out .= "<script>(function(){if(window.sfAuthDlg)return;window.sfAuthDlg=1;"
      . "function dlg(k){var d=document.getElementById('sf-dlg-'+k);return d&&typeof d.showModal==='function'?d:null;}"
      . "function open(k){var d=dlg(k);if(!d)return false;document.querySelectorAll('dialog.sf-dialog[open]').forEach(function(o){o.close();});d.showModal();var i=d.querySelector('input:not([type=hidden])');if(i)setTimeout(function(){i.focus();},30);return true;}"
      . "document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('a,button[data-sf-auth]');"
      . "if(e.target.closest&&e.target.closest('[data-sf-dialog-close]')){var c=e.target.closest('dialog');if(c)c.close();return;}"
      . "if(e.target.tagName==='DIALOG'&&e.target.classList.contains('sf-dialog')){var r=e.target.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)e.target.close();return;}"
      . "if(!a||!a.closest('.sf-wrap'))return;var k=a.getAttribute('data-sf-auth');var h=a.getAttribute('href')||'';"
      . "if(!k){if(h.indexOf(" . json_encode(rtrim($loginUrl, '/')) . ")===0)k='login';else if(h.indexOf(" . json_encode(rtrim($regUrl, '/')) . ")===0)k='register';}"
      . "if(k&&open(k)){e.preventDefault();e.stopPropagation();}},true);})();</script>";
    return $out;
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_page_summary')) {
  /**
   * Plain-text summary of a forum page (category/topic/group), optionally shortened.
   */
  function sf_page_summary($p, int $len = 0): string {
    if(!$p || !$p->id) return '';
    $tpl = (string) $p->template->name;
    $fields = $tpl === 'forum-topic'
      ? ['field_forum_summary_topic', 'summary', 'forum_summary']
      : ['field_forum_summary_category', 'summary', 'description', 'forum_summary', 'intro'];
    $txt = '';
    foreach($fields as $f) {
      if(!$p->hasField($f)) continue;
      $v = trim(html_entity_decode(strip_tags((string) $p->getUnformatted($f)), ENT_QUOTES, 'UTF-8'));
      if($v !== '') { $txt = preg_replace('/\s+/u', ' ', $v); break; }
    }
    if($len > 0 && mb_strlen($txt) > $len) {
      $cut = mb_substr($txt, 0, $len);
      $sp = mb_strrpos($cut, ' ');
      $txt = rtrim($sp > $len * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:-") . '…';
    }
    return $txt;
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_show_summaries')) {
  function sf_show_summaries(): bool {
    $m = wire('modules')->get('NativeForum');
    $v = $m ? $m->get('showTopicSummaries') : null;
    return ($v === null || $v === '') ? true : ((int) $v === 1);
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_render_post_html')) {
  /**
   * Render post/reply body as safe HTML (or plain text) based on module settings.
   * Also sanitizes older stored content on output (defense-in-depth).
   */
  
  /**
   * Return NativeForum module config (array) reliably.
   * Some PW versions don't expose module config via $module->get('key'), so we use Modules::getConfig().
   */
  function sf_forum_cfg_array($forum): array {
    try {
      $cfg = wire('modules')->getConfig('NativeForum');
      return is_array($cfg) ? $cfg : [];
    } catch(\Throwable $e) {
      return [];
    }
  }

  /**
   * Convert YouTube/Vimeo embed HTML (including escaped iframe code) into safe short tokens
   * to avoid hosts/WAFs blocking <iframe> in POST bodies.
   */
  function sf_embed_html_to_tokens(string $html): string {
    $html = (string)$html;
    if($html === '') return $html;

    // decode common escaped iframe code (from CKEditor source mode)
    if(stripos($html, '&lt;iframe') !== false) {
      $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // replace youtube/vimeo iframes with tokens
    $html = preg_replace_callback('~<iframe\b[^>]*\bsrc\s*=\s*(["\'])([^"\']+)\1[^>]*>\s*</iframe>~i', function($m){
      $src = (string)$m[2];
      if(preg_match('~youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,})~i', $src, $mm)) {
        return '[sfyt:' . $mm[1] . ']';
      }
      if(preg_match('~player\.vimeo\.com/video/(\d{6,})~i', $src, $mm)) {
        return '[sfvimeo:' . $mm[1] . ']';
      }
      // unknown provider: drop iframe entirely
      return '';
    }, $html);

    // strip any remaining iframe tags (unknown providers)
    $html = preg_replace('~</?iframe\b[^>]*>~i', '', $html);

    return $html;
  }

if(!function_exists(__NAMESPACE__ . '\sf_fix_sized_images')) {
  /**
   * User-resized images: put the chosen width inline (with max-width/height:auto) so it
   * survives site/theme rules such as img{width:100%} and never overflows on mobile.
   */
  function sf_fix_sized_images(string $html): string {
    if(stripos($html, '<img') === false) return $html;
    return preg_replace_callback('~<img\b[^>]*>~i', function($m) {
      $tag = $m[0];
      $w = '';
      if(preg_match('~\sstyle\s*=\s*"([^"]*)"~i', $tag, $sm) && preg_match('~(?:^|;)\s*width\s*:\s*(\d{1,4}px|\d{1,3}%)~i', $sm[1], $wm)) $w = $wm[1];
      if($w === '' && preg_match('~\swidth\s*=\s*"?(\d{1,4})"?~i', $tag, $am)) $w = $am[1] . 'px';
      if($w === '') return $tag;
      $style = 'width:' . $w . ';max-width:100%;height:auto;';
      $tag = preg_replace('~\sstyle\s*=\s*"[^"]*"~i', '', $tag);
      $tag = preg_replace('~\sheight\s*=\s*"?\d+"?~i', '', $tag);
      return preg_replace('~^<img\b~i', '<img style="' . $style . '"', $tag);
    }, $html);
  }
}

function sf_render_post_html($forum, string $html, Sanitizer $sanitizer): string {
    $html = (string) $html;

    // Fix for legacy/double-encoded HTML (shows as literal </p><p> text)
    if(stripos($html, '&lt;') !== false && preg_match('~&lt;/?(p|div|br|span|a|img|ul|ol|li|blockquote|h[2-6]|table|pre|code)\b~i', $html)) {
      $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // If disabled, show as plain text
    $cfg = sf_forum_cfg_array($forum);
    $renderRichHtml = isset($cfg['renderRichHtml']) ? (int)$cfg['renderRichHtml'] : 1;
    if($renderRichHtml === 0) {
      $plain = strip_tags($html);
      $plain = $sanitizer->textarea($plain);
      return nl2br($sanitizer->entities($plain), false);
    }

    $attachmentsEnabled = isset($cfg['attachmentsEnabled']) ? (int)$cfg['attachmentsEnabled'] : 1;
    $attachmentsAllowImages = isset($cfg['attachmentsAllowImages']) ? (int)$cfg['attachmentsAllowImages'] : 1;
    $allowRichImages = isset($cfg['allowRichImages']) ? (int)$cfg['allowRichImages'] : 1;

    $allowImages = ($allowRichImages || ($attachmentsEnabled && $attachmentsAllowImages)) ? 1 : 0;
    $allowEmbeds = isset($cfg['allowRichEmbeds']) ? (int)$cfg['allowRichEmbeds'] : 1;
    $allowCode   = isset($cfg['allowRichCode']) ? (int)$cfg['allowRichCode'] : 1;

    // Render safe embed tokens on output.
    // We store tokens (instead of raw <iframe>) to avoid some hosts/mod_security
    // blocking iframe HTML in POST bodies.
    if(stripos($html, '[sfyt:') !== false || stripos($html, '[sfvimeo:') !== false) {
      // YouTube
      $html = preg_replace_callback('/\[sfyt:([A-Za-z0-9_-]{6,})\]/', function($m) use($allowEmbeds, $sanitizer) {
        $id = (string) $m[1];
        if(!$allowEmbeds) {
          $url = 'https://www.youtube.com/watch?v=' . rawurlencode($id);
          return '<a href="' . $sanitizer->entities($url) . '" target="_blank" rel="nofollow noopener">' . $sanitizer->entities($url) . '</a>';
        }
        $src = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id);
        // Fullscreen button requires allowfullscreen/allow.
        // We keep attributes minimal and whitelist them in HTMLPurifier below.
        $allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen';
        return '<iframe class="sf-embed-iframe" width="560" height="315" src="' . $sanitizer->entities($src) . '" frameborder="0" allow="' . $sanitizer->entities($allow) . '" allowfullscreen="allowfullscreen"></iframe>';
      }, $html);

      // Vimeo
      $html = preg_replace_callback('/\[sfvimeo:(\d{6,})\]/', function($m) use($allowEmbeds, $sanitizer) {
        $id = (string) $m[1];
        if(!$allowEmbeds) {
          $url = 'https://vimeo.com/' . rawurlencode($id);
          return '<a href="' . $sanitizer->entities($url) . '" target="_blank" rel="nofollow noopener">' . $sanitizer->entities($url) . '</a>';
        }
        $src = 'https://player.vimeo.com/video/' . rawurlencode($id);
        $allow = 'autoplay; fullscreen; picture-in-picture';
        return '<iframe class="sf-embed-iframe" width="560" height="315" src="' . $sanitizer->entities($src) . '" frameborder="0" allow="' . $sanitizer->entities($allow) . '" allowfullscreen="allowfullscreen"></iframe>';
      }, $html);
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
    if($allowImages) $allowedAttr = array_merge($allowedAttr, ['img.src','img.alt','img.title','img.width','img.height','img.class','img.style']);
    if($allowEmbeds) $allowedAttr = array_merge($allowedAttr, ['iframe.src','iframe.width','iframe.height','iframe.frameborder','iframe.allow','iframe.allowfullscreen']);

    $opts = [
      'HTML.AllowedElements' => implode(',', $allowed),
      'HTML.AllowedAttributes' => implode(',', $allowedAttr),
      'Attr.AllowedFrameTargets' => ['_blank','_self'],
      'HTML.Nofollow' => true,
      'Attr.EnableID' => false,
      // Allow a small set of CSS properties that we intentionally use in forum output
      // (notably width/max-width for user-resized embedded images).
      'CSS.AllowedProperties' => ['text-align','width','height','max-width'],
      'HTML.SafeIframe' => $allowEmbeds ? true : false,
      // Allow common safe iframe sources (YouTube and Vimeo). Accept with or without "www".
      'URI.SafeIframeRegexp' => $allowEmbeds ? '#^(https?:)?//((www\.)?youtube\.com/embed/|(www\.)?youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)#' : '#$#',
    ];

    // Prefer a custom HTMLPurifier instance so we can safely allow iframe attributes
    // like allow/allowfullscreen (HTMLPurifier warns/strips them by default).
    try {
      if($allowEmbeds && class_exists('\\HTMLPurifier_Config') && class_exists('\\HTMLPurifier')) {
        $cfg = \HTMLPurifier_Config::createDefault();
        // Prevent HTMLPurifier DefinitionCache/Serializer warnings by using an upgrade-safe, writable cache dir.
        // IMPORTANT: do not rely on a local $config variable in templates.
        // Use wire('config') so this works in all contexts and doesn't produce warnings.
        try {
          $pwConfig = wire('config');
          $cacheDir = rtrim((string) $pwConfig->paths->cache, '/\\') . '/HTMLPurifier';
          if(!is_dir($cacheDir)) wireMkdir($cacheDir, true);
          $cfg->set('Cache.DefinitionImpl', 'Serializer');
          $cfg->set('Cache.SerializerPath', rtrim($cacheDir, '/\\'));
        } catch(\Throwable $e) {
          // ignore
        }


        foreach($opts as $k => $v) $cfg->set($k, $v);
        $cfg->set('HTML.DefinitionID', 'sf_forum_iframe');
        $cfg->set('HTML.DefinitionRev', 1);
        if(method_exists($cfg, 'maybeGetRawHTMLDefinition')) {
          $def = $cfg->maybeGetRawHTMLDefinition();
          if($def) {
            // allowfullscreen is boolean; allow is a text list.
            $def->addAttribute('iframe', 'allowfullscreen', 'Bool');
            $def->addAttribute('iframe', 'allow', 'Text');
          }
        }
        $purifier = new \HTMLPurifier($cfg);
        return sf_fix_sized_images((string) $purifier->purify($html));
      }

      return sf_fix_sized_images((string) $sanitizer->purify($html, $opts));

    } catch(\Throwable $e) {
      return nl2br($sanitizer->entities($sanitizer->textarea(strip_tags($html))), false);
    }
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_forum_theme')) {
  function sf_forum_theme($forum): string {
    if(!$forum) return 'simple';
    $t = (string) (sf_cfg('frontendTheme', 'simple') ?: 'simple');
    return $t ?: 'simple';
  }
}


if(!function_exists(__NAMESPACE__ . '\sf_svg_icon')) {
  /**
   * Small inline SVG icons (no external dependencies).
   * Used by default so icons always work, even without Font Awesome.
   */
  function sf_svg_icon(string $name): string {
    $name = strtolower(trim($name));
    switch($name) {
      case 'edit':
        // pencil
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M12.146.146a.5.5 0 0 1 .708 0l2.992 2.992a.5.5 0 0 1 0 .708l-9.5 9.5a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l9.5-9.5zM11.207 2L3 10.207V11h.793L12 2.793 11.207 2zM13 3.793 12.207 3 4 11.207V12h.793L13 4.793z"/></svg>';

      case 'delete':
        // trash
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M5.5 5.5A.5.5 0 0 1 6 6v7a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5.5a.5.5 0 0 1 1 0v7a.5.5 0 0 1-1 0V6zm3 .5a.5.5 0 0 0-1 0v7a.5.5 0 0 0 1 0V6z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5.5l.5-.5h4l.5.5H14a1 1 0 0 1 .5 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118z"/></svg>';

      case 'pm':
      case 'message':
        // envelope
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V4zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1H2zm13 2.383-4.708 2.825L15 11.105V5.383zM14.247 12 9.06 8.872 8 9.515l-1.06-.643L1.753 12H14.247zM1 11.105l4.708-2.897L1 5.383v5.722z"/></svg>';

      case 'dots':
      case 'more':
        // horizontal three dots
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="4" cy="8" r="1.3"/><circle cx="8" cy="8" r="1.3"/><circle cx="12" cy="8" r="1.3"/></svg>';

      case 'thumbs-up':
      case 'up':
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6.956 1.745C7.021.9 7.85.3 8.69.53c.423.116.7.5.7.94v3.03h3.38c.88 0 1.6.72 1.6 1.6 0 .17-.03.34-.08.5l-1.4 4.66A1.6 1.6 0 0 1 11.36 13H6.6V6.18l.356-4.435z"/><path d="M1.5 6.5h3v6h-3a.5.5 0 0 1-.5-.5v-5a.5.5 0 0 1 .5-.5z"/></svg>';

      case 'thumbs-down':
      case 'down':
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M9.044 14.255c-.065.846-.894 1.446-1.734 1.215-.423-.116-.7-.5-.7-.94V11.5H3.23c-.88 0-1.6-.72-1.6-1.6 0-.17.03-.34.08-.5l1.4-4.66A1.6 1.6 0 0 1 4.64 3H9.4v6.82l-.356 4.435z"/><path d="M14.5 3.5h-3v6h3a.5.5 0 0 0 .5-.5v-5a.5.5 0 0 0-.5-.5z"/></svg>';


      case 'link':
        // chain link
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4.354 5.5a3 3 0 0 1 4.243 0 .5.5 0 0 1-.707.707 2 2 0 0 0-2.829 0L3.646 7.621a2 2 0 0 0 2.829 2.829.5.5 0 1 1 .707.707 3 3 0 0 1-4.243-4.243L4.354 5.5z"/><path d="M11.646 10.5a3 3 0 0 1-4.243 0 .5.5 0 1 1 .707-.707 2 2 0 0 0 2.829 0l1.414-1.414a2 2 0 0 0-2.829-2.829.5.5 0 0 1-.707-.707 3 3 0 0 1 4.243 4.243L11.646 10.5z"/></svg>';

      case 'share':
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M13 1a2 2 0 1 0 1.999 2A2 2 0 0 0 13 1zM3 6a2 2 0 1 0 2 2 2 2 0 0 0-2-2zm10 5a2 2 0 1 0 2 2 2 2 0 0 0-2-2z"/><path d="M4.6 8.8l6.8 3.4.7-1.4-6.8-3.4-.7 1.4zM11.4 5.2L4.6 8.6l.7 1.4 6.8-3.4-.7-1.4z"/></svg>';

      case 'quote':
        // quote-left
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6.2 6.1c0-2.2 1.4-4 3.4-4.6l.4 1.4c-1.2.4-2 1.5-2 2.9h1.8v4.6H6.2V6.1zM.8 6.1c0-2.2 1.4-4 3.4-4.6l.4 1.4c-1.2.4-2 1.5-2 2.9h1.8v4.6H.8V6.1z"/></svg>';

      case 'report':
      case 'flag':
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3 1.5A.5.5 0 0 1 3.5 1h8a.5.5 0 0 1 .4.8L10.6 4l1.3 2.2a.5.5 0 0 1-.4.8H3.5A.5.5 0 0 1 3 6.5v-5z"/><path d="M3 7v8H2V1h1v6z"/></svg>';

      case 'cog':
      case 'gear':
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M9.6 1l.3 1.4c.3.1.6.3.9.5l1.3-.6 1 1.7-1.1.9c.1.3.1.6.1.9s0 .6-.1.9l1.1.9-1 1.7-1.3-.6c-.3.2-.6.4-.9.5L9.6 15H6.4l-.3-1.4c-.3-.1-.6-.3-.9-.5l-1.3.6-1-1.7 1.1-.9a3.9 3.9 0 0 1 0-1.8l-1.1-.9 1-1.7 1.3.6c.3-.2.6-.4.9-.5L6.4 1h3.2zM8 10.3A2.3 2.3 0 1 0 8 5.7a2.3 2.3 0 0 0 0 4.6z"/></svg>';

      case 'search':
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M11.2 10.5l3.2 3.2-1 1-3.2-3.2a6 6 0 1 1 1-1zM6.5 11A4.5 4.5 0 1 0 6.5 2a4.5 4.5 0 0 0 0 9z"/></svg>';

      case 'lock':
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M5 7V5a3 3 0 0 1 6 0v2h.5A1.5 1.5 0 0 1 13 8.5v5A1.5 1.5 0 0 1 11.5 15h-7A1.5 1.5 0 0 1 3 13.5v-5A1.5 1.5 0 0 1 4.5 7H5zm1.5 0h3V5a1.5 1.5 0 0 0-3 0v2z"/></svg>';

      case 'comments':
      case 'comment':
        // chat bubble (no question mark)
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2 2.75A1.75 1.75 0 0 1 3.75 1h8.5A1.75 1.75 0 0 1 14 2.75v5.5A1.75 1.75 0 0 1 12.25 10H7.1L4.1 12.3c-.6.45-1.47.02-1.47-.73V10A1.75 1.75 0 0 1 2 8.25v-5.5z"/></svg>';

      case 'question':
      case 'question-bubble':
        // chat bubble with question mark
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2 2.75A1.75 1.75 0 0 1 3.75 1h8.5A1.75 1.75 0 0 1 14 2.75v5.5A1.75 1.75 0 0 1 12.25 10H7.1L4.1 12.3c-.6.45-1.47.02-1.47-.73V10A1.75 1.75 0 0 1 2 8.25v-5.5z"/><path d="M8 3.3c-1.3 0-2.2.7-2.2 1.8 0 .28.22.5.5.5s.5-.22.5-.5c0-.52.5-.8 1.2-.8.66 0 1.2.3 1.2.9 0 .43-.25.68-.76 1.01-.7.45-1.14.9-1.14 1.69v.16c0 .28.22.5.5.5s.5-.22.5-.5v-.16c0-.37.18-.56.74-.92.72-.46 1.16-.93 1.16-1.78C10.46 4.04 9.4 3.3 8 3.3z"/><circle cx="8" cy="8.9" r="0.6"/></svg>';

      case 'restore':
      case 'undo':
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 3a5 5 0 1 1-4.9 6h1.6A3.5 3.5 0 1 0 8 4.5h-2V7L2.5 3.5 6 0v2h2z"/></svg>';

      case 'chevron-down':
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3.2 5.7a.75.75 0 0 1 1.06 0L8 9.44l3.74-3.74a.75.75 0 1 1 1.06 1.06l-4.27 4.27a.75.75 0 0 1-1.06 0L3.2 6.76a.75.75 0 0 1 0-1.06z"/></svg>';

      case 'chevron-up':
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M12.8 10.3a.75.75 0 0 1-1.06 0L8 6.56l-3.74 3.74a.75.75 0 1 1-1.06-1.06l4.27-4.27a.75.75 0 0 1 1.06 0l4.27 4.27a.75.75 0 0 1 0 1.06z"/></svg>';

      case 'chevron-right':
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6.2 3.2a.75.75 0 0 1 1.06 0l4.27 4.27a.75.75 0 0 1 0 1.06l-4.27 4.27a.75.75 0 1 1-1.06-1.06L9.4 8 6.2 4.26a.75.75 0 0 1 0-1.06z"/></svg>';


      case 'thumb-tack':
      case 'thumbtack':
      case 'pin':
        // pushpin / thumbtack
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6 1h4v2l-1 1v4l2 2v1H9v3.5a1 1 0 0 1-2 0V12H5v-1l2-2V4L6 3V1z"/></svg>';

    }
    return '';
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_icon')) {
  /**
   * Unified icon renderer. Defaults to built-in SVG icons.
   * If module setting iconSet=fontawesome, returns Font Awesome <i> markup.
   */
function sf_icon($forum, string $name): string {
  $provider = (string) sf_cfg('iconProvider', '');
  // Back-compat: if iconProvider isn't set, fall back to old iconSet setting.
  if($provider === '') $provider = (string) sf_cfg('iconSet', 'svg');

  $provider = strtolower(trim($provider));
  $n = strtolower(trim($name));

  // Normalize legacy/variant values
  if($provider === 'fontawesome') $provider = 'fa6';
  if($provider === 'fa') $provider = 'fa6';
  if($provider === 'fontawesome6') $provider = 'fa6';
  if($provider === 'fontawesome7') $provider = 'fa7';

  // Accept any faN (fa4/fa5/fa6/fa7...) and normalize to supported rendering rules.
  $faVer = null;
  if(preg_match('/^fa(\d+)$/', $provider, $m)) {
    $faVer = (int)$m[1];
    if($faVer <= 0) $faVer = null;
  }

  // Built-in SVG (always works)
  if($provider === 'svg' || $provider === 'builtin' || $provider === 'none' || $provider === 'site') {
    return sf_svg_icon($n);
  }

  // Font Awesome mapping
  $faStyle = strtolower((string) sf_cfg('faStyle', 'solid')); // solid|regular|brands
  if(!in_array($faStyle, ['solid','regular','brands'], true)) $faStyle = 'solid';

  $map4 = [
    'edit' => 'pencil',
    'delete' => 'trash',
    'pm' => 'envelope',
    'message' => 'envelope',
    'quote' => 'quote-left',
    'cog' => 'cog',
    'gear' => 'cog',
    'search' => 'search',
    'thumb-tack' => 'thumb-tack',
    'thumbtack' => 'thumb-tack',
    'pin' => 'thumb-tack',
    'restore' => 'undo',
    'undo' => 'undo',
    'dots' => 'ellipsis-h',
    'more' => 'ellipsis-h',
    'thumbs-up' => 'thumbs-up',
    'up' => 'thumbs-up',
    'thumbs-down' => 'thumbs-down',
    'down' => 'thumbs-down',
    'link' => 'link',
    'share' => 'share-alt',
    'flag' => 'flag',
    'report' => 'flag',
    'question' => 'question-circle',
    'comments' => 'comments',
    'comment' => 'comments',
    'chevron-down' => 'chevron-down',
    'chevron-up' => 'chevron-up',
    'chevron-right' => 'chevron-right',
  ];

  $map56 = [
    'edit' => 'pen-to-square',
    'delete' => 'trash',
    'pm' => 'envelope',
    'message' => 'envelope',
    'quote' => 'quote-left',
    'cog' => 'gear',
    'gear' => 'gear',
    'search' => 'magnifying-glass',
    'thumb-tack' => 'thumbtack',
    'thumbtack' => 'thumbtack',
    'pin' => 'thumbtack',
    'restore' => 'rotate-left',
    'undo' => 'rotate-left',
    'dots' => 'ellipsis',
    'more' => 'ellipsis',
    'thumbs-up' => 'thumbs-up',
    'up' => 'thumbs-up',
    'thumbs-down' => 'thumbs-down',
    'down' => 'thumbs-down',
    'link' => 'link',
    'share' => 'share-nodes',
    'flag' => 'flag',
    'report' => 'flag',
    'question' => 'circle-question',
    'comments' => 'comments',
    'comment' => 'comments',
    'chevron-down' => 'chevron-down',
    'chevron-up' => 'chevron-up',
    'chevron-right' => 'chevron-right',
  ];

  $icon = '';
  $cls = '';

  if($provider === 'fa4' || $faVer === 4) {
    $icon = $map4[$n] ?? '';
    $cls = 'fa fa-' . $icon;
  } elseif($provider === 'fa5' || $faVer === 5) {
    $icon = $map56[$n] ?? '';
    $prefix = ($faStyle === 'regular') ? 'far' : (($faStyle === 'brands') ? 'fab' : 'fas');
    $cls = $prefix . ' fa-' . $icon;
  } else { // fa6/fa7+ (default)
    $icon = $map56[$n] ?? '';
    $prefix = ($faStyle === 'regular') ? 'fa-regular' : (($faStyle === 'brands') ? 'fa-brands' : 'fa-solid');
    $cls = $prefix . ' fa-' . $icon;
  }

  if(!$icon) return sf_svg_icon($n);

  return '<i class="' . $cls . '" aria-hidden="true"></i>';
}
}

if(!function_exists(__NAMESPACE__ . '\sf_forum_css')) {
  function sf_forum_css($forum, $config): void {
    // Handle logout action early (before any HTML output).
    // We use a POST form with CSRF token to avoid GET-based logout.
    try {
      $user = wire('user');
      $session = wire('session');
      $pages = wire('pages');
      if($user && $user->isLoggedin() && $session && $session->CSRF && (int)wire('input')->post->sf_logout === 1) {
        if($session->CSRF->hasValidToken()) {
          $session->logout();
          // Redirect to forum root if available, otherwise to homepage.
          $root = ($forum && method_exists($forum, 'forumRoot')) ? $forum->forumRoot() : null;
          $to = ($root && $root->id) ? $root->url : '/';
          $session->redirect($to);
        }
      }
    } catch(\Throwable $e) {
      // ignore
    }

    $theme = sf_forum_theme($forum);

    // Bootstrap integration (prevents conflicts with sites that already load Bootstrap 3/4/older 5)
    if($theme === 'bootstrap' && empty($GLOBALS['nativeForumBootstrapLoaded'])) {
      $mode = (string) sf_cfg('bootstrapIntegration', '');
      if($mode === '') {
        // Back-compat: old toggle bootstrapAutoLoad (1 => force, 0 => auto)
        $mode = ((int) sf_cfg('bootstrapAutoLoad', 0) === 1) ? 'force' : 'auto';
      }

      if($mode === 'force') {
        $GLOBALS['nativeForumBootstrapLoaded'] = true;
        $cssUrl = (string) sf_cfg('bootstrapCssUrl', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');
        $jsUrl  = (string) sf_cfg('bootstrapJsUrl',  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js');
        if($cssUrl) echo "<link rel='stylesheet' href='{$cssUrl}' />";
        if($jsUrl)  echo "<script src='{$jsUrl}' defer></script>";
      }
    }

    $cssFile = $theme === 'bootstrap' ? 'native-forum-bootstrap.css' : 'native-forum.css';
    $css = $config->urls->templates . $cssFile;

    if(empty($GLOBALS['nativeForumCssLoaded'])) {
      $GLOBALS['nativeForumCssLoaded'] = true;
      // Cache-busting: use file mtime so updated theme CSS isn't stuck behind ProCache/AIOM/browser caches.
      $cssVer = 1;
      try {
        $cssAbs = (string) $config->paths->templates . $cssFile;
        $tm = @filemtime($cssAbs);
        if($tm) $cssVer = $tm;
      } catch(\Throwable $e) {}
      echo "<link rel='stylesheet' href='{$css}?v={$cssVer}' />";

// Icon/Font Awesome auto-loader
if(empty($GLOBALS['nativeForumIconCssLoaded'])) {
  $GLOBALS['nativeForumIconCssLoaded'] = true;

  $provider = strtolower((string) sf_cfg('iconProvider', ''));
  if($provider === '') $provider = strtolower((string) sf_cfg('iconSet', 'svg'));
  if($provider === 'fontawesome') $provider = 'fa6';

  // If provider=site/svg/none → do not load any icon CSS
  if(in_array($provider, ['fa4','fa5','fa6'], true)) {
    $load = (int) sf_cfg('fontAwesomeAutoLoad', 0);
    if($load) {
      $faUrl = (string) sf_cfg('fontAwesomeCssUrl', '');
      if(!$faUrl) {
        if($provider === 'fa4') $faUrl = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css';
        elseif($provider === 'fa5') $faUrl = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css';
        else $faUrl = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
      }
      echo "<link rel='stylesheet' href='{$faUrl}' />";
    }
  }
}

// NativeForum standalone assets (quote + editor)
$modUrl = rtrim((string) $config->urls->siteModules, '/') . '/NativeForum/';

// Absolute module path (for filemtime cache-busting)
$modPath = rtrim((string) $config->paths->siteModules, '/') . '/NativeForum/';
if(!is_dir($modPath)) $modPath = rtrim((string) $config->paths->modules, '/') . '/NativeForum/';
if(!is_dir($modPath) && isset($modules)) {
  $m = $modules->get('NativeForum');
  if($m && isset($m->path)) $modPath = (string) $m->path;
}

// Bootstrap compatibility shim (helps when site uses older Bootstrap, safe on BS5)
if($theme === 'bootstrap' && (int) sf_cfg('bootstrapCompatShim', 1) && empty($GLOBALS['nativeForumBsShimLoaded'])) {
  $GLOBALS['nativeForumBsShimLoaded'] = true;
  echo "<link rel='stylesheet' href='{$modUrl}assets/css/sf-bs-compat.css?v=1' />";
}

// bump version to avoid stale cache when design vars CSS changes
// Cache-busting: use file modification time so CSS updates are not stuck behind ProCache/AIOM/browser caches
$__sfExtrasV = 25;
try {
  $p = rtrim($modPath, '/').'/assets/css/sf-extras.css';
  $t = @filemtime($p);
  if($t) $__sfExtrasV = $t;
} catch(\Throwable $e) {}
echo "<link rel='stylesheet' href='{$modUrl}assets/css/sf-extras.css?v={$__sfExtrasV}' />";
// Mobile stacking override (inline to beat theme CSS and caches)
echo "<style id='sf-mobile-stack'>\n";
echo <<<'CSS'

/* NativeForum: index rows on small screens — icon + title on top, stats + last post in one line below */
@media (max-width: 768px){
  .sf-wrap .sf-forum-row .sf-forum-rowgrid{ display:flex !important; flex-direction:row !important; flex-wrap:wrap !important; justify-content:flex-start !important; align-items:center !important; gap:6px 14px !important; width:100% !important; }
  .sf-wrap .sf-forum-row .sf-forum-rowgrid > .sf-forum-main{ flex:1 1 100% !important; width:100% !important; max-width:none !important; min-width:0 !important; padding:0 !important; border:0 !important; margin:0 !important; }
  .sf-wrap .sf-forum-row .sf-forum-rowmain{ display:flex !important; flex-direction:row !important; align-items:flex-start !important; gap:12px !important; text-align:left !important; }
  .sf-wrap .sf-forum-row .sf-forum-titlewrap{ flex:1 1 auto !important; min-width:0 !important; text-align:left !important; }
  .sf-wrap .sf-forum-row .sf-forum-rowgrid > .sf-forum-stats{ flex:0 0 auto !important; width:auto !important; margin:0 0 0 48px !important; padding:0 !important; border:0 !important; text-align:left !important; }
  .sf-wrap .sf-forum-row .sf-forum-stats .sf-stat{ display:flex !important; align-items:baseline; gap:4px; }
  .sf-wrap .sf-forum-row .sf-forum-rowgrid > .sf-forum-last{ flex:1 1 0 !important; width:auto !important; min-width:0 !important; margin:0 !important; padding:0 0 0 12px !important; border:0 !important; border-left:1px solid var(--sf-border, rgba(0,0,0,.12)) !important; font-size:.875em; }
  .sf-wrap .sf-forum-row .sf-forum-last .sf-last-title{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .sf-wrap .sf-forum-title, .sf-wrap .sf-forum-title a, .sf-wrap .sf-index-title, .sf-wrap .sf-index-title a{
    white-space:normal !important; overflow:visible !important; text-overflow:clip !important; word-break:normal !important; overflow-wrap:anywhere !important;
  }
}
@media (max-width: 420px){
  .sf-wrap .sf-forum-row .sf-forum-rowgrid > .sf-forum-last{ flex:1 1 100% !important; margin-left:48px !important; border-left:0 !important; padding-left:0 !important; }
}
CSS;
echo "\n</style>";
// bump version to avoid stale cache
// Design Studio runtime layer (loaded last so it can override theme CSS)
$__sfRuntimeV = 10;
try {
  $p = rtrim($modPath, '/').'/assets/css/sf-designstudio-runtime.css';
  $t = @filemtime($p);
  if($t) $__sfRuntimeV = $t;
} catch(\Throwable $e) {}
echo "<link rel='stylesheet' href='{$modUrl}assets/css/sf-designstudio-runtime.css?v={$__sfRuntimeV}' />";

// Layout fixes CSS - common helpers (loaded after runtime so it can override)
$__sfLayoutV = 10;
try {
  $pf = rtrim($modPath, '/').'/assets/css/sf-layout-fixes.css';
  $tf = @filemtime($pf);
  if($tf) $__sfLayoutV = $tf;
} catch(\Throwable $e) {}
echo "<link rel='stylesheet' href='{$modUrl}assets/css/sf-layout-fixes.css?v={$__sfLayoutV}' />";
$__sfModernV = @filemtime(rtrim($modPath, '/') . '/assets/css/sf-modern.css') ?: 1;
echo "<link rel='stylesheet' href='{$modUrl}assets/css/sf-modern.css?v={$__sfModernV}' />";


// Design variables (colors) from module settings
try {
  $vars = [];
  $bgForum = (string) sf_cfg('designForumBg', '');
  if((int) sf_cfg('designForumBgTransparent', 0) === 1) $bgForum = 'transparent';
  $txForum = (string) sf_cfg('designForumText', '');

  $bgPanel = (string) sf_cfg('designPanelBg', '');
  if((int) sf_cfg('designPanelBgTransparent', 0) === 1) $bgPanel = 'transparent';
  $txPanel = (string) sf_cfg('designPanelText', '');

  $bgRow = (string) sf_cfg('designTopicRowBg', '');
  if((int) sf_cfg('designTopicRowBgTransparent', 0) === 1) $bgRow = 'transparent';
  $txRow = (string) sf_cfg('designTopicRowText', '');

  $bgTopic = (string) sf_cfg('designTopicPostBg', '');
  if((int) sf_cfg('designTopicPostBgTransparent', 0) === 1) $bgTopic = 'transparent';
  $txTopic = (string) sf_cfg('designTopicPostText', '');

  $bgReply = (string) sf_cfg('designReplyBg', '');
  if((int) sf_cfg('designReplyBgTransparent', 0) === 1) $bgReply = 'transparent';
  $txReply = (string) sf_cfg('designReplyText', '');

  // Theme tokens (global design system)
  $tokenBg          = (string) sf_cfg('tokenBg', '');
  $tokenSurface     = (string) sf_cfg('tokenSurface', '');
  $tokenSurface2    = (string) sf_cfg('tokenSurface2', '');
  $tokenBorder      = (string) sf_cfg('tokenBorder', '');
  $tokenText        = (string) sf_cfg('tokenText', '');
  $tokenMuted       = (string) sf_cfg('tokenMuted', '');
  $tokenPrimary     = (string) sf_cfg('tokenPrimary', '');
  $tokenPrimaryText = (string) sf_cfg('tokenPrimaryText', '');
  $tokenAccent      = (string) sf_cfg('tokenAccent', '');
  $tokenSuccess     = (string) sf_cfg('tokenSuccess', '');
  $tokenWarning     = (string) sf_cfg('tokenWarning', '');
  $tokenDanger      = (string) sf_cfg('tokenDanger', '');
  $tokenRadius      = (string) sf_cfg('tokenRadius', '');
  $tokenSpace       = (string) sf_cfg('tokenSpace', '');
  $tokenFontFamily  = (string) sf_cfg('tokenFontFamily', '');
  $tokenBaseSize    = (string) sf_cfg('tokenBaseFontSize', '');
  $tokenLineHeight  = (string) sf_cfg('tokenLineHeight', '');

  // Backwards-compatible mapping: if per-area colors are empty, derive them from tokens.
  if($bgForum === '' && $tokenBg !== '') $bgForum = $tokenBg;
  if($txForum === '' && $tokenText !== '') $txForum = $tokenText;

  if($bgPanel === '' && $tokenSurface !== '') $bgPanel = $tokenSurface;
  if($txPanel === '' && $tokenText !== '') $txPanel = $tokenText;

  if($bgRow === '' && $tokenSurface !== '') $bgRow = $tokenSurface;
  if($txRow === '' && $tokenText !== '') $txRow = $tokenText;

  if($bgTopic === '' && $tokenSurface !== '') $bgTopic = $tokenSurface;
  if($txTopic === '' && $tokenText !== '') $txTopic = $tokenText;

  if($bgReply === '' && $tokenSurface !== '') $bgReply = $tokenSurface;
  if($txReply === '' && $tokenText !== '') $txReply = $tokenText;



  // Only output vars when user set them (or requested transparent)
  $add = function(string $k, string $v) use (&$vars) {
    $v = trim((string)$v);
    if($v === '') return;

    // Normalize common size values: if user enters a number (e.g. 18), treat it as px.
    $sizeKeys = [
      '--sf-font-size','--sf-h1-size','--sf-h2-size','--sf-topic-title-size','--sf-index-title-size','--sf-page-title-size','--sf-sidebar-title-size',
      '--sf-card-header-font-size','--sf-sidebar-header-font-size','--sf-popup-title-size','--sf-popup-text-size','--sf-usercard-title-size','--sf-usercard-text-size',
      '--sf-post-meta-size','--sf-small-text-size',
      '--sf-label-font-size',
    ];
    if(in_array($k, $sizeKeys, true) || substr($k, -5) === '-size') {
      if(preg_match('/^\d+(?:\.\d+)?$/', $v)) $v .= 'px';
    }

    $vars[$k] = $v;
  };

  


// Design Studio (ds_) vars (global + current template override)
$tplNow = 'global';
try {
  $pNow = wire('page');
  if($pNow && $pNow->template) $tplNow = (string)$pNow->template->name;
} catch(\Throwable $e) { $tplNow = 'global'; }
if(!$tplNow) $tplNow = 'global';

// Map Design Studio keys to CSS variables used by the forum CSS
$dsMap = [
  // Tokens / colors
  ['colors','bg','--sf-bg'],
  ['colors','surface','--sf-surface'],
  ['colors','surface2','--sf-surface-2'],
  ['colors','border','--sf-border'],
  ['colors','text','--sf-text'],
  ['colors','muted','--sf-muted'],
  ['colors','primary','--sf-primary'],
  ['colors','primaryText','--sf-primary-contrast'],
  ['colors','accent','--sf-accent'],
  ['colors','success','--sf-success'],
  ['colors','warning','--sf-warning'],
  ['colors','danger','--sf-danger'],
  ['colors','radius','--sf-radius'],
  ['colors','space','--sf-space'],

  // Typography
  ['typography','fontFamily','--sf-font-family'],
  ['typography','baseSize','--sf-font-size'],
  ['typography','lineHeight','--sf-line-height'],
  ['typography','h1Size','--sf-h1-size'],
  ['typography','h2Size','--sf-h2-size'],
  ['typography','smallSize','--sf-small-text-size'],
  ['typography','metaSize','--sf-post-meta-size'],
  ['typography','labelSize','--sf-label-font-size'],
  ['typography','labelColor','--sf-label-color'],
  ['typography','indexTitleSize','--sf-index-title-size'],
  ['typography','indexTitleColor','--sf-index-title-color'],
  ['typography','indexTitleHoverColor','--sf-index-title-hover-color'],
  ['typography','titleWeight','--sf-title-weight'],
  ['typography','lastTitleSize','--sf-last-title-size'],
  ['typography','lastTitleColor','--sf-last-title-color'],

  // Layout
  ['layout','pagePadX','--sf-page-pad-x'],
  ['layout','pagePadY','--sf-page-pad-y'],
  ['layout','indexRowPad','--sf-index-row-pad'],
  ['layout','indexRowBorder','--sf-index-row-border'],

  ['layout','groupInnerGap','--sf-group-inner-gap'],

['layout','indexGroupGap','--sf-index-group-gap'],
  ['layout','sidebarBlockGap','--sf-side-block-gap'],
['layout','indexRowGap','--sf-index-row-gap'],
['layout','topicRowGap','--sf-topic-row-gap'],
['layout','postRowGap','--sf-post-row-gap'],
['layout','pageheadGap','--sf-pagehead-gap'],
  // Header
  ['header','headerBg','--sf-header-bg'],
  ['header','headerText','--sf-header-color'],
  ['header','headerBorder','--sf-header-border'],
  ['header','headerPad','--sf-header-pad'],
  ['header','pageTitleSize','--sf-page-title-size'],
  ['header','pageTitleColor','--sf-page-title-color'],

  // Sidebar
  ['sidebar','sideBg','--sf-side-bg'],
  ['sidebar','sideText','--sf-side-text'],
  ['sidebar','sidePad','--sf-side-pad'],
  ['sidebar','sideInnerPad','--sf-side-inner-pad'],
  ['sidebar','sidebarTitleSize','--sf-sidebar-title-size'],

  // Topic & posts
  ['topic','topicRowBg','--sf-bg-topicrow'],
  ['topic','topicRowText','--sf-text-topicrow'],
  ['topic','topicPostBg','--sf-bg-topicpost'],
  ['topic','topicPostText','--sf-text-topicpost'],
  ['topic','replyBg','--sf-bg-reply'],
  ['topic','replyText','--sf-text-reply'],
  ['topic','pinnedRowBg','--sf-pinned-row-bg'],
  ['topic','pinnedRowText','--sf-pinned-row-text'],
  ['topic','pinnedRowPad','--sf-pinned-row-pad'],
  ['topic','pinnedRowRadius','--sf-pinned-row-radius'],
  ['topic','pinnedTitleWeight','--sf-pinned-title-weight'],
  ['topic','userInfoBg','--sf-userinfo-bg'],
  ['topic','userInfoText','--sf-userinfo-text'],
  ['topic','signatureSepColor','--sf-signature-sep-color'],
  ['topic','signatureSepSize','--sf-signature-sep-size'],

  // Tags
  ['tags','tagBg','--sf-tag-bg'],
  ['tags','tagText','--sf-tag-text'],
  ['tags','tagBorder','--sf-tag-border'],
  ['tags','tagRadius','--sf-tag-radius'],
  ['tags','tagPad','--sf-tag-pad'],
  ['tags','tagSize','--sf-tag-size'],
  ['tags','tagWeight','--sf-tag-weight'],
  ['tags','tagGap','--sf-tag-gap'],
  ['tags','tagHoverBg','--sf-tag-hover-bg'],
  ['tags','tagHoverText','--sf-tag-hover-text'],

  // VIP
  ['vip','vipHeaderBg','--sf-vip-header-bg'],
  ['vip','vipHeaderText','--sf-vip-header-color'],
  ['vip','vipHeaderSize','--sf-vip-header-size'],

  // Badges
  ['badges','badgeFontSize','--sf-badge-font-size'],
  ['badges','badgeRadius','--sf-badge-radius'],
  ['badges','badgePad','--sf-badge-pad'],
  ['badges','pinnedBadgeBg','--sf-badge-pinned-bg'],
  ['badges','pinnedBadgeText','--sf-badge-pinned-color'],
  ['badges','vipBadgeBg','--sf-badge-vip-bg'],
  ['badges','vipBadgeText','--sf-badge-vip-color'],

  // Buttons / forms
  ['buttons','btnRadius','--sf-btn-radius'],
  ['buttons','btnPadding','--sf-btn-pad'],
  ['buttons','btnPrimaryBg','--sf-btn-primary-bg'],
  ['buttons','btnPrimaryText','--sf-btn-primary-text'],
  ['buttons','btnPrimaryHoverBg','--sf-btn-primary-hover-bg'],
  ['buttons','btnPrimaryHoverText','--sf-btn-primary-hover-text'],
  ['buttons','btnSecondaryBg','--sf-btn-secondary-bg'],
  ['buttons','btnSecondaryText','--sf-btn-secondary-text'],
  ['buttons','btnSecondaryHoverBg','--sf-btn-secondary-hover-bg'],
  ['buttons','btnBorder','--sf-btn-border'],
  ['buttons','btnText','--sf-btn-text'],
  ['buttons','btnFocusRing','--sf-btn-focus-ring'],
  ['forms','inputRadius','--sf-input-radius'],
  ['forms','inputPadding','--sf-input-pad'],

  // Layout
  ['layout','pagePadX','--sf-page-pad-x'],
  ['layout','pagePadY','--sf-page-pad-y'],
  ['layout','indexRowPad','--sf-index-row-pad'],
  ['layout','indexRowBorder','--sf-index-row-border'],

  ['layout','groupInnerGap','--sf-group-inner-gap'],

  // Popups
  ['popups','usercardBg','--sf-usercard-bg'],
  ['popups','usercardText','--sf-usercard-text'],
  ['popups','usercardBorder','--sf-usercard-border'],
  ['popups','usercardRadius','--sf-usercard-radius'],
  ['popups','usercardShadow','--sf-usercard-shadow'],
  ['popups','usercardHeaderBg','--sf-usercard-header-bg'],
  ['popups','usercardTitleSize','--sf-usercard-title-size'],
  ['popups','usercardRankSize','--sf-usercard-rank-size'],
  ['popups','usercardMetaSize','--sf-usercard-meta-size'],
  ['popups','usercardStatNumSize','--sf-usercard-stat-num-size'],
  ['popups','usercardStatLblSize','--sf-usercard-stat-lbl-size'],
  ['popups','usercardBtnBg','--sf-usercard-btn-bg'],
  ['popups','usercardBtnText','--sf-usercard-btn-text'],
  ['popups','usercardBtnBorder','--sf-usercard-btn-border'],
  ['popups','usercardBtnSize','--sf-usercard-btn-size'],
  ['popups','usercardBtnRadius','--sf-usercard-btn-radius'],
  ['popups','tooltipBg','--sf-tooltip-bg'],
  ['popups','tooltipText','--sf-tooltip-text'],
  ['popups','tooltipRadius','--sf-tooltip-radius'],
  ['popups','tooltipShadow','--sf-tooltip-shadow'],
];

foreach($dsMap as $row) {
  [$sec,$key,$var] = $row;
  $val = (string) sf_ds_get($tplNow, $sec, $key, '');
  // Normalize numeric lengths (e.g. "12") to px so CSS vars like gap/margins work.
  if($val !== '') {
    $lenVars = ['--sf-index-group-gap','--sf-index-row-gap','--sf-topic-row-gap','--sf-post-row-gap','--sf-pagehead-gap'];
    if(in_array($var, $lenVars, true)) {
      $vtrim = trim($val);
      if(preg_match('/^-?\d+(?:\.\d+)?$/', $vtrim)) $val = $vtrim . 'px';
    }
  }
  if($val !== '') $add($var, $val);
}

// If Design Studio provided shorthand button padding (--sf-btn-pad), derive helper vars
// so older selectors using split vars (pad-x/pad-y) and small button variants can still work.
if(isset($vars['--sf-btn-pad']) && trim((string)$vars['--sf-btn-pad']) !== '') {
  $pad = trim((string)$vars['--sf-btn-pad']);

  // Parse common forms like "8px 12px" or "8px" or "8px 12px 8px 12px".
  $num = function($v) {
    if(!is_string($v)) return null;
    if(preg_match('/^(-?\d+(?:\.\d+)?)px$/i', trim($v), $m)) return (float) $m[1];
    return null;
  };

  $parts = preg_split('/\s+/', $pad);
  $parts = array_values(array_filter($parts, fn($x) => trim((string)$x) !== ''));

  // Two-value shorthand: top/bottom, left/right
  if(count($parts) === 2) {
    $py = $num($parts[0]);
    $px = $num($parts[1]);
    if($py !== null && !isset($vars['--sf-btn-pad-y'])) $vars['--sf-btn-pad-y'] = rtrim(rtrim((string)$py,'0'),'.') . 'px';
    if($px !== null && !isset($vars['--sf-btn-pad-x'])) $vars['--sf-btn-pad-x'] = rtrim(rtrim((string)$px,'0'),'.') . 'px';
    // Small button padding ~85%
    if($py !== null && $px !== null && !isset($vars['--sf-btn-pad-sm'])) {
      $smy = max(0, round($py * 0.85));
      $smx = max(0, round($px * 0.85));
      $vars['--sf-btn-pad-sm'] = $smy . 'px ' . $smx . 'px';
    }
  }
}

  // Core tokens used by both themes (legacy).
  // IMPORTANT: Do not override Design Studio values. Only apply tokens when the variable isn't already set.
  $addIfUnset = function(string $k, string $v) use (&$vars) {
    $v = trim($v);
    if($v === '') return;
    if(isset($vars[$k]) && trim((string)$vars[$k]) !== '') return;
    $vars[$k] = $v;
  };

  $addIfUnset('--sf-bg', $tokenBg);
  $addIfUnset('--sf-surface', $tokenSurface);
  $addIfUnset('--sf-surface-2', $tokenSurface2);
  $addIfUnset('--sf-border', $tokenBorder);
  $addIfUnset('--sf-text', $tokenText);
  $addIfUnset('--sf-muted', $tokenMuted);
  $addIfUnset('--sf-primary', $tokenPrimary);
  $addIfUnset('--sf-primary-contrast', $tokenPrimaryText);
  $addIfUnset('--sf-accent', $tokenAccent);
  $addIfUnset('--sf-success', $tokenSuccess);
  $addIfUnset('--sf-warning', $tokenWarning);
  $addIfUnset('--sf-danger', $tokenDanger);
  $addIfUnset('--sf-radius', $tokenRadius);
  $addIfUnset('--sf-space', $tokenSpace);

  // Wrapper background is controlled by --sf-bg.
  // If you want to make the forum wrapper transparent (show site background),
  // use the wrapper class "sf-bg-transparent" (set in layout_open).
  $addIfUnset('--sf-text-forum', $txForum);
  $addIfUnset('--sf-bg-panel', $bgPanel);
  $addIfUnset('--sf-text-panel', $txPanel);
  $addIfUnset('--sf-bg-topicrow', $bgRow);
  $addIfUnset('--sf-text-topicrow', $txRow);
  $addIfUnset('--sf-bg-topicpost', $bgTopic);
  $addIfUnset('--sf-text-topicpost', $txTopic);
  $addIfUnset('--sf-bg-reply', $bgReply);
  $addIfUnset('--sf-text-reply', $txReply);

  // Typography + spacing compatibility (helps match host site styles)
  $inheritType = (int) sf_cfg('designInheritTypography', 1);
  if(!$inheritType) {
    $addIfUnset('--sf-font-family', (string) ($tokenFontFamily !== '' ? $tokenFontFamily : sf_cfg('designFontFamily', '')));
    $addIfUnset('--sf-font-size', (string) ($tokenBaseSize !== '' ? $tokenBaseSize : sf_cfg('designFontSize', '')));
    $addIfUnset('--sf-line-height', (string) ($tokenLineHeight !== '' ? $tokenLineHeight : sf_cfg('designLineHeight', '')));
  }
  $addIfUnset('--sf-breadcrumb-size', (string) sf_cfg('designBreadcrumbSize', ''));
  $addIfUnset('--sf-breadcrumb-weight', (string) sf_cfg('designBreadcrumbWeight', ''));
  $addIfUnset('--sf-h1-size', (string) sf_cfg('designH1Size', ''));
  $addIfUnset('--sf-h2-size', (string) sf_cfg('designH2Size', ''));
  $addIfUnset('--sf-topic-title-size', (string) sf_cfg('designTopicTitleSize', ''));
  $addIfUnset('--sf-topic-title-color', (string) sf_cfg('designTopicTitleColor', ''));
  $addIfUnset('--sf-index-title-size', (string) sf_cfg('designIndexTitleSize', ''));
  $addIfUnset('--sf-index-title-color', (string) sf_cfg('designIndexTitleColor', ''));
  $addIfUnset('--sf-title-weight', (string) sf_cfg('designTitleWeight', ''));
  $addIfUnset('--sf-page-title-size', (string) sf_cfg('designPageTitleSize', ''));
  $addIfUnset('--sf-page-title-color', (string) sf_cfg('designPageTitleColor', ''));
  $addIfUnset('--sf-sidebar-title-size', (string) sf_cfg('designSidebarTitleSize', ''));

$addIfUnset('--sf-card-header-font-size', (string) sf_cfg('designCardHeaderFontSize', ''));
$addIfUnset('--sf-card-header-padding', (string) sf_cfg('designCardHeaderPadding', ''));
$addIfUnset('--sf-sidebar-header-font-size', (string) sf_cfg('designSidebarHeaderFontSize', ''));
$addIfUnset('--sf-sidebar-header-padding', (string) sf_cfg('designSidebarHeaderPadding', ''));


  // Popups
  $addIfUnset('--sf-popup-bg', (string) sf_cfg('designPopupBg', ''));
  $addIfUnset('--sf-popup-text', (string) sf_cfg('designPopupText', ''));
  $addIfUnset('--sf-popup-border', (string) sf_cfg('designPopupBorder', ''));
  $addIfUnset('--sf-popup-title-size', (string) sf_cfg('designPopupTitleSize', ''));
  $addIfUnset('--sf-popup-text-size', (string) sf_cfg('designPopupTextSize', ''));
  $addIfUnset('--sf-popup-radius', (string) sf_cfg('designPopupRadius', ''));
  $addIfUnset('--sf-popup-shadow', (string) sf_cfg('designPopupShadow', ''));

// User hover card
$addIfUnset('--sf-usercard-bg', (string) sf_cfg('designUsercardBg', ''));
$addIfUnset('--sf-usercard-text', (string) sf_cfg('designUsercardText', ''));
$addIfUnset('--sf-usercard-border', (string) sf_cfg('designUsercardBorder', ''));
$addIfUnset('--sf-usercard-header-bg', (string) sf_cfg('designUsercardHeaderBg', ''));
$addIfUnset('--sf-usercard-header-text', (string) sf_cfg('designUsercardHeaderText', ''));
$addIfUnset('--sf-usercard-padding', (string) sf_cfg('designUsercardPadding', ''));
$addIfUnset('--sf-usercard-radius', (string) sf_cfg('designUsercardRadius', ''));
$addIfUnset('--sf-usercard-shadow', (string) sf_cfg('designUsercardShadow', ''));
$addIfUnset('--sf-usercard-title-size', (string) sf_cfg('designUsercardTitleSize', ''));
$addIfUnset('--sf-usercard-text-size', (string) sf_cfg('designUsercardTextSize', ''));

  $addIfUnset('--sf-post-meta-size', (string) sf_cfg('designPostMetaSize', ''));
  $addIfUnset('--sf-small-text-size', (string) sf_cfg('designSmallTextSize', ''));
  $addIfUnset('--sf-block-gap', (string) sf_cfg('designBlockGap', ''));
  $addIfUnset('--sf-side-bg', (string) sf_cfg('designSidebarBg', ''));
  $addIfUnset('--sf-side-text', (string) sf_cfg('designSidebarText', ''));
  $addIfUnset('--sf-userinfo-bg', (string) sf_cfg('designUserInfoBg', ''));
  $addIfUnset('--sf-userinfo-text', (string) sf_cfg('designUserInfoText', ''));
  $addIfUnset('--sf-userinfo-pad', (string) sf_cfg('designUserInfoPad', ''));
  $addIfUnset('--sf-userinfo-radius', (string) sf_cfg('designUserInfoRadius', ''));
  $addIfUnset('--sf-side-pad', (string) sf_cfg('designSidebarPad', ''));
  $addIfUnset('--sf-side-inner-pad', (string) sf_cfg('designSidebarInnerPad', ''));
  $addIfUnset('--sf-side-pad-x', (string) sf_cfg('designSidebarPadX', ''));
  $addIfUnset('--sf-side-pad-y', (string) sf_cfg('designSidebarPadY', ''));


  // Buttons & controls tokens
  $btnPreset = (string) sf_cfg('designButtonPreset', 'neutral');
  $btnSize   = (string) sf_cfg('designButtonSize', 'sm');
  $btnRadius = (int) sf_cfg('designButtonRadius', 8);
  if($btnPreset === 'pill') $btnRadius = 999;
  if($btnRadius < 0) $btnRadius = 0;
  $addIfUnset('--sf-btn-radius', $btnRadius . 'px');

  // Size scale
  if($btnSize === 'lg') {
    // Make the Large option clearly noticeable
    $addIfUnset('--sf-btn-font-size', '17px');
    // Use px defaults (easier for non-technical users, and matches the Design Studio UI)
    $addIfUnset('--sf-btn-pad-y', '10px');
    $addIfUnset('--sf-btn-pad-x', '16px');
  } elseif($btnSize === 'md') {
    $addIfUnset('--sf-btn-font-size', '14px');
    $addIfUnset('--sf-btn-pad-y', '8px');
    $addIfUnset('--sf-btn-pad-x', '12px');
  } else {
    $addIfUnset('--sf-btn-font-size', '13px');
    $addIfUnset('--sf-btn-pad-y', '6px');
    $addIfUnset('--sf-btn-pad-x', '10px');
  }

  $addIfUnset('--sf-btn-primary-bg', (string) sf_cfg('designBtnPrimaryBg', ''));
  $addIfUnset('--sf-btn-primary-text', (string) sf_cfg('designBtnPrimaryText', ''));
  $addIfUnset('--sf-btn-primary-hover-bg', (string) sf_cfg('designBtnPrimaryHoverBg', ''));
  $addIfUnset('--sf-btn-primary-hover-text', (string) sf_cfg('designBtnPrimaryHoverText', ''));
  $addIfUnset('--sf-btn-secondary-bg', (string) sf_cfg('designBtnSecondaryBg', ''));
  $addIfUnset('--sf-btn-secondary-text', (string) sf_cfg('designBtnSecondaryText', ''));
  $addIfUnset('--sf-btn-secondary-hover-bg', (string) sf_cfg('designBtnSecondaryHoverBg', ''));
  $addIfUnset('--sf-btn-border', (string) sf_cfg('designBtnBorder', ''));
  $addIfUnset('--sf-btn-text', (string) sf_cfg('designBtnText', ''));
  $addIfUnset('--sf-btn-focus-ring', (string) sf_cfg('designBtnFocusRing', ''));


  // Inputs
  $addIfUnset('--sf-input-bg', (string) sf_cfg('designInputBg', ''));
  $addIfUnset('--sf-input-text', (string) sf_cfg('designInputText', ''));
  $addIfUnset('--sf-input-border', (string) sf_cfg('designInputBorder', ''));
  $addIfUnset('--sf-input-radius', (string) sf_cfg('designInputRadius', ''));
  $addIfUnset('--sf-input-size', (string) sf_cfg('designInputHeight', 'md'));
  $addIfUnset('--sf-input-focus-border', (string) sf_cfg('designInputFocusBorder', ''));
  $addIfUnset('--sf-input-focus-ring', (string) sf_cfg('designInputFocusRing', ''));

  // Badges
  $addIfUnset('--sf-badge-bg', (string) sf_cfg('designBadgeBg', ''));
  $addIfUnset('--sf-badge-text', (string) sf_cfg('designBadgeText', ''));
  $addIfUnset('--sf-badge-radius', (string) sf_cfg('designBadgeRadius', ''));
  $addIfUnset('--sf-badge-vip-bg', (string) sf_cfg('designBadgeVipBg', ''));
  $addIfUnset('--sf-badge-vip-text', (string) sf_cfg('designBadgeVipText', ''));

  if(count($vars)) {
    
/**
 * Normalize Design Studio numeric spacing values to CSS units.
 * - "16" => "16px"
 * - "8 12" => "8px 12px"
 * - "8px 12px" stays
 */
$sfNormalizeUnits = function($val) {
  $val = trim((string)$val);
  if($val === '') return $val;
  // Split by whitespace and normalize each token that is purely numeric (int/float)
  $parts = preg_split('/\s+/', $val);
  $out = [];
  foreach($parts as $p) {
    $p = trim($p);
    if($p === '') continue;
    // Keep CSS functions/vars
    if(strpos($p, 'var(') === 0 || strpos($p, 'calc(') === 0) { $out[] = $p; continue; }
    // Append px if numeric with optional decimal and no unit
    if(preg_match('/^\d+(\.\d+)?$/', $p)) $p .= 'px';
    $out[] = $p;
  }
  return implode(' ', $out);
};

$cssVars = '';
    foreach($vars as $k => $v) {
      $v = str_replace("\"", "", $v);
      // Normalize unitless numeric values for spacing-related variables
      if(in_array($k, ['--sf-index-group-gap','--sf-index-row-gap','--sf-topic-row-gap','--sf-post-row-gap','--sf-pagehead-gap','--sf-group-inner-gap','--sf-signature-sep-size','--sf-btn-pad','--sf-input-pad'])) {
        $v = $sfNormalizeUnits($v);
      }
      $cssVars .= $k . ':' . $v . ';';
    }
    // Design preset selection
    $preset = (string) sf_cfg('designPreset', 'obsidian');
    if($preset === 'pekarna') $preset = 'obsidian'; // legacy preset name (pre-release builds)
    $presetFile = $preset;
    if($presetFile === 'monochromatic' || $presetFile === 'monochrome') $presetFile = 'mono';

    // Apply Design Studio variables ONLY when preset is Custom.
    // This prevents Custom colors from overriding bundled presets.
    // Design Studio values are printed AFTER the preset (see below), so anything the admin
    // sets explicitly overrides the chosen preset; empty fields keep the preset's look.

    // Design preset CSS (Light/Dark/Monochrome/Auto) - injected inline so it can't be broken by minifiers/caches.
    if($preset) {
      $presetFile = $preset;
      if($presetFile === 'monochromatic' || $presetFile === 'monochrome') $presetFile = 'mono';
      // Every preset file = its colour/shape tokens + the shared component skin (built from
      // assets/presets/src/). Custom = a neutral starting palette that Design Studio values override.
      $presetPath = $modPath . "assets/presets/" . $presetFile . ".css";
      if(is_file($presetPath)) {
        $presetCss = (string) file_get_contents($presetPath);
        // Safety: scope to .sf-wrap only (preset files already do, but keep this stable if edited later)
        echo "<style id='sf-preset-vars'>
" . $presetCss . "
</style>";
      }
    }
    // Design Studio variables (scoped to .sf-wrap and :root — the user hover-card lives outside .sf-wrap).
    $dsOut = $cssVars;
    {
      // Button/input values that are only the Design Studio *defaults* must not flatten the preset
      // (Custom included: its starting palette stays until the admin really changes a value).
      foreach(['--sf-btn-radius:8px;', '--sf-btn-font-size:13px;', '--sf-btn-pad-y:6px;', '--sf-btn-pad-x:10px;', '--sf-input-size:md;'] as $dsDef) {
        $dsOut = str_replace($dsDef, '', $dsOut);
      }
    }
    if(trim($dsOut) !== '') echo "<style id='sf-design-vars'>.sf-wrap,:root{" . $dsOut . "}</style>";
    // Bindings: for every Design Studio value that is actually set, apply it to the forum
    // elements directly. This guarantees the setting is visible whatever preset/theme layer
    // would otherwise win (printed after the preset, only for values the admin entered).
    $sfBind = [
      '--sf-bg' => ".sf-wrap{background:VAL!important}",
      '--sf-primary-contrast' => ".sf-wrap .btn-primary,.sf-wrap .sf-btn-primary,.sf-wrap .sf-button-primary{color:VAL!important}",
      '--sf-h1-size' => ".sf-wrap .sf-h1,.sf-wrap .sf-pagehead-title,.sf-wrap h1{font-size:VAL!important}",
      '--sf-h2-size' => ".sf-wrap .sf-h2,.sf-wrap h2,.sf-wrap .sf-card-title{font-size:VAL!important}",
      '--sf-small-text-size' => ".sf-wrap .sf-muted-sm,.sf-wrap small,.sf-wrap .small{font-size:VAL!important}",
      '--sf-label-color' => ".sf-wrap label,.sf-wrap .form-label{color:VAL!important}",
      '--sf-label-font-size' => ".sf-wrap label,.sf-wrap .form-label{font-size:VAL!important}",
      '--sf-title-weight' => ".sf-wrap .sf-link-title,.sf-wrap .sf-index-title,.sf-wrap .sf-last-link,.sf-wrap .sf-lastpost-title,.sf-wrap .sf-member-name{font-weight:VAL!important}",
      '--sf-index-title-size' => ".sf-wrap .sf-link-title,.sf-wrap .sf-index-title{font-size:VAL!important}",
      '--sf-last-title-color' => ".sf-wrap .sf-last-link,.sf-wrap .sf-lastpost-title,.sf-wrap .sf-last-title a{color:VAL!important}",
      '--sf-last-title-size' => ".sf-wrap .sf-last-link,.sf-wrap .sf-lastpost-title,.sf-wrap .sf-last-title{font-size:VAL!important}",
      '--sf-side-block-gap' => ".sf-wrap .sf-side>*+*{margin-top:VAL!important}.sf-wrap .sf-side .sf-sidebox,.sf-wrap .sf-side .sf-sidecard{margin-bottom:0!important}",
      '--sf-side-pad' => ".sf-wrap .sf-side{padding:VAL!important}",
      '--sf-side-inner-pad' => ".sf-wrap .sf-sidebox-b,.sf-wrap .sf-sidecard-b,.sf-wrap .sf-side .sf-card-b{padding:VAL!important}",
      '--sf-sidebar-title-size' => ".sf-wrap .sf-sidebox-h,.sf-wrap .sf-sidecard-h{font-size:VAL!important}",
      '--sf-side-text' => ".sf-wrap .sf-sidebox,.sf-wrap .sf-sidebox-b{color:VAL!important}",
      '--sf-pinned-row-pad' => ".sf-wrap .sf-topic-rowitem.sf-pinned,.sf-wrap .list-group-item.sf-pinned{padding:VAL!important}",
      '--sf-pinned-row-bg' => ".sf-wrap .sf-topic-rowitem.sf-pinned,.sf-wrap .list-group-item.sf-pinned{background:VAL!important}",
      '--sf-btn-pad' => ".sf-wrap .btn,.sf-wrap .sf-btn,.sf-wrap .sf-button,.sf-wrap .sf-button-secondary,.sf-wrap .sf-button-primary{padding:VAL!important}",
      '--sf-btn-font-size' => ".sf-wrap .btn,.sf-wrap .sf-btn,.sf-wrap .sf-button,.sf-wrap .sf-button-secondary,.sf-wrap .sf-button-primary{font-size:VAL!important}",
      '--sf-space' => ".sf-wrap .sf-grid{--sf-grid-gap:VAL}",
      '--sf-accent' => ".sf-wrap .sf-post-card:target{box-shadow:0 0 0 2px VAL!important}",
      '--sf-danger' => ".sf-wrap .btn-danger,.sf-wrap .sf-btn-danger{background:VAL!important;border-color:VAL!important}",
      '--sf-warning' => ".sf-wrap .text-bg-warning{background:VAL!important}",
      '--sf-vip-header-size' => ".sf-wrap .sf-vip-h,.sf-wrap .sf-panel-vip>.card-header{font-size:VAL!important}",
    ];
    $sfBindOut = '';
    foreach($sfBind as $bv => $rule) {
      if(!isset($vars[$bv])) continue;
      $val = trim((string) $vars[$bv]);
      if($val === '' || preg_match('/[{};<>]/', $val)) continue;
      if(strpos($dsOut, $bv . ':') === false) continue; // filtered default
      $sfBindOut .= str_replace('VAL', $val, $rule);
    }
    if($sfBindOut !== '') echo "<style id='sf-design-bindings'>{$sfBindOut}</style>";
// Inline spacing CSS (high specificity) so spacing controls work even if
    // the site is using older copied CSS files or host theme overrides.
    // This is safe for both Simple and Bootstrap themes.
    echo "<style id='sf-spacing-inline'>";
    echo ".sf-wrap .sf-group-list{display:flex!important;flex-direction:column!important;gap:var(--sf-index-group-gap,16px)!important;}";
    echo ".sf-wrap .sf-groupblock{margin:0!important;}";
    // Category rows (index) spacing: apply to any forum-table container (structure may vary between themes/older templates)
    echo ".sf-wrap .sf-forum-table{display:flex!important;flex-direction:column!important;gap:var(--sf-index-row-gap,6px)!important;padding-top:var(--sf-group-inner-gap,10px)!important;padding-bottom:var(--sf-group-inner-gap,10px)!important;}";
    echo ".sf-wrap .sf-forum-table>.sf-forum-row,.sf-wrap .sf-forum-table>.sf-forum-rowitem{margin:0!important;}";
    echo ".sf-wrap .sf-forum-row,.sf-wrap .sf-forum-rowitem{margin:0!important;}";
    echo ".sf-wrap .sf-topic-list{display:flex!important;flex-direction:column!important;gap:var(--sf-topic-row-gap,6px)!important;}";
    echo ".sf-wrap .sf-topic-rowitem{margin:0!important;}";
    echo ".sf-wrap .sf-post-list{display:flex!important;flex-direction:column!important;gap:var(--sf-post-row-gap,12px)!important;}";
    echo ".sf-wrap .sf-post-card{margin:0!important;}";
    // Gap between last reply and the reply form/editor
    echo ".sf-wrap .sf-post-list + .sf-reply-form-wrapper{margin-top:calc(var(--sf-post-row-gap,12px) + 12px)!important;}.sf-wrap .sf-reply-form-wrapper{margin-top:calc(var(--sf-post-row-gap,12px) + 12px)!important;}";
    // Signature separator has its own setting (separate from the overall post border)
    echo ".sf-wrap .sf-signature{border-top:var(--sf-signature-sep-size,1px) solid var(--sf-signature-sep-color,rgba(0,0,0,.12))!important;padding-top:12px!important;}";
    echo "</style>";
  }

  // Custom CSS override (last-wins)
  // NOTE: Intentionally NOT scoped. Users may target any selectors.
  // This should always output when non-empty (no separate enable toggle required),
  // and it must be printed LAST so it can override earlier forum CSS.
  $sfCustomCssOut = '';
  $rawCss = (string) sf_cfg('designCustomCss', '');
  // Design Studio custom CSS (optional). If set for current template, it appends to global.
  $tplNowCss = 'global';
  try { $pNow = wire('page'); if($pNow && $pNow->template) $tplNowCss = (string)$pNow->template->name; } catch(\Throwable $e) { $tplNowCss = 'global'; }
  if(!$tplNowCss) $tplNowCss = 'global';
  // Always include Design Studio "global" custom CSS, then (optionally) the current template CSS.
  $dsCssGlobal = (string) sf_ds_get('global', 'customcss', 'customCss', '');
  if(trim($dsCssGlobal) !== '') $rawCss = trim($rawCss . "\n\n" . $dsCssGlobal);
  if($tplNowCss !== 'global') {
    $dsCssTpl = (string) sf_ds_get($tplNowCss, 'customcss', 'customCss', '');
    if(trim($dsCssTpl) !== '') $rawCss = trim($rawCss . "\n\n" . $dsCssTpl);
  }
  $rawCss = str_replace(["\r\n", "\r"], "\n", $rawCss);
  $rawCss = str_replace('</style', '<\/style', $rawCss);
  if(trim($rawCss) !== '') {
    $sfCustomCssOut = "<style id='sf-custom-css'>" . $rawCss . "</style>";
  }
} catch(\Throwable $e) { /* ignore */ }

  // Design audit mode (superuser only)
  if((int) sf_cfg('designAuditMode', 0) && isset($user) && $user->isSuperuser()) {
    echo "<style id='sf-audit-css'>"
      . ".sf-wrap{--sf-audit-outline:1px dashed rgba(255,0,0,.35);} "
      . ".sf-wrap .sf-panel,.sf-wrap .sf-post-card,.sf-wrap .sf-topic-row,.sf-wrap .sf-sidebar,.sf-wrap .sf-userinfo,.sf-wrap .sf-toolbar{outline:var(--sf-audit-outline);} "
      . ".sf-wrap .sf-btn,.sf-wrap button,.sf-wrap a.btn{outline:1px dashed rgba(0,128,255,.25);} "
      . ".sf-wrap input,.sf-wrap select,.sf-wrap textarea{outline:1px dashed rgba(0,200,120,.25);} "
      . "</style>";
  }

// Override Bootstrap row gutters inside forum posts/replies to avoid unwanted left/right gaps
echo "<style>
  .sf-wrap.sf-wrap-bootstrap .sf-post-card .row{--bs-gutter-x:0;--bs-gutter-y:0;}
  .sf-wrap.sf-wrap-bootstrap .sf-post-card .row > *{padding-left:0!important;padding-right:0!important;margin-top:0!important;}
</style>";

// Optional compact layout stylesheet
$stylePreset = (string) sf_cfg('forumStyle', 'classic');
if($stylePreset === 'compact') {
  $__sfCompactV = @filemtime(rtrim($modPath, '/') . '/assets/css/sf-compact.css') ?: 1;
  echo "<link rel='stylesheet' href='{$modUrl}assets/css/sf-compact.css?v={$__sfCompactV}' />";
}

// Print custom CSS LAST so it can override everything above (including compact preset CSS).
// If you need to override rules that use !important, add !important in your custom CSS.
if(!empty($sfCustomCssOut)) echo $sfCustomCssOut;


// Prefer ProcessWire's CKEditor 4 bundle (frontend WYSIWYG)
// Only load the (heavy) editor for visitors who can actually write something.
$sfNeedsEditor = false;
try { $sfNeedsEditor = wire('user')->isLoggedin() || ($forum && $forum->canPost()); } catch(\Throwable $e) { $sfNeedsEditor = false; }
if($sfNeedsEditor && (int) sf_cfg('enableFrontendWysiwyg', 1)) {
  // Toolbar preset from module settings (basic / standard / full)
  $tb = (string) sf_cfg('frontendCkToolbar', 'standard');
  if(!$tb) $tb = 'standard';
  echo "<script>window.SF_CK_TOOLBAR=" . json_encode($tb) . ";</script>";

  // Detect CKEditor base path dynamically (PW versions ship different folder names)
  $ckBaseUrl = '';
  try {
    $basePath = rtrim($config->paths->wire, '/') . '/modules/Inputfield/InputfieldCKEditor/';
    $cands = glob($basePath . 'ckeditor*/ckeditor.js');
    // Newer ProcessWire: InputfieldCKEditor installed as a site module
    if(!$cands) $cands = glob(rtrim($config->paths->siteModules, '/') . '/InputfieldCKEditor/ckeditor*/ckeditor.js');
    if($cands && isset($cands[0])) {
      $dir = str_replace('\\', '/', dirname($cands[0])) . '/';
      $rootFs = str_replace('\\', '/', rtrim($config->paths->root, '/')) . '/';
      $rel = str_replace($rootFs, '', $dir);
      $ckBaseUrl = rtrim($config->urls->root, '/') . '/' . ltrim($rel, '/');
    }
  } catch(\Throwable $e) { $ckBaseUrl = ''; }

  if($ckBaseUrl) {
    // Not loaded here: sf-editor.js loads CKEditor only on pages that have an editor, and only
    // after the page has finished loading (or as soon as the visitor clicks into the text box).
    echo "<script>window.CKEDITOR_BASEPATH=" . json_encode($ckBaseUrl) . ";window.SF_CK_SRC=" . json_encode($ckBaseUrl . 'ckeditor.js') . ";</script>";
  }
}

if($sfNeedsEditor) echo "<script src='{$modUrl}assets/js/sf-editor.js?v=29' defer></script>";
// Cache-busting for UI JS (prevents stale mobile/accordion behavior under ProCache/AIOM)
$__sfUiV = 1;
try {
  $p = rtrim($modPath, '/').'/assets/js/sf-ui.js';
  $t = @filemtime($p);
  if($t) $__sfUiV = $t;
} catch(\Throwable $e) {}
echo "<script src='{$modUrl}assets/js/sf-ui.js?v={$__sfUiV}' defer></script>";

echo <<<'SFJS'
<script>
(function(){
  function _decodeHtml(s){
    try{
      var ta=document.createElement('textarea');
      ta.innerHTML=s;
      return ta.value;
    }catch(e){ return s; }
  }
  function _extractSrc(html){
    var m = html.match(/src\s*=\s*['"]([^'"]+)['"]/i);
    return m ? m[1] : '';
  }
  function _ytId(u){
    if(!u) return null;
    var m = u.match(/\/embed\/([A-Za-z0-9_-]{6,})/);
    if(m) return m[1];
    m = u.match(/[?&]v=([A-Za-z0-9_-]{6,})/);
    if(m) return m[1];
    m = u.match(/youtu\.be\/([A-Za-z0-9_-]{6,})/);
    if(m) return m[1];
    return null;
  }
  function _vimeoId(u){
    if(!u) return null;
    var m = u.match(/vimeo\.com\/video\/(\d+)/);
    if(m) return m[1];
    m = u.match(/vimeo\.com\/(\d+)/);
    if(m) return m[1];
    return null;
  }
  function _tokenForIframe(iframeHtml){
    var src=_extractSrc(iframeHtml);
    var y=_ytId(src);
    if(y) return '[sfyt:' + y + ']';
    var v=_vimeoId(src);
    if(v) return '[sfvimeo:' + v + ']';
    return ''; // strip unknown iframes
  }

  function sfTokenizeEmbedsRaw(html){
    if(!html) return html;

    // 1) CKEditor fake iframe placeholder (image tag with data-cke-realelement)
    html = html.replace(/<img[^>]*\bcke_iframe\b[^>]*>/gi, function(tag){
      var m = tag.match(/data-cke-realelement=\"([^\"]+)\"/i);
      if(!m) return '';
      var real = _decodeHtml(m[1]);
      // real is usually &lt;iframe ...&gt;
      real = _decodeHtml(real);
      return _tokenForIframe(real) || '';
    });

    // 2) Escaped iframe blocks typed/pasted in source mode
    html = html.replace(/&lt;iframe[\s\S]*?&lt;\/iframe&gt;/gi, function(block){
      var decoded=_decodeHtml(block);
      return _tokenForIframe(decoded) || '';
    });

    // 3) Literal iframe elements
    html = html.replace(/<iframe[\s\S]*?<\/iframe>/gi, function(block){
      return _tokenForIframe(block) || '';
    });

    // 4) Last resort: remove any remaining 'iframe' substring to avoid WAF blocks
    html = html.replace(/iframe/gi, '');

    return html;
  }

  window.sfPreSubmitReply = function(form){
    try{
      var ta = form.querySelector('textarea[name=\"body\"]');
      if(!ta) return true;

      var html = ta.value;

      // CKEditor instance?
      if(window.CKEDITOR) {
        for (var n in CKEDITOR.instances) {
          var inst = CKEDITOR.instances[n];
          if(inst && inst.element && inst.element.$ === ta) {
            html = inst.getData();
            break;
          }
        }
      }

      if(window.sfEditorHtmlToTokens) { try { html = window.sfEditorHtmlToTokens(html); } catch(e) {} }
      var cleaned = sfTokenizeEmbedsRaw(html);

      // also set textarea value as fallback
      ta.value = cleaned;

      return true;
    } catch(e) {
      try{
        var ta2 = form.querySelector('textarea[name=\"body\"]');
        if(ta2) ta2.value = (ta2.value||'').replace(/iframe/gi,'');
      }catch(_){}
      return true;
    }
  };
})();
</script>
SFJS;

if($sfNeedsEditor) echo "<script src='{$modUrl}assets/js/sf-quote.js?v=23' defer></script>";
if(wire('user')->isLoggedin()) echo "<script src='{$modUrl}assets/js/sf-follow.js?v=20' defer></script>";

if($sfNeedsEditor && (int)sf_cfg('attachmentsEnabled', 1)) {
  echo "<script src='{$modUrl}assets/js/sf-uploads.js?v=22' defer></script>";
}
if((int)sf_cfg('attachmentsEnabled',1) && (int)sf_cfg('attachmentsAllowImages',1) && (int)sf_cfg('attachmentsEnableLightbox',1)) {
  echo "<script src='{$modUrl}assets/js/sf-lightbox.js?v=20' defer></script>";
}

    }

    
    // Theme variables for the Simple (built-in) theme
    // (Bootstrap theme variables are handled further below.)
    if($theme !== 'bootstrap' && empty($GLOBALS['nativeForumSimpleThemeVarsLoaded'])) {
      $GLOBALS['nativeForumSimpleThemeVarsLoaded'] = true;

      // Header colour settings. Explicit values override the design preset (scoped to .sf-wrap,
      // which beats the preset's own .sf-wrap tokens because it is printed later). Empty values
      // fall back to the preset — or to the classic defaults when no preset is used.
      $sfUsesPreset = ((string) sf_cfg('designPreset', 'obsidian')) !== 'custom';
      $sfHdr = [
        '--sf-header-bg' => ['headerBgColor', '#1f2937'],
        '--sf-header-color' => ['headerTextColor', '#ffffff'],
        '--sf-vip-header-bg' => ['vipHeaderBgColor', '#dc3545'],
        '--sf-vip-header-color' => ['vipHeaderTextColor', '#ffffff'],
        '--sf-sidebar-header-bg' => ['sidebarHeaderBgColor', ''],
        '--sf-sidebar-header-color' => ['sidebarHeaderTextColor', ''],
      ];
      // Design Studio sidebar header tokens win over the legacy sidebar settings
      if(trim((string) sf_cfg('ds_global_header_sidebarHeaderBg', '')) !== '') unset($sfHdr['--sf-sidebar-header-bg']);
      if(trim((string) sf_cfg('ds_global_header_sidebarHeaderText', '')) !== '') unset($sfHdr['--sf-sidebar-header-color']);
      $sfHdrSet = ''; $sfHdrDef = '';
      foreach($sfHdr as $var => $d) {
        $raw = trim((string) sf_cfg($d[0], ''));
        if($raw !== '') { $sfHdrSet .= $var . ':' . sf_sanitize_css_color($raw, $d[1] ?: '#000000') . ';'; }
        elseif($d[1] !== '') { $sfHdrDef .= $var . ':' . $d[1] . ';'; }
      }
      if(!$sfUsesPreset && $sfHdrDef !== '') echo "<style>:root{{$sfHdrDef}}</style>";
      if($sfHdrSet !== '') echo "<style id='sf-header-colors'>:root,.sf-wrap{{$sfHdrSet}}</style>";
      echo "<style>";
      echo ".sf-card-h,.sf-groupbar{background:var(--sf-header-bg)!important;color:var(--sf-header-color)!important;}";
      echo ".sf-panel>.card-header{background:var(--sf-header-bg)!important;color:var(--sf-header-color)!important;}";
      echo ".sf-panel>.card-header a{color:inherit!important;}";
      echo ".sf-breadcrumbbar{background:var(--sf-header-bg)!important;color:var(--sf-header-color)!important;}";
      echo ".sf-breadcrumbbar a{color:inherit!important;}";
      echo ".sf-toolbar{background:var(--sf-header-bg)!important;color:var(--sf-header-color)!important;}";
      echo ".sf-toolbar a{color:inherit!important;}";
      echo ".sf-wrap .card{background:var(--sf-card);border:1px solid var(--sf-border);border-radius:4px;overflow:hidden;}";
      echo ".sf-wrap .card-header{background:var(--sf-header-bg)!important;color:var(--sf-header-color)!important;padding:var(--sf-usercard-padding, 10px 12px);font-weight:700;font-size:13px;letter-spacing:.3px;text-transform:uppercase;border-bottom:1px solid rgba(0,0,0,.06);}";
      echo ".sf-wrap .card-header a{color:inherit!important;text-decoration:none;}";
      echo ".sf-wrap .card-body{padding:12px;}";
      echo ".sf-toolbar a,.sf-toolbar .sf-muted-sm{color:inherit!important;}";
      echo ".sf-vip-h{background:var(--sf-vip-header-bg)!important;color:var(--sf-vip-header-color)!important;}";
      echo ".sf-sidebox-h{background:var(--sf-sidebar-header-bg)!important;color:var(--sf-sidebar-header-color)!important;}";
      echo "</style>";

    }

// Frontend theme variables (Bootstrap theme only)
    if($theme === 'bootstrap' && empty($GLOBALS['nativeForumThemeVarsLoaded'])) {
      $GLOBALS['nativeForumThemeVarsLoaded'] = true;
      $GLOBALS['nativeForumSidebarVarsLoaded'] = true;

      $hb  = (string) sf_cfg('headerBgColor', '#0d6efd');
      $ht  = (string) sf_cfg('headerTextColor', '#ffffff');
      $vhb = (string) sf_cfg('vipHeaderBgColor', '#dc3545');
      $vht = (string) sf_cfg('vipHeaderTextColor', '#ffffff');
      $shb = (string) sf_cfg('sidebarHeaderBgColor', '#f8f9fa');
      $sht = (string) sf_cfg('sidebarHeaderTextColor', '#212529');

      foreach(['hb','ht','vhb','vht','shb','sht'] as $k) {
        $$k = sf_sanitize_css_color($$k, '#000000');
      }

      echo "<style>:root{--sf-header-bg:{$hb};--sf-header-color:{$ht};--sf-vip-header-bg:{$vhb};--sf-vip-header-color:{$vht};}";
      echo ".sf-panel>.card-header{background:var(--sf-header-bg)!important;color:var(--sf-header-color)!important;}";
      echo ".sf-panel>.card-header a{color:inherit!important;}";
      echo ".sf-breadcrumbbar{background:var(--sf-header-bg)!important;color:var(--sf-header-color)!important;}";
      echo ".sf-breadcrumbbar a{color:inherit!important;}";
      echo ".sf-toolbar{background:var(--sf-header-bg)!important;color:var(--sf-header-color)!important;}";
      echo ".sf-toolbar a,.sf-toolbar .sf-muted-sm{color:inherit!important;}";
      echo ".sf-toolbar .form-control{background:#fff!important;color:#000!important;}";

      // Unify ALL card headers across the forum (category/topic/profile/etc.)
      // Some templates use plain Bootstrap .card-header (not wrapped in .sf-panel),
      // so we must style .sf-wrap .card-header too.
      echo ".sf-wrap .card-header,.sf-wrap .sf-card-header,.sf-wrap .sf-card-h{background:var(--sf-header-bg)!important;color:var(--sf-header-color)!important;padding:var(--sf-header-pad, 10px 12px)!important;}";
      echo ".sf-wrap .card-header a,.sf-wrap .sf-card-header a,.sf-wrap .sf-card-h a{color:inherit!important;text-decoration:none;}";
      echo ".sf-wrap .card-header *,.sf-wrap .sf-card-header *,.sf-wrap .sf-card-h *{color:inherit!important;}";

      echo ".sf-sidecard-h{background:var(--sf-sidebar-header-bg)!important;color:var(--sf-sidebar-header-color)!important;}";
      echo ".sf-sidecard-h a{color:inherit!important;}";
      echo ".sf-sidebox-h{background:var(--sf-sidebar-header-bg)!important;color:var(--sf-sidebar-header-color)!important;}";
      echo ".sf-panel.sf-panel-vip>.card-header{background:var(--sf-vip-header-bg)!important;color:var(--sf-vip-header-color)!important;}";
      echo ".sf-panel.sf-panel-vip>.card-header a{color:inherit!important;}";
      echo ".sf-post-card.sf-post-vip .sf-post-meta{background:var(--sf-vip-header-bg)!important;color:var(--sf-vip-header-color)!important;}";
      echo ".sf-post-card.sf-post-vip .sf-post-meta a{color:inherit!important;}";
      echo "</style>";
    }

    // Site loader bypass: injected into <head> by NativeForum::hookAfterPageRender()
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_format_dt')) {
  /**
   * Format timestamps across the forum, based on module setting `dateTimeStyle`.
   */
  function sf_format_dt(int $ts, bool $forceTime = false): string {
    if($ts <= 0) return '';

    $style = 'iso_dt';
    try {
      $m = wire('modules')->get('NativeForum');
      if($m) $style = (string) ($m->get('dateTimeStyle') ?? 'iso_dt');
    } catch(\Throwable $e) {
      $style = 'iso_dt';
    }

    
    if($forceTime) {
      // Map date-only styles to their time variants for contexts where time is required (e.g. private messages list)
      $map = [
        'iso_d' => 'iso_dt',
        'dmy_short' => 'dmy_short_time',
        'dmy_long' => 'dmy_long_time',
        'd_mon_abbr' => 'd_mon_abbr_time',
        'd_mon_full' => 'd_mon_full_time',
      ];
      if(isset($map[$style])) $style = $map[$style];
    }

// Slovenian month names (fallback for "december" styles)
    $mAbbr = [1=>'jan.',2=>'feb.',3=>'mar.',4=>'apr.',5=>'maj',6=>'jun.',7=>'jul.',8=>'avg.',9=>'sep.',10=>'okt.',11=>'nov.',12=>'dec.'];
    $mFull = [1=>'januar',2=>'februar',3=>'marec',4=>'april',5=>'maj',6=>'junij',7=>'julij',8=>'avgust',9=>'september',10=>'oktober',11=>'november',12=>'december'];

    $day = (int) date('j', $ts);
    $mon = (int) date('n', $ts);
    $yr  = (int) date('Y', $ts);
    $hm  = date('H:i', $ts);

    switch($style) {
      case 'iso_d':
        return date('Y-m-d', $ts);
      case 'dmy_short':
        return date('d.m.y', $ts);
      case 'dmy_short_time':
        return date('d.m.y H:i', $ts);
      case 'dmy_long':
        return date('d.m.Y', $ts);
      case 'dmy_long_time':
        return date('d.m.Y H:i', $ts);
      case 'd_mon_abbr':
        return sprintf('%d. %s %d', $day, $mAbbr[$mon] ?? date('M', $ts), $yr);
      case 'd_mon_abbr_time':
        return sprintf('%d. %s %d %s', $day, $mAbbr[$mon] ?? date('M', $ts), $yr, $hm);
      case 'd_mon_full':
        return sprintf('%d. %s %d', $day, $mFull[$mon] ?? date('F', $ts), $yr);
      case 'd_mon_full_time':
        return sprintf('%d. %s %d %s', $day, $mFull[$mon] ?? date('F', $ts), $yr, $hm);
      case 'iso_dt':
      default:
        return date('Y-m-d H:i', $ts);
    }
  }

  /**
   * Forum search helper.
   *
   * Scopes:
   * - everywhere: search across the whole forum root
   * - this:       search within the provided context (category/group/root)
   * - topics:     only topics
   * - posts:      topics + replies
   * - categories: groups + categories
   * - members:    users
   */
  function sf_forum_search($forum, Page $forumRoot, Page $context, string $q, string $scope, Pages $pages, Users $users, Sanitizer $sanitizer, ?User $viewer = null, int $limit = 50): array {
    $q = trim($q);
    if($q === '') return [
      'scope' => $scope,
      'q' => $q,
      'topics' => new PageArray(),
      'replies' => new PageArray(),
      'categories' => new PageArray(),
      'members' => [],
    ];

    $scope = $scope ?: 'this';
    $sv = $sanitizer->selectorValue($q);

// TAG SEARCH: if query starts with "#tag" treat it as tag search for topics
$tagQuery = '';
if(preg_match('/^\s*#([a-z0-9_-]{1,30})\s*$/i', $q, $m)) {
  $tagQuery = strtolower($m[1]);
}



    // Build a list of searchable text fields that exist on this installation.
    // NOTE: We intentionally use the '*=' operator for broad compatibility
    // (some installations don't have fulltext indexes for textarea fields).
    $fieldsApi = wire('fields');
    $topicFields = ['title'];
    foreach(['forum_body','forum_summary','body','summary'] as $fn) {
      try {
        if($fieldsApi && $fieldsApi->get($fn)) $topicFields[] = $fn;
      } catch(\Throwable $e) {}
    }
    $topicFields = array_values(array_unique($topicFields));
    $topicMatch = '(' . implode('|', $topicFields) . "*={$sv}" . ')';

    $replyFields = [];
    foreach(['forum_body','body'] as $fn) {
      try {
        if($fieldsApi && $fieldsApi->get($fn)) $replyFields[] = $fn;
      } catch(\Throwable $e) {}
    }
    if(!count($replyFields)) $replyFields = ['forum_body'];
    $replyFields = array_values(array_unique($replyFields));
    $replyMatch = '(' . implode('|', $replyFields) . "*={$sv}" . ')';

    // Determine base container
    $baseId = ($scope === 'this') ? (int)$context->id : (int)$forumRoot->id;
    if($scope === 'everywhere') $baseId = (int)$forumRoot->id;

    $isMod = false;
    try { $isMod = ($forum && $viewer && method_exists($forum,'isModerator') && $forum->isModerator($viewer)); } catch(\Throwable $e) {}
    $vis = $isMod ? 'include=all' : 'status<hidden';

    $outTopics = new PageArray();
    $outReplies = new PageArray();
    $outCats = new PageArray();
    $outMembers = [];

    // Topics
    if(in_array($scope, ['everywhere','this','topics','posts'], true)) {
      $sel = "template=forum-topic, has_parent={$baseId}, {$vis}, {$topicMatch}, sort=-created, limit={$limit}";
      $found = $pages->find($sel);
      foreach($found as $t) {
        if($forum && method_exists($forum,'canReadForum') && $viewer && !$forum->canReadForum($viewer, $t)) continue;
        // VIP category check
        if($forum && method_exists($forum,'canReadCategory') && $viewer && $t->parent && !$forum->canReadCategory($viewer, $t->parent)) continue;
        $outTopics->add($t);
      }
    }

    // Replies (posts)
    if(in_array($scope, ['everywhere','this','posts'], true)) {
      $sel = "template=forum-reply, has_parent={$baseId}, {$vis}, {$replyMatch}, sort=-created, limit={$limit}";
      $found = $pages->find($sel);
      foreach($found as $r) {
        $topic = $r->parent;
        if(!$topic || !$topic->id) continue;
        if($forum && method_exists($forum,'canReadForum') && $viewer && !$forum->canReadForum($viewer, $topic)) continue;
        if($forum && method_exists($forum,'canReadCategory') && $viewer && $topic->parent && !$forum->canReadCategory($viewer, $topic->parent)) continue;
        $outReplies->add($r);
      }
    }

    // Categories / Groups
    if(in_array($scope, ['everywhere','this','categories'], true)) {
      $sel = "template=forum-group|forum-category, has_parent={$baseId}, {$vis}, title*={$sv}, sort=title, limit={$limit}";
      $found = $pages->find($sel);
      foreach($found as $c) {
        if($forum && method_exists($forum,'canReadForum') && $viewer && !$forum->canReadForum($viewer, $c)) continue;
        if($c->template && $c->template->name === 'forum-category' && $forum && method_exists($forum,'canReadCategory') && $viewer && !$forum->canReadCategory($viewer, $c)) continue;
        $outCats->add($c);
      }
    }

    // Members
    if(in_array($scope, ['everywhere','members'], true)) {
      $uFields = ['name','email'];
      try { if($fieldsApi && $fieldsApi->get('forum_nickname')) $uFields[] = 'forum_nickname'; } catch(\Throwable $e) {}
      $uMatch = '(' . implode('|', $uFields) . "*={$sv}" . ')';
      $sel = "id>0, name!=guest, check_access=0, {$uMatch}, sort=name, limit={$limit}";
      $found = $users->find($sel);
      foreach($found as $u) $outMembers[] = $u;
    }

    return [
      'scope' => $scope,
      'q' => $q,
      'topics' => $outTopics,
      'replies' => $outReplies,
      'categories' => $outCats,
      'members' => $outMembers,
    ];
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_user_label')) {
  function sf_user_label(?User $u, Sanitizer $sanitizer): string {
    return $sanitizer->entities(sf_user_display_name($u));
  }
}

// Resolve a display name for a user (nickname > username). Returns plain text (NOT escaped).
if(!function_exists(__NAMESPACE__ . '\sf_user_display_name')) {
  function sf_user_display_name(?User $u): string {
    if(!$u || !$u->id) return 'Unknown';
    $nick = '';
    try {
      if(method_exists($u, 'hasField') && $u->hasField('forum_nickname')) {
        $nick = (string) $u->get('forum_nickname');
      } elseif(isset($u->forum_nickname)) {
        $nick = (string) $u->forum_nickname;
      }
    } catch(\Throwable $e) {
      $nick = '';
    }
    $nick = trim($nick);
    return $nick !== '' ? $nick : (string) $u->name;
  }
}

// Load user by id without access restrictions (request-cached, batch-friendly).
if(!function_exists(__NAMESPACE__ . '\sf_user_by_id')) {
  function sf_user_by_id(int $id): ?User {
    if($id <= 0) return null;
    $forum = wire('modules')->get('NativeForum');
    if($forum && method_exists($forum, 'userById')) return $forum->userById($id);
    $u = wire('users')->get("id=$id, include=all, check_access=0");
    return ($u && $u->id) ? $u : null;
  }
}

// Author user id of a topic/reply without loading the user.
if(!function_exists(__NAMESPACE__ . '\sf_author_id')) {
  function sf_author_id(Page $p): int {
    try {
      if(!$p->hasField('forum_author')) return (int) $p->created_users_id;
      $fa = $p->getUnformatted('forum_author');
      if($fa instanceof PageArray) $fa = $fa->first();
      if($fa instanceof Page && $fa->id) return (int) $fa->id;
      if(is_numeric($fa)) return (int) $fa;
      return (int) $p->created_users_id;
    } catch(\Throwable $e) { return 0; }
  }
}

// Resolve the author user for a forum page (topic/reply) even when user pages are not viewable.
if(!function_exists(__NAMESPACE__ . '\sf_page_author_user')) {
  function sf_page_author_user(Page $p): ?User {
    // Preferred: explicit forum_author field
    try {
      if($p->hasField('forum_author')) {
        $fa = $p->get('forum_author');
        if($fa instanceof User && $fa->id) return sf_user_by_id((int)$fa->id) ?: $fa;
        if($fa instanceof Page && $fa->id) return sf_user_by_id((int)$fa->id);
        if(is_numeric($fa)) return sf_user_by_id((int)$fa);
      }
    } catch(\Throwable $e) {
      // ignore
    }

    // Fallback: created user id stored on the page
    $cid = 0;
    try {
      if(isset($p->created_users_id)) $cid = (int) $p->created_users_id;
    } catch(\Throwable $e) {
      $cid = 0;
    }
    if($cid) return sf_user_by_id($cid);

    // Last resort: createdUser object
    try {
      $cu = $p->createdUser;
      if($cu instanceof User && $cu->id) return sf_user_by_id((int)$cu->id) ?: $cu;
    } catch(\Throwable $e) {
      // ignore
    }
    return null;
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_user_profile_url')) {
  function sf_user_profile_url($forum, $pages, User $u): string {
    if(!$u || !$u->id) return '#';
    // Clean /forum-user/{name}/ URLs when URL segments are enabled (SEO), ?id= fallback otherwise.
    if($forum && method_exists($forum, 'userProfileUrl')) return (string) $forum->userProfileUrl($u);
    $root = $pages->get('/forum-user/');
    return ($root && $root->id) ? $root->url . '?id=' . (int)$u->id : '#';
  }
}

// Some templates (e.g. compact forum index) need an avatar helper.
// Keep it here so copied templates work without requiring extra includes.
if(!function_exists(__NAMESPACE__ . '\sf_user_avatar')) {
  /**
   * Return the first avatar image for a user, if any.
   * Looks for forum_avatar (preferred), then avatar, then any image field.
   */
  function sf_user_avatar(User $u): ?Pageimage {
    if(!$u || !$u->id) return null;

    $img = null;

    // Access user fields safely
    $u->of(false);
    try {
      $fieldVal = null;

      if(method_exists($u, 'hasField') && $u->hasField('forum_avatar') && $u->get('forum_avatar')) {
        $fieldVal = $u->get('forum_avatar');
      } elseif(method_exists($u, 'hasField') && $u->hasField('avatar') && $u->get('avatar')) {
        $fieldVal = $u->get('avatar');
      } else {
        // Fallback: first image field that has an image
        foreach($u->fields as $f) {
          if($f->type instanceof FieldtypeImage) {
            $images = $u->get($f->name);
            if($images) { $fieldVal = $images; break; }
          }
        }
      }

      if($fieldVal instanceof Pageimages) $img = $fieldVal->first();
      elseif($fieldVal instanceof Pageimage) $img = $fieldVal;

      // Normalize: Pageimages::first() can return false → convert to null
      if($img === false || !($img instanceof \ProcessWire\Pageimage)) $img = null;

    } catch(\Throwable $e) {
      $img = null;
    }

    $u->of(true);
    return $img;
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_user_link')) {
  /**
   * Render a user profile link.
   * In Bootstrap theme, the link also gets a popover with basic user info on hover.
   *
   * @param User|null $u
   * @param mixed $forum
   * @param Pages $pages
   * @param Sanitizer $sanitizer
   * @param array $opts Supported keys:
   *  - class: string extra classes
   *  - showUnknownAsText: bool (default true)
   */
  function sf_user_link(?User $u, $forum, Pages $pages, Sanitizer $sanitizer, array $opts = []): string {
    $showUnknownAsText = array_key_exists('showUnknownAsText', $opts) ? (bool)$opts['showUnknownAsText'] : true;
    $extraClass = isset($opts['class']) ? trim((string)$opts['class']) : '';

    if(!$u || !$u->id) {
      return $showUnknownAsText ? "<span class='sf-muted'>Unknown</span>" : '';
    }

    $theme = sf_forum_theme($forum);
    $namePlain = sf_user_display_name($u);
    $nameEsc = $sanitizer->entities($namePlain);
    $url = sf_user_profile_url($forum, $pages, $u);

    $rank = '';
    try {
      if($forum && is_object($forum) && method_exists($forum, 'getUserRank')) {
        $rank = (string)$forum->getUserRank($u);
      } elseif(method_exists($u, 'hasField') && $u->hasField('forum_rank')) {
        $rank = (string)$u->get('forum_rank');
      }
    } catch(\Throwable $e) {
      $rank = '';
    }
    $rank = trim($rank);
    $joined = '';
    try {
      if((int)$u->created) $joined = sf_format_dt((int)$u->created);
    } catch(\Throwable $e) {
      $joined = '';
    }

    $classes = trim('sf-userlink ' . $extraClass);

    // Build structured hover-card data for BOTH themes.
    $titleAttr = htmlspecialchars($namePlain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $urlAttr = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Avatar for hover card
    $avatarUrl = '';
    try {
      $img = sf_user_avatar($u);
      if($img) $avatarUrl = $img->size(96,96)->url;
    } catch(\Throwable $e) { $avatarUrl = ''; }
    $avatarAttr = htmlspecialchars($avatarUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $initial = mb_substr($namePlain, 0, 1, 'UTF-8');
    $initialAttr = htmlspecialchars($initial, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Stats
    // Compute from forum content (topics+replies) + votes/likes received.
    // Cached to keep hover fast.
    $msgCount = 0; $reactScore = 0; $points = 0; $ratings = 0;
    try {
      $cache = wire('cache');
      $pagesW = wire('pages');
      $uid = (int) $u->id;
      $sfF = wire('modules')->get('NativeForum');
      $sfSt = $sfF ? $sfF->userStats([$uid]) : [];
      $stats = $sfSt[$uid] ?? [];

      $msgCount   = isset($stats['messages']) ? (int) $stats['messages'] : 0;
      $reactScore = isset($stats['reaction']) ? (int) $stats['reaction'] : 0;
      $points     = isset($stats['points'])   ? (int) $stats['points']   : 0;
      $ratings    = isset($stats['ratings'])  ? (int) $stats['ratings']  : 0;

      // Optional user fields override computed values (if present)
      if(method_exists($u,'hasField') && $u->hasField('forum_reaction_score')) $reactScore = (int) $u->get('forum_reaction_score');
      if(method_exists($u,'hasField') && $u->hasField('forum_points')) $points = (int) $u->get('forum_points');
      if(method_exists($u,'hasField') && $u->hasField('forum_ratings_received')) $ratings = (int) $u->get('forum_ratings_received');
      if(method_exists($u,'hasField') && ($u->hasField('forum_messages') || $u->hasField('forum_posts'))) {
        $msgCount = (int) ($u->hasField('forum_messages') ? $u->get('forum_messages') : $u->get('forum_posts'));
      }
    } catch(\Throwable $e) {
      // ignore
    }

    // If these don't exist, return 0 but also allow frontend to relabel to Topics/Replies if desired.
    if($reactScore === null) $reactScore = 0;
    if($points === null) $points = 0;
    if($ratings === null) $ratings = 0;

    // Last seen
    $lastSeen = '';
    try {
      $ts = (int)($u->get('lastLogin') ?: 0);
      if($ts > 0) $lastSeen = sf_format_dt($ts, true);
    } catch(\Throwable $e) { $lastSeen = ''; }

    // PM url for "Start conversation"
    $pmUrl = '';
    try {
      $viewer = wire('user');
      if($viewer && $viewer->isLoggedin() && $forum && method_exists($forum,'privateMessagesEnabled') && $forum->privateMessagesEnabled() && (int)$viewer->id !== (int)$u->id) {
        $msgRoot = $forum->messagesRoot();
        if($msgRoot && $msgRoot->id) $pmUrl = $msgRoot->url . '?to=' . (int)$u->id;
      }
    } catch(\Throwable $e) { $pmUrl = ''; }
    $pmUrlAttr = htmlspecialchars($pmUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $rankAttr = htmlspecialchars($rank, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $joinedAttr = htmlspecialchars($joined, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lastSeenAttr = htmlspecialchars($lastSeen, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return "<span class='sf-userhover' data-sf-usercard='1' data-sf-user-url='{$urlAttr}' data-sf-usercard-title='{$titleAttr}' data-sf-user-rank='{$rankAttr}' data-sf-user-joined='{$joinedAttr}' data-sf-user-lastseen='{$lastSeenAttr}' data-sf-user-messages='{$msgCount}' data-sf-user-reactions='{$reactScore}' data-sf-user-points='{$points}' data-sf-user-ratings='{$ratings}' data-sf-user-pm-url='{$pmUrlAttr}' data-sf-user-avatar='{$avatarAttr}' data-sf-user-initial='{$initialAttr}'><a class='{$classes}' href='{$urlAttr}'>{$nameEsc}</a></span>";
  }
}

// Helper: wrap any inner HTML (avatar, icon) in the same hover-card markup as sf_user_link().
if(!function_exists(__NAMESPACE__ . '\\sf_user_hover_wrap')) {
  function sf_user_hover_wrap(User $u, $forum, Pages $pages, Sanitizer $sanitizer, string $innerHtml, string $aClass = ''): string {
    if(!$u || !$u->id) return $innerHtml;
    $namePlain = sf_user_display_name($u);
    $url = sf_user_profile_url($forum, $pages, $u);
    $aCls = trim('sf-userlink ' . $aClass);

    // Reuse sf_user_link() data attributes by rendering a normal link and swapping the inner HTML.
    $link = sf_user_link($u, $forum, $pages, $sanitizer, ['class' => $aCls]);
    // The link returned by sf_user_link() already includes the wrapper span + anchor.
    // Replace anchor inner text with provided HTML.
    $link = preg_replace('#(<a[^>]*>)(.*?)(</a>)#s', '$1' . $innerHtml . '$3', $link, 1);
    return $link ?: $innerHtml;
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_sidebar_html')) {
  /**
   * @param User|null $currentUser Optional - used for login/register buttons.
   */
  function sf_sidebar_html($forum, Page $context, Pages $pages, Users $users, Sanitizer $sanitizer, ?User $currentUser = null): string {
    $theme = sf_forum_theme($forum);
    if(!$currentUser) $currentUser = wire('user');
    $isMod = false;
    try {
      if($forum && method_exists($forum, 'isModerator')) $isMod = (bool) $forum->isModerator($currentUser);
    } catch(\Throwable $e) { $isMod = false; }


    $wrap = function($title, $body) use ($theme) {
      if($theme === 'bootstrap') {
        $isList = (strpos((string) $body, "list-group") !== false);
        $bodyClass = $isList ? "p-0" : "py-3";
        return "<div class='card mb-3 sf-sidecard'>"
          . "<div class='card-header py-2 sf-sidecard-h'><strong>{$title}</strong></div>"
          . "<div class='card-body {$bodyClass} sf-sidecard-b'>{$body}</div>"
          . "</div>";
      }
      return "<div class='sf-sidebox'><div class='sf-sidebox-h'>{$title}</div><div class='sf-sidebox-b'>{$body}</div></div>";
    };

    $root = $forum ? $forum->forumRoot() : $pages->get('/forum/');
    $rootId = $root && $root->id ? (int)$root->id : 0;

    // Account block (login/register modals)
    $account = '';
    if($theme === 'bootstrap') {
      if($currentUser && $currentUser->isLoggedin()) {
        $profileUrl = sf_user_profile_url($forum, $pages, $currentUser);
        $unreadPm = 0;
        try { $unreadPm = (int) $forum->unreadMessageCount($currentUser); } catch(\Throwable $e) { $unreadPm = 0; }
        // Logout form (POST + CSRF)
        $session = wire('session');
        $csrfName = $session && $session->CSRF ? $session->CSRF->getTokenName() : '';
        $csrfVal  = $session && $session->CSRF ? $session->CSRF->getTokenValue() : '';
        $logoutForm = "<form method='post' class='m-0'>"
          . ($csrfName ? "<input type='hidden' name='{$csrfName}' value='" . $sanitizer->entities($csrfVal) . "'>" : "")
          . "<input type='hidden' name='sf_logout' value='1'>"
          . "<button class='btn btn-outline-danger btn-sm' type='submit'>Logout</button>"
          . "</form>";
        $account = "<div class='small mb-2'>Logged in as " . sf_user_link($currentUser, $forum, $pages, $sanitizer, ['class' => 'fw-semibold']) . "</div>"
          . sf_pm_account_html($forum)
          . "<div class='d-grid gap-2'>"
          . ($profileUrl !== '#' ? "<a class='btn btn-outline-secondary btn-sm d-flex justify-content-between align-items-center' href='{$profileUrl}'>My profile</a>" : "")
          . $logoutForm
          . "</div>";
      } else {
        $account = "<div class='d-grid gap-2'>"
          . "<button class='btn btn-primary btn-sm' type='button'>Login</button>"
          . "<button class='btn btn-outline-primary btn-sm' type='button'>Register</button>"
          . "</div>";
      }
    }

    // Account block for built-in (simple) theme
    if($theme !== 'bootstrap') {
      if($currentUser && $currentUser->isLoggedin()) {
        $profileUrl = sf_user_profile_url($forum, $pages, $currentUser);
        $unreadPm = 0;
        try { $unreadPm = (int) $forum->unreadMessageCount($currentUser); } catch(\Throwable $e) { $unreadPm = 0; }
        // Logout form (POST + CSRF)
        $session = wire('session');
        $csrfName = $session && $session->CSRF ? $session->CSRF->getTokenName() : '';
        $csrfVal  = $session && $session->CSRF ? $session->CSRF->getTokenValue() : '';
        $logoutForm = "<form method='post' class='sf-form-logout'>"
          . ($csrfName ? "<input type='hidden' name='{$csrfName}' value='" . $sanitizer->entities($csrfVal) . "'>" : "")
          . "<input type='hidden' name='sf_logout' value='1'>"
          . "<button class='sf-btn sf-btn-danger sf-btn-sm' type='submit'>Logout</button>"
          . "</form>";
        $account = "<div class='sf-muted sf-mb-2'>Logged in as " . sf_user_link($currentUser, $forum, $pages, $sanitizer, ['class' => 'sf-strong']) . "</div>"
          . sf_pm_account_html($forum)
          . "<div class='sf-auth-stack'>"
          . ($profileUrl !== '#' ? "<a class='sf-btn sf-btn-ghost sf-btn-sm' href='{$profileUrl}'>My profile</a>" : "")
          . $logoutForm
          . "</div>";
      } else {
        $loginPage = $pages->get("template=forum-login, include=all");
        $regPage   = $pages->get("template=forum-register, include=all");
        $loginUrl = ($loginPage && $loginPage->id) ? $loginPage->url : '/forum-login/';
        $regUrl   = ($regPage && $regPage->id) ? $regPage->url : '/forum-register/';
        // Preserve current page for redirect after login/register (supports subfolder installs)
        $req = '';
        try {
          $input = wire('input');
          $req = (string) ($input && isset($input->server->REQUEST_URI) ? $input->server->REQUEST_URI : '');
        } catch(\Throwable $e) { $req = ''; }
        if($req) {
          $q = 'return=' . rawurlencode($req);
          $loginUrl .= (strpos($loginUrl, '?') !== false ? '&' : '?') . $q;
          $regUrl   .= (strpos($regUrl,   '?') !== false ? '&' : '?') . $q;
        }
        $account = "<div class='sf-auth-stack'>"
          . "<a class='sf-btn sf-btn-primary sf-btn-sm' href='{$loginUrl}'>Login</a>"
          . (sf_registration_open() ? "<a class='sf-btn sf-btn-ghost sf-btn-sm' href='{$regUrl}'>Register</a>" : '')
          . "</div>";
      }
    }

    // Counts (cached; cleared whenever forum content changes)
    $sfCnt = null;
    try { $sfCnt = wire('cache')->getFor('NativeForumView', 'sbcnt_' . ($isMod ? 1 : 0) . '_' . $rootId); } catch(\Throwable $e) { $sfCnt = null; }
    if(!is_array($sfCnt)) {
      $categories = $rootId ? $pages->count("template=forum-category, has_parent=$rootId" . ($isMod ? ", include=all" : ", status<hidden")) : 0;
      $groups     = $rootId ? $pages->count("template=forum-group, parent=$rootId" . ($isMod ? ", include=all" : ", status<hidden")) : 0;
      $topics     = $rootId ? $pages->count("template=forum-topic, has_parent=$rootId" . ($isMod ? ", include=all" : ", status<hidden")) : 0;
      $replies    = $rootId ? $pages->count("template=forum-reply, has_parent=$rootId" . ($isMod ? ", include=all" : ", status<hidden")) : 0;
      // Members count: exclude only the built-in guest *user*.
      // Do NOT filter by roles!=guest because logged-in users may still have 'guest' implicitly.
      $members    = $users->count("id>0, name!=guest, check_access=0");
      $sfCnt = [$categories, $groups, $topics, $replies, $members];
      try { wire('cache')->saveFor('NativeForumView', 'sbcnt_' . ($isMod ? 1 : 0) . '_' . $rootId, $sfCnt, 300); } catch(\Throwable $e) {}
    }
    list($categories, $groups, $topics, $replies, $members) = array_map('intval', $sfCnt);

    if($theme === 'bootstrap') {
      $stats = "<ul class='list-group list-group-flush sf-statlist-bs'>"
        . "<li class='list-group-item d-flex justify-content-between'><span>Groups</span><strong>{$groups}</strong></li>"
        . "<li class='list-group-item d-flex justify-content-between'><span>Categories</span><strong>{$categories}</strong></li>"
        . "<li class='list-group-item d-flex justify-content-between'><span>Topics</span><strong>{$topics}</strong></li>"
        . "<li class='list-group-item d-flex justify-content-between'><span>Posts</span><strong>" . ($topics + $replies) . "</strong></li>"
        . "<li class='list-group-item d-flex justify-content-between'><span>Members</span><strong>{$members}</strong></li>"
        . "</ul>";
    } else {
      $stats = "<ul class='sf-statlist'>"
        . "<li><span>Groups</span><strong>{$groups}</strong></li>"
        . "<li><span>Categories</span><strong>{$categories}</strong></li>"
        . "<li><span>Topics</span><strong>{$topics}</strong></li>"
        . "<li><span>Posts</span><strong>" . ($topics + $replies) . "</strong></li>"
        . "<li><span>Members</span><strong>{$members}</strong></li>"
        . "</ul>";
    }

    // Search (enhanced scopes)
    $searchAction = ($rootId ? $pages->get($rootId)->url : $context->url);
    $qVal = trim((string) $context->wire()->input->get->q);
    $scopeVal = (string) $context->wire()->input->get->sf_scope;
    if(!$scopeVal) $scopeVal = 'this';
    $isCat = ($context->template && $context->template->name === 'forum-category');
    $isGroup = ($context->template && $context->template->name === 'forum-group');
    $thisLabel = $isCat ? 'This Category' : ($isGroup ? 'This Group' : 'This Forum');
    $scopeLabels = [
      'everywhere' => 'Everywhere',
      'this'       => $thisLabel,
      'topics'     => 'Topics',
      'posts'      => 'Posts',
      'categories' => 'Categories',
      'members'    => 'Members',
    ];
    $scopeBtn = $scopeLabels[$scopeVal] ?? $thisLabel;

    
// Search form (ProcessWire-forum-like).
// Uses <details> so scope options work without any JS framework.
$scopeTitle = $scopeLabels[$scopeVal] ?? $thisLabel;
$qEsc = $sanitizer->entities($qVal);
$scopeEsc = $sanitizer->entities($scopeVal);
$ctxId = (int) $context->id;

$inputClass = ($theme === 'bootstrap') ? "form-control" : "sf-input";
$submitClass = ($theme === 'bootstrap') ? "btn btn-outline-secondary" : "sf-btn sf-btn-ghost sf-btn-sm";


$search = "<div class='sf-pwqs' data-sf-pwqs='1'>"
  . "<form method='get' action='{$searchAction}' class='sf-search sf-csearch' autocomplete='off'>"
  . "<input type='search' class='{$inputClass} sf-pwqs-field' name='q' value='{$qEsc}' placeholder='" . $sanitizer->entities(sfT('search_placeholder','Search Forums...')) . "' aria-label='" . $sanitizer->entities(sfT('search','Search')) . "' />"
  . "<details class='sf-pwqs-filter' role='listbox' aria-label='Search scope'>"
  . "<summary class='sf-pwqs-filter__text' aria-haspopup='listbox' aria-expanded='false'>"
  . "<span class='sf-pwqs-summaryText'>" . $sanitizer->entities($scopeTitle) . "</span>"
  . "</summary>"
  . "<ul class='sf-pwqs-menu' role='presentation'>";

foreach($scopeLabels as $k => $lab) {
  $checked = ($k === $scopeVal) ? " checked" : "";
  $kEsc = $sanitizer->entities($k);
  $labEsc = $sanitizer->entities($lab);
  $search .= "<li><label>"
          . "<input type='radio' name='sf_scope_radio' value='{$kEsc}'{$checked} />"
          . "<span class='sf-pwqs-menuText'>{$labEsc}</span>"
          . "</label></li>";
}

$search .= "</ul></details>"
  // Hidden values AFTER <details> so sibling CSS works (input + details)
  . "<input type='hidden' name='sf_scope' value='{$scopeEsc}' />"
  . "<input type='hidden' name='sf_ctx' value='{$ctxId}' />"
  . "<button class='sf-pwqs-submit' type='submit' aria-label='Search'>"
  . "<svg viewBox='0 0 24 24' aria-hidden='true'><path fill='currentColor' d='M10 4a6 6 0 104.472 10.03l4.249 4.25a1 1 0 001.414-1.415l-4.25-4.248A6 6 0 0010 4zm0 2a4 4 0 110 8 4 4 0 010-8z'/></svg>"
  . "</button>"
  . "</form></div>";

// Latest topics + newest members (cached for 2 minutes per permission set)
    $sfSbKey = 'sb_' . $theme . '_' . $rootId . '_' . md5(json_encode([$isMod, ($currentUser && $currentUser->id) ? $currentUser->roles->explode('id') : []]));
    $sfSb = null;
    try { $sfSb = wire('cache')->getFor('NativeForumView', $sfSbKey); } catch(\Throwable $e) { $sfSb = null; }
    if(is_array($sfSb) && count($sfSb) === 2) {
      list($latest, $newest) = $sfSb;
    } else {
// Latest topics
    $latest = '';
    if($rootId) {
      $ltSel = "template=forum-topic, has_parent=$rootId" . ($isMod ? ", include=all" : ", status<hidden") . ", sort=-created, limit=6";
      $latestTopics = $pages->find($ltSel);
      if($latestTopics->count()) {
        $latest .= ($theme === 'bootstrap') ? "<ul class='list-group list-group-flush sf-side-list'>" : "<ul class='sf-side-list'>";
        foreach($latestTopics as $t) {
          // Hide VIP topics from users without VIP read access
          if($forum && method_exists($forum,'canReadForum') && !$forum->canReadForum($currentUser, $t)) continue;

          // Badges: avoid Bootstrap badge classes when using the built-in (simple) theme
          $badge = '';
          try {
            $isPinned = ($forum && method_exists($forum,'isPinnedTopic')) ? (bool) $forum->isPinnedTopic($t) : ($t->hasField('forum_is_pinned') && (int)$t->forum_is_pinned);
            $isLocked = ($t->hasField('forum_is_locked') && (int)$t->forum_is_locked);
            $isVip    = ($forum && $t->parent && $t->parent->id && method_exists($forum,'isVipCategory') && $forum->isVipCategory($t->parent));

            if($theme === 'bootstrap') {
              if($isPinned) $badge .= " <span class='badge text-bg-warning sf-pinned-badge ms-1'>Pinned</span>";
              if($isLocked) $badge .= " <span class='badge text-bg-secondary ms-1'>Locked</span>";
              if($isVip)    $badge .= " <span class='badge text-bg-danger sf-vip-badge ms-1'>VIP</span>";
            } else {
              if($isPinned) $badge .= " <span class='sf-badge sf-badge-pin'>Pinned</span>";
              if($isLocked) $badge .= " <span class='sf-badge sf-badge-lock'>Locked</span>";
              if($isVip)    $badge .= " <span class='sf-badge sf-badge-vip'>VIP</span>";
            }
          } catch(\Throwable $e) {}

          $when = sf_format_dt((int)$t->created);
          $title = $sanitizer->entities($t->title);
          if($theme === 'bootstrap') {
            $latest .= "<li class='list-group-item d-flex justify-content-between align-items-center py-2'>"
              . "<div class='me-2 text-truncate'><a class='sf-side-link' href='{$t->url}' data-sf-tooltip='{$title}'>{$title}</a>{$badge}</div>"
              . "<small class='text-muted flex-shrink-0 ms-2'>{$when}</small>"
              . "</li>";
          } else {
            $latest .= "<li class='sf-side-item'>"
              . "<span class='sf-side-left'><a class='sf-side-link' href='{$t->url}' data-sf-tooltip='{$title}'>{$title}</a>{$badge}</span>"
              . "<span class='sf-side-right'>{$when}</span>"
              . "</li>";
          }
        }
        $latest .= "</ul>";
      } else {
        $latest = "<p class='sf-muted'>No topics yet.</p>";
      }
    }

    // Newest members
    // Use $users->find() to avoid relying on admin-tree pages; exclude only the guest *user*.
    $newest = '';
    $newUsers = $users->find("id>0, name!=guest, sort=-created, limit=7, check_access=0");
    if($newUsers && $newUsers->count()) {
      $newest .= ($theme === 'bootstrap') ? "<ul class='list-group list-group-flush sf-side-list'>" : "<ul class='sf-side-list'>";
      foreach($newUsers as $u) {
        $labelLink = sf_user_link($u, $forum, $pages, $sanitizer);
        $when = $u->created ? sf_format_dt((int)$u->created) : '';
        $label = $labelLink;
        if($theme === 'bootstrap') {
          $newest .= "<li class='list-group-item d-flex justify-content-between align-items-center py-2'>"
            . "<div class='me-2 text-truncate'>{$label}</div>"
            . ($when ? "<small class='text-muted flex-shrink-0 ms-2'>{$when}</small>" : "")
            . "</li>";
        } else {
          $newest .= "<li class='sf-side-item'>"
            . "<span class='sf-side-left'>{$label}</span>"
            . ($when ? "<span class='sf-side-right'>{$when}</span>" : "")
            . "</li>";
        }
      }
      $newest .= "</ul>";
    }

      try { wire('cache')->saveFor('NativeForumView', $sfSbKey, [$latest, $newest], 120); } catch(\Throwable $e) {}
    }

    // Quick links
    // Quick links
    
    $msgRoot = $forum ? $forum->messagesRoot() : $pages->get('/messages/');
    $membersPage = $pages->get("template=forum-members, include=all");
    $links = '';
    $liOpen = ($theme === 'bootstrap') ? "<li class='list-group-item py-2'>" : "<li>";
    $links .= ($theme === 'bootstrap') ? "<ul class='list-group list-group-flush sf-side-links'>" : "<ul class='sf-side-links'>";
    $links .= ($rootId ? $liOpen . "<a href='" . $pages->get($rootId)->url . "'>Forum index</a></li>" : "");
    $links .= (($membersPage && $membersPage->id) ? $liOpen . "<a href='{$membersPage->url}'>Members</a></li>" : "");
    if($msgRoot && $msgRoot->id && $forum && $forum->privateMessagesEnabled()) {
      if($currentUser && $currentUser->isLoggedin()) {
        $unread = 0;
        try { $unread = (int) $forum->unreadMessageCount($currentUser); } catch(\Throwable $e) { $unread = 0; }
        $badge = '';
        if($unread > 0) {
          if($theme === 'bootstrap') $badge = " <span class='badge text-bg-danger ms-1'>{$unread}</span>";
          else $badge = " <span class='sf-badge sf-badge-vip'>{$unread}</span>";
        }
        $links .= $liOpen . "<a href='{$msgRoot->url}'>Private messages{$badge}</a></li>";
      }
    }
    // Followed topics (with the number of topics that have unread replies)
    if($forum && $currentUser && $currentUser->isLoggedin() && method_exists($forum, 'followedTopics')) {
      try {
        $profPage = $pages->get("template=forum-profile, include=hidden");
        if($profPage && $profPage->id && count($forum->followedTopics($currentUser))) {
          $fn = (int) $forum->followedNewCount($currentUser);
          $fb = $fn ? (($theme === 'bootstrap') ? " <span class='badge text-bg-primary ms-1'>{$fn}</span>" : " <span class='sf-badge sf-badge-new'>{$fn}</span>") : '';
          $links .= $liOpen . "<a href='{$profPage->url}?tab=following'>" . sfT('followed_topics', 'Followed topics') . "{$fb}</a></li>";
        }
      } catch(\Throwable $e) {}
    }
    $links .= "</ul>";
$html = '';
    if($account) $html .= $wrap(sfT('account','Account'), $account);
    $html .= $wrap(sfT('search','Search'), $search);
    $html .= $wrap(sfT('forum_statistics','Forum statistics'), $stats);
    $html .= $wrap(sfT('latest_topics','Latest topics'), $latest);
    if($newest) $html .= $wrap(sfT('newest_members','Newest members'), $newest);
    $html .= $wrap('Quick links', $links);

    return $html;
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_layout_open')) {
  function sf_layout_open($forum, $config, string $titleHtml, string $sidebarHtml): void {
    $theme = sf_forum_theme($forum);

    // Sidebar visibility should affect BOTH the actual sidebar output and the main column width.
    // Also consider empty sidebar HTML as "no sidebar" so the main column becomes full width.
    $showSidebar = sf_should_show_sidebar();
    $hasSidebar  = $showSidebar && trim((string) $sidebarHtml) !== '';

    if($theme === 'bootstrap') {
      $container = (string) sf_cfg('forumWidthPreset', 'container');
      if(!in_array($container, ['container','container-xl','container-fluid','inherit'], true)) $container = 'container';
      $forceFull = !empty(sf_cfg('forumForceFullWidth', 0));
      $topSpace = (int) sf_cfg('forumTopSpacePx', 0);
      $botSpace = (int) sf_cfg('forumBottomSpacePx', 0);
      $prependMarkup = (string) sf_cfg('forumPrependMarkup', '');
      $appendMarkup  = (string) sf_cfg('forumAppendMarkup', '');
		$prependWhere  = (string) sf_cfg('forumPrependPlacement', 'inside_container');
		$appendWhere   = (string) sf_cfg('forumAppendPlacement', 'inside_container');
      $wrapStyle = '';
      if($topSpace || $botSpace) {
        $wrapStyle = " style='--sf-top-space:" . (int)$topSpace . "px;--sf-bottom-space:" . (int)$botSpace . "px;'";
      }
      $wrapClassExtra = ($forceFull && $container === 'container-fluid') ? ' sf-fullbleed' : '';
      $forumStyle = (string) sf_cfg('forumStyle', 'classic');
      $styleClass = ($forumStyle === 'compact') ? ' sf-style-compact' : '';
		
      $frontendTheme = (string) sf_cfg('frontendTheme', 'simple');
      if(!in_array($frontendTheme, ['simple','bootstrap'], true)) $frontendTheme = 'simple';
      $themeClass = ' sf-theme-' . $frontendTheme;
      // Post author/user-info column side (topic/replies)
      $authorSide = (string) sf_cfg('postAuthorSide', 'left');
      if(!in_array($authorSide, ['left','right'], true)) $authorSide = 'left';
      $authorClass = ($authorSide === 'right') ? ' sf-author-right' : ' sf-author-left';

$btnPreset = (string) sf_cfg('designButtonPreset', 'neutral');
		if(!$btnPreset) $btnPreset = 'neutral';
		$inputSize = (string) sf_cfg('designInputHeight', 'md');
		if(!in_array($inputSize, ['sm','md','lg'], true)) $inputSize = 'md';


      $split = (string) sf_cfg('layoutMainColsLg', '8-4');
      if(!preg_match('/^(\d+)-(\d+)$/', $split, $m)) { $m = [null, 8, 4]; }
      $mainLg = max(6, min(10, (int)$m[1]));
      $sideLg = max(2, min(6, (int)$m[2]));
      if($mainLg + $sideLg !== 12) { $mainLg = 8; $sideLg = 4; }

      // If sidebar is disabled OR empty, use full-width main column.
      if(!$hasSidebar) { $mainLg = 12; $sideLg = 0; }


      $containerClass = ($container === 'inherit') ? '' : $container;
$tplClass = '';
try {
	$pNow = wire('page');
	$tplName = ($pNow && $pNow->template) ? (string)$pNow->template->name : '';
	if($tplName) {
		$tplName = preg_replace('/[^a-z0-9_-]/i', '', $tplName);
		$tplClass = $tplName ? (' sf-tpl-' . $tplName) : '';
	}
} catch(\Throwable $e) { $tplClass = ''; }
$wrapClasses = "sf-wrap sf-wrap-bootstrap sf-btnmode-{$btnPreset}" . $wrapClassExtra . $styleClass . $tplClass;
		// Prepend: can be outside the entire forum wrapper, inside wrapper, or inside container.
		if($prependMarkup && $prependWhere === 'outside_forum') {
			echo "<div class='sf-prepend sf-prepend--outside'>" . $prependMarkup . "</div>";
		}
		echo "<div class='{$wrapClasses}'{$wrapStyle}>";
		if($prependMarkup && $prependWhere === 'inside_wrap') {
			echo "<div class='sf-prepend sf-prepend--wrap'>" . $prependMarkup . "</div>";
		}
		echo "<div class='" . ($containerClass ? ($containerClass . " ") : "") . "sf-container'>";
		if($prependMarkup && $prependWhere === 'inside_container') {
			echo "<div class='sf-prepend sf-prepend--container'>" . $prependMarkup . "</div>";
		}
      // Auth actions (Login/Register) shown on every forum page when logged out
      $authHtml = '';
      try {
        $u = wire('user');
        if($u && !$u->isLoggedin()) {
          $input = wire('input');
          $req = '';
          try { $req = (string) ($input && isset($input->server->REQUEST_URI) ? $input->server->REQUEST_URI : ''); } catch(\Throwable $e) { $req = ''; }
          // Remember where the user was so login/register can return here even if return param is lost.
          try {
            $s = wire('session');
            if($s && $req && strpos($req, '/forum-login') === false && strpos($req, '/forum-register') === false) {
              $s->set('sf_return', $req);
            }
          } catch(\Throwable $e) {}
          $retQ = $req ? ('return=' . rawurlencode($req)) : '';

          $p = wire('pages');
          $loginPage = $p->get("template=forum-login, include=all");
          $regPage   = $p->get("template=forum-register, include=all");
          $loginUrl = ($loginPage && $loginPage->id) ? $loginPage->url : '/forum-login/';
          $regUrl   = ($regPage && $regPage->id) ? $regPage->url : '/forum-register/';

          if($retQ) {
            $loginUrl .= (strpos($loginUrl, '?') !== false ? '&' : '?') . $retQ;
            $regUrl   .= (strpos($regUrl,   '?') !== false ? '&' : '?') . $retQ;
          }
          $authHtml = "<div class='sf-auth-actions d-flex gap-2'>"
            . "<a class='btn btn-primary btn-sm' href='{$loginUrl}'>Login</a>"
            . (sf_registration_open() ? "<a class='btn btn-outline-primary btn-sm' href='{$regUrl}'>Register</a>" : '')
            . "</div>";
        }
      } catch(\Throwable $e) { $authHtml = ''; }
      if($authHtml === '') $authHtml = sf_pm_titlebar_html($forum);

      echo "<div class='sf-page-title d-flex flex-wrap align-items-center justify-content-between gap-2'>{$titleHtml}{$authHtml}</div>";
      echo "<div class='row g-3'><div class='col-12 col-lg-{$mainLg}'>";
    } else {
      $topSpace = (int) sf_cfg('forumTopSpacePx', 0);
      $botSpace = (int) sf_cfg('forumBottomSpacePx', 0);
		$prependMarkup = (string) sf_cfg('forumPrependMarkup', '');
		$prependWhere  = (string) sf_cfg('forumPrependPlacement', 'inside_container');
      $wrapStyle = '';
      if($topSpace || $botSpace) {
        $wrapStyle = " style='--sf-top-space:" . (int)$topSpace . "px;--sf-bottom-space:" . (int)$botSpace . "px;'";
      }
      $forumStyle = (string) sf_cfg('forumStyle', 'classic');
      $styleClass = ($forumStyle === 'compact') ? ' sf-style-compact' : '';
	  // Input size token used by runtime CSS/JS (sm|md|lg)
	  $inputSize = (string) sf_cfg('designInputHeight', 'md');
	  if(!in_array($inputSize, ['sm','md','lg'], true)) $inputSize = 'md';
		$btnPreset = (string) sf_cfg('designButtonPreset', 'neutral');
		if(!$btnPreset) $btnPreset = 'neutral';
		// Theme + author column side classes (also used in simple theme)
		$frontendTheme = (string) sf_cfg('frontendTheme', 'simple');
		if(!in_array($frontendTheme, ['simple','bootstrap'], true)) $frontendTheme = 'simple';
		$themeClass = ' sf-theme-' . $frontendTheme;
		$authorSide = (string) sf_cfg('postAuthorSide', 'left');
		if(!in_array($authorSide, ['left','right'], true)) $authorSide = 'left';
		$authorClass = ($authorSide === 'right') ? ' sf-author-right' : ' sf-author-left';
		// Per-template body class for scoped CSS overrides
		$tplClass = '';
		try {
			$pNow = wire('page');
			$tplName = ($pNow && $pNow->template) ? (string)$pNow->template->name : '';
			if($tplName) {
				$tplName = preg_replace('/[^a-z0-9_-]/i', '', $tplName);
				$tplClass = $tplName ? (' sf-tpl-' . $tplName) : '';
			}
		} catch(\Throwable $e) { $tplClass = ''; }
		if($prependMarkup && $prependWhere === 'outside_forum') {
			echo "<div class='sf-prepend sf-prepend--outside'>" . $prependMarkup . "</div>";
		}
		// Layout settings (same meaning as in the Bootstrap theme)
		$split = (string) sf_cfg('layoutMainColsLg', '8-4');
		if(!preg_match('/^(\d+)-(\d+)$/', $split, $sm) || ((int)$sm[1] + (int)$sm[2]) !== 12) $sm = [null, 8, 4];
		$sideCols = max(2, min(5, (int) $sm[2]));
		$widthPreset = (string) sf_cfg('forumWidthPreset', 'container');
		if(!in_array($widthPreset, ['container','container-xl','container-fluid','inherit'], true)) $widthPreset = 'container';
		$widthClass = ' sf-w-' . $widthPreset;
		if($widthPreset === 'container-fluid' && !empty(sf_cfg('forumForceFullWidth', 0))) $widthClass .= ' sf-fullbleed';
		$layoutVars = '--sf-side-cols:' . $sideCols . ';--sf-main-cols:' . (12 - $sideCols) . ';';
		if($wrapStyle) $wrapStyle = str_replace("style='", "style='" . $layoutVars, $wrapStyle);
		else $wrapStyle = " style='{$layoutVars}'";
		echo "<div class='sf-wrap sf-btnmode-{$btnPreset}{$styleClass}{$themeClass}{$authorClass}{$tplClass}{$widthClass} sf-split-" . (12 - $sideCols) . "-{$sideCols}' data-sf-input-size='{$inputSize}'{$wrapStyle}>";
		if($prependMarkup && $prependWhere === 'inside_wrap') {
			echo "<div class='sf-prepend sf-prepend--wrap'>" . $prependMarkup . "</div>";
		}
		echo "<div class='sf-container'>";
		if($prependMarkup && $prependWhere === 'inside_container') {
			echo "<div class='sf-prepend sf-prepend--container'>" . $prependMarkup . "</div>";
		}
      // Auth actions (Login/Register) shown on every forum page when logged out
      $authHtml = '';
      try {
        $u = wire('user');
        if($u && !$u->isLoggedin()) {
          $input = wire('input');
          $req = '';
          try { $req = (string) ($input && isset($input->server->REQUEST_URI) ? $input->server->REQUEST_URI : ''); } catch(\Throwable $e) { $req = ''; }
          // Remember where the user was so login/register can return here even if return param is lost.
          try {
            $s = wire('session');
            if($s && $req && strpos($req, '/forum-login') === false && strpos($req, '/forum-register') === false) {
              $s->set('sf_return', $req);
            }
          } catch(\Throwable $e) {}
          $retQ = $req ? ('return=' . rawurlencode($req)) : '';

          $p = wire('pages');
          $loginPage = $p->get("template=forum-login, include=all");
          $regPage   = $p->get("template=forum-register, include=all");
          $loginUrl = ($loginPage && $loginPage->id) ? $loginPage->url : '/forum-login/';
          $regUrl   = ($regPage && $regPage->id) ? $regPage->url : '/forum-register/';

          if($retQ) {
            $loginUrl .= (strpos($loginUrl, '?') !== false ? '&' : '?') . $retQ;
            $regUrl   .= (strpos($regUrl,   '?') !== false ? '&' : '?') . $retQ;
          }
          $authHtml = "<div class='sf-auth-actions'>"
            . "<a class='sf-btn sf-btn-primary' href='{$loginUrl}'>Login</a>"
            . (sf_registration_open() ? "<a class='sf-btn sf-btn-ghost' href='{$regUrl}'>Register</a>" : '')
            . "</div>";
        }
      } catch(\Throwable $e) { $authHtml = ''; }
      if($authHtml === '') $authHtml = sf_pm_titlebar_html($forum);

      echo "<div class='sf-page-title sf-titlebar'>{$titleHtml}{$authHtml}</div>";
      $gridClass = $hasSidebar ? 'sf-grid' : 'sf-grid sf-grid-no-sidebar';
      echo "<div class='{$gridClass}'><div class='sf-main'>";
    }
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_pm_notice')) {
  /**
   * Unread private message info for the current user (cached per request).
   * Returns [] when nothing to show, else ['count','url','latest','from','subject'].
   */
  function sf_pm_notice($forum): array {
    static $cache = null;
    if($cache !== null) return $cache;
    $cache = [];
    try {
      $u = wire('user');
      if(!$forum || !$u || !$u->isLoggedin() || !method_exists($forum, 'privateMessagesEnabled') || !$forum->privateMessagesEnabled()) return $cache;
      $n = (int) $forum->unreadMessageCount($u);
      if($n < 1) return $cache;
      $root = $forum->messagesRoot();
      $latest = method_exists($forum, 'latestUnreadMessage') ? $forum->latestUnreadMessage($u) : null;
      $from = '';
      if($latest && $latest->id) {
        $fu = $latest->getUnformatted('forum_from_user');
        if($fu instanceof PageArray) $fu = $fu->first();
        if($fu instanceof User && $fu->id) $from = sf_user_display_name($fu);
      }
      $url = ($n === 1 && $latest && $latest->id) ? $latest->url : (($root && $root->id) ? $root->url : '#');
      $cache = [
        'count' => $n,
        'url' => $url,
        'inbox' => ($root && $root->id) ? $root->url : $url,
        'latest' => $latest ? (int) $latest->id : 0,
        'from' => $from,
        'subject' => $latest ? (string) $latest->getUnformatted('title') : '',
      ];
    } catch(\Throwable $e) { $cache = []; }
    return $cache;
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_pm_label')) {
  function sf_pm_label(int $n): string {
    return $n === 1
      ? sfT('pm_new_one', '1 new private message')
      : str_replace('{n}', (string) $n, sfT('pm_new_many', '{n} new private messages'));
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_pm_envelope')) {
  function sf_pm_envelope(): string {
    return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V4zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1H2zm13 2.383-4.708 2.825L15 11.105V5.383zM14.247 12 9.06 8.872 8 9.515l-1.06-.643L1.753 12H14.247zM1 11.105l4.708-2.897L1 5.383v5.722z"/></svg>';
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_pm_titlebar_html')) {
  /** Compact "✉ 3" pill for the forum title bar (only when there are unread messages). */
  function sf_pm_titlebar_html($forum): string {
    $pm = sf_pm_notice($forum);
    if(!$pm) return '';
    $san = wire('sanitizer');
    $label = $san->entities(sf_pm_label($pm['count']));
    return "<div class='sf-auth-actions sf-pm-actions'><a class='sf-pm-pill' href='" . $san->entities($pm['url']) . "' title='{$label}' aria-label='{$label}'>"
      . "<span class='sf-pm-pill-ic'>" . sf_pm_envelope() . "</span>"
      . "<span class='sf-pm-pill-n'>" . (int) $pm['count'] . "</span>"
      . "<span class='sf-pm-pill-t'>" . $san->entities(sfT('pm_new_short', 'new')) . "</span></a></div>";
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_pm_account_html')) {
  /** Prominent unread notice inside the sidebar Account box. */
  function sf_pm_account_html($forum): string {
    $pm = sf_pm_notice($forum);
    if(!$pm) return '';
    $san = wire('sanitizer');
    $sub = $pm['from'] !== '' ? sfT('pm_latest_from', 'Latest from') . ' ' . $pm['from'] : sfT('pm_click_to_read', 'Click to read');
    return "<a class='sf-pm-box' href='" . $san->entities($pm['url']) . "'>"
      . "<span class='sf-pm-box-ic'>" . sf_pm_envelope() . "<span class='sf-pm-box-n'>" . (int) $pm['count'] . "</span></span>"
      . "<span class='sf-pm-box-t'><strong>" . $san->entities(sf_pm_label($pm['count'])) . "</strong><small>" . $san->entities($sub) . "</small></span>"
      . "</a>";
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_pm_toast_html')) {
  /**
   * One-time popup when a new private message arrives. Shown once per newest unread message
   * (remembered in localStorage), never on the messages pages themselves.
   */
  function sf_pm_toast_html($forum): string {
    $pm = sf_pm_notice($forum);
    if(!$pm || !$pm['latest']) return '';
    $p = wire('page');
    if($p && $p->template && in_array($p->template->name, ['forum-messages-root', 'forum-message'], true)) return '';
    $san = wire('sanitizer');
    $title = $san->entities(sf_pm_label($pm['count']));
    $line = '';
    if($pm['from'] !== '') {
      $line = $san->entities(sfT('pm_from', 'From') . ' ' . $pm['from']);
      if($pm['subject'] !== '') $line .= ': <em>' . $san->entities(mb_strimwidth($pm['subject'], 0, 70, '…')) . '</em>';
    }
    $id = (int) $pm['latest'];
    $read = $san->entities(sfT('pm_read', 'Read'));
    $later = $san->entities(sfT('pm_later', 'Later'));
    return "<div class='sf-pm-toast' id='sf-pm-toast' role='status' aria-live='polite' data-sf-pm-latest='{$id}' hidden>"
      . "<div class='sf-pm-toast-ic'>" . sf_pm_envelope() . "</div>"
      . "<div class='sf-pm-toast-body'><strong>{$title}</strong>" . ($line !== '' ? "<span>{$line}</span>" : '')
      . "<div class='sf-pm-toast-act'><a class='btn btn-primary btn-sm' href='" . $san->entities($pm['url']) . "' data-sf-pm-ack>{$read}</a>"
      . "<button type='button' class='btn btn-outline-secondary btn-sm' data-sf-pm-ack data-sf-pm-close>{$later}</button></div></div>"
      . "<button type='button' class='sf-pm-toast-x' aria-label='Close' data-sf-pm-ack data-sf-pm-close>&times;</button>"
      . "</div>"
      . "<script>(function(){var t=document.getElementById('sf-pm-toast');if(!t)return;var id=t.getAttribute('data-sf-pm-latest'),k='sfPmSeen',seen='';"
      . "try{seen=localStorage.getItem(k)||'';}catch(e){}"
      . "if(seen===id){t.parentNode.removeChild(t);return;}"
      . "function ack(){try{localStorage.setItem(k,id);}catch(e){}}"
      . "t.hidden=false;requestAnimationFrame(function(){t.classList.add('is-in');});"
      . "Array.prototype.forEach.call(t.querySelectorAll('[data-sf-pm-ack]'),function(b){b.addEventListener('click',function(){ack();if(b.hasAttribute('data-sf-pm-close')){t.classList.remove('is-in');setTimeout(function(){t.hidden=true;},250);}});});"
      . "document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!t.hidden){ack();t.classList.remove('is-in');setTimeout(function(){t.hidden=true;},250);}});"
      . "})();</script>";
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_bootstrap_auth_modals')) {
  function sf_bootstrap_auth_modals($forum, Pages $pages, Sanitizer $sanitizer, Session $session, User $user): void {
    if(empty($forum) || sf_forum_theme($forum) !== 'bootstrap') return;
    if($user->isLoggedin()) return;

    if(!empty($GLOBALS['nativeForumAuthModalsLoaded'])) return;
    $GLOBALS['nativeForumAuthModalsLoaded'] = true;

    $loginPage = $pages->get("template=forum-login, include=all");
    $regPage   = $pages->get("template=forum-register, include=all");

    $loginUrl = ($loginPage && $loginPage->id) ? $loginPage->url : '/forum-login/';
    $regUrl   = ($regPage && $regPage->id) ? $regPage->url : '/forum-register/';

    // Current request (used for redirecting back after auth)
    $req = '';
    try {
      $input = wire('input');
      $req = (string) ($input && isset($input->server->REQUEST_URI) ? $input->server->REQUEST_URI : '');
    } catch(\Throwable $e) { $req = ''; }
    // Remember where the user was so login/register can return here even if return param is lost.
    try {
      $s = wire('session');
      if($s && $req && strpos($req, '/forum-login') === false && strpos($req, '/forum-register') === false) {
        $s->set('sf_return', $req);
      }
    } catch(\Throwable $e) {}
    $returnInput = $req ? "<input type='hidden' name='return' value='" . $sanitizer->entities($req) . "'>" : '';

    // Login modal
    echo "<div class='modal fade' id='sfLoginModal' tabindex='-1' aria-hidden='true'>"
      . "<div class='modal-dialog modal-dialog-centered'>"
      . "<div class='modal-content sf-auth-modal'>"
      . "<div class='modal-header border-0 pb-0'>"
      . "<h5 class='modal-title sf-auth-title'>login</h5>"
      . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>"
      . "</div>"
      . "<div class='modal-body pt-2'>"
      . "<form method='post' action='{$loginUrl}' class='sf-auth-form'>" . $forum->antiSpamFields('login')
      . $returnInput
      . "<div class='mb-3'>"
      . "<label class='form-label small mb-1'>Username:</label>"
      . "<div class='input-group'>"
      . "<span class='input-group-text'>👤</span>"
      . "<input class='form-control' type='text' name='name' autocomplete='username' required>"
      . "</div></div>"
      . "<div class='mb-3'>"
      . "<label class='form-label small mb-1'>Password:</label>"
      . "<div class='input-group'>"
      . "<span class='input-group-text'>🔒</span>"
      . "<input class='form-control' type='password' name='pass' autocomplete='current-password' required>"
      . "</div></div>"
      . "<div class='d-flex justify-content-between align-items-center mt-3'>"
      . "<button class='btn btn-primary px-4' type='submit' name='login_submit' value='1'>Login</button>"
      . "<div class='form-check small'>"
      . "<input class='form-check-input' type='checkbox' value='1' id='sfRemember'>"
      . "<label class='form-check-label' for='sfRemember'>Remember me</label>"
      . "</div>"
      . "</div>"
      . "</form>"
      . (sf_registration_open() ? "<div class='small mt-3'>No account? <a href='#' data-bs-dismiss='modal'>Register</a></div>" : '')
      . "</div></div></div></div>";

    // Register modal
    echo "<div class='modal fade' id='sfRegisterModal' tabindex='-1' aria-hidden='true'>"
      . "<div class='modal-dialog modal-dialog-centered'>"
      . "<div class='modal-content sf-auth-modal'>"
      . "<div class='modal-header border-0 pb-0'>"
      . "<h5 class='modal-title sf-auth-title'>register</h5>"
      . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>"
      . "</div>"
      . "<div class='modal-body pt-2'>"
      . "<form method='post' action='{$regUrl}' class='sf-auth-form'>" . $forum->antiSpamFields('register')
      . "<div class='mb-3'>"
      . "<label class='form-label small mb-1'>Username:</label>"
      . "<div class='input-group'>"
      . "<span class='input-group-text'>👤</span>"
      . "<input class='form-control' type='text' name='name' autocomplete='username' required>"
      . "</div></div>"
      . "<div class='mb-3'>"
      . "<label class='form-label small mb-1'>Email:</label>"
      . "<div class='input-group'>"
      . "<span class='input-group-text'>✉️</span>"
      . "<input class='form-control' type='email' name='email' autocomplete='email' required>"
      . "</div></div>"
      . "<div class='mb-3'>"
      . "<label class='form-label small mb-1'>Password:</label>"
      . "<div class='input-group'>"
      . "<span class='input-group-text'>🔒</span>"
      . "<input class='form-control' type='password' name='pass1' autocomplete='new-password' required>"
      . "</div></div>"
      . "<div class='mb-3'>"
      . "<label class='form-label small mb-1'>Repeat password:</label>"
      . "<div class='input-group'>"
      . "<span class='input-group-text'>🔒</span>"
      . "<input class='form-control' type='password' name='pass2' autocomplete='new-password' required>"
      . "</div></div>"
      . "<div class='d-flex justify-content-between align-items-center mt-3'>"
      . "<button class='btn btn-primary px-4' type='submit' name='register_submit' value='1'>Create account</button>"
      . "<button class='btn btn-link' type='button' data-bs-dismiss='modal'>Cancel</button>"
      . "</div>"
      . "</form>"
      . "<div class='small mt-3'>Already have an account? <a href='#' data-bs-dismiss='modal'>Login</a></div>"
      . "</div></div></div></div>";

    // Small JS niceties (safe even if bootstrap is already on the page)
    echo "<script>
      document.addEventListener('shown.bs.modal', function(e){
        try {
          var el = e.target && e.target.querySelector('input,textarea,select');
          if(el) el.focus();
        } catch(err){}
      });
    </script>";
  }
}

if(!function_exists(__NAMESPACE__ . '\sf_layout_close')) {
  /**
   * Close the main forum layout wrapper and (optionally) the sidebar.
   * Also injects shared UI helpers (tooltips + hover user cards) once.
   */
	  function sf_layout_close($forum, string $sidebarHtml, $pages = null, $sanitizer = null, $session = null, $user = null): void {
	    $theme = sf_forum_theme($forum);
	    $appendMarkup  = (string) sf_cfg('forumAppendMarkup', '');
	    $appendWhere   = (string) sf_cfg('forumAppendPlacement', 'inside_container');
	    $showSidebar = sf_should_show_sidebar();

    if($theme === 'bootstrap') {
      $split = (string) sf_cfg('layoutMainColsLg', '8-4');
      if(!preg_match('/^(\d+)-(\d+)$/', $split, $m)) { $m = [null, 8, 4]; }
      $sideLg = max(2, min(6, (int)$m[2]));
      if(((int)$m[1]) + $sideLg !== 12) $sideLg = 4;

      $mainLg = max(6, min(12, (int)$m[1]));
      if($mainLg + $sideLg !== 12) $mainLg = 12 - $sideLg;
      if(!$showSidebar || trim($sidebarHtml) === '') { $mainLg = 12; $sideLg = 0; }
      // Forum activity (below content/editor, inside main column so it respects sidebar layout)
      if((int)sf_cfg('activityEnabled', 1) === 1) {
        $p = wire('page');
        $tid = ($p && $p->template && $p->template->name === 'forum-topic') ? (int)$p->id : 0;
        echo "<div class='mt-4'>" . sf_activity_block_html($tid) . "</div>";
      }



      // Close main col
      echo "</div>";
      // Sidebar col
      if($showSidebar && trim($sidebarHtml) !== '') {
        echo "<div class='col-12 col-lg-{$sideLg}'>" . $sidebarHtml . "</div>";
      }
      // Close row
      echo "</div>";
      echo sf_auth_dialogs($forum);
      echo sf_pm_toast_html($forum);

      // Append markup (placement-aware)
	      if($appendMarkup && $appendWhere === 'inside_container') {
	        echo "<div class='sf-append sf-append--container'>" . $appendMarkup . "</div>";
	      }

	      // Close container
	      echo "</div>";
	      // Append inside wrap (outside container)
	      if($appendMarkup && $appendWhere === 'inside_wrap') {
	        echo "<div class='sf-append sf-append--wrap'>" . $appendMarkup . "</div>";
	      }
	      // Close wrap
	      echo "</div>";
	      // Append outside forum
	      if($appendMarkup && $appendWhere === 'outside_forum') {
	        echo "<div class='sf-append sf-append--outside'>" . $appendMarkup . "</div>";
	      }

      // Ensure auth modals are available on every forum page in Bootstrap theme
      try {
        $pages = wire('pages');
        $sanitizer = wire('sanitizer');
        $session = wire('session');
        $user = wire('user');
        if($pages instanceof Pages && $sanitizer instanceof Sanitizer && $session instanceof Session && $user instanceof User) {
          // (Bootstrap modals replaced by native dialogs: sf_auth_dialogs())
        }
      } catch(\Throwable $e) { /* ignore */ }
    } else {
      // Simple theme
      echo "</div>"; // close .sf-main

      if($showSidebar && trim($sidebarHtml) !== '') {
        echo "<div class='sf-side'>" . $sidebarHtml . "</div>";
      }
      echo "</div>"; // close .sf-grid

      // Forum activity block (bottom)
      if((int)sf_cfg('activityEnabled', 1) === 1) {
        $p = wire('page');
        $tid = ($p && $p->template && $p->template->name === 'forum-topic') ? (int)$p->id : 0;
        echo sf_activity_block_html($tid);
      }
      echo sf_auth_dialogs($forum);
      echo sf_pm_toast_html($forum);

	      if($appendMarkup && $appendWhere === 'inside_container') {
	        echo "<div class='sf-append sf-append--container'>" . $appendMarkup . "</div>";
	      }

	      echo "</div>"; // close .sf-container
	      if($appendMarkup && $appendWhere === 'inside_wrap') {
	        echo "<div class='sf-append sf-append--wrap'>" . $appendMarkup . "</div>";
	      }
	      echo "</div>"; // close .sf-wrap
	      if($appendMarkup && $appendWhere === 'outside_forum') {
	        echo "<div class='sf-append sf-append--outside'>" . $appendMarkup . "</div>";
	      }
    }

    // UI helpers (tooltips, hover user cards, new topic modal) are handled by assets/js/sf-ui.js

  }


/**
 * Build the ProcessWire-forum-like quick search form (input + details scope menu + icon submit).
 * Works in Simple and Bootstrap themes (no dependency on Bootstrap JS).
 */
function sf_csearch_form_html($forum, string $actionUrl, string $q = '', int $ctxId = 0, string $scope = 'everywhere', array $scopes = [], string $formClass = '', string $placeholder = 'Search…', string $size = 'sm'): string {
  $sanitizer = wire('sanitizer');

  $q = (string)$q;
  $scope = (string)$scope;
  if(!$scopes) {
    $scopes = [
      'everywhere' => 'Everywhere',
      'this'       => 'This page',
    ];
  }
  if(!isset($scopes[$scope])) {
    $keys = array_keys($scopes);
    $scope = (string)($keys[0] ?? 'everywhere');
  }

  // Unique field id per instance (header + sidebar can exist on same page)
  static $sfqsN = 0;
  $sfqsN++;
  $fieldId = "sfqsField{$sfqsN}";

  $actionEsc = $sanitizer->entities($actionUrl);
  $qEsc = $sanitizer->entities($q);
  $phEsc = $sanitizer->entities($placeholder);

  $sizeClass = ($size === 'sm') ? 'sf-csearch--sm' : (($size === 'lg') ? 'sf-csearch--lg' : 'sf-csearch--md');
  $cls = trim("sf-csearch {$sizeClass} {$formClass} sfqs");

  // Summary label (text in the bubble)
  $label = (string)($scopes[$scope] ?? 'Everywhere');
  $labelEsc = $sanitizer->entities($label);

  $out  = "<form class='{$cls}' data-sfqs='1' method='get' action='{$actionEsc}' accept-charset='utf-8' autocomplete='off'>";
  // IMPORTANT: input MUST be immediately followed by <details> to match the PW-style CSS behavior
  $out .= "<input type='search' id='{$fieldId}' class='sf-csearch-input sfqs-field' name='q' value='{$qEsc}' placeholder='{$phEsc}' autocomplete='off' aria-label='Search'>";

  $out .= "<details class='sf-csearch-filter cSearchFilter' role='listbox' aria-label='Search scope'>";
  $out .=   "<summary class='sf-csearch-filter__text cSearchFilter__text' aria-haspopup='listbox' aria-expanded='false'>";
  $out .=     "<span class='sf-csearch-filter__summaryText cSearchFilter__summaryText'>{$labelEsc}</span>";
  $out .=   "</summary>";
  $out .=   "<ul class='sf-csearch-filter__menu cSearchFilter__menu' role='presentation'>";
  foreach($scopes as $k => $lab) {
    $k = (string)$k;
    $checked = ($k === $scope) ? " checked" : "";
    $out .= "<li><label>"
         .  "<input type='radio' name='sf_scope_radio' value='" . $sanitizer->entities($k) . "'{$checked}>"
         .  "<span class='sf-csearch-filter__menuText cSearchFilter__menuText'>" . $sanitizer->entities($lab) . "</span>"
         .  "</label></li>";
  }
  $out .=   "</ul>";
  $out .= "</details>";

  // Hidden values (kept for backward compatible forum search handler)
  $out .= "<input type='hidden' name='sf_scope' value='" . $sanitizer->entities($scope) . "' class='sf-csearch-scopeval'>";
  if($ctxId) $out .= "<input type='hidden' name='sf_ctx' value='" . (int)$ctxId . "'>";

  // Submit icon (inline SVG to match the exact reference)
  $out .= "<button class='sf-csearch-submit cSearchSubmit' type='submit' aria-label='Search'>"
       .  "<svg viewBox='0 0 24 24' aria-hidden='true'>"
       .  "<path fill='currentColor' d='M10 4a6 6 0 104.472 10.03l4.249 4.25a1 1 0 001.414-1.415l-4.25-4.248A6 6 0 0010 4zm0 2a4 4 0 110 8 4 4 0 010-8z'/>"
       .  "</svg>"
       .  "</button>";

  $out .= "</form>";
  return $out;
}

}
