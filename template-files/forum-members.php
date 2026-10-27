<?php namespace ProcessWire;
/**
 * NativeForum Template
 * Members list with ranks (and admin inline editing when allowed).
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

$canManage = $user->isSuperuser() || $user->hasPermission('forum-manage-users') || $user->hasRole('forum-admin');

if($input->post->set_rank && $canManage && $session->CSRF->hasValidToken('sfrank')) {
  $uid = (int)$input->post->uid;
  $rank = (string)$input->post->rank;
  $u = $users->get($uid);
  if($u && $u->id) {
    if(method_exists($forum,'normalizeRank')) $rank = $forum->normalizeRank($rank);
    $u->of(false);
    if($u->hasField('forum_rank')) $u->forum_rank = $rank;
    $u->save('forum_rank');
    $u->of(true);
  }
  $session->redirect($page->url);
}

$rankList = [];
if(method_exists($forum,'getRankList')) $rankList = $forum->getRankList();
if(!is_array($rankList)) $rankList = [];

// Exclude only the built-in guest *user* (NOT the guest role).
// Note: In ProcessWire, logged-in users may still have the 'guest' role implicitly,
// so using roles!=guest can accidentally exclude everyone.
// Paginated + filtered in the database (fast even with thousands of members)
$sfPer = 40;
$sfPg = max(1, (int) $input->get->p);
$mq = trim($sanitizer->text((string) $input->get->q));
$sfSel = "id>0, name!=guest, check_access=0";
if($mq !== '') {
  $mqS = $sanitizer->selectorValue($mq);
  $sfSel .= ($fields->get('forum_nickname') ? ", name|forum_nickname%=$mqS" : ", name%=$mqS");
}
$sfTotal = (int) $users->count($sfSel);
$sfPages = max(1, (int) ceil($sfTotal / $sfPer));
if($sfPg > $sfPages) $sfPg = $sfPages;
$sfIds = $users->findIDs($sfSel . ", sort=name, start=" . (($sfPg - 1) * $sfPer) . ", limit=$sfPer");
$members = new PageArray();
foreach($forum->preloadUsers($sfIds) as $m) $members->add($m);

$sidebarHtml = sf_sidebar_html($forum, $page, $pages, $users, $sanitizer);

// Breadcrumb header
$forumRoot = $forum->forumRoot() ?: $pages->get('/forum/');
$crumb = "<div class='sf-breadcrumbbar'>" .
  "<a href='{$forumRoot->url}'>Board index</a>" .
  "<span class='sf-breadcrumb-sep'>»</span>" .
  "Members" .
  "</div>";

sf_layout_open($forum, $config, $crumb . "<h1 class='sf-h1'>Members</h1>", $sidebarHtml);

echo "<div class='sf-card sf-members'>";
echo "<div class='sf-card-h sf-members-h'><span>" . $sfTotal . " members</span>";
echo "<form method='get' class='sf-members-filter' role='search'><input type='search' name='q' value='" . $sanitizer->entities($mq) . "' placeholder='Find a member…' aria-label='Find a member'></form></div>";
echo "<ul class='sf-member-list'>";

foreach($members as $m) {
  $rank = method_exists($forum,'getUserRank') ? $forum->getUserRank($m) : (($m->hasField('forum_rank')) ? (string)$m->forum_rank : '');
  $profileUrl = sf_user_profile_url($forum, $pages, $m);
  $name = $forum->displayName($m);
  $posts = 0;
  try { $posts = (int) $forum->getUserPostCount($m); } catch(\Throwable $e) {}
  $av = function_exists(__NAMESPACE__ . '\\sf_user_avatar') ? sf_user_avatar($m) : null;
  $avHtml = $av ? "<img src='" . $av->size(80, 80)->url . "' alt='' width='40' height='40' loading='lazy'>" : $sanitizer->entities(mb_strtoupper(mb_substr($name, 0, 1)));

  echo "<li class='sf-member'>";
  echo "<a class='sf-avatar sf-member-av' href='{$profileUrl}' aria-hidden='true' tabindex='-1'>{$avHtml}</a>";
  echo "<div class='sf-member-main'><a class='sf-member-name' href='{$profileUrl}'>" . $sanitizer->entities($name) . "</a>";
  if($rank) echo " <span class='badge text-bg-primary'>" . $sanitizer->entities($rank) . "</span>";
  echo "<div class='sf-muted-sm'>Joined " . sf_format_dt((int) $m->created) . "</div></div>";
  echo "<div class='sf-member-stat'><strong>{$posts}</strong><span>posts</span></div>";

  if($canManage) {
    echo "<form method='post' class='sf-member-rank'>";
    echo "<input type='hidden' name='uid' value='" . (int)$m->id . "'>";
    echo "<select name='rank' aria-label='Rank for " . $sanitizer->entities($name) . "'>";
    echo "<option value=''>— rank —</option>";
    foreach($rankList as $r) {
      $sel = ((string)$r === (string)$rank) ? "selected" : "";
      echo "<option {$sel} value='" . $sanitizer->entities($r) . "'>" . $sanitizer->entities($r) . "</option>";
    }
    echo "</select>";
    echo $session->CSRF->renderInput('sfrank');
    echo "<button class='sf-button-secondary' type='submit' name='set_rank' value='1'>Save</button>";
    echo "</form>";
  }
  echo "</li>";
}
if(!count($members)) echo "<li class='sf-member sf-muted'>No members found.</li>";
echo "</ul>";
if($sfPages > 1) {
  echo "<nav class='sf-pagination' aria-label='Members pages'>";
  for($i = 1; $i <= $sfPages; $i++) {
    if($i !== 1 && $i !== $sfPages && abs($i - $sfPg) > 2) { if($i === 2 || $i === $sfPages - 1) echo "<span class='sf-pagination-gap'>…</span>"; continue; }
    $href = $page->url . '?' . http_build_query(array_filter(['q' => $mq, 'p' => $i > 1 ? $i : null]));
    echo $i === $sfPg ? "<span class='is-active' aria-current='page'>{$i}</span>" : "<a href='" . $sanitizer->entities($href) . "'>{$i}</a>";
  }
  echo "</nav>";
}
echo "</div>";

sf_layout_close($forum, $sidebarHtml);
