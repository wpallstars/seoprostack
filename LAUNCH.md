# SEO Pro Stack launch state

Where SEO Pro Stack stands on going public and on WordPress.org. The steps
every plugin follows: `RELEASING.md` (releases and submitting) and
`DEVELOPMENT.md` → At public launch. Both need the owner's say.

## Public launch

Done ahead of launch (issue #218): history scan (no secrets; four false
positives allowed in `.gitleaks.toml`, the Fluent Forms field keys in
`starters/fluentform.json`), PHPStan baseline emptied, a Plugin URI of its
own, `readme.txt` headroom, and the community files.

## WordPress.org: to do before submitting (state at 0.5.0)

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

## WordPress.org: guideline review for this plugin

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
      items, No fade between admin screens, Quiet Freemius prompts and Fixes
      for other plugins are on after activation (Updates from GitHub, also on,
      is not in this build). Say so in the description, as now.
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

Once listed, sites with the GitHub build update from WordPress.org unless
**Early updates from GitHub** is on, which keeps them on GitHub releases.
