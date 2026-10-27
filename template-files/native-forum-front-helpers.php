<?php namespace ProcessWire;
/**
 * NativeForum Template Bundle
 * Shared helpers for frontend templates (simple + bootstrap).
 */

if(!function_exists('sf_forum_theme')) {

  /** Translation helper (module override + PW translator) */
  function sfT($forum, string $key, string $default): string {
    if($forum && is_object($forum) && method_exists($forum, 't')) return (string) $forum->t($key, $default);
    return __($default);
  }


  /**
   * Some sites store titles already HTML-encoded (e.g. "&amp;" in DB).
   * Decode once so we don't end up displaying "&amp;" on the frontend.
   */
  function sf_title_plain($value): string {
    $s = (string) $value;
    if($s === '') return '';
    // Decode entities to plain text (safe to re-escape later with $sanitizer->entities()).
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Normalize whitespace a bit.
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
  }

  function sf_forum_theme($forum): string {
    $theme = 'simple';
    if($forum && is_object($forum)) {
      $t = (string) $forum->get('frontendTheme');
      if($t) $theme = $t;
    }
    return $theme === 'bootstrap' ? 'bootstrap' : 'simple';
  }

  function sf_include_forum_css($forum, Config $config): void {
    $theme = sf_forum_theme($forum);
    $cssFile = $theme === 'bootstrap' ? 'native-forum-bootstrap.css' : 'native-forum.css';
    $cssUrl  = $config->urls->templates . $cssFile;

    if(empty($GLOBALS['nativeForumCssLoaded'])) {
      $GLOBALS['nativeForumCssLoaded'] = true;
      echo "<link rel='stylesheet' href='{$cssUrl}' />";
    }
  }

  function sf_forum_root_page($forum, Pages $pages): ?Page {
    if($forum && method_exists($forum, 'forumRoot')) {
      return $forum->forumRoot();
    }
    $p = $pages->get("template=forum-root, name=forum, include=all");
    return $p && $p->id ? $p : null;
  }

  function sf_messages_root_page($forum, Pages $pages): ?Page {
    if($forum && method_exists($forum, 'messagesRoot')) {
      return $forum->messagesRoot();
    }
    $p = $pages->get("template=forum-messages-root, name=messages, include=all");
    return $p && $p->id ? $p : null;
  }

  function sf_user_profile_root($forum, Pages $pages): ?Page {
    // Prefer module setting if present
    $id = 0;
    if($forum && is_object($forum)) {
      $id = (int) $forum->get('userProfileRoot');
    }
    if($id) {
      $p = $pages->get($id);
      if($p && $p->id) return $p;
    }
    $p = $pages->get('/forum-user/');
    return $p && $p->id ? $p : null;
  }

  function sf_user_profile_url($forum, Pages $pages, User $u): string {
    // Use query-string based URLs for maximum compatibility with sites
    // that don't have urlSegments enabled on the forum-user template.
    $root = sf_user_profile_root($forum, $pages);
    $base = ($root && $root->id) ? $root->url : '/forum-user/';
    return $base . '?id=' . (int) $u->id;
  }

  function sf_user_nickname(User $u): string {
    $nick = '';
    if(method_exists($u, 'hasField') && $u->hasField('forum_nickname')) $nick = (string) $u->get('forum_nickname');
    if(!$nick && isset($u->forum_nickname)) $nick = (string) $u->forum_nickname;
    $nick = trim($nick);
    return $nick !== '' ? $nick : $u->name;
  }

  function sf_user_rank($forum, User $u): string {
    if($forum && is_object($forum) && method_exists($forum, 'getUserRank')) {
      return (string) $forum->getUserRank($u);
    }
    // fallback: manual field on user
    $rank = '';
    if(method_exists($u, 'hasField') && $u->hasField('forum_rank')) $rank = (string) $u->get('forum_rank');
    if(!$rank && isset($u->forum_rank)) $rank = (string) $u->forum_rank;
    return trim($rank);
  }

  function sf_user_avatar(User $u): ?Pageimage {
    $img = null;
    $u->of(false);
    try {
      $fieldVal = null;
      if(method_exists($u, 'hasField') && $u->hasField('forum_avatar') && $u->get('forum_avatar')) {
        $fieldVal = $u->get('forum_avatar');
      } elseif(method_exists($u, 'hasField') && $u->hasField('avatar') && $u->get('avatar')) {
        $fieldVal = $u->get('avatar');
      } else {
        foreach($u->fields as $f) {
          if($f->type instanceof FieldtypeImage) {
            $images = $u->get($f->name);
            if($images) { $fieldVal = $images; break; }
          }
        }
      }
      if($fieldVal instanceof Pageimages) $img = $fieldVal->first();
      elseif($fieldVal instanceof Pageimage) $img = $fieldVal;
    } catch(\Throwable $e) {
      $img = null;
    }
    $u->of(true);
    return $img;
  }

  /**
   * Small inline SVG icons (no external dependencies).
   */
  function sf_svg_icon(string $name): string {
    $name = strtolower(trim($name));
    switch($name) {
      case 'edit':
        // pencil
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M12.146.146a.5.5 0 0 1 .708 0l2.992 2.992a.5.5 0 0 1 0 .708l-9.5 9.5a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l9.5-9.5zM11.207 2L3 10.207V11h.793L12 2.793 11.207 2zM13 3.793 12.207 3 4 11.207V12h.793L13 4.793z"/></svg>';

      case 'delete':
        // trash
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M5.5 5.5A.5.5 0 0 1 6 6v7a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5.5a.5.5 0 0 1 1 0v7a.5.5 0 0 1-1 0V6zm3 .5a.5.5 0 0 0-1 0v7a.5.5 0 0 0 1 0V6z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5.5l.5-.5h4l.5.5H14a1 1 0 0 1 .5 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118z"/></svg>';

      case 'pm':
      case 'message':
        // envelope
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V4zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1H2zm13 2.383-4.708 2.825L15 11.105V5.383zM14.247 12 9.06 8.872 8 9.515l-1.06-.643L1.753 12H14.247zM1 11.105l4.708-2.897L1 5.383v5.722z"/></svg>';
    }
    return '';
  }

  function sf_forum_stats($forumRoot, Pages $pages, Users $users): array {
    $data = [
      'groups'     => 0,
      'categories' => 0,
      'topics'     => 0,
      'replies'    => 0,
      'members'    => 0,
    ];
    if($forumRoot && $forumRoot->id) {
      $data['groups']     = (int) $forumRoot->children("template=forum-group, include=all")->count();
      $data['categories'] = (int) $pages->count("template=forum-category, has_parent=$forumRoot, include=all");
      $data['topics']     = (int) $pages->count("template=forum-topic, has_parent=$forumRoot, include=all");
      $data['replies']    = (int) $pages->count("template=forum-reply, has_parent=$forumRoot, include=all");
    }
    $data['members'] = (int) $users->count("id>0");
    return $data;
  }

  function sf_latest_topics($forumRoot, Pages $pages, int $limit = 7): PageArray {
    if(!$forumRoot || !$forumRoot->id) return new PageArray();
    return $pages->find("template=forum-topic, has_parent=$forumRoot, include=all, sort=-created, limit=$limit");
  }

  function sf_sidebar_blocks($forum, Pages $pages, Users $users): array {
    $forumRoot = sf_forum_root_page($forum, $pages);
    $stats = sf_forum_stats($forumRoot, $pages, $users);
    $latest = sf_latest_topics($forumRoot, $pages, 7);

    return [
      'forumRoot' => $forumRoot,
      'stats'     => $stats,
      'latest'    => $latest,
    ];
  }

  function sf_render_sidebar($forum, Pages $pages, Users $users, Sanitizer $sanitizer): string {
    $theme = sf_forum_theme($forum);
    $d = sf_sidebar_blocks($forum, $pages, $users);
    $forumRoot = $d['forumRoot'];
    $stats = $d['stats'];
    $latest = $d['latest'];

    $searchUrl = $forumRoot && $forumRoot->id ? $forumRoot->url : '/forum/';
    $html = '';

    if($theme === 'bootstrap') {
      $html .= '<div class="sf-sidebar">';
      $html .= '<div class="card mb-3"><div class="card-body">';
      $html .= '<form method="get" action="' . $searchUrl . '" class="d-flex gap-2">';
      $html .= '<input class="form-control" type="search" name="q" placeholder="Search forum">';
      $html .= '<button class="btn btn-primary" type="submit">Search</button>';
      $html .= '</form>';
      $html .= '</div></div>';

      $html .= '<div class="card mb-3"><div class="card-header">Forum stats</div><div class="card-body">';
      $html .= '<div class="row g-2">';
      $html .= '<div class="col-6"><div class="sf-stat"><div class="sf-stat-num">' . (int)$stats['groups'] . '</div><div class="sf-stat-label">Groups</div></div></div>';
      $html .= '<div class="col-6"><div class="sf-stat"><div class="sf-stat-num">' . (int)$stats['categories'] . '</div><div class="sf-stat-label">Categories</div></div></div>';
      $html .= '<div class="col-6"><div class="sf-stat"><div class="sf-stat-num">' . (int)$stats['topics'] . '</div><div class="sf-stat-label">Topics</div></div></div>';
      $html .= '<div class="col-6"><div class="sf-stat"><div class="sf-stat-num">' . (int)$stats['replies'] . '</div><div class="sf-stat-label">Replies</div></div></div>';
      $html .= '</div>';
      $html .= '</div></div>';

      $html .= '<div class="card mb-3"><div class="card-header">Latest topics</div>';
      $html .= '<div class="list-group list-group-flush">';
      foreach($latest as $t) {
        $html .= '<a class="list-group-item list-group-item-action" href="' . $t->url . '">';
        $html .= '<div class="sf-latest-title">' . $sanitizer->entities($t->title) . '</div>';
        $html .= '<div class="sf-muted small">' . date('Y-m-d H:i', $t->created) . '</div>';
        $html .= '</a>';
      }
      if(!$latest->count()) $html .= '<div class="list-group-item sf-muted">No topics yet.</div>';
      $html .= '</div></div>';

      $html .= '</div>';
    } else {
      $html .= '<aside class="sf-sidebar">';
      $html .= '<div class="sf-box sf-box-search">';
      $html .= '<form method="get" action="' . $searchUrl . '" class="sf-search">';
      $html .= '<input type="search" name="q" placeholder="Search forum" class="sf-input">';
      $html .= '<button type="submit" class="sf-button-primary">Search</button>';
      $html .= '</form>';
      $html .= '</div>';

      $html .= '<div class="sf-box">';
      $html .= '<div class="sf-box-title">Forum stats</div>';
      $html .= '<div class="sf-stats-grid">';
      $html .= '<div class="sf-stat"><div class="sf-stat-num">' . (int)$stats['groups'] . '</div><div class="sf-stat-label">Groups</div></div>';
      $html .= '<div class="sf-stat"><div class="sf-stat-num">' . (int)$stats['categories'] . '</div><div class="sf-stat-label">Categories</div></div>';
      $html .= '<div class="sf-stat"><div class="sf-stat-num">' . (int)$stats['topics'] . '</div><div class="sf-stat-label">Topics</div></div>';
      $html .= '<div class="sf-stat"><div class="sf-stat-num">' . (int)$stats['replies'] . '</div><div class="sf-stat-label">Replies</div></div>';
      $html .= '</div>';
      $html .= '</div>';

      $html .= '<div class="sf-box">';
      $html .= '<div class="sf-box-title">Latest topics</div>';
      $html .= '<div class="sf-latest">';
      foreach($latest as $t) {
        $html .= '<a class="sf-latest-item" href="' . $t->url . '">';
        $html .= '<div class="sf-latest-title">' . $sanitizer->entities($t->title) . '</div>';
        $html .= '<div class="sf-muted">' . date('Y-m-d H:i', $t->created) . '</div>';
        $html .= '</a>';
      }
      if(!$latest->count()) $html .= '<div class="sf-muted">No topics yet.</div>';
      $html .= '</div>';
      $html .= '</div>';

      $html .= '</aside>';
    }

    return $html;
  }

}
