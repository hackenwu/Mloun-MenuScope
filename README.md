**English** | [简体中文](README.zh-CN.md)

# Mloun MenuScope

Clean up the WordPress admin for clients and team members: **hide, block, rename and reorder admin menus** per role or user, hide plugins, and hide users — without touching any code.

This repository holds the source of the free version of the plugin, the same code that is published on [WordPress.org](https://wordpress.org/plugins/mloun-menroscope/).

- **Requires:** WordPress 5.8+, PHP 7.4+
- **Tested up to:** WordPress 7.1
- **License:** GPLv2 or later
- **Settings page:** *Settings → MenuScope*

## Why

A stock WordPress dashboard shows every menu, every plugin and every user to everybody. For a client site, that is noise at best and a support ticket at worst. MenuScope lets you decide what each role — or one specific user — actually sees, and it keeps the decisions reversible.

## Features

- **Hide menus per role or user** — the menu item disappears from the sidebar; the page URL stays reachable.
- **Hide & block menus per role or user** — the menu disappears *and* direct URL access is denied with a 403.
- **Drag & drop ordering** — reorder top-level menus and submenus per role or user. Submenus are collapsible in the editor.
- **Rename menus** — give any admin menu or submenu a different label for a specific role or user.
- **Hide plugins** — hidden plugins vanish from the Plugins screen and cannot be deactivated, deleted, updated or opened in the plugin file editor by that role or user. MenuScope can hide itself.
- **Hide users** — selected users become invisible to everyone else: gone from the Users screen, from author dropdowns, and their profile page cannot be opened by URL. They can still log in and work normally. You can hide yourself.
- **User settings override role settings** — when a section (menus, plugins, renames) is configured for a user, it completely replaces the same section from that user's roles.

## Hide vs. Block

| | Menu in sidebar | Direct URL access |
|---|---|---|
| **Hide** | removed | still works |
| **Hide & block** | removed | denied (403) |

Block is the right choice for anything a user should never reach, such as a menu belonging to a plugin they should not touch. Hide is the right choice when you only want to reduce clutter.

## Installation

1. Install from **Plugins → Add New** (search "Mloun MenuScope"), or upload the plugin ZIP, or copy the `mloun-menroscope` folder to `wp-content/plugins/`.
2. Activate the plugin.
3. Open **Settings → MenuScope**.

## How it works

The settings page has three tabs:

- **Menus** — pick a role or a user in the *Configure for* box, then toggle visibility, choose hide/block, drag to reorder, and rename items.
- **Plugins** — hide plugins from the Plugins screen for a role or user.
- **Users** — hide users from everyone else.

Settings are stored per role and per user. To undo a role or user completely, click *Delete* next to it in the *Configure for* box.

## Safety

- Users with the `manage_options` capability always keep access to the MenuScope settings page, so the plugin cannot lock an administrator out of its own settings.
- Blocking core infrastructure menus (the Settings parent menu, for example) is automatically downgraded to *hide* for administrators — an administrator can hide the menu but never 403 themselves out of `wp-admin`.
- Uninstalling the plugin removes its settings, including on every site of a multisite network.

## Translations

All strings use the `mloun-menroscope` text domain and the repository ships `languages/mloun-menroscope.pot`. Community translations are managed at [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/mloun-menroscope/), so WordPress loads them automatically — there is no bundled `.po/.mo` in the release.

## Source layout

```
mloun-menroscope.php          Plugin header, bootstrap, constants
includes/class-core.php       Hook wiring, capabilities, settings access
includes/class-admin-page.php Settings UI and form handling
includes/class-applier.php    Applies the configured rules at runtime
includes/class-plugin-visibility.php  Hidden plugins
includes/class-user-hiding.php       Hidden users
assets/css|js/admin.*         Settings screen styling and behaviour
uninstall.php                 Multisite-aware cleanup
languages/mloun-menroscope.pot
readme.txt                    WordPress.org readme
```

## License

Mloun MenuScope is free software, released under the GNU General Public License v2 or later. See [LICENSE](LICENSE).

---

Made by [Mloun](https://mloun.com) — WordPress development, WooCommerce and SEO for exporters.
