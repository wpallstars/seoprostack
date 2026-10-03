# SEO Pro Stack — agent guide

WordPress plugin. Slug and text domain `seoprostack`, main file `seoprostack.php`.
Minimums: **WordPress 6.2, PHP 7.4** (`readme.txt`, plugin header). User docs:
`README.md` (developers) and `readme.txt` (WordPress.org).

## Adding or changing a feature

- One class per feature in `includes/features/`, extending `SEOProStack_Feature`
  (`includes/class-seoprostack-feature.php`), registered in
  `SEOProStack::$core_features` (`includes/class-seoprostack.php`).
- `settings()` declares the schema; the admin UI renders and saves it with no
  extra code. Field types and keys: `README.md` → Developers.
- `boot()` returns early unless `self::enabled()`. Features are **off by
  default**. The only exceptions, at the owner's request, are Hide admin bar
  items, which hides Comments and + New, No fade between admin screens,
  Quiet Freemius prompts, Fixes for other plugins and Updates from GitHub
  (GitHub builds only).
  Turning another feature on by default needs the owner's say.
- A feature that replaces another plugin sets `'replaces' => array(slug => name)`,
  imports that plugin's settings in `migrate()` with `self::import_setting()`
  (fills only unset keys), never writes or deletes the other plugin's options,
  and removes the slug from `admin/data/free-plugins.php` with a comment. The
  Plugins screen then suggests deactivating and deleting that plugin
  (`admin/includes/class-replaced-plugins.php`) with no extra code.
- A feature that replaces or is modelled on another plugin also gets a row in
  `README.md` → Features table (replaced plugins) and `README.md` → Credits:
  plugin, maker, its WordPress.org page and its maker's own source repository
  (check each link with `gh api repos/{owner}/{repo}` or the WordPress.org
  plugin API; never link a mirror or guess a URL), and the feature.
  Commit, then run `php scripts/replaced-plugins.php --write` to update the
  count and download sizes above the Features table; preflight fails when the
  count is out of date.
- `README.md` is also the plugin's Read Me tab
  (`admin/includes/class-readme-manager.php`), which renders headings, lists,
  tables, bold, italic, inline code, links (http(s) and `#heading` links,
  with GitHub-style heading IDs) and images from the plugin folder on a line
  of their own (`![alt](admin/images/banner.svg)`). Use only that Markdown,
  or extend the renderer in the same change. The banner's source is
  `.wordpress-org/banner.svg`; rebuild it with `scripts/build-banner.sh`.
- Migrations run once per `SEOProStack_Settings::DB_VERSION`. After a release,
  a new or changed import needs a version bump and a line in the
  `maybe_migrate()` docblock.
- New options, post meta, transients or cron hooks must be removed in
  `uninstall.php`.
- Update `README.md` (feature section, hooks, changelog), `changelog.txt`
  (the user-facing changelog entry) and `readme.txt` in the same change.
  `readme.txt` must stay under 10 KB for WordPress.org: one short line per
  feature, every service the plugin contacts under External services, and only
  the newest version's changelog, in short. Details go in `README.md`.

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

## Code rules

- PHP 7.4 syntax and WordPress 6.2 APIs. Guard newer core APIs with
  `function_exists()` (see `class-seoprostack-preload-pages.php`).
- Capability and nonce checks on every admin action and AJAX handler; escape on
  output; sanitise through the schema.
- Admin copy: short, plain words, sentence case, no jargon.
- Decide for the user. Within a feature, SEO Pro Stack makes the choices
  (which plugins, screens or items it applies to) from what it can detect.
  Settings are there to bypass something that causes a problem, not choices
  people need to understand first. A new setting must earn its place;
  prefer detecting the right behaviour plus a short bypass list.
- No page caching or CSS and JS minification in SEO Pro Stack (owner's
  decision): they are a common source of broken sites and support load, so
  leave them to plugins that specialise in them (LiteSpeed Cache,
  WP-Optimize). SEO Pro Stack may recommend those plugins and set them up
  through their presets, saving through their own code.
- Site owner in control, performance first: the owner decides what their site
  sends, contacts and shows. Calls to outside services are opt-in where they
  are not the point of the feature, made only as often and for as long as
  needed (cache answers, never on every page load), and never block a visitor's
  page when they can run later. SEO Pro Stack's own features follow this, and
  features that rein in other plugins (Ask before licence checks, Quiet
  Freemius prompts) hand the choice to the owner instead of deciding for them.
  WordPress update checks and downloads are the exception: leave them alone
  (next rule).
- Do not change WordPress update behaviour (update transients, `auto_update_*`
  filters, update checks) outside the file below. Plugin Check reports
  `plugin_updater_detected` as an error, and WordPress.org asks plugins not to
  interfere with the updater. A parked example of turning updates off is on
  the `feature/disable-updates-parked` branch.
- The one exception, at the owner's request (for speed, reliability and site
  owners' control, and because more plugins will be released on GitHub):
  Updates from GitHub, all in
  `includes/features/class-seoprostack-github-updates.php` (listed in
  `SEOProStack::$optional_features`, loaded only when present). It replaces
  Git Updater: it adds GitHub releases of any plugin with a
  `GitHub Plugin URI` header to core's own update check and `plugins_api`,
  and leaves the download, install, auto-updates and rollback to core. It
  only adds entries for those plugins; it never removes or blocks other
  updates. Keep anything that installs or updates code from outside
  WordPress.org in that file, because the WordPress.org build leaves it out.
  Never add an `Update URI` header. Tokens for private repositories come only
  from `wp-config.php` or a filter, go only to api.github.com and are never
  stored.
- Leave no PHP errors, warnings, notices or deprecations behind. Fix any that
  SEO Pro Stack causes as you find them, in the same change when it is small,
  or as a tracked issue. That includes ones in other plugins that only happen
  because of SEO Pro Stack (for example a plugin skipped by Load plugins only
  where needed). Messages that other plugins cause on their own are theirs:
  mention them, do not hide them.

## Releases

GitHub releases are the early channel; WordPress.org gets settled versions.
Sites install the latest GitHub release whose tag is a plain version and the
asset whose name starts with the plugin folder (Updates from GitHub; Git
Updater, where still active, reads `Version:` on `main` instead), so:

- Publish the GitHub release (tag `vX.Y.Z`, asset `seoprostack-X.Y.Z.zip`
  with a `seoprostack/` folder, built with `.distignore`) straight after the
  version change reaches `main`.
- Never put a pre-release version (`-beta1`, `-rc1`) in `Version:` on `main`;
  mark test releases as pre-releases on GitHub.
- The WordPress.org build is the release build without the files in
  `.distignore-wporg` (`includes/features/class-seoprostack-github-updates.php`)
  and the `GitHub Plugin URI`, `Primary Branch` and `Release Asset` header
  lines. Its zip is named `wordpress-org-seoprostack-X.Y.Z.zip` so no updater
  picks it; never attach it to a GitHub release.
- Other plugins released on GitHub follow the same pattern: a
  `GitHub Plugin URI: owner/repo` header (and `Release Asset: true`), plain
  version tags, and a `{folder}-X.Y.Z.zip` asset with a `{folder}/` inside.
- Build both zips with `scripts/build-release.sh`, check them with
  `scripts/preflight-release.sh` and `scripts/plugin-check.sh`. None of them
  tags, publishes or uploads anything.
- Releasing and submitting to WordPress.org need the owner's say. The
  repository is private until then, so sites cannot read it without a token
  (`SEOPROSTACK_GITHUB_TOKEN`).

Details: `RELEASING.md` (steps and WordPress.org checklist), `README.md` →
Updates and releases.

## Front-end styling and dark mode

Block, shortcode and other front-end styles must work with the Kadence Pro
dark mode switcher (and themes that switch palettes the same way).

- How it switches: Kadence adds `color-switch-dark` or `color-switch-light` to
  `<body>`. The dark class sets `color-scheme: dark` and redefines
  `--global-palette1`…`15` and `--wp--preset--color--theme-palette-N` **on
  `<body>`**. `<html>` stays `color-scheme: light`. Palette 3 is the strongest
  text and palette 9 the page background in light mode; dark mode swaps them.
- Use `currentColor`, `inherit`, translucent neutrals (for example
  `rgba(127, 127, 127, 0.12)`) or palette variables, never fixed light or dark
  colours for text, backgrounds or borders.
- Do not use `@media (prefers-color-scheme)` to follow the site: it tracks the
  visitor's system, not the switcher.
- Do not define custom properties on `:root` from palette variables; they
  resolve above `<body>` and keep the light values. Read palette variables
  where they are used, or define derived ones on the block.
- Preset references (`var:preset|color|theme-palette3`) become CSS variables
  with kebab-cased slugs, as core does: `--wp--preset--color--theme-palette-3`.
  Use `_wp_to_kebab_case()` in PHP and the same rule in editor JS (see
  `css_value()` in `class-seoprostack-screenshots.php` and `cssValue()` in
  `blocks/screenshot/index.js`).
- Embedded pages (iframes) do not follow the switcher: they see the visitor's
  system setting, and the browser paints their own background behind them, so
  they stay readable in both modes. Do not make iframes transparent or tint them.
- Test light and dark with the Kadence theme by toggling the body class (see
  Testing, step 5).

## Testing

CI (`.github/workflows/ci.yml`) runs the code checks, the release preflight,
Plugin Check and a smoke test on every pull request; details and local
commands: `DEVELOPMENT.md`.

While the repository is private, CI and review apps only advise: nothing is
required to merge, for speed. Fix failures your change causes before merging;
open an issue for any other failure and merge anyway. Do not turn on branch
protection, required checks or paid reviewers. At public launch (owner's say),
run the full sweep in `DEVELOPMENT.md` → At public launch, which makes the
checks required.

The checks catch errors, not wrong behaviour, so also verify on real
WordPress:

1. `scripts/lint.sh` (syntax, ShellCheck, PHPCS, PHPStan; run
   `composer install` once). Fix findings in the code; never grow
   `phpstan-baseline.neon` or add a `phpcs:ignore` without a reason.
2. The user reviews on one local test site, shared by every session and
   worktree. It shows a **combined preview**: `origin/main` plus every open
   pull request from this repository, merged together. Update it only with
   the script, from any worktree:

   ```bash
   scripts/preview-site.sh             # the first run on a clone takes the site: scripts/preview-site.sh "<site>"
   scripts/preview-site.sh --dry-run   # report what would be included, copy nothing
   ```

   It fetches `origin`, merges each open PR's branch onto `origin/main` in PR
   order without touching any checkout, leaves out branches that conflict
   (and lists them), copies the result with `.distignore` applied (exactly
   what a release build contains), and writes
   `<site>/wp-content/seoprostack-synced-from.txt` listing what is included.
   A lock stops two runs at once. Because every run includes everyone's
   pushed work, no session hides another's.

   - **Never** `rsync` your worktree into the shared site: it hides every
     other session's work until the next run. Do not use Git hooks either:
     they are shared by every worktree and would deploy the wrong checkout.
   - Only pushed work with an open PR is included. Before asking the user to
     look at unmerged work, push the branch and open a draft PR, then run the
     script. It says so if the branch you run it from is missing or has
     commits that are not pushed.
   - After merging a PR, run the script again, then check that the stamp
     lists your merge in `main` before telling the user to look.
   - Conflicts only in `changelog.txt`, `readme.txt` or `README.md` do not
     leave a branch out: every PR adds lines at the top of the same
     changelogs, so each merge to `main` would otherwise drop every other
     open PR. The preview keeps both sides' lines there and says so in the
     stamp; still merge `origin/main` into your branch before it merges.
   - If your branch is left out because it conflicts in other files, merge
     `origin/main` into it (or wait for the other PR), push and run the
     script again. Tell the user which PRs are left out.
   - To check your branch on its own, or for checks the user will not look
     at, use a throwaway site of your own (step 4's Docker image on a free
     port), which no one else overwrites:
     `rsync -a --delete --delete-excluded --exclude-from=.distignore ./ "<site>/wp-content/plugins/seoprostack/"`.

3. Exercise the changed feature through the admin UI or HTTP, and check
   the debug log for new messages mentioning `seoprostack` or a plugin it
   skipped (`wp-content/debug.log`, or the file Debug Log Manager writes in
   `wp-content/uploads/debug-log-manager/` when it is active, as on the shared
   test site). For settings imports, seed the replaced plugin's
   options and delete `seoprostack_options` and `seoprostack_db_version` while
   the plugin is inactive, then activate it.
4. For changes that touch core APIs, also smoke-test on WordPress 6.2 with
   PHP 7.4, for example the `wordpress:php7.4-apache` Docker image with
   `wp core download --version=6.2 --force`.
5. For front-end styling, view the page with the Kadence theme in light and
   dark mode. Without Kadence Pro, simulate the switcher: print a
   `body.color-switch-dark { color-scheme: dark; --global-palette1: …; }`
   rule with a dark palette (palette 3 light, palette 9 dark, and the matching
   `--wp--preset--color--theme-palette-N: var(--global-paletteN)` lines), then
   swap the body class between `color-switch-light` and `color-switch-dark`.
   Check text, backgrounds, borders and palette colours chosen in block
   settings in both.

Note: since WordPress 5.6, posts restored from the Bin become drafts. Republish
test posts after bulk-trash tests.

## Release build

`.distignore` lists files kept out of the release zip. Add new development-only
files there, then check the build with Plugin Check.
