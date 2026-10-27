<?php namespace ProcessWire;
/**
 * NativeForum Template (Bootstrap 5)
 * Login page (also used by modal).
 */
/** @var Page $page */
/** @var User $user */
/** @var Input $input */
/** @var Sanitizer $sanitizer */
/** @var Session $session */
/** @var Config $config */

$forum = $modules->get('NativeForum');
if(!$forum) throw new Wire404Exception();

require_once $config->paths->templates . 'forum-layout.php';
sf_forum_css($forum, $config);

/**
 * Make sure we only redirect within this ProcessWire installation (including subfolder installs).
 */
if(!function_exists(__NAMESPACE__ . '\\sf_safe_return_path')) {
  function sf_safe_return_path(string $ret, Config $config): string {
    $ret = trim(str_replace(["\r", "\n"], '', $ret));
    if($ret === '') return '';

    // reject absolute URLs (prevent open redirect)
    if(strpos($ret, '://') !== false || str_starts_with(strtolower($ret), 'javascript:')) return '';

    // ensure leading slash
    if($ret[0] !== '/') $ret = '/' . ltrim($ret, '/');

    $root = $config->urls->root ?: '/';
    // if installed in subfolder, require path to start with root
    if($root !== '/' && strpos($ret, $root) !== 0) return '';

    // avoid redirect loops
    if(strpos($ret, '/forum-login') !== false || strpos($ret, '/forum-register') !== false) return '';

    return $ret;
  }
}

// decide where to go back to
$return = '';
$return = (string) $input->post->return ?: (string) $input->get->return;
// session fallback (useful for modal login and hosts that strip query params)
if(!$return) {
  try {
    $return = (string) ($session ? $session->get('sf_return') : '');
  } catch(\Throwable $e) {
    $return = '';
  }
}
if(!$return) {
  // try referer (same-site only)
  $ref = (string) ($input->server->HTTP_REFERER ?? '');
  if($ref) {
    $u = parse_url($ref);
    if(!empty($u['path'])) {
      $return = $u['path'] . (!empty($u['query']) ? '?' . $u['query'] : '');
    }
  }
}

$return = sf_safe_return_path($return, $config);

if($user->isLoggedin()) {
  try { if($session) $session->remove('sf_return'); } catch(\Throwable $e) {}
  $session->redirect($return ?: $forum->forumRoot()->url);
}

$message = '';

if($input->post->login_submit && ($spamErr = $forum->antiSpamCheck('login', 0))) {
  $message = $sanitizer->entities($spamErr);
} elseif($input->post->login_submit) {
  $name = $sanitizer->username($input->post->name);
  $pass = (string) $input->post->pass;

  $loggedIn = false;
  try {
    $loggedIn = (bool) $session->login($name, $pass);
  } catch(\Throwable $e) {
    // e.g. SessionLoginThrottleException (too many attempts) – show it instead of an error page
    $message = (string) ($e->getMessage() ?: 'Too many login attempts. Please wait a moment and try again.');
  }
  if($loggedIn) {
    try { if($session) $session->remove('sf_return'); } catch(\Throwable $e) {}
    $session->redirect($return ?: $forum->forumRoot()->url);
  } elseif($message === '') {
    $message = 'Invalid username or password.';
  }
}

$theme = sf_forum_theme($forum);

$sidebarHtml = sf_sidebar_html($forum, $page, $pages, $users, $sanitizer, $user);

$forumRoot = $forum->forumRoot() ?: $pages->get('/forum/');
$titleHtml = "<div class='sf-breadcrumbbar'><a href='{$forumRoot->url}'>Board index</a><span class='sf-breadcrumb-sep'>»</span>Login</div>";
sf_layout_open($forum, $config, $titleHtml, $sidebarHtml);

if($theme === 'bootstrap') {
  echo "<div class='row justify-content-center'><div class='col-md-8 col-lg-6'>";
  echo "<div class='sf-card'>";
  echo "<div class='sf-card-h'>login</div>";
  echo "<div class='sf-card-b'>";

  if($message) echo "<div class='sf-alert sf-alert-error'>{$sanitizer->entities($message)}</div>";

  echo "<form method='post'>" . $forum->antiSpamFields('login');
  if($return) echo "<input type='hidden' name='return' value='" . $sanitizer->entities($return) . "'>";
  echo "<div class='mb-3'><label class='form-label'>Username</label><input class='form-control' type='text' name='name' autocomplete='username' required></div>";
  echo "<div class='mb-3'><label class='form-label'>Password</label><input class='form-control' type='password' name='pass' autocomplete='current-password' required></div>";
  echo "<button class='btn btn-primary' type='submit' name='login_submit' value='1'>Login</button>";
  // In Bootstrap theme this is convenient to open register modal.
  
  $regPage = $pages->get("template=forum-register, include=all");
  $regUrl  = $regPage && $regPage->id ? $regPage->url : '#';
  if(sf_registration_open()) echo " <a class='btn btn-link' href='" . $sanitizer->entities($regUrl) . "'>Register</a>";
  echo "</form>";

  echo "</div></div></div></div>";
} else {
  // Simple (built-in) theme: plain form + real links (no modals)
  $regPage = $pages->get("template=forum-register, include=all");
  $regUrl  = ($regPage && $regPage->id) ? $regPage->url : '/forum-register/';

  echo "<div class='sf-card'>";
  echo "<div class='sf-card-h'>login</div>";
  echo "<div class='sf-card-b'>";
  if($message) echo "<div class='sf-alert sf-alert-error'>{$sanitizer->entities($message)}</div>";

  echo "<form method='post'>" . $forum->antiSpamFields('login');
  if($return) echo "<input type='hidden' name='return' value='" . $sanitizer->entities($return) . "'>";
  echo "<div class='sf-mb-2'><label class='sf-muted'>Username</label><br><input class='sf-input' type='text' name='name' autocomplete='username' required></div>";
  echo "<div class='sf-mb-2'><label class='sf-muted'>Password</label><br><input class='sf-input' type='password' name='pass' autocomplete='current-password' required></div>";
  echo "<button class='sf-btn sf-btn-primary' type='submit' name='login_submit' value='1'>Login</button>";
  if(sf_registration_open()) echo " <a class='sf-btn sf-btn-ghost' href='{$regUrl}'>Register</a>";
  echo "</form>";
  echo "</div></div>";
}

sf_layout_close($forum, $sidebarHtml, $pages, $sanitizer, $session, $user);
