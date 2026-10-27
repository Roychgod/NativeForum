<?php namespace ProcessWire;
/**
 * NativeForum Template (Bootstrap 5)
 * Register page (also used by modal).
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

// Safe return path helper (shared with login template)
if(!function_exists(__NAMESPACE__ . '\\sf_safe_return_path')) {
  function sf_safe_return_path(string $ret, Config $config): string {
    $ret = trim(str_replace(["\r","\n"], '', $ret));
    if($ret === '') return '';
    if(strpos($ret, '://') !== false || str_starts_with(strtolower($ret), 'javascript:')) return '';
    if($ret[0] !== '/') $ret = '/' . ltrim($ret, '/');
    $root = $config->urls->root ?: '/';
    if($root !== '/' && strpos($ret, $root) !== 0) return '';
    if(strpos($ret, '/forum-login') !== false || strpos($ret, '/forum-register') !== false) return '';
    return $ret;
  }
}

$return = (string) $input->post->return ?: (string) $input->get->return;
// session fallback (useful for modal register and hosts that strip query params)
if(!$return) {
  try {
    $return = (string) ($session ? $session->get('sf_return') : '');
  } catch(\Throwable $e) {
    $return = '';
  }
}
if(!$return) {
  $ref = (string) ($input->server->HTTP_REFERER ?? '');
  if($ref) {
    $u = parse_url($ref);
    if(!empty($u['path'])) $return = $u['path'] . (!empty($u['query']) ? '?' . $u['query'] : '');
  }
}
$return = sf_safe_return_path($return, $config);

if($user->isLoggedin()) {
  try { if($session) $session->remove('sf_return'); } catch(\Throwable $e) {}
  $session->redirect($return ?: $forum->forumRoot()->url);
}

$message = '';
$notice = '';
$vs = (string) $input->get->verify_status;
if($vs === 'sent') {
  $em = $sanitizer->email((string) $input->get->em);
  $notice = 'Almost done! We sent a confirmation link to ' . ($em ? '<strong>' . $sanitizer->entities($em) . '</strong>' : 'your e-mail') . '. Click it to activate your account — the link is valid for ' . (int) round($forum->verifyExpireSeconds() / 3600) . ' hours. Check your spam folder if you do not see it.';
} elseif($vs === 'expired') {
  $message = 'This confirmation link has expired or was already used. Please register again.';
} elseif($vs === 'invalid') {
  $message = 'Invalid confirmation link.';
} elseif($vs === 'exists') {
  $message = 'This account already exists. Please log in.';
}

if($input->post->register_submit) {
  $err = $forum->antiSpamCheck('register', 3);
  if($err) {
    $message = $sanitizer->entities($err);
  } else {
    try {
      $u = $forum->registerUser((string) $input->post->name, (string) $input->post->email, (string) $input->post->pass1, (string) $input->post->pass2, (string) $return);
      if(!$u) {
        // E-mail confirmation required: nothing is created until the link is clicked
        $session->redirect($page->url . '?verify_status=sent&em=' . rawurlencode((string) $sanitizer->email((string) $input->post->email)), false);
      }
      $session->login($u->name, (string) $input->post->pass1);
      try { $session->remove('sf_return'); } catch(\Throwable $e) {}
      $session->redirect($return ?: $forum->forumRoot()->url);
    } catch(WireException $e) {
      $message = $sanitizer->entities($e->getMessage());
    } catch(\Throwable $e) {
      $message = 'Registration failed. Please try again.';
    }
  }
}

$sidebarHtml = sf_sidebar_html($forum, $page, $pages, $users, $sanitizer, $user);

$theme = sf_forum_theme($forum);

$forumRoot = $forum->forumRoot() ?: $pages->get('/forum/');
$titleHtml = "<div class='sf-breadcrumbbar'><a href='{$forumRoot->url}'>Board index</a><span class='sf-breadcrumb-sep'>»</span>Register</div>";
sf_layout_open($forum, $config, $titleHtml, $sidebarHtml);

// Registration closed, or confirmation e-mail sent: show only the message (no form)
$sfRegClosed = !$forum->cfgOn('allowRegistration');
if($sfRegClosed || $notice) {
  $loginPage = $pages->get("template=forum-login, include=all");
  $loginUrl  = ($loginPage && $loginPage->id) ? $loginPage->url : '/forum-login/';
  echo "<div class='sf-card'><div class='sf-card-h'><h1 class='sf-card-title'>" . ($sfRegClosed ? 'Registration closed' : 'Create account') . "</h1></div><div class='sf-card-b'>";
  if($sfRegClosed) echo "<p>New registrations are currently closed. Already have an account? <a href='" . $sanitizer->entities($loginUrl) . "'>Log in</a>.</p>";
  else echo "<div class='sf-alert sf-alert-success' role='status'>{$notice}</div>";
  echo "</div></div>";
  sf_layout_close($forum, $sidebarHtml);
  return;
}

if($theme === 'bootstrap') {
  echo "<div class='row justify-content-center'><div class='col-md-8 col-lg-6'>";
  echo "<div class='sf-card'>";
  echo "<div class='sf-card-h'><h1 class='sf-card-title'>Create account</h1></div>";
  echo "<div class='sf-card-b'>";

  if($message) echo "<div class='sf-alert sf-alert-error' role='alert'>{$message}</div>";
  if($notice) echo "<div class='sf-alert sf-alert-success' role='status'>{$notice}</div>";

  echo "<form method='post' novalidate>" . $forum->antiSpamFields('register');
  if($return) echo "<input type='hidden' name='return' value='" . $sanitizer->entities($return) . "'>";
  echo "<div class='mb-3'><label class='form-label'>Username</label><input class='form-control' type='text' name='name' autocomplete='username' minlength='3' maxlength='40' pattern='[A-Za-z0-9_-]+' required value='" . $sanitizer->entities((string) $input->post->name) . "'></div>";
  echo "<div class='mb-3'><label class='form-label'>Email</label><input class='form-control' type='email' name='email' autocomplete='email' required value='" . $sanitizer->entities((string) $input->post->email) . "'></div>";
  echo "<div class='mb-3'><label class='form-label'>Password</label><input class='form-control' type='password' name='pass1' autocomplete='new-password' minlength='8' required></div>";
  echo "<div class='mb-3'><label class='form-label'>Repeat password</label><input class='form-control' type='password' name='pass2' autocomplete='new-password' minlength='8' required></div>";
  echo "<button class='btn btn-primary' type='submit' name='register_submit' value='1'>Create account</button>";
  // In Bootstrap theme this is convenient to open login modal.
  
  $loginPage = $pages->get("template=forum-login, include=all");
  $loginUrl  = $loginPage && $loginPage->id ? $loginPage->url : '#';
  echo " <a class='btn btn-link' href='" . $sanitizer->entities($loginUrl) . "'>Login</a>";
  echo "</form>";

  echo "</div></div></div></div>";
} else {
  // Simple (built-in) theme: plain form + real links (no modals)
  $loginPage = $pages->get("template=forum-login, include=all");
  $loginUrl  = ($loginPage && $loginPage->id) ? $loginPage->url : '/forum-login/';

  echo "<div class='sf-card'>";
  echo "<div class='sf-card-h'><h1 class='sf-card-title'>Create account</h1></div>";
  echo "<div class='sf-card-b'>";
  if($message) echo "<div class='sf-alert sf-alert-error' role='alert'>{$message}</div>";
  if($notice) echo "<div class='sf-alert sf-alert-success' role='status'>{$notice}</div>";

  echo "<form method='post' novalidate>" . $forum->antiSpamFields('register');
  if($return) echo "<input type='hidden' name='return' value='" . $sanitizer->entities($return) . "'>";
  echo "<div class='sf-mb-2'><label class='sf-muted'>Username</label><br><input class='sf-input' type='text' name='name' autocomplete='username' minlength='3' maxlength='40' pattern='[A-Za-z0-9_-]+' required value='" . $sanitizer->entities((string) $input->post->name) . "'></div>";
  echo "<div class='sf-mb-2'><label class='sf-muted'>Email</label><br><input class='sf-input' type='email' name='email' autocomplete='email' required value='" . $sanitizer->entities((string) $input->post->email) . "'></div>";
  echo "<div class='sf-mb-2'><label class='sf-muted'>Password</label><br><input class='sf-input' type='password' name='pass1' autocomplete='new-password' minlength='8' required></div>";
  echo "<div class='sf-mb-2'><label class='sf-muted'>Repeat password</label><br><input class='sf-input' type='password' name='pass2' autocomplete='new-password' minlength='8' required></div>";
  echo "<button class='sf-btn sf-btn-primary' type='submit' name='register_submit' value='1'>Create account</button>";
  echo " <a class='sf-btn sf-btn-ghost' href='{$loginUrl}'>Login</a>";
  echo "</form>";
  echo "</div></div>";
}

sf_layout_close($forum, $sidebarHtml, $pages, $sanitizer, $session, $user);
