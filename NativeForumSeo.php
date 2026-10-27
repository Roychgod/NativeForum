<?php namespace ProcessWire;

/**
 * NativeForum SEO + GEO (generative engine optimization) layer.
 *
 * - Full HTML5 document for standalone layout (doctype, viewport, assets in <head>)
 * - <title>, meta description, canonical (with ?p= pagination), rel prev/next, robots
 * - Open Graph + Twitter cards
 * - JSON-LD: DiscussionForumPosting (+ comments), BreadcrumbList, CollectionPage,
 *   ProfilePage, WebSite + SearchAction
 * - {forum}/sitemap.xml, {forum}/feed.xml (RSS 2.0), /llms.txt, topic ?format=md
 */
class NativeForumSeo extends Wire {

  /** @var NativeForum */
  protected $forum;

  public function __construct(NativeForum $forum) {
    parent::__construct();
    $this->forum = $forum;
  }

  /* ------------------------------------------------------------------ helpers */

  protected function cfg(string $key, $default = null) {
    $v = $this->forum->get($key);
    return ($v === null || $v === '') ? $default : $v;
  }

  public function forumName(): string {
    $n = trim((string) $this->cfg('forumName', ''));
    if($n !== '') return $n;
    $root = $this->forum->forumRoot();
    return $root ? (string) $root->title : 'Forum';
  }

  protected function e($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

  /** Plain text excerpt from HTML. */
  public function excerpt($html, int $len = 160): string {
    $html = (string) $html;
    $html = preg_replace('~<(script|style|iframe)\b[^>]*>.*?</\1>~is', ' ', $html);
    for($qi = 0; $qi < 10 && stripos($html, '<blockquote') !== false; $qi++) { // quotes are someone else's words (inside out)
      $html = preg_replace('~<blockquote\b[^>]*>(?:(?!<blockquote\b).)*?</blockquote>~is', ' ', $html);
    }
    $html = preg_replace('~\[\[sf-(img|file|embed)[^\]]*\]\]~i', ' ', $html);
    $txt = html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>'], [' <br', ' </p>', ' </li>'], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $txt = trim(preg_replace('/\s+/u', ' ', $txt));
    if($len > 0 && mb_strlen($txt) > $len) {
      $cut = mb_substr($txt, 0, $len - 1);
      $sp = mb_strrpos($cut, ' ');
      if($sp !== false && $sp > $len * 0.6) $cut = mb_substr($cut, 0, $sp);
      $txt = rtrim($cut, " ,.;:-") . '…';
    }
    return $txt;
  }

  protected function summaryOf(Page $p): string {
    foreach(['field_forum_summary_topic', 'field_forum_summary_category', 'summary', 'description'] as $f) {
      if($p->hasField($f)) {
        $v = trim((string) $p->getUnformatted($f));
        if($v !== '') return $this->excerpt($v, 300);
      }
    }
    return '';
  }

  protected function currentPageNum(): int {
    $input = $this->wire('input');
    return max(1, (int) $input->get('p'), (int) $input->pageNum);
  }

  /** Is the page readable by anonymous visitors? (decides indexing) */
  protected function isPublic(Page $page): bool {
    if(!$page->isPublic()) return false;
    $guest = $this->wire('users')->getGuestUser();
    $t = (string) $page->template->name;
    try {
      if($t === 'forum-group') return $this->forum->canReadGroup($guest, $page);
      if($t === 'forum-category') return $this->forum->canReadCategory($guest, $page);
      if($t === 'forum-topic') return $this->forum->canReadForum($guest, $page);
      return $this->forum->canReadForum($guest);
    } catch(\Throwable $e) {
      return false;
    }
  }

  protected function author(Page $p): ?User {
    $a = $p->hasField('forum_author') ? $p->getUnformatted('forum_author') : null;
    if($a instanceof PageArray) $a = $a->first();
    if($a instanceof Page && $a->id) {
      $u = $this->wire('users')->get((int) $a->id);
      if($u && $u->id) return $u;
    }
    $u = $this->wire('users')->get((int) $p->created_users_id);
    return ($u && $u->id && !$u->isGuest()) ? $u : null;
  }

  protected function personLd(?User $u): array {
    if(!$u) return ['@type' => 'Person', 'name' => 'Guest'];
    return [
      '@type' => 'Person',
      'name'  => $this->forum->displayName($u),
      'url'   => $this->absUrl($this->forum->userProfileUrl($u)),
    ];
  }

  protected function absUrl(string $url): string {
    if($url === '' || $url === '#') return '';
    if(preg_match('~^https?://~i', $url)) return $url;
    $config = $this->wire('config');
    $scheme = $config->https ? 'https://' : 'http://';
    return $scheme . $config->httpHost . $url;
  }

  protected function iso(int $ts): string {
    return date('c', $ts ?: time());
  }

  protected function firstImage(Page $p): string {
    // only images actually shown in this post's own text (not quotes, not removed uploads)
    if(!$p->hasField('forum_body')) return '';
    $body = (string) $p->getUnformatted('forum_body');
    // quoted posts (blockquotes) may contain other members' pictures
    for($i = 0; $i < 10 && stripos($body, '<blockquote') !== false; $i++) $body = preg_replace('~<blockquote\b[^>]*>(?:(?!<blockquote\b).)*?</blockquote>~is', ' ', $body);
    if(preg_match_all('~<img\b[^>]*>~i', $body, $tags)) {
      foreach($tags[0] as $tag) {
        foreach(['src', 'data-src', 'data-lazy-src', 'srcset', 'data-srcset'] as $attr) {
          if(!preg_match('~\s' . preg_quote($attr, '~') . '\s*=\s*(["\'])(.*?)\1~is', $tag, $m)) continue;
          $u = trim(preg_split('~[\s,]+~', trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')))[0] ?? '');
          if($u === '' || stripos($u, 'data:') === 0 || preg_match('~(emoji|smil(e|ey)|/icons?/)~i', $u)) continue;
          return $this->absUrl($u);
        }
      }
    }
    // markup changed by an image optimizer/editor: an image of this post whose file name appears in the text
    foreach(['forum_post_images', 'forum_images', 'images'] as $f) {
      if(!$p->hasField($f)) continue;
      $imgs = $p->getUnformatted($f);
      if(!$imgs || !count($imgs)) continue;
      foreach($imgs as $im) {
        $base = pathinfo((string) $im->basename, PATHINFO_FILENAME);
        if($base !== '' && stripos($body, $base) !== false) return $this->absUrl((string) $im->url);
      }
    }
    return '';
  }

  /** og:image size tags (Facebook only shows an image on the very first share when it knows its size). */
  protected function imageSizeTags(string $url): string {
    if($url === '') return '';
    $path = (string) parse_url($url, PHP_URL_PATH);
    $host = (string) parse_url($url, PHP_URL_HOST);
    $config = $this->wire('config');
    if($host !== '' && strcasecmp(preg_replace('~^www\.~i', '', $host), preg_replace('~^www\.|:\d+$~i', '', (string) $config->httpHost)) !== 0) return '';
    $file = rtrim($config->paths->root, '/') . '/' . ltrim(rawurldecode($path), '/');
    if($path === '' || strpos($path, '..') !== false || !is_file($file)) return '';
    $sz = @getimagesize($file);
    if(!$sz || empty($sz[0])) return '';
    $h = '<meta property="og:image:width" content="' . (int) $sz[0] . '">'
       . '<meta property="og:image:height" content="' . (int) $sz[1] . '">';
    if(!empty($sz['mime'])) $h .= '<meta property="og:image:type" content="' . $this->e($sz['mime']) . '">';
    return $h;
  }

  /**
   * Link preview for a single reply (Facebook, Messenger, WhatsApp, X, LinkedIn, Slack, Discord…).
   * Social crawlers get this small page; people are redirected to the post inside its topic.
   */
  public function replySharePage(Page $reply, string $targetUrl): string {
    $topic = $reply->parent;
    $author = null;
    try { $a = $reply->getUnformatted('forum_author'); if($a instanceof PageArray) $a = $a->first(); if($a instanceof User && $a->id) $author = $a; } catch(\Throwable $e) {}
    $who = $author ? $this->forum->displayName($author) : '';
    $body = $reply->hasField('forum_body') ? (string) $reply->getUnformatted('forum_body') : '';
    // the reply's own words (not the quotes it contains)
    for($i = 0; $i < 10 && stripos($body, '<blockquote') !== false; $i++) $body = preg_replace('~<blockquote\b[^>]*>(?:(?!<blockquote\b).)*?</blockquote>~is', ' ', $body);
    $desc = $this->excerpt($body, 200);
    if($who !== '') $desc = $who . ': ' . $desc;
    $title = ($topic && $topic->id ? (string) $topic->title : (string) $reply->title) . ' | ' . $this->forumName();
    // this reply's own image, otherwise the forum's general share image (never another post's picture)
    $img = $this->firstImage($reply) ?: (string) $this->cfg('seoDefaultImage', '');
    $url = $reply->httpUrl;
    $e = function($x) { return $this->e($x); };
    $h = "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>{$e($title)}</title>"
      . "<meta name=\"description\" content=\"{$e($desc)}\">"
      . "<meta property=\"og:site_name\" content=\"{$e($this->forumName())}\">"
      . "<meta property=\"og:type\" content=\"article\">"
      . "<meta property=\"og:title\" content=\"{$e($title)}\">"
      . "<meta property=\"og:description\" content=\"{$e($desc)}\">"
      . "<meta property=\"og:url\" content=\"{$e($url)}\">"
      . ($img ? "<meta property=\"og:image\" content=\"{$e($this->absUrl($img))}\">" . $this->imageSizeTags($this->absUrl($img)) : '')
      . "<meta property=\"article:published_time\" content=\"{$e($this->iso((int) $reply->created))}\">"
      . "<meta name=\"twitter:card\" content=\"" . ($img ? 'summary_large_image' : 'summary') . "\">"
      . "<meta name=\"twitter:title\" content=\"{$e($title)}\">"
      . "<meta name=\"twitter:description\" content=\"{$e($desc)}\">"
      . "<link rel=\"canonical\" href=\"{$e($this->absUrl($targetUrl))}\">"
      . "<meta http-equiv=\"refresh\" content=\"0;url={$e($targetUrl)}\">"
      . "</head><body><p><a href=\"{$e($targetUrl)}\">{$e($title)}</a></p><p>{$e($desc)}</p></body></html>";
    return $h;
  }

  /* ------------------------------------------------------------ meta builder */

  /**
   * Collect all SEO data for the current page.
   */
  public function meta(Page $page): array {
    $input = $this->wire('input');
    $tpl = (string) $page->template->name;
    $pageNum = $this->currentPageNum();
    $forumName = $this->forumName();
    $root = $this->forum->forumRoot();

    $m = [
      'title' => (string) $page->title,
      'description' => '',
      'canonical' => $page->httpUrl,
      'robots' => 'index,follow,max-image-preview:large',
      'type' => 'website',
      'image' => (string) $this->cfg('seoDefaultImage', ''),
      'prev' => '',
      'next' => '',
      'jsonld' => [],
      'published' => 0,
      'modified' => 0,
    ];

    $noindexTpls = ['forum-login', 'forum-register', 'forum-profile', 'forum-messages-root', 'forum-message'];
    if(in_array($tpl, $noindexTpls, true)) $m['robots'] = 'noindex,nofollow';
    if(!$this->isPublic($page)) $m['robots'] = 'noindex,nofollow';
    $q = trim((string) $input->get('q'));
    if($q !== '') {
      $m['robots'] = 'noindex,follow';
      $m['title'] = 'Search: ' . $this->wire('sanitizer')->text($q);
    }

    $crumbs = [];
    foreach($page->parents as $par) {
      if($par->id === 1) continue;
      $crumbs[] = $par;
    }
    $crumbs[] = $page;

    switch($tpl) {
      case 'forum-root':
        $m['title'] = $q !== '' ? $m['title'] : $forumName;
        $m['description'] = (string) $this->cfg('forumDescription', '') ?: ($this->summaryOf($page) ?: "Discussion forum: questions, answers and community topics on {$forumName}.");
        $m['jsonld'][] = [
          '@context' => 'https://schema.org',
          '@type' => 'WebSite',
          'name' => $forumName,
          'url' => $page->httpUrl,
          'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $page->httpUrl . '?q={search_term_string}&sf_scope=everywhere'],
            'query-input' => 'required name=search_term_string',
          ],
        ];
        break;

      case 'forum-group':
      case 'forum-category':
        $m['description'] = $this->summaryOf($page) ?: ($tpl === 'forum-category'
          ? "Topics and discussions in {$page->title} on {$forumName}."
          : "Forum section {$page->title}: categories and discussions on {$forumName}.");
        $items = [];
        if($tpl === 'forum-category') {
          $per = max(5, (int) $this->cfg('topicsPerPage', 20));
          $total = (int) $page->numChildren('template=forum-topic');
          $last = max(1, (int) ceil($total / $per));
          if($pageNum > $last) $m['robots'] = 'noindex,follow';
          if($pageNum > 1) $m['prev'] = $page->httpUrl . ($pageNum > 2 ? '?p=' . ($pageNum - 1) : '');
          if($pageNum < $last) $m['next'] = $page->httpUrl . '?p=' . ($pageNum + 1);
          $list = $page->children("template=forum-topic, sort=-forum_is_pinned, sort=-modified, start=" . (($pageNum - 1) * $per) . ", limit=$per");
          $pos = ($pageNum - 1) * $per;
          foreach($list as $t) $items[] = ['@type' => 'ListItem', 'position' => ++$pos, 'url' => $t->httpUrl, 'name' => (string) $t->title];
        } else {
          $pos = 0;
          foreach($page->children('template=forum-category') as $c) $items[] = ['@type' => 'ListItem', 'position' => ++$pos, 'url' => $c->httpUrl, 'name' => (string) $c->title];
        }
        $m['jsonld'][] = [
          '@context' => 'https://schema.org',
          '@type' => 'CollectionPage',
          'name' => (string) $page->title,
          'url' => $m['canonical'],
          'description' => $m['description'],
          'isPartOf' => ['@type' => 'WebSite', 'name' => $forumName, 'url' => $root ? $root->httpUrl : ''],
          'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items],
        ];
        break;

      case 'forum-topic':
        $m['type'] = 'article';
        $body = $page->hasField('forum_body') ? (string) $page->getUnformatted('forum_body') : '';
        $m['description'] = $this->summaryOf($page) ?: $this->excerpt($body, 160);
        $m['published'] = (int) $page->created;
        $img = $this->firstImage($page);
        if($img) $m['image'] = $img;

        $per = $this->forum->postsPerPage();
        $pages = $this->wire('pages');
        $replyCount = (int) $pages->count("template=forum-reply, parent={$page->id}");
        $totalPosts = $replyCount + 1;
        $last = max(1, (int) ceil($totalPosts / $per));
        if($pageNum > $last) $m['robots'] = 'noindex,follow';
        if($pageNum > 1) $m['prev'] = $page->httpUrl . ($pageNum > 2 ? '?p=' . ($pageNum - 1) : '');
        if($pageNum < $last) $m['next'] = $page->httpUrl . '?p=' . ($pageNum + 1);

        $lastReply = $pages->findOne("template=forum-reply, parent={$page->id}, sort=-created");
        $m['modified'] = max((int) $page->modified, $lastReply && $lastReply->id ? (int) $lastReply->created : 0);

        // Replies shown on this page (topic post is #1 on page 1)
        $start = max(0, ($pageNum - 1) * $per - 1);
        $limit = $pageNum === 1 ? $per - 1 : $per;
        $comments = [];
        foreach($pages->find("template=forum-reply, parent={$page->id}, sort=created, sort=id, start=$start, limit=$limit") as $r) {
          if($r->hasField('forum_is_deleted') && (int) $r->forum_is_deleted) continue;
          $text = $this->excerpt((string) $r->getUnformatted('forum_body'), 1000);
          if($text === '') continue;
          $c = [
            '@type' => 'Comment',
            'text' => $text,
            'datePublished' => $this->iso((int) $r->created),
            'author' => $this->personLd($this->author($r)),
            'url' => $this->forum->replyUrl($r, true),
          ];
          $likes = $r->hasField('forum_like_users') ? $r->getUnformatted('forum_like_users') : null;
          $up = ($likes instanceof PageArray ? $likes->count() : 0) + max(0, (int) $r->get('forum_vote_score'));
          if($up) $c['interactionStatistic'] = ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/LikeAction', 'userInteractionCount' => $up];
          $comments[] = $c;
        }

        $likes = $page->hasField('forum_like_users') ? $page->getUnformatted('forum_like_users') : null;
        $stats = [
          ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/CommentAction', 'userInteractionCount' => $replyCount],
        ];
        if($page->hasField('forum_views')) $stats[] = ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/ViewAction', 'userInteractionCount' => (int) $page->forum_views];
        if($likes instanceof PageArray) $stats[] = ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/LikeAction', 'userInteractionCount' => $likes->count()];

        $post = [
          '@context' => 'https://schema.org',
          '@type' => 'DiscussionForumPosting',
          '@id' => $page->httpUrl . '#topic',
          'headline' => mb_substr((string) $page->title, 0, 110),
          'url' => $page->httpUrl,
          'mainEntityOfPage' => $m['canonical'],
          'datePublished' => $this->iso((int) $page->created),
          'dateModified' => $this->iso($m['modified']),
          'author' => $this->personLd($this->author($page)),
          'commentCount' => $replyCount,
          'interactionStatistic' => $stats,
          'isPartOf' => $page->parent && $page->parent->id ? ['@type' => 'CollectionPage', 'name' => (string) $page->parent->title, 'url' => $page->parent->httpUrl] : null,
        ];
        if($pageNum === 1) {
          $post['text'] = $this->excerpt($body, 5000);
        } else {
          $post['articleSection'] = (string) $page->parent->title;
        }
        if($page->hasField('forum_tags') && trim((string) $page->forum_tags) !== '') $post['keywords'] = implode(', ', preg_split('/\s+/', trim((string) $page->forum_tags)));
        if($img) $post['image'] = $img;
        if($page->hasField('forum_is_locked') && (int) $page->forum_is_locked) $post['discussionUrl'] = $page->httpUrl;
        if(count($comments)) $post['comment'] = $comments;
        $m['jsonld'][] = array_filter($post, function($v) { return $v !== null && $v !== ''; });
        break;

      case 'forum-user':
        $seg = (string) $input->urlSegment1;
        $u = $seg !== '' ? $this->wire('users')->get('name=' . $this->wire('sanitizer')->pageName($seg) . ', include=all, check_access=0') : null;
        if(!$u || !$u->id) {
          $uid = (int) ($input->get('id') ?: $input->get('u') ?: $input->get('uid') ?: $input->get('user'));
          if($uid) $u = $this->wire('users')->get($uid);
        }
        if($u && $u->id && !$u->isGuest()) {
          $name = $this->forum->displayName($u);
          $m['title'] = $name . ' – profile';
          $m['canonical'] = $this->absUrl($this->forum->userProfileUrl($u));
          $m['type'] = 'profile';
          $posts = 0;
          try { $posts = $this->forum->getUserPostCount($u); } catch(\Throwable $e) {}
          $m['description'] = "{$name} on {$forumName}: {$posts} posts.";
          // Thin profiles (no posts) are not useful in search results
          if($posts < 1) $m['robots'] = 'noindex,follow';
          if(!(int) $this->cfg('allowGuestsProfile', 1)) $m['robots'] = 'noindex,nofollow';
          $m['jsonld'][] = [
            '@context' => 'https://schema.org',
            '@type' => 'ProfilePage',
            'dateCreated' => $this->iso((int) $u->created),
            'mainEntity' => array_merge($this->personLd($u), [
              'identifier' => (string) $u->id,
              'interactionStatistic' => ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/WriteAction', 'userInteractionCount' => $posts],
            ]),
          ];
          $crumbs = [$root, $page];
        } else {
          $m['robots'] = 'noindex,follow';
        }
        break;

      case 'forum-members':
        $m['description'] = "Members of {$forumName}.";
        if($pageNum > 1 || $q !== '') $m['robots'] = 'noindex,follow';
        break;
    }

    if($pageNum > 1 && in_array($tpl, ['forum-topic', 'forum-category'], true)) {
      $m['canonical'] = $page->httpUrl . '?p=' . $pageNum;
      $m['title'] .= ' – page ' . $pageNum;
    }
    if($tpl !== 'forum-root' && $q === '') $m['title'] .= ' | ' . $forumName;

    // Breadcrumbs
    $list = [];
    $pos = 0;
    foreach($crumbs as $c) {
      if(!$c || !$c->id) continue;
      $list[] = ['@type' => 'ListItem', 'position' => ++$pos, 'name' => (string) $c->title, 'item' => $c->httpUrl];
    }
    if(count($list) > 1) {
      $m['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    $m['description'] = $this->excerpt($m['description'], 160);
    if($m['description'] === '') {
      $fd = trim((string) $this->cfg('forumDescription', ''));
      $m['description'] = $this->excerpt($fd !== '' ? $fd : ((string) $page->title . ' – ' . $forumName), 160);
    }
    if(!$m['image']) $m['image'] = (string) $this->cfg('seoDefaultImage', '');
    $m['image'] = $m['image'] !== '' ? $this->absUrl(trim($m['image'])) : '';
    return $m;
  }

  /**
   * Render <head> tags.
   */
  public function headTags(Page $page, array $m, bool $includeTitle = true): string {
    $e = function($s) { return $this->e($s); };
    $out = [];
    if($includeTitle) $out[] = '<title>' . $e($m['title']) . '</title>';
    if($m['description'] !== '') $out[] = "<meta name=\"description\" content=\"{$e($m['description'])}\">";
    $out[] = "<meta name=\"robots\" content=\"{$e($m['robots'])}\">";
    if(strpos($m['robots'], 'noindex') === false) {
      $out[] = "<link rel=\"canonical\" href=\"{$e($m['canonical'])}\">";
      if($m['prev']) $out[] = "<link rel=\"prev\" href=\"{$e($m['prev'])}\">";
      if($m['next']) $out[] = "<link rel=\"next\" href=\"{$e($m['next'])}\">";
    }
    $root = $this->forum->forumRoot();
    if($root && $root->id) {
      $out[] = "<link rel=\"alternate\" type=\"application/rss+xml\" title=\"{$e($this->forumName())}\" href=\"{$e($root->httpUrl . 'feed.xml')}\">";
      if($page->template->name === 'forum-category') {
        $out[] = "<link rel=\"alternate\" type=\"application/rss+xml\" title=\"{$e($page->title)}\" href=\"{$e($root->httpUrl . 'feed.xml?c=' . $page->id)}\">";
      }
    }
    if($page->template->name === 'forum-topic' && $this->forum->cfgOn('seoMarkdown') && strpos($m['robots'], 'noindex') === false) {
      $out[] = "<link rel=\"alternate\" type=\"text/markdown\" href=\"{$e($page->httpUrl . '?format=md')}\">";
    }
    // Open Graph / Twitter
    $out[] = "<meta property=\"og:site_name\" content=\"{$e($this->forumName())}\">";
    $out[] = "<meta property=\"og:type\" content=\"{$e($m['type'])}\">";
    $out[] = "<meta property=\"og:title\" content=\"{$e($m['title'])}\">";
    if($m['description'] !== '') $out[] = "<meta property=\"og:description\" content=\"{$e($m['description'])}\">";
    $out[] = "<meta property=\"og:url\" content=\"{$e($m['canonical'])}\">";
    if($m['image']) $out[] = "<meta property=\"og:image\" content=\"{$e($this->absUrl($m['image']))}\">" . $this->imageSizeTags($this->absUrl($m['image']));
    if($m['published']) $out[] = "<meta property=\"article:published_time\" content=\"{$e($this->iso($m['published']))}\">";
    if($m['modified']) $out[] = "<meta property=\"article:modified_time\" content=\"{$e($this->iso($m['modified']))}\">";
    $out[] = "<meta name=\"twitter:card\" content=\"" . ($m['image'] ? 'summary_large_image' : 'summary') . "\">";
    $out[] = "<meta name=\"twitter:title\" content=\"{$e($m['title'])}\">";
    if($m['description'] !== '') $out[] = "<meta name=\"twitter:description\" content=\"{$e($m['description'])}\">";
    if($m['image']) $out[] = "<meta property=\"og:image:alt\" content=\"{$e($m['title'])}\">";
    foreach($m['jsonld'] as $ld) {
      $json = json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
      if($json) $out[] = "<script type=\"application/ld+json\">{$json}</script>";
    }
    return implode("\n", $out) . "\n";
  }

  /**
   * Turn template output into a complete, valid, SEO-ready document.
   */
  public function processDocument(Page $page, string $html, string $mode): string {
    $m = $this->meta($page);

    if((string) $m['robots'] !== '' && strpos($m['robots'], 'noindex') !== false && !headers_sent()) {
      header('X-Robots-Tag: ' . $m['robots']);
    }

    $hasDoc = stripos($html, '<html') !== false && stripos($html, '</head>') !== false;

    if(!$hasDoc) {
      // Standalone document: move stylesheets + style blocks into <head>
      $headAssets = [];
      $html = preg_replace_callback('~<link\b[^>]*rel=["\']?stylesheet["\']?[^>]*>~i', function($mm) use (&$headAssets) {
        $headAssets[md5($mm[0])] = $mm[0];
        return '';
      }, $html);
      $html = preg_replace_callback('~<style\b[^>]*>.*?</style>~is', function($mm) use (&$headAssets) {
        $headAssets[md5($mm[0])] = $mm[0];
        return '';
      }, $html);

      $lang = preg_replace('/[^a-zA-Z-]/', '', (string) $this->cfg('htmlLang', 'en')) ?: 'en';
      $theme = (string) $this->cfg('frontendTheme', 'simple');
      $headExtra = (string) $this->cfg('standaloneHeadMarkup', '');
      $favicon = (string) $this->cfg('faviconUrl', '');
      $header = (string) $this->cfg('standaloneHeaderMarkup', '');
      $footer = (string) $this->cfg('standaloneFooterMarkup', '');
      $themeColor = sf_sanitize_css_color((string) $this->cfg('themeColor', ''), '');

      $doc = "<!DOCTYPE html>\n<html lang=\"{$lang}\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1, viewport-fit=cover\">\n"
        . "<meta name=\"color-scheme\" content=\"light dark\">\n"
        . ($themeColor ? "<meta name=\"theme-color\" content=\"{$this->e($themeColor)}\">\n" : '')
        . ($favicon ? "<link rel=\"icon\" href=\"{$this->e($favicon)}\">\n" : '')
        . $this->headTags($page, $m)
        . implode("\n", $headAssets) . "\n"
        . $headExtra . "\n"
        . "</head>\n<body class=\"sf-body sf-standalone sf-body-{$this->e($page->template->name)} sf-body-theme-{$this->e($theme)}\">\n"
        . "<a class=\"sf-skip\" href=\"#sf-main\">Skip to content</a>\n"
        . $header . "\n<main id=\"sf-main\">" . $html . "</main>\n" . $footer . "\n</body>\n</html>";
      return $doc;
    }

    // Site layout: inject what is missing into the existing <head>
    $tags = $this->headTags($page, $m, false);
    if((int) $this->cfg('seoOverrideTitle', 1) && preg_match('~<title\b[^>]*>.*?</title>~is', $html)) {
      $html = preg_replace('~<title\b[^>]*>.*?</title>~is', '<title>' . $this->e($m['title']) . '</title>', $html, 1);
    } elseif(!preg_match('~<title\b~i', $html)) {
      $tags = '<title>' . $this->e($m['title']) . "</title>\n" . $tags;
    }
    // Avoid duplicates of tags the site already outputs
    if(preg_match('~<meta[^>]+name=["\']description["\']~i', $html)) $tags = preg_replace('~<meta name="description"[^>]*>\n?~', '', $tags);
    if(preg_match('~<link[^>]+rel=["\']canonical["\']~i', $html)) $tags = preg_replace('~<link rel="canonical"[^>]*>\n?~', '', $tags);
    if(preg_match('~<meta[^>]+name=["\']robots["\']~i', $html)) $tags = preg_replace('~<meta name="robots"[^>]*>\n?~', '', $tags);
    // Social cards: the forum knows the topic (title, text, image) better than the site's generic
    // tags, so the site's Open Graph / Twitter tags are replaced on forum pages. The site's image is
    // kept as fallback when the post has no image and no default forum image is set.
    if(!$m['image'] && preg_match('~<meta[^>]+property=["\']og:image["\'][^>]*content=["\']([^"\']+)~i', $html, $im)) {
      $tags = str_replace('<meta property="og:url"', '<meta property="og:image" content="' . $this->e(html_entity_decode($im[1], ENT_QUOTES)) . "\">\n<meta property=\"og:url\"", $tags);
      $tags = str_replace('<meta name="twitter:card" content="summary">', '<meta name="twitter:card" content="summary_large_image">', $tags);
    }
    $html = preg_replace('~<meta\s[^>]*(property|name)=["\'](og:[^"\']*|twitter:[^"\']*|article:[^"\']*)["\'][^>]*>\s*~i', '', $html);
    if(!preg_match('~<meta[^>]+name=["\']viewport["\']~i', $html)) $tags = "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n" . $tags;
    $tc = function_exists(__NAMESPACE__ . '\\sf_sanitize_css_color') ? sf_sanitize_css_color((string) $this->cfg('themeColor', ''), '') : '';
    if($tc !== '' && !preg_match('~<meta[^>]+name=["\']theme-color["\']~i', $html)) $tags .= "<meta name=\"theme-color\" content=\"" . $this->e($tc) . "\">\n";
    // Put the forum's tags at the TOP of <head> (after charset/viewport): link-preview crawlers
    // (Facebook, WhatsApp…) often read only the first part of a page, and big inline styles or
    // scripts in a site's <head> must not push the Open Graph tags out of reach.
    if(preg_match('~<head\b[^>]*>~i', $html, $hm, PREG_OFFSET_CAPTURE)) {
      $at = $hm[0][1] + strlen($hm[0][0]);
      if(preg_match('~\G(\s*<meta\s[^>]*(charset|name=["\']viewport["\'])[^>]*>)+~i', $html, $cm, 0, $at)) $at += strlen($cm[0]);
      return substr($html, 0, $at) . "\n" . $tags . substr($html, $at);
    }
    $pos = stripos($html, '</head>');
    return substr($html, 0, $pos) . $tags . substr($html, $pos);
  }

  /* ---------------------------------------------------------------- routes */

  /**
   * Handle {forumPath}/sitemap.xml and {forumPath}/feed.xml when the path is the forum root.
   * Returns false (=404) when the path is not ours.
   */
  public function routeIfForum(string $path, string $what) {
    $root = $this->forum->forumRoot();
    if(!$root || !$root->id) return false;
    if('/' . trim($path, '/') . '/' !== $root->path) return false;
    if($what === 'sitemap') return $this->sitemapXml($root);
    if($what === 'feed') return $this->rssFeed($root);
    return false;
  }

  protected function guestVisibleCategories(Page $root): PageArray {
    $guest = $this->wire('users')->getGuestUser();
    $out = new PageArray();
    foreach($this->wire('pages')->find("template=forum-category, has_parent={$root->id}") as $c) {
      if($c->parent->template->name === 'forum-group' && !$this->forum->canReadGroup($guest, $c->parent)) continue;
      if(!$this->forum->canReadCategory($guest, $c)) continue;
      $out->add($c);
    }
    return $out;
  }

  public function sitemapXml(Page $root): string {
    header('Content-Type: application/xml; charset=utf-8');
    $guest = $this->wire('users')->getGuestUser();
    if(!$this->forum->canReadForum($guest)) {
      return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    }
    $cache = $this->wire('cache');
    $key = 'NativeForum-sitemap';
    $xml = $cache->getFor('NativeForumView', $key);
    if($xml) return $xml;

    $e = function($s) { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
    $rows = [];
    $rows[] = "<url><loc>{$e($root->httpUrl)}</loc><lastmod>" . date('c', (int) $root->modified) . "</lastmod></url>";
    $cats = $this->guestVisibleCategories($root);
    $groups = [];
    foreach($cats as $c) {
      if($c->parent->template->name === 'forum-group') $groups[$c->parent->id] = $c->parent;
    }
    foreach($groups as $g) $rows[] = "<url><loc>{$e($g->httpUrl)}</loc></url>";
    $per = $this->forum->postsPerPage();
    if(count($cats)) {
      $catIds = implode('|', $cats->explode('id'));
      foreach($cats as $c) $rows[] = "<url><loc>{$e($c->httpUrl)}</loc><lastmod>" . date('c', (int) $c->modified) . "</lastmod></url>";
      foreach($this->wire('pages')->findMany("template=forum-topic, parent=$catIds, sort=-modified, limit=45000") as $t) {
        $rows[] = "<url><loc>{$e($t->httpUrl)}</loc><lastmod>" . date('c', (int) $t->modified) . "</lastmod></url>";
        $n = (int) $t->numChildren('template=forum-reply') + 1;
        $pagesTotal = (int) ceil($n / $per);
        for($i = 2; $i <= min($pagesTotal, 50); $i++) $rows[] = "<url><loc>{$e($t->httpUrl . '?p=' . $i)}</loc></url>";
      }
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n" . implode("\n", $rows) . "\n</urlset>";
    $cache->saveFor('NativeForumView', $key, $xml, 3600);
    return $xml;
  }

  public function rssFeed(Page $root): string {
    header('Content-Type: application/rss+xml; charset=utf-8');
    $guest = $this->wire('users')->getGuestUser();
    $e = function($s) { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
    $catId = (int) $this->wire('input')->get('c');
    $cats = $this->guestVisibleCategories($root);
    $title = $this->forumName();
    $link = $root->httpUrl;
    if($catId) {
      $c = $cats->get("id=$catId");
      $cats = new PageArray();
      if($c && $c->id) { $cats->add($c); $title = $c->title . ' | ' . $title; $link = $c->httpUrl; }
    }
    $items = [];
    if($this->forum->canReadForum($guest) && count($cats)) {
      $ids = implode('|', $cats->explode('id'));
      foreach($this->wire('pages')->find("template=forum-topic, parent=$ids, sort=-created, limit=30") as $t) {
        $a = $this->author($t);
        $items[] = "<item><title>{$e($t->title)}</title><link>{$e($t->httpUrl)}</link><guid isPermaLink=\"true\">{$e($t->httpUrl)}</guid>"
          . "<pubDate>" . date(DATE_RSS, (int) $t->created) . "</pubDate>"
          . "<dc:creator>{$e($a ? $this->forum->displayName($a) : 'Guest')}</dc:creator>"
          . "<category>{$e($t->parent->title)}</category>"
          . "<description>{$e($this->excerpt((string) $t->getUnformatted('forum_body'), 400))}</description></item>";
      }
    }
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
      . '<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
      . "<title>{$e($title)}</title><link>{$e($link)}</link><description>{$e($this->cfg('forumDescription', $title))}</description>"
      . "<atom:link href=\"{$e($root->httpUrl . 'feed.xml' . ($catId ? '?c=' . $catId : ''))}\" rel=\"self\" type=\"application/rss+xml\"/>"
      . implode('', $items) . '</channel></rss>';
  }

  /**
   * /llms.txt — a concise map of the forum for LLM crawlers (llmstxt.org format).
   */
  public function llmsTxt() {
    $root = $this->forum->forumRoot();
    if(!$root || !$root->id) return false;
    header('Content-Type: text/plain; charset=utf-8');
    $guest = $this->wire('users')->getGuestUser();
    $cache = $this->wire('cache');
    $txt = $cache->getFor('NativeForumView', 'NativeForum-llms');
    if($txt) return $txt;
    $lines = [];
    $lines[] = '# ' . $this->forumName();
    $lines[] = '';
    $lines[] = '> ' . ((string) $this->cfg('forumDescription', '') ?: 'Community discussion forum.');
    $lines[] = '';
    $lines[] = 'Every topic is available as clean Markdown by appending `?format=md` to its URL. RSS: ' . $root->httpUrl . 'feed.xml . Sitemap: ' . $root->httpUrl . 'sitemap.xml';
    if($this->forum->canReadForum($guest)) {
      $cats = $this->guestVisibleCategories($root);
      foreach($cats as $c) {
        $lines[] = '';
        $lines[] = '## ' . $c->title;
        $sum = $this->summaryOf($c);
        if($sum) { $lines[] = ''; $lines[] = $sum; }
        $lines[] = '';
        foreach($this->wire('pages')->find("template=forum-topic, parent={$c->id}, sort=-modified, limit=25") as $t) {
          $desc = $this->summaryOf($t) ?: $this->excerpt((string) $t->getUnformatted('forum_body'), 120);
          $lines[] = '- [' . str_replace(['[', ']'], '', (string) $t->title) . '](' . $t->httpUrl . '?format=md)' . ($desc ? ': ' . $desc : '');
        }
      }
    }
    $txt = implode("\n", $lines) . "\n";
    $cache->saveFor('NativeForumView', 'NativeForum-llms', $txt, 3600);
    return $txt;
  }

  /**
   * Clean Markdown rendering of a whole topic (GEO: easy to quote/cite by AI assistants).
   */
  public function topicMarkdown(Page $topic): string {
    $md = [];
    $md[] = '# ' . $topic->title;
    $md[] = '';
    $a = $this->author($topic);
    $md[] = '- URL: ' . $topic->httpUrl;
    $md[] = '- Category: ' . $topic->parent->title;
    $md[] = '- Author: ' . ($a ? $this->forum->displayName($a) : 'Guest');
    $md[] = '- Published: ' . date('Y-m-d H:i', (int) $topic->created);
    if($topic->hasField('forum_tags') && trim((string) $topic->forum_tags) !== '') $md[] = '- Tags: ' . trim((string) $topic->forum_tags);
    $md[] = '';
    $md[] = $this->htmlToMarkdown((string) $topic->getUnformatted('forum_body'));
    $n = 1;
    foreach($this->wire('pages')->findMany("template=forum-reply, parent={$topic->id}, sort=created, sort=id") as $r) {
      if($r->hasField('forum_is_deleted') && (int) $r->forum_is_deleted) continue;
      $n++;
      $ra = $this->author($r);
      $md[] = '';
      $md[] = "## Reply #{$n} — " . ($ra ? $this->forum->displayName($ra) : 'Guest') . ', ' . date('Y-m-d H:i', (int) $r->created);
      $md[] = '';
      $md[] = $this->htmlToMarkdown((string) $r->getUnformatted('forum_body'));
    }
    return implode("\n", $md) . "\n";
  }

  public function htmlToMarkdown(string $html): string {
    $h = preg_replace('~\[\[sf-(img|file|embed)[^\]]*\]\]~i', '', $html);
    $h = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $h);
    $h = preg_replace_callback('~<pre\b[^>]*>(.*?)</pre>~is', function($m) {
      return "\n```\n" . html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8') . "\n```\n";
    }, $h);
    $h = preg_replace('~<code\b[^>]*>(.*?)</code>~is', '`$1`', $h);
    $h = preg_replace('~<(strong|b)\b[^>]*>(.*?)</\1>~is', '**$2**', $h);
    $h = preg_replace('~<(em|i)\b[^>]*>(.*?)</\1>~is', '*$2*', $h);
    $h = preg_replace('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', '[$2]($1)', $h);
    $h = preg_replace('~<img\b[^>]*src=["\']([^"\']+)["\'][^>]*>~i', '![]($1)', $h);
    $h = preg_replace_callback('~<blockquote\b[^>]*>(.*?)</blockquote>~is', function($m) {
      $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
      return "\n> " . str_replace("\n", "\n> ", $t) . "\n";
    }, $h);
    $h = preg_replace('~<h([1-6])\b[^>]*>(.*?)</h\1>~is', "\n### $2\n", $h);
    $h = preg_replace('~<li\b[^>]*>~i', "\n- ", $h);
    $h = preg_replace('~<br\s*/?>~i', "\n", $h);
    $h = preg_replace('~</(p|div|ul|ol)>~i', "\n\n", $h);
    $h = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $h = preg_replace("/[ \t]+\n/", "\n", $h);
    return trim(preg_replace("/\n{3,}/", "\n\n", $h));
  }
}
