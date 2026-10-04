# SEO Pro Stack — agent guide

WordPress plugin made from the wpallstars starter plugin
(`wpallstars/wp-plugin-starter-template-for-ai-coding`).

**Read `STANDARDS.md` before any change.** It holds the rules every plugin
made from the starter shares: structure and core files, code rules, Updates
from GitHub, releases, front-end styling and dark mode, and testing. It is
the same in every plugin, so change it in the starter first. This file holds
only what is SEO Pro Stack's own.

| Placeholder in `STANDARDS.md` | SEO Pro Stack |
|---|---|
| `{slug}` | `seoprostack` (main file `seoprostack.php`) |
| `{prefix}` | `seoprostack` |
| `{Prefix}` | `SEOProStack` |
| `{PREFIX}` | `SEOPROSTACK` |
| `{Name}` | SEO Pro Stack |
| `{css}` | `sps` |

Core files are listed in `scripts/core-files.txt` (`STANDARDS.md` → Agent
docs says how to change them). SEO Pro Stack's own admin
styles and script are `admin/css/seoprostack-tabs.css` and
`admin/js/seoprostack-tabs.js` (loaded from `SEOProStack_Setup`), and its
own release checks (presets and starter data) are in
`scripts/preflight-plugin.sh`.

User docs: `README.md` (developers, and the Read Me tab) and `readme.txt`
(WordPress.org). Development: `DEVELOPMENT.md`. Releases: `RELEASING.md`;
this plugin's WordPress.org submission state: `LAUNCH.md`. Past changes:
`changelog.txt`; planned work: GitHub issues.

## Features

- Scope (owner's principle): offer everything a core CMS should have, so
  set-up is quick, easy and fast, and daily use is intuitive and versatile,
  with WordPress's own APIs, blocks and screens, compatible with Kadence and
  its light and dark modes on the front end. Specialist Pro add-ons stay
  with their providers. When a plugin mixes the two (TaxoPress: displays
  and clean-up are core; auto-tagging, AI and automatic linking are
  specialist), build the core part, keep pages made with the plugin
  working after it is deactivated, and stop recommending it.
- On by default, at the owner's request: Hide admin bar items, which hides
  Comments and + New, No fade between admin screens, Quiet Freemius prompts,
  Quiet Appsero prompts, Fixes for other plugins and Updates from GitHub (GitHub builds only).
- Updates from GitHub (`includes/features/class-seoprostack-github-updates.php`,
  in `SEOProStack_Setup::OPTIONAL_FEATURES` and `.distignore-wporg`) is the
  setting for the shared updater: off, Early updates from GitHub, waiting
  for Git Updater. It also reads the older `SEOPROSTACK_GITHUB_TOKEN` and
  `seoprostack_github_*` filters. A parked example of turning updates off is
  on the `feature/disable-updates-parked` branch.
- The Read Me tab's banner source is `.wordpress-org/banner.svg`; rebuild it
  with `scripts/build-banner.sh`.
- No page caching or CSS and JS minification in SEO Pro Stack (owner's
  decision): they are a common source of broken sites and support load, so
  leave them to plugins that specialise in them (LiteSpeed Cache,
  WP-Optimize). SEO Pro Stack may recommend those plugins and set them up
  through their presets, saving through their own code.
- Link Whisper retirement does not need historic click data or replacement
  analytics (owner's choice): leave the toolkit's click counting off for the
  rollout; separate analytics plugins and apps can cover those needs.
- Load plugins only where needed skips plugins on some requests. When
  checking the debug log, look for messages from a skipped plugin too: they
  are SEO Pro Stack's to fix.

## Task docs

Read the doc for your task first (`STANDARDS.md` → Agent docs):

- Adding or changing a preset (`presets/`) or starter data (`starters/`):
  `docs/presets.md`.
- Changing which plugins Free Plugins or Pro Plugins recommend:
  `docs/plugin-directory.md`.
- Adding a feature that replaces another plugin, or is modelled on one:
  `docs/replaced-plugins.md`.
- Testing, or timing pages on a live site: `TESTING.md`.
- SEO Pro Stack writes other plugins' settings only through presets and
  starter data, and only when someone asks.

## Test sites

The shared preview site runs SEO Pro Stack with every recommended plugin
(`admin/data/free-plugins.php`) installed and many active, with Debug Log
Manager writing the log. Size it, and any throwaway site, as
`DEVELOPMENT.md` → Test site resources says; when Hosting needs warns on a
test site, raise the value it asks for and update that table.
