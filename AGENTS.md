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
| `{Prefix}` | `SEOProStack` |
| `{PREFIX}` | `SEOPROSTACK` |
| `{Name}` | SEO Pro Stack |

User docs: `README.md` (developers, and the Read Me tab) and `readme.txt`
(WordPress.org). Development: `DEVELOPMENT.md`. Releases: `RELEASING.md`;
this plugin's WordPress.org submission state: `LAUNCH.md`. Manual test
checklists: `TESTING.md`.

## Features

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
- Features that rein in other plugins (Ask before licence checks, Quiet
  Freemius prompts) hand the choice to the owner instead of deciding for
  them, as `STANDARDS.md` → Code rules says.
- Load plugins only where needed skips plugins on some requests. When
  checking the debug log, look for messages from a skipped plugin too: they
  are SEO Pro Stack's to fix.

## Plugin presets

`presets/{plugin-folder}.json` holds SEO Pro Stack's chosen settings for other
plugins: the reference point the owner iterates on. Format and merge rules:
`includes/class-seoprostack-presets.php`. Users apply, reset and undo them on
the Plugins screen (Plugin presets, Plugins tab) or with
`wp seoprostack presets list|diff|apply|reset|undo`. These are the only places
SEO Pro Stack writes other plugins' settings, and only when someone asks.

To add or update a preset, on a throwaway site (never a live one):

1. Install the plugin's current version, activate it and do not configure it.
   Save `wp seoprostack presets export <folder> --like='<prefix>\_%'` as the
   fresh snapshot.
2. Choose the settings in the plugin's own screen, so its save code runs.
   Choose for speed, privacy (no calls to outside services that are not the
   point of the plugin), quiet admin screens (no dashboard boxes, promotions
   or emails that nobody reads), and the Kadence light and dark modes.
3. Run the export again with `--compare=<snapshot>`. Only changed settings
   are kept, with the fresh values as `defaults` (`null` means "not stored":
   the plugin's own default applies).
4. Remove what is not a preference: state (activation redirects, "tested",
   version and timestamp options, caches, counters), anything site-specific
   (addresses, emails, page or post IDs) and anything the export warned about.
   Secrets are left out automatically; never add them by hand.
5. Leave out settings that only work when saved through the plugin's screen
   (it schedules cron, writes files, calls a service or clears a cache kept
   under another name in that code; a cache under the option's own name is
   cleared for you). Read its save handler to check; say so in `notes` if users
   must save once in the plugin.
6. Write `notes` in plain words: what the preset does and why. Keep `tested`
   at the version you checked. Name every setting under `settings` for the
   Apply preset dialog: `label` as the plugin's screen words it, a short
   `description`, and `values` (stored value => what it shows, `"null"` for
   not stored, saying the plugin's default). Take them from the plugin's
   settings screen code; `scripts/preflight-release.sh` fails on a missing one.
7. Check on a second fresh site: `diff`, `apply`, confirm the plugin behaves
   as intended, `reset`, `undo`.
8. Add a changelog line in `README.md`, `changelog.txt` and `readme.txt`.

`starters/{plugin-folder}.json` holds starter data: the lists, tags, fields,
boards and forms the owner organises plugins' own tables with (FluentCRM,
Fluent Boards, Fluent Forms). Format: `includes/class-seoprostack-starters.php`.
Forms must work with only the free plugin: mark Pro-only fields with
`"when"`, and submit each form as a visitor on the throwaway site. Take it from the
owner's own sites, read-only (structure only: names, slugs, how they link;
never contacts, entries or messages), and write `notes` that explain the
pattern so people can extend it. Adding only fills what is missing; Remove
takes back only unused items SEO Pro Stack added. Check both on a throwaway
site with `wp seoprostack starters diff|add|remove`, with items in use.

A preset and starter data never set the same setting, so the order they are
used in never matters: Add starter data leaves stored settings alone and Apply
preset overwrites them. A setting goes in one or the other.
`scripts/preflight-release.sh` fails if one is in both.

## Test sites

The shared preview site runs SEO Pro Stack with every recommended plugin
(`admin/data/free-plugins.php`) installed and many active, with Debug Log
Manager writing the log. Size it, and any throwaway site, as
`DEVELOPMENT.md` → Test site resources says; when Hosting needs warns on a
test site, raise the value it asks for and update that table.
