<?php namespace ProcessWire;
/**
 * NativeForum Template (Bootstrap 5)
 * Topic page: topic post + replies (with pagination) + reply form.
 */
/** @var Page $page */
/** @var User $user */
/** @var Input $input */
/** @var Sanitizer $sanitizer */
/** @var Session $session */
/** @var Config $config */

$forum = $modules->get('NativeForum');
$sfCfg = $modules->getConfig('NativeForum');

// Theme CSS (loaded once)
require_once $config->paths->templates . 'forum-layout.php';
sf_forum_css($forum, $config);

$sfVipClass = ($forum && method_exists($forum,'isVipCategory') && $page->parent && $forum->isVipCategory($page->parent)) ? ' sf-vip' : '';

if(!$forum) throw new Wire404Exception();
if(!$forum->canReadForum($user, $page)) throw new Wire404Exception();

// Hidden/unpublished topics should not be visible to non-moderators
$isMod = false;
try { $isMod = $forum->isModerator($user); } catch(\Throwable $e) { $isMod = false; }
if(($page->isHidden() || $page->isUnpublished()) && !$isMod) throw new Wire404Exception();

// View counter endpoint (works even when the main page is cached): /topic/?sf_view=1
if((int)$input->get->sf_view === 1) {
  if($page->hasField('forum_views')) {
    $k = 'sf_topic_view_' . (int)$page->id;
    $last = (int) $session->get($k);
    if(!$last || (time() - $last) > 3600) {
      $session->set($k, time());
      $forum->bumpTopicViews($page);
    }
  }
  header('Content-Type: image/gif');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
  exit;
}




$theme = sf_forum_theme($forum);

// Alias for readability (some blocks expect $topic)
$topic = $page;

// Mark current topic for Forum Activity block
$GLOBALS['sf_current_topic'] = $topic;

// --- True per-topic read tracking ---
// Store per-user map: { topicId: lastActivityTimestampRead }
if($user->isLoggedin()) {
  try {
    $sfTs = $forum->topicStats([(int) $topic->id]);
    $lastActivity = !empty($sfTs[(int) $topic->id]['last_created']) ? (int) $sfTs[(int) $topic->id]['last_created'] : (int)$topic->created;

    // Get existing map (if field exists)
    $map = [];
    if(method_exists($user,'hasField') && $user->hasField('forum_read_topics')) {
      $map = json_decode((string)$user->get('forum_read_topics') ?: '{}', true);
      if(!is_array($map)) $map = [];

      // Only move forward (avoid rewinding read state)
      $cur = (int)($map[(string)$topic->id] ?? $map[(int)$topic->id] ?? 0);
      if($lastActivity > $cur) {
        $map[(string)$topic->id] = $lastActivity;
        $forum->saveReadMap($user, $map);
      }
    }
  } catch(\Throwable $e) {
    // ignore
  }
}


// Icon used in multiple places (keeps Tracy happy)
$quoteIcon = sf_icon($forum, "quote");

// Vote widget renderer (desktop: vertical column on the right; mobile: below content)
$renderVoteWidget = function(int $postId, int $score) use ($forum, $user, $session) {
  $canVote = false;
  try { $canVote = ($user && $user->isLoggedin() && $forum && $forum->canVote($user)); } catch(\Throwable $e) { $canVote = false; }

  $html = "<div class='sf-post-vote sf-post-vote--vert' aria-label='Vote'>";
  if($canVote) {
    $html .= "<form method='post' class='m-0'>" . $session->CSRF->renderInput('sfvote');
    $html .= "<input type='hidden' name='vote_post' value='" . (int)$postId . "'>";
    $html .= "<input type='hidden' name='delta' value='1'>";
    $html .= "<button class='btn btn-outline-secondary btn-sm' type='submit' title='Thumbs up' aria-label='Thumbs up'>" . sf_icon($forum,'thumbs-up') . "</button>";
    $html .= "</form>";
  }
  $html .= "<div class='sf-muted-sm text-center sf-vote-score'>" . (int)$score . "</div>";
  if($canVote) {
    $html .= "<form method='post' class='m-0'>" . $session->CSRF->renderInput('sfvote');
    $html .= "<input type='hidden' name='vote_post' value='" . (int)$postId . "'>";
    $html .= "<input type='hidden' name='delta' value='-1'>";
    $html .= "<button class='btn btn-outline-secondary btn-sm' type='submit' title='Thumbs down' aria-label='Thumbs down'>" . sf_icon($forum,'thumbs-down') . "</button>";
    $html .= "</form>";
  }
  $html .= "</div>";
  return $html;
};



$canPost = $forum->canPostToTopic($page, $user);
$isMod   = $forum->isModerator($user);

// Current pagination page (used to keep edit actions on the same page)
// Support both our query-string pagination (?p=2) and ProcessWire pageNum (/page2/).
$curPage = max(1, (int) $input->get->p);
try {
  $curPage = max($curPage, (int) $input->pageNum);
} catch(\Throwable $e) {
  // ignore
}

// Posts per page (topic + replies). Includes the topic post itself as #1.
$postsPerPage = (int) ($sfCfg['postsPerPage'] ?? 11);
if($postsPerPage < 3) $postsPerPage = 3;
if($postsPerPage > 200) $postsPerPage = 200;

// Jump to a specific reply from search results: /topic/?r=123
$jumpReplyId = (int) $input->get->r;
if($jumpReplyId > 0) {
  try {
    $r = $pages->get($jumpReplyId);
    if($r && $r->id && $r->template && $r->template->name === 'forum-reply' && (int)$r->parent_id === (int)$page->id) {
      $before = $pages->count("template=forum-reply, parent={$page->id}, created<{$r->created}");
      // stable tie-breaker for replies with same timestamp
      $before += $pages->count("template=forum-reply, parent={$page->id}, created={$r->created}, id<{$r->id}");
      $replyIndex = $before + 1; // 1-based among replies
      $postNum = 1 + $replyIndex; // topic itself is #1
      $pageNum = (int) ceil($postNum / $postsPerPage);
      if($pageNum < 1) $pageNum = 1;
      $dest = $page->url . ($pageNum > 1 ? "?p={$pageNum}" : '') . "#reply-{$r->id}";
      $session->redirect($dest);
    }
  } catch(\Throwable $e) {
    // ignore
  }
}


// Upload context for reply attachments (AJAX temp uploads)
$uploadCtxReply = '';
if($user->isLoggedin() && $forum && method_exists($forum, 'attachmentsEnabled') && $forum->attachmentsEnabled()) {
  $k = 'sf_uplctx_reply_' . (int)$page->id . '_' . (int)$user->id;
  $uploadCtxReply = (string) $session->get($k);
  if(!$uploadCtxReply) {
    try { $uploadCtxReply = 'r' . time() . '-' . bin2hex(random_bytes(8)); }
    catch(\Throwable $e) { $uploadCtxReply = 'r' . time() . '-' . mt_rand(1000,9999); }
    $session->set($k, $uploadCtxReply);
  }
}


// Voting (topic + replies)
if($input->post->vote_post) {
  $postId = (int) $input->post->vote_post;
  $back = $page->url . ((int) $input->get->p > 1 ? '?p=' . (int) $input->get->p : '');
  if($forum->canVote($user) && $session->CSRF->hasValidToken('sfvote')) {
    $post = $pages->get($postId);
    // Only the topic itself or one of its replies
    if($post && $post->id && ($post->id === $page->id || $post->parent_id === $page->id)) {
      try { $forum->vote($post, $user, (int) $input->post->delta); } catch(WireException $e) {}
      $back .= $post->id === $page->id ? '#post-' . $post->id : '#reply-' . $post->id;
    }
  }
  $session->redirect($back);
}

// Likes (topic)
if($input->post->like_topic) {
  if($forum->canVote($user) && $session->CSRF->hasValidToken('sfvote')) {
    try { $forum->toggleLike($page, $user); } catch(WireException $e) {}
  }
  $session->redirect($page->url . '#post-' . $page->id);
}

// Moderator actions (lock/pin/move/delete/restore)
if($input->post->sf_mod_action) {
  if(!$isMod) throw new Wire404Exception();
  $session->CSRF->validate('sfmod');

  $action = $sanitizer->name($input->post->sf_mod_action);

  if($action === 'lock')   { $forum->setTopicLocked($page, true,  $user); $session->redirect($page->url); }
  if($action === 'unlock') { $forum->setTopicLocked($page, false, $user); $session->redirect($page->url); }
  if($action === 'pin')    { $forum->setTopicPinned($page, true,  $user); $session->redirect($page->url); }
  if($action === 'unpin')  { $forum->setTopicPinned($page, false, $user); $session->redirect($page->url); }

  if($action === 'move') {
    $newId = (int) $input->post->new_category;
    $newCat = $pages->get($newId);
    if($newCat && $newCat->id && $newCat->template->name === 'forum-category') $forum->moveTopic($page, $newCat, $user);
    $session->redirect($page->url);
  }

  if($action === 'delete_reply' || $action === 'restore_reply') {
    $rid = (int) $input->post->reply_id;
    $reply = $pages->get($rid);
    if($reply && $reply->id && $reply->template->name === 'forum-reply' && $reply->parent->id === $page->id) {
      $forum->setReplyDeleted($reply, $action === 'delete_reply', $user);
      $session->redirect($page->url . '#reply-' . (int)$reply->id);
    }
    $session->redirect($page->url);
  }

  $session->redirect($page->url);
}

// New reply
$sfFormError = '';
if($input->post->submit_reply) {
 try {
  if(!$canPost) throw new WireException('Posting not allowed.');
  if($user->isGuest()) {
    // guests: cache-safe token + honeypot + time trap + JS check (+ optional captcha)
    if(($spamErr = $forum->antiSpamCheck('reply', 4)) !== '') throw new WireException($spamErr);
  } elseif(!$session->CSRF->hasValidToken('sfreply')) {
    throw new WireException('Your session expired. Please copy your text, reload the page and try again.');
  }

  $body = (string) $input->post->body; // preserve HTML
  $replyTo = null;
  $replyToId = (int) $input->post->reply_to;

  if($replyToId) {
    $tmp = $pages->get($replyToId);
    if($tmp->id && $tmp->template->name === 'forum-reply' && $tmp->parent->id === $page->id) $replyTo = $tmp;
  }

  if(trim($body) !== '') {
    $ctx = (string) $input->post->sf_upload_ctx;
    $reply = $forum->createReply($page, $user, $body, $replyTo, $ctx);

    // Redirect to the last page and the new reply anchor
    $perPage = $postsPerPage;
    $totalReplies = (int) $pages->count("template=forum-reply, parent={$page->id}, include=all");
    $totalPosts = 1 + $totalReplies; // topic + replies
    $totalPages = (int) ceil($totalPosts / $perPage);
    $url = $page->url . ($totalPages > 1 ? "?p={$totalPages}" : "") . "#reply-" . (int) $reply->id;
    $session->redirect($url);
  } else {
    throw new WireException('Your reply is empty.');
  }
 } catch(WireException $e) {
  $sfFormError = $e->getMessage();
 }
}

// Follow / unfollow topic (AJAX, no refresh)
if($input->post->sf_follow_toggle && $forum->cfgOn('enableTopicFollow')) {
  // Use ProcessWire's built-in ajax flag (compatible across PW versions).
  $isAjax = (bool) $config->ajax;

  if(!$user->isLoggedin()) {
    if($isAjax) {
      if(class_exists('\\Tracy\\Debugger')) { \Tracy\Debugger::$showBar = false; }
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'login' => true]);
      exit;
    }
    $session->redirect($page->url);
  }

  $session->CSRF->validate('sffollow');

  $now = false;
  try {
    $now = $forum->toggleFollowTopic($user, $page);
  } catch(\Throwable $e) {
    $now = false;
  }

  if($isAjax) {
    if(class_exists('\\Tracy\\Debugger')) { \Tracy\Debugger::$showBar = false; }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'following' => (bool)$now]);
    exit;
  }

  $session->redirect($page->url);
}

// Report post (topic or reply) — emails admin immediately
if($input->post->sf_report_post) {
  if(!$user->isLoggedin()) $session->redirect($page->url);
  $session->CSRF->validate('sfreport');
  $pid = (int) $input->post->sf_report_post;
  $target = $pages->get($pid);

  $ok = false;
  if($target && $target->id && in_array($target->template->name, ['forum-topic','forum-reply'], true)) {
    // Only allow reporting posts that belong to this topic view
    if($target->template->name === 'forum-topic' && (int)$target->id === (int)$page->id) $ok = true;
    if($target->template->name === 'forum-reply' && $target->parent && (int)$target->parent->id === (int)$page->id) $ok = true;
  }

  if($ok) {
    $sfReason = (string) $input->post->sf_report_reason;
    $sfComment = (string) $input->post->sf_report_comment;
    $forum->addReport($target, $user, $sfReason, $sfComment);
    $sfReasons = $forum->reportReasons();
    $adminTo = (string) $config->adminEmail;
    if(!$adminTo) {
      // NOTE: Users::findOne() does not exist in some ProcessWire versions.
      // Users::get() supports selectors and returns the first match (or NullPage).
      $su = $users->get('roles=superuser, email!=');
      if($su && $su->id) $adminTo = (string) $su->email;
    }

    if($adminTo) {
      $who = $user->isLoggedin() ? ($user->name . ' (#' . (int)$user->id . ')') : 'Guest';
      $postUrl = $page->httpUrl . '#'. ($target->template->name === 'forum-reply' ? ('reply-' . (int)$target->id) : ('post-' . (int)$page->id));
      $subj = 'Forum report: ' . $page->title . ' (post #' . (int)$target->id . ')';
      $body = "A forum post was reported.\n\n";
      $body .= "Topic: " . $page->title . " (#" . (int)$page->id . ")\n";
      $body .= "Reported post ID: #" . (int)$target->id . "\n";
      $body .= "Reported by: " . $who . "\n";
      $body .= "Reason: " . ($sfReasons[$sfReason] ?? 'Other') . "\n";
      if(trim($sfComment) !== '') $body .= "Comment: " . trim($sanitizer->textarea($sfComment)) . "\n";
      $body .= "Link: " . ($target->template->name === 'forum-reply' ? $target->httpUrl : $page->httpUrl) . "\n";
      $body .= "Moderation queue: " . $config->urls->httpAdmin . "native-forum/reports/\n\n";
      $body .= "--- Post excerpt ---\n";
      $excerpt = '';
      if($target->hasField('forum_body')) $excerpt = (string)$target->getUnformatted('forum_body');
      elseif($target->hasField('body')) $excerpt = (string)$target->body;
      $excerpt = trim(strip_tags($excerpt));
      if(strlen($excerpt) > 800) $excerpt = substr($excerpt, 0, 800) . '…';
      $body .= $excerpt . "\n";

      $m = wireMail();
      $m->to($adminTo);
      if($config->adminEmail) $m->from($config->adminEmail);
      $m->subject($subj);
      $m->body($body);
      try { $m->send(); } catch(\Throwable $e) {}
    }
  }

  // Return to the same post
  // Return to the same post + show a modal confirmation (via ?sf_reported=1)
  $hash = ($pid === (int)$page->id ? ('post-' . (int)$page->id) : ('reply-' . (int)$pid));
  $redir = $page->url;
  if($ok && $target->template->name === 'forum-reply') {
    $sfRu = $forum->replyUrl($target);
    $redir = preg_replace('~#.*$~', '', $sfRu);
  }
  $qSep = (strpos($redir, '?') === false ? '?' : '&');
  $redir .= $qSep . 'sf_reported=1&sf_pid=' . (int)$pid;
  $session->redirect($redir . '#' . $hash);
}

// Edit own post (topic or reply)
if($input->post->submit_edit_post) {
  if(!$user->isLoggedin()) $session->redirect($page->url);
  $session->CSRF->validate('sfedit');
  $pid = (int) $input->post->post_id;
  $body = (string) $input->post->body;
  $target = $pages->get($pid);
  if($target && $target->id && in_array($target->template->name, ['forum-topic','forum-reply'], true)) {
    // Return to the same pagination page where the edit was initiated.
    $retP = (int) $input->post->return_p;
    if($retP < 1) $retP = (int) $input->get->p;
    if($retP < 1) $retP = 1;
    $retQ = ($retP > 1) ? ('?p=' . $retP) : '';

    // ensure reply belongs to this topic
    if($target->template->name === 'forum-topic' && $target->id === $page->id) {

      // Allow editing of topic meta (title, summary, tags) when editing the first post.
      $target->of(false);

      $newTitle = $sanitizer->text((string)$input->post->title);
      if($newTitle) $target->title = $newTitle;

      $newSummary = $sanitizer->text((string)$input->post->summary);
      if($target->hasField('field_forum_summary_topic')) $target->set('field_forum_summary_topic', $newSummary);
      else if($target->hasField('summary')) $target->set('summary', $newSummary);

      $tagsRaw = (string) $input->post->tags;
      if($tagsRaw && $target->hasField('forum_tags') && method_exists($forum,'parseTagsString')) {
        $tagsArr = $forum->parseTagsString($tagsRaw);
        $target->set('forum_tags', implode(' ', $tagsArr)); // FieldtypeTags accepts arrays (prevents word-splitting)
      } else if($target->hasField('forum_tags') && $tagsRaw === '') {
        // allow clearing tags
        $target->set('forum_tags', '');
      }

      // Optional: store edit reason if such field exists on this template
      $editReason = $sanitizer->text((string)$input->post->edit_reason);
      if($editReason) {
        foreach(['forum_edit_reason','edit_reason','sf_edit_reason'] as $rf) {
          if($target->hasField($rf)) { $target->set($rf, $editReason); break; }
        }
      }

      $target->save();
      $target->of(true);

      $ok = $forum->updatePostBody($target, $user, $body);
      $session->redirect($page->url . $retQ . '#post-' . (int)$page->id);
    }
    if($target->template->name === 'forum-reply' && $target->parent && $target->parent->id === $page->id) {
      $ok = $forum->updatePostBody($target, $user, $body);
      $session->redirect($page->url . $retQ . '#reply-' . (int)$target->id);
    }
  }
  $session->redirect($page->url);
}

$editId = (int) $input->get->edit;

// Reply-to preview (GET)
$replyToPreview = null;
$replyToGetId = (int) $input->get->reply_to;
if($replyToGetId) {
  $tmp = $pages->get($replyToGetId);
  if($tmp->id && $tmp->template->name === 'forum-reply' && $tmp->parent->id === $page->id) $replyToPreview = $tmp;
}

// Breadcrumb pieces
$forumRoot = $forum->forumRoot() ?: $pages->get('/forum/');
$cat = $page->parent;
$group = ($cat && $cat->parent && $cat->parent->template->name === 'forum-group') ? $cat->parent : null;

$isVipContext = ($forum && $cat && method_exists($forum,'isVipCategory')) ? (bool)$forum->isVipCategory($cat) : false;

// View counting happens in the ?sf_view=1 pixel above (works with ProCache / full-page caches).

// Topic author (resolve even when user pages aren't viewable)
$topicAuthor = sf_page_author_user($page);
$topicNickname = sf_user_display_name($topicAuthor);

$topicRank = ($topicAuthor && $topicAuthor->id) ? $forum->getUserRank($topicAuthor) : '';
$topicProfileUrl = ($topicAuthor && $topicAuthor->id) ? sf_user_profile_url($forum, $pages, $topicAuthor) : '';
$topicSignature = ($topicAuthor && $topicAuthor->id && $topicAuthor->hasField('forum_signature')) ? (string)$topicAuthor->forum_signature : '';

// Avatar resolver
$resolveAvatar = function(User $u) {
  $avatar = null;
  $u->of(false);
  $fieldVal = null;
  if($u->hasField('forum_avatar') && $u->forum_avatar) $fieldVal = $u->forum_avatar;
  elseif($u->hasField('avatar') && $u->avatar) $fieldVal = $u->avatar;
  else {
    foreach($u->fields as $f) {
      if($f->type instanceof FieldtypeImage) {
        $images = $u->get($f->name);
        if($images) { $fieldVal = $images; break; }
      }
    }
  }
  if($fieldVal instanceof Pageimages) {
    $first = $fieldVal->first();
    // Some contexts can yield false; normalize to null
    $avatar = ($first instanceof Pageimage) ? $first : null;
  } elseif($fieldVal instanceof Pageimage) {
    $avatar = $fieldVal;
  }
  $u->of(true);
  return $avatar;
};

$topicAvatar = ($topicAuthor && $topicAuthor->id) ? $resolveAvatar($topicAuthor) : null;
$msgRoot = $forum->messagesRoot();

// Pagination: include topic post as #1
$allRepliesCount = $page->children("template=forum-reply")->count();
$perPage = $postsPerPage; // posts per page (topic + replies)
$totalPosts = 1 + $allRepliesCount;
$totalPages = max(1, (int)ceil($totalPosts / $perPage));
$curPage = max(1, (int)$input->get->p);
if($curPage > $totalPages) $curPage = $totalPages;

if($curPage === 1) {
  $replyStart = 0;
  $replyLimit = max(0, $perPage - 1);
} else {
  $replyStart = ($perPage - 1) + ($curPage - 2) * $perPage;
  $replyLimit = $perPage;
}

$replies = $page->children("template=forum-reply, sort=created, sort=id, start={$replyStart}, limit={$replyLimit}");
// Batch work for this page of posts: authors (+ their stats) and reply URLs, instead of queries per post
$sfPageUrl = $page->url . ($curPage > 1 ? '?p=' . (int) $curPage : '');
$sfBatch = [$page];
foreach($replies as $r) {
  $forum->primeReplyUrl((int) $r->id, $sfPageUrl . '#reply-' . (int) $r->id);
  $sfBatch[] = $r;
  $sfRt = $r->hasField('forum_reply_to') ? $r->getUnformatted('forum_reply_to') : null;
  if($sfRt instanceof Page && $sfRt->id) $sfBatch[] = $sfRt;
}
$forum->preloadAuthors($sfBatch);

// Sidebar + header
$sidebarHtml = sf_sidebar_html($forum, $page, $pages, $users, $sanitizer, $user);

$crumb = "<div class='sf-breadcrumbbar'>"
  . "<a href='{$forumRoot->url}'>Board index</a>"
  . ($group ? "<span class='sf-breadcrumb-sep'>»</span><a href='{$group->url}'>" . $sanitizer->entities(sf_title_plain($group->title)) . "</a>" : "")
  . ($cat ? "<span class='sf-breadcrumb-sep'>»</span><a href='{$cat->url}'>" . $sanitizer->entities(sf_title_plain($cat->title)) . "</a>" : "")
  . "<span class='sf-breadcrumb-sep'>»</span>"
  . $sanitizer->entities(sf_title_plain($page->title))
  . "</div>";

sf_layout_open($forum, $config, $crumb, $sidebarHtml);

// Page header (title + summary) so you always know where you are
$topicSummary = '';
$sumField = (string) ($forum && method_exists($forum,'get') ? $forum->get('field_forum_summary_topic', 'summary') : 'summary');
$candidates = array_unique(array_filter([$sumField,'summary','description','forum_summary','intro','body']));
foreach($candidates as $f) {
  if($topicSummary) break;
  if($page->hasField($f)) {
    $val = trim(strip_tags((string)$page->get($f)));
    if($val) $topicSummary = $val;
  }
}
if($topicSummary && strlen($topicSummary) > 240) $topicSummary = rtrim(substr($topicSummary, 0, 240)) . '…';
echo "<div class='sf-pagehead card mb-3{$sfVipClass}'><div class='card-body" . ($sfVipClass ? " sf-vip-header" : "") . "'>";
echo "<div class='sf-pagehead-title'>" . $sanitizer->entities(sf_title_plain($page->title)) . "</div>";
if($topicSummary) echo "<div class='sf-pagehead-summary'>" . $sanitizer->entities($topicSummary) . "</div>";
echo "</div></div>";

// View tracking pixel (ensures view counts increment even with full-page caching)
$vp = $page->url . '?sf_view=1';
// sent after the page has loaded, so it never delays the page (or a site preloader waiting for onload)
echo "<script>(function(){var u=" . json_encode($vp) . ";function s(){try{if(navigator.sendBeacon&&navigator.sendBeacon(u))return;}catch(e){}(new Image()).src=u;}if(document.readyState==='complete')setTimeout(s,300);else window.addEventListener('load',function(){setTimeout(s,300);});})();</script>";
echo "<noscript><img src='{$vp}' alt='' width='1' height='1' style='position:absolute;left:-9999px;top:-9999px' /></noscript>";

// Toolbar
echo "<div class='sf-toolbar sf-toolbar--topic'>";
echo "<div class='sf-toolbar-left'>";
echo "<a class='btn btn-primary btn-sm' href='#reply-form'>Post Reply</a>";
echo "</div>";
echo "<div class='sf-toolbar-right'>";
// Quick search (PW-forum-like)
$ctxId = $cat ? (int)$cat->id : (int)$page->id;
$scopes = [
  'topic'      => 'This Topic',
  'this'       => 'This Category',
  'everywhere' => 'Everywhere',
];
$curScope = $input->get('sf_scope');
if(!isset($scopes[$curScope])) $curScope = 'topic';
echo sf_csearch_form_html($forum, $forumRoot->url, (string)$input->get('q'), $ctxId, $curScope, $scopes, 'sf-csearch-toolbar', 'Search Forums…', 'sm');

echo "<div class='sf-topic-pageinfo sf-muted-sm'>{$totalPosts} posts • Page {$curPage} of {$totalPages}</div>";
echo "</div>";
echo "</div>";

$renderPagination = function() use ($page, $curPage, $totalPages, $input): string {
  if($totalPages <= 1) return '';
  $q = [];
  $html = "<div class='sf-pagination'>";
  for($i=1; $i<=$totalPages; $i++) {
    // long topics: first, last and a window around the current page
    if($totalPages > 9 && $i !== 1 && $i !== $totalPages && abs($i - $curPage) > 2) {
      if($i === 2 || $i === $totalPages - 1) $html .= "<span class='sf-pagination-gap'>…</span>";
      continue;
    }
    $url = $page->url;
    if($i > 1) $url .= "?p={$i}";
    if($i === $curPage) $html .= "<span class='is-active' aria-current='page'>{$i}</span>";
    else $html .= "<a href='{$url}'>{$i}</a>";
  }
  $html .= "</div>";
  return $html;
};


echo $renderPagination();

// Moderator panel (minimal)
if($isMod) {
  $locked = $page->hasField('forum_is_locked') && (int)$page->forum_is_locked;
  $vip = ($forum && $page->parent && method_exists($forum,'isVipCategory')) ? $forum->isVipCategory($page->parent) : false;
  $pinned = ($forum && method_exists($forum,'isPinnedTopic')) ? $forum->isPinnedTopic($page) : ($page->hasField('forum_is_pinned') && (int)$page->forum_is_pinned);

  echo "<div class='sf-card mb-3'><div class='sf-card-h'>Moderator tools</div><div class='sf-card-b'>";
  echo "<form method='post' class='sf-mod-form d-flex flex-wrap gap-2 align-items-center'>";
  echo $session->CSRF->renderInput('sfmod');
  echo $locked
    ? "<button class='btn btn-outline-success btn-sm' type='submit' name='sf_mod_action' value='unlock'>Unlock</button>"
    : "<button class='btn btn-outline-danger btn-sm' type='submit' name='sf_mod_action' value='lock'>Lock</button>";
  echo $pinned
    ? "<button class='btn btn-outline-secondary btn-sm' type='submit' name='sf_mod_action' value='unpin'>Unpin</button>"
    : "<button class='btn btn-outline-secondary btn-sm' type='submit' name='sf_mod_action' value='pin'>Pin</button>";

  // Move dropdown
  $cats = $pages->find("template=forum-category, has_parent=" . (int)$forumRoot->id . ", include=all, sort=title");
  if($cats->count()) {
    echo "<select class='form-select form-select-sm' name='new_category' style='max-width:280px'>";
    foreach($cats as $c) {
      $sel = ($cat && $c->id === $cat->id) ? "selected" : "";
      echo "<option {$sel} value='" . (int)$c->id . "'>" . $sanitizer->entities($c->title) . "</option>";
    }
    echo "</select>";
    echo "<button class='btn btn-outline-primary btn-sm' type='submit' name='sf_mod_action' value='move'>Move</button>";
  }

  echo "</form></div></div>";
}

// Render author column (right)
$renderAuthorCol = function(?User $a, ?Pageimage $avatar, string $nickname, string $rank) use ($sanitizer, $pages, $forum, $resolveAvatar, $user): string {
  $profileUrl = ($a && $a->id) ? (string)$forum->userProfileUrl($a) : '';
  // Joined: date only (time is noise here and made the narrow author column wrap)
  $joined = ($a && $a->id && $a->created) ? preg_replace('~[\s,]+\d{1,2}:\d{2}(:\d{2})?\s*$~', '', sf_format_dt((int)$a->created)) : '';
  $posts = 0;
  if($a && $a->id) { $sfUs = $forum->userStats([(int) $a->id]); $posts = (int) ($sfUs[(int) $a->id]['messages'] ?? 0); }

  $html = "<div class='sf-userinfo text-center'>";
  // Pageimage doesn't have an "id" property; checking it would always be false.
  // Avatar + name should trigger the same hover user-card as the username elsewhere.
  if($avatar) {
    $thumb = $avatar->size(72, 72);
    $avInner = "<div class='sf-avatarbox'><img class='sf-avatarimg' src='{$thumb->url}' width='{$thumb->width}' height='{$thumb->height}' alt=''></div>";
  } else {
    $letter = strtoupper(substr($nickname, 0, 1));
    $avInner = "<div class='sf-avatar sf-avatar-placeholder mx-auto'>{$letter}</div>";
  }
  if($a && $a->id) $html .= sf_user_hover_wrap($a, $forum, $pages, $sanitizer, $avInner, 'sf-author-avatar-link');
  else $html .= $avInner;

  if($a && $a->id) {
    $html .= "<div class='sf-author-name'>" . sf_user_link($a, $forum, $pages, $sanitizer, ['class' => 'sf-link-title']) . "</div>";
  } else {
    $nameHtml = $sanitizer->entities($nickname);
    $html .= "<div class='sf-author-name'>{$nameHtml}</div>";
  }

  // Private message button (if enabled)
  if($a && $a->id && $forum->privateMessagesEnabled()) {
    $msgRoot = $forum->messagesRoot();
    if($msgRoot && $msgRoot->id && $user && $user->isLoggedin() && (int)$user->id !== (int)$a->id) {
      $icoPm = sf_icon($forum, 'pm');
      $html .= "<div class='mt-2'><a class='btn btn-outline-secondary btn-sm sf-icon-btn' title='Send message' aria-label='Send message' href='{$msgRoot->url}?to=" . (int)$a->id . "'>" . $icoPm . "</a></div>";
    }
  }

  if($rank) $html .= "<div class='sf-author-rank'>" . $sanitizer->entities($rank) . "</div>";

  $html .= "<div class='sf-muted-sm sf-author-stat mt-2'><span>Posts:</span> <strong>{$posts}</strong></div>";
  if($joined) $html .= "<div class='sf-muted-sm sf-author-stat'><span>Joined:</span> <span class='sf-date'>{$joined}</span></div>";
  $html .= "</div>";
  return $html;
};

// Topic post card
// Show the first (topic) post only on page 1 by default, unless enabled in settings.
$showTopicOnAllPages = (int) sf_cfg('showTopicPostOnAllPages', 0);
$showTopicPost = ($showTopicOnAllPages === 1) ? true : ((int)$curPage <= 1);
echo "<div class='sf-post-list'>";
if($showTopicPost) {

    echo "<div class='sf-post-card mb-3" . ($isVipContext ? " sf-post-vip" : "") . "' id='post-" . (int)$page->id . "' data-sf-kind='topic' data-sf-ts='" . (int)$page->created . "'>";
    // Stretch columns so the vote column can sit at the bottom-right on desktop
    echo "<div class='row g-0 align-items-stretch'>";
// Author LEFT (compact) / Message RIGHT (wide)
echo "<div class='col-12 col-md-2 col-lg-2 sf-post-authorcol'>";
echo $renderAuthorCol($topicAuthor, $topicAvatar, $topicNickname, $topicRank);
echo "</div>";

$voteScore = ($page->hasField('forum_vote_score')) ? (int)$page->forum_vote_score : 0;
$hasVoteCol = (($user->isLoggedin() && $forum->canVote($user)) || ((int)$voteScore !== 0));
$mainCols = 10;
echo "<div class='col-12 col-md-{$mainCols} col-lg-{$mainCols} sf-post-content'><div class='sf-post-main'>";
echo "<div class='sf-post-meta'>";
echo "<div>";
echo "<span class='sf-muted-sm'>by </span>";
echo $topicProfileUrl ? "<a href='{$topicProfileUrl}'>" . $sanitizer->entities($topicNickname) . "</a>" : $sanitizer->entities($topicNickname);
echo "<span class='sf-muted-sm'> • " . sf_format_dt((int)$page->created) . "</span>";
    $sfTopicPermalink = $page->httpUrl;
    $sfShareTitle = sf_title_plain((string) $page->title);
    echo " <a class='sf-post-id' href='" . $sanitizer->entities($sfTopicPermalink) . "' data-sf-permalink='" . $sanitizer->entities($sfTopicPermalink) . "' data-sf-anchor='post-" . (int)$page->id . "' title='Post #1 – click to copy the link'>#1</a>";
    echo " <button type='button' class='btn btn-link btn-sm p-0 ms-1 sf-copylink-btn' data-sf-copy='" . $sanitizer->entities($sfTopicPermalink) . "' data-bs-toggle='tooltip' data-bs-placement='left' title='Copy link'>" . sf_icon($forum, 'link') . "</button>";
echo "</div>";

echo "<div class='sf-post-header-actions sf-post-actions'>";


// Quote button (no refresh) — quotes the topic post
$topicQuoteMeta = $topicNickname . ' wrote · ' . sf_format_dt((int)$page->created);
echo "<button type='button' class='btn btn-outline-secondary btn-sm sf-quote-btn' data-bs-toggle='tooltip' data-bs-placement='left' title='Reply with quote'
  data-reply-to='0'
  data-quote-source='#post-" . (int)$page->id . " .sf-post-body'
  data-quote-url='" . $page->url . "#post-" . (int)$page->id . "'
  data-quote-meta=\"" . $sanitizer->entities($topicQuoteMeta) . "\">" .
  "" . $quoteIcon . "" .
  "<span class='visually-hidden'>Reply with quote</span>" .
  "</button>";

// Post actions menu (three dots)
$topicMenuId = 'sfPostMenuTopic' . (int)$page->id;
echo "<div class='dropdown'>";
echo "<button id='{$topicMenuId}' aria-controls='{$topicMenuId}-menu'  class='btn btn-outline-secondary btn-sm sf-icon-btn dropdown-toggle sf-dots-btn'  type='button' data-bs-toggle='dropdown' aria-expanded='false' aria-label='Post options' type='button' title='Options'>";
echo "" . sf_icon($forum,'dots') . "";
echo "</button>";
echo "<ul id='{$topicMenuId}-menu' class='dropdown-menu dropdown-menu-end sf-post-menu' aria-labelledby='{$topicMenuId}'>";

// Edit
if($user->isLoggedin() && $forum->canEditPost($page, $user)) {
  $qp = ($curPage > 1) ? ('?p=' . (int)$curPage . '&edit=') : '?edit=';
  echo "<li><a class='dropdown-item' href='{$page->url}{$qp}" . (int)$page->id . "#post-" . (int)$page->id . "'>" . sf_icon($forum,'edit') . "<span class='ms-2'></span>Edit</a></li>";
}

// Share
$shareUrl = $page->httpUrl;
$sfShareText = '';
try { $sfShareText = $forum->seo()->excerpt((string) $page->getUnformatted('forum_body'), 140); } catch(\Throwable $e) {}
echo "<li><button type='button' class='dropdown-item sf-share-btn' data-share-url='" . $sanitizer->entities($shareUrl) . "' data-share-title='" . $sanitizer->entities($sfShareTitle) . "' data-share-text='" . $sanitizer->entities($sfShareText) . "'>" . sf_icon($forum,'share') . "<span class='ms-2'></span>" . $sanitizer->entities(sfT('share', 'Share')) . "</button></li>";

// Report (emails admin)
if($user->isLoggedin()) {
  echo "<li><form method='post' class='m-0 px-2 py-1'>";
  echo $session->CSRF->renderInput('sfreport');
  echo "<button type='submit' name='sf_report_post' value='" . (int)$page->id . "' class='dropdown-item text-danger px-0 sf-report-btn'>" . sf_icon($forum,'report') . "<span class='ms-2'></span>Report</button>";
  echo "</form></li>";
}

echo "</ul></div>";

if($forum->isPinnedTopic($page)) echo "<span class='badge text-bg-warning sf-pinned-badge'>Pinned</span>";
if(($page->hasField('forum_is_locked') && (int)$page->forum_is_locked)) echo "<span class='badge text-bg-danger'>Locked</span>";

// Voting is shown at the bottom-right (below the post) to match the compact layout.

echo "</div></div>";

$bodyField = $page->hasField('forum_body') ? (string)$page->forum_body : (string)$page->body;
if($editId === (int)$page->id && $user->isLoggedin() && $forum->canEditPost($page, $user)) {
  $isWys = !empty($sfCfg['enableFrontendWysiwyg']);
  echo "<div class='sf-post-body'>";
  echo "<form method='post' class='sf-edit-form' onsubmit=\"return sfPreSubmitReply(this);\">";
  echo $session->CSRF->renderInput('sfedit');
  echo "<input type='hidden' name='post_id' value='" . (int)$page->id . "'>";
  echo "<input type='hidden' name='return_p' value='" . (int)$curPage . "'>";
  // Topic meta fields (only when editing the main topic post)
  if($page->template->name === 'forum-topic' && $editId === (int)$page->id) {
    $tTitleVal = htmlspecialchars((string)$page->title, ENT_QUOTES, 'UTF-8');
    $tSumVal = '';
    if($page->hasField('field_forum_summary_topic')) $tSumVal = htmlspecialchars((string)$page->get('field_forum_summary_topic'), ENT_QUOTES, 'UTF-8');
    else if($page->hasField('summary')) $tSumVal = htmlspecialchars((string)$page->summary, ENT_QUOTES, 'UTF-8');

    $tTagsVal = '';
    if($page->hasField('forum_tags')) $tTagsVal = htmlspecialchars(trim((string)$page->get('forum_tags')), ENT_QUOTES, 'UTF-8');

    echo "<div class='sf-edit-meta mb-2'>";
    echo "<div class='mb-2'><label class='form-label mb-1'>Title</label><input type='text' name='title' class='form-control' value='{$tTitleVal}'></div>";
    if($tSumVal !== '') {
      echo "<div class='mb-2'><label class='form-label mb-1'>Summary</label><input type='text' name='summary' class='form-control' value='{$tSumVal}'></div>";
    } else {
      // still show summary if the field exists
      if($page->hasField('field_forum_summary_topic') || $page->hasField('summary')) {
        echo "<div class='mb-2'><label class='form-label mb-1'>Summary</label><input type='text' name='summary' class='form-control' value=''></div>";
      }
    }
    if($page->hasField('forum_tags')) {
      echo "<div class='mb-2'><label class='form-label mb-1'>Tags</label><input type='text' name='tags' class='form-control' value='{$tTagsVal}' placeholder='#tag one  #tag two'></div>";
    }
    echo "<div class='mb-2'><label class='form-label mb-1'>Reason for editing</label><input type='text' name='edit_reason' class='form-control' value='' placeholder='Optional'></div>";
    echo "</div>";
  }
  
  echo "<textarea name='body' class='form-control " . ($isWys ? "sf-wysiwyg" : "") . "' rows='8'>" . htmlspecialchars(sf_embed_html_to_tokens((string)$bodyField), ENT_QUOTES, 'UTF-8') . "</textarea>";
  echo "<div class='mt-2 d-flex gap-2'>";
  echo "<button class='btn btn-primary btn-sm' type='submit' name='submit_edit_post' value='1'>Save changes</button>";
  $retQ = ($curPage > 1) ? ('?p=' . (int)$curPage) : '';
  echo "<a class='btn btn-outline-secondary btn-sm' href='{$page->url}{$retQ}#post-" . (int)$page->id . "'>Cancel</a>";
  echo "</div>";
  echo "</form></div>";
} else {
  echo "<div class='sf-post-body'>" . sf_render_post_html($forum, $bodyField, $sanitizer) . "</div>";
}
	  // Votes are rendered once per post.
	  // Responsive behavior is handled by the Bootstrap grid: on mobile the vote column is full width (below content),
	  // on md+ it becomes a slim right column.

// Voting (bottom-right, above signature)
if($hasVoteCol) {
  echo "<div class='sf-vote-slot d-flex justify-content-end mt-3'>" . $renderVoteWidget((int)$page->id, (int)$voteScore) . "</div>";
}

if(trim($topicSignature) !== '') {
  echo "<div class='sf-signature'>" . sf_render_post_html($forum, (string)$topicSignature, $sanitizer) . "</div>";
}

 echo "</div></div>";
	// Close: .row + .sf-post-card (avoid closing outer layout wrappers)
	echo "</div></div>";

// Replies
} // end $showTopicPost
$sfPostNo = ($curPage === 1) ? 1 : (($perPage - 1) + ($curPage - 2) * $perPage + 1);
foreach($replies as $r) {
  $sfPostNo++;
  $isDeleted = $r->hasField('forum_is_deleted') && (int)$r->forum_is_deleted;

  $a = sf_page_author_user($r);
  $nickname = sf_user_display_name($a);
  $rank = ($a && $a->id) ? $forum->getUserRank($a) : '';
  $profileUrl = ($a && $a->id) ? sf_user_profile_url($forum, $pages, $a) : '';
  $avatar = ($a && $a->id) ? $resolveAvatar($a) : null;

  echo "<div class='sf-post-card mb-3' id='reply-" . (int)$r->id . "' data-sf-kind='reply' data-sf-ts='" . (int)$r->created . "'>";
  // Stretch columns so the vote column can sit at the bottom-right on desktop
  echo "<div class='row g-0 align-items-stretch'>";

  // Author LEFT (compact) / Message RIGHT (wide)
  echo "<div class='col-12 col-md-2 col-lg-2 sf-post-authorcol'>";
  echo $renderAuthorCol($a, $avatar, $nickname, $rank);
  echo "</div>";

  $voteScore = ($page->hasField('forum_vote_score')) ? (int)$page->forum_vote_score : 0;
$hasVoteCol = (($user->isLoggedin() && $forum->canVote($user)) || ((int)$voteScore !== 0));
$mainCols = 10;
echo "<div class='col-12 col-md-{$mainCols} col-lg-{$mainCols} sf-post-content'><div class='sf-post-main'>";

  echo "<div class='sf-post-meta'>";
  echo "<div>";
  echo "<span class='sf-muted-sm'>by </span>";
  echo $profileUrl ? "<a href='{$profileUrl}'>" . $sanitizer->entities($nickname) . "</a>" : $sanitizer->entities($nickname);
  echo "<span class='sf-muted-sm'> • " . sf_format_dt((int)$r->created) . "</span>";
  $sfReplyPermalink = $r->httpUrl; // short permanent link: always lands on the right page and post
  echo " <a class='sf-post-id' href='" . $sanitizer->entities($forum->replyUrl($r)) . "' data-sf-permalink='" . $sanitizer->entities($sfReplyPermalink) . "' data-sf-anchor='reply-" . (int)$r->id . "' title='Post #{$sfPostNo} – click to copy the link'>#{$sfPostNo}</a>";
  echo " <button type='button' class='btn btn-link btn-sm p-0 ms-1 sf-copylink-btn' data-sf-copy='" . $sanitizer->entities($sfReplyPermalink) . "' title='Copy link'>" . sf_icon($forum, 'link') . "</button>";
  echo "</div>";

  echo "<div class='sf-post-header-actions sf-post-actions'>";


  if($user->isLoggedin()) {
    $quoteMeta = $nickname . ' wrote · ' . sf_format_dt((int)$r->created);
    echo "<button type='button' class='btn btn-outline-secondary btn-sm sf-quote-btn' data-bs-toggle='tooltip' data-bs-placement='left' title='Reply with quote'" .
      " data-reply-to='" . (int)$r->id . "'" .
      " data-quote-source='#reply-" . (int)$r->id . " .sf-post-body'" .
      " data-quote-url='" . $forum->replyUrl($r) . "'" .
      " data-quote-meta=\"" . $sanitizer->entities($quoteMeta) . "\">" .
      "" . $quoteIcon . "" .
      "<span class='visually-hidden'>Reply with quote</span>" .
      "</button>";

    // Post actions menu (three dots)
    $menuId = 'sfPostMenuReply' . (int)$r->id;
    echo "<div class='dropdown'>";
    echo "<button id='{$menuId}' class='btn btn-outline-secondary btn-sm sf-icon-btn sf-dots-btn dropdown-toggle' type='button' data-bs-toggle='dropdown' aria-expanded='false' aria-label='Post options' title='Options'>";
    echo "" . sf_icon($forum,'dots') . "";
    echo "</button>";
	echo "<ul id='{$menuId}-menu' class='dropdown-menu dropdown-menu-end sf-post-menu' aria-labelledby='{$menuId}'>";


    // Edit
    if($forum->canEditPost($r, $user)) {
      $qp = ($curPage > 1) ? ('?p=' . (int)$curPage . '&edit=') : '?edit=';
      echo "<li><a class='dropdown-item' href='{$page->url}{$qp}" . (int)$r->id . "#reply-" . (int)$r->id . "'>" . sf_icon($forum,'edit') . "<span class='ms-2'></span>Edit</a></li>";
    }

    // Delete / restore (moderators)
    if($isMod) {
      echo "<li><form method='post' class='m-0 px-2 py-1'>";
      echo $session->CSRF->renderInput('sfmod');
      echo "<input type='hidden' name='reply_id' value='" . (int)$r->id . "'>";
      if($isDeleted) {
        echo "<button type='submit' name='sf_mod_action' value='restore_reply' class='dropdown-item text-success px-0'>" . sf_icon($forum, 'undo') . "<span class='ms-2'>Restore</span></button>";
      } else {
        echo "<button type='submit' name='sf_mod_action' value='delete_reply' class='dropdown-item text-danger px-0' onclick=\"return confirm('Are you sure you want to delete this post?');\">" . sf_icon($forum,'delete') . "<span class='ms-2'></span>Delete</button>";
      }
      echo "</form></li>";
    }

    // Share
    $shareUrl = $r->httpUrl;
    $sfRText = '';
    try { $sfRText = $forum->seo()->excerpt((string) $r->getUnformatted('forum_body'), 140); } catch(\Throwable $e) {}
    echo "<li><button type='button' class='dropdown-item sf-share-btn' data-share-url='" . $sanitizer->entities($shareUrl) . "' data-share-title='" . $sanitizer->entities(sf_title_plain((string) $page->title)) . "' data-share-text='" . $sanitizer->entities($sfRText) . "'>" . sf_icon($forum,'share') . "<span class='ms-2'></span>" . $sanitizer->entities(sfT('share', 'Share')) . "</button></li>";

    // Report
    echo "<li><form method='post' class='m-0 px-2 py-1'>";
    echo $session->CSRF->renderInput('sfreport');
    echo "<button type='submit' name='sf_report_post' value='" . (int)$r->id . "' class='dropdown-item text-danger px-0 sf-report-btn'>" . sf_icon($forum,'report') . "<span class='ms-2'></span>Report</button>";
    echo "</form></li>";

    echo "</ul></div>";
  }

  echo "</div></div>";

  // "In reply to" — like other forums: a compact link when the post already quotes text,
  // otherwise a short preview of the post it answers (without that post's own quotes).
  if($r->hasField('forum_reply_to') && $r->forum_reply_to && $r->forum_reply_to->id) {
    $rt = $r->forum_reply_to;
    $rtAuthor = sf_page_author_user($rt);
    $rtName = $sanitizer->entities(sf_user_display_name($rtAuthor));
    $rtUrl = $rt->template->name === 'forum-reply' ? $forum->replyUrl($rt) : ($rt->url . '#post-' . (int) $rt->id);
    $rtLink = "<a class='sf-replyto-link' href='{$rtUrl}'>↩ In reply to <strong>{$rtName}</strong> <span class='sf-replyto-id'>#" . (int) $rt->id . "</span></a>";
    $ownBody = (string) $r->getUnformatted('forum_body');
    if(stripos($ownBody, '<blockquote') !== false) {
      echo "<div class='sf-replyto sf-replyto--compact'>{$rtLink}</div>";
    } else {
      // remove quotes from the inside out (nested quotes would otherwise leave fragments)
      $rtHtml = (string) $rt->getUnformatted('forum_body');
      for($qi = 0; $qi < 10 && stripos($rtHtml, '<blockquote') !== false; $qi++) {
        $rtHtml = preg_replace('~<blockquote\b[^>]*>(?:(?!<blockquote\b).)*?</blockquote>~is', ' ', $rtHtml);
      }
      $rtSnippet = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br', '</p>'], [' <br', ' </p>'], $rtHtml)), ENT_QUOTES, 'UTF-8')));
      if(mb_strlen($rtSnippet) > 180) $rtSnippet = rtrim(mb_substr($rtSnippet, 0, 180)) . '…';
      echo "<div class='sf-replyto'>{$rtLink}" . ($rtSnippet !== '' ? "<div class='sf-replyto-text'>" . $sanitizer->entities($rtSnippet) . "</div>" : '') . "</div>";
    }
  }

  if($isDeleted && !$isMod) {
    echo "<div class='sf-muted'>This post was deleted.</div>";
  } else {
    $body = $r->hasField('forum_body') ? (string)$r->forum_body : (string)$r->body;
    if($editId === (int)$r->id && $user->isLoggedin() && $forum->canEditPost($r, $user)) {
      $isWys = !empty($sfCfg['enableFrontendWysiwyg']);
      echo "<div class='sf-post-body'>";
      echo "<form method='post' class='sf-edit-form' onsubmit=\"return sfPreSubmitReply(this);\">";
      echo $session->CSRF->renderInput('sfedit');
      echo "<input type='hidden' name='post_id' value='" . (int)$r->id . "'>";
      echo "<input type='hidden' name='return_p' value='" . (int)$curPage . "'>";
      echo "<textarea name='body' class='form-control " . ($isWys ? "sf-wysiwyg" : "") . "' rows='8'>" . htmlspecialchars(sf_embed_html_to_tokens((string)$body), ENT_QUOTES, 'UTF-8') . "</textarea>";
      echo "<div class='mt-2 d-flex gap-2'>";
      echo "<button class='btn btn-primary btn-sm' type='submit' name='submit_edit_post' value='1'>Save changes</button>";
      $retQ = ($curPage > 1) ? ('?p=' . (int)$curPage) : '';
      echo "<a class='btn btn-outline-secondary btn-sm' href='{$page->url}{$retQ}#reply-" . (int)$r->id . "'>Cancel</a>";
      echo "</div>";
      echo "</form></div>";
    } else {
      echo "<div class='sf-post-body'>" . sf_render_post_html($forum, (string)$body, $sanitizer) . "</div>";
    }
  }
	  // Votes are rendered once per post (see vote column below).

  // Signature (under message)
  $replySignature = '';
  try {
    if($a && $a->id && $a->hasField('forum_signature')) {
      $replySignature = '';
      try {
        if($a && $a->id) {
          // Prefer forum_signature, fallback to signature (compat)
          if($a->hasField('forum_signature')) $replySignature = trim((string) $a->get('forum_signature'));
          if($replySignature === '' && $a->hasField('signature')) $replySignature = trim((string) $a->get('signature'));
        }
      } catch(\Throwable $e) { $replySignature = ''; }
    }
  } catch(\Throwable $e) {
    $replySignature = '';
  }
  // Voting (bottom-right, above signature)
if($hasVoteCol) {
  echo "<div class='sf-vote-slot d-flex justify-content-end mt-3'>" . $renderVoteWidget((int)$r->id, (int)$voteScore) . "</div>";
}

if($replySignature !== '') {
  $sigHtml = sf_render_post_html($forum, $replySignature, $sanitizer);
  echo "<div class='sf-signature mt-3'>{$sigHtml}</div>";
}

 echo "</div></div>";
  echo "</div></div>";
}

echo "</div>";

echo $renderPagination();

// Locked note
if($page->hasField('forum_is_locked') && (int)$page->forum_is_locked && !$isMod) {
  echo "<div class='alert alert-warning'><strong>Topic is locked.</strong> You can no longer reply.</div>";
}

// Reply form
echo "<div class='sf-card mt-4 sf-reply-form-wrapper' id='reply-form-wrap'>";
echo "<div class='sf-card-h'>Write a reply</div>";
echo "<div class='sf-card-b'>";

if($canPost && !($page->hasField('forum_is_locked') && (int)$page->forum_is_locked && !$isMod)) {

  // Preview label when replying-to (quote)
  echo "<div id='sf-replying-to' class='sf-muted-sm mb-2' style='display:none'></div>";

  if($replyToPreview && $replyToPreview->id) {
    $ra = sf_page_author_user($replyToPreview);
    $rn = sf_user_display_name($ra);
    echo "<p class='sf-muted-sm'><strong>Replying to:</strong> " . $sanitizer->entities($rn) . " — <a href='#reply-" . (int)$replyToPreview->id . "'>view message</a></p>";
  }

  echo "<form method='post' id='reply-form' action='{$page->url}#reply-form-wrap' onsubmit=\"return (window.sfPreSubmitReply?sfPreSubmitReply(this):true)\">";
  echo $user->isGuest() ? $forum->antiSpamFields('reply') : $session->CSRF->renderInput('sfreply');
  echo "<input type='hidden' name='reply_to' id='sf-reply-to' value='" . ($replyToPreview ? (int)$replyToPreview->id : 0) . "'>";
  $isWys = !empty($sfCfg['enableFrontendWysiwyg']);
  if(!empty($sfFormError)) echo "<div class='sf-alert sf-alert-error' role='alert'>" . $sanitizer->entities($sfFormError) . "</div>";
  $sfKeepBody = !empty($sfFormError) ? htmlspecialchars((string) $input->post->body, ENT_QUOTES, 'UTF-8') : '';
  echo "<textarea name='body' id='sf-reply-body' class='form-control " . ($isWys ? "sf-wysiwyg" : "") . "' rows='8' aria-label='Your reply'>{$sfKeepBody}</textarea>";
  echo "<input type='hidden' name='sf_upload_ctx' value='" . $sanitizer->entities($uploadCtxReply) . "'>";

  // Attachments widget (AJAX temp uploads)
  if($user->isLoggedin() && $forum && method_exists($forum,'attachmentsEnabled') && $forum->attachmentsEnabled()) {
    $allowImg = method_exists($forum,'attachmentsAllowImages') ? $forum->attachmentsAllowImages() : (int)$forum->get('attachmentsAllowImages');
    $allowFil = method_exists($forum,'attachmentsAllowFiles') ? $forum->attachmentsAllowFiles() : (int)$forum->get('attachmentsAllowFiles');
    echo "<div class='sf-upload-widget mt-3' data-upload-url='{$page->url}' data-target='sf-reply-body'>";
    echo $session->CSRF->renderInput('sfupl');
    echo "<input type='hidden' name='sf_upload_ctx' value='" . $sanitizer->entities($uploadCtxReply) . "'>";
    echo "<div class='sf-upload-drop'>Drag files here to attach, or ";
    if($allowImg) echo "<button type='button' class='btn btn-sm btn-outline-secondary sf-btn-pick' data-kind='image'>choose images</button> ";
    if($allowFil) echo "<button type='button' class='btn btn-sm btn-outline-secondary sf-btn-pick' data-kind='file'>choose files</button> ";
    echo "</div>";
    echo "<input type='file' class='sf-upload-input d-none' " . (($allowImg || $allowFil) ? "multiple" : "") . ">";
    echo "<div class='sf-upload-list mt-2'></div>";
    echo "<div class='sf-muted-sm mt-1'>Tip: after upload, click <em>Insert</em> to place it at your cursor.</div>";
    echo "</div>";
  }

  echo "</form>";

  // Follow toggle + submit (no refresh)
  $isFollowing = $user->isLoggedin() ? $forum->isFollowingTopic($user, $page) : false;

  echo "<div class='d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2'>";

  if($user->isLoggedin() && $forum->cfgOn('enableTopicFollow')) {
    echo "<form method='post' id='sf-follow-form' class='mb-0' action='{$page->url}'>";
    echo $session->CSRF->renderInput('sffollow');
    echo "<input type='hidden' name='sf_follow_toggle' value='1'>";

    echo "<div class='sf-follow sf-follow-toggle'>";
      // Custom toggle (works even when the site has no Bootstrap switches)
      echo "<label class='sf-toggle sf-follow-wrap' aria-label='Follow topic'>";
        echo "<input type='checkbox' id='sf-follow-toggle' data-url='{$page->url}' " . ($isFollowing ? "checked" : "") . ">";
        echo "<span class='sf-toggle-track'><span class='sf-toggle-thumb'></span></span>";
      echo "</label>";
      echo "<div class='lh-sm'>";
        echo "<div class='sf-follow-label'>Follow topic</div>";
        echo "<div class='sf-follow-desc'>(email me when someone replies)</div>";
      echo "</div>";
      echo "<span id='sf-follow-status' class='sf-muted-sm ms-2' style='display:none'></span>";
    echo "</div>";

    echo "</form>";
  } else {
    echo "<div class='sf-muted-sm'>Login to follow this topic.</div>";
  }

  echo "<button type='submit' form='reply-form' name='submit_reply' value='1' class='btn btn-primary'>Submit Reply</button>";
  echo "</div>";

} else {
  echo "<p class='mb-0'>"
    . "<a href='#' data-sf-auth='login'>Login</a> or "
    . "<a href='#' data-sf-auth='register'>register</a> to reply."
    . "</p>";
}

echo "</div></div>";

// Quote button + follow toggle JS (no refresh)
if(empty($GLOBALS['sfTopicJs'])) {
  $GLOBALS['sfTopicJs'] = true;


}

sf_layout_close($forum, $sidebarHtml, $pages, $sanitizer, $session, $user);