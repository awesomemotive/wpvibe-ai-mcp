=== WPVibe - WordPress MCP Server. Connect Claude, ChatGPT & Any AI Agent via MCP ===
Contributors: seedprod, smub
Tags: mcp, claude, chatgpt, ai-assistant, mcp-server
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.15.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure WordPress MCP server. Connect Claude, ChatGPT, Cursor & any AI agent via MCP to manage content, edit themes & automate your site.

== Description ==

Your WordPress site just became MCP-ready. [WPVibe](https://wpvibe.ai/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin) is the Model Context Protocol server for WordPress, connecting your self-hosted site to any AI assistant or AI agent that speaks MCP: Claude, ChatGPT, Cursor, Windsurf, OpenCode, and more. No copy-pasting between tabs. No switching between your AI chat and wp-admin. Tell your AI what you want, and it happens on your live WordPress site.

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

= Connect Cursor, Windsurf, and Every MCP-Compatible AI Agent =

WPVibe is not locked to a single AI vendor. Cursor, Windsurf, OpenCode, Claude Code, ChatGPT, Claude, and any other AI agent that supports the Model Context Protocol can connect through the same MCP server URL. One WordPress MCP server, every AI assistant, no integration rewrite when you switch tools.

For developers, this means Cursor can edit your WordPress theme files with context-aware suggestions, Claude Code can run WordPress tasks as part of an agentic workflow, and Windsurf can scaffold new WordPress templates. For content creators and agencies, this means whichever AI writes best for your brand can publish directly to your WordPress site through the WPVibe MCP bridge.

= AI-Powered WordPress Content Management via MCP =

Managing WordPress content through MCP has never been easier. Create blog posts, update pages, upload media, manage categories and tags, all through natural conversation with your AI assistant. Tell Claude to write a draft post about your latest product launch, ask ChatGPT to update your about page, or have Cursor reorganize your blog categories. Your AI assistant handles the WordPress REST API calls behind the MCP protocol, so you never have to touch wp-admin.

WPVibe works with every AI client that supports the Model Context Protocol, giving you the freedom to use Claude Desktop, ChatGPT, Cursor, Windsurf, OpenCode, or any future MCP-compatible AI tool for WordPress management.

= WordPress Abilities API Support for MCP =

WordPress 6.9 introduced the Abilities API, a powerful way for plugins to declare self-describing operations that AI assistants can discover and execute. WPVibe fully supports this WordPress MCP integration. Your AI can discover what abilities your installed plugins expose, inspect their input schemas, and run them directly through natural conversation.

This means AI-powered WordPress plugin management works automatically over MCP. If a plugin registers abilities (WPForms, SeedProd, and others are adopting this standard), your AI assistant can interact with it without any custom integration. The WordPress Abilities API and WPVibe together make every compatible plugin MCP-ready.

= WooCommerce, Elementor, Bricks, and Your Other Plugins =

WPVibe works with the plugins already running your site. For WooCommerce, your AI can review the store, manage products, and bulk-edit prices, stock, and descriptions through conversation, so updating fifty product pages no longer means fifty trips through wp-admin.

Page builders get dedicated support. Elementor, Bricks, Breakdance, Beaver Builder, and Divi pages are written through each builder's own save pipeline, so the result opens in the builder like a hand-built page. Built-in skills cover Gutenberg, Kadence, GeneratePress (including GP Premium Elements), and SeedProd. Other plugins work through their own REST APIs or the WordPress Abilities API, and custom fields (including ACF fields, which are post meta under the hood) are read and written correctly, including on custom post types.

= Safely Edit WordPress Theme Files =

WPVibe lets your MCP client browse and edit your WordPress theme files safely. Your AI can list files, search file contents, analyze code structure, and make edits through a draft theme workflow. The draft clones the active WordPress theme into a sandbox, makes changes there, and exposes a preview URL so you can see the results before going live. Your live WordPress site is never touched until you explicitly approve and publish.

Every file operation runs through WordPress capability checks, a path sandbox scoped to the draft theme, and PHP syntax validation before save. You keep the safety of wp-admin's file editing guardrails while giving your AI a real place to work.

= WordPress WP-CLI Commands over MCP =

Run WordPress administration commands through your MCP client. Activate plugins, switch themes, update options, flush caches, query the database, run serialized-data-aware search-replace with a dry run, and more, all via native PHP dispatch with a security-first command allowlist. Everything is emulated through PHP, so it works on shared hosting with no shell and no SSH. Your AI gets a productive WordPress admin surface without the risks of raw command execution.

= Approvals You Can See, and an Audit Log =

WPVibe does not open your site to the world. There is no public endpoint sitting on your site for bots to hit; access runs through WordPress's own encrypted Application Passwords, revocable in one click. And when your AI asks for something destructive (deleting a user, mutating the database, uninstalling a plugin), WPVibe pauses and shows an approval panel right in the chat: the exact operation, a dry-run preview of what will change, and Approve or Decline buttons. Nothing irreversible happens without you.

Every sensitive action is also recorded in an append-only Approval Log in your WordPress admin, including the preview you saw and the result, so you always have a paper trail of what your AI did. Posts default to draft, deletes go to trash, and theme publishing keeps a backup of your previous files so you can roll back.

= Smart MCP Notifications on Your WordPress Admin =

Every change your AI makes over MCP triggers a smart notification in your browser with a direct link to view or edit the updated content. The notification knows whether you are in the WordPress admin or on the frontend and adapts the link, so your workflow is never disrupted while your AI works in the background.

= One-Click WordPress MCP Authorization =

Connecting your WordPress site to an MCP server should take seconds. No application passwords typed by hand, no API keys copied between tabs: provide your site URL, click the authorization link that appears in your WordPress admin, and approve the connection. Credentials are encrypted with AES-256-GCM and stored securely on Cloudflare-hosted WPVibe servers. One click, done.

= WordPress MCP Server for Every Use Case =

Whether you are a blogger managing content, a developer building WordPress themes, or an agency managing multiple client sites, WPVibe makes AI-powered WordPress management and automation accessible through whichever MCP client you already use.

<strong>Bloggers and Content Creators</strong> write and publish posts, manage media, organize categories and tags, and update WordPress site settings through conversation with Claude, ChatGPT, or any MCP assistant.

<strong>WordPress Developers and Designers</strong> browse theme files, analyze code structure, and edit WordPress themes using a safe draft-preview-publish workflow. Build classic WordPress themes from scratch with AI-powered design directly from Cursor, Claude Code, or your favorite MCP client.

<strong>Agencies and WordPress Site Managers</strong> connect client WordPress sites and manage content at scale. Use the WordPress Abilities API over MCP to interact with installed plugins. Automate routine WordPress tasks with whichever AI agent fits the job. White label mode (free on every plan) hides WPVibe from a client site's WordPress dashboard entirely, so your clients see a clean wp-admin while you manage the site through your AI.

= Full WPVibe MCP Server Feature List =

* WordPress MCP server connection with one-click authorization and AES-256 encrypted credential storage
* AI content management - create, update, and manage WordPress posts, pages, media, categories, and tags through AI conversation
* WooCommerce management - review the store and bulk-edit products, prices, stock, and descriptions through conversation
* Page builder integrations - Elementor, Bricks, Breakdance, Beaver Builder, and Divi pages via each builder's own save pipeline
* Human-in-the-loop approvals - destructive operations pause for an in-chat approval panel with a dry-run preview
* Approval Log - append-only audit trail in wp-admin of every destructive operation, its preview, and its result
* Surgical content edits - targeted find-and-replace in posts, meta, and options without rewriting the whole value
* Full WordPress REST API access exposed as MCP tools, including custom post types and plugin routes
* WordPress Abilities API support - discover and execute plugin abilities on WordPress 6.9+ sites automatically
* Connect Claude Desktop, Claude on the web, or Claude Code to WordPress via MCP
* Connect ChatGPT's web app and desktop app to WordPress via MCP
* Connect Cursor, Windsurf, OpenCode, and any other MCP-compatible AI agent
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

WPVibe is built by the team behind [SeedProd](https://www.seedprod.com/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin), the most popular WordPress landing page and theme builder plugin, trusted by over 1 million WordPress websites. We have been building WordPress tools since 2012.

= Better Than Custom AI WordPress Integrations =

If you have connected AI to your WordPress site before, you have probably dealt with custom API wrappers, one-off WordPress AI integrations, hand-rolled Custom GPTs, or copying content back and forth between Claude and your browser. WPVibe replaces all of that with a proper MCP server for WordPress, built on the Model Context Protocol, an open standard supported by Claude, ChatGPT, Cursor, Windsurf, and a growing list of AI tools. Connect your WordPress site once and use it with any MCP client. No vendor lock-in, no custom code to maintain.

Unlike bundled-AI plugins (AI Engine, GetGenie, Bertha, AI Power, WPCode AI, and similar) that ship one model and one prompt style, WPVibe lets you bring your own AI and use whichever model reasons best for each task: Claude for long-form writing, ChatGPT for research, Cursor for theme editing, all through the same WordPress MCP server. Your data stays on your WordPress server, with no third-party servers processing your WordPress content.

= Branding Guidelines =

This plugin is a product of SeedProd LLC. The product name is **WPVibe**, one word, everywhere: the plugin, [wpvibe.ai](https://wpvibe.ai/), the documentation, and the in-product UI. When writing about it, please use WPVibe rather than WPvibe, Wp Vibe, WP Vibe, or VibeAI.

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

= My host strips the Authorization header. Does WPVibe need an .htaccess change? =

No, not from version 1.13.0 onward. Some servers, commonly Apache running PHP as CGI or FastCGI, do not pass the Authorization header to PHP unless CGIPassAuth is enabled. WordPress then treats every authenticated REST request as logged out. WPVibe sends the same credential under an X-WPVibe-Authorization header, which those servers do pass through, and the plugin restores it before WordPress authenticates. This mirrors what WordPress core already does in wp_populate_basic_auth_from_authorization_header(): it only runs when no credential arrived by any normal route, it validates the header format, and WordPress still checks the credential exactly as it always has, so nothing about who may do what changes.

If you would rather it did not run, add `define( 'WPVIBE_DISABLE_AUTH_FALLBACK', true );` to wp-config.php, or return false from the `wpvibe_allow_auth_fallback` filter.

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

= 1.15.3 =
* Fix: services that authenticate with WooCommerce REST API keys (TrackShip, Metorik, and similar) no longer receive a 401 Unknown username error on sites where another plugin resolves the user early in the request.

= 1.15.2 =
* Security: hardened the protections on WPVibe's raw database access so a disguised query cannot slip past them. WPVibe already refuses to change your site's core web addresses, touch the users table, read your site's secret keys, or read and write files on the server, even on a command you approve. This release closes several ways a specially crafted query could hide those actions from the safety checks, such as using SQL comments or unusual spellings of a protected setting's name.
* Fix: read-only database queries that use REPLACE() to count or inspect content (a common reporting pattern) are no longer refused by mistake.

= 1.15.1 =
* Hardening: your page builder's site-wide settings and global presets (such as Divi's design presets) now require your approval before any write, on every path including option writes and raw SQL, and the approval screen shows exactly which settings change. This closes a route where a bulk edit could overwrite a builder's global styling and break its editor across the whole site.
* Hardening: a content edit that would corrupt a page's block markup (leaving a block's settings unreadable, so the block vanishes from the editor) is now refused before it saves.
* Hardening: WPVibe no longer creates a draft copy of a page builder parent theme (such as Divi or Avada), which would leave the builder unable to load. It points you to the correct path instead.
* Fix: when a builder value cannot be edited directly, the guidance now names a command that actually works instead of one that dead-ends.

= 1.15.0 =
* Feature: your AI assistant can now manage navigation menus with WP-CLI style commands (menu create; menu item add-custom, add-post, add-term, update, and delete; menu location assign), no raw database access needed.
* Feature: category and tag management commands (term create, term update, term delete). Deleting a term asks for your approval first and shows how many posts and child terms are affected.
* Feature: theme mod set for changing theme customizer values, and rewrite structure for permalink settings.
* Feature: user account commands (user create, user update, user set-role, user add-role, user remove-role) plus a full user meta family (get, list, add, update, delete).
* Security: user changes that grant or remove administrator level access, or change a password or email address, always require your approval first. WPVibe also refuses to demote your connected account or the last user holding the administrator role.
* Security: capability and session storage keys can never be written through user meta commands, closing off role changes that would bypass the permission system. Session hashes, application passwords, and plugin stored secrets (such as two factor seeds and API keys) are withheld from user command output.
* Hardening: passwords set through user commands are hidden from approval screens, logs, and analytics.

= 1.14.3 =
* Fix: page builder compatibility. The live-refresh script no longer loads inside Divi, Elementor, Beaver Builder, Bricks, or Breakdance editing sessions, where it could interrupt the builder while your AI assistant was making changes (fixes the "Edit with Divi" endless spinner).
* Hardening: approved plugin replacements re-verify the installed version and active state at execution, and refuse to run if the site changed after the approval was granted (for example an auto-update during the approval window).
* Fix: clearer guidance when eval and eval-file commands are blocked. The denial now points to the code snippet workflow instead of a dead-end help lookup.
* Hardening: the approval gate and the install handler now derive "is this replacing an existing plugin" from one shared check, so they can never disagree.

= 1.14.2 =
* Feature: plugin rollback. `plugin install <slug> --version=<version> --force` now replaces an installed plugin with the exact version you name, so your AI can walk a broken update back to the last working release. Replacing an existing install pauses for browser approval and shows the version change before anything runs; if the plugin was active it stays active afterward.
* Fix: `plugin install` on an already-installed plugin now explains the two ways forward (update, or force-replace with a specific version) instead of failing with a raw folder error, and unsupported install flags are refused with the supported list instead of being silently ignored.
* Fix: a version that does not exist on WordPress.org is refused with a clear error instead of silently installing the latest release, and replacing a single-file plugin now switches the active copy cleanly instead of leaving the old file active.
* Hardening: the force-replace approval also covers directories WordPress can no longer read as plugins (broken installs), and WPVibe refuses to replace its own files over its own connection.

= 1.14.1 =
* Improvement: approved operations now leave an execution receipt on your site. If the connection drops right after you click Approve, WPVibe can check the receipt and tell your AI exactly what happened (it ran, it was rejected, or it never arrived) instead of reporting the outcome as unknown.
* Improvement: an approved operation can never run twice. If the same approved request is ever re-sent, the site returns the recorded result of the first run instead of executing again.

= 1.14.0 =
* Feature: Bricks support. Your AI can now build and edit Bricks pages through Bricks' own save pipeline, with the theme's element security checks applied and page CSS regenerated on every save (external file mode included). Layouts open in the Bricks editor exactly like hand-built pages.
* Feature: Breakdance support. Your AI can now build and edit Breakdance pages, including the blank canvas template for landing pages. Saves go through Breakdance's own data format and refresh its CSS cache, so pages render correctly on the first load and open cleanly in the Breakdance editor. Writing a layout requires Breakdance builder access, the same permission Breakdance uses for its own editor, so connect as an administrator or a role you have granted Breakdance access.
* Fix: text values written to post fields by the AI now store exactly what was sent. Words like true or false used to be converted before saving, which could silently break theme and plugin settings that expect the literal text (found with GeneratePress page layout options). Structured JSON values are unaffected.
* Fix: sites that lock the theme and plugin file editors (a common managed-hosting setting) are now reported as locked instead of "a security plugin removed your permissions", so your AI explains the real reason and what to do about it.
* Security: saving an Elementor or Beaver Builder page as private or scheduled now requires the same publish permission WordPress requires for publishing. Previously a user who could edit but not publish could reach those states through the builder endpoints. Publishing itself was always checked.

= 1.13.5 =
* Improvement: editing page-builder content now works through the safe content-edit path. Elementor stores a page's text inside a protected field, so surgical text edits used to fall back to direct database writes. Those edits now go through the normal content-edit tool, which also refreshes Elementor's cached styles so the change shows on the front end right away.
* Fix: a content edit that would have broken a builder layout's stored data is now refused before saving, with the original left untouched, instead of writing a corrupted value.
* Fix: content search and edit now match text the way it appears on the page when the database stores HTML entities. A search for "R&D" finds stored "R&amp;D", and ordinary spaces match non-breaking spaces, so an AI reading rendered HTML can edit the real stored value without a no-match miss.
* Security: direct database writes to protected site settings (site address, active plugins, user roles and capabilities, the users table) are now refused even after approval. These already could not be changed through the normal commands, and raw SQL can no longer be used to get around that. Everyday content edits are unaffected.

= 1.13.4 =
* Fix: draft theme preview no longer breaks on sites running a child theme. The preview pointed WordPress at the draft for both the child theme and its parent, so the parent theme's code never loaded and the page stopped rendering partway through. The preview now keeps the parent theme in place and layers your draft on top of it, the way WordPress expects a child theme to work. The live site was never affected. Thanks to Ryan De La Uz for the detailed report.
* Improvement: the WP-CLI command layer is reorganized under the hood so new commands can ship in smaller, safer pieces. Which commands you can run and how they behave are unchanged.

= 1.13.3 =
* Fix: updating a single plugin no longer leaves it deactivated. WordPress silently deactivates a plugin before replacing its files, and the update command did not turn it back on, so a plugin that was active before an update could end up switched off without any error. Updates now use the same method as the WordPress dashboard and the WP-CLI tool, which keeps the plugin active the whole time.
* Improvement: the update result now states whether the plugin is active or inactive after the update, verified against the site rather than assumed, so your AI assistant reports the real state instead of guessing.
* Fix: an update that failed to replace the plugin files used to report success. It now reports the failure and says the installed version is unchanged.

= 1.13.2 =
* Fix: cache purge --url=… was stripped before dispatch, so a surgical purge silently became a full cache flush. The flag now reaches the purge dispatcher for cache purge and its engine aliases; bare or empty --url errors instead of over-purging. When every detected page cache refuses a URL, the command now fails and names each engine's reason instead of claiming there was nothing to purge.
* Fix: content search now finds text regardless of quote style. WordPress displays straight quotes as curly ones, and a search for the plain version used to come back empty even though the editing route would have matched it. That mismatch sent AI assistants hunting in the wrong place or rewriting whole posts when a one-line edit was intended. Search and edit now follow the same matching rules, so anything search returns is guaranteed to work as an edit.
* Fix: search results now say when a long line was shortened. Results were silently trimmed at 400 characters, so on long paragraphs and page builder layouts an AI could be handed a shortened snippet with no way to know text was missing. Results are now centered on the matched text and clearly flagged whenever they or their surrounding context were trimmed.
* Improvement: a failed edit now shows what is actually stored nearby. When an edit misses because the text is not quite what is stored, the error includes the closest matching passages from the real content, so the retry is informed instead of a guess. When an edit matches more than one place, the error now lists those places instead of only counting them.
* Improvement: edits now report what was actually saved. WordPress filters and security plugins can quietly alter content as it is saved. After every edit the plugin re-reads the stored value and says so when the site changed what was written, so an AI never builds its next edit on a version of the content that no longer exists.
* Improvement: theme editing denials now name their real cause. On multisite networks only network super admins can edit theme files, and some security plugins remove that ability even from administrators. In both cases the old error advised reconnecting with a more privileged account, which cannot work; the error now says which situation applies and what actually helps.

= 1.13.1 =
* Security: WordPress settings that WPVibe protects can no longer be changed through the content editing route. WPVibe keeps a list of settings that are never writable, including the ones controlling whether anyone can register and what role new accounts get, plus your site's security keys and salts. The WP-CLI commands honoured that list, but the content editing route wrote settings directly and did not, so an AI acting on your site could change them without the usual approval step. That route now enforces the same list, and the security keys can no longer be read back through it either. Ordinary settings are unaffected.
* Fix: commands no longer report success for work they did not do. Some WP-CLI options were accepted and then quietly ignored, so a request could come back successful while part of what you asked for never happened, which is the one kind of failure your AI cannot notice. Those options now return a clear error naming what is unsupported. If a command you rely on has been dropping an option, you will see an error where you previously saw a false success. The error is the accurate answer, and the earlier success was not.
* Fix: search and replace can no longer damage stored passwords. The user_pass column is now always excluded, even if it is explicitly requested, so a replacement that happens to match text inside a password hash cannot corrupt it and lock someone out.
* Fix: listing a post's custom fields now matches WP-CLI. A field with more than one stored value was folded into a single entry using field names WP-CLI does not use, so your AI could not tell how many values existed or reliably remove just one of them. Every stored row is now listed separately, using the standard post_id, meta_key and meta_value names. Nothing about how values are stored has changed.
* Improvement: two more edits that cannot be undone now ask first. Removing a key from inside a setting, and removing every stored value of a custom field when the protected-field guard is overridden, now show a preview and wait for your approval. WordPress keeps no trash for settings and no revision history for custom fields, so page builder layouts and template settings cannot be recovered afterwards. Removing one specific value still runs without a prompt, because that only affects the row you named.
* Improvement: long lists now say when they were cut short. Listing options, users, or posts stops at a row limit, and the reply had no way to signal that more existed, so an AI could work through what looked like a complete set and quietly miss the rest. Those listings now warn when the limit was reached and say not to treat the result as complete.
* Improvement: reporting options behave as WP-CLI does. --format=ids and --porcelain now return the bare values they are meant to, which makes them usable for chaining one command into the next.

= 1.13.0 =
* Fix: WPVibe now works on hosts that strip the login header. On some servers, commonly Apache running PHP as CGI or FastCGI and some LiteSpeed setups, the web server removes the Authorization header before WordPress can read it. Every WPVibe request then arrived as a logged out visitor and failed with a permission error, even though the site was connected and the password was valid. WPVibe now sends the same credentials a second way that these servers pass through, and the plugin hands them back to WordPress before it checks who you are. Who is allowed to do what does not change, and sites that were already working are unaffected. To turn this off, define WPVIBE_DISABLE_AUTH_FALLBACK as true.
* Improvement: permission errors now name the real cause. A request WordPress could not authenticate used to report a missing capability, which sent people off to reconnect with a different account when the actual problem was the server dropping the header, or Application Passwords being switched off. WPVibe now reports which of those it was, so the fix matches the problem.

= 1.12.0 =
* Fix: safer settings edits. Option values are now treated as plain text unless your AI explicitly asks for JSON (matching the real WP-CLI), and WPVibe refuses any write that would silently change a setting's stored type, which could previously corrupt cache plugin settings or take a site offline. Reading an option now tells your AI when a value is one string that merely looks like a list.
* Fix: theme and code edits no longer fail on hosts whose security firewall mistakes legitimate code for an attack. Some hosting firewalls inspect the content of save requests and block anything that looks like PHP or SQL, which made edits to files like functions.php fail intermittently with a 403 error. When that happens, WPVibe now automatically resends the same edit in an encoded form the firewall does not misread, and the plugin decodes it before any of its usual security checks run. Nothing changes about what gets saved or who is allowed to save it.

= 1.11.0 =
* New: White label mode for agencies. One click on the WPVibe admin page (or one ask to your AI) hides WPVibe everywhere in the WordPress dashboard: the admin menu, dashboard widget, Plugins list entry, and editor sidebar. The site stays fully manageable through your AI. WordPress auto-updates keep the plugin current while hidden, and if the site goes 30 days without WPVibe activity the plugin reappears on its own, so it can never be lost.
* Fix: Content edits now match straight and curly quotes interchangeably. WordPress converts quotes to the curly kind when it saves, which could make an edit fail with "no match" on text your AI had just written.
* Fix: The WPVibe theme header is now registered on every request, so site_info correctly detects AI-built themes over REST. Props to Nick Kimuli for finding it and opening the fix on our GitHub mirror.

= 1.10.0 =
* New: Beaver Builder support. Your AI can now build and edit real Beaver Builder pages, landing pages, and layouts. The result is a native Beaver Builder layout: open it in the builder and every row, column, and module is there and individually editable, exactly as if you built it by hand. Works with both the free Beaver Builder (Lite) and the paid plugin.
* New: Beaver Themer support. Build site-wide headers and footers (navigation menus included), plus archive, 404, and single-post layouts, each wired to the right location rules. Themer headers and footers need a Themer-compatible theme (the Beaver Builder Theme, Astra, GeneratePress, Kadence, and similar); WPVibe tells you up front when the active theme cannot render one instead of leaving a layout that shows nowhere.
* New: "post meta add" appends a row to multi-value post meta, and "post meta delete" accepts an optional value to remove only the matching row. Previously update replaced every row of a key and delete wiped them all, which made multi-value metas effectively untouchable. Divi's Theme Builder links its templates through exactly this kind of meta, so your AI can now add a second Theme Builder template without destroying the first.

= 1.9.1 =
* Fix: uninstalling a plugin, updating a plugin, or deleting a theme through WPVibe no longer fails with a 500 error. These commands ran WordPress's own delete and upgrade functions without first loading wp-admin's filesystem bootstrap, which wp-admin pre-loads but WPVibe's REST context does not. All file-modifying commands now load it up front.
* New: update several plugins at once. `plugin update` now accepts multiple slugs or `--all` (with `--exclude` and `--dry-run`), previews the full list before you confirm, and reports a per-plugin result. WPVibe itself is skipped automatically, since it cannot replace its own files over its own connection.
* Fix: Google Site Kit (and other plugins that read the logged-in user early) now see the correct user on WPVibe requests. WordPress resolves Application Password logins later than plugins that initialize on init, so Site Kit read an empty Google authorization and rejected every Analytics, Search Console, and PageSpeed request with missing_required_scopes. WPVibe now tells WordPress that REST requests are API requests from the start, so the login resolves before those plugins load.

= 1.9.0 =
* New: WPVibe dashboard widget. Your wp-admin Dashboard now shows whether the site is connected, the last few changes your AI made, and a short list of cookbook recipes matched to the plugins you actually run, each with a copy-ready prompt. Not connected yet? The widget gives you the one-line prompt that connects your site.
* New: recipe suggestions are filtered on your own site against your installed plugins. The widget fetches one small public recipe list from wpvibe.ai (nothing about your site is sent, same pattern as the WordPress Events and News widget) and picks locally.
* Improvement: recent AI-made changes are now kept in a small activity log (last 10 entries) so the dashboard can show them; the full record remains in the Approval Log.

= 1.8.1 =
* Fix: SeedProd landing pages now render on your site automatically after WPVibe builds or updates them. The automatic render step added in 1.8.0 only recognized SeedProd theme templates and coming-soon or maintenance pages, so regular landing pages still asked you to open the SeedProd builder and click Save yourself.

= 1.8.0 =
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

= Older versions =
WP.org caps the changelog at 5,000 words. For the full release history back to 1.0.0, see https://wpvibe.ai/changelog/
