# Extra Chill Roadie

Extra Chill platform chat tools for [Data Machine](https://github.com/Extra-Chill/data-machine) agents. Roadie gives AI agents the ability to manage artist profiles, link pages, user profiles, and community forums on the Extra Chill network — all through natural language chat.

## What It Does

Roadie is the bridge between Extra Chill's platform features and Data Machine's chat system. It registers four chat tools via the `datamachine_tools` filter, each wrapping cross-site REST calls to the appropriate subsite in the multisite network:

| Tool | Site | Description |
|------|------|-------------|
| `manage_artist_profile` | artist.extrachill.com | Create, list, get, and update artist profiles |
| `manage_link_page` | artist.extrachill.com | Full link-in-bio page management (links, socials, styles, settings) |
| `manage_user_profile` | extrachill.com | View and update community profile (bio, title, city, links) |
| `manage_community` | community.extrachill.com | Browse forums, create topics, post replies, manage notifications |

All tools require authentication (`access_level: authenticated`). Artist-scoped tools auto-resolve the artist ID when the user has a single artist profile.

## Architecture

Roadie follows the Extra Chill platform pattern: **business logic lives in domain plugins** (extrachill-users, extrachill-api, etc.), and Roadie provides the AI-facing tool interface on top.

```
User (chat) → Data Machine → Roadie Tool → ec_cross_site_rest_request() → Subsite REST API → Ability
```

### ECRoadie_PlatformTool

All tools extend `ECRoadie_PlatformTool`, which extends Data Machine's `BaseTool` and provides:

- **`rest_request()`** — Cross-site REST calls via `ec_cross_site_rest_request()` (internal HTTP to localhost with HMAC auth)
- **`get_blog_id()`** — Site key resolution for safe data reads via `switch_to_blog()`

Each tool declares a `$site_key` (e.g. `'artist'`, `'community'`, `'main'`) and a `$tool_slug` for error context.

### Permissions

Roadie bridges Extra Chill team membership to Data Machine's agent access system:

- Hooks `datamachine_can_access_agent` to grant EC team members access to the Roadie agent
- Uses `ec_is_team_member()` as the source of truth
- Agent policy (name, status, redirect URIs) is synced on `plugins_loaded`

### Bridge Onboarding

For external chat clients (like Beeper/Matrix via [mautrix-data-machine](https://github.com/Extra-Chill/mautrix-data-machine)), Roadie provides onboarding configuration via the `datamachine_bridge_onboarding_config` filter:

- Welcome message, description, login instructions
- Room name and topic for the chat bridge
- Capability list (artist profile, link page, user profile, community)
- Avatar URL (filterable via `extrachill_roadie_bridge_avatar_url`)

## Chat Tool Reference

### manage_artist_profile

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `list` | List the current user's artist profiles | — |
| `get` | Get artist profile details | `artist_id` (auto-resolved) |
| `create` | Create a new artist profile | `name` |
| `update` | Update an existing artist profile | `artist_id` (auto-resolved), plus fields to update |

Optional fields for create/update: `bio`, `genre`, `local_city`, `profile_image_id`, `header_image_id`.

### manage_link_page

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `get` | View the full link page | `artist_id` (auto-resolved) |
| `add_link` | Add a single link | `url`, `text`, optional `section` |
| `remove_link` | Remove a link | `url` or `link_id` |
| `save_links` | Replace all link sections | `links` array |
| `save_socials` | Replace social links | `socials` array |
| `save_styles` | Update CSS variables | `css_vars` object |
| `save_settings` | Update settings | `settings` object |

Convenience actions (`add_link`, `remove_link`) handle the fetch-modify-save cycle internally so the AI doesn't need multi-step orchestration.

### manage_user_profile

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `get` | View the current user's profile | — |
| `update` | Update bio, title, or city | At least one of `custom_title`, `bio`, `local_city` |
| `update_links` | Replace profile links | `links` array |

Profile links support types: `website`, `facebook`, `instagram`, `twitter`, `youtube`, `tiktok`, `spotify`, `soundcloud`, `bandcamp`, `github`, `other`.

### manage_community

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `list_forums` | Browse available forums | — |
| `list_topics` | List topics (optionally filtered by forum) | Optional `forum_id` |
| `get_topic` | Read a topic with replies | `topic_id` |
| `create_topic` | Post a new topic | `forum_id`, `title`, `content` |
| `create_reply` | Reply to a topic | `topic_id`, `content` |
| `get_notifications` | Check notifications | Optional `unread` filter |
| `mark_notifications_read` | Mark all as read | — |

## Dependencies

- **[Data Machine](https://github.com/Extra-Chill/data-machine)** — Provides `BaseTool` class and the `datamachine_tools` filter
- **[Extra Chill Multisite](https://github.com/Extra-Chill/extrachill-multisite)** — Provides `ec_cross_site_rest_request()` for internal cross-site HTTP
- **[Extra Chill API](https://github.com/Extra-Chill/extrachill-api)** — REST endpoints and route affinity middleware
- **[Extra Chill Users](https://github.com/Extra-Chill/extrachill-users)** — User profile abilities and `ec_is_team_member()`

## Requirements

- WordPress 6.9+
- PHP 7.4+
- Data Machine plugin (network-activated)
- Extra Chill Multisite plugin (network-activated)

## Filters

| Filter | Description |
|--------|-------------|
| `datamachine_tools` | Registers all four chat tools (via BaseTool) |
| `datamachine_can_access_agent` | Bridges EC team membership to Roadie agent access |
| `datamachine_bridge_onboarding_config` | Provides Roadie-specific onboarding for bridge clients |
| `extrachill_roadie_allowed_redirect_uris` | External redirect URIs allowed for bridge auth |
| `extrachill_roadie_bridge_avatar_url` | Override the Roadie avatar URL |

## File Structure

```
extrachill-roadie.php          # Plugin bootstrap
inc/
  permissions.php              # Team access bridge + agent policy sync
  onboarding.php               # Bridge onboarding config (Beeper/Matrix)
  tools/
    register.php               # Tool registration on plugins_loaded
    class-ec-platform-tool.php # Base class with cross-site REST helper
    class-manage-artist-profile.php
    class-manage-link-page.php
    class-manage-user-profile.php
    class-manage-community.php
docs/
  CHANGELOG.md
homeboy.json                   # Homeboy deployment config
```

## Deployment

Managed via [Homeboy](https://github.com/Extra-Chill/homeboy):

```bash
homeboy build extrachill-roadie
homeboy deploy extrachill-roadie
```

## License

GPL v2 or later.
