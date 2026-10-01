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
  items, which hides Comments and + New, and No fade between admin screens.
  Turning another feature on by default needs the owner's say.
- A feature that replaces another plugin sets `'replaces' => array(slug => name)`,
  imports that plugin's settings in `migrate()` with `self::import_setting()`
  (fills only unset keys), never writes or deletes the other plugin's options,
  and removes the slug from `admin/data/free-plugins.php` with a comment.
- Migrations run once per `SEOProStack_Settings::DB_VERSION`. After a release,
  a new or changed import needs a version bump and a line in the
  `maybe_migrate()` docblock.
- New options, post meta, transients or cron hooks must be removed in
  `uninstall.php`.
- Update `README.md` (feature section, hooks, changelog) and `readme.txt`
  (description, privacy if it contacts a service, changelog) in the same change.

## Code rules

- PHP 7.4 syntax and WordPress 6.2 APIs. Guard newer core APIs with
  `function_exists()` (see `class-seoprostack-preload-pages.php`).
- Capability and nonce checks on every admin action and AJAX handler; escape on
  output; sanitise through the schema.
- Admin copy: short, plain words, sentence case, no jargon.
- Do not change WordPress update behaviour (update transients, `auto_update_*`
  filters, update checks). Plugin Check reports `plugin_updater_detected` as an
  error, and WordPress.org asks plugins not to interfere with the updater. A
  parked example is on the `feature/disable-updates-parked` branch.
- The one exception, at the owner's request: updates from GitHub through Git
  Updater, all in `includes/features/class-seoprostack-github-updates.php`
  (listed in `SEOProStack::$optional_features`, loaded only when present). It
  uses Git Updater's own `gu_*` filters and installs Git Updater on request;
  it never touches core update transients or bundles an updater. Keep
  anything that installs or updates code from outside WordPress.org in that
  file, because the WordPress.org build leaves it out. Never add an
  `Update URI` header.

## Releases

GitHub releases are the early channel; WordPress.org gets settled versions.
Git Updater compares the `Version:` header of `seoprostack.php` on `main`
with installed copies and installs the newest release asset, so:

- Publish the GitHub release (tag `vX.Y.Z`, asset `seoprostack-X.Y.Z.zip`
  with a `seoprostack/` folder, built with `.distignore`) straight after the
  version change reaches `main`.
- Never put a pre-release version (`-beta1`, `-rc1`) in `Version:` on `main`;
  every site with Git Updater would be offered it.
- The WordPress.org build is the release build without the files in
  `.distignore-wporg` (`includes/features/class-seoprostack-github-updates.php`)
  and the Git Updater header lines. Its zip is named
  `wordpress-org-seoprostack-X.Y.Z.zip` so Git Updater never picks it; never
  attach it to a GitHub release.
- Build both zips with `scripts/build-release.sh`, check them with
  `scripts/preflight-release.sh` and `scripts/plugin-check.sh`. None of them
  tags, publishes or uploads anything.
- Releasing and submitting to WordPress.org need the owner's say. The
  repository is private until then, so Git Updater cannot read it yet.

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

No automated suite ships with the plugin. Verify on real WordPress:

1. `php -l` every PHP file and `node --check` every JS file.
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
   - If your branch is left out because it conflicts, merge `origin/main`
     into it (or wait for the other PR), push and run the script again. Tell
     the user which PRs are left out.
   - To check your branch on its own, or for checks the user will not look
     at, use a throwaway site of your own (step 4's Docker image on a free
     port), which no one else overwrites:
     `rsync -a --delete --delete-excluded --exclude-from=.distignore ./ "<site>/wp-content/plugins/seoprostack/"`.

3. Exercise the changed feature through the admin UI or HTTP, and check
   `wp-content/debug.log`. For settings imports, seed the replaced plugin's
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
