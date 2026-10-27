<?php namespace ProcessWire;
/**
 * NativeForum Template
 * Private message view + quick reply.
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

if(!$forum->privateMessagesEnabled()) throw new Wire404Exception();
if(!$user->isLoggedin()) throw new Wire404Exception();

$from = $page->hasField('forum_from_user') ? $page->forum_from_user : null;
$to   = $page->hasField('forum_to_user')   ? $page->forum_to_user   : null;

// If user pages aren't viewable, page reference fields can appear empty.
// Recover IDs using unformatted values and load with check_access=0.
$extractUserId = function($raw): int {
  if(!$raw) return 0;
  if(is_numeric($raw)) return (int) $raw;
  if($raw instanceof User) return (int) $raw->id;
  if($raw instanceof Page) return (int) $raw->id;
  if($raw instanceof PageArray || $raw instanceof WireArray) {
    $first = $raw->first();
    if($first instanceof Page) return (int) $first->id;
  }
  return 0;
};

if((!$from || !$from->id) && $page->hasField('forum_from_user')) {
  $fid = $extractUserId($page->getUnformatted('forum_from_user'));
  if($fid) $from = sf_user_by_id($fid);
}
if((!$to || !$to->id) && $page->hasField('forum_to_user')) {
  $tid = $extractUserId($page->getUnformatted('forum_to_user'));
  if($tid) $to = sf_user_by_id($tid);
}

$canSee = ($from && $from->id && $from->id === $user->id) || ($to && $to->id && $to->id === $user->id) || ($forum && method_exists($forum,'isModerator') && $forum->isModerator($user));
if(!$canSee) throw new Wire404Exception();

// Mark as read for recipient
if($to && $to->id === $user->id && $page->hasField('forum_is_read') && !$page->forum_is_read) {
  $page->of(false);
  $page->forum_is_read = 1;
  $page->save('forum_is_read');
  $page->of(true);
}

// Quick reply (reply to sender)
$replyToUser = ($from && $from->id === $user->id) ? $to : $from;
if($input->post->pm_reply && $replyToUser && $replyToUser->id) {
  $subject = (string) $page->title;
  if(stripos($subject, 'Re:') !== 0) $subject = 'Re: ' . $subject;
  $body = (string) $input->post->body;
  if(trim($body) !== '') {
    $forum->sendMessage($user, $replyToUser, $subject, $body);
    $root = $forum->messagesRoot();
    if($root && $root->id) {
      $toUrl = $root->url . ($config->usePageSegments ? 'sent/' : '?box=sent');
      $session->redirect($toUrl);
    }
    $session->redirect('/');
  }
}

$sidebarHtml = sf_sidebar_html($forum, $page, $pages, $users, $sanitizer);
$sfFr = $forum->forumRoot();
$titleHtml = "<div class='sf-breadcrumbbar'><a href='" . ($sfFr && $sfFr->id ? $sfFr->url : '/') . "'>Board index</a><span class='sf-breadcrumb-sep'>»</span><a href='" . $page->parent->url . "'>Private messages</a></div><h1 class='sf-h1'>" . $sanitizer->entities(sf_title_plain($page->title)) . "</h1>";
sf_layout_open($forum, $config, $titleHtml, $sidebarHtml);

echo "<div class='sf-card'>";
echo "<div class='sf-card-b'>";
echo "<div class='sf-meta'>";
echo "<div><strong>From:</strong> " . sf_user_link($from, $forum, $pages, $sanitizer) . "</div>";
echo "<div><strong>To:</strong> " . sf_user_link($to, $forum, $pages, $sanitizer) . "</div>";
	echo "<div class='sf-muted'>" . sf_format_dt((int)$page->created, true) . "</div>";
echo "</div>";

	// Message body (styled like a chat bubble)
	$theme = function_exists(__NAMESPACE__ . '\\sf_forum_theme') ? sf_forum_theme($forum) : ((string)($forum->get('frontendTheme') ?: 'simple'));
	$bodyClass = ($theme === 'bootstrap') ? "sf-msgbody sf-msgbubble" : "sf-msgbody sf-msgbubble";
	echo "<div class='{$bodyClass}'>" . $page->forum_body . "</div>";
echo "</div></div>";

if($replyToUser && $replyToUser->id) {
  echo "<div class='sf-card' id='sf-reply'>";
  echo "<div class='sf-card-h'>Quick reply</div>";
  echo "<div class='sf-card-b'>";
  $theme = function_exists(__NAMESPACE__ . '\\sf_forum_theme') ? sf_forum_theme($forum) : ((string)($forum->get('frontendTheme') ?: 'simple'));
  $taClass = ($theme === 'bootstrap') ? 'form-control' : 'sf-textarea';
  $btnClass = ($theme === 'bootstrap') ? 'btn btn-primary' : 'sf-button-primary';
  echo "<form method='post' class='sf-form'>";
  echo "<p><textarea name='body' rows='5' class='{$taClass}' placeholder='Write your reply...'></textarea></p>";
  echo "<p><button type='submit' name='pm_reply' value='1' class='{$btnClass}'>Send reply</button></p>";
  echo "</form>";
  echo "</div></div>";
}

sf_layout_close($forum, $sidebarHtml);
