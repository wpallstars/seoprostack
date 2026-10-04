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

Core files (`scripts/core-files.txt`) come from the starter
(`wpallstars/wp-plugin-starter-template-for-ai-coding`): change them there
first, then run `scripts/sync-core.sh` here. SEO Pro Stack's own admin
styles and script are `admin/css/seoprostack-tabs.css` and
`admin/js/seoprostack-tabs.js` (loaded from `SEOProStack_Setup`), and its
own release checks (presets and starter data) are in
`scripts/preflight-plugin.sh`.

User docs: `README.md` (developers, and the Read Me tab) and `readme.txt`
(WordPress.org). Development: `DEVELOPMENT.md`. Releases: `RELEASING.md`;
this plugin's WordPress.org submission state: `LAUNCH.md`. Manual test
checklists: `TESTING.md`.

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
  Fixes for other plugins and Updates from GitHub (GitHub builds only).
- Updates from GitHub (`includes/features/class-seoprostack-github-updates.php`,
  in `SEOProStack_Setup::OPTIONAL_FEATURES` and `.distignore-wporg`) is the
  setting for the shared updater: off, Early updates from GitHub, waiting
  for Git Updater. It also reads the older `SEOPROSTACK_GITHUB_TOKEN` and
  `seoprostack_github_*` filters. A parked example of turning updates off is
  on the `feature/disable-updates-parked` branch.
- A feature that replaces another plugin also removes the slug from
  `admin/data/free-plugins.php` with a comment, and gets a row in
  `README.md` → Features table (replaced plugins) and `README.md` → Credits:
  plugin, maker, its WordPress.org page and its maker's own source repository
  (check each link with `gh api repos/{owner}/{repo}` or the WordPress.org
  plugin API; never link a mirror or guess a URL), and the feature. So does
  a feature modelled on another plugin. Commit, then run
  `php scripts/replaced-plugins.php --write` to update the count and
  download sizes above the Features table; preflight fails when the count is
  out of date.
- The Read Me tab's banner source is `.wordpress-org/banner.svg`; rebuild it
  with `scripts/build-banner.sh`.
- No page caching or CSS and JS minification in SEO Pro Stack (owner's
  decision): they are a common source of broken sites and support load, so
  leave them to plugins that specialise in them (LiteSpeed Cache,
  WP-Optimize). SEO Pro Stack may recommend those plugins and set them up
  through their presets, saving through their own code.
- The LiteSpeed Cache preset leaves CSS and JS Minify off (owner's
  decision, #285): without Combine, LiteSpeed Cache re-minifies every file
  on each page-cache miss (0.25–1.2 s per page on Hostinger), and Combine
  breaks pages. Don't turn them back on in the preset; `README.md` →
  LiteSpeed hosting has the numbers.
- Time uncached pages straight to the origin (`curl --resolve`), not from
  the hosting server through Cloudflare: those requests are held to 2.2 s
  whenever PHP takes 1.2–2.1 s, which visitors never see (#285).
- Features that rein in other plugins (Ask before licence checks, Quiet
  Freemius prompts) hand the choice to the owner instead of deciding for
  them, as `STANDARDS.md` → Code rules says.
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
- SEO Pro Stack writes other plugins' settings only through presets and
  starter data, and only when someone asks.

## Test sites

The shared preview site runs SEO Pro Stack with every recommended plugin
(`admin/data/free-plugins.php`) installed and many active, with Debug Log
Manager writing the log. Size it, and any throwaway site, as
`DEVELOPMENT.md` → Test site resources says; when Hosting needs warns on a
test site, raise the value it asks for and update that table.
