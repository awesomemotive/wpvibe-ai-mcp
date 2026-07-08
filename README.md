# WPVibe — MCP Server for WordPress

![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/vibe-ai)
![Tested up to WP 6.9](https://img.shields.io/wordpress/plugin/tested/vibe-ai)
![Requires PHP](https://img.shields.io/wordpress/plugin/required-php/vibe-ai)
![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

**WPVibe** is the open-source WordPress plugin that makes your self-hosted WordPress site accessible to AI assistants via the [Model Context Protocol (MCP)](https://modelcontextprotocol.io). Pair the plugin with the hosted WPVibe MCP service at [wpvibe.ai](https://wpvibe.ai) and your AI — **Claude**, **ChatGPT**, **Cursor**, **Windsurf**, **OpenCode**, **Claude Code**, or any MCP-compatible client — can manage WordPress through natural conversation.

> 🌐 **Website:** [wpvibe.ai](https://wpvibe.ai)
> 📦 **Install from WordPress.org:** [wordpress.org/plugins/vibe-ai](https://wordpress.org/plugins/vibe-ai/)
> 💬 **Support:** [GitHub Issues](https://github.com/awesomemotive/wpvibe-ai-mcp/issues) or [WordPress.org forum](https://wordpress.org/support/plugin/vibe-ai/)
> 🧠 **About MCP:** [modelcontextprotocol.io](https://modelcontextprotocol.io)

> **A quick note on naming:** The product is **WPVibe**. The WordPress.org plugin slug is `vibe-ai` (slugs can never change after approval, and the plugin file is `vibe-ai.php` to match), so the plugin URL and file names keep that spelling. The plugin itself displays as WPVibe everywhere.

> **Scope of this repository:** This is the **WordPress plugin** — the WP-side component that exposes WordPress operations as MCP-callable REST endpoints. The hosted WPVibe MCP server (gateway, OAuth, tool registration) lives at [wpvibe.ai](https://wpvibe.ai) and is not in this repo. Install both for the full experience.

---

## What WPVibe does

WPVibe is the **WordPress MCP integration** — a plugin you install on your WordPress site that exposes WordPress operations as MCP tools. Combined with the hosted WPVibe MCP service, your AI assistant gets a sandboxed, authenticated, capability-checked path to:

- **Manage WordPress content** — create, update, and publish posts, pages, and custom post types via the WordPress REST API
- **Edit WordPress themes safely** — sandboxed draft-preview-publish workflow with PHP syntax validation
- **Run WordPress WP-CLI commands** — 34 allowlisted commands via native PHP dispatch (no wp-cli binary required)
- **Use the WordPress Abilities API** — call any plugin's registered abilities (WordPress 6.9+)
- **Upload WordPress media** — pull images from URLs or Unsplash directly into your media library
- **Discover site state** — list files, search code, inspect installed plugins and themes — all read-first by default

No copy-pasting between AI chat and wp-admin. No API key juggling. One-click OAuth, AES-256-GCM encrypted credentials, and your WordPress site is AI-accessible.

## How WPVibe works

```
┌──────────────────┐
│  AI assistant    │  Claude · ChatGPT · Cursor · Windsurf · Claude Code · …
│  (MCP client)    │
└────────┬─────────┘
         │ MCP protocol (JSON-RPC over HTTPS)
         ▼
┌──────────────────┐
│  WPVibe MCP      │  Hosted at wpvibe.ai — OAuth, routing, tool registration
│  server          │  (closed-source service, separate from this repo)
└────────┬─────────┘
         │ HTTPS (authenticated REST)
         ▼
┌──────────────────┐
│  WPVibe plugin   │  ← This repo. Installed on your WordPress site.
│                  │     Capability checks, sandboxing, PHP lint, denylists
│                  │
└────────┬─────────┘
         │ Native WordPress PHP APIs
         ▼
┌──────────────────┐
│  Your WordPress  │  Content, theme files, options, database
└──────────────────┘
```

## MCP tools your AI gets

When you connect a WordPress site through WPVibe, your AI assistant has access to:

| Category | Tools |
|---|---|
| **Site management** | `connect_site`, `site_info`, `list_sites`, `remove_site` |
| **Content** | `rest_api` — call any WordPress REST endpoint (GET/POST/PUT/DELETE) |
| **Media** | `upload_media`, `search_images` (Unsplash) |
| **Abilities** | `discover_abilities`, `get_ability_info`, `run_ability` |
| **Files** | `read_file`, `edit_file`, `write_file`, `delete_file`, `list_files`, `search_files`, `get_file_outline` |
| **Theme workflow** | `create_draft_theme`, `publish_draft_theme`, `delete_draft_theme`, `get_preview_url`, `create_classic_theme` |
| **WP-CLI** | `run_wp_cli` — 34 native PHP commands, no shell required |
| **Visual** | `get_page_html`, `navigate` |
| **Skills** | `load_skill` — progressive instructions for common WordPress workflows |

Full per-tool documentation: [wpvibe.ai](https://wpvibe.ai)

## Quick start

1. **Install** the plugin from [WordPress.org](https://wordpress.org/plugins/vibe-ai/) or clone this repo into `wp-content/plugins/vibe-ai/`
2. **Activate** the plugin in wp-admin — you'll see "WPVibe" in your admin sidebar
3. **Click the OAuth authorization link** in the WPVibe admin page
4. **Add the WPVibe MCP server URL** to your AI client:
   - **Claude Desktop:** Settings → Developer → Edit Config
   - **Claude Code:** `claude mcp add wpvibe https://mcp.wpvibe.ai/mcp`
   - **Cursor / Windsurf:** MCP settings panel
   - **ChatGPT:** Connectors → Add MCP server

Your WordPress site is now AI-accessible.

## Security model

WPVibe is designed for safe AI access to production WordPress sites:

- **One-click OAuth** — no application passwords typed into chat, no long-lived tokens on disk
- **AES-256-GCM credential encryption** at rest with per-site salting
- **Per-endpoint WordPress capability checks** — `edit_themes`, `manage_options`, `install_plugins`, etc.
- **DISALLOW_FILE_MODS** honored for all write operations
- **Path sandboxing** for file operations — scoped to the active theme or its draft sandbox
- **File extension allowlist** for writes — `.php`, `.css`, `.js`, `.json`, `.html`, `.txt`
- **PHP syntax validation** — every saved PHP file passes an in-process syntax check before write
- **WP-CLI default-deny allowlist** — only 34 explicitly listed commands, dangerous flags stripped, shell metacharacters blocked
- **DB queries restricted to SELECT** — LIMIT enforced (max 1000), blocked-keyword regex with word boundaries
- **Sensitive option denylist** — `auth_*`, `*_salt`, `active_plugins`, `db_version`, and 20+ other core options can't be read or modified through the plugin
- **Two-phase confirmation flow** for `plugin install` / `plugin update`
- **DELETE moves to trash** for posts and pages — never a permanent delete via the MCP
- **Per-user scoping** on AI-action notifications so multi-admin sites don't leak activity between users

Full security model: [wpvibe.ai/security](https://wpvibe.ai)

For responsible disclosure of security vulnerabilities, please email **security@wpvibe.ai**. Do not open public GitHub issues for security reports.

## What makes WPVibe different

| Feature | WPVibe | Generic AI plugins | Custom integration |
|---|:---:|:---:|:---:|
| MCP-native (works with any AI client) | ✅ | ❌ | ❌ |
| No vendor lock-in (open protocol) | ✅ | ❌ | depends |
| Safe theme editing (draft + preview + lint) | ✅ | ❌ | maybe |
| WP-CLI command dispatch | ✅ | ❌ | rarely |
| WordPress Abilities API support | ✅ | ❌ | rarely |
| Open-source plugin (GPL-2.0) | ✅ | varies | varies |
| Capability-checked file operations | ✅ | varies | depends |
| OAuth flow (no app passwords in chat) | ✅ | ❌ | depends |

## Compatibility

- **WordPress:** 6.0+ (tested up to 6.9)
- **PHP:** 7.4+ (8.x recommended)
- **AI clients:** Anthropic Claude (Desktop, Web, Code), OpenAI ChatGPT, Cursor, Windsurf, OpenCode, Continue, Cody, and any [MCP-compatible client](https://modelcontextprotocol.io/clients)

## Contributing

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

- **Found a bug?** [Open an issue](https://github.com/awesomemotive/wpvibe-ai-mcp/issues/new?template=bug_report.md)
- **Have a feature idea?** [Suggest it](https://github.com/awesomemotive/wpvibe-ai-mcp/issues/new?template=feature_request.md)
- **Security disclosure?** Email **security@wpvibe.ai** — please don't open public issues for security reports

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history. The canonical changelog for WordPress.org is in `readme.txt`.

## License

GPL-2.0-or-later — the same license as WordPress core. See [LICENSE](LICENSE).

## Related projects

- [Model Context Protocol](https://modelcontextprotocol.io) — open protocol for AI-tool integration
- [WordPress Abilities API](https://make.wordpress.org/core/2024/12/19/proposal-abilities-api/) — WordPress 6.9 introduction
- [WPVibe](https://wpvibe.ai) — hosted MCP service for WordPress

---

**WPVibe** is built by [Awesome Motive](https://awesomemotive.com), makers of [WPForms](https://wpforms.com), [SeedProd](https://www.seedprod.com), [MonsterInsights](https://www.monsterinsights.com), [OptinMonster](https://optinmonster.com), [Duplicator](https://duplicator.com), [RafflePress](https://rafflepress.com), and other WordPress products.
