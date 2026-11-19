=== WPVibe – MCP Server for WordPress, works with Claude, ChatGPT & other AI MCP agents ===
Contributors: seedprod, smub
Tags: mcp, claude, chatgpt, ai-assistant, mcp-server
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.24.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

MCP server for WordPress. Connect Claude, ChatGPT, Cursor or any MCP client to manage content, themes and settings, with approvals.

== Description ==

[WPVibe](https://wpvibe.ai/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin) is an MCP server that connects your self-hosted WordPress site to the AI assistant you already use. Ask in plain words, and your AI does the work on your site: drafts and edits posts, uploads images, updates plugins, changes settings, and edits your theme in a draft copy first.

WPVibe is made by SeedProd. It is not affiliated with or endorsed by the WordPress Foundation, Anthropic (Claude), OpenAI (ChatGPT), or the makers of the other third-party products named on this page (WPForms, All in One SEO and WPCode are SeedProd's sister plugins).

https://www.youtube.com/watch?v=AsasOvrSWgI

= How it works =

WPVibe is an MCP server for WordPress. MCP (the Model Context Protocol) is an open standard that lets AI assistants use tools on other services.

1. Install this free plugin. It adds the REST API routes WPVibe's MCP tools use.
2. Connect your site once: give WPVibe your site's address, open the link it gives you, and click Approve on your own site's WordPress authorization screen. WordPress creates an Application Password for your user. You never type or copy one.
3. Add WPVibe's MCP server address to your AI client.

From then on, your AI calls WPVibe's MCP tools, and the WPVibe service sends each call to your site's REST API, signed with that Application Password. Your AI acts as the WordPress user who connected, with that user's permissions. The plugin needs the WPVibe service to work; the service has a free plan. What passes through the service, and what it keeps, is under Third-Party Services below.

= Works with your AI =

Any MCP client works, including Claude (desktop, web and Claude Code), ChatGPT (web and desktop), Cursor, Windsurf and OpenCode. Switching MCP clients changes nothing on your site. WPVibe is listed in the ChatGPT Apps directory and in Claude's connector directory.

= What your AI can do =

Your AI works through WPVibe's MCP tools:

* **Content:** posts, pages and custom post types, media, categories and tags, menus, comments and users. Small edits replace only the exact text; post edits keep a revision where revisions are on.
* **Custom fields:** read and write post meta, including ACF fields.
* **WooCommerce:** review the store, and create or bulk-edit products, prices, stock and descriptions.
* **Page builders:** Elementor (pages and Elementor Pro theme builder templates), Bricks, Breakdance and Beaver Builder pages are saved through each builder's own save code, so they open in the builder like a hand-built page. SeedProd pages are saved by opening the SeedProd builder (see Third-Party Services). Built-in guides tell your AI how Gutenberg, Divi, Kadence, GeneratePress (including GP Premium Elements), Astra and Blocksy store their content.
* **Other plugins:** through their own REST API routes, or the WordPress Abilities API (WordPress 6.9 and later): your AI can list the abilities your plugins register, read their inputs and run them.
* **Theme files:** browse, search and edit files in a draft copy of your theme, with a preview link. The live theme changes only when you publish, and publishing keeps a backup you can restore. File writes go through WordPress capability checks, a path sandbox, a file type allowlist and a PHP syntax check. Your AI can also build a classic theme from a starter.
* **WP-CLI style commands:** one MCP tool covers plugins, themes, users, options, cache, cron, database reads, and search-replace with a dry run. They run in PHP from a fixed allowlist, so shared hosting works: no shell or SSH.
* **Updates across sites:** update plugins, themes and WordPress core on every connected site from one panel, with a check before and a version check after.
* **Images:** upload from your computer through the chat, add one from a URL, or search Unsplash.
* **White label:** hide WPVibe in wp-admin for every user (menu, screens, Dashboard widget, editor panel, Site Health entries, and its Plugins row except while an update is pending), for agencies managing a client's site. It also turns on WordPress auto-updates for WPVibe where your account may change plugin updates (network-wide on multisite). WPVibe reappears by itself after 30 days without activity.

= Approvals and the Approval Log =

Access uses a WordPress Application Password, which you can revoke from your user profile at any time.

Destructive operations (deleting a user, a database query that changes data, uninstalling a plugin, permanently deleting a post) pause for your approval and show the exact operation, a dry-run preview, and Approve and Decline buttons. An Administrator can turn approvals off for a site with the "Dangerously bypass approvals" setting in wp-admin; then they run without asking and are still logged.

Each destructive operation, approved or run with approvals off, is recorded in the Approval Log in wp-admin with its preview and result. The log keeps the most recent 1,000 entries.

New posts are drafts unless your AI is asked to publish them. Deletes go to the trash wherever WordPress keeps one. Where WordPress keeps none (media files by default, categories, tags and menu items, or everything on a site that turns its trash off), a delete is permanent, as it is in wp-admin.

WPVibe's settings commands (reading an option or transient, and searching or editing an option's text) and its database queries keep secrets out of their answers: WordPress's keys and salts, the AI provider API keys in Settings > Connectors, Akismet's API key, WPVibe's own keys, and settings fields whose names mark them as a password, secret, token or API key. These are refused or shown as a masked placeholder, and WPVibe's settings and meta commands refuse to write that placeholder back. Database queries also mask user password hashes, password reset keys, session tokens and application passwords, and reading WooCommerce API keys through them needs your approval.

= Live reload =

While your AI works, wp-admin shows a notice for each change with a link to it, and your signed-in front-end tab reloads to show it. Each user can turn this off on the WPVibe page or on their profile.

= Third-Party Services =

The plugin needs the WPVibe service, and some features call other services. Each one is listed here with what is sent and when. Connections to WPVibe's servers use HTTPS.

**The WPVibe service (mcp.wpvibe.ai, hosted on Cloudflare).** Your AI client connects to it over MCP, and it sends your AI's requests to your site. Whatever your AI reads from or writes to your site passes through it. It keeps your account and the sites you connect, each site's Application Password encrypted with AES-256-GCM, records of the operations you approve or are asked to approve (which can include the content they change), and logs of the requests your AI makes. When your AI takes a screenshot of a page or reads it as rendered, the service loads that page with Cloudflare's browser rendering. WPVibe does not sell your data. The Privacy Policy describes what is kept and what account data is shared for analytics and ad measurement.

**Diagnostics (your site, read by the WPVibe service).** The plugin keeps a short record of your site's most recent PHP fatal errors (up to five from the last 7 days: the error type, PHP's own message, the file and line, and the plugin or theme it belongs to), memory use and limit, your host's name, when WordPress last sent a recovery-mode email, and your WordPress, PHP, WPVibe and active plugin versions. The WPVibe service can read this record through the `/wpvibe/v1/diagnostics` route, signed with its own key rather than your Application Password, so support can see why a site stopped answering. Nothing is sent unless the service asks, and no page content, request data or stored values are in it.

**WPVibe's relay servers (on DigitalOcean).** Requests to your site may pass through relay servers WPVibe runs, so they arrive from fixed IP addresses your host's firewall can allow. Each request, with the Application Password that authenticates it, passes through in transit. The relays do not store requests, responses or credentials; they log each request's site hostname, status, timing and any error. The same servers do two jobs for the builder tools: to compile a theme's styles, they receive the theme files the styles are built from; to save a SeedProd page, they open the SeedProd builder on your site with a single-use login link the plugin issues for that one page, and click Save.

**Your AI provider.** Whatever your AI reads from or sends to your site also goes to the company that runs your AI, under its terms: for example Anthropic for Claude ([terms](https://www.anthropic.com/legal/consumer-terms), [privacy](https://www.anthropic.com/legal/privacy)) or OpenAI for ChatGPT ([terms](https://openai.com/policies/terms-of-use/), [privacy](https://openai.com/policies/privacy-policy/)).

**Connection check (mcp.wpvibe.ai/connection/preflight).** When an Administrator clicks "Check site connectivity" on the WPVibe page, the plugin sends your site's address and a one-time code to this address, and WPVibe's servers then request your site to see whether they can reach it. No passwords are sent.

**Approval failure report (mcp.wpvibe.ai).** On your site's authorization screen for a WPVibe connection, if the approval request is blocked or fails, or the check the screen runs before you click Approve finds a problem, the screen reports it to the WPVibe service with the connection attempt's ID: the HTTP status, whether the answer was a page or JSON, and the security product if it recognizes one. Never page content. Nothing is reported when approval works. To turn the report off, return an empty list from the `wpvibe_authorize_beacon_hosts` filter.

**Dashboard widget (wpvibe.ai/wp-json/wpvibe/v1/widget-feed).** The WPVibe widget on the Dashboard shows suggested tasks (recipes) from wpvibe.ai. When an Administrator opens the Dashboard, the plugin fetches them, at most once every 12 hours (hourly while the feed is unreachable). The request carries no site data: its user agent is "WPVibe/" and the plugin version. The widget does not load in white label mode.

**Unsplash.** When your AI searches for stock photos, the WPVibe service sends the search words to Unsplash; your site sends nothing. For each photo a search returns, the WPVibe service also sends Unsplash the download report its API terms require. When your AI adds one of those photos, your site downloads it from Unsplash's image server. [Terms](https://unsplash.com/terms), [privacy](https://unsplash.com/privacy).

**Google PageSpeed Insights.** When your AI audits a page's speed, accessibility or SEO, the WPVibe service sends that page's address to Google PageSpeed Insights, and Google loads the page to test it. [Terms](https://policies.google.com/terms), [privacy](https://policies.google.com/privacy).

**Images from a URL.** When your AI adds an image by its address, your site downloads it from that address, which can be any public website. The plugin refuses private and local network addresses.

**WordPress.org.** When your AI installs, updates or checks plugins, themes or WordPress itself, your site contacts WordPress.org (api.wordpress.org and downloads.wordpress.org) for plugin and theme information, downloads and checksums. Like WordPress's own update checks, these requests carry your site's address in their user agent. [Privacy](https://wordpress.org/about/privacy/).

**Links in wp-admin.** Some buttons open mcp.wpvibe.ai with your site's address in the link, so the page shows that site: "Connect your AI" and "Check connection" in the Dashboard widget, "Connect Claude / ChatGPT" in the post editor's WPVibe panel, and "Check authorized access" on the WPVibe page. Nothing is sent until you click. Links to wpvibe.ai carry campaign tags that name the wp-admin screen they came from.

* [WPVibe Privacy Policy](https://wpvibe.ai/privacy/)
* [WPVibe Terms of Service](https://wpvibe.ai/terms/)

To report a security issue, email security@wpvibe.ai.

= Third-Party Libraries =

WPVibe bundles three third-party libraries:

* **Alpine.js** v3.15.12, MIT License, [https://alpinejs.dev/](https://alpinejs.dev/), included at `starter-themes/classic/assets/js/alpine.min.js`. Used as the interactivity layer (modals, dropdowns, tabs, accordions, sliders) for AI-generated classic themes. Not loaded outside scaffolded themes.
* **Tailwind CSS browser build** (@tailwindcss/browser) v4.3.3 by Tailwind Labs, MIT License, [https://github.com/tailwindlabs/tailwindcss](https://github.com/tailwindlabs/tailwindcss), included at `assets/js/tailwindcss-browser.min.js`. Styles the draft theme preview while you build a Tailwind theme. Loaded only on draft previews of WPVibe Tailwind themes; published themes use compiled CSS instead.
* **svg-sanitize** (enshrined/svg-sanitize) v1.0.0 by Daryll Doyle, GPL-2.0-or-later, [https://github.com/darylldoyle/svg-sanitizer](https://github.com/darylldoyle/svg-sanitizer), included at `third-party/svg-sanitize/` with its PHP namespace prefixed so it cannot clash with other plugins that bundle it, such as Safe SVG. Used to clean SVG files before WPVibe writes them to a draft theme or adds them to the Media Library.

= About WPVibe =

WPVibe is a product of SeedProd LLC, the team behind [SeedProd](https://www.seedprod.com/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin). The name is WPVibe, one word: please write WPVibe rather than WPvibe, WP Vibe or VibeAI.

* [WPVibe Documentation](https://wpvibe.ai/docs/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin)

== Installation ==

1. Upload the `vibe-ai` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Open the WPVibe menu in your WordPress admin and click Connect to see the setup steps for your AI client

For detailed setup instructions, visit [wpvibe.ai/docs](https://wpvibe.ai/docs/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin).

== Screenshots ==

1. Destructive operations pause for your approval right in the chat, with a dry-run preview and Approve or Decline buttons.
2. The WPVibe admin screen: install the plugin, copy the MCP server URL into your AI client, and your site is connected.
3. Upload images from your computer through a panel in the conversation, and your AI adds them to the WordPress media library.

== Frequently Asked Questions ==

= My host strips the Authorization header. Does WPVibe need an .htaccess change? =

No, not from version 1.13.0 onward. Some servers, commonly Apache running PHP as CGI or FastCGI, do not pass the Authorization header to PHP unless CGIPassAuth is enabled. WordPress then treats every authenticated REST request as logged out. WPVibe sends the same credential under an X-WPVibe-Authorization header, which those servers do pass through, and the plugin restores it before WordPress authenticates. This mirrors what WordPress core already does in wp_populate_basic_auth_from_authorization_header(): it only runs when no credential arrived by any normal route, it validates the header format, and WordPress still checks the credential exactly as it always has, so nothing about who may do what changes.

If you would rather it did not run, add `define( 'WPVIBE_DISABLE_AUTH_FALLBACK', true );` to wp-config.php, or return false from the `wpvibe_allow_auth_fallback` filter.

= Is WPVibe free? Do I need another AI subscription? =

The plugin is free, and the WPVibe service has a free plan that includes every tool with a daily allowance of WordPress actions. You bring the AI you already use, free plans included; WPVibe never charges for AI. Paid plans raise the daily allowance of WordPress actions, which is separate from your AI's own limits.

= Which AI assistants work with WPVibe? =

Any MCP client. See "Works with your AI" above.

= Does this plugin change my live site directly? =

Content changes (posts, pages, media) go live as soon as they are made, as they do in wp-admin; new posts are drafts unless your AI is asked to publish them. Theme file changes happen in a draft copy of your theme, and the live theme changes only when you publish it.

= What authentication does WPVibe use? =

WordPress Application Passwords (built into WordPress 5.6 and later), created when you click Approve on your site's authorization screen, so no password is typed into the chat. WordPress stores its copy hashed; the WPVibe service stores its copy encrypted.

= Is my WordPress data sent to third-party servers? =

Yes, as needed to do what you ask. Whatever your AI reads or changes passes through other services, and some of it is kept there:

* The WPVibe service, which sends each request to your site and keeps your account, your site's Application Password (encrypted), records of the operations you approve or are asked to approve (which can include the content they change), and logs of your AI's requests.
* WPVibe's relay servers, which pass requests and responses through without storing them. The same servers receive a theme's files to compile its styles, and open the SeedProd builder to save a SeedProd page.
* Your AI provider, which receives whatever your AI reads from or sends to your site, under its own terms.

WPVibe does not sell your data. Third-Party Services above lists the services that receive your site's content, with what is sent and when; the [Privacy Policy](https://wpvibe.ai/privacy/) lists the processors that receive account data, which includes your site's address.

= Can the AI break my WordPress site? =

WPVibe limits what can go wrong. Your AI acts with the permissions of the WordPress user who connected. Theme edits happen in a draft copy, inside a path sandbox, with a file type allowlist and a PHP syntax check, and publishing keeps a backup of the previous theme you can restore. Commands run from a fixed allowlist. Destructive operations pause for your approval with a dry-run preview, unless an Administrator turns approvals off in wp-admin, and each one is recorded in the Approval Log. Deletes go to the trash wherever WordPress keeps one; a forced permanent delete of a post pauses for approval.

= Does WPVibe work with Elementor and other page builders? =

Yes. Elementor, Bricks, Breakdance and Beaver Builder pages are saved through each builder's own save code, and Elementor Pro theme builder templates are supported too. SeedProd pages are saved through the SeedProd builder itself. Divi, Gutenberg, Kadence, GeneratePress, Astra and Blocksy have built-in guides for your AI. See "What your AI can do" above.

= Does WPVibe work with WooCommerce? =

Yes. Your AI can review your store and create, update and bulk-edit products, prices, stock and descriptions.

= Does WPVibe work with ACF and custom fields? =

Yes. Custom fields are post meta, and WPVibe reads and writes them on any post type, including ACF fields. Fields a plugin keeps private (keys that start with an underscore) follow WordPress's rules: they can be changed where the plugin that owns them allows it, and otherwise only by an Administrator, on a post that account can edit.

= Can I connect multiple WordPress sites? =

Yes. Connected sites are unlimited on every plan, including the free plan. Connect all your sites to one account and switch between them in any conversation.

= Do I need to know how to code to use WPVibe? =

No. You ask in plain words and your AI does the work, including writing theme code.

== Changelog ==

= 1.24.1 =
* Security: WPVibe's settings commands and database queries keep passwords, API keys, salts and tokens out of what they show your AI. Option and transient reads mask secret fields inside a setting, such as an SMTP password or a payment gateway key. Database queries read this site's options, users and user meta tables with those values masked, and search-replace skips secrets: it does not search, count or name them. Reading WooCommerce API keys through a database query needs your approval.
* Security: An Author connected through WPVibe can no longer write protected custom fields, such as Elementor's page data, by adding a backslash to the field name. The post, term and user meta commands check the key the same way.
* Hardening: Protected custom fields follow WordPress's own permission checks for every role, Administrators included, unless an Administrator forces the write on a post they can edit. Writing a page builder's raw layout needs the unfiltered_html capability.
* Hardening: The page builder save routes, media uploads, ability runs and WPVibe's background update and job routes check the post, ability or one-time token they act on before running.
* Hardening: Theme backups are encrypted with a key kept in your database and tied to their theme, so a backup downloaded from uploads is useless on any server, nginx included. Backups made by 1.24.0 are encrypted the next time wp-admin loads.
* Fix: A theme publish or restore that stops part way is finished or undone the next time an administrator opens wp-admin. Theme folders a publish left behind are kept as backups before they go, on multisite too.
* Fix: Publishing a draft theme on multisite follows the network's allowed themes, as switching themes does.
* Fix: An approved operation whose first run never recorded an outcome is no longer run a second time; WPVibe asks your AI to check its effect first.
* Fix: Bricks saves keep the layout they overwrite as a revision, and restoring that revision puts the layout back.
* Fix: The Approval Log and operation records no longer query a missing table or print database errors, and create their tables again when they go missing.
* Fix: When a plugin or setting turns off Application Passwords for the REST API only, the WPVibe page and connection check say so and offer Allow for WPVibe, instead of passing the check while every connection failed.
* Fix: White label turns on WPVibe auto-updates only where your account may change plugin updates.
* Fix: The readme lists the outside services that receive your site's content, from the plugin or from the WPVibe service, with what is sent and when, and says WPVibe is not affiliated with the WordPress Foundation, Anthropic, OpenAI or the other third-party products it names.

= 1.24.0 =
* Feature: Each user can now turn off live reload for themselves, on the WPVibe page or on their own Profile page. With it off, your browser stays on the page you are viewing while your AI edits the site, instead of jumping to each post it changes.
* Feature: Your AI can now set a post's custom fields in the same step that creates or updates the post, instead of one call per field. Protected fields are still refused, and nothing is written when one is included.
* Fix: When WordPress would strip scripts or styles from a post because the connected user is not allowed unfiltered HTML, WPVibe now refuses the edit and says why, instead of saving the post with them removed.
* Fix: On a connection whose WordPress user is not an Administrator, WPVibe's refusals now point your AI to the REST routes for content, and say how to reconnect as an Administrator for admin-only work, instead of suggesting WP-CLI or a plain reconnect.
* Fix: After a theme publish, when your front page answers with an empty or cut-short page, WPVibe now says it could not confirm the theme works, instead of reporting it as healthy.
* Feature: When you publish a draft theme, WPVibe now keeps the previous theme as a protected zip under uploads instead of a theme folder, and your AI can put it back with `theme restore-backup`. Running it again undoes the restore. Existing backup folders become zips.
* Hardening: Turning on Angie's Super Admin mode through an option change now asks for your approval first. Turning it off stays free.
* Hardening: Your AI can no longer read or change the API keys WordPress keeps for AI providers in Settings > Connectors, or Akismet's API key.
* Hardening: Edits now check the capabilities of the post, template or theme being changed, not only Administrator access. Theme switches follow the network's allowed themes on multisite, and white label no longer changes network-wide auto-updates for a site administrator.
* Fix: WPVibe's activity log and approval records tables are now recorded as installed only once they exist, and a failed install is retried.
* Fix: The readme now describes what passes through the WPVibe service, its relay servers and your AI provider.

= 1.23.0 =
* Feature: Before your AI saves over a published Elementor page, WPVibe now keeps the page as it was as a WordPress revision, with its layout and page settings. If the change is not what you wanted, your AI can put the page back from that revision. If your site keeps no revisions for the page, your AI is told the save cannot be undone.
* Fix: In white-label mode, WPVibe's row now shows on the Plugins screen while an update is available, so WordPress's "new version available" line appears. The row hides again once WPVibe is up to date.
* Fix: The draft preview banner now says anyone with the preview link can see the draft until the link expires, instead of "Changes are only visible to you".
* Fix: When Approve on the connect page fails with WordPress's "Cookie check failed", WPVibe now says why and how to get past it: reload the page and approve again, try a private window, or exclude wp-admin and /wp-json/ from your cache or CDN.
* Fix: The connection check names SeedProd as the page answering instead of the WordPress API only when SeedProd's coming soon or maintenance page is what answered, not just because the mode is switched on.
* Fix: When your AI deletes a post on a site with the trash turned off, it is now told the post was deleted for good, not moved to the trash.
* Fix: When your AI is refused a write to an SEO plugin's field (Rank Math, Yoast SEO or SEOPress), it is now told whose field it is and how to make the change you asked for, instead of dropping it. For All in One SEO, it is pointed to the way that plugin stores its data.
* Fix: When a signed approval does not match your site, WPVibe can now tell whether your host rewrote your site's address (as on some temporary domains) and say so, instead of a general mismatch error.
* Fix: When your AI adds an image from a URL that WordPress downloads with its transfer framing still in the file, WPVibe now refuses it and says to use a direct link to the image, instead of adding a broken image to your Media Library.
* Fix: When your AI searches a page's Elementor data or other stored settings for text with accented or non-English letters, it now finds it, and it is told when the field it searched does not exist.
* Hardening: When your AI reads a file on your site, WPVibe now also hides Google and Gemini API keys, and keys passed in a link's key= parameter.

= 1.22.1 =
* Fix: When someone else has an Elementor page open in WordPress, your AI's save now waits instead of overwriting their unsaved changes. Your AI is told who is editing the page and to try again once they have saved or left the editor.
* Fix: When a plugin install or update fails, your AI now sees which file failed, WordPress's error code and the upgrader's log, not only "Could not copy file.". On hosts such as WP Engine that refuse PHP file writes outside wp-admin, it asks you to update in wp-admin instead of retrying.
* Hardening: The upgrader log your AI sees after a failed install or update now keeps only the site name of a download link, which for a premium plugin can carry its license key, and shows file paths inside WordPress instead of the server's full path.

= 1.22.0 =
* Feature: Your AI can now make several changes to one post, setting or field in a single edit, saved together as one revision. If any change does not match, nothing is saved and your AI is told which one failed.
* Feature: Elementor's own AI abilities (Elementor 4.3 and later) now work through WPVibe even when Elementor's MCP setting is off. The setting stays as you left it for everything else, including Elementor's own MCP connection.
* Feature: Before saving an Elementor page, WPVibe now checks every element with Elementor's own rules: unique ids, widgets that are installed and active, and Elementor 4 settings in the right shape. A page with problems is not saved, and your AI is told which element to fix. Your AI can also check a page without saving it.
* Feature: WPVibe now keeps your site's last few PHP fatal errors for 7 days, with the plugin or theme that caused them, so WPVibe can tell why a site fails even when it cannot sign in. It keeps PHP's own error text, file names inside WordPress, memory use and your WordPress, PHP and active plugin versions: no stack traces, values or request data. The connection check on the WPVibe page shows the latest one.
* Fix: WPVibe connects again on sites running WP 2FA 4.3.0, which blocked Application Password logins for every user with two-factor set up. Only WPVibe's own password, on a WPVibe request over HTTPS, gets through. WP 2FA's lockout and every other password stay as they were.
* Fix: When WPVibe's draft theme record holds a value no draft action accepts, such as "none", every draft action used to fail. Deleting the draft now clears that record, and only when nothing exists at that path, so no files are deleted.
* Security: Theme delete and update, and their approval preview, now refuse a theme name that is a path (a slash, a backslash, "." or ".."). Until now an approved theme delete given such a path could remove a folder outside your themes folder.
* Hardening: A search-replace that runs without an approval showing that exact change, such as a fleet run, now leaves out tables that hold code or keys (Code Snippets, Header Footer Code Manager, Wow Coder, WPVibe's own records and WooCommerce API keys), and never changes a post's type or a term's taxonomy. A search-replace you approved still covers them.

= 1.21.7 =
* Fix: When your AI tries to delete the folder of a WPVibe draft theme, it is now refused and pointed to discarding the draft instead, so WPVibe is never left pointing at a draft that no longer exists. A publish that moved the draft live but could not restore its theme name no longer leaves that stale draft record behind either.
* Fix: With "Allow for WPVibe" on, the WPVibe page now explains that while a security plugin turns Application Passwords off, only a password WPVibe created gets through. If your account has none yet, it says so and how to connect.
* Fix: On sites where another plugin turns on strict database error reporting, a database query from your AI with a mistake in it now returns the SQL error instead of WordPress's critical error page, and no longer leaves a read-only transaction or time limit open.

= 1.21.6 =
* Fix: wp-admin no longer offers Activate or Live Preview on a WPVibe draft theme, which locked every draft change once active. A switch to a draft from anywhere else puts the site straight back on its theme and says how to preview or publish the draft instead.
* Fix: Asking your AI to search or edit a post that does not exist now says the post does not exist, instead of reporting a missing permission and asking you to reconnect.
* Fix: When Approve is refused because the request is not a plain browser login, the message now names the cause (an Application Password, a server password such as HTTP Basic Auth, or an extra header) and how to get past it.
* Fix: On white-labelled sites with "Dangerously bypass approvals" on, the warning banner now turns it off in place. Your AI can turn the setting off for you too, but never on.
* Fix: When your AI cannot make a draft copy of your theme, it now says why and what to do: a theme the host links in from elsewhere (as on WordPress.com), a link inside the theme, a themes folder WordPress cannot write to, or a draft record the database did not keep. It used to say only that the existing draft was preserved.
* Fix: Connecting a site now tells WPVibe which way WordPress created the connection's password, to help trace connections that fail.
* Hardening: Before WPVibe first changes a post's title or content, WordPress keeps a revision of the original, so the first AI edit can be undone. When revisions are off for that post, your AI is told the change cannot be undone.

= 1.21.5 =
* Fix: Approve now connects on sites whose REST API address answers "not found". It retries through the ?rest_route= address, which those sites still answer, and the help notice names the address it tried.
* Fix: The connection check no longer fails on sites running LiteSpeed Cache, which could serve an earlier check's answer. WPVibe now tells LiteSpeed Cache not to cache it.
* Hardening: A search-replace that runs without an approval showing that exact change, such as a fleet run, now leaves WPCode and Elementor code snippets unchanged and says so. A search-replace you approved still changes them.

= 1.21.4 =
* Fix: SeedProd pages your AI saves compile again on sites where other plugins run background tasks, such as Action Scheduler, while the page is saved. The builder sign-in now refuses those requests instead of signing itself out, so the save goes through.
* Fix: WPVibe's update notices show in wp-admin even when WPVibe is hidden from admins.
* Hardening: The draft theme preview loads Tailwind from the plugin instead of a CDN.
* Security: The readme now explains that requests to your site may pass through WPVibe's relay servers, and how to report a security issue (security@wpvibe.ai).

= 1.21.3 =
* Feature: WPVibe now finds and runs abilities that a plugin offers to AI agents only (marked for MCP but hidden from the REST API), such as those in Fluent Forms, Meta Box, Premium Addons for Elementor and PixelYourSite. Each ability's own permission check still decides who can run it.
* Fix: Publishing a draft theme no longer rolls back when a maintenance or coming-soon page is on (SeedProd, LightStart, CMP or Maintenance). The publish is kept, and WPVibe tells you to view the site logged in before you turn maintenance off. A real error page still rolls back as before.
* Security: The SeedProd builder sign-in now also limits Super Admins on multisite, accepts only the exact fields the builder sends on each call, refuses file uploads, and views the front end signed out.

= 1.21.2 =
* Security: The one-time sign-in WPVibe uses to save a SeedProd page in the builder now only reaches that page's builder. It can no longer open other admin screens, upload plugins or themes, add users or change settings, it ends after 10 minutes instead of 2 days, and any request outside the builder signs it out.
* Security: On multisite, a site administrator who is not a Super Admin now only reads their own site: database reads, user lookups and network transients stay on that site, and search and replace skips network and other sites' tables.
* Security: Search and replace now escapes table and column names when it writes, and skips any table whose name is not a plain identifier.
* Hardening: Reading wp-config constants now uses an allowlist of diagnostic settings (paths, debug, cache, memory, multisite), so a credential stored under an unusual name is never returned.
* Hardening: WPVibe only takes back a draft theme folder it created itself. A folder that merely ends in -wpvibe-draft is left alone, including on uninstall.

= 1.21.1 =
* Security: The approval log on your site no longer keeps passwords and other secret values from the commands you approved, such as post passwords and the values in a database query. Before, only user passwords were hidden. Approved commands are now logged by their shape.
* Feature: Your AI can read large plugin and theme files a section at a time, up to 1 MB. Before, any source file in wp-content over 64 KB was refused, even for a few lines. The whole file is still checked for secrets, and they are masked before any lines are returned.
* Feature: Beaver Builder pages can be edited one part at a time. Your AI sends only the rows and modules it changed, so it no longer has to pass your whole layout, including form keys such as reCAPTCHA secrets, back through the chat.
* Feature: Your AI can list a post's categories and tags (wp post term list), look up a single category or tag (wp term get), and read and change term meta (wp term meta).
* Fix: Reading a page's HTML works on WordPress sites installed in a subfolder (for example example.com/blog). It used to fetch a "page not found" page instead and report success.
* Fix: Beaver Builder saves refuse a module whose internal id does not match its key, which Beaver would otherwise drop or rename while reporting success.

= 1.21.0 =
* Security: Pending plugin, theme and WordPress updates can no longer be pointed at a different download through an option change. WordPress's update records are now read-only to your AI.
* Security: The list of user roles is now protected on every site, including sites with a custom database table prefix and every multisite subsite.
* Security: The draft theme file tools only ever work inside a draft theme WPVibe created, never in your live theme or its parent theme.
* Security: The SeedProd builder sign-in that WPVibe opens to compile your pages can no longer switch on "Dangerously bypass approvals".
* Security: The approval preview for a database query no longer runs statements the query itself would refuse, such as file reads or queries on protected tables.
* Security: With "Allow for WPVibe" on behind a security plugin that blocks application passwords, only the WPVibe password of an account connected to WPVibe gets through. Other accounts, and the same account's other application passwords, stay blocked.
* Fix: Draft theme editing works on Windows hosts and local Windows installs (for example Local). Reading, editing and outlining existing files no longer fail with a path error.
* Fix: Your AI can no longer delete the WordPress user WPVibe is connected as, which used to break the connection until you reconnected by hand. It is told to reconnect as another user first.
* Fix: Post meta and user meta writes follow WP-CLI's --format option. JSON text stored by plugins such as SeedProd and Elementor stays text instead of being turned into an array, and --format=json or --format=plaintext picks either one.
* Fix: User meta saved through WPVibe keeps escaped quotes, so JSON text in user meta stays valid.
* Improvement: option pluck supports --format=json, so your AI can tell true, 1 and "1" apart. Undoing an Astra setting change uses it.

= 1.20.5 =
* Security: User meta changes that need your approval can no longer be slipped past it with a key the database treats as the same as a protected one (for example a different letter case, an accent or a hidden character). Keys like that are refused, and a write is refused whenever WPVibe cannot confirm which stored key it would touch. Thanks to Mahesh Bohara (https://github.com/maheshbohara) for reporting it.
* Improvement: WPVibe's own updates are safer. An update waits while WordPress is running its own background updates, a failed or rolled-back update is always reported as failed (with the reason when the site could not reach itself to check the new version), maintenance mode is cleared if an update is interrupted, and WPVibe records a note whenever it had to switch itself back on.
* Fix: Approved database writes no longer fail with "Multiple SQL statements" when a quoted value contains a semicolon, such as serialized data or CSS. Anything the check cannot read with certainty is still refused.
* Fix: When another plugin or theme crashes while WPVibe saves an edit, administrators see the PHP error with its file, line and the plugin or theme it came from, instead of WordPress's bare "critical error". Your AI is told to check whether the change was saved rather than send the same edit again.
* Fix: An upload your site refuses because of its file type (for example an SVG blocked by an SVG plugin) is reported as an unsupported file type with the site's own message, not as a disk error, so your AI stops retrying it.
* Fix: Menu changes no longer fail on sites whose plugins look up the admin screen when a menu is saved (for example Ocean Extra).
* Fix: The plugin page now names the right Wordfence screen (All Options, Brute Force Protection) for the setting that turns off application passwords.
* Fix: Astra child themes are recognized in the dashboard widget and when WPVibe picks the right Astra tools.
* Improvement: When a site refuses WPVibe's sign-in, the connection check reports a few more facts about why (whether the server passed the credentials through and whether WordPress's application password check ran), never the credentials themselves.

= 1.20.4 =
* Fix: When WPVibe updates itself, it stays switched on. Before, an update WPVibe ran on its own (from your AI or a fleet job) could leave the plugin deactivated until someone turned it back on. The update now runs the way WordPress's own background updates do, so WordPress also checks the new version for errors and restores the previous one if it would break the site.
* Improvement: Beaver Builder pages save on Beaver Builder versions before 2.6. When a save hits a PHP error, administrators see the error with its file and line, and a page the failed save created is removed.
* Feature: Bricks header and footer templates built through WPVibe now show on the site. Before, they were saved where Bricks does not read them.
* Improvement: Other plugins can log WPVibe's activity: the new `wpvibe_operation_executed` action fires each time an approved operation runs, with the command and the preview you approved.
* Fix: WPVibe's SeedProd-only login route no longer appears in the plugin's route list, so AI agents stop calling it on sites without SeedProd.
* Improvement: WPVibe can turn off a WPCode snippet it created, with no approval needed, because turning it off loses nothing and you can turn it back on in WPCode. It can also delete one of its own snippets after you approve it on the approval page (or at once, if you turned on "Dangerously bypass approvals"). A deleted snippet goes to the trash in WPCode, where you can restore it. WPVibe never turns off or deletes snippets you created, and it does not delete one of its own snippets after you changed its code. Turning a snippet off also stops it running when it was moved to the trash while still on, and clears the page caches WPVibe supports.
* Fix: Draft theme previews work on sites that use the SeedProd Website Builder. Before, the preview showed the live SeedProd site instead of the draft. The preview and the publish result say that the live site keeps showing the SeedProd site until the Website Builder is turned off.
* Fix: Preview pages tell hosts, caching plugins and CDNs not to cache them, so a page cache no longer serves a stale preview after an edit.

= 1.20.3 =
* Fix: Approved database writes that are long (over about 8 KB) and wrapped in double quotes no longer fail with a syntax error on some hosts.
* Fix: File listings with a name pattern such as *.php now find matching files in every folder, and a pattern used with a folder now matches inside that folder. Before, *.php found only top-level files, and with a folder it found nothing.
* Fix: Saved Site Editor changes on the live theme no longer override a published draft theme by default. Publishing sets aside the live theme's saved global styles, and its saved templates and template parts that the draft also has. Set-aside changes are kept and can be brought back, and you can ask to keep them instead. Changes saved in the Site Editor during the preview now go live with the theme, unless you keep a live change with the same name. Deleting a draft sets its saved changes aside too.
* Feature: The preview and the publish result warn when the theme's folder name belongs to a theme on WordPress.org, because a WordPress.org update could replace a custom theme. Adding "Update URI: false" to style.css stops this.

= 1.20.2 =
* Fix: Live reload puts much less load on your server. It checks for changes every five seconds instead of every two and a half. When a check times out, every open tab of the site stops checking, so it cannot tie up the server's PHP workers; reload the page after five minutes to turn live reload back on.
* Fix: When an approved database change fails, the Approval Log now records the error message instead of an empty result, and the change is labelled as a write.

= 1.20.1 =
* Improvement: Claude users can add WPVibe from Claude's connectors directory. The "Add to Claude" button on the WPVibe settings page and a new link in the dashboard widget open the listing. The server address is still there for Claude Code, Cursor and other clients.
* Hardening: Turning on white label mode always asks for approval, even when "Dangerously bypass approvals" is on. White label hides the Approval Log, so a person confirms it each time. Sites that already use white label are not affected.

= Older versions =
WP.org caps the changelog at 5,000 words. For the full release history back to 1.0.0, see https://wpvibe.ai/changelog/
