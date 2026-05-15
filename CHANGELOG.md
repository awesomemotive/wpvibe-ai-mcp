# Changelog

All notable changes to the WPVibe WordPress plugin *(listed on WordPress.org as "Vibe AI")*. The canonical source for WordPress.org's update API is `readme.txt`; this file mirrors the same information in markdown for GitHub readers.

## [1.2.3] — 2026-05-15

### Fixed
- Draft theme name no longer accumulates `(WPVibe Draft)` on every publish cycle. The suffix is stripped on both create and publish, and the theme header cache is invalidated after restore. Thanks to J. Hoon Yu for the report.

## [1.2.2] — 2026-05-11

### Security
- SSRF hardening on `/upload-media` — validates every resolved A and AAAA record against private, loopback, link-local, and reserved ranges; re-validates redirect hops.
- Server-side user scoping on `/last-change` so a lower-privilege user can't read change summaries from an admin session.
- Require `edit_theme_options` or `edit_posts` in addition to the `x_wpvibe` header before bumping the admin "Connected" indicator.
- 24-hour TTL on the draft theme preview token so a leaked URL can't be used indefinitely.
- Removed SVG from the file-write allowlist (SVG can embed script and isn't needed for classic-theme scaffolding).

### Fixed
- Undefined variable when building the "View Trash" admin URL in the change tracker.

### Maintenance
- Uninstall now clears `wpvibe_last_active`, `wpvibe_preview_token_issued`, the activation-redirect transient, and any leftover `*-wpvibe-draft` / `*-wpvibe-backup` theme directories on disk.

Thanks to Rob Weaver for the responsible disclosure.

## [1.2.1] — 2026-04-20

### Compliance
- Migrated inline styles and scripts to `wp_enqueue_style` / `wp_enqueue_script`.
- Replaced direct PHP file I/O with the `WP_Filesystem` API across theme and file operations.
- Replaced `exec()`-based PHP syntax validation with an in-process tokenizer.

### Added
- Unsplash stock photo search with third-party service disclosure.

### Fixed
- Allow SQL comparison operators in `db query` and honor the `--limit` flag; added `{prefix}` placeholder for table prefixes.
- Detect an active WPVibe connection via last-active timestamp instead of the auth token.
- Custom CLI command sanitizer that preserves angle brackets used by SQL queries.

## [1.1.0]

### Added
- Expanded WP-CLI dispatcher with 16 new commands (34 total).
- New read commands: `plugin search`, `option list`, `taxonomy list`, `term list`, `post meta get`, `media list`, `comment list`, `comment count`, `sidebar list`.
- New write commands: `post create`, `post update`, `post delete`, `post meta update`, `post meta delete`.
- Plugin install and update with two-phase confirmation flow.
- Flag normalization: hyphenated flags (`--per-page`) auto-convert to underscored (`--per_page`).

### Security
- Block sensitive options (auth keys, salts) from being read via `option get`.
- Whitelist `post get` return fields (excludes `post_password`).

### Improved
- Content truncation for large `post_content` and `post_content_filtered` fields.

## [1.0.0]

### Added
- Initial release.
- WordPress site connection with one-click OAuth authorization.
- Full WordPress REST API access for AI content management.
- WordPress Abilities API support (WP 6.9+).
- WordPress theme file browsing — list, search, outline.
- WordPress theme editing via draft-preview-publish workflow.
- Classic WordPress theme builder.
- WordPress WP-CLI native dispatch.
- WordPress media uploads from URL.
- Unsplash stock photo search.
- Smart live reload with context-aware navigation.
- Progressive skills system for guided AI WordPress workflows.
