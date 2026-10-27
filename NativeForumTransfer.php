<?php namespace ProcessWire;

/**
 * Native Forum – Export / Import of a whole forum as one .zip file.
 *
 * The zip contains:
 *   manifest.json   format, version, source site, counts, options
 *   settings.json   module settings (without page IDs / page-tree layout of the source site)
 *   pages.ndjson    groups, categories, topics, replies, private messages – parents first
 *   users.ndjson    members referenced by the forum: name, e-mail, forum roles, forum profile fields
 *   reports.json    moderation reports
 *   files/…         uploaded images and files (posts, messages, avatars)
 *
 * Import maps everything to the target site: new page IDs, members by user name (or e-mail),
 * image/file URLs in post text, followed topics, read markers and reports.
 */
class NativeForumTransfer extends Wire {

  const FORMAT = 'native-forum-export';
  const VERSION = 1;

  /** @var NativeForum */
  protected $forum;
  protected $contentTemplates = ['forum-group', 'forum-category', 'forum-topic', 'forum-reply', 'forum-message'];
  /** settings that describe the source site's page tree – never copied */
  protected $siteOnlySettings = ['forumRoot', 'messagesRoot', 'userProfileRoot', 'treePreset', 'sf_active_group'];

  public function __construct(NativeForum $forum) {
    parent::__construct();
    $this->forum = $forum;
  }

  protected function workDir(string $sub = ''): string {
    $dir = rtrim($this->wire('config')->paths->assets, '/') . '/cache/NativeForum/transfer/' . ($sub !== '' ? trim($sub, '/') . '/' : '');
    if(!is_dir($dir)) $this->wire('files')->mkdir($dir, true);
    return $dir;
  }

  /** remove exports/imports older than 2 hours */
  public function cleanup(): void {
    $base = $this->workDir();
    foreach(glob($base . '*') ?: [] as $f) {
      if(@filemtime($f) > time() - 7200) continue;
      if(is_dir($f)) $this->wire('files')->rmdir($f, true); else @unlink($f);
    }
  }

  /* =================================================================================
   * EXPORT
   * ================================================================================= */

  /**
   * @param array $opt settings, content, members, files (bool)
   * @return string path of the created .zip
   */
  public function export(array $opt = []): string {
    if(!class_exists('ZipArchive')) throw new WireException('The PHP "zip" extension is required for export.');
    @set_time_limit(0);
    $opt = array_merge(['settings' => true, 'content' => true, 'members' => true, 'files' => true, 'passwords' => false, 'passphrase' => ''], $opt);
    $passphrase = (string) $opt['passphrase'];
    unset($opt['passphrase']);
    if($opt['passwords'] && strlen($passphrase) < 8) throw new WireException('Choose a transfer password with at least 8 characters for the password export.');
    if($opt['passwords'] && !$opt['members']) $opt['passwords'] = false;
    $this->cleanup();
    $config = $this->wire('config');
    $pages = $this->wire('pages');
    $path = $this->workDir() . 'native-forum-export-' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6) . '.zip';
    $zip = new \ZipArchive();
    if($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new WireException('Cannot create the export file.');

    $root = $this->forum->forumRoot();
    $msgRoot = $this->forum->messagesRoot();
    $counts = ['pages' => 0, 'users' => 0, 'files' => 0, 'reports' => 0];
    $userIds = [];

    // settings
    if($opt['settings']) {
      $cfg = $this->wire('modules')->getConfig('NativeForum');
      if(!is_array($cfg)) $cfg = [];
      foreach($this->siteOnlySettings as $k) unset($cfg[$k]);
      $zip->addFromString('settings.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // content, parents first
    $tmpPages = $this->workDir('x' . substr(md5($path), 0, 8)) . 'pages.ndjson';
    $fh = fopen($tmpPages, 'w');
    if($opt['content']) {
      $ids = [];
      $tpls = implode('|', array_filter($this->contentTemplates, function($t) { return (bool) $this->wire('templates')->get($t); }));
      foreach([$root, $msgRoot] as $r) {
        if(!$r || !$r->id || $tpls === '') continue;
        foreach($pages->findIDs("has_parent={$r->id}, template=$tpls, include=all, status<" . Page::statusTrash, ['returnAllCols' => false]) as $id) $ids[(int) $id] = 1;
      }
      $ids = array_keys($ids);
      // depth via one query: parent of each page
      $depth = $this->depths($ids);
      asort($depth);
      foreach(array_chunk(array_keys($depth), 200) as $chunk) {
        foreach($pages->getById($chunk) as $p) {
          $row = $this->exportPage($p, $zip, $opt['files'], $userIds, $counts);
          fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
          $counts['pages']++;
        }
        $pages->uncacheAll();
      }
    }
    fclose($fh);
    $zip->addFile($tmpPages, 'pages.ndjson');

    // members
    $tmpUsers = dirname($tmpPages) . '/users.ndjson';
    $fh = fopen($tmpUsers, 'w');
    if($opt['members']) {
      foreach(['forum-member', 'forum-admin'] as $rn) {
        $r = $this->wire('roles')->get($rn);
        if($r && $r->id) foreach($this->wire('users')->findIDs("roles=$r, include=all") as $id) $userIds[(int) $id] = 1;
      }
      foreach(array_chunk(array_keys($userIds), 200) as $chunk) {
        foreach($pages->getById($chunk) as $u) {
          if(!$u instanceof User || !$u->id || $u->isGuest()) continue;
          $row = ['id' => (int) $u->id, 'name' => (string) $u->name, 'email' => (string) $u->email, 'superuser' => $u->isSuperuser() ? 1 : 0,
            'roles' => array_values(array_filter($u->roles->explode('name'), function($n) { return strpos($n, 'forum-') === 0; })),
            'created' => (int) $u->created, 'f' => []];
          foreach($u->template->fieldgroup as $f) {
            if(strpos((string) $f->name, 'forum_') !== 0) continue;
            $dummy = [];
            $row['f'][$f->name] = $this->exportValue($u, $f, $zip, $opt['files'], $dummy, $counts);
          }
          fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
          $counts['users']++;
        }
        $pages->uncacheAll();
      }
    }
    fclose($fh);
    $zip->addFile($tmpUsers, 'users.ndjson');

    // password hashes (never plain passwords), encrypted with the transfer password
    if($opt['passwords'] && $userIds) {
      $hashes = [];
      $db = $this->wire('database');
      foreach(array_chunk(array_keys($userIds), 500) as $chunk) {
        $q = $db->query('SELECT p.name, f.data, f.salt FROM pages p JOIN field_pass f ON f.pages_id=p.id WHERE p.id IN(' . implode(',', array_map('intval', $chunk)) . ')');
        foreach($q->fetchAll(\PDO::FETCH_NUM) as $r) if(strlen((string) $r[1])) $hashes[(string) $r[0]] = ['h' => (string) $r[1], 's' => (string) $r[2]];
      }
      $payload = ['a' => (string) $config->userAuthSalt, 't' => (string) $config->userAuthHashType, 'users' => $hashes];
      $zip->addFromString('passwords.enc', json_encode($this->encrypt(json_encode($payload), $passphrase)));
      $counts['passwords'] = count($hashes);
    }

    // reports
    if($opt['content']) {
      try {
        $this->forum->ensureReportsTable();
        $rows = $this->wire('database')->query("SELECT * FROM nativeforum_reports ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        $zip->addFromString('reports.json', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $counts['reports'] = count($rows);
      } catch(\Throwable $e) {}
    }

    $manifest = [
      'format' => self::FORMAT, 'version' => self::VERSION,
      'module' => $this->wire('modules')->getModuleInfoProperty('NativeForum', 'version'),
      'created' => date('c'), 'site' => (string) $config->httpHost,
      'filesUrl' => (string) $config->urls->files,
      'forumRoot' => $root && $root->id ? ['id' => (int) $root->id, 'path' => $root->path] : null,
      'messagesRoot' => $msgRoot && $msgRoot->id ? ['id' => (int) $msgRoot->id, 'path' => $msgRoot->path] : null,
      'options' => $opt, 'counts' => $counts,
    ];
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->close();
    $this->wire('files')->rmdir(dirname($tmpPages), true);
    return $path;
  }

  /** AES-256-GCM with a key derived from the transfer password (PBKDF2-SHA256). */
  protected function encrypt(string $plain, string $passphrase): array {
    if(!function_exists('openssl_encrypt')) throw new WireException('The PHP "openssl" extension is required to export passwords.');
    $salt = random_bytes(16); $iv = random_bytes(12); $iter = 200000;
    $key = hash_pbkdf2('sha256', $passphrase, $salt, $iter, 32, true);
    $tag = '';
    $data = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if($data === false) throw new WireException('Encryption failed.');
    return ['v' => 1, 'alg' => 'aes-256-gcm', 'kdf' => 'pbkdf2-sha256', 'iter' => $iter,
      'salt' => base64_encode($salt), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($data)];
  }

  protected function decrypt(array $box, string $passphrase): ?string {
    if(!function_exists('openssl_decrypt') || ($box['alg'] ?? '') !== 'aes-256-gcm') return null;
    $iter = max(10000, (int) ($box['iter'] ?? 200000));
    $key = hash_pbkdf2('sha256', $passphrase, (string) base64_decode((string) $box['salt']), $iter, 32, true);
    $out = openssl_decrypt((string) base64_decode((string) $box['data']), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
      (string) base64_decode((string) $box['iv']), (string) base64_decode((string) $box['tag']));
    return $out === false ? null : $out;
  }

  /** depth (number of parents) for page IDs, cheaply */
  protected function depths(array $ids): array {
    $out = [];
    if(!$ids) return $out;
    $db = $this->wire('database');
    $parent = [];
    foreach(array_chunk($ids, 1000) as $chunk) {
      $q = $db->query('SELECT id, parent_id FROM pages WHERE id IN(' . implode(',', array_map('intval', $chunk)) . ')');
      foreach($q->fetchAll(\PDO::FETCH_NUM) as $r) $parent[(int) $r[0]] = (int) $r[1];
    }
    foreach($ids as $id) {
      $d = 0; $cur = (int) $id; $guard = 0;
      while(isset($parent[$cur]) && $guard++ < 50) { $cur = $parent[$cur]; $d++; }
      $out[(int) $id] = $d;
    }
    return $out;
  }

  protected function exportPage(Page $p, \ZipArchive $zip, bool $withFiles, array &$userIds, array &$counts): array {
    $row = [
      'id' => (int) $p->id, 'parent' => (int) $p->parent_id, 'template' => (string) $p->template->name,
      'name' => (string) $p->name, 'status' => (int) $p->status, 'sort' => (int) $p->sort,
      'created' => (int) $p->created, 'modified' => (int) $p->modified,
      'created_user' => $p->createdUser && $p->createdUser->id ? (string) $p->createdUser->name : '',
      'f' => [],
    ];
    if($p->createdUser && $p->createdUser->id) $userIds[(int) $p->createdUser->id] = 1;
    foreach($p->template->fieldgroup as $f) {
      $row['f'][$f->name] = $this->exportValue($p, $f, $zip, $withFiles, $userIds, $counts);
    }
    return $row;
  }

  protected function exportValue(Page $p, Field $f, \ZipArchive $zip, bool $withFiles, array &$userIds, array &$counts) {
    $type = $f->type;
    if($type instanceof FieldtypePassword) return null;
    $v = $p->getUnformatted($f->name);
    if($type instanceof FieldtypeFile) {
      $out = [];
      if(!$v || !$withFiles) return $out;
      foreach($v as $pf) {
        $file = (string) $pf->filename;
        if(!is_file($file)) continue;
        $zpath = 'files/' . (int) $p->id . '/' . $f->name . '/' . $pf->basename;
        $zip->addFile($file, $zpath);
        $counts['files']++;
        $out[] = ['zip' => $zpath, 'name' => (string) $pf->basename, 'desc' => (string) $pf->description, 'tags' => (string) $pf->tags];
      }
      return ['files' => $out];
    }
    if($type instanceof FieldtypePage) {
      $items = $v instanceof PageArray ? $v : (($v instanceof Page && $v->id) ? [$v] : []);
      $out = [];
      foreach($items as $ref) {
        if(!$ref || !$ref->id) continue;
        if($ref instanceof User) { $out[] = ['u' => (string) $ref->name]; $userIds[(int) $ref->id] = 1; }
        elseif($ref instanceof Role) $out[] = ['r' => (string) $ref->name];
        else $out[] = ['p' => (int) $ref->id];
      }
      return ['refs' => $out];
    }
    if(is_object($v)) {
      if($v instanceof WireArray) return null;
      return method_exists($v, '__toString') ? (string) $v : null;
    }
    return $v;
  }

  /* =================================================================================
   * IMPORT
   * ================================================================================= */

  /**
   * @param string $zipPath uploaded .zip
   * @param array $opt settings, content, members (bool), mode = merge|replace, createUsers (bool)
   * @return array report
   */
  public function import(string $zipPath, array $opt = []): array {
    if(!class_exists('ZipArchive')) throw new WireException('The PHP "zip" extension is required for import.');
    @set_time_limit(0);
    $opt = array_merge(['settings' => true, 'content' => true, 'members' => true, 'mode' => 'merge', 'createUsers' => true, 'passphrase' => ''], $opt);
    $this->cleanup();
    $zip = new \ZipArchive();
    if($zip->open($zipPath) !== true) throw new WireException('This is not a valid .zip file.');
    for($i = 0; $i < $zip->numFiles; $i++) {
      $n = (string) $zip->getNameIndex($i);
      if($n === '' || $n[0] === '/' || strpos($n, '..') !== false || strpos($n, '\\') !== false) { $zip->close(); throw new WireException('Unsafe file name in the zip.'); }
    }
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    if(!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) { $zip->close(); throw new WireException('This zip is not a Native Forum export.'); }
    if((int) ($manifest['version'] ?? 0) > self::VERSION) { $zip->close(); throw new WireException('This export was made by a newer Native Forum – please update the module first.'); }
    $dir = $this->workDir('import-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6));
    $zip->extractTo($dir);
    $zip->close();

    $pages = $this->wire('pages');
    $users = $this->wire('users');
    $config = $this->wire('config');
    $report = ['settings' => 0, 'pages' => 0, 'skipped' => 0, 'files' => 0, 'users_matched' => 0, 'users_created' => 0, 'passwords' => 0, 'reports' => 0, 'deleted' => 0, 'warnings' => []];
    // password hashes (optional, encrypted)
    $pw = null;
    if(is_file($dir . 'passwords.enc') && $opt['members'] && $opt['createUsers']) {
      if((string) $opt['passphrase'] === '') {
        $report['warnings'][] = 'The file contains passwords, but no transfer password was entered – new members get a random password (they can use “Forgot password”).';
      } else {
        $plain = $this->decrypt((array) json_decode((string) file_get_contents($dir . 'passwords.enc'), true), (string) $opt['passphrase']);
        if($plain === null) { $this->wire('files')->rmdir($dir, true); throw new WireException('Wrong transfer password – the passwords in this file could not be decrypted. Nothing was imported.'); }
        $pw = json_decode($plain, true);
      }
    }
    $this->forum->sfBulk = true;

    try {
      // 1) settings
      if($opt['settings'] && is_file($dir . 'settings.json')) {
        $in = json_decode((string) file_get_contents($dir . 'settings.json'), true);
        if(is_array($in)) {
          foreach($this->siteOnlySettings as $k) unset($in[$k]);
          $cur = $this->wire('modules')->getConfig('NativeForum');
          $this->wire('modules')->saveConfig('NativeForum', array_merge(is_array($cur) ? $cur : [], $in));
          $report['settings'] = count($in);
        }
      }

      // 2) members: match by user name, then e-mail; create when allowed
      $userMap = []; // old user name => User
      $userRows = [];
      if(is_file($dir . 'users.ndjson')) {
        foreach(file($dir . 'users.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
          $r = json_decode($line, true);
          if(!is_array($r) || empty($r['name'])) continue;
          $userRows[] = $r;
          $u = $users->get('name=' . $this->wire('sanitizer')->selectorValue($r['name']));
          if((!$u || !$u->id) && !empty($r['email'])) $u = $users->get('email=' . $this->wire('sanitizer')->selectorValue($r['email']));
          if($u && $u->id) { $userMap[$r['name']] = $u; $report['users_matched']++; continue; }
          if(!$opt['members'] || !$opt['createUsers'] || !empty($r['superuser'])) continue;
          $nu = new User();
          $nu->name = $this->wire('sanitizer')->pageName($r['name'], true);
          if(!$nu->name) continue;
          $nu->email = $this->wire('sanitizer')->email((string) ($r['email'] ?? ''));
          $nu->pass = bin2hex(random_bytes(12)) . 'A1!';
          foreach((array) ($r['roles'] ?? []) as $rn) { $role = $this->wire('roles')->get($rn); if($role && $role->id) $nu->addRole($role); }
          if(!$nu->roles->count() || !$nu->hasRole('forum-member')) { $role = $this->wire('roles')->get('forum-member'); if($role && $role->id) $nu->addRole($role); }
          try {
            $users->save($nu); $userMap[$r['name']] = $nu; $report['users_created']++;
            // their old password keeps working: verified once against the old hash at the first login, then re-hashed for this site
            if(is_array($pw) && isset($pw['users'][$r['name']])) {
              $nu->meta('nf_legacy_pass', ['h' => $pw['users'][$r['name']]['h'], 's' => $pw['users'][$r['name']]['s'], 'a' => (string) ($pw['a'] ?? ''), 't' => (string) ($pw['t'] ?? '')]);
              $report['passwords']++;
            }
          }
          catch(\Throwable $e) { $report['warnings'][] = "Member {$r['name']}: " . $e->getMessage(); }
        }
      }

      // 3) replace: empty the forum first
      $root = $this->forum->forumRoot();
      $msgRoot = $this->forum->messagesRoot();
      if($opt['content'] && $opt['mode'] === 'replace') {
        foreach([$root, $msgRoot] as $r) {
          if(!$r || !$r->id) continue;
          foreach($r->children('include=all') as $c) {
            if(!in_array((string) $c->template->name, $this->contentTemplates, true)) continue;
            try { $pages->delete($c, true); $report['deleted']++; } catch(\Throwable $e) { $report['warnings'][] = 'Delete ' . $c->path . ': ' . $e->getMessage(); }
          }
        }
        try { $this->wire('database')->exec('DELETE FROM nativeforum_reports'); } catch(\Throwable $e) {}
      }

      // 4) pages, parents first
      $idMap = [];
      if(!empty($manifest['forumRoot']['id']) && $root && $root->id) $idMap[(int) $manifest['forumRoot']['id']] = $root;
      if(!empty($manifest['messagesRoot']['id']) && $msgRoot && $msgRoot->id) $idMap[(int) $manifest['messagesRoot']['id']] = $msgRoot;
      $pending = [];
      if($opt['content'] && is_file($dir . 'pages.ndjson')) {
        $fh = fopen($dir . 'pages.ndjson', 'r');
        while(($line = fgets($fh)) !== false) {
          $r = json_decode($line, true);
          if(!is_array($r) || empty($r['template'])) continue;
          $tpl = $this->wire('templates')->get((string) $r['template']);
          $parent = $idMap[(int) $r['parent']] ?? null;
          if(!$tpl || !in_array($tpl->name, $this->contentTemplates, true) || !$parent) { $report['skipped']++; continue; }
          $p = new Page();
          $p->template = $tpl;
          $p->parent = $parent;
          $p->of(false);
          $name = $this->wire('sanitizer')->pageName((string) $r['name'], true) ?: 'post';
          $base = $name; $n = 1;
          while($parent->child('name=' . $name . ', include=all')->id) $name = $base . '-' . (++$n);
          $p->name = $name;
          $p->status = (int) $r['status'] & ~Page::statusTrash;
          foreach((array) $r['f'] as $fname => $val) {
            if(!$p->hasField($fname) || is_array($val)) continue; // refs + files in pass 2 / below
            $p->set($fname, $val);
          }
          if(!$p->title) $p->title = $name;
          try { $pages->save($p, ['quiet' => false]); }
          catch(\Throwable $e) { $report['warnings'][] = "{$r['template']} {$r['name']}: " . $e->getMessage(); $report['skipped']++; continue; }
          $idMap[(int) $r['id']] = $p;
          $report['pages']++;
          // files
          foreach((array) $r['f'] as $fname => $val) {
            if(!is_array($val) || !isset($val['files']) || !$p->hasField($fname)) continue;
            $report['files'] += $this->addFiles($p, $fname, $val['files'], $dir);
          }
          $pending[] = [$p->id, $r];
          if(count($idMap) % 300 === 0) $pages->uncacheAll();
        }
        fclose($fh);
      }

      // 5) pass 2: references, URLs in text, original dates and authors
      $oldFiles = rtrim((string) ($manifest['filesUrl'] ?? $config->urls->files), '/') . '/';
      $newFiles = rtrim((string) $config->urls->files, '/') . '/';
      $urlMap = [];
      foreach($idMap as $oldId => $np) $urlMap[$oldFiles . (int) $oldId . '/'] = $newFiles . (int) $np->id . '/';
      uksort($urlMap, function($a, $b) { return strlen($b) - strlen($a); });
      foreach($pending as $pd) {
        list($newId, $r) = $pd;
        $p = $pages->get((int) $newId);
        if(!$p->id) continue;
        $p->of(false);
        foreach((array) $r['f'] as $fname => $val) {
          if(!$p->hasField($fname)) continue;
          if(is_array($val) && isset($val['refs'])) {
            $p->set($fname, $this->resolveRefs($val['refs'], $p->getField($fname), $idMap, $userMap));
          } elseif(is_string($val) && strpos($val, $oldFiles) !== false) {
            $p->set($fname, strtr($val, $urlMap));
          }
        }
        $cu = (string) ($r['created_user'] ?? '');
        if($cu !== '' && isset($userMap[$cu])) $p->created_users_id = (int) $userMap[$cu]->id;
        if(!empty($r['created'])) $p->created = (int) $r['created'];
        if(!empty($r['modified'])) $p->modified = (int) $r['modified'];
        try { $pages->save($p, ['quiet' => true]); } catch(\Throwable $e) { $report['warnings'][] = 'Pass 2 ' . $p->path . ': ' . $e->getMessage(); }
        $pages->uncache($p);
      }

      // 6) members' forum profile (followed topics, read markers, avatar, nickname …)
      if($opt['members']) {
        foreach($userRows as $r) {
          $u = $userMap[$r['name']] ?? null;
          if(!$u || !$u->id) continue;
          $u->of(false);
          $changed = false;
          foreach((array) ($r['f'] ?? []) as $fname => $val) {
            if(!$u->hasField($fname)) continue;
            if(is_array($val) && isset($val['files'])) {
              $cur = $u->getUnformatted($fname);
              if($cur && count($cur)) continue; // keep an avatar the member already has
              $report['files'] += $this->addFiles($u, $fname, $val['files'], $dir);
              continue;
            }
            if(is_array($val) && isset($val['refs'])) {
              $new = $this->resolveRefs($val['refs'], $u->getField($fname), $idMap, $userMap);
              if($new instanceof PageArray) {
                $cur = $u->getUnformatted($fname);
                if($cur instanceof PageArray) { foreach($new as $x) $cur->add($x); $new = $cur; }
              }
              $u->set($fname, $new); $changed = true;
              continue;
            }
            if($fname === 'forum_read_topics') {
              $old = json_decode((string) $val, true);
              $cur = json_decode((string) $u->getUnformatted($fname), true);
              if(!is_array($cur)) $cur = [];
              if(is_array($old)) foreach($old as $tid => $ts) { if(isset($idMap[(int) $tid])) $cur[(string) $idMap[(int) $tid]->id] = $ts; }
              $u->set($fname, json_encode($cur)); $changed = true;
              continue;
            }
            $curVal = $u->getUnformatted($fname);
            if(($curVal === '' || $curVal === null || $curVal === 0) && $val !== null && $val !== '') { $u->set($fname, $val); $changed = true; }
          }
          if($changed) { try { $users->save($u, ['quiet' => true]); } catch(\Throwable $e) { $report['warnings'][] = "Member {$r['name']}: " . $e->getMessage(); } }
        }
      }

      // 7) reports
      if($opt['content'] && is_file($dir . 'reports.json')) {
        $rows = json_decode((string) file_get_contents($dir . 'reports.json'), true);
        $byId = [];
        foreach($userRows as $ur) if(isset($userMap[$ur['name']])) $byId[(int) $ur['id']] = (int) $userMap[$ur['name']]->id;
        if(is_array($rows) && $rows) {
          $this->forum->ensureReportsTable();
          $st = $this->wire('database')->prepare('INSERT INTO nativeforum_reports (post_id, topic_id, post_author, reporter_id, reason, comment, status, created, handled_by, handled_at, action) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
          foreach($rows as $row) {
            $pid = isset($idMap[(int) $row['post_id']]) ? (int) $idMap[(int) $row['post_id']]->id : 0;
            if(!$pid) continue;
            $tid = isset($idMap[(int) $row['topic_id']]) ? (int) $idMap[(int) $row['topic_id']]->id : 0;
            $st->execute([$pid, $tid, $byId[(int) $row['post_author']] ?? 0, $byId[(int) $row['reporter_id']] ?? 0, (string) $row['reason'], $row['comment'],
              (string) $row['status'], (int) $row['created'], $byId[(int) $row['handled_by']] ?? 0, (int) $row['handled_at'], (string) $row['action']]);
            $report['reports']++;
          }
        }
      }
    } finally {
      $this->forum->sfBulk = false;
      $this->wire('files')->rmdir($dir, true);
      try { $this->wire('cache')->deleteFor('NativeForumView'); } catch(\Throwable $e) {}
      try { $this->forum->clearProCacheAll(); } catch(\Throwable $e) {}
    }
    return $report;
  }

  protected function addFiles(Page $p, string $fname, array $files, string $dir): int {
    $n = 0;
    $p->of(false);
    $field = $p->getUnformatted($fname);
    if(!$field) return 0;
    foreach($files as $fi) {
      $src = $dir . ($fi['zip'] ?? '');
      if(empty($fi['zip']) || !is_file($src)) continue;
      // keep the original file name (post text links to it)
      $tmp = dirname($src) . '/' . basename((string) $fi['name']);
      if($tmp !== $src) @copy($src, $tmp);
      try {
        $field->add($tmp);
        $last = $field->last();
        if($last && !empty($fi['desc'])) $last->description = (string) $fi['desc'];
        if($last && !empty($fi['tags'])) $last->tags = (string) $fi['tags'];
        $n++;
      } catch(\Throwable $e) {}
    }
    if($n) { try { $p->save($fname, ['quiet' => true]); } catch(\Throwable $e) {} }
    return $n;
  }

  protected function resolveRefs(array $refs, ?Field $field, array $idMap, array $userMap) {
    $list = new PageArray();
    foreach($refs as $ref) {
      $pg = null;
      if(isset($ref['u'])) $pg = $userMap[$ref['u']] ?? $this->wire('users')->get('name=' . $this->wire('sanitizer')->pageName((string) $ref['u']));
      elseif(isset($ref['r'])) $pg = $this->wire('roles')->get((string) $ref['r']);
      elseif(isset($ref['p'])) $pg = $idMap[(int) $ref['p']] ?? null;
      if($pg && $pg->id) $list->add($pg);
    }
    $single = $field && (int) $field->get('derefAsPage') > 0;
    if($single) return $list->count() ? $list->first() : null;
    return $list;
  }
}
