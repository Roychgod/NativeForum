<?php namespace ProcessWire;
/**
 * NativeForum Template
 * My profile page (logged-in user).
 * Provides overview / posts / activity / settings in Bootstrap 5 tabs.
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

if(!$user->isLoggedin()) {
  $lp = $pages->get("template=forum-login, include=hidden");
  $session->redirect(($lp && $lp->id ? $lp->url : $config->urls->root) . '?return=' . rawurlencode($page->url), false);
}

$target = $user;
$isSelf = true;

// Determine current tab
$tab = $sanitizer->name((string) $input->get->tab);
$validTabs = ['overview','posts','activity','settings'];
if($forum->privateMessagesEnabled()) $validTabs[] = 'messages';
$sfFollowOn = method_exists($forum, 'followedTopics') && $user->isLoggedin() && $user->hasField('forum_followed_topics'); // always on own profile
if($sfFollowOn) $validTabs[] = 'following';
if(!$tab || !in_array($tab, $validTabs, true)) $tab = 'settings';

// Unfollow from the "Followed topics" tab
if($sfFollowOn) sf_followed_handle($forum, $user, $page->url . '?tab=following');

$rank = method_exists($forum,'getUserRank') ? $forum->getUserRank($target) : (($target->hasField('forum_rank')) ? (string)$target->forum_rank : '');
$nick = ($target->hasField('forum_nickname') && $target->forum_nickname) ? $target->forum_nickname : $target->name;
$sig  = ($target->hasField('forum_signature')) ? (string)$target->forum_signature : '';
$showEmail = $target->hasField('forum_show_email') && $target->forum_show_email && $target->email;

$topicCount = $pages->count("template=forum-topic, forum_author=$target");
$replyCount = $pages->count("template=forum-reply, forum_author=$target");
$postCount  = $topicCount + $replyCount;

$avatarUrl = '';
if($target && $target->id) {
  $fieldVal = $target->get('forum_avatar');
  $img = null;
  if($fieldVal instanceof Pageimages) $img = $fieldVal->first();
  elseif($fieldVal instanceof Pageimage) $img = $fieldVal;
  if($img) $avatarUrl = $img->size(120,120)->url;
}

// Sidebar: profile card + default forum sidebar
$profileSidebar = "";
$profileSidebar .= "<div class='card mb-3 sf-sidecard'>";
$profileSidebar .= "<div class='card-header py-2 sf-sidecard-h'><strong>My profile</strong></div>";
$profileSidebar .= "<div class='card-body py-3 sf-sidecard-b'>";
$profileSidebar .= "<div class='d-flex align-items-center gap-3'>";
if($avatarUrl) {
  $profileSidebar .= "<img class='rounded-circle border' src='{$avatarUrl}' width='64' height='64' alt=''>";
} else {
  $letter = $nick ? strtoupper(substr($nick,0,1)) : '?';
  $profileSidebar .= "<div class='rounded-circle border bg-light d-flex align-items-center justify-content-center' style='width:64px;height:64px;font-size:22px'>{$letter}</div>";
}
$profileSidebar .= "<div class='flex-grow-1'>";
$profileSidebar .= "<div class='fw-semibold'>" . $sanitizer->entities($nick) . "</div>";
if($rank) $profileSidebar .= "<div class='small text-muted'>" . $sanitizer->entities($rank) . "</div>";
$profileSidebar .= "</div></div>";

$lastTopic = $pages->get("template=forum-topic, forum_author=$target, sort=-created");
$lastReply = $pages->get("template=forum-reply, forum_author=$target, sort=-created");
$lastTs = 0;
if($lastTopic && $lastTopic->id) $lastTs = max($lastTs, (int)$lastTopic->created);
if($lastReply && $lastReply->id) $lastTs = max($lastTs, (int)$lastReply->created);

$joined = date('Y-m-d', (int)$target->created);
$lastActive = $lastTs ? sf_format_dt($lastTs) : '';

$profileSidebar .= "<hr class='my-3'>";
$profileSidebar .= "<div class='small sf-profile-stats'>";
$profileSidebar .= "<div class='sf-kv'><span class='text-muted'>Joined</span><span class='fw-semibold'>{$joined}</span></div>";
$profileSidebar .= "<div class='sf-kv'><span class='text-muted'>Posts</span><span class='fw-semibold'>{$postCount}</span></div>";
$profileSidebar .= "<div class='sf-kv'><span class='text-muted'>Topics</span><span class='fw-semibold'>{$topicCount}</span></div>";
$profileSidebar .= "<div class='sf-kv'><span class='text-muted'>Replies</span><span class='fw-semibold'>{$replyCount}</span></div>";
if($lastActive) $profileSidebar .= "<div class='sf-kv'><span class='text-muted'>Last active</span><span class='fw-semibold'>{$lastActive}</span></div>";
$profileSidebar .= "</div>";

$publicUrl = sf_user_profile_url($forum, $pages, $target);
$profileSidebar .= "<hr class='my-3'>";
$profileSidebar .= "<div class='d-grid gap-2'>";
$profileSidebar .= "<a class='btn btn-outline-primary btn-sm' href='{$publicUrl}?tab=overview'>View public profile</a>";
$profileSidebar .= "<a class='btn btn-primary btn-sm' href='{$publicUrl}?tab=settings'>Settings</a>";
$profileSidebar .= "</div>";

$profileSidebar .= "</div></div>";

$sidebarHtml = $profileSidebar . sf_sidebar_html($forum, $page, $pages, $users, $sanitizer, $user);


// Breadcrumb header
$forumRoot = $forum->forumRoot() ?: $pages->get('/forum/');
$crumb = "<div class='sf-breadcrumbbar'>" .
  "<a href='{$forumRoot->url}'>Board index</a>" .
  "<span class='sf-breadcrumb-sep'>»</span>" .
  "My profile" .
  "</div>";

// Pass breadcrumbs + title into layout header slot
sf_layout_open($forum, $config, $crumb . "<h1 class='sf-h1'>My profile</h1>", $sidebarHtml);

// Tabs
$tabs = [
  'overview' => 'Overview',
  'posts'    => 'Recent posts',
  'activity' => 'Activity',
];
if($forum->privateMessagesEnabled()) {
  $tabs['messages'] = 'Private messages';
}
if($sfFollowOn) $tabs['following'] = sf_followed_label($forum);
$tabs['settings'] = 'Settings';

echo "<div class='card mb-3 sf-profile-card'>";
echo "<div class='card-header sf-profile-header'>";
echo "<ul class='nav nav-tabs card-header-tabs sf-profile-tabs'>";
foreach($tabs as $key => $label) {
  $active = ($tab === $key) ? ' active' : '';
  $href = $page->url . '?tab=' . $key;
  $lbl = strpos($label, '<span') !== false ? $label : $sanitizer->entities($label);
  echo "<li class='nav-item'><a class='nav-link{$active}' href='{$href}'>" . $lbl . "</a></li>";
}
echo "</ul>";
echo "</div>";
echo "<div class='card-body'>";

if($tab === 'overview') {
  echo "<div class='row g-3'>";
  echo "<div class='col-md-8'>";
  echo "<div class='mb-2'><span class='text-muted small'>Username</span><div class='fw-semibold'>" . $sanitizer->entities($target->name) . "</div></div>";

  // Roles (non-system)
  $roleNames = [];
  foreach($target->roles as $r) {
    if(in_array($r->name, ['guest','superuser'], true)) continue;
    $roleNames[] = $r->name;
  }
  if(count($roleNames)) {
    echo "<div class='mb-2'><span class='text-muted small'>Roles</span><div>" . $sanitizer->entities(implode(', ', $roleNames)) . "</div></div>";
  }

  if($showEmail) {
    echo "<div class='mb-2'><span class='text-muted small'>Email</span><div>" . $sanitizer->entities($target->email) . "</div></div>";
  }
  if($sig) {
    echo "<div class='mt-3'><span class='text-muted small'>Signature</span><div class='border rounded p-3 bg-light mt-1'>" . nl2br($sanitizer->entities($sig)) . "</div></div>";
  }
  echo "</div>";
  echo "<div class='col-md-4'>";
  echo "<div class='border rounded p-3 h-100'>";
  echo "<div class='small text-muted'>Totals</div>";
  echo "<div class='d-flex justify-content-between mt-2'><span>Posts</span><strong>{$postCount}</strong></div>";
  echo "<div class='d-flex justify-content-between'><span>Topics</span><strong>{$topicCount}</strong></div>";
  echo "<div class='d-flex justify-content-between'><span>Replies</span><strong>{$replyCount}</strong></div>";
  echo "</div>";
  echo "</div></div>";

} elseif($tab === 'posts') {
  $limit = 10;
  $pnum = max(1, (int) $input->get->p);
  $start = ($pnum - 1) * $limit;
  $q = trim((string) $input->get->q);
  $qSan = $sanitizer->text($q);

  $sel = "template=forum-topic|forum-reply, forum_author={$target->id}";
  $sv = $qSan ? $sanitizer->selectorValue($qSan) : '';
  if($sv) {
    $sel .= ", (title*=$sv|forum_body*=$sv|forum_summary*=$sv)";
  }

  $total = $pages->count($sel);
  $items = $pages->find($sel . ", sort=-created, limit={$limit}, start={$start}");

  echo "<form method='get' class='mb-3'>";
  echo "<input type='hidden' name='tab' value='posts'>";
  echo "<div class='input-group input-group-sm'>";
  echo "<input class='form-control' type='search' name='q' value='" . $sanitizer->entities($qSan) . "' placeholder='Search my posts…'>";
  echo "<button class='btn btn-outline-secondary' type='submit'>Search</button>";
  echo "</div>";
  echo "</form>";

  if(!count($items)) {
    echo "<div class='text-muted'>No posts found.</div>";
  } else {
    echo "<div class='list-group'>";
    foreach($items as $it) {
      if($forum && method_exists($forum,'canReadForum') && !$forum->canReadForum($user, $it)) continue;
      $isReply = $it->template && $it->template->name === 'forum-reply';
      $topic = $isReply ? ($it->parent ?: null) : $it;
      $topicTitle = $topic && $topic->id ? $topic->title : '(unknown topic)';
      $url = $topic && $topic->id ? $topic->url : $it->url;
      $snippet = $it->get('forum_body');
      $snippet = $snippet ? wire('sanitizer')->truncate($snippet, 160) : '';

      echo "<a class='list-group-item list-group-item-action' href='{$url}'>";
      echo "<div class='d-flex justify-content-between align-items-start'>";
      echo "<div>";
      echo "<div class='fw-semibold'>" . $sanitizer->entities($topicTitle) . "</div>";
      if($snippet) echo "<div class='small text-muted mt-1'>" . $sanitizer->entities($snippet) . "</div>";
      echo "</div>";
      echo "<div class='small text-muted ms-3 text-nowrap'>" . date('Y-m-d H:i', (int)$it->created) . "</div>";
      echo "</div>";
      echo "</a>";
    }
    echo "</div>";

    // Pagination
    $pagesCount = (int) ceil($total / $limit);
    if($pagesCount > 1) {
      $queryBase = "tab=posts" . ($qSan ? ("&q=" . rawurlencode($qSan)) : "");
      echo "<nav class='mt-3' aria-label='My posts pagination'><ul class='pagination pagination-sm mb-0'>";
      for($i=1; $i <= $pagesCount; $i++) {
        $active = ($i === $pnum) ? ' active' : '';
        $href = $page->url . '?' . $queryBase . '&p=' . $i;
        echo "<li class='page-item{$active}'><a class='page-link' href='{$href}'>{$i}</a></li>";
      }
      echo "</ul></nav>";
    }
  }

} elseif($tab === 'following') {
  echo sf_followed_html($forum, $user);

} elseif($tab === 'activity') {
  $recentTopics = $pages->find("template=forum-topic, forum_author=$target, sort=-created, limit=10");
  $recentReplies = $pages->find("template=forum-reply, forum_author=$target, sort=-created, limit=10");

  echo "<div class='row g-4'>";
  echo "<div class='col-md-6'>";
  echo "<h6 class='mb-2'>Latest topics</h6>";
  if(!count($recentTopics)) {
    echo "<div class='text-muted'>No topics yet.</div>";
  } else {
    echo "<ul class='list-group list-group-flush'>";
    foreach($recentTopics as $t) {
      if($forum && method_exists($forum,'canReadForum') && !$forum->canReadForum($user, $t)) continue;
      echo "<li class='list-group-item px-0'>";
      echo "<a href='{$t->url}' class='fw-semibold text-decoration-none'>" . $sanitizer->entities($t->title) . "</a>";
      echo "<div class='small text-muted'>" . date('Y-m-d H:i', (int)$t->created) . "</div>";
      echo "</li>";
    }
    echo "</ul>";
  }
  echo "</div>";

  echo "<div class='col-md-6'>";
  echo "<h6 class='mb-2'>Latest replies</h6>";
  if(!count($recentReplies)) {
    echo "<div class='text-muted'>No replies yet.</div>";
  } else {
    echo "<ul class='list-group list-group-flush'>";
    foreach($recentReplies as $r) {
      if($forum && method_exists($forum,'canReadForum') && !$forum->canReadForum($user, $r)) continue;
      $topic = $r->parent;
      $title = ($topic && $topic->id) ? $topic->title : 'Topic';
      $url = ($topic && $topic->id) ? $topic->url : $r->url;
      echo "<li class='list-group-item px-0'>";
      echo "<a href='{$url}' class='fw-semibold text-decoration-none'>" . $sanitizer->entities($title) . "</a>";
      $snippet = (string)$r->get('forum_body');
      if($snippet) {
        $snippet = wire('sanitizer')->truncate($snippet, 120);
        echo "<div class='small text-muted mt-1'>" . $sanitizer->entities($snippet) . "</div>";
      }
      echo "<div class='small text-muted'>" . date('Y-m-d H:i', (int)$r->created) . "</div>";
      echo "</li>";
    }
    echo "</ul>";
  }
  echo "</div></div>";

} elseif($tab === 'messages' && $forum->privateMessagesEnabled()) {
  $theme = function_exists(__NAMESPACE__ . '\\sf_forum_theme') ? sf_forum_theme($forum) : ((string)($forum->get('frontendTheme') ?: 'simple'));

  $box = strtolower($sanitizer->name((string)($input->get->box ?? 'all')));
  if(!in_array($box, ['all','inbox','sent'], true)) $box = 'all';

  $msgRoot = $forum->messagesRoot();
  if(!$msgRoot || !$msgRoot->id) {
    echo "<p class='text-muted'>Messages are not configured.</p>";
  } else {
    $composeError = '';
    $prefillTo = trim((string)($input->get->to ?? ''));
    $resolveToUser = function(string $toVal): ?User {
      $toVal = trim($toVal);
      if($toVal === '') return null;
      if(ctype_digit($toVal)) return sf_user_by_id((int)$toVal);
      $u = wire('users')->get("name=" . wire('sanitizer')->pageName($toVal, true) . ", include=all, check_access=0");
      return ($u && $u->id) ? $u : null;
    };

    if($input->post->pm_send) {
      try { $session->CSRF->validate('sfpm'); } catch(\Throwable $e) { $composeError = 'Security token expired. Please try again.'; }
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
          $forum->sendMessage($user, $toU, $subject, $body);
          $session->redirect($page->url . '?tab=messages&box=sent');
        }
      }
    }

    // Unread count
    $unread = 0;
    try { $unread = (int) $forum->unreadMessageCount($user); } catch(\Throwable $e) { $unread = 0; }

    // Nav
    if($theme === 'bootstrap') {
      echo "<ul class='nav nav-pills mb-3'>";
      foreach(['all' => 'All', 'inbox' => 'Inbox', 'sent' => 'Sent'] as $k => $label) {
        $active = ($box === $k) ? ' active' : '';
        $href = $page->url . '?tab=messages&box=' . $k;
        $badge = ($k !== 'sent' && $unread > 0) ? " <span class='badge text-bg-danger ms-1'>{$unread}</span>" : '';
        echo "<li class='nav-item'><a class='nav-link{$active}' href='{$href}'>" . $sanitizer->entities($label) . "{$badge}</a></li>";
      }
      echo "</ul>";
    } else {
      echo "<ul class='nav nav-pills sf-msg-nav mb-3'>";
      foreach(['all' => 'All', 'inbox' => 'Inbox', 'sent' => 'Sent'] as $k => $label) {
        $href = $page->url . '?tab=messages&box=' . $k;
        $active = ($box === $k) ? ' active' : '';
        $cur = ($box === $k) ? " aria-current='page'" : '';
        $badge = ($k !== 'sent' && $unread > 0) ? " <span class='badge text-bg-danger ms-1'>{$unread}</span>" : '';
        echo "<li class='nav-item'><a class='nav-link{$active}'{$cur} href='{$href}'>" . $sanitizer->entities($label) . "{$badge}</a></li>";
      }
      echo "</ul>";
    }

    // Compose
    if($theme === 'bootstrap') {
      echo "<div class='card mb-3 sf-profile-card'>";
      echo "<div class='card-header sf-profile-subheader'><strong>New message</strong></div>";
      echo "<div class='card-body'>";
      if($composeError) echo "<div class='alert alert-danger py-2'>" . $sanitizer->entities($composeError) . "</div>";
      echo "<form method='post' class='row g-2'>";
      echo $session->CSRF->renderInput('sfpm');
      echo "<div class='col-12 col-md-4'><label class='form-label small mb-1'>To (username or id)</label><input class='form-control form-control-sm' name='to' value='" . $sanitizer->entities($prefillTo) . "' required></div>";
      echo "<div class='col-12 col-md-8'><label class='form-label small mb-1'>Subject</label><input class='form-control form-control-sm' name='subject' required></div>";
      echo "<div class='col-12'><label class='form-label small mb-1'>" . sfT('message','Message') . "</label><textarea class='form-control' rows='4' name='body' required></textarea></div>";
      echo "<div class='col-12 d-flex justify-content-end'><button class='btn btn-primary btn-sm' type='submit' name='pm_send' value='1'>Send</button></div>";
      echo "</form></div></div>";
    } else {
      echo "<div class='sf-card sf-mb-3'><div class='sf-card-h'>New message</div><div class='sf-card-b'>";
      if($composeError) echo "<div class='sf-alert sf-alert-danger'>" . $sanitizer->entities($composeError) . "</div>";
      echo "<form method='post' class='sf-form'>";
      echo $session->CSRF->renderInput('sfpm');
      echo "<p><label class='sf-label'>To (username or id)</label><input class='sf-input' name='to' value='" . $sanitizer->entities($prefillTo) . "' required></p>";
      echo "<p><label class='sf-label'>Subject</label><input class='sf-input' name='subject' required></p>";
      echo "<p><label class='sf-label'>" . sfT('message','Message') . "</label><textarea class='sf-textarea' rows='5' name='body' required></textarea></p>";
      echo "<p><button class='sf-button-primary' type='submit' name='pm_send' value='1'>Send</button></p>";
      echo "</form></div></div>";
    }

    // List
    $inbox = $msgRoot->children("template=forum-message, forum_to_user=$user, sort=-created");
    $sent  = $msgRoot->children("template=forum-message, forum_from_user=$user, sort=-created");

    $renderTable = function($title, $items, $whoLabel, $field) use ($sanitizer, $forum, $pages, $theme) {
      if($theme === 'bootstrap') {
        echo "<div class='card mb-3 sf-profile-card'><div class='card-header sf-profile-subheader'><strong>" . $sanitizer->entities($title) . "</strong></div><div class='card-body p-0'>";
        if(!$items->count()) {
          echo "<div class='p-3 text-muted'>No messages.</div>";
        } else {
          echo "<div class='table-responsive'><table class='table table-sm mb-0 align-middle'>";
          echo "<thead class='table-light'><tr><th>Subject</th><th>{$whoLabel}</th><th>Date</th><th></th></tr></thead><tbody>";
          foreach($items as $m) {
            $raw = $m->hasField($field) ? $m->getUnformatted($field) : null;
            if($raw instanceof PageArray) $raw = $raw->first(); $uid = is_numeric($raw) ? (int)$raw : (( $raw instanceof Page) ? (int)$raw->id : 0);
            $u = $uid ? sf_user_by_id($uid) : null;
            $unameLink = sf_user_link($u, $forum, $pages, $sanitizer);
            echo "<tr><td><a href='{$m->url}'>" . $sanitizer->entities($m->title) . "</a></td><td>{$unameLink}</td><td>" . sf_format_dt((int)$m->created) . "</td><td class='text-end' style='white-space:nowrap'><a class='btn btn-outline-primary btn-sm' href='{$m->url}'>Open</a></td></tr>";
          }
          echo "</tbody></table></div>";
        }
        echo "</div></div>";
      } else {
        echo "<div class='sf-card sf-mb-3'><div class='sf-card-h'>" . $sanitizer->entities($title) . "</div><div class='sf-card-b'>";
        if(!$items->count()) {
          echo "<p class='sf-muted'>No messages.</p>";
        } else {
          echo "<div class='sf-tablewrap'><table class='sf-table'><thead><tr><th>Subject</th><th>{$whoLabel}</th><th>Date</th><th></th></tr></thead><tbody>";
          foreach($items as $m) {
            $raw = $m->hasField($field) ? $m->getUnformatted($field) : null;
            if($raw instanceof PageArray) $raw = $raw->first(); $uid = is_numeric($raw) ? (int)$raw : (( $raw instanceof Page) ? (int)$raw->id : 0);
            $u = $uid ? sf_user_by_id($uid) : null;
            $unameLink = sf_user_link($u, $forum, $pages, $sanitizer);
            echo "<tr><td><a href='{$m->url}'>" . $sanitizer->entities($m->title) . "</a></td><td>{$unameLink}</td><td>" . sf_format_dt((int)$m->created) . "</td><td style='white-space:nowrap'><a class='sf-link' href='{$m->url}'>Open</a></td></tr>";
          }
          echo "</tbody></table></div>";
        }
        echo "</div></div>";
      }
    };

    if($box === 'all' || $box === 'inbox') $renderTable('Inbox', $inbox, 'From', 'forum_from_user');
    if($box === 'all' || $box === 'sent')  $renderTable('Sent',  $sent,  'To',   'forum_to_user');
  }

} else {
  // Settings tab
  $allowAvatarUpload = (int) $forum->get('allowAvatarUpload') ? 1 : 0;

$msg = '';
  if($input->post->save_profile) {
    $user->of(false);
    if($user->hasField('forum_nickname')) $user->forum_nickname = $sanitizer->text($input->post->forum_nickname);
    if($user->hasField('forum_country'))  $user->forum_country  = $sanitizer->text($input->post->forum_country);
    $email = $sanitizer->email($input->post->email);
    if($email) $user->email = $email;
    if($user->hasField('forum_signature')) $user->forum_signature = $sanitizer->textarea($input->post->forum_signature);
    if($user->hasField('forum_show_email')) $user->forum_show_email = (int) $input->post->forum_show_email ? 1 : 0;

    // avatar upload (single)
    if($allowAvatarUpload && $user->hasField('forum_avatar') && isset($_FILES['forum_avatar']) && !empty($_FILES['forum_avatar']['name'])) {
      $tmpDir = rtrim($config->paths->cache, '/\\') . DIRECTORY_SEPARATOR . 'NativeForumAvatars' . DIRECTORY_SEPARATOR;
      if(!is_dir($tmpDir)) wireMkdir($tmpDir);

      $upload = new WireUpload('forum_avatar');
      $upload->setOverwrite(true);
      $upload->setMaxFiles(1);
      $upload->setValidExtensions(['jpg','jpeg','png','gif','webp']);
      $upload->setDestinationPath($tmpDir);
      $files = $upload->execute();

      if(count($files)) {
        $filePath = $tmpDir . $files[0];
        if(is_file($filePath)) {
          $user->forum_avatar->removeAll();
          $user->forum_avatar->add($filePath);
          @unlink($filePath);
        }
      }
    }

    $user->save();
    $user->of(true);
    $msg = 'Saved.';
  }

  $nickVal = $user->hasField('forum_nickname') ? (string)$user->forum_nickname : '';
  $countryVal = $user->hasField('forum_country') ? (string)$user->forum_country : '';
  $sigVal  = $user->hasField('forum_signature') ? (string)$user->forum_signature : '';
  $showVal = $user->hasField('forum_show_email') ? (int)$user->forum_show_email : 0;
$av = '';
$fieldVal = $user->get('forum_avatar');
$img = null;
if($fieldVal instanceof Pageimages) $img = $fieldVal->first();
elseif($fieldVal instanceof Pageimage) $img = $fieldVal;
if($img) $av = $img->size(80,80)->url;

  if($msg) echo "<div class='alert alert-success py-2'>{$msg}</div>";

  echo "<div class='row g-3 align-items-start'>";
  echo "<div class='col-md-8'>";
  echo "<form method='post' enctype='multipart/form-data'>";
  echo "<div class='mb-3'><label class='form-label'>Nickname</label><input class='form-control' type='text' name='forum_nickname' value='" . $sanitizer->entities($nickVal) . "'></div>";
  echo "<div class='mb-3'><label class='form-label'>Country</label><input class='form-control' type='text' name='forum_country' value='" . $sanitizer->entities($countryVal) . "' placeholder='e.g. Slovenia'></div>";
  echo "<div class='mb-3'><label class='form-label'>Email</label><input class='form-control' type='email' name='email' value='" . $sanitizer->entities((string)$user->email) . "'></div>";
  echo "<div class='mb-3'><label class='form-label'>Signature</label><textarea class='form-control' rows='5' name='forum_signature'>" . $sanitizer->entities($sigVal) . "</textarea></div>";
  echo "<div class='form-check mb-3'><input class='form-check-input' type='checkbox' name='forum_show_email' value='1' id='sfShowEmail' " . ($showVal ? 'checked' : '') . "><label class='form-check-label' for='sfShowEmail'>Show email on forum</label></div>";
  if($allowAvatarUpload) {
  echo "<div class='mb-3'><label class='form-label'>Avatar</label><input class='form-control form-control-sm' type='file' name='forum_avatar'></div>";
  } else {
    echo "<div class='alert alert-info py-2 mb-3'>Profile image upload is disabled.</div>";
  }
  echo "<button type='submit' name='save_profile' value='1' class='btn btn-primary'>Save</button>";
  echo "</form>";
  echo "</div>";
  echo "<div class='col-md-4'>";
  echo "<div class='border rounded p-3'>";
  echo "<div class='small text-muted mb-2'>Current avatar</div>";
  if($av) {
    echo "<img class='rounded-circle border' src='{$av}' width='80' height='80' alt=''>";
  } else {
    echo "<div class='text-muted'>No avatar uploaded.</div>";
  }
  echo "</div>";
  echo "</div></div>";
}

echo "</div></div>";

sf_layout_close($forum, $sidebarHtml);