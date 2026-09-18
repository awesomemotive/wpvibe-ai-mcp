# Changelog

## [1.17.3] - 2026-09-18

- Fix: Administrator accounts can read and edit protected (underscore-prefixed) meta through content edit and content search, matching what the CLI already allowed. Keys a plugin guards with its own auth callback stay refused, with a message that names the owning plugin's path.
- Fix: updating WPVibe from within WPVibe no longer fails with an undefined function on sites where the automatic updater runs outside wp-admin.
- Fix: a staging or cloned site no longer displays the production site's verified connection status; saved connection observations are tied to the site address.
- Fix: saving through Bricks, Breakdance, Elementor and Beaver Builder keeps a page template the active theme no longer offers instead of resetting it to default.
- Fix: a draft theme copy that fails partway is cleaned up, and a leftover draft directory that is an unmodified partial copy is removed automatically; anything else is named so it can be removed by hand.
- Hardening: search-replace refuses to rename an option into or out of a protected name, or to a non-ASCII name. Background operation records are signed and any record not written by WPVibe on this site never runs.

## [1.17.2] - 2026-09-17

- Fix: Signed operation approvals recover from stale per-site proof keys after updating, without reconnecting the site.
- Fix: creating a classic theme preserves existing unpublished drafts and refuses conflicting files instead of replacing them.
- Fix: interrupted draft creation cannot overwrite leftover draft files on retry. Failed deletion retains the draft record for recovery.
- Fix: publishing a new classic theme keeps its name and accurately reports whether a previous theme was backed up. Apostrophes in new theme names no longer break generated PHP.
- Hardening: draft creation, editing, publishing and deletion coordinate their file changes. Unsafe paths and drafts manually activated as the live theme are refused.
- Hardening: authenticated safety checks let WPVibe verify draft-preservation support before enabling new classic-theme creation.

## [1.17.1] - 2026-09-16

- Fix: saving Elementor data into an existing draft page or template no longer publishes it. The post keeps its status unless you ask for a change.
- Fix: Allow for WPVibe now applies to the Approve click itself. Sites where a security plugin turns off Application Passwords passed the connectivity check but still refused the approval; both now agree.
- Fix: WP-CLI commands that spell an apostrophe inside a single-quoted value the POSIX way (closing quote, backslash apostrophe, reopening quote) now reach the handler byte for byte, and post meta values keep their backslashes. Shortcode values written that way lost their quotes. Two backslashes outside quotes now collapse to one, as in a shell.
- Security: the background-run and self-update hand-off records can no longer be written with option add, option update, option patch, or raw SQL sent by an AI, so a seeded record cannot start a run through the public loopback route. Reading them for status still works.
- Fix: editing a page whose saved template no longer exists (theme switched, builder canvas template with the builder off) failed with "Invalid page template" and a misleading crash hint. Content edits and post updates now leave the stored template alone.
- Security: wp-content diagnostic reads redact the connection proof key and bare 64-character hex secrets that a failed database write can leave in debug.log.
- Hardening: the Allow for WPVibe setting can no longer be changed through WP-CLI commands sent by an AI; only the button on the WPVibe page changes it.

## [1.17.0] - 2026-09-15

- New: Four-step setup page. Add WPVibe to your AI, connect the site with one prompt, and confirm with a read. Steps turn green on their own as WordPress approves and your AI reads the site.
- New: Check site connectivity tests the site from inside and out before you open your AI: HTTPS, Application Passwords, a staging password gate or REST-blocking plugin, a maintenance page answering the API, a host that strips the Authorization header, and WPVibe reaching the site. Failures name the cause and the fix; the report carries the exact Cloudflare rule when a firewall is challenging WPVibe.
- New: Allow for WPVibe permits Application Passwords for WPVibe requests only when a security plugin turns them off site-wide.
- New: A signed confirmation when your AI completes its first read, plus a copyable report for support.
- New: Administrators can read wp-content files for diagnostics (read-only, secrets redacted, audited); wp-content writes remain blocked.
- New: The dashboard widget shows the same connection status as the setup page, with a Check connection link.
- New: The setup page says when the site address is http:// and WPVibe needs https://, and links to Security and the DPA in the footer.
- Change: Sites already connected read as connected from their recent activity after updating. Nothing to redo.
- Fix: The approval page explains when this WordPress account cannot create Application Passwords, or has them disabled, instead of a generic error.
- Fix: Check site connectivity runs when wp-admin is open at a different address than the site URL, such as with or without www.
- Fix: live reload polling is bounded with timeout and backoff and stops after repeated failures instead of polling forever.
- Hardening: reconnecting keeps the existing Application Password until the replacement verifies; the browser-approved credential can seed the proof key only once.
- Fix: proof key provisioning, reset and rotation work on SQLite-backed sites (WordPress Studio, Playground, the SQLite integration plugin).

## [1.16.5] - 2026-09-09
* Fix: publishing Tailwind themes now collects source files in one request, reducing host rate-limit errors on themes with many templates.
* Hardening: publishing stops if source files cannot be collected completely or if the draft changes during preparation, preventing incomplete or stale compiled styles from going live.

## [1.16.4] - 2026-09-05
- Fix: approved long-running commands (a live `search-replace`, mutating `db query`) no longer depend on WP-Cron to run in the background. On some hosts WP-Cron never fires, so from 1.16.0 to 1.16.3 those commands could sit for 30 minutes and then report an unknown outcome without ever running. The request now answers immediately and keeps running the command in the same PHP process after releasing the connection (PHP-FPM and LiteSpeed); where PHP cannot release a connection early, the site hands the job to itself over a one-time token, and where it cannot reach itself either, the command simply runs inline. Jobs that an earlier version left queued for WP-Cron are expired if that cron ever fires, never run late.
- Fix: a fatal error during a background run (memory, an engine time limit that could not be lifted) is now recorded in the operation receipt instead of leaving it open.
- Fix: `plugin update vibe-ai` (WPVibe updating itself) uses the same hand-off and no longer depends on WP-Cron either.

## [1.16.3] - 2026-09-04
- Fix: sites on XSERVER with the WAF "Command" rule enabled can connect again. That rule blocks any URL containing "ping", which was the name of the route WPVibe checks before connecting. The check now also answers at /wpvibe/v1/health, and the plugin's own self-update loopback moved off that word too. The /ping route stays for older connections.

## [1.16.2] - 2026-09-03
- Fix: on sites that lock the file editor (DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS), the read-only theme tools work again: listing files, reading a file, previews, and page HTML. Reads come from the draft theme when one exists and otherwise from the active theme, so a site can be inspected before an edit is proposed. Writes and draft creation stay locked.
- Improvement: the reminder that deactivating or deleting WPVibe does not disconnect the site has moved off the Plugins screen and onto the WPVibe settings page, shown once the site is connected. The confirm on Deactivate stays.
- Hardening: once a site has a proof key, the routes that require one refuse a request without it instead of falling back to plain authentication, including the window between a reconnect and the first signed call.
- Hardening: after a reconnect, only the application password that reconnect created can register the next proof key, so a credential entered by hand cannot seed one.
- Hardening: the proof-key options are blocked from `option get`, `option update`, and `option delete`.

## [1.16.1] - 2026-09-02
- Fix: uploading a file type WordPress does not allow (or an empty file) now says so, with the extension and where to allow it, instead of reporting a filesystem error that sent people checking folder permissions.
- Fix: reading a translated page's HTML on a WPML site with language directories now returns that language's page instead of the default one.
- Feature: the WPVibe row on the Plugins screen explains that deactivating or deleting the plugin does not disconnect the site or revoke its application password, with links to do both, and a confirm on Deactivate says the same before the plugin's code stops running.
- Fix: `comment create` now applies WordPress's comment filtering for authors who lack the unfiltered_html capability (non-super-admins on multisite, sites with DISALLOW_UNFILTERED_HTML), matching what core does for those users.

## [1.16.0] - 2026-09-02
- Feature: WPVibe can now update itself through your AI assistant. `plugin update vibe-ai` schedules the update to run out-of-band (the same model WordPress core uses), so the connection serving the request is never the one replacing the plugin's files. Where available, WordPress's automatic updater runs it, with its post-update fatal check and rollback for active plugins. Progress and the outcome are recorded in a status option your AI can read back.
- Feature: `plugin auto-updates enable|disable|status` commands, matching real WP-CLI behavior, so your AI can enroll any plugin (including WPVibe) in WordPress auto-updates. Also fixes auto-update enrollment on multisite, where the setting lives in a network option.
- Feature: `theme update --all` with `--exclude` and `--dry-run`, and multiple theme slugs in one command, matching the plugin update family.
- Feature: `--expect-version` on `plugin update` and `theme update` (a WPVibe extension): the update refuses if the available version is not the one you named, closing the race where a newer release lands between review and execution.
- Fix: publishing a draft theme whose functions.php fatals now rolls back to the theme the site was actually running, and only ever activates a theme that is really installed, instead of leaving the site pointed at a directory that no longer exists.
- Fix: raw SQL that writes to a protected identity table (users, usermeta, blocked options), including through a JOIN from another table, is now refused when submitted instead of after a human approves it, and the refusal names the protected table.
- Fix: `theme install --version=<version>` now installs the version you asked for. The themes API ignores a version argument, so the flag was silently dropped and the latest release was installed instead; an unavailable version is refused by name.
- Feature: comment moderation from your AI assistant: `comment create` (replies via `--comment_parent`), `comment approve`, `unapprove`, `spam`, `unspam`, `trash`, `untrash`, and `comment delete`. Deleting without `--force` moves comments to the trash; `--force` permanently deletes and pauses for approval. `comment list` gains `--format=ids|count`, `--orderby`, `--order`, `--offset`, and `--comment__in`.
- Feature: approved long-running commands (a live `search-replace`, mutating `db query`) can run in the background: the request answers immediately, a one-shot WP-Cron job (or a token-authenticated loopback when cron is disabled) runs the command as the approving user, and the outcome is recorded in the operation receipt your AI polls. No more connection timeouts on big search-replace runs.
- Feature: `post list` and `user list` accept `--paged=<n>` / `--offset=<n>` so large sites can be enumerated page by page; the truncation notice names the next page.
- Security: the approval-only routes (`cli/run-approved`, `code-snippet`) now verify a per-operation proof signed by WPVibe with a key provisioned per site, so an approved operation can only be executed by the approval flow that showed it to you, never by a request that merely holds the application password. Sites without a key keep working as before until WPVibe provisions one.
- Fix: operation receipts now accept the fleet runner's operation ids, so background jobs can recover the outcome of a call that timed out mid-flight instead of re-probing.
- Feature: `core update` and `core update-db`. Updating WordPress core pauses for browser approval and shows the exact version change; the approval is re-verified against the site at execution, and a leftover .maintenance file is cleaned up so a failed update cannot strand the site.
- Fix: when a security plugin filters WordPress core update data, `core check-update` and `core update` now say so instead of reporting "no update available" as a certainty.
- Fix: Elementor pages saved through WPVibe are verified after the save. If Elementor stored the layout empty, WPVibe writes the requested structure directly, removes the empty autosave the editor would otherwise open, and never reports success on a page that is still empty.
- Fix: a content edit whose old text differs from the stored value only by whitespace now applies when it is the one unambiguous match, and a miss says what was tried instead of a bare "not found".
- Hardening: PHP file writes are refused when they would redeclare a function or class the running site already has, and publishing a draft theme renders the front page afterwards and rolls back to the backup on a fatal, keeping the draft for editing.
- Fix: the Approve click on the connection screen now creates the application password through WPVibe's own route with your logged-in session, so hosts that block the core users endpoint as "user enumeration" no longer stop the one-click connect.
- Fix: when the connection check fails, the connected page now says what actually came back (a firewall page, a redirect, a server error) and what to do about it, instead of one generic message.

## [1.15.5] - 2026-08-29
- Fix: when WordPress rejects the application password it created seconds earlier, the plugin now reports whether that user has any application passwords stored at all, plus the install facts that explain a lost one (persistent object cache, shared user tables, wp-content drop-ins). WPVibe uses this to say "your site did not keep the password" instead of blaming the host for stripping the Authorization header.
- Fix: the Approve page no longer leaves an empty red bar when the site's REST API answers with a firewall page or nothing at all. It now says what came back (status, page vs JSON, the security vendor if recognisable), the two fixes, and where to get help, and warns before you click when the same request is already blocked. Advisory only; the Approve form is never changed. Disable with the WPVIBE_DISABLE_AUTHORIZE_NOTICE constant or the wpvibe_authorize_notice filter.

## [1.15.4] - 2026-08-23
- Fix: sites running WPCode with Error Logging turned on no longer hit a "Class WPCode_File_Cache not found" fatal on WPVibe requests when a snippet emits a warning. WPCode only loads that class inside wp-admin, so WPVibe now loads it for its own requests. This restores code snippet creation and WP-CLI reads on affected sites.

## [1.15.3] - 2026-08-18
- Fix: services that authenticate with WooCommerce REST API keys (TrackShip, Metorik, and similar) no longer receive a 401 Unknown username error on sites where another plugin resolves the user early in the request.

## [1.15.2] - 2026-08-15
- Security: hardened raw database access so a disguised query cannot bypass the protections on core options, the users table, secret keys, and server files (SQL comments, option-name obfuscation, file-access primitives, privileged-target parsing).
- Fix: read-only queries using REPLACE() to count/inspect content are no longer refused by mistake.


## [1.15.1] - 2026-08-14

* Hardening: your page builder's site-wide settings and global presets (such as Divi's design presets) now require your approval before any write, on every path including option writes and raw SQL, and the approval screen shows exactly which settings change. This closes a route where a bulk edit could overwrite a builder's global styling and break its editor across the whole site.
* Hardening: a content edit that would corrupt a page's block markup (leaving a block's settings unreadable, so the block vanishes from the editor) is now refused before it saves.
* Hardening: WPVibe no longer creates a draft copy of a page builder parent theme (such as Divi or Avada), which would leave the builder unable to load. It points you to the correct path instead.
* Fix: when a builder value cannot be edited directly, the guidance now names a command that actually works instead of one that dead-ends.

## [1.15.0] - 2026-08-13

* Feature: your AI assistant can now manage navigation menus with WP-CLI style commands (menu create; menu item add-custom, add-post, add-term, update, and delete; menu location assign), no raw database access needed.
* Feature: category and tag management commands (term create, term update, term delete). Deleting a term asks for your approval first and shows how many posts and child terms are affected.
* Feature: theme mod set for changing theme customizer values, and rewrite structure for permalink settings.
* Feature: user account commands (user create, user update, user set-role, user add-role, user remove-role) plus a full user meta family (get, list, add, update, delete).
* Security: user changes that grant or remove administrator level access, or change a password or email address, always require your approval first. WPVibe also refuses to demote your connected account or the last user holding the administrator role.
* Security: capability and session storage keys can never be written through user meta commands, closing off role changes that would bypass the permission system. Session hashes, application passwords, and plugin stored secrets (such as two factor seeds and API keys) are withheld from user command output.
* Hardening: passwords set through user commands are hidden from approval screens, logs, and analytics.

## [1.14.3] - 2026-08-11

* Fix: page builder compatibility. The live-refresh script no longer loads inside Divi, Elementor, Beaver Builder, Bricks, or Breakdance editing sessions, where it could interrupt the builder while your AI assistant was making changes (fixes the "Edit with Divi" endless spinner).
* Hardening: approved plugin replacements re-verify the installed version and active state at execution, and refuse to run if the site changed after the approval was granted (for example an auto-update during the approval window).
* Fix: clearer guidance when eval and eval-file commands are blocked. The denial now points to the code snippet workflow instead of a dead-end help lookup.
* Hardening: the approval gate and the install handler now derive "is this replacing an existing plugin" from one shared check, so they can never disagree.

## [1.14.2] - 2026-08-10

* Feature: plugin rollback. `plugin install <slug> --version=<version> --force` now replaces an installed plugin with the exact version you name, so your AI can walk a broken update back to the last working release. Replacing an existing install pauses for browser approval and shows the version change before anything runs; if the plugin was active it stays active afterward.
* Fix: `plugin install` on an already-installed plugin now explains the two ways forward (update, or force-replace with a specific version) instead of failing with a raw folder error, and unsupported install flags are refused with the supported list instead of being silently ignored.
* Fix: a version that does not exist on WordPress.org is refused with a clear error instead of silently installing the latest release, and replacing a single-file plugin now switches the active copy cleanly instead of leaving the old file active.
* Hardening: the force-replace approval also covers directories WordPress can no longer read as plugins (broken installs), and WPVibe refuses to replace its own files over its own connection.

## [1.14.1] - 2026-08-08

* Improvement: approved operations now leave an execution receipt on your site. If the connection drops right after you click Approve, WPVibe can check the receipt and tell your AI exactly what happened (it ran, it was rejected, or it never arrived) instead of reporting the outcome as unknown.
* Improvement: an approved operation can never run twice. If the same approved request is ever re-sent, the site returns the recorded result of the first run instead of executing again.

## [1.14.0] - 2026-08-06

* Feature: Bricks support. Your AI can now build and edit Bricks pages through Bricks' own save pipeline, with the theme's element security checks applied and page CSS regenerated on every save (external file mode included). Layouts open in the Bricks editor exactly like hand-built pages.
* Feature: Breakdance support. Your AI can now build and edit Breakdance pages, including the blank canvas template for landing pages. Saves go through Breakdance's own data format and refresh its CSS cache, so pages render correctly on the first load and open cleanly in the Breakdance editor. Writing a layout requires Breakdance builder access, the same permission Breakdance uses for its own editor, so connect as an administrator or a role you have granted Breakdance access.
* Fix: text values written to post fields by the AI now store exactly what was sent. Words like true or false used to be converted before saving, which could silently break theme and plugin settings that expect the literal text (found with GeneratePress page layout options). Structured JSON values are unaffected.
* Fix: sites that lock the theme and plugin file editors (a common managed-hosting setting) are now reported as locked instead of "a security plugin removed your permissions", so your AI explains the real reason and what to do about it.
* Security: saving an Elementor or Beaver Builder page as private or scheduled now requires the same publish permission WordPress requires for publishing. Previously a user who could edit but not publish could reach those states through the builder endpoints. Publishing itself was always checked.

## [1.13.5] - 2026-08-05

* Improvement: editing page-builder content now works through the safe content-edit path. Elementor stores a page's text inside a protected field, so surgical text edits used to fall back to direct database writes. Those edits now go through the normal content-edit tool, which also refreshes Elementor's cached styles so the change shows on the front end right away.
* Fix: a content edit that would have broken a builder layout's stored data is now refused before saving, with the original left untouched, instead of writing a corrupted value.
* Fix: content search and edit now match text the way it appears on the page when the database stores HTML entities. A search for "R&D" finds stored "R&amp;D", and ordinary spaces match non-breaking spaces, so an AI reading rendered HTML can edit the real stored value without a no-match miss.
* Security: direct database writes to protected site settings (site address, active plugins, user roles and capabilities, the users table) are now refused even after approval. These already could not be changed through the normal commands, and raw SQL can no longer be used to get around that. Everyday content edits are unaffected.

All notable changes to the WPVibe WordPress plugin *(WordPress.org slug: `vibe-ai`)*. The canonical source for WordPress.org's update API is `readme.txt`; this file mirrors the same information in markdown for GitHub readers.

## [1.13.4] - 2026-08-04

* Fix: draft theme preview no longer breaks on sites running a child theme. The preview pointed WordPress at the draft for both the child theme and its parent, so the parent theme's code never loaded and the page stopped rendering partway through. The preview now keeps the parent theme in place and layers your draft on top of it, the way WordPress expects a child theme to work. The live site was never affected. Thanks to Ryan De La Uz for the detailed report.
* Improvement: the WP-CLI command layer is reorganized under the hood so new commands can ship in smaller, safer pieces. Which commands you can run and how they behave are unchanged.

## [1.13.3] - 2026-07-28

* Fix: updating a single plugin no longer leaves it deactivated. WordPress silently deactivates a plugin before replacing its files, and the update command did not turn it back on, so a plugin that was active before an update could end up switched off without any error. Updates now use the same method as the WordPress dashboard and the WP-CLI tool, which keeps the plugin active the whole time.
* Improvement: the update result now states whether the plugin is active or inactive after the update, verified against the site rather than assumed, so your AI assistant reports the real state instead of guessing.
* Fix: an update that failed to replace the plugin files used to report success. It now reports the failure and says the installed version is unchanged.

## [1.13.2] - 2026-07-28

* Fix: cache purge --url=… was stripped before dispatch, so a surgical purge silently became a full cache flush. The flag now reaches the purge dispatcher for cache purge and its engine aliases; bare or empty --url errors instead of over-purging. When every detected page cache refuses a URL, the command now fails and names each engine's reason instead of claiming there was nothing to purge.
* Fix: content search now finds text regardless of quote style. WordPress displays straight quotes as curly ones, and a search for the plain version used to come back empty even though the editing route would have matched it. That mismatch sent AI assistants hunting in the wrong place or rewriting whole posts when a one-line edit was intended. Search and edit now follow the same matching rules, so anything search returns is guaranteed to work as an edit.
* Fix: search results now say when a long line was shortened. Results were silently trimmed at 400 characters, so on long paragraphs and page builder layouts an AI could be handed a shortened snippet with no way to know text was missing. Results are now centered on the matched text and clearly flagged whenever they or their surrounding context were trimmed.
* Improvement: a failed edit now shows what is actually stored nearby. When an edit misses because the text is not quite what is stored, the error includes the closest matching passages from the real content, so the retry is informed instead of a guess. When an edit matches more than one place, the error now lists those places instead of only counting them.
* Improvement: edits now report what was actually saved. WordPress filters and security plugins can quietly alter content as it is saved. After every edit the plugin re-reads the stored value and says so when the site changed what was written, so an AI never builds its next edit on a version of the content that no longer exists.
* Improvement: theme editing denials now name their real cause. On multisite networks only network super admins can edit theme files, and some security plugins remove that ability even from administrators. In both cases the old error advised reconnecting with a more privileged account, which cannot work; the error now says which situation applies and what actually helps.

## [1.13.1] - 2026-07-27

* Security: WordPress settings that WPVibe protects can no longer be changed through the content editing route. WPVibe keeps a list of settings that are never writable, including the ones controlling whether anyone can register and what role new accounts get, plus your site's security keys and salts. The WP-CLI commands honoured that list, but the content editing route wrote settings directly and did not, so an AI acting on your site could change them without the usual approval step. That route now enforces the same list, and the security keys can no longer be read back through it either. Ordinary settings are unaffected.
* Fix: commands no longer report success for work they did not do. Some WP-CLI options were accepted and then quietly ignored, so a request could come back successful while part of what you asked for never happened, which is the one kind of failure your AI cannot notice. Those options now return a clear error naming what is unsupported. If a command you rely on has been dropping an option, you will see an error where you previously saw a false success. The error is the accurate answer, and the earlier success was not.
* Fix: search and replace can no longer damage stored passwords. The user_pass column is now always excluded, even if it is explicitly requested, so a replacement that happens to match text inside a password hash cannot corrupt it and lock someone out.
* Fix: listing a post's custom fields now matches WP-CLI. A field with more than one stored value was folded into a single entry using field names WP-CLI does not use, so your AI could not tell how many values existed or reliably remove just one of them. Every stored row is now listed separately, using the standard post_id, meta_key and meta_value names. Nothing about how values are stored has changed.
* Improvement: two more edits that cannot be undone now ask first. Removing a key from inside a setting, and removing every stored value of a custom field when the protected-field guard is overridden, now show a preview and wait for your approval. WordPress keeps no trash for settings and no revision history for custom fields, so page builder layouts and template settings cannot be recovered afterwards. Removing one specific value still runs without a prompt, because that only affects the row you named.
* Improvement: long lists now say when they were cut short. Listing options, users, or posts stops at a row limit, and the reply had no way to signal that more existed, so an AI could work through what looked like a complete set and quietly miss the rest. Those listings now warn when the limit was reached and say not to treat the result as complete.
* Improvement: reporting options behave as WP-CLI does. --format=ids and --porcelain now return the bare values they are meant to, which makes them usable for chaining one command into the next.

## [1.13.0] - 2026-07-26

* Fix: WPVibe now works on hosts that strip the login header. On some servers, commonly Apache running PHP as CGI or FastCGI and some LiteSpeed setups, the web server removes the Authorization header before WordPress can read it. Every WPVibe request then arrived as a logged out visitor and failed with a permission error, even though the site was connected and the password was valid. WPVibe now sends the same credentials a second way that these servers pass through, and the plugin hands them back to WordPress before it checks who you are. Who is allowed to do what does not change, and sites that were already working are unaffected. To turn this off, define WPVIBE_DISABLE_AUTH_FALLBACK as true.
* Improvement: permission errors now name the real cause. A request WordPress could not authenticate used to report a missing capability, which sent people off to reconnect with a different account when the actual problem was the server dropping the header, or Application Passwords being switched off. WPVibe now reports which of those it was, so the fix matches the problem.

## [1.12.0] - 2026-07-23

* Fix: safer settings edits. Option values are now treated as plain text unless your AI explicitly asks for JSON (matching the real WP-CLI), and WPVibe refuses any write that would silently change a setting's stored type, which could previously corrupt cache plugin settings or take a site offline. Reading an option now tells your AI when a value is one string that merely looks like a list.
* Fix: theme and code edits no longer fail on hosts whose security firewall mistakes legitimate code for an attack. Some hosting firewalls inspect the content of save requests and block anything that looks like PHP or SQL, which made edits to files like functions.php fail intermittently with a 403 error. When that happens, WPVibe now automatically resends the same edit in an encoded form the firewall does not misread, and the plugin decodes it before any of its usual security checks run. Nothing changes about what gets saved or who is allowed to save it.

## [1.11.0] - 2026-07-22

* New: White label mode for agencies. One click on the WPVibe admin page (or one ask to your AI) hides WPVibe everywhere in the WordPress dashboard: the admin menu, dashboard widget, Plugins list entry, and editor sidebar. The site stays fully manageable through your AI. WordPress auto-updates keep the plugin current while hidden, and if the site goes 30 days without WPVibe activity the plugin reappears on its own, so it can never be lost.
* Fix: Content edits now match straight and curly quotes interchangeably. WordPress converts quotes to the curly kind when it saves, which could make an edit fail with "no match" on text your AI had just written.
* Fix: The WPVibe theme header is now registered on every request, so site_info correctly detects AI-built themes over REST. Props to Nick Kimuli for finding it and opening the fix on our GitHub mirror.

## [1.10.0] - 2026-07-20

* New: Beaver Builder support. Your AI can now build and edit real Beaver Builder pages, landing pages, and layouts. The result is a native Beaver Builder layout: open it in the builder and every row, column, and module is there and individually editable, exactly as if you built it by hand. Works with both the free Beaver Builder (Lite) and the paid plugin.
* New: Beaver Themer support. Build site-wide headers and footers (navigation menus included), plus archive, 404, and single-post layouts, each wired to the right location rules. Themer headers and footers need a Themer-compatible theme (the Beaver Builder Theme, Astra, GeneratePress, Kadence, and similar); WPVibe tells you up front when the active theme cannot render one instead of leaving a layout that shows nowhere.
* New: "post meta add" appends a row to multi-value post meta, and "post meta delete" accepts an optional value to remove only the matching row. Previously update replaced every row of a key and delete wiped them all, which made multi-value metas effectively untouchable. Divi's Theme Builder links its templates through exactly this kind of meta, so your AI can now add a second Theme Builder template without destroying the first.

## [1.9.1] - 2026-07-16

* Fix: uninstalling a plugin, updating a plugin, or deleting a theme through WPVibe no longer fails with a 500 error. These commands ran WordPress's own delete and upgrade functions without first loading wp-admin's filesystem bootstrap, which wp-admin pre-loads but WPVibe's REST context does not. All file-modifying commands now load it up front. Reported and diagnosed by Nicholas Kimuli ([#2](https://github.com/awesomemotive/wpvibe-ai-mcp/issues/2), [#3](https://github.com/awesomemotive/wpvibe-ai-mcp/pull/3)).
* New: update several plugins at once. `plugin update` now accepts multiple slugs or `--all` (with `--exclude` and `--dry-run`), previews the full list before you confirm, and reports a per-plugin result. WPVibe itself is skipped automatically, since it cannot replace its own files over its own connection.
* Fix: Google Site Kit (and other plugins that read the logged-in user early) now see the correct user on WPVibe requests. WordPress resolves Application Password logins later than plugins that initialize on init, so Site Kit read an empty Google authorization and rejected every Analytics, Search Console, and PageSpeed request with missing_required_scopes. WPVibe now tells WordPress that REST requests are API requests from the start, so the login resolves before those plugins load.

## [1.9.0] - 2026-07-14

* New: WPVibe dashboard widget. Your wp-admin Dashboard now shows whether the site is connected, the last few changes your AI made, and a short list of cookbook recipes matched to the plugins you actually run, each with a copy-ready prompt. Not connected yet? The widget gives you the one-line prompt that connects your site.
* New: recipe suggestions are filtered on your own site against your installed plugins. The widget fetches one small public recipe list from wpvibe.ai (nothing about your site is sent, same pattern as the WordPress Events and News widget) and picks locally.
* Improvement: recent AI-made changes are now kept in a small activity log (last 10 entries) so the dashboard can show them; the full record remains in the Approval Log.

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
