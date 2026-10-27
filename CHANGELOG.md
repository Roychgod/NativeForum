# Changelog

All notable changes to Native Forum are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] – 2026-10-27

First public release (successor of SimpleForum).

### Added
- Groups, categories, topics, replies, private messages, topic following, likes / votes, ranks, member profiles and directory
- Site layout and standalone rendering modes; Simple and Bootstrap 5 themes; six design presets and Design Studio
- Admin: dashboard, reports (moderation queue), groups & categories, topic editor, messages, members, settings
- Export / Import of the whole forum, with optional encrypted password transfer
- Anti-spam: honeypot, signed time check, JS check, flood control, e-mail and IP block lists, banned words, link limits, Turnstile, StopForumSpam
- SEO / GEO: meta and Open Graph tags, JSON-LD, sitemap, RSS, `/llms.txt`, Markdown version of topics
- Optional demo content, removable with one click
- Automatic takeover of an existing SimpleForum installation

### Security
- CSRF protection on profile settings (My profile and own public profile) and on the private message quick reply
- Changing the e-mail address on the profile is refused when another account already uses it
- Upload endpoint always refuses executable / script file types (PHP, HTML, SVG, JS …), whatever is listed in the allowed extensions

### Fixed
- Private message quick reply showed an error page instead of a message when sending was refused (spam rules, flood limit)
- Avatar upload on the own public profile ignored the "Allow avatar upload" setting
- Optional parameters declared explicitly nullable (no deprecation notices on PHP 8.4)
