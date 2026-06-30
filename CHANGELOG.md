# Changelog

All notable changes to the WPVibe WordPress plugin *(listed on WordPress.org as "Vibe AI")*. The canonical source for WordPress.org's update API is `readme.txt`; this file mirrors the same information in markdown for GitHub readers.

## [1.5.1] - 2026-06-30

### Security
- Image imports now pin the download to the exact IP address that passed the security check, closing a DNS-rebinding window where a hostname could switch to an internal address between validation and download.
- Content meta edits and searches now enforce WordPress per-key meta permissions, so a user can no longer read or change protected post meta they are not authorized for, even on posts they can otherwise edit.
- The WP-CLI post meta update and delete commands now guard every protected meta key (not just core internal keys) behind the same explicit `--force` override.

### Fixed
- Image imports no longer fail with "you are not allowed to upload this file type" when the source name has dots before the extension (e.g. macOS screenshots like "…14.45.58@2x"). The importer now derives the extension from the file's actual type instead of trusting the parsed name.

## [1.5.0] - 2026-06-25

### Added
- Surgical content edits: targeted find-and-replace on a post's content, excerpt, or title, on post meta, and on site options without rewriting the whole value. Two endpoints (content search and content edit) locate the exact text, then replace one match or all. Serialized values are refused so they cannot be corrupted.
- Bulk cleanup commands: post update, post delete, user delete, and plugin uninstall now accept several targets in one call.

### Changed
- Approval previews enumerate every affected item when an irreversible action touches more than one target (deleting posts, deleting users, uninstalling plugins). Reversible actions (trashing posts, updating posts) still run without interruption.
- Clearer database change previews: update queries now show a sample of the rows that will change (previously only deletes did), and long values are trimmed.
- The approval gate for direct SQL now also covers REPLACE, CREATE, RENAME, and GRANT/REVOKE statements.

### Fixed
- Frontend edit affordances render only for registered WPVibe fields/settings, preventing stray edit markers on unrelated template attributes.
- Classic starter theme front-page hero fields resolve against the configured static front page, so hover-to-edit links point to the correct page fields.
- Classic starter theme declares responsive-embeds support so embedded media scales on mobile.

## [1.4.0] - 2026-06-01

### Added
- Field API for theme authors: register editable custom fields and global settings from a theme's functions.php via wpvibe_field_register(), wpvibe_setting_register(), and wpvibe_field_group_register(), with admin meta box rendering, save handlers, and sanitization across 12 field types. Templates read native get_option() / get_post_meta() so they keep rendering when the plugin is deactivated.
- "WPVibe AI" meta box on every post edit screen for themes that declare WPVibe: yes in style.css, surfacing registered fields plus a Connect Claude / ChatGPT CTA when no MCP client is paired.
- Frontend hover-to-edit affordance: registered fields render with a dashed outline and edit pin during draft preview, click to jump to the wp-admin edit screen.
- Hybrid classic starter theme: Tailwind v4 (browser CDN at draft time, compiled dist/styles.css at publish) plus Gutenberg color and typography integration from the same theme.css @theme tokens, bundling Alpine.js v3.15.12. No theme.json.
- Cookie-based draft preview that survives wp-admin navigation, so the field API works in wp-admin without a preview-token query string on every URL.
- Elementor integration with four REST endpoints: list widgets, schema discovery, save page, and save Pro theme-builder templates. Routes return 404 with elementor_inactive when Elementor is not installed.
- Per-request REST timing: every WPVibe REST response carries an X-WPVibe-PHP-Time-Ms header.
- Human-in-the-loop approval for destructive operations: AI-initiated mutating SQL, user deletes, plugin uninstalls, and --force trash bypasses pause and surface an approval URL the user confirms in their browser.
- WP-CLI-style cleanup commands: option add, option delete, transient delete (--all / --expired), and transient list. Non-blocked option and transient deletes auto-execute; protected core options stay hard-blocked.
- Approval Log admin tab: an append-only audit of every destructive operation executed after approval, including the dry-run preview and the result.
- New REST endpoints: /wpvibe/v1/cli/run-approved, /wpvibe/v1/audit-log, /wpvibe/v1/registered-meta.

### Changed
- Destructive operations are classified by command and SQL keyword rather than a default-deny allowlist. The narrow list (mutating SQL, user delete, plugin uninstall, post delete --force) returns approval_required with a row-count preview when it hits the database; everything else auto-executes behind the existing per-command capability checks.

### Fixed
- Custom post types auto-receive 'custom-fields' support when fields are registered, fixing silently dropped meta on REST writes for CPTs that lacked it.
- First write to a new draft-theme subdirectory (such as dist/styles.css) no longer fails the path-safety check, which now walks up to the nearest existing ancestor for realpath validation.
- publish_draft_theme tolerates a missing live theme directory and creates it from the draft instead of fatal-ing.

## [1.3.0] - 2026-05-20

### Added
- New unauthenticated /wpvibe/v1/ping endpoint returns plugin and WordPress version so the MCP server can detect plugin presence before generating an OAuth magic link. Cuts the "magic link works but plugin is missing" onboarding failure.

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
