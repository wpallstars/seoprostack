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
- [x] **Assets** for the SVN `assets/` folder (not in the plugin zip):
      `banner-772x250` and `banner-1544x500` (PNG or JPG, up to 4 MB),
      `icon-128x128` and `icon-256x256` (PNG, JPG or GIF, up to 1 MB) and
      optionally `icon.svg`, and `screenshot-N` (PNG or JPG, up to 10 MB), with a
      `== Screenshots ==` section in `readme.txt` captioning each one. Keep the
      sources in the repository under `.wordpress-org/` (in `.distignore`).
      The banners are done: `.wordpress-org/banner-772x250.png` and
      `banner-1544x500.png`, built from `.wordpress-org/banner.svg` with
      `scripts/build-banner.sh` (which also writes the Read Me tab's
      `admin/images/banner.svg`). The icons are the banner's picture on its
      own, without words: `.wordpress-org/icon.svg`, exported by the same
      script as `icon-256x256.png` and `icon-128x128.png`. Screenshots 1–3
      are `.wordpress-org/screenshot-N.png`, captioned in `readme.txt`.

## WordPress.org: guideline review for this plugin

Reviewers read the code. Check each against the guidelines before submitting.
Reviewed on `main` at 0.13.0 plus #418 and #419 (GitHub issue #77):
`scripts/preflight-release.sh --strict` had 0 errors and only the
release-time warnings (Unreleased sections, slug, tag), and
`scripts/plugin-check.sh` had 0 errors and 0 warnings on the WordPress.org
zip. Check again after changes that touch an item.

- [x] **No code from elsewhere** (guideline 8): the WordPress.org zip has no
      GitHub updater, no `GitHub Plugin URI` headers and no other installer; Free
      Plugins installs only WordPress.org plugins through core. Preflight
      checks this.
- [x] **External services** (guidelines 6 and 7): every service the plugin
      contacts is in `readme.txt` → External services, with what is sent and
      when, and links to terms and privacy policy (WordPress.org API,
      screenshot services, Google Analytics). Preflight lists hosts in the code
      that the readme does not name: only google.com, facebook.com and
      trustpilot.com, the example destinations of starter short links, which
      are never contacted.
- [x] **Scripts and styles ship with the plugin** (guideline 8); Google's
      gtag.js is part of the Analytics service. Preflight: no scripts or
      styles loaded from other sites.
- [ ] **Links in the directories** (Pro Plugins, Hosting, Tools): link straight
      to the product. Affiliate links must be disclosed and not cloaked
      (guideline 12: "must directly link to the affiliate service, not a
      redirect or cloaked URL"); guideline 11 adds "tracking referrals via
      those ads is not permitted" for advertising in the dashboard.
      Disclosed in `readme.txt` → Affiliate disclosure. The WordPress.org
      build keeps the same links: `scripts/build-release.sh` only leaves out
      `.distignore-wporg` files and updater headers. Open, for the owner:
      Namecheap's link (`namecheap.pxf.io` in
      `admin/data/hosting-providers.php`) is a tracking redirect, not
      Namecheap's own domain, so it fails guideline 12 as written; and
      whether the referral codes (Liquid Web `irpid=` in
      `admin/data/pro-plugins.php` and `admin/partials/theme-panel.php`,
      `ref=`, `REFERRALCODE=`, `referral_code=` in Pro Plugins, Hosting and
      Tools) stay in the WordPress.org build, given guideline 11.
- [x] **Admin notices** (guideline 11): the plugin's own notices are
      contextual and dismissible; no promotions in the dashboard. Checked
      for 1.0.0: result notices appear once after an action and drop their
      query arguments (Replace media, Resize uploads, WebP and AVIF images,
      Watermark, Duplicate posts, Post versions, Term tools, Short links,
      presets, deleted-plugin clean-up). Replaced plugins shows only on the
      Plugins screen with a Hide link. The Rank Math pillar warning shows
      only on the selected post type's list and goes when fixed. The
      Developers note shows only on the settings screen it limits. Example
      notices show only when their setting is on.
- [x] **Defaults** (guideline 11 and the owner's rule): features safe on
      every site start on (`README.md` → Features lists them); those that
      need choices or change content, files, people or other plugins'
      settings stay off. Updates from GitHub, also on in GitHub builds, is
      not in this build. `readme.txt` → Description says so.
- [x] **Quiet Freemius and Appsero prompts** (guidelines 7, 9 and 11): Quiet
      Freemius uses the filters Freemius provides for this; Quiet Appsero
      removes the notice, deactivation-survey and theme-switch hooks from each
      Appsero Insights object, since Appsero has no filters. Neither stores
      anything in the other plugins (none is opted in or out); both keep
      licence, account and support features working, and switch off in one
      click. They only stop prompts that guideline 11 asks plugins to keep
      few, and they mean less data sent to Freemius and Appsero, not more. If
      a reviewer asks, offer to make them off by default.
- [x] **Files outside the plugin folder**: on by default, Load plugins only
      where needed writes a loader to `wp-content/mu-plugins`, and Fixes for
      other plugins a block at the top of the site's `.htaccess` on LiteSpeed
      servers (`# BEGIN SEO Pro Stack background requests`). Optional: Block
      web access to log and backup files (the site's `.htaccess`), WebP and
      AVIF images (uploads' `.htaccess`) and Watermark (an `.htaccess` that
      keeps its originals folder private). Each is removed when switched off
      or SEO Pro Stack is deactivated, except Watermark's, which stays with
      the originals. `readme.txt` → FAQ says so.
- [x] **Uninstall** removes every option, meta key, transient, cron hook and
      file the plugin adds (`uninstall.php`). The smoke test fails on options
      and cron hooks left behind; meta keys were checked by hand. Kept on
      purpose: `_seoprostack_source_url` on imported media, which stays, and
      the plugins moved by Network Plugins stay activated on each site.
- [x] **GPL** (guideline 1): all code and images GPL-compatible; credit any
      bundled third-party code in `readme.txt`. The only third-party files
      are the brand icons (`assets/brand-icons/`): Simple Icons (CC0) and
      Font Awesome Free brand icons (CC BY 4.0, GPLv3-compatible), credited
      in `assets/brand-icons/LICENSE.txt`, which ships, and in `readme.txt`.
- [x] **Name and trademarks** (guideline 17): the name does not start with
      someone else's brand ("WordPress", "WP", "Woo"...).
- [x] **Complete plugin** (guideline 16): nothing unfinished or placeholder
      (no TODO, FIXME or placeholder text in the shipped code).

Once listed, sites with the GitHub build update from WordPress.org unless
**Early updates from GitHub** is on, which keeps them on GitHub releases.
