# Releasing SEO Pro Stack

Releases on GitHub and the WordPress.org submission need the owner's say.
None of the scripts below tags, publishes, uploads or commits anything.

## Scripts

| Script | What it does |
|--------|--------------|
| `scripts/build-release.sh [--ref REF] [--out DIR]` | Builds both zips from a Git ref (default `HEAD`) into `dist/` (gitignored), with `SHA256SUMS`. Files come from Git, never the working tree. |
| `scripts/preflight-release.sh [--ref REF] [--strict] [--offline]` | Checks versions, headers, readme, both zips (layout, development files, PHP 7.4 and JS syntax, updater code), remote assets, that presets and starter data parse and never set the same setting, and the Git tag. Errors stop a release; `--strict` also fails on warnings, for a WordPress.org submission. |
| `scripts/plugin-check.sh [--ref REF] [--zip FILE]` | Runs Plugin Check on both zips in a disposable WordPress in Docker, then removes it. |

The two builds of each version:

| Zip | Contents | Goes to |
|-----|----------|---------|
| `seoprostack-X.Y.Z.zip` | Files in Git, less `.distignore` | GitHub release asset |
| `wordpress-org-seoprostack-X.Y.Z.zip` | The same, less `.distignore-wporg` (Updates from GitHub) and the `GitHub Plugin URI`, `Primary Branch` and `Release Asset` header lines | WordPress.org only |

Sites install the release asset whose name starts with `seoprostack` (Updates
from GitHub, and Git Updater where it is still active), so the WordPress.org
zip is named differently and must never be attached to a GitHub release.

Plugin Check reports Updates from GitHub as an updater
(`plugin_updater_detected`, `update_modification_detected`, and
`OffloadedContent` for its raw.githubusercontent.com address) in the GitHub zip;
`scripts/plugin-check.sh` lists those as expected there and fails on them in
the WordPress.org zip.

## GitHub release

1. In a pull request, set the version in `seoprostack.php` (`Version:` and
   `SEOPROSTACK_VERSION`) and `readme.txt` (`Stable tag:`), rename the
   changelog's Unreleased section to the version in `readme.txt`,
   `changelog.txt` and `README.md`, and add an upgrade notice if people need
   to act. `readme.txt` keeps only the newest version, in short, and must stay
   under 10 KB; `changelog.txt` keeps every version in full.
   Numbers only (`1.2.3`): sites still on Git Updater offer a `Version:` on
   `main` to every site, and Updates from GitHub skips tags with letters.
2. On the pull request's branch: `scripts/preflight-release.sh` (no errors) and
   `scripts/plugin-check.sh` (no errors).
3. Merge, then straight away:

   ```bash
   git fetch origin
   scripts/preflight-release.sh --ref origin/main   # no errors before tagging
   git tag -a vX.Y.Z origin/main -m "SEO Pro Stack X.Y.Z"
   scripts/build-release.sh --ref vX.Y.Z
   git push origin vX.Y.Z
   gh release create vX.Y.Z dist/seoprostack-X.Y.Z.zip --title "SEO Pro Stack X.Y.Z" --notes-file <notes>
   ```

   Sites with Updates from GitHub see the release when they next check.
   Sites still on Git Updater are offered the `Version:` on `main` before the
   release exists, so do this in the same sitting as the merge.
4. Check the release has exactly one asset, `seoprostack-X.Y.Z.zip`, and on a
   site with the previous version that **Check again** on the Updates screen
   shows the update and that it installs.

Sites read the repository without signing in, so it must be public for sites
to get updates. It is private for now; a test site can use a read-only token
in `WPALLSTARS_GITHUB_TOKEN` (`wp-config.php`; it serves every plugin with
the shared updater) meanwhile. Making it public
needs the owner's say; do the quality sweep in `DEVELOPMENT.md` → At public
launch in the same step.

## WordPress.org preflight

Guidelines: [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
[Planning, submitting and maintaining](https://developer.wordpress.org/plugins/wordpress-org/planning-submitting-and-maintaining-plugins/),
[Plugin readmes](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/),
[Plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/),
[Using Subversion](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/).

### To do before submitting (state at 0.5.0)

`scripts/preflight-release.sh --strict` reports these:

- [x] **readme.txt under 10 KB** (WordPress.org says larger readmes may cause
      errors): one line per feature, the newest version's changelog in short,
      every version in `changelog.txt`. Keep it under 10 KB as features are
      added; details belong in `README.md`, which the Read Me tab shows.
- [ ] **Slug.** WordPress.org makes the slug from the Plugin Name:
      "SEO Pro Stack" becomes `seo-pro-stack`. The text domain and folder are
      `seoprostack`, so ask for `seoprostack` in the submission notes. It can
      change only before approval.
- [x] **Contributors: `wpallstars`**, the WordPress.org account that submits
      the plugin. Contributors must be WordPress.org usernames (case-sensitive).
- [x] **Plugin URI** is the GitHub repository, unique to the plugin (public
      from launch). Change it if the plugin gets its own page.
- [ ] **Changelog**: name the Unreleased section for the version submitted, in
      `readme.txt` and `changelog.txt`.
- [ ] **Assets** for the SVN `assets/` folder (not in the plugin zip):
      `banner-772x250` and `banner-1544x500` (PNG or JPG, up to 4 MB),
      `icon-128x128` and `icon-256x256` (PNG, JPG or GIF, up to 1 MB) and
      optionally `icon.svg`, and `screenshot-N` (PNG or JPG, up to 10 MB), with a
      `== Screenshots ==` section in `readme.txt` captioning each one. Keep the
      sources in the repository under `.wordpress-org/` (in `.distignore`).
      The banners are done: `.wordpress-org/banner-772x250.png` and
      `banner-1544x500.png`, built from `.wordpress-org/banner.svg` with
      `scripts/build-banner.sh` (which also writes the Read Me tab's
      `admin/images/banner.svg`). Icons and screenshots are still to do.

### Guideline review for this plugin

Reviewers read the code. Check each against the guidelines before submitting:

- [ ] **No code from elsewhere** (guideline 8): the WordPress.org zip has no
      GitHub updater, no `GitHub Plugin URI` headers and no other installer; Free
      Plugins installs only WordPress.org plugins through core. Preflight
      checks this.
- [ ] **External services** (guidelines 6 and 7): every service the plugin
      contacts is in `readme.txt` → External services, with what is sent and
      when, and links to terms and privacy policy (WordPress.org API,
      screenshot services, Google Analytics). Preflight lists hosts in the code
      that the readme does not name.
- [ ] **Scripts and styles ship with the plugin** (guideline 8); Google's
      gtag.js is part of the Analytics service.
- [ ] **Links in the directories** (Pro Plugins, Hosting, Tools): link straight
      to the product. Affiliate links must be disclosed and not cloaked
      (guideline 12).
- [ ] **Admin notices** (guideline 11): the plugin's own notices are
      contextual and dismissible; no promotions in the dashboard.
- [ ] **Defaults** (guideline 11 and the owner's rule): only Hide admin bar
      items, No fade between admin screens and Quiet Freemius prompts are on
      after activation (Updates from GitHub, also on, is not in this build). Say so in the description, as now.
- [ ] **Quiet Freemius prompts** (guidelines 7, 9 and 11): it uses the
      filters Freemius provides for this, stores nothing in the other plugins
      (none is opted in or out), keeps their licence, account and support
      features working, and switches off in one click. It only stops prompts
      that guideline 11 asks plugins to keep few, and it means fewer opt-ins
      to Freemius's data collection, not more. If a reviewer asks, offer to
      make it off by default.
- [ ] **Files outside the plugin folder**: Load plugins only where needed
      writes a loader to `wp-content/mu-plugins`, and WebP and AVIF images and
      Watermark write `.htaccess` files in uploads. All are opt-in and removed
      when turned off or on uninstall; say so in `readme.txt`.
- [ ] **Uninstall** removes every option, meta key, transient, cron hook and
      file the plugin adds (`uninstall.php`).
- [ ] **GPL** (guideline 1): all code and images GPL-compatible; credit any
      bundled third-party code in `readme.txt`.
- [ ] **Name and trademarks** (guideline 17): the name does not start with
      someone else's brand ("WordPress", "WP", "Woo"...).
- [ ] **Complete plugin** (guideline 16): nothing unfinished or placeholder.

### Submitting

1. `scripts/build-release.sh --ref vX.Y.Z` (a version already released on
   GitHub), then `scripts/preflight-release.sh --ref vX.Y.Z --strict` passes,
   or every warning is accepted.
2. `scripts/plugin-check.sh --ref vX.Y.Z` reports no errors; read every
   warning.
3. Test the WordPress.org zip on a clean site (latest WordPress and 6.2 with
   PHP 7.4), with `WP_DEBUG` on: activate, turn each feature on and off,
   deactivate, delete. `debug.log` stays empty.
4. Signed in as the owner's WordPress.org account, upload
   `dist/wordpress-org-seoprostack-X.Y.Z.zip` at
   [Add your plugin](https://wordpress.org/plugins/developers/add/), and ask
   for the `seoprostack` slug in the notes.
5. Review takes about 1 to 10 business days. Reply to the reviewer's email
   from the same account; fix issues in this repository, not in a copy.

### After approval (Subversion)

The SVN repository is `https://plugins.svn.wordpress.org/seoprostack/` (or the
slug given). It is for releases only, not development. The SVN password is
separate from the account password and is set on the WordPress.org profile
(see Using Subversion). Install Subversion first (`brew install subversion`).

1. Check out the empty repository: `svn co https://plugins.svn.wordpress.org/seoprostack/ svn-seoprostack`.
2. Unzip the WordPress.org build's `seoprostack/` folder into `trunk/` (no zip
   files in SVN), `svn add` new files, `svn rm` removed ones.
3. Copy trunk to the tag: `svn cp trunk tags/X.Y.Z`. `Stable tag:` in
   `trunk/readme.txt` and `tags/X.Y.Z/readme.txt` must be `X.Y.Z`; never
   `trunk`.
4. Put the banners, icons and screenshots in `assets/`.
5. `svn ci -m "Release X.Y.Z"`, then check the plugin page and download.
6. Consider release confirmation emails (Plugin Handbook → Release
   Confirmation Emails), so a release goes out only after it is confirmed.

Each later WordPress.org release: build from the tag that is already on GitHub,
`--strict` preflight, Plugin Check, then steps 2 to 5. Readme-only changes
(such as raising Tested up to) go to trunk and the current tag.

Once listed, sites with the GitHub build update from WordPress.org unless
**Early updates from GitHub** is on, which keeps them on GitHub releases
(`includes/features/class-seoprostack-github-updates.php`, which drives the
shared updater in `includes/github-updater/`).
