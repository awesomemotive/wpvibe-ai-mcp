# Changelog

All notable changes to the WPVibe WordPress plugin *(WordPress.org slug: `vibe-ai`)*. The canonical source for WordPress.org's update API is `readme.txt`; this file mirrors the same information in markdown for GitHub readers.

## [1.8.2] - 2026-07-14

* Fix: uninstalling a plugin, updating a plugin, or deleting a theme through WPVibe no longer crashes the site with a 500 error. These commands ran WordPress's own delete and upgrade functions without first loading wp-admin's filesystem bootstrap, which wp-admin pre-loads but WPVibe's REST context does not. All file-modifying commands now load it up front. ([#2](https://github.com/awesomemotive/wpvibe-ai-mcp/issues/2))

## [1.8.1] - 2026-07-13

* Fix: SeedProd landing pages now render on your site automatically after WPVibe builds or updates them. The automatic render step added in 1.8.0 only recognized SeedProd theme templates and coming-soon or maintenance pages, so regular landing pages still asked you to open the SeedProd builder and click Save yourself.

## [1.8.0] - 2026-07-10

* New: SeedProd pages and theme templates that WPVibe builds now render on your site automatically, without you opening each one in the SeedProd builder and clicking Save yourself. WPVibe triggers the builder's own save step for you through a single-use sign-in link that expires in two minutes and is scoped to that one page, so nothing else on your site is exposed.
* Improvement: Elementor pages can now set their WordPress page template at save time (new page_template field on the save-page endpoint): "elementor_canvas" for standalone landing pages, "elementor_header_footer" to keep the theme's header and footer without the page title. Previously every AI-built page rendered with the theme's default template, which prints the raw page title above the design on most themes. Unknown template names are saved but flagged in the response warnings.
* New: Code snippets, safely. Your AI can now draft a code snippet (PHP, JavaScript, CSS, HTML, universal, or text) through the free WPCode plugin. You approve the exact code, type, and placement in your browser before anything is written, and every snippet is saved switched off: you review and enable it yourself in wp-admin, where WPCode runs its own fatal-error check. PHP snippets are also syntax-checked at write time, so a typo is caught before it ever reaches the enable switch. Requires WPCode; without it the feature says so clearly instead of failing cryptically.
* Hardening: WP-CLI commands can no longer create or edit WPCode snippet posts (including their custom fields and type/location terms). Snippet code always goes through the code approval panel, and enabling a snippet remains something only you can do.
* New: "post term set", "post term add", and "post term remove" assign existing taxonomy terms to a post (by slug, or by id with --by=id), including private taxonomies the REST API cannot reach. Terms are never created implicitly; a missing term is reported with how to create it first.
* New: "option pluck" reads a single nested key out of a large settings option (plugin settings arrays, JSON blobs) instead of fetching the whole option into the conversation.
* Improvement: "theme list" now reports update availability (update and update_version fields, plus --update=available), matching what plugin list has done since 1.5.2, so "any theme updates?" is one command instead of probing each theme.
* Improvement: "cache purge" now detects the official Cloudflare plugin and purges its edge cache alongside the other cache plugins, so a purge actually reaches visitors on Cloudflare-fronted sites.
* Fix: post create/update accept --post_content_base64 for content that mixes single and double quotes. The plain --post_content flag silently dropped colliding quote characters; the new flag round-trips the content byte for byte, backslashes included.
* Hardening: "db query" now rejects MySQL executable comments (/*! ... */), which could hide a blocked keyword from the query validator.
* New: "cache purge --url=" purges specific pages instead of the whole cache, on every cache plugin with a URL purge API (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, SiteGround Speed Optimizer). Plugins without one still flush fully, and the result says which happened. "--skip=" leaves named layers alone (for example Cloudflare or the object cache).
* Fix: "cache purge" no longer reports the Cloudflare cache as purged when the Cloudflare plugin's Automatic Cache Management toggle is off. The plugin silently ignores purge requests in that state; the result now says so and points at the setting.
* Improvement: cache purges now run origin caches first and Cloudflare last, so the edge cannot re-cache stale pages mid-purge.
* Improvement: publishing a draft theme now flushes every detected cache as part of the publish itself, so visitors see the new design immediately on every cache plugin, not just the ones that react to a theme switch.
* Fix: deleting a user by email address now shows the real account details in the approval preview. Previously the preview claimed the user would not be found while the deletion itself would still proceed after approval.
* Fix: the "transient delete --all" approval preview no longer claims site transients are included; only regular transients are deleted, and the preview now says so.
* Fix: "widget list" reads sidebars through the WordPress core accessor, so sites upgraded from very old WordPress versions no longer see a bogus "array_version" row.
* Fix: search-replace always quotes primary-key values in its row queries. On plugin tables with text primary keys, numeric-looking key values could previously match the wrong row and copy one row's content into another.
* Fix: "option list --search" and "transient list --search" treat underscores in your search text as literal characters instead of single-character wildcards, so searching blog_* no longer matches unrelated options.
* Improvement: truncated previews of long post, comment, and option values now cut cleanly on multibyte (emoji, accented, CJK) content.

## [1.7.1] - 2026-07-09

* Fix: CLI commands that carry punctuation inside a quoted value (a serialized setting, an SEO title with a pipe) are no longer rejected as unsafe. Quoted values are treated as data; the safety checks on command structure are unchanged.
* Fix: Publishing a draft theme now works on hosts that block deleting the live theme folder, by swapping the folders instead of deleting and recopying. If anything goes wrong mid-publish, a complete backup is kept and the message says exactly where.
* Improvement: When a draft theme action finds no draft, the message now says whether the last draft was published or deleted and when, so your AI stops trying to recreate a draft you already finished with.

## [1.7.0] - 2026-07-08

* Improvement: Every error the plugin reports now includes structured facts about what went wrong (the cause, whether retrying can help, and whether the connected account is an administrator), so AI assistants stop guessing at remedies and stop repeating fixes that cannot work.
* Fix: Permission denials when creating content or editing custom fields through the CLI tools now surface as a proper permission error naming the post type and capability, instead of a quiet command failure that error tracking never recorded.

## [1.6.3] - 2026-07-08

* Fix: Administrators were blocked from editing content that belongs to plugins with their own permission schemes (WPForms forms, some LMS and e-commerce post types). Content search and edit now work for administrator accounts on those post types. Post types that explicitly forbid editing (such as order records) stay locked, and protected fields keep their existing safeguards.
* Fix: The error shown when an account lacks permission for a specific post now names the post and its type, and no longer suggests reconnecting when reconnecting would not help.
* Improvement: Site info now reports which WordPress account is connected and its role, so your AI assistant can spot a limited account up front instead of failing mid-task.

## [1.6.2] - 2026-07-08

* Improvement: The plugin now displays as WPVibe, matching the product brand at wpvibe.ai. Same plugin, nothing else changes.
* Fix: "plugin update vibe-ai" no longer tries to replace the plugin's own files over its own connection, which failed with an unhelpful server error. It now explains that WPVibe should be updated from the wp-admin Plugins screen or via auto-updates.

## [1.6.1] - 2026-07-07

* Fix: "option list --autoload=on|off" returned no rows on WordPress 6.6+ (the query only matched the legacy yes/no autoload values). It now matches the current on/off/auto-on/auto-off/auto values as well.
* Fix: "option update" and "option patch" no longer report a false failure when writing a numeric or boolean value (JSON decoding produced an int/bool while WordPress stores scalars as strings; setting an option to its current value also tripped this).
* Fix: "user list" now includes user_registered, so account age is available to site-audit workflows.
* Fix: WP-CLI commands no longer have HTML tags silently stripped from their values (a value like "&lt;b&gt;x&lt;/b&gt;" was stored as "x", and script blocks vanished entirely, surfacing as a confusing usage error). Commands containing angle brackets are now rejected with a clear message pointing to the content editing tools, which handle HTML safely.
* Fix: "post list" was silently ignoring targeting flags (--s, --year, --monthnum, --author) and returning the full unfiltered list, which is dangerous when a listing feeds a bulk operation. Those filters now work, and unsupported flags are rejected with a clear message instead of ignored.
* Fix: "post create" now honors --post_date (site-local time, matching WP-CLI) instead of silently creating the post dated today.
* Fix: "post list" now accepts a comma-separated --post_type (e.g. post,page) instead of returning an empty list.
* Fix: draft themes can now be deleted on hosts that block the HTTP DELETE method at the server (a POST alias was added; previously the cancel action failed with a 405 on many hardened hosts).
* Improvement: "option get" now allows reading users_can_register and default_role (writes remain blocked). Security-audit workflows need to check whether open registration is enabled.

## [1.6.0] - 2026-07-02

Large WP-CLI emulation expansion. Every new write is gated behind browser approval where it is destructive, and none of it bypasses WordPress capability checks.

### Added
- Command discovery: `help` returns the full supported-command catalog (name, tier, usage, approval requirement) generated from the security allowlist, and `help <command>` filters it. `cli version` / `cli info` return honest emulator identity (plugin, WordPress, and PHP versions) instead of an error.
- `search-replace` (previously a stub): serialized-data-aware find/replace that unserializes, replaces, and re-serializes nested arrays and objects with correct lengths, so widget settings and theme mods survive a domain migration. Works table by table in primary-key chunks, skips the `guid` column by default (`--include-guids` to opt in), supports `--dry-run`, `--skip-tables`, `--skip-columns`, and explicit tables. Live runs pause for browser approval with per-table match counts and warn when the replacement would change the site URL; `--dry-run` runs without approval.
- Role and capability editing (the gap core REST cannot fill): `cap add/remove` on roles, `role create` (with `--clone`), `role delete`, `role reset`, and `user add-cap/remove-cap`. Every change pauses for browser approval spelling out the literal grant, administrator-equivalent capabilities are flagged, and lockout protections refuse removing core capabilities from the administrator role, deleting the administrator role, or deleting the last administrator user.
- `theme install`, `theme update`, and `theme delete` (delete approval-gated; refuses the active theme and the parent of an active child), symmetric with the existing plugin commands.
- `cron event run <hook>` and `cron event delete <hook>` (both approval-gated), plus `cron test` for WP-Cron spawn diagnostics.
- Unified `cache purge`: detects the installed cache plugin (LiteSpeed Cache, WP Rocket, SG Optimizer, WP Super Cache, W3 Total Cache, Breeze, plus the Elementor CSS cache) and calls each plugin's own purge API, then flushes the object cache. The plugin-specific spellings assistants guess first work as scoped aliases.
- `config get <constant>` for diagnostics like WP_DEBUG or DISALLOW_FILE_EDIT. Credentials and secrets (database credentials and anything matching KEY, SALT, SECRET, PASSWORD, or TOKEN) are blocked; `config list/set/edit` remain blocked.
- `option patch insert|update|delete` for surgical changes to one key inside a nested settings array without rewriting the whole option.
- WP-CLI checksum verification: `core verify-checksums` and `plugin verify-checksums` compare installed files against the official WordPress.org checksums, report modified, missing, and unexpected files, and support `--include-root`, `--exclude`, `--version`, `--locale`, and `--strict`.
- Permission diagnostics `cap list <role>` (with `--show-grant`) and `role list`; `maintenance-mode status` (core file, drop-in, and maintenance-plugin detection); and symmetric read commands: `core version`, `core check-update`, `db tables`, `db prefix`, `post-type list`, `menu location list`, `menu item list`, `theme mod list`, `theme get`, `plugin get`, `media image-size`, `transient get`, and `user get`.

### Changed
- Deleting an option now pauses for browser approval with a preview of the stored value, since options have no trash and a plugin's entire configuration can live in one. The AI's own temporary options (`wpvibe_task_` prefix) and transients stay approval-free, and the session-bypass checkbox covers repeated cleanup.

## [1.5.2] - 2026-07-01

### Fixed
- The WP-CLI plugin list command now reports update availability. It exposes "update" (available/none) and "update_version" fields and honors the `--update=available` filter, so an assistant can reliably see which plugins have updates instead of getting blank update info.
- Permission-denied errors now name the specific missing WordPress capability (e.g. "edit_theme_options") instead of WordPress's generic "not allowed" message, so an assistant connected with a lower-privilege account gets an actionable next step instead of a dead end.

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
