<?php namespace ProcessWire;
/**
 * NativeForum Template (Bootstrap 5)
 * Forum index (groups + categories) with sidebar.
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

if(!function_exists(__NAMESPACE__ . '\\sf_human_count')) {
  function sf_human_count(int $n): string {
    if($n < 1000) return (string)$n;
    $units = [1000000000 => 'B', 1000000 => 'M', 1000 => 'K'];
    foreach($units as $div => $suf) {
      if($n >= $div) {
        $v = $n / $div;
        $dec = ($v >= 100) ? 0 : 1; // 532K vs 12.3K
        $txt = number_format($v, $dec, '.', '');
        $txt = rtrim(rtrim($txt, '0'), '.');
        return $txt . $suf;
      }
    }
    return (string)$n;
  }
}

if(!$forum->canReadForum($user, $page)) throw new Wire404Exception();

$isMod = (method_exists($forum,'isModerator') && $forum->isModerator($user));

// Local cache for expensive index computations (counts + last-post preview).
// Short TTL keeps things fresh and plays well with ProCache.
$cache = wire('cache');


$forumStyle = (string) ($forum->get('forumStyle') ?: 'classic');

$forumRoot = $page->template->name === 'forum-root' ? $page : $forum->forumRoot();
if(!$forumRoot || !$forumRoot->id) $forumRoot = $page;

$q = trim((string) $input->get->q);
$sfScope = (string) $input->get->sf_scope;
if(!$sfScope) $sfScope = 'this';
$ctxId = (int) $input->get->sf_ctx;

// Resolve context page for "This ..." searches
$ctxPage = $forumRoot;
if($ctxId) {
  $p = $pages->get((int)$ctxId);
  if($p && $p->id) {
    // Only allow context inside the forum tree
    // ProcessWire compatibility: Page::hasParent() is not available in some versions.
    // Use parents() check instead.
    if($p->id === $forumRoot->id || $p->parents->has($forumRoot)) $ctxPage = $p;
  }
}

if($q !== '') {
  $results = sf_forum_search($forum, $forumRoot, $ctxPage, $q, $sfScope, $pages, $users, $sanitizer, $user, 60);
}

$sidebarContext = ($q !== '' && $sfScope === 'this' && $ctxPage && $ctxPage->id) ? $ctxPage : $forumRoot;
$sidebarHtml = sf_sidebar_html($forum, $sidebarContext, $pages, $users, $sanitizer, $user);

$titleHtml = ($forumStyle === 'compact')
  ? "<div class='sf-breadcrumbbar'>Forums</div>"
  : "<div class='sf-breadcrumbbar'><span class='me-2'>🏠</span>Board index</div>";
sf_layout_open($forum, $config, $titleHtml, $sidebarHtml);

  // Index toolbar (view toggles + New Topic)
  $theme = function_exists(__NAMESPACE__ . '\\sf_forum_theme') ? sf_forum_theme($forum) : ((string)($forum->get('frontendTheme') ?: 'simple'));
  $btnPrimary = ($theme === 'bootstrap') ? 'btn btn-primary btn-sm' : 'sf-button-primary';
  $btnGhost   = ($theme === 'bootstrap') ? 'btn btn-outline-primary btn-sm' : 'sf-button-secondary';
  echo "<div class='sf-index-toolbar'>";
    if($user->isLoggedin()) {
      echo "<button type='button' id='sfNewTopicIndexBtn' class='" . $btnPrimary . "'>" . $sanitizer->entities(sfT('start_new_topic', 'Start new topic')) . "</button>";
    } else {
      echo "<a class='" . $btnPrimary . "' href='" . $sanitizer->entities(($pages->get('template=forum-login, include=all')->url ?: '/forum-login/')) . "' data-sf-auth='login'>" . $sanitizer->entities(sfT('start_new_topic', 'Start new topic')) . "</a>";
    }
  echo "</div>";
  // Categories the member may post in, grouped (used by the "Start new topic" chooser)
  if($user->isLoggedin()) {
    $sfNtGroups = [];
    $sfNtSum = function($c) { foreach(['field_forum_summary_category', 'summary', 'description'] as $f) { if($c->hasField($f) && trim(strip_tags((string) $c->get($f))) !== '') return mb_strimwidth(trim(strip_tags((string) $c->get($f))), 0, 110, '…'); } return ''; };
    foreach($forumRoot->children("template=forum-group|forum-category, sort=sort") as $child) {
      $list = $child->template->name === 'forum-group' ? $child->children("template=forum-category, sort=sort") : [$child];
      $cats = [];
      foreach($list as $c) {
        try { if(!$forum->canReadCategory($user, $c) || !$forum->canWriteCategory($user, $c)) continue; } catch(\Throwable $e) { continue; }
        $cats[] = ['t' => sf_title_plain((string) $c->title), 'u' => $c->url . '#new-topic-form', 'd' => $sfNtSum($c)];
      }
      if($cats) $sfNtGroups[] = ['g' => $child->template->name === 'forum-group' ? sf_title_plain((string) $child->title) : '', 'cats' => $cats];
    }
    echo "<script type='application/json' id='sfNewTopicCats'>" . json_encode($sfNtGroups, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "</script>";
  }

if(isset($results)) {
  // Local snippet helper
  if(!function_exists(__NAMESPACE__ . '\\sf_search_snip')) {
    function sf_search_snip(string $html, int $max = 170): string {
      $t = trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
      if(mb_strlen($t) <= $max) return $t;
      return rtrim(mb_substr($t, 0, $max - 1)) . '…';
    }
  }

  $scopeLabel = [
    'everywhere' => 'Everywhere',
    'this' => ($ctxPage->template && $ctxPage->template->name==='forum-category' ? 'This Category' : ($ctxPage->template && $ctxPage->template->name==='forum-group' ? 'This Group' : 'This Forum')),
    'topics' => 'Topics',
    'posts' => 'Posts',
    'categories' => 'Categories',
    'members' => 'Members',
  ][$results['scope']] ?? 'Search';

  echo "<div class='sf-card'><div class='sf-card-h'>Search results</div><div class='sf-card-b'>";
  echo "<div class='sf-muted mb-3'>for <strong>" . $sanitizer->entities($q) . "</strong> in <strong>" . $sanitizer->entities($scopeLabel) . "</strong></div>";

  $foundAny = ($results['topics']->count() || $results['replies']->count() || $results['categories']->count() || count($results['members']));
  if(!$foundAny) {
    echo "<p class='sf-muted'>No results.</p>";
    echo "</div></div>";

  // New topic modal (index)
  $catsForModal = [];
  foreach($forumRoot->children("template=forum-group|forum-category, sort=sort") as $child) {
    if($child->template->name === 'forum-group') {
      foreach($child->children("template=forum-category, sort=sort") as $c) $catsForModal[] = ['label' => $child->title . ' / ' . $c->title, 'url' => $c->url];
    } else {
      $catsForModal[] = ['label' => $child->title, 'url' => $child->url];
    }
  }
  echo "<div class='sf-modal' id='sfNewTopicModal' aria-hidden='true'>";
  echo "<div class='sf-modal-backdrop' data-sf-close='1'></div>";
  echo "<div class='sf-modal-dialog' role='dialog' aria-modal='true'>";
  echo "<div class='sf-modal-header'><div class='sf-modal-title'>Select Forum</div><button type='button' class='sf-modal-close' data-sf-close='1' aria-label='Close'>&times;</button></div>";
  echo "<div class='sf-modal-body'>";
  echo "<label class='sf-label'>Forum</label>";
  echo "<select id='sfNewTopicForumSelect' class='sf-select'>";
  foreach($catsForModal as $it) {
    $lbl = $sanitizer->entities($it['label']);
    $u = $sanitizer->entities($it['url']);
    echo "<option value='{$u}'>{$lbl}</option>";
  }
  echo "</select>";
  echo "</div>";
  echo "<div class='sf-modal-footer'>";
  echo "<button type='button' class='sf-btn sf-btn-secondary' data-sf-close='1'>Cancel</button>";
  echo "<button type='button' id='sfNewTopicGo' class='sf-btn sf-btn-primary'>Continue</button>";
  echo "</div>";
  echo "</div></div>";

    sf_layout_close($forum, $sidebarHtml, $pages, $sanitizer, $session, $user);
    return;
  }

  // Categories
  if($results['categories']->count()) {
    echo "<h6 class='mb-2'>Categories &amp; groups</h6><ul class='sf-latest mb-3'>";
    foreach($results['categories'] as $c) {
      $ttl = sf_entities_once($c->title);
      $meta = ($c->template && $c->template->name==='forum-category' && $c->parent && $c->parent->template && $c->parent->template->name==='forum-group')
        ? " <span class='sf-muted'>(" . sf_entities_once($c->parent->title) . ")</span>" : '';
      echo "<li><a class='sf-link-title sf-index-title' href='{$c->url}' data-bs-toggle='tooltip' data-bs-placement='top' title='{$ttl}'>{$ttl}</a>{$meta}</li>";
    }
    echo "</ul>";
  }

  // Topics
  if($results['topics']->count()) {
    echo "<h6 class='mb-2'>Topics</h6><ul class='sf-latest mb-3'>";
    foreach($results['topics'] as $t) {
      $sn = '';
      try { $sn = sf_search_snip((string)$t->get('forum_body')); } catch(\Throwable $e) { $sn = ''; }
      $place = ($t->parent && $t->parent->id) ? " • <span class='sf-muted'>" . sf_entities_once($t->parent->title) . "</span>" : '';
      $when = sf_format_dt((int)$t->created);
      $tTitle = sf_entities_once($t->title);
      echo "<li><a class='sf-link-title sf-index-title' href='{$t->url}' data-bs-toggle='tooltip' data-bs-placement='top' title='{$tTitle}'>{$tTitle}</a>{$place} <span class='sf-muted'>{$when}</span>";
      if($sn) {
        $snH = $sanitizer->entities($sn);
        $qH = $sanitizer->entities($q);
        if($qH !== '') $snH = preg_replace('~(' . preg_quote($qH, '~') . ')~iu', "<mark class='sf-hl'>$1</mark>", $snH);
        echo "<div class='sf-muted-sm'>{$snH}</div>";
      }
      echo "</li>";
    }
    echo "</ul>";
  }

  // Posts (replies)
  if($results['replies']->count()) {
    echo "<h6 class='mb-2'>Posts</h6><ul class='sf-latest mb-3'>";
    foreach($results['replies'] as $r) {
      $topic = $r->parent;
      if(!$topic || !$topic->id) continue;
      $sn = '';
      try { $sn = sf_search_snip((string)$r->get('forum_body')); } catch(\Throwable $e) { $sn = ''; }
      $when = sf_format_dt((int)$r->created);
      $url = $forum->replyUrl($r);
      echo "<li><a class='sf-link-title sf-index-title' href='{$url}'>" . sf_entities_once($topic->title) . "</a> <span class='sf-muted'>{$when}</span>";
      if($sn) {
        $snH = $sanitizer->entities($sn);
        $qH = $sanitizer->entities($q);
        if($qH !== '') $snH = preg_replace('~(' . preg_quote($qH, '~') . ')~iu', "<mark class='sf-hl'>$1</mark>", $snH);
        echo "<div class='sf-muted-sm'>{$snH}</div>";
      }
      echo "</li>";
    }
    echo "</ul>";
  }

  // Members
  if(count($results['members'])) {
    echo "<h6 class='mb-2'>Members</h6><ul class='sf-latest'>";
    foreach($results['members'] as $m) {
      $label = sf_user_link($m, $forum, $pages, $sanitizer);
      echo "<li>{$label}</li>";
    }
    echo "</ul>";
  }

  echo "</div></div>";
  sf_layout_close($forum, $sidebarHtml, $pages, $sanitizer, $session, $user);
  return;
}


// --- Pinned topics (includes topics pinned individually OR inherited from pinned groups) ---
$pinnedTopics = [];
try {
  // Individually pinned topics
  foreach($pages->find("template=forum-topic, has_parent={$forumRoot->id}, forum_is_pinned=1, sort=-created, limit=25") as $t) {
    $pinnedTopics[$t->id] = $t;
  }

  // Topics under pinned groups
  if($forum && method_exists($forum, 'isPinnedGroup')) {
    $pinnedGroups = $forumRoot->children("template=forum-group, forum_group_is_pinned=1" . ($isMod ? ", include=all" : ", status<hidden"));
    foreach($pinnedGroups as $g) {
      foreach($pages->find("template=forum-topic, has_parent={$g->id}, sort=-created, limit=25") as $t) {
        $pinnedTopics[$t->id] = $t;
      }
    }
  }
} catch(\Exception $e) {}

$pinnedTopics = array_values($pinnedTopics);
if(count($pinnedTopics)) {
  echo "<div class='card sf-panel mb-4'>";
  echo "<div class='card-header d-flex align-items-center justify-content-between'>";
  echo "<div>" . sfT('pinned_topics', 'Pinned topics') . "</div>";
  echo "<div class='sf-muted-sm'>Across the forum</div>";
  echo "</div>";
  echo "<div class='sf-forum-table'>";
  foreach($pinnedTopics as $t) {
    if(!$t || !$t->id) continue;
    $cat = $t->parent;
    if(!$cat || !$cat->id) continue;
    // Respect VIP/read permissions
    if($forum && method_exists($forum,'canReadCategory') && !$forum->canReadCategory($user, $cat)) continue;

    $badge = "";
    $effPinned = ($forum && method_exists($forum,'isPinnedTopic')) ? $forum->isPinnedTopic($t) : ($t->hasField('forum_is_pinned') && (int)$t->forum_is_pinned);
    if($effPinned) $badge .= " <span class='badge text-bg-warning sf-pinned-badge ms-1'>Pinned</span>";
    if($t->hasField('forum_is_locked') && (int)$t->forum_is_locked) $badge .= " <span class='badge text-bg-secondary ms-1'>Locked</span>";
    if($forum && method_exists($forum,'isVipCategory') && $forum->isVipCategory($cat)) $badge .= " <span class='badge text-bg-danger sf-vip-badge ms-1'>VIP</span>";

    $catTitle = sf_entities_once($cat->title);
    $groupTitle = ($cat->parent && $cat->parent->template && $cat->parent->template->name==='forum-group') ? sf_entities_once($cat->parent->title) : '';
    $place = $groupTitle ? "{$groupTitle} / {$catTitle}" : $catTitle;

    $author = sf_page_author_user($t);
    $by = $author ? sf_user_link($author, $forum, $pages, $sanitizer) : "<span class='sf-muted'>Unknown</span>";
    $dt = sf_format_dt((int)$t->created);

	    // Pinned topic row (NOT a category row)
	    $topicTitle = sf_entities_once($t->title);
	    $topicUrl = $t->url;
	    $replyCount = 0;
	    try { $replyCount = (int) $t->numChildren("template=forum-reply" . ($isMod ? ", include=all" : ", status<hidden")); } catch(\Throwable $e) { $replyCount = 0; }
	    $viewCount = 0;
	    try { if($t->hasField('forum_views')) $viewCount = (int) $t->get('forum_views'); } catch(\Throwable $e) { $viewCount = 0; }

	    echo "<div class='sf-forum-row sf-forum-row--pinned'>";
	    echo "<div class='sf-forum-rowgrid'>";
	      echo "<div class='sf-forum-main'>";
	        echo "<div class='sf-forum-rowmain'>";
	          echo "<div class='sf-forum-icon'>" . sf_icon($forum, 'thumb-tack') . "</div>";
	          echo "<div class='sf-forum-titlewrap'>";
	            echo "<a class='sf-link-title sf-index-title' href='{$topicUrl}'>{$topicTitle}</a>{$badge}";
	            echo "<div class='sf-muted-sm'>" . $place . "</div>";
	          echo "</div>";
	        echo "</div>";
	      echo "</div>";
	      echo "<div class='sf-forum-stats sf-forum-stats--pair'>";
	        echo "<div class='sf-stat'><div class='sf-stat-num'>" . (int)$replyCount . "</div><div class='sf-stat-lbl'>replies</div></div>";
	        echo "<div class='sf-stat'><div class='sf-stat-num'>" . (int)$viewCount . "</div><div class='sf-stat-lbl'>views</div></div>";
	      echo "</div>";
	      echo "<div class='sf-forum-last'>";
	        echo "<div class='sf-muted-sm'>by {$by} • {$dt}</div>";
	      echo "</div>";
	    echo "</div>";
	    echo "</div>";
  }

  echo "</div></div>";
};


// Helper: count topics and posts for a category
$countCounts = function(Page $cat) use ($pages, $isMod, $cache): array {
  // Cache for speed (index can render many categories)
  $ck = "sf_cat_counts_" . (int)$cat->id . "_" . ($isMod ? 'm' : 'u');
  try {
    $hit = $cache ? $cache->getFor('NativeForumView', $ck) : null;
    if(is_array($hit) && isset($hit[0]) && isset($hit[1])) return [(int)$hit[0], (int)$hit[1]];
  } catch(\Throwable $e) {}

  $topicSel = "template=forum-topic, parent_id={$cat->id}" . ($isMod ? ", include=all" : ", status<hidden");
  $topics = (int) $pages->count($topicSel);

  // Replies are direct children of topics. Avoid chained selectors like parent.parent=... for compatibility.
  $topicIds = $pages->findIDs($topicSel);
  $replies = 0;
  if(count($topicIds)) {
    $replies = (int) $pages->count("template=forum-reply, parent_id=" . implode("|", $topicIds) . ($isMod ? ", include=all" : ", status<hidden"));
  }

  $posts = $topics + $replies;

  try { if($cache) $cache->save($ck, [$topics, $posts], 300); } catch(\Throwable $e) {}
  return [$topics, $posts];
};

// Helper: last post HTML for a category
$lastPostHtml = function(Page $cat) use ($pages, $sanitizer, $forum, $isMod, $cache): string {
  $ck = "sf_cat_lastpost_" . (int)$cat->id . "_" . ($isMod ? 'm' : 'u');
  try {
    $hit = $cache ? $cache->getFor('NativeForumView', $ck) : null;
    if(is_string($hit) && $hit !== '') return $hit;
  } catch(\Throwable $e) {}

  $t = $cat->child("template=forum-topic" . ($isMod ? ", include=all" : ", status<hidden") . ", sort=-created");
  if(!$t || !$t->id) return "<div class='sf-muted-sm'>No posts</div>";
  $author = function_exists(__NAMESPACE__ . "\\sf_page_author_user") ? sf_page_author_user($t) : null;
  $by = ($author && $author->id && function_exists(__NAMESPACE__ . "\\sf_user_link"))
    ? sf_user_link($author, $forum, wire('pages'), $sanitizer)
    : "<span class='sf-muted'>Unknown</span>";
  $dt = function_exists(__NAMESPACE__ . "\\sf_format_dt") ? sf_format_dt((int)$t->created) : date('Y-m-d H:i', (int)$t->created);
  $ttl = sf_entities_once($t->title);
  $u = $t->url;
  $sum = sf_show_summaries() ? sf_page_summary($t, 90) : '';
  $html = "<div class='sf-last-title'><a class='sf-last-link' href='{$u}' title='{$ttl}'>{$ttl}</a></div>"
    . ($sum !== '' ? "<div class='sf-lastpost-summary'>" . $sanitizer->entities($sum) . "</div>" : '')
    . "<div class='sf-muted-sm'>by {$by} • {$dt}</div>";

  try { if($cache) $cache->saveFor('NativeForumView', $ck, $html, 300); } catch(\Throwable $e) {}
  return $html;
};


// Helper: count topics and posts for a category
// Ungrouped categories are direct children of forum root
$ungrouped = $page->children("template=forum-category" . ($isMod ? ", include=all" : ", status<hidden") . ", sort=sort");

  // Groups under forum root
  $groups = $page->children("template=forum-group" . ($isMod ? ", include=all" : ", status<hidden") . ", sort=sort");
  if(!$groups) $groups = new PageArray();

// Render a group block (header + category rows).
// NOTE: $countCounts and $lastPostHtml are closures defined above; they must be imported into this closure scope.
$renderBlock = function(string $titleHtml, $cats, bool $isVipGroup=false) use ($sanitizer, $forum, $user, $isMod, $pages, $countCounts, $lastPostHtml) {
  if(!$cats) return;
  static $sfGroupI = 0;
  $sfGroupI++;
  $gid = 'sf-group-' . $sfGroupI;
  $bodyId = $gid . '-body';
  echo "<div class='sf-panel card mb-3 sf-groupblock'>";
  echo "<div class='card-header sf-card-header'>";
    echo "<div class='sf-group-title'>" . $titleHtml . "</div>";
    // Toggle (accordion-like) - works in both themes; JS stores state in localStorage
    $arrow = function_exists(__NAMESPACE__ . '\\sf_icon') ? sf_icon($forum, 'chevron-down') : "<span aria-hidden='true'>▾</span>";
    echo "<button type='button' class='sf-group-toggle' data-sf-group-toggle='1' aria-expanded='true' aria-controls='{$bodyId}' title='Collapse/expand'>";
      echo "<span class='sf-group-arrow' aria-hidden='true'>{$arrow}</span>";
    echo "</button>";
  echo "</div>";
  echo "<div class='sf-group-body' id='{$bodyId}'>";
  echo "<div class='sf-forum-table'>";
  foreach($cats as $cat) {
    if(!$cat || !$cat->id) continue;
    // Respect VIP/read permissions if available
    $sfLocked = false;
    if($forum && method_exists($forum,'canReadCategory') && !$forum->canReadCategory($user, $cat)) {
      // Setting "Hide VIP / restricted categories": No = show a locked row (title + summary, no link/stats)
      if((int) sf_cfg('hideVipCategories', 1) !== 0) continue;
      $sfLocked = true;
    }
    $badge = '';
    if($forum && method_exists($forum,'isVipCategory') && $forum->isVipCategory($cat)) $badge .= " <span class='badge text-bg-danger sf-vip-badge ms-1'>VIP</span>";
    if($sfLocked) {
      $ld = sf_page_summary($cat, 220);
      echo "<div class='sf-forum-row sf-forum-row--locked'><div class='sf-forum-rowgrid'><div class='sf-forum-main'><div class='sf-forum-rowmain'>"
        . "<div class='sf-forum-icon'>" . sf_icon($forum, 'lock') . "</div><div class='sf-forum-titlewrap'>"
        . "<span class='sf-link-title sf-index-title'>" . sf_entities_once($cat->title) . "</span>{$badge}"
        . ($ld !== '' ? "<div class='sf-muted-sm sf-index-summary'>" . $sanitizer->entities($ld) . "</div>" : '')
        . "<div class='sf-muted-sm'>Members only — log in or ask for access.</div></div></div></div></div></div>";
      continue;
    }
    // Icon
    // Default forum/category icon: comments bubble
    $iconName = 'comments';
    if($forum && method_exists($forum,'get')) {
      $cfgIcon = (string) $forum->get('designForumIcon', 'comments');
      if($cfgIcon) $iconName = $cfgIcon;
    }
    $desc = sf_page_summary($cat, 220);

$descHtml = $desc ? "<div class='sf-muted-sm sf-index-summary'>" . $sanitizer->entities($desc) . "</div>" : "";

    list($topics, $posts) = $countCounts($cat);
echo "<div class='sf-forum-row'>";
      echo "<div class='sf-forum-rowgrid'>";
        echo "<div class='sf-forum-main'>";
          echo "<div class='sf-forum-rowmain'>";
            echo "<div class='sf-forum-icon'>" . sf_icon($forum, $iconName) . "</div>";
            echo "<div class='sf-forum-titlewrap'>";
              echo "<a class='sf-link-title sf-index-title' href='{$cat->url}'>" . sf_entities_once($cat->title) . "</a>{$badge}";
              echo $descHtml;
            echo "</div>";
          echo "</div>";
        echo "</div>";
        echo "<div class='sf-forum-stats sf-forum-stats--posts'>";
          $postsHuman = sf_human_count((int)$posts);
          echo "<div class='sf-stat'><div class='sf-stat-num'>" . $postsHuman . "</div><div class='sf-stat-lbl'>posts</div></div>";
        echo "</div>";
        echo "<div class='sf-forum-last'>";
          echo $lastPostHtml($cat);
        echo "</div>";
      echo "</div>";
    echo "</div>";
  }
  echo "</div></div></div>";
};

echo "<div class='sf-group-list'>";

foreach($groups as $g) {
  // Hide VIP groups from users without VIP read access
  if($forum && method_exists($forum,'canReadGroup') && !$forum->canReadGroup($user, $g) && (int) sf_cfg('hideVipCategories', 1) !== 0) continue;
  $cats = $g->children("template=forum-category" . ($isMod ? ", include=all" : ", status<hidden") . ", sort=sort");
  $t = sf_entities_once($g->title);
  $bad = '';
  if($g->hasField('forum_group_is_vip') && (int)$g->forum_group_is_vip) $bad .= " <span class='badge text-bg-danger sf-vip-badge ms-1'>VIP</span>";
  if($g->hasField('forum_group_is_pinned') && (int)$g->forum_group_is_pinned) $bad .= " <span class='badge text-bg-warning sf-pinned-badge ms-1'>Pinned</span>";
  $isVipGroup = ($g->hasField('forum_group_is_vip') && (int)$g->forum_group_is_vip);
  $renderBlock($t . $bad, $cats, $isVipGroup);
}

if($ungrouped && $ungrouped->count()) {
  $renderBlock($sanitizer->entities('Other forums'), $ungrouped, false);
}

echo "</div>";


  // New Topic modal behavior is handled centrally in assets/js/sf-ui.js
sf_layout_close($forum, $sidebarHtml, $pages, $sanitizer, $session, $user);
