Native Forum for ProcessWire — 1.0.7
====================================

A complete community forum for ProcessWire: groups, categories, topics, replies, private
messages, following, moderation, anti-spam, SEO/GEO, and ready-made designs.

INSTALL
- Copy this folder to /site/modules/NativeForum/ (the folder name matters).
- Modules > Refresh, then install "Native Forum". The admin ("Native Forum Admin") is
  installed automatically. Open /forum/ — done.
- Optional demo content: the Native Forum dashboard offers "Add demo content" (a Community
  group, 3 categories, pinned forum rules, 4 topics, a few replies and 2 demo members) or
  "Start with an empty forum". Demo content can be edited, or removed with one click.

UPGRADING FROM "SimpleForum" (the module's former name)
- Copy NativeForum next to the old SimpleForum folder, Modules > Refresh, install Native Forum.
- It takes over everything: settings, forum pages and posts, fields, admin page, the
  permission, pending sign-ups and your /site/templates forum files. The old module is NOT
  uninstalled (that would delete the forum); its folder is moved to
  /site/modules/.SimpleForum-replaced-by-NativeForum/ and can be deleted afterwards.

EXPORT / IMPORT (move a forum to another site)
- Native Forum > Export / Import: download one .zip with settings, all content (groups,
  categories, topics, replies, private messages, reports), members' forum profiles and all
  uploaded images/files. On the other site: install Native Forum, open Export / Import,
  upload the .zip. Choose "Add to the existing forum" or "Replace". Members are matched by
  user name or e-mail; missing ones can be created (forum-member role, random password).
  Posts get new page IDs; authors, dates, likes, votes, replies-to, followed topics, read
  markers, reports and picture/file links in the text are carried over.
- Optional "Passwords (encrypted)": members keep their password on the new site. Only the
  password hashes are exported (never readable passwords), encrypted with AES-256-GCM using
  a transfer password you choose (PBKDF2-SHA256 key). Enter the same transfer password at
  import. At a member's first login on the new site the password is verified once against
  the old hash and immediately saved with the new site's own hashing; the old hash is then
  deleted. Members who already exist on the new site keep their current password.

UNINSTALL
- Uninstalling removes EVERYTHING the forum created: all forum pages with their files,
  templates, fields (also the forum fields on users), roles, reports, settings, caches,
  logs and the forum template files in /site/templates. Site user accounts are kept.
  Modules > Native Forum shows a warning and asks for confirmation. Export first if needed.

What's new in 1.0.7
-------------------
- Site layout mode: the site's _init.php/_main.php now run from /site/templates/, so layouts that
  include files with relative paths (include("./_func.php"), "./includes/…", e.g. the Skyscrapers
  profile) work. Before, they failed and the page showed PHP warnings and "SFFORUMOUTPUT9c41x".
- If the site layout fails anyway, its partial output is discarded (no warnings/marker on the page);
  the error is logged in Setup > Logs > native-forum.
- Inside a narrow content column of the site's layout the forum switches to its compact/mobile
  layout by its own width (sidebar below, stacked rows) instead of squeezing.

What's new in 1.0.6
-------------------
- Settings: the Design Studio "Section" dropdown switches instantly in the page (no reload);
  open panels and the scroll position are kept after Save and after switching the Template.
- Design Studio shows the selected preset's real values (colours, borders, spacing, radius…),
  measured from each preset in both themes. Change what you like: changed fields are marked and
  have "Reset to preset". Values equal to the preset are not stored, so switching presets still
  works; changing the preset in the form updates untouched fields immediately.
- New "Forms (inputs)" section (input radius and padding).
- Fixed: choosing a page template in Design Studio could stop the settings page with a selector
  error (override fields of templates with a "-" in the name).
- Forum admin pages keep the scroll position after quick actions (move topic, edit in modal).

What's new in 1.0.5
-------------------
- Dropdown lists are readable in dark admin themes (Konkat dark, Uikit dark): the opened list
  follows the admin colours on every forum admin page and in Settings.
- Frontend readability guard: when a host theme or a Design Studio colour makes labels or text
  unreadable on their background (e.g. dark label colour on a dark preset), the forum switches
  that text to a readable colour. Login/Register dialogs and pages included.
- Dropdown option lists follow the field colours in all presets.

What's new in 1.0.4
-------------------
- "Followed topics" tab on your own profile everywhere: the public profile behind "My profile"
  (/forum-user/name/) and /forum-profile/, in every preset and theme. Topic following is
  switched on by the upgrade.

What's new in 1.0.3
-------------------
- Export / Import can carry members' passwords (encrypted hashes, see above).

What's new in 1.0.2
-------------------
- Optional demo content after install (incl. ready-to-use "Forum rules"), removable with
  one click from the dashboard.

What's new in 1.0.1
-------------------
- Export / Import of the whole forum (see above).
- Uninstall now really removes everything (it left fields, the members template, the reports
  table, caches and template files behind before) – with a clear warning and confirmation.
- A fresh install also creates the members page; the autocomplete input used by the user
  fields is installed automatically.
- Reports from the SimpleForum era are moved into Native Forum's table.

What's new in 1.0.0
-------------------
- New name: Native Forum (was SimpleForum). Installing it also installs its admin module.
- Page reference fields are fully configured on install/upgrade (no "needs to be configured"
  notice, correct single/multiple values, autocomplete for users/topics). Followed topics
  now keep every topic a member follows.
- My profile → "Followed topics": every followed topic with the number of new replies
  ("2 NEW" opens the first unread post), last post, and Unfollow. Quick link with a counter
  in the sidebar.
- Profile tabs wrap on wider screens instead of hiding the last tab.

What's new in v73
-----------------
- The dark slate / crimson preset is now called "Obsidian" (existing settings are switched
  over automatically).

What's new in v72
-----------------
- Design presets rebuilt on one shared skin (the one behind the Obsidian look): Light, Dark,
  Auto, Monochrome and Custom are now complete, ready-to-use themes — buttons, bars, forms,
  editor, messages, tables, badges, alerts — for the Simple and the Bootstrap theme.
  Obsidian looks exactly as before. Presets other than Obsidian give the forum its own
  page background, so they stay readable on light and dark sites.
- Custom starts from a teal palette; every Design Studio value you set overrides it
  (Design Studio defaults no longer flatten a preset).
- Bootstrap theme: "Auto" integration loads Bootstrap 5 when the site has none (sites that
  already load Bootstrap are left alone; "Off" never loads it). Post author column and post
  now sit side by side; sidebar account buttons and member avatars fixed.
- Forum style "Compact" really is compact: slim bars, table-like category/topic lists,
  smaller avatars, paddings and buttons (about a third more content on screen).
- Editor toolbar follows light presets (normal icons on a light bar).
- Source of the presets: assets/presets/src (tokens + _skin.css, build.py).

What's new in v71
-----------------
- Settings re-arranged: every row fills the full width, small switches are grouped in
  rows of 3–4, text areas are paired, long groups have sub-sections (Integration, Width
  & sidebar, Site loader, Extra markup; Limits, Block lists, Captcha). Related options
  moved together (Messages root page → General), groups in a logical order (Design
  Studio after Theme, Attachments after Topics & posts). Translations four per row.
- Fixed: on the Settings page, Yes/No switches lost their position and width and were
  pushed to the end of their group as empty full-width rows.

What's new in v70
-----------------
- Admin on phones: tables turn into compact cards, the Messages page stacks into one
  column, tiles show two per row, no sideways scrolling on any admin page.
- Konkat / Uikit dark mode: the topic editor's text area is dark too.
- Admin topic editor: the "Created" date/time is now saved correctly.
- Settings / edit dialogs: no more empty row at the top of the form.
- Forum on phones: profile tabs scroll sideways instead of overflowing, the private
  messages table is compact, dialogs use the full screen width.

What's new in v69
-----------------
- Admin → Topics → Edit opens a full topic editor: title, text (rich editor), summary,
  tags, author, date, views, pinned/locked/hidden/unpublished, category, and every
  reply (text, author, hide, move to trash), 20 replies per page. Reports and the
  Dashboard link straight to the post in this editor.
- Link previews: the post's picture is found in any markup (lazy-load data-src, srcset,
  image optimizers); og:image width/height/type are sent so Facebook shows the picture
  on the very first share; the crawler preview is never stored by ProCache, and a
  reply's URL is cleared from ProCache when the reply is saved.

What's new in v68
-----------------
- Sharing a reply shows only that reply's own picture; if the reply has no picture, the
  forum's general share image ("Default share image URL") is used — never a picture of
  another reply or of the topic. Pictures inside quotes are ignored, and so are uploads
  that were removed from the post again. Topics work the same way (own picture, else the
  general image).

What's new in v67
-----------------
Sharing / link previews:
- Facebook & co. now always get the forum's title, description and image: the Open Graph
  tags are written at the very top of <head> (some sites have so much inline code in
  <head> that crawlers stopped reading before the tags), the description is never empty
  and "Default share image URL" is used whenever a post has no image.
- After updating, the ProCache cache is cleared automatically (old cached pages had the
  site's generic tags). Use Facebook's Sharing Debugger → "Scrape Again" once.
- Share dialog: Facebook / X / … open in a small window; the forum page stays where it is.

Reports (moderation queue):
- "Report" asks for a reason (spam, offensive, off-topic, illegal, other) and optional
  details. Reports are stored (table nativeforum_reports) and still e-mailed.
- Admin → Native Forum → Reports: open / handled / all, grouped per post with reasons,
  comments and reporters; actions: View, Hide (soft-delete) / Restore, Lock / Unlock
  topic, Edit, Delete (trash), Resolved, Dismiss. The dashboard shows the open reports.

Admin:
- New dashboard: key numbers (open reports, topics, replies, posts in 24 h, categories,
  members, unread messages), reports to review, latest posts, quick links, newest members,
  categories overview.
- All admin pages use the full width and one consistent look: same headers, panels,
  tables, buttons with icons, badges, toggle chips (VIP / Pin / Lock / Hidden / Unpub)
  and the same drag handle everywhere.
- Members: search, paging, avatar, roles as badges, post count, profile / edit buttons.
- Messages: compose panel next to the message list (unread badge, excerpt, reply / edit).

What's new in v66
-----------------
- "Start new topic" on the board index: a clear chooser in the forum colours, grouped by
  forum group, only forums you may post in, with a filter box when there are many.
  Guests get the login dialog instead.
- Post numbers like other forums: #1 is the topic, replies continue #2, #3… across pages.
  Clicking #N copies the post's permanent link and highlights the post; the link icon
  next to it copies it too ("Link copied" toast).
- Permanent post links (/forum/…/topic/<post>/) always open the right page and scroll
  to the post, even when pagination changes later.
- Share (… menu): dialog with Facebook, X, WhatsApp, Telegram, LinkedIn, e-mail, the
  phone's own share sheet (when available) and Copy link.
- Link previews (Facebook, Messenger, WhatsApp, X, LinkedIn, Slack, Discord, Telegram…):
  topic links show the topic title, summary and first image; a shared reply shows the topic
  title, the reply author and the reply's own text (without the quotes) and its image.
  On forum pages the forum's Open Graph tags replace the site's generic ones (the site's
  image is kept as fallback). Tip: set "SEO & GEO → Default share image URL" for topics
  without images.
- Post "…" menu follows the theme (dark on Obsidian).

What's new in v65
-----------------
Attachments (images and files):
- Each file appears at once as a row with a preview (images) or a type badge (PDF, ZIP…),
  its size, an animated progress bar with the percentage and animated "Uploading…" dots;
  then "Ready — click Insert…". Errors are shown on the row itself.
- Rows follow the theme (Obsidian: black), Remove is a quiet secondary button.
- Faster: up to 3 files upload at the same time; the upload request releases the PHP
  session lock immediately (it used to wait for any other request of the same visitor);
  large photos (JPEG/WebP over ~1 MB or wider than 2000 px) are scaled down in the
  browser before they are sent.
- "Insert" works even if the editor has not finished loading yet.
- Attached files in posts are shown as a clear download button (icon + name).

What's new in v64
-----------------
- Private messages: the message text box and the message lists follow the theme
  (Obsidian: black boxes, dark table header, subtle dark hover) — no white backgrounds,
  so "From" names are readable again.
- Buttons no longer touch the card edge when the site CSS floats form paragraphs
  (sites with form p{float:left}); space between the form and the tabs below.
- Editor on dark themes: dark toolbar with light icons and a black writing area
  (Obsidian preset; other presets keep the light editor).
- Search scope menus use the theme colours.
- Fixed: "From/To" stayed empty in profile message lists in some setups.

What's new in v63
-----------------
Speed (measured with 600 members, 300 topics, 6,600 posts):
- Topic page 420 → ~100 database queries, category 500 → ~80, members page 2.1 s → 0.08 s.
- Member statistics (posts, reactions) come from one aggregate query instead of loading
  every post of every author on the page (this was the main slowdown on big forums).
- Authors, reply counts and "last post" are loaded in batches; small forum fields are
  loaded together with their pages (autojoin, set once on upgrade).
- Sidebar statistics / latest topics / newest members are cached (2–5 min, cleared as
  soon as something is posted).
- Topic views and "read" markers no longer save pages: no cache clearing and no
  ProCache flush on every page view. A post is no longer counted twice per view.
- The editor (CKEditor) is loaded only on pages with a text box and only after the page
  has loaded (immediately when you click into the box). Site loaders that wait for the
  page to finish loading disappear much sooner.
- The view counter is sent after the page has loaded and is answered before the site's
  _init.php runs.
- Members list is paged (40 per page) and searched in the database.
- Long topics: pagination shows 1 … 4 5 6 … 12.
- Fixed: a member with 0 posts caused a user save on every page view.
- Superusers see the build time in an HTML comment; everyone gets a Server-Timing header.

ProCache:
- Pages for guests contain no session data any more (forms fetch a fresh token when you
  start typing), so they can be cached by ProCache. New posts clear the cached topic,
  category, group and forum index.

Anti-spam (Settings → Anti-spam & security):
- All public forms: invisible honeypot, signed time check, JavaScript check and a token
  that is fetched only when a person uses the form; bots that post directly are rejected.
- Registration: throw-away e-mail providers blocked (~70 built in + your list), e-mail
  domain must be able to receive mail (MX), max. registrations per IP and day, no
  web addresses as user names, optional StopForumSpam.org check.
- Posts and private messages: banned words / phrases (also /regex/), new members may
  post only 1 link until they have 3 posts (configurable), guests may not post links,
  optional link limit for everyone, PM flood limit for new members.
- Blocked IPs (1.2.3.4, 1.2.3.*, 1.2.3.0/24) and blocked e-mail domains.
- Optional free captcha: Cloudflare Turnstile (registration, login, guest posts).
- Blocked attempts are logged: Setup → Logs → nativeforum-spam.
- Fixed: pending registrations (confirmation links) and flood control were wiped
  whenever someone posted.

Settings:
- "Disable the site loader on forum pages" is back (Page layout & integration), with a
  field for your own loader class / ID (e.g. loader-wrapper, #preloader, .loader).
  The loader is hidden from the first paint (CSS in <head>).
- Column widths cleaned up (no half-empty rows when options are hidden).

What's new in v62
-----------------
Private message notices (like phpBB / XenForo):
- Title bar: a red "✉ 3 new" pill (pulsing) on every forum page while there are
  unread private messages — also on mobile, where the sidebar sits below the content.
- Account box: a highlighted "3 new private messages · Latest from <name>" row.
- Popup (bottom right, full width on phones): "New private message — From <name>:
  <subject>" with Read / Later. Shown once per new message (remembered in the
  browser), never on the messages pages.
- With one unread message the links open it directly, otherwise the inbox.
- The small counter in Quick links stays as it was.
- All texts can be changed: Advanced → Translations → Private message notices.

Image resizing in the editor (CKEditor):
- One resize handle for new and existing images: click an image, drag the blue
  corner handle (mouse or touch). A size label is shown while dragging.
- Works the same when a post is edited again, as often as you like; the size is
  stored on the image itself (width + max-width:100% + height:auto) and is never
  lost or turned into full width.
- Fixed: a new upload that was not resized was saved as 100 px wide ("max-width"
  was read as the width), and editing could drop the chosen size.
- Fixed: the editor content was not converted on submit in the right order, so
  the browser could post the raw editor HTML.
- The handle follows the image while scrolling inside the editor and stays
  reachable for images taller than the editor.
- Saved sizes are kept on the page even if the site theme sets img{width:100%}.

Other:
- Too many wrong logins no longer end in an error page; the wait message is shown
  in the login form.

What's new in v61
-----------------
Quotes and replies follow the usual forum pattern (phpBB / Discourse / XenForo):
- Quoting a post copies only that post's own text (its quotes, reply box and
  signature are left out), so no more quote pyramids. If you select part of a post
  first, only the selection is quoted. The quote header is "Name wrote · date" and
  links to the quoted post.
- Quotes inside quotes (also in existing posts) collapse to one line
  "Earlier quote: …" that can be opened.
- Long quotes are clipped with a "Show more" button. Works for guests too.
- "In reply to Name #id" link above a reply (links to the right page and post). When
  the reply already quotes text it is a single compact line; otherwise it shows a
  two-line preview of the answered post without its quotes.
- Quote/reply colours follow the theme; Obsidian: black (--sf-quote-bg).

What's new in v60
-----------------
- Reply-to box ("X wrote: view") uses the theme colours instead of a fixed light
  grey; black in the Obsidian preset (CSS variable --sf-replyto-bg to change it).

What's new in v59
-----------------
- Up to v55 the "Native Forum → Settings" screen displayed some switches as OFF and
  saved them as OFF (fixed in v56). Affected: Allow front-end registration (Register
  buttons disappeared), Require e-mail confirmation, Show topic summaries, PM e-mail
  notifications, Markdown version of topics, /llms.txt, Override site <title>.
  The upgrade switches these back ON once; review them under Settings afterwards.

What's new in v58
-----------------
- Category topic list: the author/date column sizes to its content, so the date is
  never cut (title column takes the remaining space). Checked at 1400/1000/800px with
  8-4 and 9-3 sidebar.
- Narrow sidebar: the search field keeps its width, the scope selector shrinks.

What's new in v57
-----------------
- Login and Register open as modal dialogs on every forum page (both themes, native
  <dialog>, no Bootstrap JS needed). Links still work without JavaScript; after login
  you return to the page you were on.
- Sidebar "Latest topics": shortened titles show the full title in a popup on hover
  (same as long titles in the main area).
- Topic page author column: "Joined" shows the date only and no longer gets cut.
- Profile: message box tabs (All / Inbox / Sent) are styled buttons; form labels and
  the "Show email on forum" checkbox no longer take the site's large label font.
  Profile stats list has clear rows and dates never wrap.
- Page heading sits under the breadcrumb (was pushed to the far right).
- Private messages pages have a breadcrumb.
- Mobile: index and category rows reworked (icon + title, then stats + last post in
  one compact line); pinned topics use the same row layout; profile tabs scroll.
- Badges (Pinned/VIP/Locked) have consistent size; topic tags no longer show "##".

What's new in v56
-----------------
Every setting was tested on the frontend (set → page reloaded → change verified).
- Layout settings now work in the Simple theme too: Content/Sidebar widths (7-5,
  8-4, 9-3, 10-2), Forum width (container, container-xl, full, inherit), full-width
  breakout, top/bottom spacing. Sidebar mode "forum index only" works.
- Settings page: checkboxes that were OFF were rendered as checked (checked="0") and
  got switched ON with the next save (e.g. "Show topic post on every page"). Fixed,
  also in the Native Forum → Settings screen.
- Defaults now apply at runtime even if the settings were never saved (e.g. auto
  ranks were off until the first save).
- Design preset + Design Studio: the preset is the base look and every Design Studio /
  header colour value you set overrides it (previously Design Studio only worked with
  preset "Custom"). Empty fields keep the preset look.
- Translations (Advanced → Translations): all frontend texts are applied now (33 of
  them were ignored before).
- Registration off now hides the form and all Register buttons; "Follow topic" off
  hides the toggle; "Hide VIP categories = No" shows restricted categories as locked
  rows; theme colour meta also in site layout.
- Export/import boxes were stored in the module config on every save (config grew
  to 160 KB+). They are no longer saved; the next save cleans the config.
- Removed duplicate/legacy options from the settings page (Icon set → use Icon
  provider; Bootstrap auto-load → use Bootstrap integration; Site Bootstrap version;
  Allow guests to vote — votes require a member account).

What's new in v55
-----------------
- Fix: on sites whose theme floats the header/wrappers (e.g. sites that
  float:left;width:100%), the forum was squeezed to 0px width next to the header and
  appeared empty. The forum now always starts below floats and forms its own block
  context.
- Fix: all forum CSS uses em instead of rem, so sites with html{font-size:62.5%}
  (10px) no longer shrink forum text and buttons.

What's new in v54
-----------------
- New default design preset "Obsidian" (assets/presets/obsidian.css): dark slate panels and title bars, crimson accent (#e31b3f), grey
  square buttons, white titles with red hover, red text links, yellow/red badges,
  square corners, transparent forum background over the site's background, font
  inherited from the site. Covers index, groups, categories, topics/posts, members,
  profiles + tabs, messages, search, login/register, forms, tables, pagination.
  Upgrading sets the preset automatically if "Custom" was selected; change it any
  time under Settings → Theme & icons → Design preset.
- Fixed missing button padding (undefined --sf-btn-pad-* variables) in all presets.

What's new in v53
-----------------
- Settings reorganized into clear groups: General · Page layout & integration ·
  Theme & icons · Topics, posts & editor · Members, registration & ranks ·
  Notifications & private messages · Permissions, guests & VIP · SEO & GEO ·
  Design Studio · Attachments · Advanced (template fields, tools, translations).
  Consistent column widths; nothing was renamed, saved values are kept.
- Admin styles are theme-agnostic (light, dark, Konkat): no hard-coded white panels.
- Fixed a fatal error on the settings page on sites without FieldtypeFieldsetTabOpen.
- Summaries are shown under titles: category summaries on the index and group pages,
  topic summaries in category lists and under the "last post" on index/group pages
  (setting: Topics, posts & editor → Show topic summaries in lists).
- E-mail confirmation for registrations (on by default): no account is created until
  the link in the e-mail is clicked; the user is then logged in and taken back to the
  forum with a welcome message. Unconfirmed registrations expire (default 24 h). Only
  a password hash is stored while pending. Sender address is configurable.
- Index "last post" cache is cleared when posts change.

What's new in v50 / v51
-----------------
Critical fixes
- Page-reference fields (forum_author, likes, votes, PM from/to, reply-to, followed
  topics, read/write roles) could not store user pages -> authors, likes, votes and
  private messages were silently lost. Fixed; upgrade back-fills authors.
- Every forum page was output 4x on sites using Markup Regions (_main.php) and had
  no <html>/<head>/<title>/viewport. New layout modes fix this (see below).
- Topic page markup had stray </div> tags (broken layout, 877px wide on mobile).
- init() rewrote files in /site/templates on EVERY request; removed (sync only on
  install/upgrade or via Settings -> Tools).
- Upgrades overwrote saved settings with defaults; fixed.
- canPost()/canVote() used isLoggedin() (only true for the current user) and guests
  bypassed "allow guests to post". Fixed.
- XSS in private messages (body was not sanitized). Fixed.
- Replies were not checked against VIP/category write permissions. Fixed.
- Votes/likes: CSRF added, own posts excluded, only posts of the current topic.
- Replies were shown out of order when posted in the same second.
- /forum-user/name/ profile URLs never worked (non-existent config flag); unknown
  users now return a real 404.

Layout (Settings -> SEO, GEO & page layout)
- Inside site layout (default, "auto"): the forum is rendered inside YOUR site's
  _init.php + _main.php — your header, menus, footer and scripts (sliders, marquee,
  back-to-top, analytics…) keep working. Works with Markup Regions (forum goes into
  the element with id "content", configurable) and with delayed output ($content,
  configurable) and with layouts that print $page->body directly. If none of these
  is found, the forum goes into the content element (configured id, content, main,
  page-content, …), then <main>, then between </header> and <footer> — never above
  the site header. The forum background is transparent and the font is inherited so
  it blends with your design; Design Studio settings still apply.
- Standalone: the forum renders its own complete HTML5 document (used automatically
  when the site has no appendTemplateFile/_main.php).
- Isolation: all forum CSS is scoped to .sf-wrap (no global utility classes), and
  forum JS only reacts to forum markup — it never touches the site's dropdowns,
  links or scripts.

SEO
- <title>, meta description, canonical (incl. ?p=N pagination), rel prev/next,
  robots (noindex for login/register/profile/messages/search/restricted content),
  X-Robots-Tag, Open Graph + Twitter cards.
- JSON-LD: DiscussionForumPosting (with Comment items, interaction counters,
  keywords), BreadcrumbList, CollectionPage + ItemList, ProfilePage, WebSite with
  SearchAction.
- /forum/sitemap.xml (add it to robots.txt:  Sitemap: https://your.site/forum/sitemap.xml)
- /forum/feed.xml RSS 2.0, per category: /forum/feed.xml?c=<category id>
- Reply URLs 301-redirect to the correct topic page + #reply-<id> anchor.

GEO (generative engine optimization)
- /llms.txt — forum map for AI crawlers (llmstxt.org format)
- Every topic as clean Markdown: <topic url>?format=md (linked via rel=alternate)
- Long, quotable text in DiscussionForumPosting JSON-LD

Security & spam (Settings -> Security, spam & registration)
- Login/registration: CSRF, honeypot, signed timestamp, minimum fill time.
- Registration validates username/email uniqueness and password length and never
  assigns privileged roles. Roles "forum-member" and "forum-admin" (moderators) are
  created on install.
- Flood control between posts (default 15 s, moderators exempt).
- Friendly error messages instead of HTTP 500 on expired forms / flood / permissions.

Frontend
- v51: all templates restyled consistently (index, group, category, topic, user
  profile + tabs, my profile + tabs, messages, members directory with search, search
  results with highlighting, login, register); mobile-checked at 390px.
- New assets/css/sf-modern.css: system font stack, neutral header colors, cards,
  pagination, focus rings, skip link, mobile post layout, print styles,
  reduced-motion; Design Studio settings still override it.
- CKEditor and editor scripts are only loaded for users who can post (faster pages
  for guests); all scripts are deferred.

Upgrade from an older build
1. Replace the folder /site/modules/NativeForum/ with this one.
2. Modules -> Refresh (runs the upgrade: fields repaired, authors back-filled,
   template files synced into /site/templates/).
   Customized template copies containing the text "NativeForum Custom" are kept.


ProcessNativeForum + NativeForum
================================

This package contains a NativeForum implementation with its own Admin menu,
similar idea to the Blog (ProcessBlog + MarkupBlog) modules.

Structure in this ZIP
---------------------
site/modules/NativeForum/
  - ProcessNativeForum.module.php   (admin Process module)
  - NativeForum.module.php          (core forum logic + auto-create fields/templates/pages)
  - template-files/
      forum-root.php
      forum-layout.php              (shared frontend helpers + layout open/close)
      forum-category.php
      forum-topic.php
      forum-reply.php
      forum-messages-root.php
      forum-message.php
      forum-profile.php
      forum-user.php
      forum-members.php
      forum-group.php
      forum-login.php
      forum-register.php
      native-forum.css              (Simple theme)
      native-forum-bootstrap.css    (Bootstrap theme)
      native-forum-front-helpers.php
  - assets/
      css/                          (extras, runtime, layout fixes, compat shim)
      js/                           (editor, follow, lightbox, quote, ui, uploads)
      admin-colorpicker/            (used by Design Studio settings)
      admin-help/                   (tooltips for Design Studio settings)
      presets/                      (light, dark, mono, auto preset CSS)

Installation
------------
1. Upload this folder to your ProcessWire site so that it becomes:
   /site/modules/NativeForum/

   (You can also use Modules > New > Upload and upload this ZIP directly.)

2. In the admin go to Modules -> Refresh.

3. Install the module "ProcessNativeForum".
   - ProcessWire will automatically install the required "NativeForum" module.

4. On install:
   - NativeForum will auto-create:
       * All forum-related fields (forum_body, forum_author, forum_like_users, etc)
       * All templates (forum-root, forum-category, forum-topic, forum-reply,
                       forum-messages-root, forum-message, forum-profile,
                       forum-user, forum-members, forum-group)
       * Root pages: /forum, /messages, /forum-profile, /forum-user, /forum-members
       * Adds forum_nickname + forum_avatar fields to the user template
   - ProcessNativeForum will:
       * Create an Admin menu item under Setup/Tools (depending on your AdminTheme)
         called "Native Forum"
       * Copy the frontend template files (forum-*.php) into /site/templates/
         but ONLY if they don't already exist
       * Copy native-forum.css into /site/templates/ if it doesn't exist

Usage
-----
- Visit Admin -> Native Forum (new menu item).
  - Dashboard with stats and quick links.
  - Groups/Categories tab: drag & drop reorder, VIP/Pinned toggles, inline edit.
  - Topics tab: list/create topics per category.
  - Messages tab: list/create private messages.
  - Members tab: user list with nickname/email/signature.
  - Settings tab: full module config (same as core module settings page).

- Frontend pages created automatically:
  - /forum               (template: forum-root)
  - /forum/*             (groups: forum-group)
  - /forum/*/*           (categories: forum-category)
  - /forum/*/*/*         (topics: forum-topic, replies under them)
  - /messages            (template: forum-messages-root, inbox/sent list)
  - /messages/*          (forum-message)
  - /forum-profile       (template: forum-profile, edit nickname/avatar/email)
  - /forum-user          (template: forum-user, public profile pages)
  - /forum-members       (template: forum-members, members directory)
  - /forum-login         (template: forum-login)
  - /forum-register      (template: forum-register)

- The default CSS is copied as /site/templates/native-forum.css
  You can include it in your main layout, e.g.:

  <link rel="stylesheet" href="<?= $config->urls->templates ?>native-forum.css" />

You can later customize any of the copied template files in /site/templates/
without touching the module code. Use the Settings -> Tools section in admin
to re-sync templates from the module if you need to.


Themes
------
Two frontend themes are available (Settings -> Frontend & Theme):
- Simple   : built-in lightweight theme (no Bootstrap required).
- Bootstrap: Bootstrap 5 markup; pairs well with sites already on Bootstrap.

A Design Studio (Settings -> Design Studio) lets you tweak colors, typography,
spacing, buttons, badges, and per-template overrides without writing CSS.
A "Reset design" button restores all design tokens to their defaults.


Permissions
-----------
- Permission "native-forum" is created on install. Assign it manually to the
  roles that should access the NativeForum admin menu.
- canPost() works for any logged-in user by default. Optionally enable guest
  posting in Settings -> Permissions & Guests.


Version history (notable)
-------------------------
v3 - Multi-tab admin UI; manual role assignment; CSRF in templates.
v4 - Admin forms for creating topics/messages; Forum tree layout config.
v5 - Explicit uninstall cleanup; guest posting; basic voting; reply-to-message;
     frontend login/register templates; profile editing.
v6 - Fixed role creation order on install.
v7 - Removed automatic role/permission creation to avoid install errors.

Cleanup pass (this build)
-------------------------
- Fixed fatal error in forum-layout.php caused by a stray reference to
  undefined sf_asset_url() / SF_ASSET_VER / $head (now uses the standard
  modUrl + filemtime cache-busting like the rest of the file).
- Fixed duplicated `case 'share':` and `case 'quote':` branches in the
  inline SVG icon switch.
- Removed 4x repeated $addIfUnset() block that lived outside the closure's
  scope and would fatally error on PHP 8 ("undefined function" via closure).
- Defined $themeClass, $authorClass, $tplClass in the Simple theme branch
  of sf_layout_open() so wrapper class output is no longer "Undefined variable".
- Removed dangerous self-modifying init() blocks that wrote files into
  /site/templates/ with broken PHP escape sequences.
- Removed duplicate sf_sanitize_css_color() definition in init().
- Removed duplicate TemplateFile::render hook (forum-root.php closures were
  injected twice with two different mechanisms).
- Consolidated repeated <style> blocks in admin into a single sfAdminCss()
  helper that only emits CSS once per request.
- Fixed an unterminated CSS rule in native-forum-bootstrap.css that ended
  with "..." instead of "}", which silently broke every rule below it.
- Removed the unused assets/admin-theme-tools/ folder (referenced nowhere).
- Cleaned up duplicate `.sf-drop-hint` CSS selector.
- Polished admin CSS for cleaner table look (sticky headers, hover rows,
  consistent card padding).

Cleanup pass v2 (config + frontend polish)
------------------------------------------
- native-forum.css: added the missing CSS variables that the file itself
  referenced (--sf-blue, --sf-green, --sf-header-bg, --sf-header-color).
  Card headers, breadcrumb bars and primary buttons would otherwise fall
  through to the user-agent default and lose their color.
- native-forum.css: Card header / breadcrumb / button color is now driven
  by --sf-header-bg / --sf-primary, with --sf-blue and --sf-green kept as
  legacy aliases for older selectors.
- NativeForum.module.php: removed the duplicated addOption('mono', ...)
  on the Design Preset select.
- NativeForum.module.php: declared defaults for ~20 keys that the UI saved
  but the $defaults array did not list, so module config now consistently
  shows the saved values (forumStyle, forumWidthPreset, forumForceFullWidth,
  forumTopSpacePx/forumBottomSpacePx, forumPrependMarkup/Placement and the
  Append pair, layoutMainColsLg, sidebarDisplayMode, sidebarShowTemplates,
  postAuthorSide, frontendCkToolbar, bootstrapAutoLoad, bootstrapIntegration,
  bootstrapSiteVersion, bootstrapCompatShim, bootstrapCssUrl, bootstrapJsUrl,
  iconProvider, faStyle, treePreset, activityEnabled/Window, plus
  showTopicPostOnAllPages and attachmentsLightboxLibrary).
- forum-layout.php: added filemtime-based cache-busting to the main theme
  CSS link (native-forum.css / native-forum-bootstrap.css) so updates to
  /site/templates/native-forum.css aren't stuck behind browser/ProCache.
- sf-extras.css: added a defensive sizing rule for inline SVG icons that
  don't carry width/height attributes, so any icon used in templates can't
  blow up to the 300x150 user-agent default.
