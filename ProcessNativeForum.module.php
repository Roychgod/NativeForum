<?php namespace ProcessWire;

class ProcessNativeForum extends Process implements Module {

  const PAGE_NAME = 'native-forum';

  public static function getModuleInfo() {
    return [
      'title' => 'Native Forum Admin',
      'version' => 100,
      'summary' => 'Admin for Native Forum: dashboard, reports, groups & categories, topics, messages, members, settings and export/import.',
      'author' => 'Gregor Rojko (Pyxios)',
      'href' => 'https://processwire.com/modules/native-forum/',
      'icon' => 'comments',
      'requires' => ['ProcessWire>=3.0.200', 'NativeForum'],
      'permissions' => ['native-forum'],
      'permission' => 'native-forum',
      'singular' => true,
      'nav' => [
        ['url' => '', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['url' => 'reports', 'label' => 'Reports', 'icon' => 'flag'],
        ['url' => 'groups', 'label' => 'Groups/Categories'],
        ['url' => 'topics', 'label' => 'Topics'],
        ['url' => 'messages', 'label' => 'Messages'],
        ['url' => 'members', 'label' => 'Members'],
        ['url' => 'settings', 'label' => 'Settings'],
        ['url' => 'transfer', 'label' => 'Export / Import', 'icon' => 'exchange'],
      ],
    ];
  }

  public function ___install() {
    $pages = $this->wire('pages');
    $permissions = $this->wire('permissions');
    $config = $this->wire('config');
    $modules = $this->wire('modules');

    // Ensure Native Forum is installed (will create fields/templates/pages)
    $modules->get('NativeForum');

    // Ensure permission exists (do NOT modify any roles automatically)
    $perm = $permissions->get('native-forum');
    if(!$perm || !$perm->id) {
      $perm = new Permission();
      $perm->name = 'native-forum';
      $perm->title = 'Access Native Forum admin';
      $permissions->save($perm);
    }

    // Create Admin page (Process page) – or take over the one of the former SimpleForum admin
    $page = $pages->get('template=admin, name=' . self::PAGE_NAME . ', include=all');
    if(!$page->id) $page = $pages->get('template=admin, name=simple-forum, include=all');
    if($page->id) {
      $page->of(false);
      $page->name = self::PAGE_NAME;
      $page->title = 'Native Forum';
      $page->process = $this;
      $page->save();
    } else {
      $page = new Page();
      $page->template = 'admin';
      $page->parent = $pages->get($config->adminRootPageID);
      $page->title = 'Native Forum';
      $page->name = self::PAGE_NAME;
      $page->process = $this;
      $page->save();
    }

    // Copy frontend template files + CSS from module/template-files to /site/templates
    $this->copyFrontendFiles(true);
  }

  public function ___uninstall() {
    $pages = $this->wire('pages');
    $permissions = $this->wire('permissions');

    // Delete admin page
    $page = $pages->get('template=admin, name=' . self::PAGE_NAME);
    if($page->id) {
      $this->message("Deleted admin page: {$page->path}");
      $page->delete();
    }

    // Remove permission (non-destructive in terms of data)
    $perm = $permissions->get('native-forum');
    if($perm && $perm->id) {
      $permissions->delete($perm);
      $this->message('Deleted permission: native-forum');
    }
  }

  public function upgrade($fromVersion, $toVersion) {
    // On module upgrade, refresh frontend templates/CSS
    $this->copyFrontendFiles(true);
  }

  protected function copyFrontendFiles(bool $overwrite = false) {
    $config = $this->wire('config');

    $sourcepath = dirname(__FILE__) . '/template-files/';
    $dest = $config->paths->templates;

    $files = [
'forum-root.php',
      'forum-layout.php',
      'forum-category.php',
      'forum-topic.php',
      'forum-reply.php',
      'forum-messages-root.php',
      'forum-message.php',
      'forum-profile.php',
      'forum-login.php',
      'forum-register.php',
      'native-forum.css',
      'native-forum-bootstrap.css',
    ];

    foreach($files as $file) {
      if(!is_file($sourcepath . $file)) continue;
      if(is_file($dest . $file) && !$overwrite) continue; // do not overwrite user's files unless forced
      copy($sourcepath . $file, $dest . $file);
    }
  }

  /**
   * Return shared admin CSS for NativeForum admin pages.
   * Returns the <style> tag only on the first call per request to avoid duplication.
   */
  protected function sfAdminCss(): string {
    static $emitted = false;
    if($emitted) return '';
    $emitted = true;
    $css = <<<'CSS'
:root{--sfa-accent:var(--pw-main-color,#e83561);--sfa-surface:rgba(127,127,127,.05);--sfa-surface-2:rgba(127,127,127,.10);--sfa-border:rgba(127,127,127,.25);--sfa-radius:10px;--sfa-ok:#2fb344;--sfa-warn:#f59f00;--sfa-danger:#e03131;--sfa-info:#1c7ed6}
.nativeforum-admin{max-width:none!important;width:100%;color:inherit}
.nativeforum-admin *{box-sizing:border-box}
.nativeforum-admin .sf-muted,.nativeforum-admin .sfa-muted,.nativeforum-admin small{opacity:.72}
.nativeforum-admin h2{margin:0 0 4px;font-size:1.45em;font-weight:600}
.nativeforum-admin h3{margin:0 0 10px;font-size:1.05em;font-weight:600;border:0;padding:0}
.nativeforum-admin h4{margin:0 0 8px;font-size:.78em;font-weight:700;letter-spacing:.07em;text-transform:uppercase;opacity:.7}
.sfa-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 18px}
.sfa-head p{margin:2px 0 0;opacity:.72}
.sfa-head .sfa-actions{display:flex;gap:8px;flex-wrap:wrap}
.sfa-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin:0 0 18px}
.sfa-tile{display:flex;align-items:center;gap:12px;padding:14px 16px;border:1px solid var(--sfa-border);border-radius:var(--sfa-radius);background:var(--sfa-surface);color:inherit!important;text-decoration:none!important;transition:border-color .12s,transform .12s}
a.sfa-tile:hover{border-color:var(--sfa-accent);transform:translateY(-1px)}
.sfa-tile .fa{font-size:20px;width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--sfa-surface-2);color:var(--sfa-accent)}
.sfa-tile b{display:block;font-size:1.5em;line-height:1.1;font-weight:700}
.sfa-tile span{font-size:.82em;opacity:.72}
.sfa-tile.is-alert{border-color:var(--sfa-danger)}
.sfa-tile.is-alert .fa{background:var(--sfa-danger);color:#fff}
.sfa-grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));align-items:start;margin:0 0 16px}
.sfa-grid-3{grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}
.sfa-card,.sfa-panel{background:var(--sfa-surface);border:1px solid var(--sfa-border);border-radius:var(--sfa-radius);padding:16px 18px;margin:0 0 16px;min-width:0}
.sfa-grid>.sfa-card,.sfa-grid>.sfa-panel{margin:0}
.sfa-panel-h{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:-2px 0 12px}
.sfa-panel-h h3{margin:0;display:flex;align-items:center;gap:8px}
.sfa-panel-h h3 .fa{color:var(--sfa-accent)}
.sfa-panel-h a{font-size:.88em}
.sfa-flex{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));margin:0 0 16px}
.sf-sortable li.sf-sort-placeholder{visibility:visible!important;border:2px dashed var(--sfa-accent);background:transparent;min-height:40px}
.sfa-col{min-width:0}
.sfa-flex>.sfa-col.sfa-card{margin:0}
.nativeforum-admin table.AdminDataTable,.nativeforum-admin table.sfa-table{width:100%;border-collapse:separate;border-spacing:0;margin:0;background:transparent;border:1px solid var(--sfa-border);border-radius:8px;overflow:hidden}
.nativeforum-admin table.AdminDataTable th,.nativeforum-admin table.sfa-table th{padding:9px 12px;background:var(--sfa-surface-2);border-bottom:1px solid var(--sfa-border);text-align:left;font-weight:700;font-size:.72em;text-transform:uppercase;letter-spacing:.06em;opacity:.85;white-space:nowrap}
.nativeforum-admin table.AdminDataTable td,.nativeforum-admin table.sfa-table td{padding:9px 12px;border-bottom:1px solid var(--sfa-border);text-align:left;vertical-align:middle}
.nativeforum-admin table.AdminDataTable tbody tr:last-child td,.nativeforum-admin table.sfa-table tbody tr:last-child td{border-bottom:0}
.nativeforum-admin table.AdminDataTable tbody tr:nth-child(even){background:transparent}
.nativeforum-admin table.AdminDataTable tbody tr:hover,.nativeforum-admin table.sfa-table tbody tr:hover{background:var(--sfa-surface)}
.sfa-table td.sfa-nowrap,.sfa-nowrap{white-space:nowrap}
.sfa-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:.75em;font-weight:700;background:var(--sfa-surface-2);white-space:nowrap;line-height:1.6}
.sfa-badge.is-red{background:var(--sfa-danger);color:#fff}.sfa-badge.is-green{background:var(--sfa-ok);color:#fff}.sfa-badge.is-amber{background:var(--sfa-warn);color:#111}.sfa-badge.is-blue{background:var(--sfa-info);color:#fff}.sfa-badge.is-accent{background:var(--sfa-accent);color:#fff}
.sfa-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border-radius:7px;border:1px solid var(--sfa-border);background:var(--sfa-surface-2);color:inherit!important;font:inherit;font-size:.86em;font-weight:600;line-height:1.3;cursor:pointer;text-decoration:none!important;white-space:nowrap}
.sfa-btn:hover{border-color:var(--sfa-accent)}
.sfa-btn.is-primary{background:var(--sfa-accent);border-color:var(--sfa-accent);color:#fff!important}
.sfa-btn.is-danger:hover{border-color:var(--sfa-danger);color:var(--sfa-danger)!important}
.sfa-btn.is-ok:hover{border-color:var(--sfa-ok);color:var(--sfa-ok)!important}
.sfa-btns{display:flex;gap:6px;flex-wrap:wrap}
.sfa-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 14px;padding:10px 12px;background:var(--sfa-surface);border:1px solid var(--sfa-border);border-radius:var(--sfa-radius)}
.sfa-toolbar input[type=search],.sfa-toolbar select{min-width:220px}
.sfa-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px}
.sfa-tabs a{padding:6px 12px;border-radius:999px;border:1px solid var(--sfa-border);text-decoration:none!important;color:inherit!important;font-size:.88em;font-weight:600}
.sfa-tabs a.is-active{background:var(--sfa-accent);border-color:var(--sfa-accent);color:#fff!important}
.sfa-empty{padding:22px;text-align:center;opacity:.75;border:1px dashed var(--sfa-border);border-radius:var(--sfa-radius);margin:0}
.sfa-avatar{width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:var(--sfa-surface-2);font-weight:700;font-size:.85em;flex:0 0 auto;overflow:hidden}
.sfa-avatar img{width:100%;height:100%;object-fit:cover}
.sfa-user{display:flex;align-items:center;gap:10px}
.sfa-excerpt{opacity:.8;font-size:.9em;margin-top:3px;max-width:60ch;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.sfa-quick{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.sfa-quick a{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;border:1px solid var(--sfa-border);text-decoration:none!important;color:inherit!important}
.sfa-quick a:hover{border-color:var(--sfa-accent)}
.sfa-quick .fa{color:var(--sfa-accent);width:18px;text-align:center}
/* sortable lists (groups, categories, topics) */
.sf-sortable{list-style:none;margin:0;padding:8px;min-height:56px;border:1px dashed var(--sfa-border);border-radius:var(--sfa-radius);background:transparent}
.sf-sortable li{list-style:none;margin:0 0 6px;padding:9px 12px;border:1px solid var(--sfa-border);border-radius:8px;background:var(--sfa-surface);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.sf-sortable li:last-child{margin-bottom:0}
.sf-sortable li.ui-sortable-helper{box-shadow:0 10px 24px rgba(0,0,0,.25);border-color:var(--sfa-accent)}
.sf-sortable li.ui-sortable-placeholder{visibility:visible!important;border:2px dashed var(--sfa-accent);background:transparent}
.sf-sortable li.sf-drop-hint{opacity:.65;border-style:dashed;background:transparent;justify-content:center;font-size:.9em}
.sf-title{display:flex;align-items:center;gap:10px;min-width:0;flex:1 1 220px}
.sf-title strong{font-weight:600}
.sf-drag{cursor:grab;opacity:.55;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;border-radius:5px;flex:0 0 auto}
.sf-drag:hover{opacity:1;background:var(--sfa-surface-2)}
.sf-drag:active{cursor:grabbing}
.sf-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.sf-actions label{display:inline-flex!important;align-items:center;gap:5px;margin:0!important;padding:3px 9px;border:1px solid var(--sfa-border);border-radius:999px;font-size:.78em;font-weight:600;cursor:pointer;user-select:none;white-space:nowrap}
.sf-actions label:has(input:checked){background:var(--sfa-accent);border-color:var(--sfa-accent);color:#fff}
.sf-actions label input{margin:0!important;accent-color:#fff}
.sf-actions select{max-width:240px;font-size:.88em}
.sf-actions .pw-button-small,.sf-actions .sf-edit,.sf-actions .sf-edit-link{padding:4px 10px!important;font-size:.82em!important;border-radius:7px!important;margin:0!important}
.sfa-catgrid{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(330px,1fr))}
.sfa-catgrid h4 .sfa-badge{margin-left:6px}
/* modal */
.sf-admin-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;display:none}
.sf-admin-modal{position:fixed;inset:0;display:none;z-index:10001;align-items:center;justify-content:center;padding:20px}
.sf-admin-modal .box{background:var(--sfa-modal-bg,#fff);color:inherit;border-radius:12px;max-width:820px;width:100%;max-height:88vh;overflow:auto;border:1px solid var(--sfa-border);box-shadow:0 20px 60px rgba(0,0,0,.35)}
.sf-admin-modal .head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--sfa-border)}
.sf-admin-modal .body{padding:16px}
/* forms inside admin pages */
.nativeforum-admin form p{margin:0 0 12px}
.nativeforum-admin form label{font-weight:600;font-size:.92em}
.nativeforum-admin input.pw-input-text,.nativeforum-admin select.pw-input,.nativeforum-admin .sfa-card input[type=text],.nativeforum-admin .sfa-card select{display:block;width:100%;margin-top:5px;padding:8px 10px;border:1px solid var(--sfa-border);border-radius:7px;background:rgba(255,255,255,.04);color:inherit;font:inherit;font-weight:400;min-height:38px}
.nativeforum-admin form label input[type=checkbox]{margin-right:6px;accent-color:var(--sfa-accent)}
.nativeforum-admin .sf-actions select.pw-input{display:inline-block;width:auto;margin:0;min-height:30px;padding:4px 8px}
.sfa-grid-msg{grid-template-columns:minmax(300px,1fr) minmax(420px,2fr)}
.nativeforum-admin .sfa-panel .InputfieldContent select,.nativeforum-admin .sfa-panel select.uk-select{width:100%;max-width:100%}
.nativeforum-admin select,.nativeforum-admin input[type=text],.nativeforum-admin input[type=search]{max-width:100%}
/* native dropdown lists follow the admin theme (Konkat dark: no white-on-white options) */
.nativeforum-admin select,.sf-admin-modal select{color-scheme:var(--sfa-scheme,normal)}
.nativeforum-admin select option,.nativeforum-admin select optgroup,.sf-admin-modal select option,.sf-admin-modal select optgroup{background-color:var(--sfa-modal-bg,#fff);color:var(--sfa-fg,#1f2328)}
@media (max-width:980px){.sfa-grid-msg{grid-template-columns:1fr}}
@media (max-width:640px){
.sfa-grid,.sfa-flex,.sfa-grid-msg,.sfa-catgrid{grid-template-columns:1fr}
.sfa-tiles{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.sfa-head{flex-wrap:wrap}.sfa-head .sfa-actions{width:100%;justify-content:flex-start;flex-wrap:wrap}
.nativeforum-admin table.sfa-table,.nativeforum-admin table.sfa-table tbody,.nativeforum-admin table.sfa-table tr,.nativeforum-admin table.sfa-table td{display:block;width:100%;box-sizing:border-box}
.nativeforum-admin table.sfa-table thead{display:none}
.nativeforum-admin table.sfa-table tr{display:flex!important;flex-wrap:wrap;align-items:center;gap:4px 2px;padding:10px 4px;border-bottom:1px solid var(--sfa-border)}
.nativeforum-admin table.sfa-table td{width:auto!important}.nativeforum-admin table.sfa-table td:first-child{flex:1 1 100%}
.nativeforum-admin table.sfa-table tbody tr:last-child{border-bottom:0}
.nativeforum-admin table.sfa-table td{border:0!important;padding:3px 10px!important;white-space:normal}
.nativeforum-admin table.sfa-table td:empty{display:none}
.nativeforum-admin table.sfa-table td.sfa-nowrap{font-size:.88em;opacity:.85}
.nativeforum-admin table.sfa-table .sfa-btns{flex-wrap:wrap;justify-content:flex-start}
.sfa-panel,.sfa-card{padding-left:12px!important;padding-right:12px!important}
.sf-sortable li{flex-wrap:wrap}.sf-actions{flex-wrap:wrap;justify-content:flex-start!important}
.sfa-edit-topic .sfa-pager .sf-muted{flex-basis:100%;margin:6px 0 0}
}
.sfa-title-link{color:inherit!important;text-decoration:none!important}.sfa-title-link:hover strong{color:var(--sfa-accent)}
.sf-actions .sf-edit-link{display:inline-flex;align-items:center;gap:5px;text-decoration:none!important}
.sfa-edit-topic .sfa-pager{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.sfa-edit-topic .sfa-pager .sf-muted{margin-left:8px;font-size:.85em}
.sfa-edit-topic .sfa-reply-fs>.InputfieldHeader{font-weight:600}
.sfa-edit-topic .sfa-reply-fs .InputfieldContent>.description,.sfa-edit-topic .sfa-reply-fs>.InputfieldContent>p.description{opacity:.75}
.sfa-edit-topic .sfa-danger-check label,.sfa-edit-topic .sfa-danger-check .pw-no-select{color:var(--sfa-danger)}
.sfa-edit-topic .InputfieldMarkup .sfa-btn{margin-right:6px}
CSS;
    return '<script>window.sfaReload=function(){try{sessionStorage.setItem("sfa-y:"+location.pathname+location.search,String(window.scrollY));}catch(e){}location.reload();};(function(){function r(){try{var k="sfa-y:"+location.pathname+location.search,y=sessionStorage.getItem(k);if(y!==null){sessionStorage.removeItem(k);window.scrollTo(0,+y);setTimeout(function(){window.scrollTo(0,+y);},300);}}catch(e){}}if(document.readyState!=="loading")r();else document.addEventListener("DOMContentLoaded",r);})();(function(){function bg(e){while(e){var c=getComputedStyle(e).backgroundColor;if(c&&c!=="transparent"&&!/rgba\\(.*,\\s*0\\)$/.test(c))return c;e=e.parentElement;}return "";}function set(){var c=bg(document.getElementById("pw-content-body")||document.querySelector("#pw-content,main")||document.body);if(c){var r=document.documentElement.style;r.setProperty("--sfa-modal-bg",c);var m=c.match(/[\\d.]+/g)||[255,255,255];var l=(0.299*m[0]+0.587*m[1]+0.114*m[2])/255;r.setProperty("--sfa-scheme",l<0.5?"dark":"light");r.setProperty("--sfa-fg",l<0.5?"#e9ecef":"#1f2328");}}if(document.readyState!=="loading")set();else document.addEventListener("DOMContentLoaded",set);})();</script><style>' . $css . '</style>';
  }

  /** Page header with icon, description and optional action buttons. */
  protected function sfaHead(string $title, string $icon, string $desc = '', string $actions = ''): string {
    return '<div class="sfa-head"><div><h2><i class="fa fa-fw fa-' . $icon . '"></i> ' . $this->sanitizer->entities($title) . '</h2>'
      . ($desc !== '' ? '<p>' . $desc . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="sfa-actions">' . $actions . '</div>' : '') . '</div>';
  }

  /************************************************************
   * NAV: Dashboard (default execute)
   ************************************************************/
  public function execute() {
    $modules = $this->wire('modules');
    /** @var NativeForum $forum */
    $forum = $modules->get('NativeForum');
    $pages = $this->wire('pages');
    $config = $this->wire('config');
    $users  = $this->wire('users');
    $san = $this->sanitizer;
    $admin = $this->page->url;

    // demo content: add / start empty / remove
    $input = $this->wire('input');
    if($input->post('sfa_demo') && method_exists($forum, 'addDemoContent')) {
      $this->wire('session')->CSRF->validate();
      try {
        $act = (string) $input->post('sfa_demo');
        if($act === 'add') { $r = $forum->addDemoContent(); $this->message('Demo content added: ' . count($r['pages'] ?? []) . ' pages and ' . count($r['users'] ?? []) . ' demo members. Have a look at the forum!'); }
        elseif($act === 'skip') { $forum->skipDemoContent(); $this->message('OK – starting with an empty forum. Create your first group and category under Groups/Categories.'); }
        elseif($act === 'remove') { $n = $forum->removeDemoContent(); $this->message("Demo content removed ($n items)."); }
      } catch(\Throwable $e) { $this->error($e->getMessage()); }
      $this->wire('session')->redirect($this->page->url);
    }

    $forumRoot    = $forum->forumRoot();
    $messagesRoot = $forum->messagesRoot();
    $rootId  = ($forumRoot && $forumRoot->id) ? (int) $forumRoot->id : 0;
    $msgRootId = ($messagesRoot && $messagesRoot->id) ? (int) $messagesRoot->id : 0;

    $nCats    = $rootId ? (int) $pages->count("template=forum-category, has_parent=$rootId, include=all") : 0;
    $nTopics  = $rootId ? (int) $pages->count("template=forum-topic, has_parent=$rootId, include=all") : 0;
    $nReplies = $rootId ? (int) $pages->count("template=forum-reply, has_parent=$rootId, include=all") : 0;
    $nMembers = (int) $users->count('id>0, name!=guest, include=all, check_access=0');
    $nUnread  = $msgRootId ? (int) $pages->count("template=forum-message, has_parent=$msgRootId, forum_is_read=0, include=all") : 0;
    $nReports = method_exists($forum, 'openReportCount') ? $forum->openReportCount() : 0;
    $day = time() - 86400;
    $nToday = $rootId ? (int) $pages->count("template=forum-topic|forum-reply, has_parent=$rootId, created>$day, include=all") : 0;

    $out = $this->sfAdminCss() . '<div class="nativeforum-admin">';
    $out .= $this->sfaHead('Dashboard', 'dashboard', 'Overview of your forum.',
      ($forumRoot && $forumRoot->id ? '<a class="sfa-btn" href="' . $forumRoot->url . '" target="_blank"><i class="fa fa-external-link"></i> View forum</a>' : '')
      . '<a class="sfa-btn" href="' . $admin . 'settings/"><i class="fa fa-cog"></i> Settings</a>');

    // Welcome: offer demo content once; afterwards a small "remove demo" bar while it exists
    if(method_exists($forum, 'demoContentInfo')) {
      $tok = $this->wire('session')->CSRF->renderInput();
      $demo = $forum->demoContentInfo();
      $btn = function($act, $icon, $label, $cls = '') use ($tok) {
        return '<form method="post" style="display:inline">' . $tok . '<button type="submit" name="sfa_demo" value="' . $act . '" class="sfa-btn' . ($cls ? ' ' . $cls : '') . '"' . ($act === 'remove' ? ' onclick="return confirm(\'Remove the demo groups, topics, replies and demo members?\')"' : '') . '><i class="fa fa-' . $icon . '"></i> ' . $label . '</button></form> ';
      };
      if($demo) {
        $out .= '<div class="sfa-panel sfa-demo-bar" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">'
          . '<div><i class="fa fa-magic"></i> <strong>Demo content is on your forum</strong> <span class="sf-muted">– ' . count($demo['pages'] ?? []) . ' pages, ' . count($demo['users'] ?? []) . ' demo members. Edit it or remove it when you are ready.</span></div>'
          . '<div>' . $btn('remove', 'trash', 'Remove demo content', 'is-danger') . '</div></div>';
      } elseif(!$forum->demoChoiceMade() && !$nTopics) {
        $out .= '<div class="sfa-panel sfa-welcome" style="border-left:4px solid var(--sfa-accent);">'
          . '<h3 style="margin:0 0 6px"><i class="fa fa-hand-peace-o"></i> Welcome to Native Forum!</h3>'
          . '<p style="margin:0 0 12px">Want to see how a living forum looks? Add a little demo content: a <em>Community</em> group with 3 categories, '
          . '4 topics (incl. pinned <em>Forum rules</em>), a few replies and 2 demo members. You can edit it – or remove it with one click.</p>'
          . $btn('add', 'magic', 'Add demo content', 'is-primary') . $btn('skip', 'square-o', 'Start with an empty forum')
          . '</div>';
      }
    }

    // KPI tiles
    $tile = function($icon, $n, $label, $url = '', $alert = false) {
      $tag = $url ? 'a href="' . $url . '"' : 'div';
      $end = $url ? 'a' : 'div';
      return '<' . $tag . ' class="sfa-tile' . ($alert ? ' is-alert' : '') . '"><i class="fa fa-' . $icon . '"></i><div><b>' . number_format($n, 0, ',', '.') . '</b><span>' . $label . '</span></div></' . $end . '>';
    };
    $out .= '<div class="sfa-tiles">'
      . $tile('flag', $nReports, 'Open reports', $admin . 'reports/', $nReports > 0)
      . $tile('comments', $nTopics, 'Topics', $admin . 'topics/')
      . $tile('comment-o', $nReplies, 'Replies')
      . $tile('bolt', $nToday, 'Posts in 24 h')
      . $tile('folder-o', $nCats, 'Categories', $admin . 'groups/')
      . $tile('users', $nMembers, 'Members', $admin . 'members/')
      . $tile('envelope-o', $nUnread, 'Unread messages', $admin . 'messages/')
      . '</div>';

    // Reports block
    $out .= '<div class="sfa-panel"><div class="sfa-panel-h"><h3><i class="fa fa-flag"></i> Reports to review</h3><a href="' . $admin . 'reports/">All reports &rarr;</a></div>';
    $out .= $this->sfaReportsTable($forum, 'open', 5, true);
    $out .= '</div>';

    $out .= '<div class="sfa-grid">';
    // Latest posts
    $latest = $rootId ? $pages->find("template=forum-topic|forum-reply, has_parent=$rootId, include=all, sort=-created, limit=8") : new PageArray();
    $out .= '<div class="sfa-panel"><div class="sfa-panel-h"><h3><i class="fa fa-comments"></i> Latest posts</h3><a href="' . $admin . 'topics/">Topics &rarr;</a></div>';
    if(!$latest->count()) {
      $out .= '<p class="sfa-empty">No posts yet.</p>';
    } else {
      $out .= '<table class="sfa-table"><thead><tr><th>Post</th><th>Author</th><th>Date</th></tr></thead><tbody>';
      foreach($latest as $p) {
        $t = $p->template->name === 'forum-topic' ? $p : $p->parent;
        $a = $p->getUnformatted('forum_author'); if($a instanceof PageArray) $a = $a->first();
        $url = $p->template->name === 'forum-reply' && method_exists($forum, 'replyUrl') ? $forum->replyUrl($p) : $p->url;
        $out .= '<tr><td>' . ($p->template->name === 'forum-topic' ? '<span class="sfa-badge is-blue">topic</span> ' : '<span class="sfa-badge">reply</span> ')
          . '<a href="' . $url . '" target="_blank"><strong>' . $san->entities($t ? $t->title : $p->title) . '</strong></a>'
          . ($p->isUnpublished() ? ' <span class="sfa-badge is-amber">unpublished</span>' : '')
          . '<div class="sfa-excerpt">' . $san->entities(mb_strimwidth(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $p->getUnformatted('forum_body')))), 0, 120, '…')) . '</div></td>'
          . '<td>' . $this->sfaUserCell($a instanceof User ? $a : null) . '</td><td class="sfa-nowrap">' . date('Y-m-d H:i', $p->created)
          . ($t && $t->id ? ' <a class="sfa-btn" style="margin-left:6px" href="' . $admin . 'edit-topic/?id=' . (int) $t->id . ($p->template->name === 'forum-reply' ? '&reply=' . (int) $p->id : '') . '" title="Edit"><i class="fa fa-pencil"></i></a>' : '') . '</td></tr>';
      }
      $out .= '</tbody></table>';
    }
    $out .= '</div>';

    // Right column: quick links + newest members + messages
    $out .= '<div>';
    $out .= '<div class="sfa-panel"><div class="sfa-panel-h"><h3><i class="fa fa-link"></i> Quick links</h3></div><ul class="sfa-quick">';
    if($forumRoot && $forumRoot->id) $out .= '<li><a href="' . $forumRoot->url . '" target="_blank"><i class="fa fa-external-link"></i> Open the forum</a></li>';
    $out .= '<li><a href="' . $admin . 'groups/"><i class="fa fa-sitemap"></i> Groups & categories</a></li>';
    $out .= '<li><a href="' . $admin . 'settings/"><i class="fa fa-cog"></i> Forum settings</a></li>';
    $out .= '<li><a href="' . $config->urls->admin . 'setup/logs/view/nativeforum-spam/"><i class="fa fa-shield"></i> Blocked spam (log)</a></li>';
    if($forumRoot && $forumRoot->id) $out .= '<li><a href="' . $config->urls->admin . 'page/edit/?id=' . $forumRoot->id . '"><i class="fa fa-pencil"></i> Edit forum root page</a></li>';
    $out .= '</ul></div>';

    $newest = $users->find('id>0, name!=guest, include=all, check_access=0, sort=-created, limit=6');
    $out .= '<div class="sfa-panel"><div class="sfa-panel-h"><h3><i class="fa fa-user-plus"></i> Newest members</h3><a href="' . $admin . 'members/">All members &rarr;</a></div>';
    $out .= '<table class="sfa-table"><tbody>';
    foreach($newest as $u) $out .= '<tr><td>' . $this->sfaUserCell($u, true) . '</td><td class="sfa-nowrap" style="text-align:right">' . date('Y-m-d', (int) $u->created) . '</td></tr>';
    $out .= '</tbody></table></div>';
    $out .= '</div>'; // right column
    $out .= '</div>'; // grid

    // Categories overview
    if($rootId) {
      $cats = $pages->find("template=forum-category, has_parent=$rootId, include=all, sort=parent, sort=sort");
      $out .= '<div class="sfa-panel"><div class="sfa-panel-h"><h3><i class="fa fa-folder-o"></i> Categories</h3><a href="' . $admin . 'groups/">Manage &rarr;</a></div>';
      if(!$cats->count()) {
        $out .= '<p class="sfa-empty">No categories yet.</p>';
      } else {
        $out .= '<table class="sfa-table"><thead><tr><th>Category</th><th>Group</th><th>Topics</th><th>Last topic</th><th></th></tr></thead><tbody>';
        foreach($cats as $cat) {
          $n = (int) $pages->count('template=forum-topic, include=all, parent_id=' . (int) $cat->id);
          $last = $pages->findOne('template=forum-topic, include=all, parent_id=' . (int) $cat->id . ', sort=-created');
          $flags = '';
          if($cat->hasField('forum_is_vip') && (int) $cat->getUnformatted('forum_is_vip')) $flags .= ' <span class="sfa-badge is-amber">VIP</span>';
          if($cat->isHidden()) $flags .= ' <span class="sfa-badge">hidden</span>';
          if($cat->isUnpublished()) $flags .= ' <span class="sfa-badge is-red">unpublished</span>';
          $out .= '<tr><td><a href="' . $cat->url . '" target="_blank"><strong>' . $san->entities($cat->title) . '</strong></a>' . $flags . '</td>'
            . '<td>' . ($cat->parent->template->name === 'forum-group' ? $san->entities($cat->parent->title) : '<span class="sf-muted">&mdash;</span>') . '</td>'
            . '<td>' . $n . '</td>'
            . '<td>' . ($last && $last->id ? '<a href="' . $last->url . '" target="_blank">' . $san->entities($last->title) . '</a> <small>' . date('Y-m-d', $last->created) . '</small>' : '<span class="sf-muted">&mdash;</span>') . '</td>'
            . '<td><a class="sfa-btn" href="' . $admin . 'topics/?cat=' . (int) $cat->id . '" title="Manage topics"><i class="fa fa-list"></i></a></td></tr>';
        }
        $out .= '</tbody></table>';
      }
      $out .= '</div>';
    }

    $out .= '</div>';
    return $out;
  }

  /************************************************************
   * NAV: Reports (moderation queue)
   ************************************************************/
  /************************************************************
   * EXPORT / IMPORT (move a whole forum to another site)
   ************************************************************/
  public function ___executeTransfer() {
    $forum = $this->wire('modules')->get('NativeForum');
    $input = $this->wire('input');
    $session = $this->wire('session');
    $user = $this->wire('user');
    $san = $this->sanitizer;
    if(!$user->isSuperuser()) return '<p>Only superusers can export or import the forum.</p>';
    $self = $this->page->url . 'transfer/';

    // export: stream the zip
    if($input->post('sfa_export')) {
      $session->CSRF->validate();
      try {
        $path = $forum->transfer()->export([
          'settings' => (bool) $input->post('ex_settings'),
          'content'  => (bool) $input->post('ex_content'),
          'members'  => (bool) $input->post('ex_members'),
          'files'    => (bool) $input->post('ex_files'),
          'passwords'  => (bool) $input->post('ex_passwords'),
          'passphrase' => (string) $input->post('ex_passphrase'),
        ]);
        $this->wire('log')->save('native-forum', 'Export created: ' . basename($path) . ' (' . round(filesize($path) / 1048576, 2) . ' MB)');
        wireSendFile($path, ['forceDownload' => true, 'exit' => true, 'downloadFilename' => 'native-forum-' . preg_replace('~[^a-z0-9.-]+~i', '-', (string) $this->wire('config')->httpHost) . '-' . date('Y-m-d') . '.zip']);
      } catch(\Throwable $e) { $this->error('Export failed: ' . $e->getMessage()); $session->redirect($self); }
    }

    // import
    if($input->post('sfa_import')) {
      $session->CSRF->validate();
      $f = $_FILES['import_zip'] ?? null;
      if(!$f || (int) ($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
        $err = (int) ($f['error'] ?? 4);
        $this->error($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
          ? 'The file is larger than this server accepts (upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size') . ').'
          : 'Please choose the export .zip file.');
        $session->redirect($self);
      }
      if((string) $input->post('im_confirm') !== 'IMPORT') { $this->error('Type IMPORT to confirm.'); $session->redirect($self); }
      try {
        $r = $forum->transfer()->import((string) $f['tmp_name'], [
          'settings'    => (bool) $input->post('im_settings'),
          'content'     => (bool) $input->post('im_content'),
          'members'     => (bool) $input->post('im_members'),
          'createUsers' => (bool) $input->post('im_create_users'),
          'mode'        => $input->post('im_mode') === 'replace' ? 'replace' : 'merge',
          'passphrase'  => (string) $input->post('im_passphrase'),
        ]);
        $this->message(sprintf('Import done: %d posts/pages, %d files, %d settings, members %d matched + %d created (%d keep their password), %d reports%s%s.',
          $r['pages'], $r['files'], $r['settings'], $r['users_matched'], $r['users_created'], $r['passwords'] ?? 0, $r['reports'],
          $r['deleted'] ? ", {$r['deleted']} old sections removed first" : '', $r['skipped'] ? ", {$r['skipped']} skipped" : ''));
        foreach(array_slice($r['warnings'], 0, 10) as $w) $this->warning($w);
        $this->wire('log')->save('native-forum', 'Import: ' . json_encode(array_diff_key($r, ['warnings' => 1])));
      } catch(\Throwable $e) { $this->error('Import failed: ' . $e->getMessage()); }
      $session->redirect($self);
    }

    $this->headline('Export / Import');
    $this->browserTitle('Export / Import');
    $csrf = $session->CSRF->renderInput();
    $maxUp = ini_get('upload_max_filesize');
    $chk = function($name, $label, $hint = '', $on = true) {
      return '<label class="sfa-check"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> <span><strong>' . $label . '</strong>' . ($hint !== '' ? '<small>' . $hint . '</small>' : '') . '</span></label>';
    };
    $out = $this->sfAdminCss() . '<div class="nativeforum-admin sfa-transfer">';
    $out .= $this->sfaHead('Export / Import', 'exchange', 'Move a whole forum to another site: download one .zip here, install Native Forum on the other site and import the file there.');
    $out .= '<style>.sfa-transfer .sfa-check{display:flex;gap:10px;align-items:flex-start;margin:0 0 10px;cursor:pointer}.sfa-transfer .sfa-check input{margin-top:4px}.sfa-transfer .sfa-check small{display:block;opacity:.7;font-weight:400}'
      . '.sfa-transfer .sfa-radio{display:block;margin:0 0 6px}.sfa-transfer .sfa-field{margin:12px 0}.sfa-transfer input[type=file],.sfa-transfer input[type=text]{max-width:100%}'
      . '.sfa-transfer .sfa-note{font-size:.88em;opacity:.8;margin:10px 0 0}</style>';
    $out .= '<div class="sfa-grid">';

    // export panel
    $out .= '<div class="sfa-panel"><div class="sfa-panel-h"><h3><i class="fa fa-download"></i> Export</h3></div>'
      . '<form method="post" action="' . $self . '">' . $csrf
      . $chk('ex_settings', 'Settings', 'All Native Forum settings, design preset, Design Studio, texts.')
      . $chk('ex_content', 'Forum content', 'Groups, categories, topics, replies, private messages, reports – with dates, authors, likes and votes.')
      . $chk('ex_members', 'Members', 'User name, e-mail, forum roles and forum profile (nickname, signature, rank, followed topics, read markers). Passwords only with the option below.')
      . $chk('ex_files', 'Uploaded images and files', 'Pictures and attachments in posts and messages, avatars.')
      . $chk('ex_passwords', 'Passwords (encrypted)', 'Members keep their password on the new site. Only the secure password hashes are exported – never readable passwords – and they are encrypted (AES-256) with the transfer password below. At a member\'s first login on the new site the password is checked once and saved with the new site\'s own hashing.', false)
      . '<div class="sfa-field sfa-pass" style="margin:-4px 0 12px 28px"><label>Transfer password (min. 8 characters, you need it for the import): '
      . '<input type="password" name="ex_passphrase" autocomplete="new-password" style="width:220px"></label></div>'
      . '<button type="submit" name="sfa_export" value="1" class="ui-button ui-state-default"><i class="fa fa-download"></i> Download export (.zip)</button>'
      . '<p class="sfa-note">Big forums with many images can take a while.</p></form></div>';

    // import panel
    $out .= '<div class="sfa-panel"><div class="sfa-panel-h"><h3><i class="fa fa-upload"></i> Import</h3></div>'
      . '<form method="post" action="' . $self . '" enctype="multipart/form-data">' . $csrf
      . '<div class="sfa-field"><input type="file" name="import_zip" accept=".zip,application/zip" required> <span class="sf-muted">max ' . $san->entities($maxUp) . '</span></div>'
      . $chk('im_settings', 'Settings', 'Replace this site\'s forum settings with the ones from the file (page tree and root pages of this site are kept).')
      . $chk('im_content', 'Forum content')
      . '<div style="margin:-4px 0 10px 28px">'
      . '<label class="sfa-radio"><input type="radio" name="im_mode" value="merge" checked> Add to the existing forum</label>'
      . '<label class="sfa-radio"><input type="radio" name="im_mode" value="replace"> Replace: delete this forum\'s groups, categories, topics and messages first</label></div>'
      . $chk('im_members', 'Members', 'Members are matched by user name, then e-mail.')
      . $chk('im_create_users', 'Create missing members', 'They get the forum-member role. With exported passwords they log in with their old password, otherwise they use “Forgot password”.')
      . '<div class="sfa-field" style="margin:-4px 0 12px 28px"><label>Transfer password (only if the export contains passwords): '
      . '<input type="password" name="im_passphrase" autocomplete="off" style="width:220px"></label></div>'
      . '<div class="sfa-field"><label>Type <strong>IMPORT</strong> to confirm: <input type="text" name="im_confirm" autocomplete="off" style="width:120px"></label></div>'
      . '<button type="submit" name="sfa_import" value="1" class="ui-button ui-state-default"><i class="fa fa-upload"></i> Import</button>'
      . '<p class="sfa-note">Tip: make a database backup before importing. Posts get new page IDs; links and pictures in the text are updated.</p></form></div>';

    $out .= '</div></div>';
    $out .= '<script>(function(){var c=document.querySelector("input[name=ex_passwords]"),f=document.querySelector(".sfa-pass");if(!c||!f)return;function s(){f.style.display=c.checked?"":"none";}c.addEventListener("change",s);s();})();</script>';
    return $out;
  }

  public function ___executeReports() {
    $forum = $this->wire('modules')->get('NativeForum');
    $input = $this->wire('input');
    $session = $this->wire('session');
    if($input->post('sfa_report_action')) {
      $session->CSRF->validate();
      try {
        $msg = $forum->handleReport((int) $input->post('post_id'), (string) $input->post('sfa_report_action'), $this->wire('user'));
        if($msg) $this->message($msg);
      } catch(\Throwable $e) { $this->error($e->getMessage()); }
      $back = (string) $input->post('back');
      $session->redirect($back && strpos($back, '/') === 0 ? $back : $this->page->url . 'reports/');
    }
    $status = (string) $input->get('status');
    if(!in_array($status, ['open', 'closed', 'all'], true)) $status = 'open';
    $open = $forum->openReportCount();
    $out = $this->sfAdminCss() . '<div class="nativeforum-admin">';
    $out .= $this->sfaHead('Reports', 'flag', 'Posts that members reported. Look at the post, then hide it, lock the topic, delete it, or dismiss the report.');
    $out .= '<div class="sfa-tabs">'
      . '<a href="?status=open"' . ($status === 'open' ? ' class="is-active"' : '') . '>Open' . ($open ? ' (' . $open . ')' : '') . '</a>'
      . '<a href="?status=closed"' . ($status === 'closed' ? ' class="is-active"' : '') . '>Handled</a>'
      . '<a href="?status=all"' . ($status === 'all' ? ' class="is-active"' : '') . '>All</a></div>';
    $out .= '<div class="sfa-panel">' . $this->sfaReportsTable($forum, $status, 100, false) . '</div>';
    $out .= '</div>';
    return $out;
  }

  /** Reports table (dashboard + reports page). */
  protected function sfaReportsTable($forum, string $status, int $limit, bool $compact): string {
    if(!method_exists($forum, 'reportsByPost')) return '<p class="sfa-empty">Reports need the latest Native Forum.</p>';
    $rows = $forum->reportsByPost($status, $limit);
    if(!$rows) return '<p class="sfa-empty"><i class="fa fa-check"></i> ' . ($status === 'open' ? 'Nothing to review.' : 'No reports.') . '</p>';
    $san = $this->sanitizer;
    $pages = $this->wire('pages');
    $reasons = $forum->reportReasons();
    $back = $this->wire('input')->url(true);
    $token = $this->wire('session')->CSRF->renderInput();
    $out = '<table class="sfa-table"><thead><tr><th>Reported post</th><th>Why</th>' . ($compact ? '' : '<th>Reported by</th>') . '<th>When</th><th>Actions</th></tr></thead><tbody>';
    foreach($rows as $pid => $r) {
      $p = $pages->get((int) $pid);
      $exists = $p && $p->id;
      $topic = $exists ? ($p->template->name === 'forum-topic' ? $p : $p->parent) : null;
      $author = null;
      if($exists) { $a = $p->getUnformatted('forum_author'); if($a instanceof PageArray) $a = $a->first(); if($a instanceof User) $author = $a; }
      $hidden = $exists && ($p->isUnpublished() || ($p->hasField('forum_is_deleted') && (int) $p->getUnformatted('forum_is_deleted')) || $p->isTrash());
      $locked = $topic && $topic->id && $topic->hasField('forum_is_locked') && (int) $topic->getUnformatted('forum_is_locked');
      $url = $exists ? ($p->template->name === 'forum-reply' && method_exists($forum, 'replyUrl') ? $forum->replyUrl($p) : $p->url) : '';
      $why = '';
      foreach($r['reasons'] as $k => $n) $why .= '<span class="sfa-badge' . ($k === 'spam' || $k === 'illegal' ? ' is-red' : ($k === 'offensive' ? ' is-amber' : '')) . '">' . $san->entities($reasons[$k] ?? $k) . ($n > 1 ? ' ×' . $n : '') . '</span> ';
      $comments = '';
      $who = [];
      foreach($r['items'] as $it) {
        $ru = $this->wire('users')->get((int) $it['reporter_id']);
        $who[] = $ru && $ru->id ? $san->entities($forum->displayName($ru)) : 'guest';
        if(trim((string) $it['comment']) !== '') $comments .= '<div class="sfa-excerpt">“' . $san->entities($it['comment']) . '”</div>';
      }
      $out .= '<tr><td>';
      if($exists) {
        $out .= '<a href="' . $url . '" target="_blank"><strong>' . $san->entities($topic ? $topic->title : $p->title) . '</strong></a> '
          . ($p->template->name === 'forum-topic' ? '<span class="sfa-badge is-blue">topic</span>' : '<span class="sfa-badge">reply</span>')
          . ($hidden ? ' <span class="sfa-badge is-amber">hidden</span>' : '') . ($locked ? ' <span class="sfa-badge">locked</span>' : '')
          . '<div class="sfa-excerpt">' . $san->entities(mb_strimwidth(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $p->getUnformatted('forum_body')))), 0, 160, '…')) . '</div>'
          . '<div style="margin-top:6px">' . $this->sfaUserCell($author) . '</div>';
      } else {
        $out .= '<span class="sf-muted">Post #' . (int) $pid . ' no longer exists</span>';
      }
      $out .= '</td><td>' . $why . $comments . '</td>';
      if(!$compact) $out .= '<td>' . implode(', ', array_unique($who)) . '</td>';
      $out .= '<td class="sfa-nowrap">' . date('Y-m-d H:i', (int) $r['last']) . ($r['count'] > 1 ? '<br><small>' . (int) $r['count'] . ' reports</small>' : '')
        . ($r['status'] !== 'open' ? '<br><span class="sfa-badge is-green">' . $san->entities($r['status']) . ($r['action'] ? ': ' . $san->entities($r['action']) : '') . '</span>' : '') . '</td>';
      $btn = function($action, $icon, $label, $cls = '', $confirm = '') use ($pid, $token, $back) {
        return '<form method="post" action="' . $this->page->url . 'reports/" style="display:inline">' . $token
          . '<input type="hidden" name="post_id" value="' . (int) $pid . '"><input type="hidden" name="back" value="' . htmlspecialchars($back, ENT_QUOTES) . '">'
          . '<button class="sfa-btn ' . $cls . '" type="submit" name="sfa_report_action" value="' . $action . '" title="' . $label . '"' . ($confirm ? ' onclick="return confirm(\'' . $confirm . '\')"' : '') . '><i class="fa fa-' . $icon . '"></i> ' . $label . '</button></form>';
      };
      $out .= '<td><div class="sfa-btns">';
      if($exists) {
        $out .= '<a class="sfa-btn" href="' . $url . '" target="_blank" title="Open the post"><i class="fa fa-eye"></i> View</a>';
        $out .= $hidden ? $btn('restore', 'undo', 'Restore', 'is-ok') : $btn('hide', 'eye-slash', 'Hide', 'is-danger');
        if($topic && $topic->id && $topic->hasField('forum_is_locked')) $out .= $locked ? $btn('unlock', 'unlock', 'Unlock') : $btn('lock', 'lock', 'Lock topic');
        if($topic && $topic->id) $out .= '<a class="sfa-btn" href="' . $this->page->url . 'edit-topic/?id=' . (int) $topic->id . ($p->template->name === 'forum-reply' ? '&reply=' . (int) $p->id : '') . '" title="Edit the text"><i class="fa fa-pencil"></i> Edit</a>';
        $out .= $btn('delete', 'trash', 'Delete', 'is-danger', 'Move this post to the trash?');
      }
      if($r['status'] === 'open') {
        $out .= $btn('resolve', 'check', 'Resolved', 'is-ok') . $btn('dismiss', 'times', 'Dismiss');
      }
      $out .= '</div></td></tr>';
    }
    return $out . '</tbody></table>';
  }

/**
 * Handle AJAX drag/drop actions for sorting/moving groups/categories/topics in admin.
 * Returns true if handled (response already output + exited).
 */
protected function sfHandleAjax() {
  $input   = $this->wire('input');
  if(!$input->post->sf_action) return false;

  $session = $this->wire('session');
  $pages   = $this->wire('pages');

  // CSRF
  try { $session->CSRF->validate(); } catch(\Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'CSRF']);
    exit;
  }

  $action = (string) $input->post->sf_action;

  // Helpers
  $saveOrder = function(Page $parent, array $ids, string $expectedTpl) use ($pages) {
    $i = 0;
    foreach($ids as $id) {
      $id = (int) $id;
      if(!$id) continue;
      $p = $pages->get($id);
      if(!$p || !$p->id) continue;
      if($expectedTpl && $p->template && $p->template->name !== $expectedTpl) continue;

      $p->of(false);
      if($parent && $parent->id && $p->parent->id !== $parent->id) {
        $p->parent = $parent;
      }
      $p->sort = $i++;
      $p->save();
      $p->of(true);
    }
  };

  $ok = true; $msg = '';
  $html = null;

  if($action === 'sort_groups') {
    $rootId = (int) $input->post->root;
    $root = $pages->get($rootId);
    $order = $input->post->order;
    if(!$root || !$root->id) { $ok=false; $msg='Missing root'; }
    else {
      $ids = is_array($order) ? $order : [];
      $saveOrder($root, $ids, 'forum-group');
    }

  } elseif($action === 'sort_categories') {
    $parentId = (int) $input->post->parent;
    $parent = $pages->get($parentId);
    $order = $input->post->order;
    if(!$parent || !$parent->id) { $ok=false; $msg='Missing parent'; }
    else {
      $ids = is_array($order) ? $order : [];
      $saveOrder($parent, $ids, 'forum-category');
    }

  } elseif($action === 'move_category') {
    $id = (int) $input->post->id;
    $parentId = (int) $input->post->parent;
    $p = $pages->get($id);
    $parent = $pages->get($parentId);
    if(!$p || !$p->id || $p->template->name !== 'forum-category') { $ok=false; $msg='Bad category'; }
    elseif(!$parent || !$parent->id || !in_array($parent->template->name, ['forum-group','forum-root'], true)) { $ok=false; $msg='Bad parent'; }
    else {
      $p->of(false);
      $p->parent = $parent;
      $p->sort = 9999;
      $p->save();
      $p->of(true);
    }

  } elseif($action === 'sort_topics') {
    $parentId = (int) $input->post->parent;
    $parent = $pages->get($parentId);
    $order = $input->post->order;
    if(!$parent || !$parent->id) { $ok=false; $msg='Missing category'; }
    else {
      $ids = is_array($order) ? $order : [];
      $saveOrder($parent, $ids, 'forum-topic');
    }

  } elseif($action === 'move_topic') {
    $id = (int) $input->post->id;
    $parentId = (int) $input->post->parent;
    $p = $pages->get($id);
    $parent = $pages->get($parentId);
    if(!$p || !$p->id || $p->template->name !== 'forum-topic') { $ok=false; $msg='Bad topic'; }
    elseif(!$parent || !$parent->id || $parent->template->name !== 'forum-category') { $ok=false; $msg='Bad category'; }
    else {
      $p->of(false);
      $p->parent = $parent;
      $p->sort = 9999;
      $p->save();
      $p->of(true);
    }
  
  } elseif($action === 'toggle_field') {
    $type  = (string) $input->post->type;
    $id    = (int) $input->post->id;
    $field = (string) $input->post->field;
    $value = (int) $input->post->value;

    $p = $pages->get($id);
    if(!$p || !$p->id) { $ok = false; $msg = 'Not found'; }
    else {
      // Validate expected template by type
      $expected = [
        'group' => 'forum-group',
        'category' => 'forum-category',
        'topic' => 'forum-topic',
      ];
      if(!isset($expected[$type]) || $p->template->name !== $expected[$type]) {
        $ok = false; $msg = 'Bad type';
      } else {
        $p->of(false);

        // Page status toggles
        if($field === 'status_hidden') {
          if($value) $p->addStatus(Page::statusHidden);
          else $p->removeStatus(Page::statusHidden);
        } elseif($field === 'status_unpublished') {
          if($value) $p->addStatus(Page::statusUnpublished);
          else $p->removeStatus(Page::statusUnpublished);
        } else {
          // Field toggles (whitelist)
          $allowed = [
            'group' => ['forum_group_is_vip', 'forum_group_is_pinned'],
            'category' => ['forum_is_vip'],
            'topic' => ['forum_is_pinned', 'forum_is_locked'],
          ];
          if(!in_array($field, $allowed[$type] ?? [], true) || !$p->hasField($field)) {
            $ok = false; $msg = 'Not allowed';
          } else {
            $p->set($field, $value ? 1 : 0);
          }
        }

        if($ok) $p->save();
        $p->of(true);
      }
    }

  } elseif($action === 'load_edit_form') {
    $type = (string) $input->post->type;
    $id   = (int) $input->post->id;
    $p    = $pages->get($id);
    if(!$p || !$p->id) { $ok=false; $msg='Not found'; }
    else {
      $html = $this->sfAdminEditFormHtml($type, $p);
      if(!$html) { $ok=false; $msg='Bad type'; }
    }

  } elseif($action === 'save_edit_form') {
    $type = (string) $input->post->type;
    $id   = (int) $input->post->id;
    $p    = $pages->get($id);
    if(!$p || !$p->id) { $ok=false; $msg='Not found'; }
    else {
      $ok = $this->sfAdminSaveFromPost($type, $p, $input->post);
      if(!$ok) $msg = 'Save failed';
    }

  } else {
    $ok = false;
    $msg = 'Unknown action';
  }

  header('Content-Type: application/json; charset=utf-8');
   $payload = ['ok' => $ok, 'message' => $msg];
  if($html !== null) $payload['html'] = $html;
  echo json_encode($payload);
  exit;
}

/**
 * Render category list row with quick toggles + edit button (admin).
 */
protected function sfAdminCategoryRow(Page $c): string {
  $isVip = $c->hasField('forum_is_vip') && (int)$c->get('forum_is_vip') === 1;
  $isHidden = $c->isHidden();
  $isUnpub  = $c->isUnpublished();
  $topicsCount = $c->children("template=forum-topic")->count();

  $out = '';
  $out .= '<li data-id="' . (int)$c->id . '">';
  $out .= '<div class="sf-title"><span class="sf-drag" title="Drag to reorder"><i class="fa fa-bars"></i></span><strong>' . $this->sanitizer->entities($c->title) . '</strong> <span class="sfa-badge" title="Topics">' . (int)$topicsCount . ' <i class="fa fa-comments-o"></i></span></div>';
  $out .= '<div class="sf-actions">';
  $out .= '<label title="VIP category"><input class="sf-qt" type="checkbox" data-type="category" data-id="' . (int)$c->id . '" data-field="forum_is_vip"' . ($isVip ? ' checked' : '') . '>VIP</label>';
  $out .= '<label title="Hidden (page status)"><input class="sf-qt" type="checkbox" data-type="category" data-id="' . (int)$c->id . '" data-field="status_hidden"' . ($isHidden ? ' checked' : '') . '>Hidden</label>';
  $out .= '<label title="Unpublished (page status)"><input class="sf-qt" type="checkbox" data-type="category" data-id="' . (int)$c->id . '" data-field="status_unpublished"' . ($isUnpub ? ' checked' : '') . '>Unpub</label>';
  $out .= '<button type="button" class="pw-button pw-button-small sf-edit" data-type="category" data-id="' . (int)$c->id . '"><i class="fa fa-pencil"></i> Edit</button>';
  $out .= '</div></li>';
  return $out;
}


/**
 * Build custom edit form HTML (returned for modal via AJAX).
 * This intentionally avoids ProcessWire page editor, and only exposes relevant forum fields.
 */
protected function sfAdminEditFormHtml(string $type, Page $p): string {
  $modules = $this->wire('modules');
  $pages   = $this->wire('pages');
  $session = $this->wire('session');

  $expected = [
    'group' => 'forum-group',
    'category' => 'forum-category',
    'topic' => 'forum-topic',
  ];
  if(!isset($expected[$type]) || $p->template->name !== $expected[$type]) return '';

  /** @var InputfieldForm $form */
  $form = $modules->get('InputfieldForm');
  $form->addClass('sf-admin-edit-form');
  $form->attr('method', 'post');
  $form->attr('action', $this->page->url); // AJAX posts back to same Process URL
  /** @var InputfieldMarkup $m */
  $m = $modules->get('InputfieldHidden');
  $m->attr('name', $session->CSRF->getTokenName());
  $m->attr('value', $session->CSRF->getTokenValue());
  $form->add($m);

  // Hidden context
  $ctx = $modules->get('InputfieldHidden');
  $ctx->name = 'type';
  $ctx->value = $type;
  $form->add($ctx);

  $ctx2 = $modules->get('InputfieldHidden');
  $ctx2->name = 'id';
  $ctx2->value = (int)$p->id;
  $form->add($ctx2);

  // Title
  /** @var InputfieldText $fTitle */
  $fTitle = $modules->get('InputfieldText');
  $fTitle->label = 'Title';
  $fTitle->name  = 'title';
  $fTitle->value = $p->title;
  $fTitle->required = true;
  $form->add($fTitle);

  // Status
  /** @var InputfieldCheckboxes $fStatus */
  $fStatus = $modules->get('InputfieldCheckboxes');
  $fStatus->label = 'Visibility';
  $fStatus->name  = 'status_flags';
  $fStatus->addOption('hidden', 'Hidden');
  $fStatus->addOption('unpublished', 'Unpublished');
  $fStatus->value = [];
  if($p->isHidden()) $fStatus->value[] = 'hidden';
  if($p->isUnpublished()) $fStatus->value[] = 'unpublished';
  $form->add($fStatus);

  if($type === 'group') {
    /** @var InputfieldCheckbox $vip */
    $vip = $modules->get('InputfieldCheckbox');
    $vip->label = 'VIP group';
    $vip->name  = 'forum_group_is_vip';
    $vip->attr('checked', ($p->hasField('forum_group_is_vip') && (int)$p->get('forum_group_is_vip') === 1) ? 'checked' : '');
    $form->add($vip);

    /** @var InputfieldCheckbox $pin */
    $pin = $modules->get('InputfieldCheckbox');
    $pin->label = 'Pinned group';
    $pin->name  = 'forum_group_is_pinned';
    $pin->attr('checked', ($p->hasField('forum_group_is_pinned') && (int)$p->get('forum_group_is_pinned') === 1) ? 'checked' : '');
    $form->add($pin);
  }

  if($type === 'category') {
    // Summary
    if($p->hasField('field_forum_summary_category')) {
      /** @var InputfieldText $sum */
      $sum = $modules->get('InputfieldText');
      $sum->label = 'Summary';
      $sum->name  = 'field_forum_summary_category';
      $sum->value = (string) $p->get('field_forum_summary_category');
      $sum->attr('maxlength', 255);
      $form->add($sum);
    }

    // VIP
    if($p->hasField('forum_is_vip')) {
      /** @var InputfieldCheckbox $vipc */
      $vipc = $modules->get('InputfieldCheckbox');
      $vipc->label = 'VIP category';
      $vipc->name  = 'forum_is_vip';
      $vipc->attr('checked', ((int)$p->get('forum_is_vip') === 1) ? 'checked' : '');
      $form->add($vipc);
    }

    // Read/Write roles (optional)
    $roles = $pages->find("template=role, include=all, sort=name");
    if($p->hasField('forum_read_roles')) {
      /** @var InputfieldSelect $rr */
      $rr = $modules->get('InputfieldSelect');
      $rr->label = 'Allowed read roles (optional)';
      $rr->name  = 'forum_read_roles';
      $rr->attr('multiple', 'multiple');
      foreach($roles as $r) $rr->addOption($r->id, $r->name);
      $rr->value = $p->getUnformatted('forum_read_roles') ? $p->getUnformatted('forum_read_roles')->explode('id') : [];
      $form->add($rr);
    }
    if($p->hasField('forum_write_roles')) {
      /** @var InputfieldSelect $wr */
      $wr = $modules->get('InputfieldSelect');
      $wr->label = 'Allowed write roles (optional)';
      $wr->name  = 'forum_write_roles';
      $wr->attr('multiple', 'multiple');
      foreach($roles as $r) $wr->addOption($r->id, $r->name);
      $wr->value = $p->getUnformatted('forum_write_roles') ? $p->getUnformatted('forum_write_roles')->explode('id') : [];
      $form->add($wr);
    }
  }

  if($type === 'topic') {
    // Summary
    if($p->hasField('field_forum_summary_topic')) {
      /** @var InputfieldText $sumt */
      $sumt = $modules->get('InputfieldText');
      $sumt->label = 'Summary';
      $sumt->name  = 'field_forum_summary_topic';
      $sumt->value = (string) $p->get('field_forum_summary_topic');
      $sumt->attr('maxlength', 255);
      $form->add($sumt);
    }

    // Pinned / Locked
    if($p->hasField('forum_is_pinned')) {
      $pin = $modules->get('InputfieldCheckbox');
      $pin->label = 'Pinned topic';
      $pin->name  = 'forum_is_pinned';
      $pin->attr('checked', ((int)$p->get('forum_is_pinned') === 1) ? 'checked' : '');
      $form->add($pin);
    }
    if($p->hasField('forum_is_locked')) {
      $lock = $modules->get('InputfieldCheckbox');
      $lock->label = 'Locked topic';
      $lock->name  = 'forum_is_locked';
      $lock->attr('checked', ((int)$p->get('forum_is_locked') === 1) ? 'checked' : '');
      $form->add($lock);
    }

    // Move topic
    $forumRoot = $this->wire('modules')->get('NativeForum')->forumRoot();
    $rootId = ($forumRoot && $forumRoot->id) ? (int) $forumRoot->id : 0;
    $cats = $rootId ? $pages->find("template=forum-category, has_parent=$rootId, include=all, sort=title") : new PageArray();
    $move = $modules->get('InputfieldSelect');
    $move->label = 'Move to category';
    $move->name  = 'move_parent';
    foreach($cats as $c) {
      $label = $c->title;
      if($c->parent && $c->parent->template->name === 'forum-group') $label = $c->parent->title . ' / ' . $label;
      $move->addOption($c->id, $label);
    }
    $move->value = $p->parent ? $p->parent->id : 0;
    $form->add($move);
  }

  /** @var InputfieldSubmit $save */
  $save = $modules->get('InputfieldSubmit');
  $save->name = 'save';
  $save->value = 'Save';
  $save->icon = 'check';
  $form->add($save);

  return $form->render();
}

/**
 * Save custom edit form posted values.
 */
protected function sfAdminSaveFromPost(string $type, Page $p, WireInputData $post): bool {
  $pages = $this->wire('pages');
  $session = $this->wire('session');

  $expected = [
    'group' => 'forum-group',
    'category' => 'forum-category',
    'topic' => 'forum-topic',
  ];
  if(!isset($expected[$type]) || $p->template->name !== $expected[$type]) return false;

  try { $session->CSRF->validate(); } catch(\Throwable $e) { return false; }

  $title = $this->sanitizer->text($post->title);
  if(!$title) return false;

  $p->of(false);
  $p->title = $title;

  // Status flags
  $flags = $post->status_flags;
  $flags = is_array($flags) ? $flags : [];
  if(in_array('hidden', $flags, true)) $p->addStatus(Page::statusHidden);
  else $p->removeStatus(Page::statusHidden);
  if(in_array('unpublished', $flags, true)) $p->addStatus(Page::statusUnpublished);
  else $p->removeStatus(Page::statusUnpublished);

  if($type === 'group') {
    if($p->hasField('forum_group_is_vip')) $p->set('forum_group_is_vip', $post->forum_group_is_vip ? 1 : 0);
    if($p->hasField('forum_group_is_pinned')) $p->set('forum_group_is_pinned', $post->forum_group_is_pinned ? 1 : 0);
  }

  if($type === 'category') {
    if($p->hasField('field_forum_summary_category')) $p->set('field_forum_summary_category', $this->sanitizer->text($post->field_forum_summary_category));
    if($p->hasField('forum_is_vip')) $p->set('forum_is_vip', $post->forum_is_vip ? 1 : 0);

    // Roles (multi-select)
    foreach(['forum_read_roles','forum_write_roles'] as $rf) {
      if(!$p->hasField($rf)) continue;
      $ids = $post->$rf;
      $ids = is_array($ids) ? array_map('intval', $ids) : [];
      $rolePages = new PageArray();
      foreach($ids as $id) {
        $r = $pages->get($id);
        if($r && $r->id && $r->template->name === 'role') $rolePages->add($r);
      }
      $p->set($rf, $rolePages);
    }
  }

  if($type === 'topic') {
    if($p->hasField('field_forum_summary_topic')) $p->set('field_forum_summary_topic', $this->sanitizer->text($post->field_forum_summary_topic));
    if($p->hasField('forum_is_pinned')) $p->set('forum_is_pinned', $post->forum_is_pinned ? 1 : 0);
    if($p->hasField('forum_is_locked')) $p->set('forum_is_locked', $post->forum_is_locked ? 1 : 0);

    $moveParent = (int) $post->move_parent;
    if($moveParent) {
      $parent = $pages->get($moveParent);
      if($parent && $parent->id && $parent->template->name === 'forum-category') $p->parent = $parent;
    }
  }

  $p->save();
  $p->of(true);
  return true;
}

public function ___executeGroups() {
  $modules = $this->wire('modules');
  $input   = $this->wire('input');
  $pages   = $this->wire('pages');
  $session = $this->wire('session');

  /** @var NativeForum $forum */
  $forum = $modules->get('NativeForum');
  $forumRoot = $forum->forumRoot();
  if(!$forumRoot || !$forumRoot->id) {
    return '<p>No forum root page found. Ensure Native Forum is installed and configured.</p>';
  }

  // AJAX actions (sort/move/toggles/edit modal)
  $this->sfHandleAjax();

  // Create new group
  if($input->post->create_group) {
    $session->CSRF->validate();
    $title = $this->sanitizer->text($input->post->group_title);
    if($title) {
      $p = new Page();
      $p->template = 'forum-group';
      $p->parent   = $forumRoot;
      $p->title    = $title;
      $p->name     = $this->sanitizer->pageName($title, true);

      $p->of(false);
      if($p->hasField('forum_group_is_vip')) $p->set('forum_group_is_vip', (int) ($input->post->group_is_vip ? 1 : 0));
      if($p->hasField('forum_group_is_pinned')) $p->set('forum_group_is_pinned', (int) ($input->post->group_is_pinned ? 1 : 0));
      $p->save();
      $p->of(true);

      $this->message("Group created: $title");
      $session->redirect($this->page->url . 'groups/');
    } else {
      $this->error('Please enter group title.');
    }
  }

  // Create new category under selected group/root
  if($input->post->create_category) {
    $session->CSRF->validate();
    $title = $this->sanitizer->text($input->post->cat_title);
    $parentId = (int) $input->post->cat_parent;
    $parent = $pages->get($parentId);
    if(!$parent || !$parent->id || !in_array($parent->template->name, ['forum-group','forum-root'], true)) {
      $parent = $forumRoot;
    }
    if($title) {
      $c = new Page();
      $c->template = 'forum-category';
      $c->parent   = $parent;
      $c->title    = $title;
      $c->name     = $this->sanitizer->pageName($title, true);

      $c->of(false);
      if($c->hasField('field_forum_summary_category')) $c->set('field_forum_summary_category', $this->sanitizer->text($input->post->cat_summary));
      if($c->hasField('forum_is_vip')) $c->set('forum_is_vip', (int) ($input->post->cat_is_vip ? 1 : 0));
      $c->save();
      $c->of(true);

      $this->message("Category created: $title");
      $session->redirect($this->page->url . 'groups/');
    } else {
      $this->error('Please enter category title.');
    }
  }

  $groups = $forumRoot->children("template=forum-group, include=all, sort=sort");
  $ungrouped = $forumRoot->children("template=forum-category, include=all, sort=sort");

  $tokenName  = $session->CSRF->getTokenName();
  $tokenValue = $session->CSRF->getTokenValue();

  $out = '';
  $out .= $this->sfAdminCss();

  $out .= '<div class="nativeforum-admin">';
  $out .= $this->sfaHead('Groups & categories', 'sitemap', 'Drag <i class="fa fa-bars"></i> to reorder or move. Switch VIP / Pin / Hidden / Unpublished right in the list, or click Edit for all settings.', '<a class="sfa-btn" href="' . $forumRoot->url . '" target="_blank"><i class="fa fa-external-link"></i> View forum</a>');

  // Create forms
  $out .= '<div class="sfa-flex">';
  $out .= '<div class="sfa-col sfa-card">';
  $out .= '<h3><i class="fa fa-folder-o"></i> Create group</h3>';
  $out .= '<form method="post">' . $session->CSRF->renderInput() . '
    <p><label>Title<br><input class="pw-input-text" type="text" name="group_title" style="width:100%"></label></p>
    <p><label><input type="checkbox" name="group_is_vip" value="1"> VIP group</label> &nbsp;
       <label><input type="checkbox" name="group_is_pinned" value="1"> Pinned group</label></p>
    <p><button class="sfa-btn is-primary" type="submit" name="create_group" value="1">Create group</button></p>
  </form>';
  $out .= '</div>';

  $out .= '<div class="sfa-col sfa-card">';
  $out .= '<h3><i class="fa fa-comments-o"></i> Create category</h3>';
  $out .= '<form method="post">' . $session->CSRF->renderInput() . '
    <p><label>Title<br><input class="pw-input-text" type="text" name="cat_title" style="width:100%"></label></p>
    <p><label>Summary (optional)<br><input class="pw-input-text" type="text" name="cat_summary" maxlength="255" style="width:100%"></label></p>
    <p><label><input type="checkbox" name="cat_is_vip" value="1"> VIP category</label></p>
    <p><label>Group<br><select class="pw-input" name="cat_parent" style="width:100%">';
  $out .= '<option value="' . (int)$forumRoot->id . '">— (Ungrouped / root)</option>';
  foreach($groups as $g) $out .= '<option value="' . (int)$g->id . '">' . $this->sanitizer->entities($g->title) . '</option>';
  $out .= '</select></label></p>
    <p><button class="sfa-btn is-primary" type="submit" name="create_category" value="1">Create category</button></p>
  </form>';
  $out .= '</div>';
  $out .= '</div>';

  // Groups list
  $out .= '<div class="sfa-card">';
  $out .= '<div class="sfa-panel-h"><h3><i class="fa fa-folder-o"></i> Groups</h3><span class="sf-muted">' . $groups->count() . ' groups</span></div>';
  if(!$groups->count()) {
    $out .= '<p>No groups yet.</p>';
  } else {
    $out .= '<ul id="sf-groups-sortable" class="sf-sortable">';
    foreach($groups as $g) {
      $isVip = $g->hasField('forum_group_is_vip') && (int)$g->get('forum_group_is_vip') === 1;
      $isPin = $g->hasField('forum_group_is_pinned') && (int)$g->get('forum_group_is_pinned') === 1;
      $isHidden = $g->isHidden();
      $isUnpub  = $g->isUnpublished();

      $out .= '<li data-id="' . (int)$g->id . '">';
      $out .= '<div class="sf-title"><span class="sf-drag" title="Drag to reorder"><i class="fa fa-bars"></i></span><strong>' . $this->sanitizer->entities($g->title) . '</strong></div>';
      $out .= '<div class="sf-actions">';
      $out .= '<label title="VIP group"><input class="sf-qt" type="checkbox" data-type="group" data-id="' . (int)$g->id . '" data-field="forum_group_is_vip"' . ($isVip ? ' checked' : '') . '>VIP</label>';
      $out .= '<label title="Pinned group"><input class="sf-qt" type="checkbox" data-type="group" data-id="' . (int)$g->id . '" data-field="forum_group_is_pinned"' . ($isPin ? ' checked' : '') . '>Pin</label>';
      $out .= '<label title="Hidden (page status)"><input class="sf-qt" type="checkbox" data-type="group" data-id="' . (int)$g->id . '" data-field="status_hidden"' . ($isHidden ? ' checked' : '') . '>Hidden</label>';
      $out .= '<label title="Unpublished (page status)"><input class="sf-qt" type="checkbox" data-type="group" data-id="' . (int)$g->id . '" data-field="status_unpublished"' . ($isUnpub ? ' checked' : '') . '>Unpub</label>';
      $out .= '<button type="button" class="pw-button pw-button-small sf-edit" data-type="group" data-id="' . (int)$g->id . '"><i class="fa fa-pencil"></i> Edit</button>';
      $out .= '</div></li>';
    }
    $out .= '</ul>';
  }
  $out .= '</div>';

  // Categories (sortable columns)
  $out .= '<div class="sfa-card">';
  $out .= '<div class="sfa-panel-h"><h3><i class="fa fa-comments-o"></i> Categories</h3><span class="sf-muted">Drag a category into another group to move it</span></div>';
  $out .= '<div class="sfa-catgrid">';

  // ungrouped
  $out .= '<div class="sfa-col">';
  $out .= '<h4>Ungrouped <span class="sfa-badge">' . $ungrouped->count() . '</span></h4>';
  $out .= '<ul class="sf-cat-sortable sf-sortable" data-parent="' . (int)$forumRoot->id . '">';
  if(!$ungrouped->count()) {
    $out .= '<li class="sf-drop-hint" data-id="0">Drop categories here</li>';
  } else {
    foreach($ungrouped as $c) {
      $out .= $this->sfAdminCategoryRow($c);
    }
  }
  $out .= '</ul></div>';

  foreach($groups as $g) {
    $cats = $g->children("template=forum-category, include=all, sort=sort");
    $out .= '<div class="sfa-col">';
    $out .= '<h4>' . $this->sanitizer->entities($g->title) . ' <span class="sfa-badge">' . $cats->count() . '</span></h4>';
    $out .= '<ul class="sf-cat-sortable sf-sortable" data-parent="' . (int)$g->id . '">';
    if(!$cats->count()) {
      $out .= '<li class="sf-drop-hint" data-id="0">Drop categories here</li>';
    } else {
      foreach($cats as $c) $out .= $this->sfAdminCategoryRow($c);
    }
    $out .= '</ul></div>';
  }

  $out .= '</div></div>'; // flex + card

  // Modal containers
  $out .= '<div class="sf-admin-modal-backdrop" id="sfAdminModalBg"></div>';
  $out .= '<div class="sf-admin-modal" id="sfAdminModal"><div class="box">';
  $out .= '<div class="head"><strong id="sfAdminModalTitle">Edit</strong><button type="button" class="sfa-btn" id="sfAdminModalClose"><i class="fa fa-times"></i> Close</button></div>';
  $out .= '<div class="body" id="sfAdminModalBody"><div class="sf-muted">Loading…</div></div>';
  $out .= '</div></div>';

  // JS
  $out .= '<script>
  (function($){
    var postUrl = ' . json_encode($this->page->url . 'groups/') . ';
    var csrfName = ' . json_encode($tokenName) . ';
    var csrfValue = ' . json_encode($tokenValue) . ';
    function ajaxPost(data, cb){
      data = data || {};
      data[csrfName] = csrfValue;
      $.post(postUrl, data, function(res){
        if(cb) cb(res || {});
      }, "json");
    }

    function orderIds($ul){
      return $ul.children("li").map(function(){
        var id = $(this).data("id");
        return id ? id : null;
      }).get().filter(Boolean);
    }

    // Sort groups
    $("#sf-groups-sortable").sortable({
      handle: ".sf-drag",
      placeholder: "sf-sort-placeholder",
      update: function(){
        ajaxPost({sf_action:"sort_groups", root:' . (int)$forumRoot->id . ', order: orderIds($(this))});
      }
    });

    // Sort/move categories
    $(".sf-cat-sortable").sortable({
      connectWith: ".sf-cat-sortable",
      handle: ".sf-drag",
      dropOnEmpty: true,
      tolerance: "pointer",
      receive: function(e, ui){
        // remove any drop hints
        $(this).children(".sf-drop-hint").remove();
      },
      update: function(e, ui){
        var parent = $(this).data("parent");
        ajaxPost({sf_action:"sort_categories", parent: parent, order: orderIds($(this))});
        // if list became empty, add hint
        if($(this).children("li").length === 0) $(this).append(\'<li class="sf-drop-hint" data-id="0">Drop categories here</li>\');
      }
    });

    // Quick toggles
    $(document).on("change", ".sf-qt", function(){
      var el = this;
      ajaxPost({
        sf_action: "toggle_field",
        type: $(el).data("type"),
        id: $(el).data("id"),
        field: $(el).data("field"),
        value: el.checked ? 1 : 0
      }, function(res){
        if(!res.ok){
          try { el.checked = !el.checked; } catch(e){}
          alert(res.message || "Save failed");
        }
      });
    });

    // Modal open/close
    function openModal(title, html){
      $("#sfAdminModalTitle").text(title || "Edit");
      $("#sfAdminModalBody").html(html || "");
      $("#sfAdminModalBg").show();
      $("#sfAdminModal").css("display","flex");
    }
    function closeModal(){
      $("#sfAdminModal").hide();
      $("#sfAdminModalBg").hide();
      $("#sfAdminModalBody").html(\'<div class="sf-muted">Loading…</div>\');
    }
    $("#sfAdminModalClose, #sfAdminModalBg").on("click", closeModal);

    // Load edit form
    $(document).on("click", ".sf-edit", function(e){
      e.preventDefault();
      var type = $(this).data("type");
      var id = $(this).data("id");
      openModal("Edit " + type, \'<div class="sf-muted">Loading…</div>\');
      ajaxPost({sf_action:"load_edit_form", type:type, id:id}, function(res){
        if(res && res.ok && res.html){
          openModal("Edit " + type, res.html);
        } else {
          openModal("Edit " + type, \'<div class="pw-notice pw-notice-error">\' + (res.message || "Failed to load form") + \'</div>\');
        }
      });
    });

    // Save edit form (AJAX)
    $(document).on("submit", "form.sf-admin-edit-form", function(e){
      e.preventDefault();
      var $f = $(this);
      var data = $f.serializeArray();
      data.push({name: "sf_action", value: "save_edit_form"});
      $.post(postUrl, data, function(res){
        if(res && res.ok){
          closeModal();
          sfaReload();
        } else {
          alert((res && res.message) ? res.message : "Save failed");
        }
      }, "json");
    });

  })(jQuery);
  </script>';

  $out .= '</div>'; // nativeforum-admin
  return $out;
}

public function ___executeCategories() {
  // Categories are managed inside Groups/Categories
  $this->wire('session')->redirect($this->page->url . 'groups/');
}

public function ___executeTopics() {
  $modules = $this->wire('modules');
  $pages   = $this->wire('pages');
  $input   = $this->wire('input');
  $session = $this->wire('session');

  /** @var NativeForum $forum */
  $forum = $modules->get('NativeForum');
  $forumRoot = $forum->forumRoot();
  if(!$forumRoot || !$forumRoot->id) {
    return '<p>No forum root page found. Ensure Native Forum is installed and configured.</p>';
  }

  // AJAX actions (sort/move/toggles/edit modal)
  $this->sfHandleAjax();

  $rootId = ($forumRoot && $forumRoot->id) ? (int) $forumRoot->id : 0;
  $categories = $rootId ? $pages->find("template=forum-category, has_parent=$rootId, include=all, sort=title") : new PageArray();
  $catId = (int) $input->get('cat');
  $currentCat = $catId ? $pages->get($catId) : null;
  if(!$currentCat || !$currentCat->id || $currentCat->template->name !== 'forum-category') {
    $currentCat = $categories->first();
  }

  $tokenName  = $session->CSRF->getTokenName();
  $tokenValue = $session->CSRF->getTokenValue();

  $out = '';
  $out .= $this->sfAdminCss();

  $out .= '<div class="nativeforum-admin">';
  $out .= $this->sfaHead('Topics', 'comments', 'Choose a category, drag <i class="fa fa-bars"></i> to reorder, switch Pin / Lock / Hidden / Unpublished, or move a topic to another category.');

  if(!$categories->count()) {
    $out .= '<p>No categories found.</p></div>';
    return $out;
  }

  // Category filter
  $out .= '<div class="sfa-card">';
  $out .= '<form method="get" action="' . $this->page->url . 'topics/" class="pw-form">';
  $out .= '<label>Category &nbsp; <select name="cat" onchange="this.form.submit()">';
  foreach($categories as $c) {
    $label = $c->title;
    if($c->parent && $c->parent->template->name === 'forum-group') {
      $label = $c->parent->title . ' / ' . $label;
    }
    $sel = ($currentCat && $currentCat->id === $c->id) ? ' selected' : '';
    $out .= '<option value="' . (int)$c->id . '"' . $sel . '>' . $this->sanitizer->entities($label) . '</option>';
  }
  $out .= '</select></label>';
  $out .= '</form>';
  $out .= '</div>';
  // Quick action: add new topic to current category
  try {
    $tplTopic = $this->wire('templates')->get('forum-topic');
    $adminUrl = rtrim($this->wire('config')->urls->admin, '/') . '/';
    if($tplTopic && $tplTopic->id && $currentCat && $currentCat->id) {
      $addUrl = $adminUrl . "page/add/?parent_id=" . (int)$currentCat->id . "&template_id=" . (int)$tplTopic->id;
      $out .= '<div class="sfa-card" style="margin-top:0">';
      $out .= '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
      $out .= '<a class="sfa-btn is-primary sf-add-topic-modal" href="' . $addUrl . '" data-sf-modal="1"><i class="fa fa-plus"></i> New topic in this category</a>';
      $out .= '<span class="sf-muted">Opens in a dialog.</span>
      ' . $this->sfAdminModalAssets() . '';
      $out .= '</div></div>';
    }
  } catch(\Throwable $e) { /* ignore */ }



  // Topics sortable list
  $topics = $currentCat ? $currentCat->children("template=forum-topic, include=all, sort=sort") : new PageArray();

  $out .= '<div class="sfa-card">';
  $out .= '<div class="sfa-panel-h"><h3><i class="fa fa-comments-o"></i> ' . $this->sanitizer->entities($currentCat->title) . '</h3><span class="sf-muted">' . $topics->count() . ' topics</span></div>';

  $out .= '<ul id="sf-topics-sortable" class="sf-sortable" data-parent="' . (int)$currentCat->id . '">';
  if(!$topics->count()) {
    $out .= '<li class="sf-drop-hint" data-id="0">No topics yet</li>';
  } else {
    foreach($topics as $t) {
      $isPinned = $t->hasField('forum_is_pinned') && (int)$t->get('forum_is_pinned') === 1;
      $isLocked = $t->hasField('forum_is_locked') && (int)$t->get('forum_is_locked') === 1;
      $isHidden = $t->isHidden();
      $isUnpub  = $t->isUnpublished();

      $out .= '<li data-id="' . (int)$t->id . '">';
      $out .= '<div class="sf-title"><span class="sf-drag" title="Drag to reorder"><i class="fa fa-bars"></i></span><a class="sfa-title-link" href="' . $this->page->url . 'edit-topic/?id=' . (int)$t->id . '"><strong>' . $this->sanitizer->entities($t->title) . '</strong></a></div>';
      $out .= '<div class="sf-actions">';
      $out .= '<label title="Pinned"><input class="sf-qt" type="checkbox" data-type="topic" data-id="' . (int)$t->id . '" data-field="forum_is_pinned"' . ($isPinned ? ' checked' : '') . '>Pin</label>';
      $out .= '<label title="Locked"><input class="sf-qt" type="checkbox" data-type="topic" data-id="' . (int)$t->id . '" data-field="forum_is_locked"' . ($isLocked ? ' checked' : '') . '>Lock</label>';
      $out .= '<label title="Hidden (page status)"><input class="sf-qt" type="checkbox" data-type="topic" data-id="' . (int)$t->id . '" data-field="status_hidden"' . ($isHidden ? ' checked' : '') . '>Hidden</label>';
      $out .= '<label title="Unpublished (page status)"><input class="sf-qt" type="checkbox" data-type="topic" data-id="' . (int)$t->id . '" data-field="status_unpublished"' . ($isUnpub ? ' checked' : '') . '>Unpub</label>';

      // Move dropdown
      $out .= '<select class="pw-input sf-move-topic" data-id="' . (int)$t->id . '">';
      foreach($categories as $c2) {
        $label2 = $c2->title;
        if($c2->parent && $c2->parent->template->name === 'forum-group') {
          $label2 = $c2->parent->title . ' / ' . $label2;
        }
        $sel2 = ($t->parent && $t->parent->id === $c2->id) ? ' selected' : '';
        $out .= '<option value="' . (int)$c2->id . '"' . $sel2 . '>' . $this->sanitizer->entities($label2) . '</option>';
      }
      $out .= '</select>';

      $out .= '<a class="pw-button pw-button-small sf-edit-link" href="' . $this->page->url . 'edit-topic/?id=' . (int)$t->id . '"><i class="fa fa-pencil"></i> Edit</a>';
      $out .= '</div></li>';
    }
  }
  $out .= '</ul>';
  $out .= '</div>';

  // Modal containers
  $out .= '<div class="sf-admin-modal-backdrop" id="sfAdminModalBg"></div>';
  $out .= '<div class="sf-admin-modal" id="sfAdminModal"><div class="box">';
  $out .= '<div class="head"><strong id="sfAdminModalTitle">Edit</strong><button type="button" class="sfa-btn" id="sfAdminModalClose"><i class="fa fa-times"></i> Close</button></div>';
  $out .= '<div class="body" id="sfAdminModalBody"><div class="sf-muted">Loading…</div></div>';
  $out .= '</div></div>';

  // JS
  $out .= '<script>
  (function($){
    var postUrl = ' . json_encode($this->page->url . 'topics/') . ';
    var csrfName = ' . json_encode($tokenName) . ';
    var csrfValue = ' . json_encode($tokenValue) . ';
    function ajaxPost(data, cb){
      data = data || {};
      data[csrfName] = csrfValue;
      $.post(postUrl, data, function(res){
        if(cb) cb(res || {});
      }, "json");
    }
    function orderIds($ul){
      return $ul.children("li").map(function(){
        var id = $(this).data("id");
        return id ? id : null;
      }).get().filter(Boolean);
    }

    $("#sf-topics-sortable").sortable({
      handle: ".sf-drag",
      update: function(){
        ajaxPost({sf_action:"sort_topics", parent: $("#sf-topics-sortable").data("parent"), order: orderIds($("#sf-topics-sortable"))});
      }
    });

    // Move topic on dropdown change
    $(document).on("change", "select.sf-move-topic", function(){
      var id = $(this).data("id");
      var parent = $(this).val();
      ajaxPost({sf_action:"move_topic", id:id, parent:parent}, function(res){
        if(!res.ok) alert(res.message || "Move failed");
        else sfaReload();
      });
    });

    // Quick toggles
    $(document).on("change", ".sf-qt", function(){
      var el = this;
      ajaxPost({
        sf_action: "toggle_field",
        type: $(el).data("type"),
        id: $(el).data("id"),
        field: $(el).data("field"),
        value: el.checked ? 1 : 0
      }, function(res){
        if(!res.ok){
          try { el.checked = !el.checked; } catch(e){}
          alert(res.message || "Save failed");
        }
      });
    });

    // Modal open/close
    function openModal(title, html){
      $("#sfAdminModalTitle").text(title || "Edit");
      $("#sfAdminModalBody").html(html || "");
      $("#sfAdminModalBg").show();
      $("#sfAdminModal").css("display","flex");
    }
    function closeModal(){
      $("#sfAdminModal").hide();
      $("#sfAdminModalBg").hide();
      $("#sfAdminModalBody").html(\'<div class="sf-muted">Loading…</div>\');
    }
    $("#sfAdminModalClose, #sfAdminModalBg").on("click", closeModal);

    // Load edit form
    $(document).on("click", ".sf-edit", function(e){
      e.preventDefault();
      var type = $(this).data("type");
      var id = $(this).data("id");
      openModal("Edit " + type, \'<div class="sf-muted">Loading…</div>\');
      ajaxPost({sf_action:"load_edit_form", type:type, id:id}, function(res){
        if(res && res.ok && res.html){
          openModal("Edit " + type, res.html);
        } else {
          openModal("Edit " + type, \'<div class="pw-notice pw-notice-error">\' + (res.message || "Failed to load form") + \'</div>\');
        }
      });
    });

    // Save edit form (AJAX)
    $(document).on("submit", "form.sf-admin-edit-form", function(e){
      e.preventDefault();
      var $f = $(this);
      var data = $f.serializeArray();
      data.push({name: "sf_action", value: "save_edit_form"});
      $.post(postUrl, data, function(res){
        if(res && res.ok){
          closeModal();
          sfaReload();
        } else {
          alert((res && res.message) ? res.message : "Save failed");
        }
      }, "json");
    });

  })(jQuery);
  </script>';

  $out .= '</div>'; // nativeforum-admin
  return $out;
}

/************************************************************
 * TOPIC EDITOR (full page): the topic, its text and all its replies
 ************************************************************/

/** Rich text editor for a post body (TinyMCE / CKEditor from the admin, plain textarea as fallback). */
protected function sfaBodyEditor(string $name, string $value, string $label, int $rows = 14) {
  $modules = $this->wire('modules');
  /** @var NativeForum $forum */
  $forum = $modules->get('NativeForum');
  $rich = $forum && method_exists($forum, 'richBodies') ? $forum->richBodies() : true;
  $f = null;
  if($rich && $modules->isInstalled('InputfieldTinyMCE')) {
    try {
      $f = $modules->get('InputfieldTinyMCE');
      $f->set('features', ['toolbar', 'menubar', 'statusbar', 'stickybars', 'imgResize', 'pasteFilter']);
      $f->set('toolbar', 'styles bold italic underline strikethrough | link blockquote hr | bullist numlist | table | removeformat code');
      $f->set('lazyMode', 1);
      $f->set('height', max(220, $rows * 24));
    } catch(\Throwable $e) { $f = null; }
  }
  if(!$f && $rich && $modules->isInstalled('InputfieldCKEditor')) {
    try {
      $f = $modules->get('InputfieldCKEditor');
      $f->set('toolbar', "Format, Bold, Italic, Underline, Strike, -, RemoveFormat\nNumberedList, BulletedList, -, Blockquote\nLink, Unlink, -, Table, HorizontalRule, -, Sourcedialog");
      $f->set('useACF', 0);
      $f->set('usePurifier', 0);
    } catch(\Throwable $e) { $f = null; }
  }
  if(!$f) {
    $f = $modules->get('InputfieldTextarea');
    $f->attr('rows', $rows);
  }
  $f->attr('name', $name);
  $f->attr('id', 'sfa_' . preg_replace('~[^a-z0-9_]+~i', '_', $name));
  $f->label = $label;
  $f->attr('value', $value);
  return $f;
}

/** Author field value: user name (resolved again on save from a name, e-mail or ID). */
protected function sfaFindUser(string $s): ?User {
  $s = trim($s);
  if($s === '') return null;
  $users = $this->wire('users');
  $u = null;
  if(ctype_digit($s)) $u = $users->get((int) $s);
  if((!$u || !$u->id) && strpos($s, '@') !== false) $u = $users->get('email=' . $this->sanitizer->selectorValue($s));
  if(!$u || !$u->id) $u = $users->get('name=' . $this->sanitizer->pageName($s));
  if(!$u || !$u->id) {
    try { if($this->wire('fields')->get('forum_nickname')) $u = $users->get('forum_nickname=' . $this->sanitizer->selectorValue($s)); } catch(\Throwable $e) {}
  }
  return ($u && $u->id && !$u->isGuest()) ? $u : null;
}

protected function sfaAuthorOf(Page $p): ?User {
  try {
    $a = $p->getUnformatted('forum_author');
    if($a instanceof PageArray) $a = $a->first();
    return ($a instanceof User && $a->id) ? $a : null;
  } catch(\Throwable $e) { return null; }
}

protected function sfaSetAuthor(Page $p, User $u): void {
  if(!$p->hasField('forum_author')) return;
  $fld = $this->wire('fields')->get('forum_author');
  if($fld && (int) $fld->get('derefAsPage') > 0) $p->set('forum_author', $u);
  else { $pa = new PageArray(); $pa->add($u); $p->set('forum_author', $pa); }
}

/** Has the text really changed? (editors re-format HTML: compare the cleaned, whitespace-normalised versions) */
protected function sfaBodyChanged(string $old, string $new): bool {
  if($old === $new) return false;
  $forum = $this->wire('modules')->get('NativeForum');
  $n = function($x) use ($forum) {
    $x = $forum && method_exists($forum, 'cleanPostBody') ? $forum->cleanPostBody($x) : $x;
    $x = str_replace(["\r", '&nbsp;', "\xC2\xA0"], ['', ' ', ' '], $x);
    $x = preg_replace('~\s+~u', ' ', $x);
    return trim(preg_replace('~>\s+<~', '><', $x));
  };
  return $n($old) !== $n($new);
}

public function ___executeEditTopic() {
  $modules = $this->wire('modules');
  $pages   = $this->wire('pages');
  $input   = $this->wire('input');
  $session = $this->wire('session');
  $sanitizer = $this->sanitizer;
  /** @var NativeForum $forum */
  $forum = $modules->get('NativeForum');

  $id = (int) ($input->post('topic_id') ?: $input->get('id'));
  $topic = $id ? $pages->get("id=$id, include=all") : null;
  if(!$topic || !$topic->id || $topic->template->name !== 'forum-topic') {
    $this->error('Topic not found.');
    $session->redirect($this->page->url . 'topics/');
  }
  $backUrl = $this->page->url . 'topics/?cat=' . (int) $topic->parent_id;
  $rp = max(1, (int) $input->get('rp'));
  $perPage = 20;
  // ?reply=123 → the replies page that contains this reply, opened at it
  $jump = (int) $input->get('reply');
  if($jump && !$input->requestMethod('POST')) {
    $jr = $pages->get("id=$jump, include=all");
    if($jr && $jr->id && (int) $jr->parent_id === (int) $topic->id) {
      $before = $pages->count("template=forum-reply, parent={$topic->id}, include=all, created<" . (int) $jr->created)
        + $pages->count("template=forum-reply, parent={$topic->id}, include=all, created=" . (int) $jr->created . ", id<" . (int) $jr->id);
      $rp2 = (int) floor($before / $perPage) + 1;
      $session->redirect($this->page->url . 'edit-topic/?id=' . (int) $topic->id . ($rp2 > 1 ? '&rp=' . $rp2 : '') . '#reply-' . (int) $jr->id);
    }
  }
  $selfUrl = $this->page->url . 'edit-topic/?id=' . (int) $topic->id . ($rp > 1 ? '&rp=' . $rp : '');

  /* ---------- save ---------- */
  if($input->requestMethod('POST') && $input->post('sfa_save_topic')) {
    try { $session->CSRF->validate(); } catch(\Throwable $e) { $this->error('The form expired, please try again.'); $session->redirect($selfUrl); }
    $post = $input->post;
    $changed = 0; $trashed = 0; $warn = [];

    // topic
    $topic->of(false);
    $title = $sanitizer->text((string) $post->title, ['maxLength' => 255]);
    if($title !== '') $topic->title = $title;
    else $warn[] = 'The title cannot be empty (kept the old one).';
    if($topic->hasField('forum_body')) {
      $nb = (string) $post->body;
      $ob = (string) $topic->getUnformatted('forum_body');
      if($this->sfaBodyChanged($ob, $nb)) $topic->set('forum_body', $forum->cleanPostBody($nb));
    }
    if($topic->hasField('field_forum_summary_topic')) $topic->set('field_forum_summary_topic', $sanitizer->text((string) $post->summary, ['maxLength' => 255]));
    if($topic->hasField('forum_tags')) $topic->set('forum_tags', $sanitizer->text((string) $post->tags, ['maxLength' => 255]));
    if($topic->hasField('forum_views') && $post->views !== null && $post->views !== '') $topic->set('forum_views', max(0, (int) $post->views));
    if($topic->hasField('forum_is_pinned')) $topic->set('forum_is_pinned', $post->pinned ? 1 : 0);
    if($topic->hasField('forum_is_locked')) $topic->set('forum_is_locked', $post->locked ? 1 : 0);
    if($post->hidden) $topic->addStatus(Page::statusHidden); else $topic->removeStatus(Page::statusHidden);
    if($post->unpublished) $topic->addStatus(Page::statusUnpublished); else $topic->removeStatus(Page::statusUnpublished);
    $au = (string) $post->author;
    $cur = $this->sfaAuthorOf($topic);
    if(trim($au) !== '' && (!$cur || (trim($au) !== $cur->name && (string) $cur->id !== trim($au)))) {
      $nu = $this->sfaFindUser($au);
      if($nu) $this->sfaSetAuthor($topic, $nu); else $warn[] = 'Author “' . $au . '” was not found.';
    }
    $np = (int) $post->category;
    if($np && $np !== (int) $topic->parent_id) {
      $cat = $pages->get("id=$np, include=all");
      if($cat && $cat->id && $cat->template->name === 'forum-category') $topic->parent = $cat;
    }
    $pages->save($topic);
    $changed++;
    $cs = trim((string) $post->created);
    $ts = preg_match('~^\d{4}-\d{2}-\d{2}( \d{1,2}:\d{2}(:\d{2})?)?$~', $cs) ? (int) strtotime($cs) : 0;
    if($ts && !strpos($cs, ':')) $ts += (int) $topic->created - (int) strtotime(date('Y-m-d', (int) $topic->created)); // date only: keep the time
    if($ts && abs($ts - (int) $topic->created) > 59) {
      $topic->created = $ts;
      $pages->save($topic, ['quiet' => true]);
    }

    // replies
    // flat field names (ProcessWire drops nested POST arrays): reply_body_123, reply_author_123 …
    $ids = array_filter(array_map('intval', explode(',', (string) $post->reply_ids)));
    $rows = [];
    foreach($ids as $rid) {
      $row = [];
      foreach(['body', 'author', 'hidden', 'trash'] as $k) {
        $v = $post->get('reply_' . $k . '_' . $rid);
        if($v !== null) $row[$k] = $v;
      }
      $rows[$rid] = $row;
    }
    if(count($rows)) {
      foreach($rows as $rid => $row) {
        $rid = (int) $rid;
        if(!$rid) continue;
        $r = $pages->get("id=$rid, include=all");
        if(!$r || !$r->id || $r->template->name !== 'forum-reply' || (int) $r->parent_id !== (int) $topic->id) continue;
        if(!empty($row['trash'])) { $pages->trash($r); $trashed++; continue; }
        $r->of(false);
        $dirty = false;
        if(isset($row['body']) && $r->hasField('forum_body')) {
          $ob = (string) $r->getUnformatted('forum_body');
          if($this->sfaBodyChanged($ob, (string) $row['body'])) { $r->set('forum_body', $forum->cleanPostBody((string) $row['body'])); $dirty = true; }
        }
        if($r->hasField('forum_is_deleted') && isset($row['body'])) {
          $hv = !empty($row['hidden']) ? 1 : 0;
          if($hv !== (int) $r->getUnformatted('forum_is_deleted')) { $r->set('forum_is_deleted', $hv); $dirty = true; }
        }
        if(isset($row['author'])) {
          $cur = $this->sfaAuthorOf($r);
          $au = trim((string) $row['author']);
          if($au !== '' && (!$cur || ($au !== $cur->name && (string) $cur->id !== $au))) {
            $nu = $this->sfaFindUser($au);
            if($nu) { $this->sfaSetAuthor($r, $nu); $dirty = true; } else $warn[] = 'Reply #' . $rid . ': author “' . $au . '” was not found.';
          }
        }
        if($dirty) { $pages->save($r); $changed++; }
      }
    }
    try { $this->wire('cache')->deleteFor('NativeForumView'); } catch(\Throwable $e) {}
    foreach($warn as $w) $this->warning($w);
    $this->message('Saved.' . ($changed > 1 ? ' Replies updated: ' . ($changed - 1) . '.' : '') . ($trashed ? ' Moved to the trash: ' . $trashed . '.' : ''));
    $session->redirect($selfUrl);
  }

  /* ---------- form ---------- */
  $total = $pages->count("template=forum-reply, parent={$topic->id}, include=all");
  $pagesN = max(1, (int) ceil($total / $perPage));
  if($rp > $pagesN) $rp = $pagesN;
  $replies = $pages->find("template=forum-reply, parent={$topic->id}, include=all, sort=created, sort=id, start=" . (($rp - 1) * $perPage) . ", limit=$perPage");

  $this->headline('Edit topic: ' . $topic->title);
  $this->browserTitle('Edit topic');

  $out = $this->sfAdminCss() . '<div class="nativeforum-admin sfa-edit-topic">';
  $editUrl = $this->wire('config')->urls->admin . 'page/edit/?id=' . (int) $topic->id;
  $actions = '<a class="sfa-btn" href="' . $sanitizer->entities($backUrl) . '"><i class="fa fa-arrow-left"></i> Topics</a>'
    . '<a class="sfa-btn" href="' . $sanitizer->entities($topic->url) . '" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> View on forum</a>'
    . '<a class="sfa-btn" href="' . $sanitizer->entities($editUrl) . '" title="ProcessWire page editor (attachments, all fields)"><i class="fa fa-cog"></i> Page editor</a>';
  $out .= $this->sfaHead((string) $topic->title, 'pencil', 'Edit the topic, its text and all replies. Changes are saved with one click and are visible on the forum right away.', $actions);

  /** @var InputfieldForm $form */
  $form = $modules->get('InputfieldForm');
  $form->attr('id', 'sfa-topic-form');
  $form->attr('method', 'post');
  $form->attr('action', $selfUrl);
  $form->addClass('sfa-topic-form');

  $h = $modules->get('InputfieldHidden'); $h->attr('name', 'topic_id'); $h->attr('value', (int) $topic->id); $form->add($h);

  // left: the post
  $left = $modules->get('InputfieldFieldset');
  $left->label = 'Topic';
  $left->icon = 'file-text-o';
  $left->columnWidth = 68;
  $f = $modules->get('InputfieldText'); $f->attr('name', 'title'); $f->label = 'Title'; $f->required = true; $f->attr('value', (string) $topic->title); $left->add($f);
  if($topic->hasField('forum_body')) $left->add($this->sfaBodyEditor('body', (string) $topic->getUnformatted('forum_body'), 'Text', 18));
  if($topic->hasField('field_forum_summary_topic')) {
    $f = $modules->get('InputfieldText'); $f->attr('name', 'summary'); $f->label = 'Summary'; $f->description = 'Short description shown in lists and used for search engines / link previews.';
    $f->attr('maxlength', 255); $f->attr('value', (string) $topic->getUnformatted('field_forum_summary_topic')); $f->collapsed = Inputfield::collapsedBlank; $left->add($f);
  }
  $form->add($left);

  // right: settings
  $right = $modules->get('InputfieldFieldset');
  $right->label = 'Settings';
  $right->icon = 'sliders';
  $right->columnWidth = 32;

  $f = $modules->get('InputfieldCheckbox'); $f->attr('name', 'pinned'); $f->label = 'Pinned'; $f->checkboxLabel = 'Pinned (always on top)'; $f->skipLabel = Inputfield::skipLabelHeader;
  $f->attr('checked', $topic->hasField('forum_is_pinned') && (int) $topic->getUnformatted('forum_is_pinned') ? 'checked' : ''); $right->add($f);
  $f = $modules->get('InputfieldCheckbox'); $f->attr('name', 'locked'); $f->label = 'Locked'; $f->checkboxLabel = 'Locked (no new replies)'; $f->skipLabel = Inputfield::skipLabelHeader;
  $f->attr('checked', $topic->hasField('forum_is_locked') && (int) $topic->getUnformatted('forum_is_locked') ? 'checked' : ''); $right->add($f);
  $f = $modules->get('InputfieldCheckbox'); $f->attr('name', 'hidden'); $f->label = 'Hidden'; $f->checkboxLabel = 'Hidden (not listed)'; $f->skipLabel = Inputfield::skipLabelHeader;
  $f->attr('checked', $topic->isHidden() ? 'checked' : ''); $right->add($f);
  $f = $modules->get('InputfieldCheckbox'); $f->attr('name', 'unpublished'); $f->label = 'Unpublished'; $f->checkboxLabel = 'Unpublished (only staff can see it)'; $f->skipLabel = Inputfield::skipLabelHeader;
  $f->attr('checked', $topic->isUnpublished() ? 'checked' : ''); $right->add($f);

  $rootId = (int) ($forum->forumRoot() ? $forum->forumRoot()->id : 0);
  $cats = $rootId ? $pages->find("template=forum-category, has_parent=$rootId, include=all, sort=parent.sort, sort=sort") : new PageArray();
  $f = $modules->get('InputfieldSelect'); $f->attr('name', 'category'); $f->label = 'Category'; $f->required = true;
  foreach($cats as $c) $f->addOption((int) $c->id, ($c->parent && $c->parent->template->name === 'forum-group' ? $c->parent->title . ' / ' : '') . $c->title);
  $f->attr('value', (int) $topic->parent_id); $right->add($f);

  $au = $this->sfaAuthorOf($topic);
  $f = $modules->get('InputfieldText'); $f->attr('name', 'author'); $f->label = 'Author'; $f->description = 'User name, e-mail or ID.';
  $f->attr('value', $au ? $au->name : ''); $f->attr('placeholder', 'username'); $right->add($f);

  $f = $modules->get('InputfieldDatetime'); $f->attr('name', 'created'); $f->label = 'Created';
  // one text value "Y-m-d H:i" (the HTML5 date+time variant posts a nested array that ProcessWire drops)
  $f->set('inputType', 'text'); $f->set('datepicker', 3); $f->set('dateInputFormat', 'Y-m-d'); $f->set('timeInputFormat', 'H:i');
  $f->set('yearRange', '-30:+1');
  $f->attr('value', (int) $topic->created); $right->add($f);

  if($topic->hasField('forum_views')) {
    $f = $modules->get('InputfieldInteger'); $f->attr('name', 'views'); $f->label = 'Views'; $f->attr('min', 0);
    $f->attr('value', (int) $topic->getUnformatted('forum_views')); $f->columnWidth = 50; $right->add($f);
  }
  $m = $modules->get('InputfieldMarkup'); $m->label = 'Replies'; $m->value = '<strong style="font-size:1.4em">' . (int) $total . '</strong>'; $m->columnWidth = 50; $right->add($m);
  if($topic->hasField('forum_tags')) {
    $f = $modules->get('InputfieldText'); $f->attr('name', 'tags'); $f->label = 'Tags'; $f->description = 'Comma separated.';
    $f->attr('value', (string) $topic->getUnformatted('forum_tags')); $right->add($f);
  }
  $form->add($right);

  // replies
  $wrap = $modules->get('InputfieldFieldset');
  $wrap->label = 'Replies (' . (int) $total . ')';
  $wrap->icon = 'comments-o';
  $wrap->description = $total ? 'Click a reply to open it and edit its text. “Hidden” keeps the reply but shows it as removed; “Move to trash” deletes it (it can be restored from the ProcessWire trash).' : '';
  if(!$total) {
    $m = $modules->get('InputfieldMarkup'); $m->value = '<p class="sf-muted">No replies yet.</p>'; $m->skipLabel = Inputfield::skipLabelHeader; $wrap->add($m);
  }
  $h = $modules->get('InputfieldHidden'); $h->attr('name', 'reply_ids'); $h->attr('value', implode(',', $replies->explode('id'))); $form->add($h);
  $no = ($rp - 1) * $perPage + 1;
  foreach($replies as $r) {
    $no++;
    $ra = $this->sfaAuthorOf($r);
    $who = $ra ? ($forum && method_exists($forum, 'displayName') ? $forum->displayName($ra) : $ra->name) : 'Guest';
    $isHid = $r->hasField('forum_is_deleted') && (int) $r->getUnformatted('forum_is_deleted');
    $body = (string) $r->getUnformatted('forum_body');
    $ex = trim(preg_replace('~\s+~u', ' ', strip_tags(preg_replace('~<blockquote\b.*?</blockquote>~is', ' ', $body))));
    $short = function_exists('mb_strimwidth') ? mb_strimwidth($ex, 0, 70, '…') : substr($ex, 0, 70);
    if(function_exists('mb_strimwidth')) $ex = mb_strimwidth($ex, 0, 160, '…');
    $fs = $modules->get('InputfieldFieldset');
    $fs->attr('id', 'sfa-reply-' . (int) $r->id);
    $fs->label = '#' . $no . ' · ' . $who . ' · ' . date('j. n. Y H:i', (int) $r->created) . ($isHid ? ' · HIDDEN' : '') . ($r->isUnpublished() ? ' · unpublished' : '') . ($short !== '' ? ' — ' . $short : '');
    $fs->icon = $isHid ? 'eye-slash' : 'comment-o';
    $fs->description = $ex !== '' ? $ex : '(no text)';
    $fs->collapsed = Inputfield::collapsedYes;
    $fs->addClass('sfa-reply-fs');
    $fs->add($this->sfaBodyEditor('reply_body_' . (int) $r->id, $body, 'Text', 10));
    $f = $modules->get('InputfieldText'); $f->attr('name', 'reply_author_' . (int) $r->id); $f->label = 'Author'; $f->description = 'User name, e-mail or ID.';
    $f->attr('value', $ra ? $ra->name : ''); $f->columnWidth = 40; $fs->add($f);
    if($r->hasField('forum_is_deleted')) {
      $f = $modules->get('InputfieldCheckbox'); $f->attr('name', 'reply_hidden_' . (int) $r->id); $f->label = 'Hidden'; $f->checkboxLabel = 'Hide this reply (shown as removed)';
      $f->attr('checked', $isHid ? 'checked' : ''); $f->columnWidth = 30; $fs->add($f);
    }
    $f = $modules->get('InputfieldCheckbox'); $f->attr('name', 'reply_trash_' . (int) $r->id); $f->label = 'Delete'; $f->checkboxLabel = 'Move to trash';
    $f->columnWidth = 30; $f->addClass('sfa-danger-check'); $fs->add($f);
    $m = $modules->get('InputfieldMarkup'); $m->skipLabel = Inputfield::skipLabelHeader;
    $m->value = '<a class="sfa-btn" href="' . $sanitizer->entities($forum->replyUrl($r)) . '" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> View on forum</a> '
      . '<a class="sfa-btn" href="' . $sanitizer->entities($this->wire('config')->urls->admin . 'page/edit/?id=' . (int) $r->id) . '"><i class="fa fa-cog"></i> Page editor</a>';
    $fs->add($m);
    $wrap->add($fs);
  }
  if($pagesN > 1) {
    $pg = '<div class="sfa-pager">';
    for($i = 1; $i <= $pagesN; $i++) {
      $u = $this->page->url . 'edit-topic/?id=' . (int) $topic->id . ($i > 1 ? '&rp=' . $i : '') . '#sfa-replies';
      $pg .= $i === $rp ? '<span class="sfa-btn is-primary">' . $i . '</span>' : '<a class="sfa-btn" href="' . $sanitizer->entities($u) . '">' . $i . '</a>';
    }
    $pg .= '<span class="sf-muted">Save before switching pages – unsaved changes on this page are lost.</span></div>';
    $m = $modules->get('InputfieldMarkup'); $m->skipLabel = Inputfield::skipLabelHeader; $m->value = $pg; $wrap->add($m);
  }
  $wrap->attr('id', 'sfa-replies');
  $form->add($wrap);

  $b = $modules->get('InputfieldSubmit'); $b->attr('name', 'sfa_save_topic'); $b->attr('value', 'Save'); $b->icon = 'check'; $b->addClass('head_button_clone');
  $form->add($b);

  $out .= $form->render();
  // Konkat / Uikit dark mode: dark editor content (TinyMCE's content CSS is light by default)
  $out .= '<script>(function(){function dark(){var b=document.body;return b&&(b.classList.contains("dark-theme")||b.classList.contains("pw-dark")||(getComputedStyle(b).colorScheme||"").indexOf("dark")===0&&!b.classList.contains("light-theme"));}function css(){var t=document.createElement("div");t.style.cssText="position:absolute;left:-9999px;background:var(--pw-inputs-background,#1d1f22);color:var(--pw-text-color,#e6e6e6);border-color:var(--pw-main-color,#e83561)";document.body.appendChild(t);var c=getComputedStyle(t);var bg=c.backgroundColor,fg=c.color,mc=c.borderTopColor;t.remove();if(!bg||bg==="rgba(0, 0, 0, 0)")bg="#1d1f22";return ":root{color-scheme:dark}html,body{background:"+bg+"!important;color:"+fg+"!important}a{color:"+mc+"}blockquote{border-left:3px solid "+mc+";background:rgba(127,127,127,.12);color:"+fg+";margin:.6em 0;padding:.4em .8em}table,td,th{border-color:rgba(127,127,127,.4)!important}code,pre{background:rgba(127,127,127,.18);color:"+fg+"}";}function add(ed){try{var d=ed.getDoc();if(!d||d.getElementById("sfa-dark"))return;var st=d.createElement("style");st.id="sfa-dark";st.textContent=css();(d.head||d.documentElement).appendChild(st);}catch(e){}}function hook(ed){ed.on("init",function(){if(dark())add(ed);});}function boot(){var n=0;(function tick(){if(window.tinymce&&tinymce.get&&dark()){tinymce.get().forEach(function(ed){if(ed.initialized)add(ed);else if(!ed._sfaHook){ed._sfaHook=1;hook(ed);}});}if(++n<120)setTimeout(tick,500);})();}if(document.readyState!=="loading")boot();else document.addEventListener("DOMContentLoaded",boot);})();</script>';
  // open a reply directly (links from Reports / Dashboard: #reply-123)
  $out .= '<script>(function(){function go(){var m=(location.hash||"").match(/^#reply-(\d+)$/);if(!m)return;var w=document.getElementById("wrap_sfa-reply-"+m[1])||document.getElementById("sfa-reply-"+m[1]);if(!w)return;if(window.jQuery){var $w=jQuery(w);if($w.hasClass("InputfieldStateCollapsed"))$w.children(".InputfieldHeader").trigger("click");}setTimeout(function(){w.scrollIntoView({behavior:"smooth",block:"start"});},150);}if(document.readyState!=="loading")setTimeout(go,50);else document.addEventListener("DOMContentLoaded",function(){setTimeout(go,50)});})();</script>';
  $out .= '</div>';
  return $out;
}

public function ___executeMessages() {
    $modules = $this->wire('modules');
    $pages   = $this->wire('pages');
    $users   = $this->wire('users');
    $input   = $this->wire('input');
    $user    = $this->wire('user');
    /** @var NativeForum $forum */
    $forum   = $modules->get('NativeForum');

    $messagesRoot = $forum->messagesRoot();

    $out = $this->sfAdminCss() . '<div class="nativeforum-admin">' . $this->sfaHead('Private messages', 'envelope-o', 'Send a message to a member and see the latest private messages of the forum.');

    if(!$messagesRoot || !$messagesRoot->id) {
      $out .= '<p>No messages root page found. Ensure Native Forum is installed and configured.</p>';
      $out .= '</div>'; return $out;
    }

    // --- Create message form in admin ---
    /** @var InputfieldForm $form */
    $form = $modules->get('InputfieldForm');
    $form->attr('method', 'post');
    $form->attr('action', $input->url());
    $form->attr('id', 'nativeforum-messages-form');

    /** @var InputfieldSelect $fTo */
    $fTo = $modules->get('InputfieldSelect');
    $fTo->label = 'To user';
    $fTo->name  = 'msg_to';
    $fTo->required = true;

    $allUsers = $users->find('');
    foreach($allUsers as $u) {
      $fTo->addOption($u->id, $u->name);
    }
    $form->add($fTo);

    /** @var InputfieldText $fSubject */
    $fSubject = $modules->get('InputfieldText');
    $fSubject->label = 'Subject';
    $fSubject->name  = 'msg_subject';
    $fSubject->required = true;
    $form->add($fSubject);

    /** @var InputfieldTextarea $fBody */
    $fBody = $modules->get('InputfieldTextarea');
    $fBody->label = 'Body';
    $fBody->name  = 'msg_body';
    $fBody->required = true;
    $form->add($fBody);

    /** @var InputfieldSubmit $submit */
    $submit = $modules->get('InputfieldSubmit');
    $submit->name  = 'submit_new_message';
    $submit->value = 'Send message';
    $submit->icon  = 'paper-plane';
    $form->add($submit);

    if($input->post->submit_new_message) {
      $form->processInput($input->post);
      if($form->getErrors()) {
        $this->error('Please fix the errors in the form.');
      } else {
        $toUser   = $users->get((int) $fTo->value);
        $subject  = trim($fSubject->value);
        $body     = trim($fBody->value);
        if($toUser && $toUser->id && $subject && $body) {
          try {
            $msg = $forum->sendMessage($user, $toUser, $subject, $body);
            $this->message('Message sent: ' . $msg->title);
            $this->session->redirect($input->url());
          } catch(\Exception $e) {
            $this->error($e->getMessage());
          }
        }
      }
    }

    $messages = $pages->find('template=forum-message, include=all, sort=-created, limit=50');
    $unread = (int) $pages->count('template=forum-message, include=all, forum_is_read=0');

    $out .= '<div class="sfa-grid sfa-grid-msg">';
    $out .= '<div class="sfa-panel"><div class="sfa-panel-h"><h3><i class="fa fa-paper-plane-o"></i> New message</h3></div>' . $form->render() . '</div>';

    $out .= '<div class="sfa-panel"><div class="sfa-panel-h"><h3><i class="fa fa-inbox"></i> Latest messages</h3>'
      . ($unread ? '<span class="sfa-badge is-red">' . $unread . ' unread</span>' : '<span class="sf-muted">all read</span>') . '</div>';
    if(!$messages->count()) {
      $out .= '<p class="sfa-empty">No messages yet.</p>';
    } else {
      $out .= '<table class="sfa-table"><thead><tr><th>Subject</th><th>From</th><th>To</th><th>Date</th><th></th></tr></thead><tbody>';
      foreach($messages as $msg) {
        $from = $msg->getUnformatted('forum_from_user'); if($from instanceof PageArray) $from = $from->first();
        $to   = $msg->getUnformatted('forum_to_user');   if($to instanceof PageArray) $to = $to->first();
        $isRead = (int) $msg->getUnformatted('forum_is_read') === 1;
        $replyUrl = ($messagesRoot && $from && $from->id) ? $messagesRoot->url . '?to=' . (int) $from->id : '';
        $out .= '<tr>';
        $out .= '<td><a href="' . $msg->url . '" target="_blank"><strong>' . $this->sanitizer->entities($msg->title) . '</strong></a>'
          . (!$isRead ? ' <span class="sfa-badge is-accent">new</span>' : '')
          . '<div class="sfa-excerpt">' . $this->sanitizer->entities(mb_strimwidth(trim(strip_tags((string) $msg->getUnformatted('forum_body'))), 0, 140, '…')) . '</div></td>';
        $out .= '<td>' . $this->sfaUserCell($from) . '</td>';
        $out .= '<td>' . $this->sfaUserCell($to) . '</td>';
        $out .= '<td class="sfa-nowrap">' . date('Y-m-d H:i', $msg->created) . '</td>';
        $out .= '<td><div class="sfa-btns">'
          . ($replyUrl ? '<a class="sfa-btn" href="' . $replyUrl . '" target="_blank" title="Reply on the forum"><i class="fa fa-reply"></i></a>' : '')
          . '<a class="sfa-btn" href="' . $msg->editUrl . '" title="Edit in the page editor"><i class="fa fa-pencil"></i></a></div></td>';
        $out .= '</tr>';
      }
      $out .= '</tbody></table>';
    }
    $out .= '</div></div></div>';

    return $out;
  }

  /************************************************************
   * NAV: Settings
   ************************************************************/

  /************************************************************
   * NAV: Members
   ************************************************************/
  public function ___executeMembers() {
    $users   = $this->wire('users');
    $input   = $this->wire('input');
    $config  = $this->wire('config');
    $forum   = $this->wire('modules')->get('NativeForum');
    $san     = $this->sanitizer;

    $q = trim($san->text((string) $input->get('q')));
    $per = 50;
    $pg = max(1, (int) $input->get('pg'));
    $sel = 'id>0, name!=guest, include=all, check_access=0';
    if($q !== '') {
      $qs = $san->selectorValue($q);
      $sel .= $this->wire('fields')->get('forum_nickname') ? ", name|email|forum_nickname%=$qs" : ", name|email%=$qs";
    }
    $total = (int) $users->count($sel);
    $pages = max(1, (int) ceil($total / $per));
    if($pg > $pages) $pg = $pages;
    $ids = $users->findIDs($sel . ', sort=-created, start=' . (($pg - 1) * $per) . ', limit=' . $per);
    $list = $forum && method_exists($forum, 'preloadUsers') ? $forum->preloadUsers($ids) : [];
    $stats = $forum && method_exists($forum, 'userStats') ? $forum->userStats($ids) : [];

    $out = $this->sfAdminCss() . '<div class="nativeforum-admin nativeforum-members">';
    $out .= $this->sfaHead('Members', 'users', $total . ' members. Edit a member to change roles, e-mail or password.', '<a class="sfa-btn" href="' . $config->urls->admin . 'access/users/add/"><i class="fa fa-user-plus"></i> Add user</a>');
    $out .= '<form class="sfa-toolbar" method="get"><i class="fa fa-search"></i><input type="search" name="q" value="' . $san->entities($q) . '" placeholder="Name, nickname or e-mail" class="uk-input uk-form-small" style="max-width:320px"><button class="sfa-btn" type="submit">Search</button>'
      . ($q !== '' ? '<a class="sfa-btn" href="./">Clear</a>' : '') . '</form>';

    if(!$ids) {
      $out .= '<p class="sfa-empty">No members found.</p></div>';
      return $out;
    }
    $out .= '<div class="sfa-panel" style="padding:0;overflow:hidden"><table class="sfa-table" style="border:0;border-radius:0"><thead><tr><th>Member</th><th>E-mail</th><th>Roles</th><th>Posts</th><th>Joined</th><th></th></tr></thead><tbody>';
    foreach($ids as $id) {
      $u = $list[$id] ?? null;
      if(!$u) continue;
      $roles = [];
      foreach($u->roles as $r) if($r->name !== 'guest') $roles[] = '<span class="sfa-badge' . ($r->name === 'superuser' ? ' is-accent' : '') . '">' . $san->entities($r->name) . '</span>';
      $profile = ($forum && method_exists($forum, 'userProfileUrl')) ? (string) $forum->userProfileUrl($u) : '';
      $posts = (int) ($stats[$id]['messages'] ?? 0);
      $out .= '<tr>';
      $out .= '<td>' . $this->sfaUserCell($u, true) . '</td>';
      $out .= '<td>' . ($u->email ? '<a href="mailto:' . $san->entities($u->email) . '">' . $san->entities($u->email) . '</a>' : '&mdash;') . '</td>';
      $out .= '<td><div class="sfa-btns">' . ($roles ? implode('', $roles) : '<span class="sf-muted">guest</span>') . '</div></td>';
      $out .= '<td>' . $posts . '</td>';
      $out .= '<td class="sfa-nowrap">' . date('Y-m-d', (int) $u->created) . '</td>';
      $out .= '<td><div class="sfa-btns">'
        . ($profile ? '<a class="sfa-btn" href="' . $profile . '" target="_blank" title="Forum profile"><i class="fa fa-id-card-o"></i></a>' : '')
        . '<a class="sfa-btn" href="' . $config->urls->admin . 'access/users/edit/?id=' . (int) $u->id . '" title="Edit user"><i class="fa fa-pencil"></i></a></div></td>';
      $out .= '</tr>';
    }
    $out .= '</tbody></table></div>';
    if($pages > 1) {
      $out .= '<div class="sfa-tabs" style="margin-top:12px">';
      for($i = 1; $i <= $pages; $i++) {
        if($pages > 9 && $i !== 1 && $i !== $pages && abs($i - $pg) > 2) continue;
        $out .= '<a href="?' . http_build_query(array_filter(['q' => $q, 'pg' => $i > 1 ? $i : null])) . '"' . ($i === $pg ? ' class="is-active"' : '') . '>' . $i . '</a>';
      }
      $out .= '</div>';
    }
    $out .= '</div>';
    return $out;
  }

  /** Avatar initial + name (+ nickname) for admin tables. */
  protected function sfaUserCell($u, bool $withName = false): string {
    if(!$u instanceof User || !$u->id) return '<span class="sf-muted">&mdash;</span>';
    $forum = $this->wire('modules')->get('NativeForum');
    $nick = $forum && method_exists($forum, 'displayName') ? (string) $forum->displayName($u) : (string) $u->name;
    $img = '';
    try {
      if($u->hasField('forum_avatar')) { $av = $u->getUnformatted('forum_avatar'); if($av && count($av)) $img = '<img src="' . $av->first()->size(64, 64)->url . '" alt="">'; }
    } catch(\Throwable $e) {}
    $init = $this->sanitizer->entities(mb_strtoupper(mb_substr($nick, 0, 1)));
    $edit = $this->wire('config')->urls->admin . 'access/users/edit/?id=' . (int) $u->id;
    return '<div class="sfa-user"><span class="sfa-avatar">' . ($img ?: $init) . '</span><span><a href="' . $edit . '"><strong>' . $this->sanitizer->entities($nick) . '</strong></a>'
      . ($withName && $nick !== $u->name ? '<br><small>' . $this->sanitizer->entities($u->name) . '</small>' : '') . '</span></div>';
  }

  public function ___executeSettings() {
  $modules = $this->wire('modules');
  $config  = $this->wire('config');
  $input   = $this->wire('input');
  $session = $this->wire('session');

  /** @var NativeForum $forum */
  $forum = $modules->get('NativeForum');
  if(!$forum) return '<p>Native Forum module not found.</p>';

  // Build the same config inputfields as the native module config screen
  $data = $modules->getConfig($forum);
  $cfgFields = $forum->getModuleConfigInputfields($data);

  // Convert checkboxes to toggles (if available) and ensure unchecked values are posted as 0
  $toggleify = function($wrapper) use (&$toggleify, $modules) {
    if(!$wrapper) return;
    $kids = method_exists($wrapper, 'children') ? $wrapper->children()->getArray() : iterator_to_array($wrapper);
    foreach($kids as $child) {
      if($child instanceof InputfieldWrapper) {
        $toggleify($child);
        continue;
      }
      if(!$child instanceof Inputfield) continue;

      // Ensure booleans submit 0/1 reliably (hidden unchecked input)
      if($child instanceof InputfieldCheckbox) {
        $child->attr('value', 1);
        $child->attr('uncheckedValue', 0);

        // Replace with InputfieldToggle if present
        if($modules->isInstalled('InputfieldToggle')) {
          $t = clone $modules->get('InputfieldToggle');
          if($t) {
            /** @var InputfieldToggle $t */
            // Copy name reliably (some PW versions store it only in attr('name'))
            $childName = (string) $child->attr('name');
            if(!$childName) $childName = (string) $child->name;
            $t->name  = $childName;
            $t->attr('name', $childName);
            $t->label = $child->label;
            $t->description = $child->description;
            $t->notes = $child->notes;
            $t->collapsed = $child->collapsed;
            $t->required = $child->required;
            $t->attr('value', 1);
            $t->attr('uncheckedValue', 0);
            // Important: InputfieldCheckbox::val() is usually always "1" even when unchecked.
            // Use the actual checked state so the toggle reflects saved config correctly.
            // checked attr may be 1/'checked' (on) or 0/''/null (off)
            $ck = $child->attr('checked');
            $isOn = !($ck === null || $ck === '' || $ck === false || $ck === 0 || $ck === '0');
            $t->val($isOn ? 1 : 0);

            // keep the layout of the original field
            foreach(['columnWidth', 'showIf', 'requiredIf', 'icon'] as $k) { $v = $child->get($k); if($v) $t->set($k, $v); }
            if($child->attr('id')) $t->attr('id', $child->attr('id'));
            foreach(explode(' ', (string) $child->wrapClass) as $wc) if(trim($wc) !== '') $t->addClass(trim($wc), 'wrapClass');
            // Replace in wrapper at the same position
            $parent = $child->parent;
            if(!$parent instanceof InputfieldWrapper) $parent = $wrapper;
            $parent->insertBefore($t, $child);
            $parent->remove($child);
          }
        }
      }
    }
  };
  $toggleify($cfgFields);

  /** @var InputfieldForm $form */
  $form = $modules->get('InputfieldForm');
  $form->attr('method', 'post');
  $form->attr('action', $this->page->url . 'settings/');

  // CSRF token
  $csrf = $modules->get('InputfieldHidden'); // hidden: no empty row in the form
  $csrf->attr('name', $session->CSRF->getTokenName());
  $csrf->attr('value', $session->CSRF->getTokenValue());
  $form->add($csrf);

  $form->add($cfgFields);

  $submit = $modules->get('InputfieldSubmit');
  $submit->name  = 'submit_nativeforum_settings';
  $submit->value = 'Save settings';
  $submit->attr('class', 'ui-button ui-state-default');
  $form->add($submit);

  if($input->post->submit_nativeforum_settings) {
    $session->CSRF->validate();

    // Populate inputfields from POST
    $form->processInput($input->post);

    // Collect values from processed form.
    // IMPORTANT: toggles/checkboxes do not always populate ->value consistently across
    // PW versions. So we always normalize them to 1/0 based on checked state.
    $values = [];
    if(method_exists($form, 'getAll')) {
      foreach($form->getAll() as $f) {
        $name = (string) $f->attr('name');
        if(!$name) continue;
        if($f instanceof InputfieldSubmit) continue;
        if($f instanceof InputfieldMarkup) continue;

        // Normalize checkbox/toggle style fields
        if($f instanceof InputfieldCheckbox || method_exists($f, 'isChecked')) {
          // InputfieldToggle extends InputfieldCheckbox
          $checked = false;
          if(property_exists($f, 'checked')) {
            $checked = (bool) $f->checked;
          } elseif(method_exists($f, 'isChecked')) {
            $checked = (bool) $f->isChecked();
          }
          $values[$name] = $checked ? 1 : 0;
        } else {
          // Use val() when available; falls back to ->value
          $values[$name] = method_exists($f, 'val') ? $f->val() : $f->value;
        }
      }
    } elseif(method_exists($form, 'getValues')) {
      // Fallback: older forms without getAll()
      $values = $form->getValues();
    }
    if(!is_array($values)) $values = [];
    unset($values['submit_nativeforum_settings']);

    $current = $modules->getConfig($forum);
    if(!is_array($current)) $current = [];
    $merged = array_merge($current, $values);

    // Save config (try both instance + module name for maximum compatibility)
    $saved = false;
    try {
      $modules->saveConfig($forum, $merged);
      $saved = true;
    } catch(\Throwable $e) {
      $this->error('Save failed: ' . $e->getMessage());
    }

    if(!$saved) {
      try {
        $modules->saveConfig('NativeForum', $merged);
        $saved = true;
      } catch(\Throwable $e) {
        $this->error('Save failed: ' . $e->getMessage());
      }
    }

    if($saved) {
      $this->message('Native Forum settings saved.');
      $session->redirect($this->page->url . 'settings/');
    }
  }

  $out  = '<div class="nativeforum-admin">';
  $out .= '<h2>Native Forum — Settings</h2>';
  $out .= '<p class="description">Configure Native Forum using the same options as the core module settings.</p>';
  $out .= $form->render();
  $out .= '</div>';

  return $out;
}

  /**
   * Admin modal assets (theme-agnostic). Returns markup once per request.
   */
  protected function sfAdminModalAssets(): string {
    static $done = false;
    if($done) return '';
    $done = true;

    $css = <<<CSS
<style>
  .sfam-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:none;align-items:center;justify-content:center;padding:20px;}
  .sfam-modal{width:min(1100px,95vw);height:min(85vh,900px);background:var(--sfa-surface);border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.35);overflow:hidden;display:flex;flex-direction:column;}
  .sfam-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-bottom:1px solid rgba(0,0,0,.08);background:rgba(0,0,0,.02);}
  .sfam-title{font-weight:600;font-size:14px;margin:0;}
  .sfam-close{border:0;background:transparent;cursor:pointer;font-size:18px;line-height:1;padding:6px 10px;border-radius:8px;}
  .sfam-close:hover{background:rgba(0,0,0,.06);}
  .sfam-frame{flex:1 1 auto;width:100%;border:0;}
</style>
CSS;

    $js = <<<JS
<script>
(function(){
  function ensureModal(){
    let ov = document.querySelector('.sfam-overlay');
    if(ov) return ov;

    ov = document.createElement('div');
    ov.className = 'sfam-overlay';
    ov.innerHTML =
      '<div class="sfam-modal" role="dialog" aria-modal="true" aria-label="Add topic">' +
        '<div class="sfam-bar">' +
          '<div class="sfam-title">Add new topic</div>' +
          '<button type="button" class="sfam-close" aria-label="Close">×</button>' +
        '</div>' +
        '<iframe class="sfam-frame" src="about:blank"></iframe>' +
      '</div>';

    document.body.appendChild(ov);

    function closeModal(refresh){
      ov.style.display = 'none';
      ov.querySelector('iframe').src = 'about:blank';
      document.documentElement.style.overflow = '';
      if(refresh) sfaReload();
    }

    ov.addEventListener('mousedown', function(e){
      if(e.target === ov) closeModal(false);
    });

    ov.querySelector('.sfam-close').addEventListener('click', function(){
      closeModal(true);
    });

    document.addEventListener('keydown', function(e){
      if(e.key === 'Escape' && ov.style.display === 'flex'){
        e.preventDefault();
        closeModal(false);
      }
    });

    ov._sfamOpen = function(url){
      const frame = ov.querySelector('iframe');
      const u = url + (url.indexOf('?') > -1 ? '&' : '?') + 'sf_modal=1';
      frame.src = u;
      ov.style.display = 'flex';
      document.documentElement.style.overflow = 'hidden';

      // Auto-close after save (heuristic)
      frame.addEventListener('load', function(){
        try{
          const href = frame.contentWindow.location.href || '';
          if(/\\b(saved|created)=1\\b/.test(href)){
            closeModal(true);
          }
        }catch(err){}
      });
    };

    return ov;
  }

  document.addEventListener('click', function(e){
    const a = e.target.closest('a.sf-add-topic-modal[data-sf-modal="1"]');
    if(!a) return;
    if(e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
    e.preventDefault();
    const ov = ensureModal();
    ov._sfamOpen(a.href);
  });
})();
</script>
JS;

    return $css . $js;
  }


}
