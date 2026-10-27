<?php namespace ProcessWire;
/**
 * NativeForum Template (Bootstrap 5)
 * Group page: shows categories within a group.
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

if(!$forum->canReadForum($user, $page)) throw new Wire404Exception();

$isMod = (method_exists($forum,'isModerator') && $forum->isModerator($user));

if(method_exists($forum, 'canReadGroup') && !$forum->canReadGroup($user, $page)) throw new Wire404Exception();

$forumRoot = $forum->forumRoot() ?: $pages->get('/forum/');

$cats = $page->children("template=forum-category" . ($isMod ? ", include=all" : ", status<hidden") . ", sort=sort");

$sidebarHtml = sf_sidebar_html($forum, $page, $pages, $users, $sanitizer, $user);

$crumb = "<div class='sf-breadcrumbbar'>"
  . "<a href='{$forumRoot->url}'>Board index</a><span class='sf-breadcrumb-sep'>»</span>"
  . $sanitizer->entities(sf_title_plain($page->title))
  . "</div>";

sf_layout_open($forum, $config, $crumb, $sidebarHtml);

$canListCategory = function(Page $cat) use ($forum, $user): bool {
  if(!$forum || !method_exists($forum, 'canReadCategory')) return true;
  return (bool) $forum->canReadCategory($user, $cat);
};

$isVip = function(Page $cat) use ($forum): bool {
  return ($forum && method_exists($forum,'isVipCategory')) ? (bool)$forum->isVipCategory($cat) : false;
};

$catCounts = function(Page $cat) use ($pages): array {
  $topics = $cat->children("template=forum-topic")->count();
  $replies = $pages->count("template=forum-reply, has_parent={$cat->id}");
  return [(int)$topics, (int)($topics + $replies)];
};

$lastPostHtml = function(Page $cat) use ($pages, $sanitizer, $forum): string {
  $last = $pages->findOne("template=forum-reply|forum-topic, has_parent={$cat->id}, sort=-created");
  if(!$last || !$last->id) return "<span class='sf-muted'>No posts</span>";

  $url = $last->template->name === 'forum-reply'
    ? ($last->parent->url . "#reply-" . (int)$last->id)
    : $last->url;

  // Resolve author without requiring view access to user pages
  $author = sf_page_author_user($last);

  $title = $last->template->name === 'forum-topic' ? $last->title : $last->parent->title;

  $t = $sanitizer->entities($title);
  $a = sf_user_link($author, $forum, $pages, $sanitizer);
  $d = sf_format_dt((int)$last->created);

  $topicPage = $last->template->name === 'forum-topic' ? $last : $last->parent;
  $sum = sf_show_summaries() ? sf_page_summary($topicPage, 90) : '';
  $sumHtml = $sum !== '' ? "<div class='sf-lastpost-summary'>" . $sanitizer->entities($sum) . "</div>" : '';
  return "<a class='sf-lastpost-title' href='{$url}'>{$t}</a>{$sumHtml}<div class='sf-muted-sm'>by {$a} • {$d}</div>";
};

 $isVipGroup = ($page->hasField('forum_group_is_vip') && (int)$page->forum_group_is_vip);
echo "<div class='card sf-panel mb-4" . ($isVipGroup ? " sf-panel-vip" : "") . "'>";
echo "<div class='card-header'>";
 $gt = $sanitizer->entities(sf_title_plain($page->title));
 $gb = '';
 if($page->hasField('forum_group_is_vip') && (int)$page->forum_group_is_vip) $gb .= " <span class='badge text-bg-danger sf-vip-badge ms-1'>VIP</span>";
 if($page->hasField('forum_group_is_pinned') && (int)$page->forum_group_is_pinned) $gb .= " <span class='badge text-bg-warning sf-pinned-badge ms-1'>Pinned</span>";
 echo "<div class='row w-100 align-items-center gy-1'>";
 echo "<div class='col-md-5'>{$gt}{$gb}</div>";
 echo "<div class='col-md-1 text-center d-none d-md-block text-nowrap'>Topics</div>";
 echo "<div class='col-md-1 text-center d-none d-md-block text-nowrap'>Posts</div>";
 echo "<div class='col-md-5 d-none d-md-block text-nowrap'>Last post</div>";
 echo "</div>";
echo "</div>";
echo "<div class='list-group list-group-flush'>";

foreach($cats as $cat) {
  if(!$canListCategory($cat)) continue;
  [$topics, $posts] = $catCounts($cat);
  $vip = $isVip($cat);
  $groupPinned = ($forum && method_exists($forum,'isPinnedGroup')) ? $forum->isPinnedGroup($page) : false;
  $badge = '';
  if($vip) $badge .= " <span class='sf-badge-vip'>VIP</span>";
  if($groupPinned) $badge .= " <span class='badge text-bg-warning sf-pinned-badge ms-1'>Pinned</span>";

  echo "<div class='list-group-item'>";
  echo "<div class='row align-items-center gy-2'>";
  echo "<div class='col-md-5'>";
  echo "<div class='d-flex gap-2 align-items-start'>";
    // Same icon renderer as the forum index (SVG by default, Font Awesome when configured)
    echo "<div class='sf-forum-icon'>" . sf_icon($forum, 'comments') . "</div>";
  echo "<div>";
  $cTitle = $sanitizer->entities(sf_title_plain($cat->title));
  echo "<a class='sf-link-title sf-title-truncate' href='{$cat->url}' data-bs-toggle='tooltip' data-bs-placement='top' title='{$cTitle}'>{$cTitle}</a>{$badge}";
  // Category summary/description (show if present)
  $cdesc = sf_page_summary($cat, 220);
  if($cdesc !== '') echo "<div class='sf-muted-sm sf-index-summary'>" . $sanitizer->entities($cdesc) . "</div>";
  echo "</div></div></div>";

  echo "<div class='col-md-1 text-center d-none d-md-block'>{$topics}</div>";
  echo "<div class='col-md-1 text-center d-none d-md-block'>{$posts}</div>";
  echo "<div class='col-md-5'>" . $lastPostHtml($cat) . "</div>";
  echo "</div></div>";
}

echo "</div></div>";

sf_layout_close($forum, $sidebarHtml, $pages, $sanitizer, $session, $user);