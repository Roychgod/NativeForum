<?php namespace ProcessWire;
/**
 * NativeForum Template
 * Private messages list (All / Inbox / Sent) with sidebar.
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

if(!$forum->privateMessagesEnabled()) {
  echo "<div class='sf-card'><div class='sf-card-b'><p>Private messages are currently disabled.</p></div></div>";
  return;
}
if(!$user->isLoggedin()) {
  $lp = $pages->get("template=forum-login, include=hidden");
  $session->redirect(($lp && $lp->id ? $lp->url : $config->urls->root) . '?return=' . rawurlencode($page->url), false);
}

$root = $forum->messagesRoot();
if(!$root || !$root->id) $root = $page;

$mode = 'all';

// Support both URL segments (/inbox/) and query-string (?box=inbox) routing.
// Some sites have urlSegments disabled on the messages template, so relying on
// urlSegment1 would make Inbox/Sent links appear "not working".
if($config->usePageSegments && !empty($input->urlSegment1)) {
  $seg = strtolower($sanitizer->name($input->urlSegment1));
  if(in_array($seg, ['inbox','sent','all'], true)) $mode = $seg;
} else {
  $q = strtolower($sanitizer->name((string) ($input->get->box ?? $input->get->mode ?? '')));
  if(in_array($q, ['inbox','sent','all'], true)) $mode = $q;
}

$theme = function_exists(__NAMESPACE__ . '\\sf_forum_theme') ? sf_forum_theme($forum) : ((string)($forum->get('frontendTheme') ?: 'simple'));

$messagesUrl = function(string $which) use ($root, $config): string {
  $which = in_array($which, ['all','inbox','sent'], true) ? $which : 'all';
  if($config->usePageSegments) {
    if($which === 'all') return $root->url;
    return $root->url . $which . '/';
  }
  // Query-string fallback
  if($which === 'all') return $root->url;
  return $root->url . '?box=' . $which;
};

$getUserFromField = function(Page $p, string $field) use ($pages): ?User {
  if(!$p->hasField($field)) return null;
  $val = $p->getUnformatted($field);
  // In some setups the unformatted value may be a numeric id
  if(is_numeric($val)) {
    return sf_user_by_id((int)$val);
  }
  if($val instanceof User) return $val;
  if($val instanceof Page && $val->template && $val->template->name === 'user') return $val;
  if($val instanceof PageArray || $val instanceof WireArray) {
    $first = $val->first();
    if($first instanceof User) return $first;
    if($first instanceof Page && $first->template && $first->template->name === 'user') return $first;
  }
  return null;
};

$inbox = $root->children("template=forum-message, forum_to_user=$user, sort=-created");
$sent  = $root->children("template=forum-message, forum_from_user=$user, sort=-created");

// Compose (new message)
$composeError = '';
$prefillTo = (string) ($input->get->to ?? '');
$prefillTo = trim($prefillTo);

$resolveToUser = function(string $toVal): ?User {
  $toVal = trim($toVal);
  if($toVal === '') return null;
  // Support both numeric ID and username
  if(ctype_digit($toVal)) return sf_user_by_id((int)$toVal);
  $u = wire('users')->get("name=" . wire('sanitizer')->pageName($toVal, true) . ", include=all, check_access=0");
  return ($u && $u->id) ? $u : null;
};

if($input->post->pm_send) {
  try {
    $session->CSRF->validate('sfpm');
  } catch(\Throwable $e) {
    $composeError = 'Security token expired. Please try again.';
  }

  if(!$composeError) {
    $toVal = (string) $input->post->to;
    $toU = $resolveToUser($toVal);
    $subject = trim((string) $input->post->subject);
    $body = trim((string) $input->post->body);

    if(!$toU || !$toU->id) $composeError = 'Please choose a valid recipient.';
    elseif($toU->id === $user->id) $composeError = 'You cannot message yourself.';
    elseif($subject === '') $composeError = 'Subject is required.';
    elseif($body === '') $composeError = 'Message body is required.';
    else {
      try {
        $forum->sendMessage($user, $toU, $subject, $body);
        $session->redirect($messagesUrl('sent'));
      } catch(WireException $e) {
        $composeError = $e->getMessage();
      }
    }
  }
}

$sidebarHtml = sf_sidebar_html($forum, $root, $pages, $users, $sanitizer);
$sfFr = $forum->forumRoot();
$titleHtml = "<div class='sf-breadcrumbbar'><a href='" . ($sfFr && $sfFr->id ? $sfFr->url : '/') . "'>Board index</a><span class='sf-breadcrumb-sep'>»</span>Private messages</div><h1 class='sf-h1'>Private messages</h1>";
sf_layout_open($forum, $config, $titleHtml, $sidebarHtml);

echo "<div class='sf-messages-page'><div class='sf-messages-card'>";

// Compose card (always shown when PM enabled)
$theme = function_exists(__NAMESPACE__ . '\\sf_forum_theme') ? sf_forum_theme($forum) : ((string)($forum->get('frontendTheme') ?: 'simple'));
if($theme === 'bootstrap') {
  echo "<div class='card mb-3'>";
  echo "<div class='card-header bg-white d-flex justify-content-between align-items-center'>";
  echo "<strong>New message</strong>";
  echo "</div>";
  echo "<div class='card-body'>";
  if($composeError) echo "<div class='alert alert-danger py-2'>" . $sanitizer->entities($composeError) . "</div>";
  echo "<form method='post' class='row g-2'>";
  echo $session->CSRF->renderInput('sfpm');
  echo "<div class='col-12 col-md-4'><label class='form-label small mb-1'>To (username or id)</label><input class='form-control form-control-sm' name='to' value='" . $sanitizer->entities($prefillTo) . "' required></div>";
  echo "<div class='col-12 col-md-8'><label class='form-label small mb-1'>Subject</label><input class='form-control form-control-sm' name='subject' required></div>";
  echo "<div class='col-12'><label class='form-label small mb-1'>Message</label><textarea class='form-control' rows='4' name='body' required></textarea></div>";
  echo "<div class='col-12 d-flex justify-content-end'><button class='btn btn-primary btn-sm' type='submit' name='pm_send' value='1'>Send</button></div>";
  echo "</form>";
  echo "</div></div>";
} else {
  echo "<div class='sf-card sf-mb-3'>";
  echo "<div class='sf-card-h'>New message</div>";
  echo "<div class='sf-card-b'>";
  if($composeError) echo "<div class='sf-alert sf-alert-danger'>" . $sanitizer->entities($composeError) . "</div>";
  echo "<form method='post' class='sf-form'>";
  echo $session->CSRF->renderInput('sfpm');
  echo "<p><label class='sf-label'>To (username or id)</label><input class='sf-input' name='to' value='" . $sanitizer->entities($prefillTo) . "' required></p>";
  echo "<p><label class='sf-label'>Subject</label><input class='sf-input' name='subject' required></p>";
  echo "<p><label class='sf-label'>Message</label><textarea class='sf-textarea' rows='5' name='body' required></textarea></p>";
  echo "<p><button class='sf-button-primary' type='submit' name='pm_send' value='1'>Send</button></p>";
  echo "</form>";
  echo "</div></div>";
}

// Nav (same markup for both themes)
echo "<ul class='nav nav-pills sf-msg-nav mb-3' role='tablist'>";
foreach(['all' => 'All', 'inbox' => 'Inbox', 'sent' => 'Sent'] as $k => $label) {
  $active = ($mode === $k) ? ' active' : '';
  $cur = ($mode === $k) ? " aria-current='page'" : '';
  echo "<li class='nav-item'><a class='nav-link{$active}'{$cur} href='" . $messagesUrl($k) . "'>" . $sanitizer->entities($label) . "</a></li>";
}
echo "</ul>";

$renderTable = function($title, $items, $whoLabel, $field) use ($sanitizer, $getUserFromField, $forum, $pages) {
  echo "<div class='sf-card'>";
  echo "<div class='sf-card-h'>" . $sanitizer->entities($title) . "</div>";
  echo "<div class='sf-card-b'>";
  if(!$items->count()) {
    echo "<p class='sf-muted'>No messages.</p>";
  } else {
    echo "<div class='sf-tablewrap'><table class='sf-table'>";
    echo "<thead><tr><th>Subject</th><th>{$whoLabel}</th><th class='sf-td-date'>Date</th><th class='sf-td-open'></th></tr></thead><tbody>";
    foreach($items as $m) {
      $u = $getUserFromField($m, $field);
      $unameLink = sf_user_link($u, $forum, $pages, $sanitizer);
      echo "<tr>";
      echo "<td><a href='{$m->url}'>" . $sanitizer->entities($m->title) . "</a></td>";
      echo "<td>{$unameLink}</td>";
      echo "<td class='sf-td-date'>" . sf_format_dt((int)$m->created) . "</td>";
      echo "<td class='sf-td-open' style='white-space:nowrap'><a class='sf-link' href='{$m->url}'>Open</a></td>";
      echo "</tr>";
    }
    echo "</tbody></table></div>";
  }
  echo "</div></div>";
};

if($mode === 'all' || $mode === 'inbox') $renderTable('Inbox', $inbox, 'From', 'forum_from_user');
if($mode === 'all' || $mode === 'sent')  $renderTable('Sent', $sent, 'To', 'forum_to_user');

echo "</div></div>";

sf_layout_close($forum, $sidebarHtml);
