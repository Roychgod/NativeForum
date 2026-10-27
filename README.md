# Native Forum for ProcessWire

A complete, self-contained community forum for [ProcessWire CMS/CMF](https://processwire.com): groups, categories, topics, replies, private messages, following, moderation, anti-spam, SEO/GEO and ready-made designs. It runs inside your existing site layout, so your header, menus, footer and scripts keep working.

- **Module class:** `NativeForum` (installs the admin module `ProcessNativeForum` automatically)
- **Version:** 1.0.0
- **Requires:** ProcessWire 3.0.200+, PHP 7.4+ (PHP 8.x recommended)
- **Optional PHP extensions:** `zip` (Export / Import), `openssl` (encrypted password transfer)
- **License:** MIT

---

## Features

**Forum**
- Groups → categories → topics → replies, with pinned, locked, hidden and VIP (restricted) categories and topics
- Rich editor (CKEditor) with image upload, drag-to-resize images, file attachments with progress, quotes and "reply to"
- Permanent post links (`#1`, `#2` …), share dialog, likes / votes, topic following with "new replies" counters
- Member profiles, members directory, ranks (automatic or manual), avatars, signatures
- Private messages with unread notices (title-bar pill, account box, popup)
- Search with highlighting, mobile-first layout, login and register as modal dialogs

**Integration**
- *Site layout* mode: the forum renders inside your own `_init.php` / `_main.php` (Markup Regions or delayed output)
- *Standalone* mode: the forum renders its own complete HTML5 document
- All forum CSS is scoped to `.sf-wrap`; forum JavaScript reacts only to forum markup
- Two themes (Simple, Bootstrap 5) and six design presets (Obsidian, Light, Dark, Auto, Monochrome, Custom)
- Design Studio: colours, typography, spacing, buttons, badges, forms and per-template overrides without writing CSS
- ProCache friendly: guest pages carry no session data; new posts clear the affected cached pages

**Admin (Native Forum in the admin menu)**
- Dashboard with key numbers, open reports, latest posts and newest members
- Reports (moderation queue), groups & categories with drag-and-drop, full topic editor, messages, members, settings
- Export / Import of the complete forum (settings, content, members' forum profiles, files) as one `.zip`
- Optional demo content, removable with one click

**Security & anti-spam**
- CSRF on every form, honeypot, signed time check, JavaScript check, flood control
- Throw-away e-mail blocklist, MX check, registrations per IP, banned words (also `/regex/`), link limits for new members
- Blocked IPs / CIDR ranges and e-mail domains, optional Cloudflare Turnstile and StopForumSpam check
- E-mail confirmation for registrations; blocked attempts are logged (`Setup → Logs → nativeforum-spam`)

**SEO & GEO**
- `<title>`, meta description, canonical, `rel` prev/next, robots, Open Graph and Twitter cards
- JSON-LD: `DiscussionForumPosting`, `BreadcrumbList`, `CollectionPage`, `ProfilePage`, `WebSite` + `SearchAction`
- `/forum/sitemap.xml`, `/forum/feed.xml` (RSS, also per category), `/llms.txt`, every topic as Markdown (`?format=md`)

---

## Installation

1. Copy the module to `/site/modules/NativeForum/` (the folder must be named `NativeForum`).
   - From GitHub: download the ZIP and rename the extracted folder from `NativeForum-main` to `NativeForum`.
   - Or in the admin: *Modules → New → Add Module from URL* and paste the ZIP URL, or *Add Module from Directory* with the class name `NativeForum`.
2. *Modules → Refresh*, then install **Native Forum**. **Native Forum Admin** is installed automatically.
3. Open `/forum/`. On the Native Forum dashboard you can add demo content or start with an empty forum.

On install the module creates its fields, templates, the pages `/forum/`, `/messages/`, `/forum-profile/`, `/forum-user/`, `/forum-members/`, `/forum-login/`, `/forum-register/`, the roles `forum-member` and `forum-admin` (moderators), the permission `native-forum`, and copies the frontend template files into `/site/templates/`.

### Permissions

- `native-forum` gives access to the admin. Assign it to the roles that should manage the forum (superusers always have it).
- `forum-admin` users are moderators on the frontend (lock, pin, move, hide, edit).
- New registrations get the `forum-member` role; privileged roles are never assigned from the frontend.

### Customising templates

The frontend templates are copied to `/site/templates/forum-*.php`. Updates refresh them automatically, **except** files that contain the text `NativeForum Custom` — add that marker as a comment to keep your own copy. *Settings → Advanced → Tools* can re-sync the templates manually.

### Sitemap

Add the forum sitemap to your `robots.txt`:

```
Sitemap: https://example.com/forum/sitemap.xml
```

---

## Moving a forum to another site

*Native Forum → Export / Import* downloads one `.zip` with settings, all content (groups, categories, topics, replies, private messages, reports), members' forum profiles and all uploaded files. On the other site install Native Forum, open *Export / Import* and upload the file — either **add to** the existing forum or **replace** it. Members are matched by user name or e-mail; missing ones can be created.

Optional **encrypted passwords**: only password hashes are exported, encrypted with AES-256-GCM (PBKDF2-SHA256 key from a transfer password you choose). At a member's first login on the new site the password is verified once and re-saved with the new site's own hashing.

---

## Upgrading from SimpleForum

Native Forum is the successor of *SimpleForum*. Copy `NativeForum` next to the old `SimpleForum` folder, *Modules → Refresh* and install Native Forum. It takes over settings, pages, posts, fields, the admin page, the permission and pending sign-ups. The old folder is moved to `/site/modules/.SimpleForum-replaced-by-NativeForum/` and can be deleted afterwards.

---

## Uninstall

Uninstalling removes **everything** the forum created: forum pages and their files, templates, fields (also the forum fields on users), roles, reports, settings, caches, logs and the forum template files in `/site/templates/`. Site user accounts are kept. Use *Export / Import* first if you want to keep the content.

---

## Development

Design presets are generated from `assets/presets/src/` (`*.tokens.css` + `_skin.css`, built with `build.py`). The container CSS is built with `assets/css/src/build_container.py`.

## Support

- ProcessWire forum support thread: *Modules/Plugins* board on [processwire.com/talk](https://processwire.com/talk/)
- Bugs and feature requests: GitHub issues of this repository

## Author

Gregor Rojko — [Pyxios](https://pyxios.com), Maribor, Slovenia

## License

MIT — see [LICENSE](LICENSE).
