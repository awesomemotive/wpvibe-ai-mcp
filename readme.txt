=== WPVibe - MCP Server for WordPress. Connect Claude, ChatGPT & Cursor ===
Contributors: seedprod, smub
Tags: mcp, mcp-server, claude, chatgpt, ai-assistant
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.7.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

MCP server for WordPress. Connect Claude, ChatGPT, Cursor & other AI assistants to manage content, edit themes & automate your WordPress site.

== Description ==

Your WordPress site just became MCP-ready. [WPVibe](https://wpvibe.ai/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin) is the Model Context Protocol server for WordPress, connecting your self-hosted site to any AI assistant that speaks MCP: Claude, ChatGPT, Cursor, Windsurf, OpenCode, and more. No copy-pasting between tabs. No switching between your AI chat and wp-admin. Tell your AI what you want, and it happens on your live WordPress site.

https://www.youtube.com/watch?v=AsasOvrSWgI

= What People Are Saying =

* "New WordPress Plugin Safely And Easily Connects AI To Your Website" (Search Engine Journal, July 2026)
* "The easiest setup of any AI product for WordPress, period. It's so click-and-forget, and it absolutely smashes it for what you can do. It's just mind-blowing." (Jackson Whelan, WPTuts)
* Thousands of WordPress sites connected and over a million WordPress operations performed since launch.

= The Model Context Protocol Server for WordPress =

WPVibe is a complete MCP server implementation for WordPress. The Model Context Protocol, introduced by Anthropic and now adopted across the AI industry, lets AI assistants discover and call tools on connected services through a standard interface. WPVibe packages every meaningful WordPress operation (content management, media uploads, theme file browsing, REST API access, and plugin abilities) as MCP tools your AI can call.

You install this free WordPress plugin, connect your site once, and every MCP-compatible AI client becomes a WordPress co-pilot. The WPVibe WordPress MCP server handles authentication, encrypts credentials with AES-256-GCM, and relays your AI's tool calls to the WordPress REST API. Your WordPress site, your data, your choice of AI.

= Connect Claude to WordPress =

WPVibe is the easiest way to connect Claude to WordPress. Use it with Claude Desktop, Claude on the web, or Claude Code in your terminal. Once connected, ask Claude to draft a blog post, schedule an article, reorganize categories, update site settings, or run any WordPress task through conversation. Claude sees your WordPress site through the MCP bridge and responds with direct action, not just suggestions.

Connecting Claude to WordPress takes about 30 seconds. Install WPVibe, open the plugin admin, click to authorize, then add the MCP server URL to Claude's connectors. From that moment, Claude can manage WordPress content, search WordPress files, upload images, and interact with any WordPress plugin that exposes the Abilities API.

= Connect ChatGPT to WordPress =

WPVibe is the ChatGPT WordPress plugin that actually connects the two systems instead of wrapping an API key. ChatGPT supports MCP servers directly in the web app and the desktop app, so once you add your WPVibe MCP server URL, ChatGPT can read and write to your WordPress site through ordinary conversation.

Ask ChatGPT to turn a Google Doc into a WordPress blog post, find and tag every customer who downloaded a specific resource, update your About page in your own writing voice, or bulk-publish a content calendar. ChatGPT handles the language and strategy, WPVibe handles the WordPress REST API calls behind the scenes. There is also an official WPVibe connector in the ChatGPT Apps directory, so you can add it to ChatGPT in a couple of clicks.

= Connect Cursor, Windsurf, and Every MCP Client =

WPVibe is not locked to a single AI vendor. Cursor, Windsurf, OpenCode, Claude Code, ChatGPT, Claude, and any other AI client that supports the Model Context Protocol can connect through the same MCP server URL. One WordPress MCP server, every AI assistant, no integration rewrite when you switch tools.

For developers, this means Cursor can edit your WordPress theme files with context-aware suggestions, Claude Code can run WordPress tasks as part of an agentic workflow, and Windsurf can scaffold new WordPress templates. For content creators and agencies, this means whichever AI writes best for your brand can publish directly to your WordPress site through the WPVibe MCP bridge.

= AI-Powered WordPress Content Management via MCP =

Managing WordPress content through MCP has never been easier. Create blog posts, update pages, upload media, manage categories and tags, all through natural conversation with your AI assistant. Tell Claude to write a draft post about your latest product launch, ask ChatGPT to update your about page, or have Cursor reorganize your blog categories. Your AI assistant handles the WordPress REST API calls behind the MCP protocol, so you never have to touch wp-admin.

WPVibe does not replace your AI assistant or lock you into a single provider. It works with every AI client that supports the Model Context Protocol, giving you the freedom to use Claude Desktop, ChatGPT, Cursor, Windsurf, OpenCode, or any future MCP-compatible AI tool for WordPress management.

= WordPress Abilities API Support for MCP =

WordPress 6.9 introduced the Abilities API, a powerful way for plugins to declare self-describing operations that AI assistants can discover and execute. WPVibe fully supports this WordPress MCP integration. Your AI can discover what abilities your installed plugins expose, inspect their input schemas, and run them directly through natural conversation.

This means AI-powered WordPress plugin management works automatically over MCP. If a plugin registers abilities (WPForms, SeedProd, and others are adopting this standard), your AI assistant can interact with it without any custom integration. The WordPress Abilities API and WPVibe together make every compatible plugin MCP-ready.

= WooCommerce, Elementor, and Your Other Plugins =

WPVibe works with the plugins already running your site. For WooCommerce, your AI can review the store, manage products, and bulk-edit prices, stock, and descriptions through conversation, so updating fifty product pages no longer means fifty trips through wp-admin.

For Elementor, WPVibe ships dedicated integration endpoints: your AI can discover installed widgets, create and update Elementor pages, and save Elementor Pro theme builder templates with display conditions. Built-in skills teach your AI the right approach for Gutenberg, Elementor, and SeedProd page building. Other plugins work through their own REST APIs or the WordPress Abilities API, and custom fields (including ACF fields, which are post meta under the hood) are read and written correctly, including on custom post types.

= Safely Edit WordPress Theme Files =

WPVibe lets your MCP client browse and edit your WordPress theme files safely. Your AI can list files, search file contents, analyze code structure, and make edits through a draft theme workflow. The draft clones the active WordPress theme into a sandbox, makes changes there, and exposes a preview URL so you can see the results before going live. Your live WordPress site is never touched until you explicitly approve and publish.

Every file operation runs through WordPress capability checks, a path sandbox scoped to the draft theme, and PHP syntax validation before save. You keep the safety of wp-admin's file editing guardrails while giving your AI a real place to work.

= WordPress WP-CLI Commands over MCP =

Run WordPress administration commands through your MCP client. Activate plugins, switch themes, update options, flush caches, query the database, run serialized-data-aware search-replace with a dry run, and more, all via native PHP dispatch with a security-first command allowlist. Everything is emulated through PHP, so it works on shared hosting with no shell and no SSH. Your AI gets a productive WordPress admin surface without the risks of raw command execution.

= Approvals You Can See, and an Audit Log =

WPVibe does not open your site to the world. There is no public endpoint sitting on your site for bots to hit; access runs through WordPress's own encrypted Application Passwords, revocable in one click. And when your AI asks for something destructive (deleting a user, mutating the database, uninstalling a plugin), WPVibe pauses and shows an approval panel right in the chat: the exact operation, a dry-run preview of what will change, and Approve or Decline buttons. Nothing irreversible happens without you.

Every sensitive action is also recorded in an append-only Approval Log in your WordPress admin, including the preview you saw and the result, so you always have a paper trail of what your AI did. Posts default to draft, deletes go to trash, and theme publishing keeps a backup of your previous files so you can roll back.

= Smart MCP Notifications on Your WordPress Admin =

Every change your AI makes over MCP triggers a smart notification in your browser. Edit a post and you will see a toast with a direct link to view or edit the updated content. The WPVibe notification system knows whether you are in the WordPress admin dashboard or viewing the frontend and adapts the link accordingly, so your workflow is never disrupted while your AI is working in the background.

= One-Click WordPress MCP Authorization =

Connecting your WordPress site to an MCP server should take seconds, not half an afternoon. WPVibe does away with application passwords you type by hand and API keys you copy between tabs. Provide your WordPress site URL, click the authorization link that appears in your WordPress admin, and approve the connection. Your WordPress credentials are encrypted with AES-256-GCM and stored securely on Cloudflare-hosted WPVibe servers. One click, done.

= WordPress MCP Server for Every Use Case =

Whether you are a blogger managing content, a developer building WordPress themes, or an agency managing multiple client sites, WPVibe makes AI-powered WordPress management accessible through whichever MCP client you already use.

<strong>Bloggers and Content Creators</strong> write and publish posts, manage media, organize categories and tags, and update WordPress site settings through conversation with Claude, ChatGPT, or any MCP assistant.

<strong>WordPress Developers and Designers</strong> browse theme files, analyze code structure, and edit WordPress themes using a safe draft-preview-publish workflow. Build classic WordPress themes from scratch with AI-powered design directly from Cursor, Claude Code, or your favorite MCP client.

<strong>Agencies and WordPress Site Managers</strong> connect client WordPress sites and manage content at scale. Use the WordPress Abilities API over MCP to interact with installed plugins. Automate routine WordPress tasks with whichever AI assistant fits the job.

= Full WPVibe MCP Server Feature List =

* WordPress MCP server connection with one-click authorization and AES-256 encrypted credential storage
* AI content management - create, update, and manage WordPress posts, pages, media, categories, and tags through AI conversation
* WooCommerce management - review the store and bulk-edit products, prices, stock, and descriptions through conversation
* Elementor integration - create and update Elementor pages and Elementor Pro theme builder templates via dedicated endpoints
* Human-in-the-loop approvals - destructive operations pause for an in-chat approval panel with a dry-run preview
* Approval Log - append-only audit trail in wp-admin of every destructive operation, its preview, and its result
* Surgical content edits - targeted find-and-replace in posts, meta, and options without rewriting the whole value
* Full WordPress REST API access exposed as MCP tools, including custom post types and plugin routes
* WordPress Abilities API support - discover and execute plugin abilities on WordPress 6.9+ sites automatically
* Connect Claude Desktop, Claude on the web, or Claude Code to WordPress via MCP
* Connect ChatGPT's web app and desktop app to WordPress via MCP
* Connect Cursor, Windsurf, OpenCode, and any other MCP-compatible AI client
* WordPress theme file browsing - list, search, and analyze theme file structure and code
* AI WordPress theme editing with a draft-preview-publish workflow, safe sandboxed file operations, and PHP syntax validation
* Classic WordPress theme builder - create new themes from scratch with AI-powered scaffolding
* WordPress WP-CLI commands - run allowlisted admin commands through your MCP client via native PHP dispatch
* WordPress media uploads - download images from URLs directly into your media library via MCP
* Unsplash stock photo search - find high-quality images for your WordPress site from AI conversation
* WordPress live reload - smart browser notifications when your AI makes changes, with context-aware navigation
* Per-user WordPress scoping - live reload only activates for the WordPress admin using WPVibe, not other team members
* WordPress credential encryption - AES-256-GCM encryption at rest with per-site salting for application passwords
* AI WordPress skills - on-demand workflow guides that teach your AI the right approach for each WordPress task
* Progressive MCP tool discovery - your MCP client discovers WordPress tools as it needs them, keeping context efficient
* Built on the open Model Context Protocol standard - no vendor lock-in, any MCP-compatible AI works
* OAuth magic link authentication - no passwords typed into chat, no long-lived tokens on your laptop

= Third-Party Service =

This plugin connects to the WPVibe service at [wpvibe.ai](https://wpvibe.ai) to relay requests between your AI assistant and your WordPress site over the Model Context Protocol. When you connect your site, a WordPress application password is created and encrypted with AES-256-GCM on WPVibe servers hosted on Cloudflare. All communication between the plugin and the WPVibe MCP service occurs over HTTPS.

No data is collected, tracked, or shared with third parties beyond what is necessary to relay your AI assistant's MCP requests to your WordPress REST API. Your content stays on your WordPress server.

* [Privacy Policy](https://wpvibe.ai/privacy/)

= Third-Party Libraries =

WPVibe bundles one third-party JavaScript library for use inside scaffolded classic starter themes:

* **Alpine.js** v3.15.12, MIT License — [https://alpinejs.dev/](https://alpinejs.dev/) — included at `starter-themes/classic/assets/js/alpine.min.js`. Used as the interactivity layer (modals, dropdowns, tabs, accordions, sliders) for AI-generated classic themes. Not loaded outside scaffolded themes.

= Built by SeedProd =

WPVibe is built by the team behind [SeedProd](https://www.seedprod.com/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin), the most popular WordPress landing page and theme builder plugin, trusted by over 1 million WordPress websites. We have been building WordPress tools since 2012 and know what WordPress site owners need from AI-powered management tools.

= Better Than Custom AI WordPress Integrations =

If you have tried connecting AI to your WordPress site before, you have probably dealt with custom API wrappers, writing fetch calls in Python scripts, hand-rolling a ChatGPT Custom GPT, or copying content back and forth between Claude and your browser. WPVibe eliminates all of that friction with a proper MCP server for WordPress.

Unlike custom scripts or one-off WordPress AI integrations, WPVibe uses the Model Context Protocol, an open standard supported by Claude, ChatGPT, Cursor, Windsurf, and a growing list of AI tools. Connect your WordPress site once, use it with any MCP client. No vendor lock-in, no custom code to maintain for your AI WordPress workflow.

Unlike hosted AI WordPress services (AI Engine, GetGenie, Bertha, AI Power, WPCode AI, and similar bundled-AI plugins) that ship one model and one prompt style, WPVibe lets you bring your own AI. Use whichever model reasons best for your task. Claude for long-form writing, ChatGPT for research, Cursor for theme editing, all connected through the same WordPress MCP server. Your data stays on your WordPress server. No third-party servers processing your WordPress content. Your WordPress site, your data, your control.

= Branding Guidelines =

This plugin is a product of SeedProd LLC. The product name is **WPVibe** everywhere: the plugin, the marketing site, the documentation, and the in-product UI ([wpvibe.ai](https://wpvibe.ai/)).

When writing about the plugin, please use the correct spelling:

* WPVibe (correct)
* WPvibe (incorrect)
* Wp Vibe (incorrect)
* WP Vibe (incorrect)
* VibeAI (incorrect)

= WordPress MCP Server Resources =

* [WPVibe WordPress MCP Documentation](https://wpvibe.ai/docs/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin)
* [Privacy Policy](https://wpvibe.ai/privacy/)

== Installation ==

1. Upload the `vibe-ai` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Open the WPVibe menu in your WordPress admin and click the Connect button to view setup instructions for Claude, ChatGPT, Cursor, and other MCP clients

For detailed setup instructions, visit [wpvibe.ai/docs](https://wpvibe.ai/docs/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin).

== Screenshots ==

1. Destructive operations pause for approval right in the chat, with a dry-run preview and Approve or Decline buttons. Nothing irreversible happens without you.
2. The WPVibe admin screen: install the plugin, copy the MCP server URL into your AI client, and your site is connected.
3. Upload images from your computer through a panel in the conversation, and your AI adds them to the WordPress media library.

== Frequently Asked Questions ==

= Is WPVibe free? Do I need another AI subscription? =

The plugin is free, and the WPVibe service has a free plan that includes every tool and skill with a daily allowance of WordPress actions. You bring the AI you already use: WPVibe works with the free plans of Claude and ChatGPT, and it never charges for AI inference. Optional paid plans raise your daily WordPress action allowance, which is completely separate from your Claude or ChatGPT limits.

= Which AI assistants work with WPVibe? =

Any AI assistant that supports the Model Context Protocol (MCP): Claude Desktop, Claude on the web, Claude Code, ChatGPT, Cursor, Windsurf, OpenCode, and any future MCP-compatible client.

= Is WPVibe a WordPress MCP server? =

Yes. WPVibe is a full Model Context Protocol server implementation for WordPress. Your AI client connects to the WPVibe MCP server, which relays authenticated requests to your WordPress REST API.

= Does this plugin modify my live WordPress site directly? =

For content management (posts, pages, media), changes go live immediately, just like editing in wp-admin. For theme editing, all changes happen in a sandboxed draft theme. The live site is never modified until you explicitly publish.

= What authentication does WPVibe use? =

WordPress application passwords (built into WordPress 5.6+). WPVibe uses a one-click authorization flow, so no passwords are typed into the AI chat. Credentials are encrypted with AES-256-GCM at rest.

= Is my WordPress data sent to third-party servers? =

No. WPVibe connects your AI assistant directly to your WordPress REST API over MCP. Your content stays on your server. The only external connection is between your AI client and the WPVibe MCP server, which proxies authenticated requests to your WordPress site.

= Can the AI break my WordPress site? =

WPVibe has multiple safety layers: draft theme isolation for file editing, file extension allowlists, path sandboxing, PHP syntax validation, WordPress capability checks, and WP-CLI command allowlisting. Destructive operations (mutating database queries, user deletes, plugin uninstalls, permanent deletes) pause for an in-chat approval panel with a dry-run preview before anything runs, and every approved operation is recorded in the append-only Approval Log. DELETE operations move to trash (never permanent delete), new posts default to draft status, and publishing a draft theme keeps a backup of your previous theme files so you can roll back.

= Does WPVibe work with Elementor and other page builders? =

Yes. WPVibe creates and edits pages in Gutenberg, Elementor, and SeedProd, with built-in skills for each, plus dedicated Elementor endpoints for pages and Elementor Pro theme builder templates. Other builders work to varying degrees through the REST API.

= Does WPVibe work with WooCommerce? =

Yes. Your AI can review your store and create, update, and bulk-edit WooCommerce products, prices, stock, and descriptions through conversation.

= Does WPVibe work with ACF and custom fields? =

Yes. Custom fields are post meta under the hood, and WPVibe reads and writes them correctly, including on custom post types where plain REST setups often fail silently. Plugins that register meta or expose the Abilities API work automatically.

= Can I connect multiple WordPress sites? =

Yes. Connected sites are unlimited on every plan, including the free plan. Connect all your sites to one account and switch between them in any conversation.

= Do I need to know how to code to use WPVibe? =

No. WPVibe lets you manage your WordPress site entirely through conversation with your AI assistant. No coding required for content management. Theme editing is also conversational, your AI writes the code for your WordPress theme.

== Changelog ==

= 1.7.1 =
* Fix: CLI commands that carry punctuation inside a quoted value (a serialized setting, an SEO title with a pipe) are no longer rejected as unsafe. Quoted values are treated as data; the safety checks on command structure are unchanged.
* Fix: Publishing a draft theme now works on hosts that block deleting the live theme folder, by swapping the folders instead of deleting and recopying. If anything goes wrong mid-publish, a complete backup is kept and the message says exactly where.
* Improvement: When a draft theme action finds no draft, the message now says whether the last draft was published or deleted and when, so your AI stops trying to recreate a draft you already finished with.
* Improvement: Every error the plugin reports now includes structured facts about what went wrong (the cause, whether retrying can help, and whether the connected account is an administrator), so AI assistants stop guessing at remedies and stop repeating fixes that cannot work.
* Fix: Permission denials when creating content or editing custom fields through the CLI tools now surface as a proper permission error naming the post type and capability, instead of a quiet command failure that error tracking never recorded.

= 1.6.3 =
* Fix: Administrators were blocked from editing content that belongs to plugins with their own permission schemes (WPForms forms, some LMS and e-commerce post types). Content search and edit now work for administrator accounts on those post types. Post types that explicitly forbid editing (such as order records) stay locked, and protected fields keep their existing safeguards.
* Fix: The error shown when an account lacks permission for a specific post now names the post and its type, and no longer suggests reconnecting when reconnecting would not help.
* Improvement: Site info now reports which WordPress account is connected and its role, so your AI assistant can spot a limited account up front instead of failing mid-task.

= 1.6.2 =
* Improvement: The plugin now displays as WPVibe, matching the product brand at wpvibe.ai. Same plugin, nothing else changes.
* Fix: "plugin update vibe-ai" no longer tries to replace the plugin's own files over its own connection, which failed with an unhelpful server error. It now explains that WPVibe should be updated from the wp-admin Plugins screen or via auto-updates.

= 1.6.1 =
* Fix: "option list --autoload=on|off" returned no rows on WordPress 6.6+ (the query only matched the legacy yes/no autoload values). It now matches the current on/off/auto-on/auto-off/auto values as well.
* Fix: "option update" and "option patch" no longer report a false failure when writing a numeric or boolean value (JSON decoding produced an int/bool while WordPress stores scalars as strings; setting an option to its current value also tripped this).
* Fix: "user list" now includes user_registered, so account age is available to site-audit workflows.
* Fix: WP-CLI commands no longer have HTML tags silently stripped from their values (a value like "&lt;b&gt;x&lt;/b&gt;" was stored as "x", and script blocks vanished entirely, surfacing as a confusing usage error). Commands containing angle brackets are now rejected with a clear message pointing to the content editing tools, which handle HTML safely.
* Fix: "post list" was silently ignoring targeting flags (--s, --year, --monthnum, --author) and returning the full unfiltered list, which is dangerous when a listing feeds a bulk operation. Those filters now work, and unsupported flags are rejected with a clear message instead of ignored.
* Fix: "post create" now honors --post_date (site-local time, matching WP-CLI) instead of silently creating the post dated today.
* Fix: "post list" now accepts a comma-separated --post_type (e.g. post,page) instead of returning an empty list.
* Fix: draft themes can now be deleted on hosts that block the HTTP DELETE method at the server (a POST alias was added; previously the cancel action failed with a 405 on many hardened hosts).
* Improvement: "option get" now allows reading users_can_register and default_role (writes remain blocked). Security-audit workflows need to check whether open registration is enabled.

= 1.6.0 =
* New: WP-CLI checksum verification. "core verify-checksums" and "plugin verify-checksums" (single plugin or --all) compare installed files against the official WordPress.org checksums and report modified or missing files, so an assistant can run a file-integrity check when investigating a possibly compromised site.
* Improvement: core verify-checksums also reports unknown files inside the core directories (the "File should not exist" check from real WP-CLI, the half that catches injected malware) and supports --include-root, --exclude, --version, and --locale. plugin verify-checksums skips readme.txt/readme.md by default (WordPress.org regenerates readmes after release) with --strict to include them, and reports files added to a plugin folder that are not part of the official release.
* New: Read-only WP-CLI commands assistants ask for most: core version, core check-update, db tables, db prefix, post-type list, menu location list, theme mod list, and plugin get.
* Feature: Unified cache purge. "cache purge" detects the installed cache plugin (LiteSpeed Cache, WP Rocket, SG Optimizer, WP Super Cache, W3 Total Cache, Breeze, plus the Elementor CSS cache) and calls each plugin's own purge API, then flushes the object cache. The plugin-specific spellings assistants guess first (litespeed-purge, elementor flush-css, rocket clean, sg purge, super-cache flush, w3-total-cache flush, breeze purge) work as scoped aliases.
* New: "config get <constant>" reads a single configuration constant (and table_prefix) for diagnostics like WP_DEBUG or DISALLOW_FILE_EDIT. Credentials and secrets (database credentials and anything matching KEY, SALT, SECRET, PASSWORD, or TOKEN) are blocked outright, and config list/set/edit remain blocked.
* New: "option patch insert|update|delete" makes surgical changes to one key inside a nested settings array (plugin settings like Rank Math) without rewriting the whole option.
* Hardening: Deleting an option now pauses for browser approval with a preview of the stored value, since options have no trash and a plugin's entire configuration can live in one. The AI's own temporary options (wpvibe_task_ prefix) and transients stay approval-free, and the session-bypass checkbox covers repeated cleanup.
* Feature: Role and capability editing, the gap core REST cannot fill (the reason role-editor plugins exist): cap add/remove on roles, role create (with --clone), role delete, role reset (restore WordPress defaults), and user add-cap/remove-cap. Every change pauses for browser approval with the literal grant spelled out, administrator-equivalent capabilities are flagged in the preview, and lockout protections refuse removing core capabilities from the administrator role, deleting the administrator role, or deleting the last administrator user. role reset is the escape hatch back to WordPress defaults.
* New: "cron event run <hook>" and "cron event delete <hook>" (both approval-gated) complete the stuck-cron debugging loop alongside cron event list and cron test.
* New: "theme install", "theme update", and "theme delete" (delete approval-gated; refuses the active theme and the parent of an active child theme), symmetric with the existing plugin management commands.
* Feature: search-replace is now implemented (previously a stub). It performs serialized-data-aware replacements (nested arrays and objects are unserialized, replaced, and re-serialized with correct lengths, so widget settings and theme mods survive a domain migration), works table by table in primary-key chunks, skips the guid column by default (--include-guids to opt in), supports --dry-run, --skip-tables, --skip-columns, and explicit table arguments, and reports completed vs remaining tables if it hits the time budget. Live runs pause for browser approval with per-table match counts, and the preview warns loudly when the replacement would change the site URL; --dry-run runs without approval.
* New: Permission diagnostics. "cap list <role>" (with --show-grant) and "role list" let an assistant show exactly which capability a role is missing instead of hand-waving at a permission error.
* New: "maintenance-mode status" reports the effective maintenance state from all three sources: the core .maintenance file (honoring the 10-minute expiry), the wp-content/maintenance.php drop-in, and known maintenance/coming-soon plugins (SeedProd, LightStart, Under Construction, CMP, Maintenance) with their enable state.
* New: "cron test" verifies WP-Cron spawning end to end (DISABLE_WP_CRON, ALTERNATE_WP_CRON, and a live loopback spawn check), so stuck-cron debugging has a first step.
* New: Symmetric read commands: media image-size, transient get (with --network), menu item list, user get, and theme get.
* New: Command discovery. "help" returns the full supported-command catalog (name, tier, usage, approval requirements) generated from the security allowlist, and "help <command>" filters it, so an assistant discovers what is supported instead of burning calls guessing. "cli version" and "cli info" return honest emulator identity (plugin version, WordPress and PHP versions) instead of an error, so an assistant probing the environment gets accurate expectations rather than concluding WP-CLI is broken.

= 1.5.2 =
* Fix: The WP-CLI plugin list command now reports update availability. It exposes "update" (available/none) and "update_version" fields and honors the --update=available filter, so an assistant can reliably see which plugins have updates instead of getting blank update info.
* Fix: Permission-denied errors now name the specific missing WordPress capability (e.g. "edit_theme_options") instead of WordPress's generic "not allowed" message, so an assistant connected with a lower-privilege account gets an actionable next step instead of a dead end.

= 1.5.1 =
* Hardening: Image imports now pin the download to the exact IP address that passed the security check, closing a DNS-rebinding window where a hostname could switch to an internal address between validation and download.
* Hardening: Content meta edits and searches now enforce WordPress per-key meta permissions, so a user can no longer read or change protected post meta they are not authorized for, even on posts they can otherwise edit.
* Hardening: The WP-CLI post meta update and delete commands now guard every protected meta key (not just core internal keys) behind the same explicit --force override.
* Fix: Image imports no longer fail with "you are not allowed to upload this file type" when the source name has dots before the extension (e.g. macOS screenshots like "...14.45.58@2x"). The importer now derives the extension from the file's actual type instead of trusting the parsed name.

= 1.5.0 =
* Feature: Surgical content edits. WPVibe can now make targeted find-and-replace changes to a post's content, excerpt, or title, to post meta, and to site options without rewriting the whole value. Two new endpoints (content search and content edit) locate the exact text first and then replace a single match (or all matches), so large posts no longer have to round-trip through the AI in full. Serialized values are refused so they cannot be corrupted.
* Feature: Bulk cleanup commands. The post update, post delete, user delete, and plugin uninstall commands now accept several targets in one call, so the AI can tidy up a batch of posts, users, or plugins in a single step.
* Feature: Approval previews now list every affected item. When an irreversible action touches more than one target (permanently deleting posts, deleting users, uninstalling plugins), the confirmation screen enumerates each one so you can review the full list before approving. Reversible actions (moving posts to trash, updating posts) continue to run without interruption.
* Hardening: Clearer database change previews. Update queries now show a sample of the rows that will change (previously only deletes did), and long values are trimmed in the preview so a wide table stays readable.
* Hardening: The approval gate for direct SQL now also covers REPLACE, CREATE, RENAME, and GRANT/REVOKE statements, so those route through the same human confirmation as other database writes.
* Fix: Frontend edit affordances now render only for registered WPVibe fields/settings, preventing accidental edit markers on unrelated template attributes.
* Fix: Classic starter theme front-page hero fields now resolve against the configured static front page, so the hover-to-edit links point to the correct page fields.
* Maintenance: Classic starter theme declares responsive-embeds support so embedded media scales correctly on mobile.

= 1.4.0 =
* Feature: Field API for theme authors — register editable custom fields and global settings from a theme's `functions.php` via `wpvibe_field_register()`, `wpvibe_setting_register()`, and `wpvibe_field_group_register()`. The plugin handles admin meta box rendering, save handlers, and sanitization across 12 field types (text, textarea, number, email, url, date, checkbox, color, image, gallery, wysiwyg, post_select, repeater). Templates read native `get_option()` / `get_post_meta()` so they keep rendering when the plugin is deactivated.
* Feature: "WPVibe AI" meta box on every post edit screen for themes that declare `WPVibe: yes` in their `style.css` header — surfaces registered fields for the current post type plus a "Connect Claude / ChatGPT" CTA when no MCP client is paired to the site.
* Feature: Frontend hover-to-edit affordance — registered fields render with a dashed outline + edit pin during draft preview, click to jump to the wp-admin edit screen for that field.
* Feature: Hybrid classic starter theme — Tailwind v4 (browser CDN at draft time, compiled `dist/styles.css` at publish) + Gutenberg color/typography integration driven by the same `theme.css` `@theme` tokens. Bundles Alpine.js v3.15.12 for interactivity (modals, dropdowns, tabs, accordions, sliders). No `theme.json` — single source of truth.
* Feature: Cookie-based draft preview that survives wp-admin navigation. Admins with `edit_themes` see the draft theme across the whole admin so the field API works in wp-admin without needing the preview-token query string on every URL.
* Feature: Elementor integration with four new REST endpoints — `GET /wpvibe/v1/elementor/widgets` (list installed widgets + structural elements), `GET /wpvibe/v1/elementor/schema?slug=` (control schema discovery for a widget or element), `POST /wpvibe/v1/elementor/save-page` (atomic create or update of an Elementor page), `POST /wpvibe/v1/elementor/save-template` (Elementor Pro theme builder templates with display conditions). All routes return 404 with `elementor_inactive` when Elementor isn't installed.
* Feature: Per-request REST timing — every WPVibe REST response now carries an `X-WPVibe-PHP-Time-Ms` header so MCP clients can break down "why is this slow?" by Worker overhead, network round-trip, and time spent in WordPress PHP.
* Feature: Human-in-the-loop approval for destructive operations. AI-initiated db query mutations (DELETE/UPDATE/DROP/etc.), user deletes, plugin uninstalls, and `--force` trash bypasses now pause and surface an approval URL the user must open and confirm in their browser before WPVibe executes them.
* Feature: New WP-CLI-style commands so the AI can clean up after itself — `option add`, `option delete`, `transient delete` (with `--all` / `--expired` modes), and `transient list`. Non-blocked option/transient deletes auto-execute; protected core options (siteurl, active_plugins, auth_*, etc.) remain hard-blocked.
* Feature: Approval Log admin tab (WP admin → WPVibe → Approval Log) — append-only audit of every destructive operation WPVibe has executed on this site, including the dry-run preview the user saw before approving and the post-execution result summary.
* Feature: New `/wpvibe/v1/cli/run-approved`, `/wpvibe/v1/audit-log`, and `/wpvibe/v1/registered-meta` REST endpoints.
* Hardening: Destructive operations classified by command/SQL keyword rather than a default-deny allowlist. The narrow list (mutating SQL, user delete, plugin uninstall, `post delete --force`) returns `approval_required` with a row-count preview when the operation hits the database; all other commands continue to auto-execute behind the existing per-command capability checks.
* Fix: Custom post types now auto-receive the `'custom-fields'` post type support when fields are registered via `wpvibe_field_register()` — was silently dropping `meta` on REST writes for CPTs that lacked it.
* Fix: First write to a new subdirectory of the draft theme (e.g. `dist/styles.css`) no longer fails with "Resolved path is outside the draft theme" — path-safety check walks up to the nearest existing ancestor for the realpath validation.
* Fix: `publish_draft_theme` now tolerates a missing live theme directory (interrupted prior publish, manual delete) and creates the live from the draft instead of fatal-ing inside `RecursiveDirectoryIterator`.

= 1.3.0 =
* Feature: New unauthenticated `/wpvibe/v1/ping` endpoint returns plugin and WordPress version so the WPVibe MCP server can detect plugin presence before generating an OAuth magic link. Cuts the "magic link works but plugin is missing" failure mode that strands users mid-onboarding.

= 1.2.3 =
* Fix: Draft theme name no longer accumulates "(WPVibe Draft)" on every publish cycle — the suffix is now stripped on both create and publish, and the theme header cache is invalidated after restore. Thanks to J. Hoon Yu for the report.

= 1.2.2 =
* Security: SSRF hardening on /upload-media — validate every resolved A and AAAA record against private, loopback, link-local, and reserved ranges; re-validate redirect hops
* Security: Server-side user scoping on /last-change so a lower-privilege user can't read change summaries from an admin session
* Security: Require edit_theme_options or edit_posts in addition to the x_wpvibe header before bumping the admin "Connected" indicator
* Security: 24-hour TTL on the draft theme preview token so a leaked URL can't be used indefinitely
* Security: Remove SVG from the file-write allowlist (SVG can embed script and isn't needed for classic-theme scaffolding)
* Fix: Resolve an undefined variable when building the "View Trash" admin URL in the change tracker
* Maintenance: Uninstall now clears wpvibe_last_active, wpvibe_preview_token_issued, the activation-redirect transient, and any leftover *-wpvibe-draft / *-wpvibe-backup theme directories on disk
* Thanks to Rob Weaver for the responsible disclosure

= 1.2.1 =
* Compliance: Migrate inline styles and scripts to wp_enqueue_style / wp_enqueue_script
* Compliance: Replace direct PHP file I/O with the WP_Filesystem API across theme and file operations
* Compliance: Replace exec()-based PHP syntax validation with in-process tokenizer
* Feature: Unsplash stock photo search with third-party service disclosure
* Fix: Allow SQL comparison operators in db query and honor the --limit flag; add {prefix} placeholder
* Fix: Detect an active WPVibe connection via last-active timestamp instead of the auth token
* Fix: Custom CLI command sanitizer that preserves angle brackets used by SQL queries

= 1.1.0 =
* Expanded WP-CLI dispatcher with 16 new commands (34 total)
* Security: Block sensitive options (auth keys, salts) from being read via option get
* Security: Whitelist post get return fields (excludes post_password)
* New read commands: plugin search, option list, taxonomy list, term list, post meta get, media list, comment list, comment count, sidebar list
* New write commands: post create, post update, post delete, post meta update, post meta delete
* Plugin install and update with two-phase confirmation flow
* Content truncation for large post_content and post_content_filtered fields
* Flag normalization: hyphenated flags (--per-page) auto-convert to underscored (--per_page)

= 1.0.0 =
* Initial release
* WordPress site connection with one-click authorization
* Full WordPress REST API access for AI content management
* WordPress Abilities API support (WP 6.9+)
* WordPress theme file browsing (list, search, outline)
* WordPress theme editing via draft-preview-publish workflow
* Classic WordPress theme builder
* WordPress WP-CLI native dispatch
* WordPress media uploads from URL
* Unsplash stock photo search
* Smart live reload with context-aware navigation
* Progressive skills system for guided AI WordPress workflows
