<?php namespace ProcessWire;
/**
 * NativeForum Template (Bootstrap 5)
 * Category page: topics list + new topic form.
 */
/** @var Page $page */
/** @var User $user */
/** @var Input $input */
/** @var Sanitizer $sanitizer */
/** @var Session $session */
/** @var Config $config */

$forum = $modules->get('NativeForum');
$sfCfg = $modules->getConfig('NativeForum');
if(!$forum) throw new Wire404Exception();

require_once $config->paths->templates . 'forum-layout.php';
sf_forum_css($forum, $config);

$sfVipClass = ($forum && method_exists($forum,'isVipCategory') && $forum->isVipCategory($page)) ? ' sf-vip' : '';

// Helper: wrap any inner HTML (avatar, icon) in the same hover-card markup as sf_user_link().
if(!function_exists(__NAMESPACE__ . '\sf_user_hover_wrap')) {
  function sf_user_hover_wrap(User $u, $forum, Pages $pages, Sanitizer $sanitizer, string $innerHtml, string $aClass = ''): string {
    if(!$u || !$u->id) return $innerHtml;
    $namePlain = sf_user_display_name($u);
    $url = sf_user_profile_url($forum, $pages, $u);

    $rank = '';
    try {
      if($forum && is_object($forum) && method_exists($forum, 'getUserRank')) $rank = (string)$forum->getUserRank($u);
      elseif(method_exists($u,'hasField') && $u->hasField('forum_rank')) $rank = (string)$u->get('forum_rank');
    } catch(\Throwable $e) { $rank = ''; }
    $rank = trim($rank);

    $joined = '';
    try { if((int)$u->created) $joined = sf_format_dt((int)$u->created); } catch(\Throwable $e) { $joined = ''; }

    $titleAttr = htmlspecialchars($namePlain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $urlAttr = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Stats: compute from forum content (topics+replies) + votes/likes received. Cached.
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
    } catch(\Throwable $e) {}

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
      if($viewer && $viewer->isLoggedin() && method_exists($forum,'privateMessagesEnabled') && $forum->privateMessagesEnabled() && (int)$viewer->id !== (int)$u->id) {
        $msgRoot = method_exists($forum,'messagesRoot') ? $forum->messagesRoot() : null;
        if($msgRoot && $msgRoot->id) $pmUrl = $msgRoot->url . '?to=' . (int)$u->id;
      }
    } catch(\Throwable $e) { $pmUrl = ''; }
    $pmUrlAttr = htmlspecialchars($pmUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $rankAttr = htmlspecialchars($rank, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $joinedAttr = htmlspecialchars($joined, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lastSeenAttr = htmlspecialchars($lastSeen, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Avatar (if user has forum_avatar)
    $avatarUrl = '';
    try {
      if(method_exists($u,'hasField') && $u->hasField('forum_avatar') && $u->forum_avatar && $u->forum_avatar->count()) {
        $img = $u->forum_avatar->first();
        if($img) {
          try { $img = $img->size(64,64); } catch(\Throwable $e) {}
          $avatarUrl = $img->url;
        }
      }
    } catch(\Throwable $e) { $avatarUrl = ''; }

    $initial = '';
    try { $initial = mb_strtoupper(mb_substr(trim($namePlain), 0, 1)); } catch(\Throwable $e) { $initial = substr(trim($namePlain),0,1); }
    $avatarAttr = htmlspecialchars($avatarUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $initialAttr = htmlspecialchars($initial, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $aCls = trim('sf-userlink ' . $aClass);
    return "<span class='sf-userhover' data-sf-usercard='1' data-sf-user-url='{$urlAttr}' data-sf-usercard-title='{$titleAttr}' data-sf-user-rank='{$rankAttr}' data-sf-user-joined='{$joinedAttr}' data-sf-user-lastseen='{$lastSeenAttr}' data-sf-user-messages='{$msgCount}' data-sf-user-reactions='{$reactScore}' data-sf-user-points='{$points}' data-sf-user-ratings='{$ratings}' data-sf-user-pm-url='{$pmUrlAttr}' data-sf-user-avatar='{$avatarAttr}' data-sf-user-initial='{$initialAttr}'><a class='{$aCls}' href='{$urlAttr}'>{$innerHtml}</a></span>";
  }
}

if(!$forum->canReadForum($user, $page)) throw new Wire404Exception();
if(method_exists($forum, 'canReadCategory') && !$forum->canReadCategory($user, $page)) throw new Wire404Exception();

$isMod = (method_exists($forum,'isModerator') && $forum->isModerator($user));

$forumRoot = $forum->forumRoot() ?: $pages->get('/forum/');
$group = $page->parent && $page->parent->template->name === 'forum-group' ? $page->parent : null;

$canPost = method_exists($forum, 'canPostToCategory') ? (bool)$forum->canPostToCategory($page, $user) : $user->isLoggedin();

// Unread/NEW indicators (true per-topic read tracking)
$sfNow = time();
$sfNewWindow = 60 * 60 * 24 * 2; // 48h = "NEW"
$sfNextUnread = null;

// Per-user read map: { topicId: lastActivityTimestampRead }
$sfReadMap = [];
$sfBaseline = 0;
if($user->isLoggedin()) {
  // Baseline for topics never opened by this user
  $sfBaseline = (int)($user->lastLogin ?: $user->created ?: $sfNow);

  $raw = '';
  try {
    if(method_exists($user, 'hasField') && $user->hasField('forum_read_topics')) {
      $raw = (string)$user->get('forum_read_topics');
    }
  } catch(\Throwable $e) { $raw = ''; }

  $tmp = json_decode($raw ?: '{}', true);
  if(is_array($tmp)) $sfReadMap = $tmp;
}

// AJAX: mark all topics in this category as read
if($input->post->sf_markallread && $user->isLoggedin()) {
  $isAjax = (bool) $config->ajax;
  try { $session->CSRF->validate('sfmarkread'); } catch(\Throwable $e) {
    if($isAjax) {
      if(class_exists('\\Tracy\\Debugger')) { \Tracy\Debugger::$showBar = false; }
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'csrf']);
      exit;
    }
  }

  $map = [];
  try {
    $rawMap = (method_exists($user,'hasField') && $user->hasField('forum_read_topics')) ? (string)$user->get('forum_read_topics') : '';
    $map = json_decode($rawMap ?: '{}', true);
    if(!is_array($map)) $map = [];
  } catch(\Throwable $e) { $map = []; }

  $topicsToMark = $pages->find("template=forum-topic, parent={$page->id}, include=all, limit=500");
  $marked = 0;
  $sfMarkStats = $forum->topicStats($topicsToMark->explode('id'), true);
  foreach($topicsToMark as $t2) {
    $lastActivity = max((int)$t2->created, (int)($sfMarkStats[(int)$t2->id]['last_created'] ?? 0));
    $cur = (int)($map[(string)$t2->id] ?? $map[(int)$t2->id] ?? 0);
    if($lastActivity > $cur) {
      $map[(string)$t2->id] = $lastActivity;
      $marked++;
    }
  }
  if(method_exists($user,'hasField') && $user->hasField('forum_read_topics')) {
    try { $forum->saveReadMap($user, $map); } catch(\Throwable $e) {}
  }

  if($isAjax) {
    if(class_exists('\\Tracy\\Debugger')) { \Tracy\Debugger::$showBar = false; }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'marked' => $marked]);
    exit;
  }
}

// Upload context for new topic attachments (AJAX temp uploads)
$uploadCtxTopic = '';
if($user->isLoggedin() && $forum && method_exists($forum, 'attachmentsEnabled') && $forum->attachmentsEnabled()) {
  $k = 'sf_uplctx_topic_' . (int)$page->id . '_' . (int)$user->id;
  $uploadCtxTopic = (string) $session->get($k);
  if(!$uploadCtxTopic) {
    try { $uploadCtxTopic = 't' . time() . '-' . bin2hex(random_bytes(8)); }
    catch(\Throwable $e) { $uploadCtxTopic = 't' . time() . '-' . mt_rand(1000,9999); }
    $session->set($k, $uploadCtxTopic);
  }
}

$error = '';
if($input->post->submit_topic) {
 try {
  if($user->isGuest()) {
    if(($spamErr = $forum->antiSpamCheck('topic', 5)) !== '') throw new WireException($spamErr);
  } elseif(!$session->CSRF->hasValidToken('sftopic')) {
    throw new WireException('Your session expired. Please copy your text, reload the page and try again.');
  }
  if(!$canPost) throw new WireException('Posting not allowed.');
  $title = $sanitizer->text($input->post->title);
  $summary = $sanitizer->text($input->post->summary);
  $tagsRaw = (string) $input->post->tags;
  $tagsArr = (method_exists($forum, 'parseTagsString')) ? $forum->parseTagsString($tagsRaw) : (function_exists('sf_parse_tags_string') ? sf_parse_tags_string($tagsRaw) : []);
  $body  = (string) $input->post->body; // preserve HTML
  if(!$title || !$body) {
    $error = 'Please enter a title and message.';
  } else {
    $ctx = (string) $input->post->sf_upload_ctx;
    $topic = $forum->createTopic($page, $user, $title, $body, $summary, $ctx, $tagsRaw);
if($topic && $topic->id && !empty($tagsArr) && $topic->hasField('forum_tags')) {
  $topic->of(false);
  $topic->set('forum_tags', implode(' ', $tagsArr));
  $topic->save('forum_tags');
  $topic->of(true);
}
    if($topic && $topic->id) $session->redirect($topic->url);
    $error = 'Could not create topic.';
  }
 } catch(WireException $e) {
  $error = $e->getMessage();
 }
}

$q = trim((string) $input->get->q);
$sfScope = (string) $input->get->sf_scope;
if(!$sfScope) $sfScope = 'this';

$perPage = max(5, min(100, (int) ($forum->get('topicsPerPage') ?: 20)));
$totalSel = "template=forum-topic, parent={$page->id}" . ($isMod ? ", include=all" : ", status<hidden");
if($q !== '') {
  // Use '*=' for broad compatibility (body fields often don't have fulltext indexes).
  $totalSel .= ", (title|forum_body|forum_summary*=" . $sanitizer->selectorValue($q) . ")";
}
$totalTopics = $pages->count($totalSel);

$totalPages = max(1, (int)ceil($totalTopics / $perPage));
$curPage = max(1, (int)$input->get->p);
if($curPage > $totalPages) $curPage = $totalPages;

$start = ($curPage - 1) * $perPage;

$sel = $totalSel . ", sort=-created, start={$start}, limit={$perPage}";
$topics = $pages->find($sel);

$sidebarHtml = sf_sidebar_html($forum, $page, $pages, $users, $sanitizer, $user);

$crumb = "<div class='sf-breadcrumbbar'>"
  . "<a href='{$forumRoot->url}'>Board index</a>"
  . ($group ? "<span class='sf-breadcrumb-sep'>»</span><a href='{$group->url}'>" . $sanitizer->entities(sf_title_plain($group->title)) . "</a>" : "")
  . "<span class='sf-breadcrumb-sep'>»</span>"
  . $sanitizer->entities(sf_title_plain($page->title))
  . "</div>";

sf_layout_open($forum, $config, $crumb, $sidebarHtml);

// Page header (title + summary) so you always know where you are
$catSummary = '';
$candidates = array_unique(array_filter(['summary','description','forum_summary','intro','body']));
foreach($candidates as $f){ if($catSummary) break; if($page->hasField($f)){ $v=trim(strip_tags((string)$page->get($f))); if($v) $catSummary=$v; } }
$sumField = (string) ($forum && method_exists($forum,'get') ? $forum->get('field_forum_summary_category', 'summary') : 'summary');
$candidates = array_unique(array_filter([$sumField,'summary','description','forum_summary','intro','body']));
foreach($candidates as $f) {
  if($catSummary) break;
  if($page->hasField($f)) {
    $val = trim(strip_tags((string)$page->get($f)));
    if($val) $catSummary = $val;
  }
}
if($catSummary && strlen($catSummary) > 240) $catSummary = rtrim(substr($catSummary, 0, 240)) . '…';
echo "<div class='sf-pagehead card mb-3{$sfVipClass}'><div class='card-body" . ($sfVipClass ? " sf-vip-header" : "") . "'>";
$markReadHtml='';
if($user->isLoggedin()) {
  $markReadHtml .= "<form method='post' class='sf-markread-form m-0'>";
  $markReadHtml .= $session->CSRF->renderInput('sfmarkread');
  $markReadHtml .= "<input type='hidden' name='sf_markallread' value='1'>";
  $markReadHtml .= "<button type='button' class='sf-btn sf-btn-secondary sf-btn-sm sf-btn-markallread' data-sf-markallread='1'>Mark read</button>";
  $markReadHtml .= "</form>";
}
echo "<div class='sf-pagehead-top'>";
echo "<div class='sf-pagehead-title'>" . $sanitizer->entities(sf_title_plain($page->title)) . "</div>";
if($markReadHtml) echo "<div class='sf-pagehead-actions'>" . $markReadHtml . "</div>";
echo "</div>";
if($catSummary) echo "<div class='sf-pagehead-summary'>" . $sanitizer->entities($catSummary) . "</div>";
echo "</div></div>";

// Toolbar (New Topic + Search + page info)
echo "<div class='sf-toolbar sf-toolbar--category'>";
echo "<div class='sf-toolbar-left'>";
if($canPost) {
  echo "<a class='btn btn-primary btn-sm' href='#new-topic-form'>" . sfT('new_topic','New topic') . "</a>";
} else {
  echo "<button class='btn btn-primary btn-sm' type='button' data-sf-auth='login'>" . sfT('new_topic','New topic') . "</button>";
}
echo "</div>";

echo "<div class='sf-toolbar-right'>";
// Quick search (PW-forum-like)
$scopes = [
  'this'       => 'This Forum',
  'everywhere' => 'Everywhere',
];
$curScope = $input->get('sf_scope');
if(!isset($scopes[$curScope])) $curScope = 'this';
echo sf_csearch_form_html($forum, $forumRoot->url, $q, (int)$page->id, $curScope, $scopes, 'sf-csearch-header', 'Search Forums…', 'sm');


echo "<div class='sf-toolbar-pageinfo sf-muted-sm'>{$totalTopics} topics • Page {$curPage} of {$totalPages}</div>";
echo "</div>";
echo "</div>";

// Topics panel
$isVipCat = ($forum && method_exists($forum,'isVipCategory')) ? (bool)$forum->isVipCategory($page) : false;
echo "<div class='card sf-panel mb-4" . ($isVipCat ? " sf-panel-vip" : "") . "'>";
// Header: keep the whole width for the category title (no column labels)
echo "<div class='card-header'>";
echo "<div class='d-flex align-items-center justify-content-between w-100'>";
echo "<div class='fw-semibold'>" . $sanitizer->entities(sf_title_plain($page->title)) . "</div>";
echo "</div>";
echo "</div>";
echo "<div class='list-group list-group-flush sf-topic-list'>";

if(!$topics->count()) {
  echo "<div class='list-group-item'><span class='sf-muted'>No topics.</span></div>";
} else {
  // Batch: reply counts + newest replies (2 queries) and all authors (1 query) for this page of topics
  $sfTopicStats = $forum->topicStats($topics->explode('id'));
  $sfLastIds = array_filter(array_column($sfTopicStats, 'last_id'));
  $sfLastReplies = $sfLastIds ? $pages->getById($sfLastIds) : new PageArray();
  $forum->preloadUsers(array_values($forum->authorIds(array_merge($topics->explode('id'), $sfLastIds))));

  foreach($topics as $t) {

    // Author (resolve even when user pages aren't viewable)
    $a = sf_page_author_user($t);
    $authorLink = sf_user_link($a, $forum, $pages, $sanitizer);

    $replies = (int) ($sfTopicStats[(int) $t->id]['replies'] ?? 0);
    $views = $t->hasField('forum_views') ? (int)$t->get('forum_views') : null;

    // Last activity = latest reply created (do NOT use modified; view counter updates modified)
    $sfLid = (int) ($sfTopicStats[(int) $t->id]['last_id'] ?? 0);
    $lastReply = $sfLid ? $sfLastReplies->get("id=$sfLid") : null;
    $lastActivity = ($lastReply && $lastReply->id) ? (int)$lastReply->created : (int)$t->created;

    $lastRead = 0;
    if($user->isLoggedin()) {
      $lastRead = (int)($sfReadMap[(string)$t->id] ?? $sfReadMap[(int)$t->id] ?? 0);
      if(!$lastRead) $lastRead = $sfBaseline;
    }
    $isUnread = ($user->isLoggedin() && $lastActivity > $lastRead);
    if($isUnread && $sfNextUnread === null) $sfNextUnread = $t;

    // NEW badge if topic was created recently
    $isNew = ((int)$t->created >= ($sfNow - $sfNewWindow));

    $badgeHtml = '';
    $isPinned = false;
    try {
      $isPinned = ($forum && method_exists($forum,'isPinnedTopic')) ? (bool)$forum->isPinnedTopic($t) : ($t->hasField('forum_is_pinned') && (int)$t->forum_is_pinned);
      if($isPinned) $badgeHtml .= " <span class='badge text-bg-warning sf-pinned-badge'>Pinned</span>";
      if($t->hasField('forum_is_locked') && (int)$t->forum_is_locked) $badgeHtml .= " <span class='badge text-bg-secondary'>Locked</span>";
      if($forum && method_exists($forum,'isVipCategory') && $forum->isVipCategory($page)) $badgeHtml .= " <span class='badge text-bg-danger sf-vip-badge'>VIP</span>";
    } catch(\Throwable $e) {}

    $rowCls = 'list-group-item sf-topic-rowitem' . ($isUnread ? ' sf-unread' : '') . ($isPinned ? ' sf-pinned' : '');
    echo "<div class='{$rowCls}'>";
    echo "<div class='row align-items-center gy-2'>";
    // Title should be the widest column
    echo "<div class='col-12 col-md-8 sf-topic-main'>";
    echo "<div class='d-flex gap-2 align-items-center'>";
    echo "<div class='sf-forum-icon'>" . sf_icon($forum, 'comments') . "</div>";
    echo "<div>";


    // NEW badge
    if($isNew) $badgeHtml .= " <span class='badge sf-badge-new'>NEW</span>";

    // Unread dot + bold title
    $titleClass = 'sf-topic-title text-decoration-none' . ($isUnread ? ' fw-bold' : '');
    $unreadDot = $isUnread ? "<span class='sf-unread-dot' aria-hidden='true'></span>" : "";
    $tTitle = $sanitizer->entities($t->title);

// Tags (topic)
$tagsHtml = '';
$tagsStr = trim((string) $t->get('forum_tags'));
if($tagsStr !== '') {
  $tagParts = preg_split('/\s+/', $tagsStr, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  if($tagParts) {
    $tagLinks = [];
    foreach($tagParts as $tg) {
      $tg = trim(ltrim(trim((string)$tg), '#'), ',;');
      if($tg === '') continue;
      $tgEsc = $sanitizer->entities($tg);
      $tgLabel = $sanitizer->entities(str_replace('-', ' ', $tg));
      $href = $forumRoot->url . '?q=' . rawurlencode('#' . $tg) . '&sf_scope=topics&sf_ctx=' . (int)$page->id;
      $tagLinks[] = "<a class='sf-tag' href='{$href}'>#{$tgLabel}</a>";
      if(count($tagLinks) >= 8) break;
    }
    if($tagLinks) $tagsHtml = "<div class='sf-tags'>" . implode('', $tagLinks) . "</div>";
  }
}


    // Tiny topic pagination next to title (1 2 3 … last)
    $postsPerPage = (int) ($sfCfg['postsPerPage'] ?? 11);
    if($postsPerPage < 3) $postsPerPage = 3;
    if($postsPerPage > 200) $postsPerPage = 200;
    $totalPosts = 1 + (int)$replies;
    $totalPages = (int) ceil($totalPosts / $postsPerPage);
    $miniPager = '';
    if($totalPages > 1) {
      $maxShow = 3;
      $miniPager .= "<span class='sf-mini-pager ms-2'>";
      $makeLink = function(int $p) use ($t) {
        $u = $t->url;
        if($p > 1) $u .= '?p=' . $p;
        return "<a class='sf-mini-page' href='{$u}'>" . $p . "</a>";
      };
      $last = $totalPages;
      $show = min($maxShow, $last);
      for($p=1; $p<=$show; $p++) $miniPager .= $makeLink($p);
      if($last > $show) {
        $miniPager .= "<span class='sf-mini-ellipsis'>…</span>";
        $miniPager .= $makeLink($last);
      }
      $miniPager .= "</span>";
    }

    echo "<div class='d-flex flex-wrap align-items-center gap-2'>"
      . "<a class='{$titleClass}' href='{$t->url}' data-bs-toggle='tooltip' data-bs-placement='top' title='{$tTitle}'>" . $unreadDot . $tTitle . "</a>"
      . $miniPager
      . $badgeHtml
      . $tagsHtml
      . "</div>";

    $tsum = sf_show_summaries() ? sf_page_summary($t, 180) : '';
    if($tsum !== '') echo "<div class='sf-topic-summary'>" . $sanitizer->entities($tsum) . "</div>";

    echo "</div></div></div>";

    // Replies + Views in one column, stacked (desktop)
    echo "<div class='col-6 col-md-2 text-end text-nowrap sf-topic-stats'>";
    $rLab = ((int)$replies === 1) ? "reply" : "replies";
    echo "<div class='small fw-semibold'>" . (int)$replies . " <span class='sf-muted-sm'>{$rLab}</span></div>";
    if($views !== null) {
      echo "<div class='small fw-semibold mt-1'>" . (int)$views . " <span class='sf-muted-sm'>views</span></div>";
    } else {
      echo "<div class='small fw-semibold mt-1'>— <span class='sf-muted-sm'>views</span></div>";
    }
    echo "</div>";

    // Author + created date (compact; avatar aligned on a fixed vertical line)
    echo "<div class='col-6 col-md-2 text-nowrap sf-topic-last'>";
    $av = ($a && $a->id) ? sf_user_avatar($a) : null;

    // Avatar HTML (wrapped in user hover-card so it works on the image too)
    $avHtml = '';
    if($av && $a && $a->id) {
      try {
        $avUrl = $av->size(32, 32)->url;
        $avAlt = $sanitizer->entities($a->name);
        $img = "<img src='{$avUrl}' class='rounded-circle flex-shrink-0 sf-author-avatar' width='32' height='32' alt='{$avAlt}' loading='lazy'>";
        $avHtml = sf_user_hover_wrap($a, $forum, $pages, $sanitizer, $img, 'sf-author-avatar-link');
      } catch(\Throwable $e) { $avHtml = ''; }
    }

    // ✅ FIX: last activity tooltip uses $lastActivity (NOT modified)
    $lastAct = (int) $lastActivity;
    $hasActivity = ($lastAct > (int)$t->created + 60);
    $lastActTip = $hasActivity ? "Last activity: " . sf_format_dt($lastAct) : "";

    // Left-align the whole avatar+meta block so all avatars start at the same X position.
    // If no avatar, output a placeholder to preserve alignment.
    echo "<div class='sf-topic-author d-flex align-items-center justify-content-start gap-2'>";
    if($avHtml) echo $avHtml;
    else echo "<span class='sf-author-avatar sf-author-avatar--empty' aria-hidden='true'></span>";
    echo "<div class='sf-author-meta text-start lh-sm'>";
    echo "<div class='small fw-semibold sf-author-name'>{$authorLink}</div>";
    echo "<div class='sf-muted-sm sf-author-date'>" . sf_format_dt((int)$t->created)
      . ($hasActivity ? " <span class='sf-lastact-dot' data-sf-tip='" . $sanitizer->entities($lastActTip) . "'>•</span>" : "")
      . "</div>";
    echo "</div></div>";
    echo "</div>";

    echo "</div></div>"; // row + list-group-item
  }
}

// Next unread topic link (like PW forum)
if($user->isLoggedin() && $sfNextUnread && $sfNextUnread->id) {
  $nuTitle = $sanitizer->entities($sfNextUnread->title);
  echo "<div class='list-group-item py-2 sf-next-unread'>";
  echo "<a class='sf-next-unread-link' href='" . $sfNextUnread->url . "'>";
  echo "<span class='sf-unread-dot me-1' aria-hidden='true'></span>";
  echo "<span class='sf-muted-sm'>Next unread topic:</span> {$nuTitle}";
  echo "</a>";
  echo "</div>";
}

echo "</div></div>";

// New topic form
echo "<div class='sf-card mt-4' id='new-topic-form'>";
echo "<div class='sf-card-h'>New Topic</div>";
echo "<div class='sf-card-b'>";

if($error) echo "<div class='sf-alert sf-alert-error'>" . $sanitizer->entities($error) . "</div>";

if($canPost) {
  echo "<form method='post' id='topic-form'>";
  echo $user->isGuest() ? $forum->antiSpamFields('topic') : $session->CSRF->renderInput('sftopic');
  echo "<div class='mb-3'><label class='form-label'>" . sfT('title','Title') . "</label>";
  echo "<input class='form-control' type='text' name='title' required></div>";
  echo "<div class='mb-3'><label class='form-label'>" . sfT('summary_optional','Summary (optional)') . "</label>";
  echo "<input class='form-control' type='text' name='summary' maxlength='255' placeholder='Short summary shown under the title…'></div>";
echo "<div class='mb-3'><label class='form-label'>" . sfT('tags','Tags') . "</label>";
echo "<input class='form-control' type='text' name='tags' maxlength='255' placeholder='" . $sanitizer->entities(sfT('tags_placeholder','#processwire #module #help')) . "'>";
echo "<div class='form-text'>" . sfT('tags_help','Add tags separated by spaces or commas. Use #tag if you like. Example: #processwire #module') . "</div></div>";

  echo "<div class='mb-3'><label class='form-label'>" . sfT('message','Message') . "</label>";
  $isWys = !empty($sfCfg['enableFrontendWysiwyg']);
  echo "<textarea name='body' id='sf-topic-body' class='form-control " . ($isWys ? "sf-wysiwyg" : "") . "' rows='10' required></textarea></div>";
  echo "<input type='hidden' name='sf_upload_ctx' value='" . $sanitizer->entities($uploadCtxTopic) . "'>";

  if($user->isLoggedin() && $forum && method_exists($forum,'attachmentsEnabled') && $forum->attachmentsEnabled()) {
    $allowImg = method_exists($forum,'attachmentsAllowImages') ? $forum->attachmentsAllowImages() : (int)$forum->get('attachmentsAllowImages');
    $allowFil = method_exists($forum,'attachmentsAllowFiles') ? $forum->attachmentsAllowFiles() : (int)$forum->get('attachmentsAllowFiles');
    echo "<div class='sf-upload-widget mt-3' data-upload-url='{$page->url}' data-target='sf-topic-body'>";
    echo $session->CSRF->renderInput('sfupl');
    echo "<input type='hidden' name='sf_upload_ctx' value='" . $sanitizer->entities($uploadCtxTopic) . "'>";
    echo "<div class='sf-upload-drop'>Drag files here to attach, or ";
    if($allowImg) echo "<button type='button' class='btn btn-sm btn-outline-secondary sf-btn-pick' data-kind='image'>choose images</button> ";
    if($allowFil) echo "<button type='button' class='btn btn-sm btn-outline-secondary sf-btn-pick' data-kind='file'>choose files</button> ";
    echo "</div>";
    echo "<input type='file' class='sf-upload-input d-none' multiple>";
    echo "<div class='sf-upload-list mt-2'></div>";
    echo "<div class='sf-muted-sm mt-1'>Tip: upload first, then click <em>Insert</em> to place it in your message.</div>";
    echo "</div>";
  }

  echo "<button class='btn btn-primary' type='submit' name='submit_topic' value='1'>Post topic</button>";
  echo "</form>";
} else {
  echo "<p class='mb-0'>You cannot post new topics in this forum. "
    . "<a href='#' data-sf-auth='login'>Login</a> or "
    . "<a href='#' data-sf-auth='register'>register</a>.</p>";
}

echo "</div></div>";

// Forum permissions (simple, like screenshot)
echo "<div class='sf-card mt-4'>";
echo "<div class='sf-card-h'>Forum permissions</div>";
echo "<div class='sf-card-b sf-muted-sm'>";
echo "<div>You " . ($canPost ? "can" : "cannot") . " post new topics in this forum.</div>";
echo "<div>You " . ($user->isLoggedin() ? "can" : "cannot") . " reply to topics in this forum.</div>";
echo "</div></div>";

// ✅ FIX: removed old category "last seen" system ($sfSeenKey) entirely (true per-topic tracking now)
sf_layout_close($forum, $sidebarHtml, $pages, $sanitizer, $session, $user);
