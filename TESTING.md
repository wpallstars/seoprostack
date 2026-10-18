# Testing SEO Pro Stack

`STANDARDS.md` → Testing and `DEVELOPMENT.md` → Checks cover what every
plugin made from the starter runs: `scripts/lint.sh`, the combined preview
site, `scripts/smoke-test.sh` (pages, query counts and `EXPLAIN` on 10,000
posts, with default settings and with every feature on),
`scripts/update-test.sh`, the release preflight and Plugin Check. This file
holds SEO Pro Stack's own manual checks: behaviour those scripts cannot see.

For every change, check the debug log afterwards. Load plugins only where
needed skips plugins on some requests, so messages from a skipped plugin are
SEO Pro Stack's to fix too.

## Delay automatic updates

Use a disposable LocalWP site, then a disposable multisite. Do not update or
uninstall plugins on the shared preview just to test the hold. Use an older
version of a small plugin and theme with an update available. Record the debug
log mark first; inspect new messages afterwards.

- [ ] The Security setting starts off, hours start at 72 and accept 0–336.
  Turn it on, choose 1 hour, leave Update canary off, and check for updates.
  `wp eval 'var_dump(apply_filters("auto_update_plugin", true, (object) array("plugin" => "<file>", "new_version" => "<v>", "slug" => "<slug>")));'`
  returns `false`. Use the actual plugin basename and offered version. Repeat
  with `auto_update_theme` and `theme` set to the theme stylesheet.
- [ ] Repeat update checks: `seoprostack_update_first_seen` keeps the original
  timestamp for that version. It does not autoload. On multisite the option
  is network-wide (`get_site_option`), not in each site's options.
- [ ] On this disposable site only, move the matching `plugin` or `theme`
  record (`<file>|<v>` or `<stylesheet>|<v>`) back more than an hour. The
  filter returns its original value (`true`, `false` or `null`), never
  turns a disabled update on, and the note disappears. A new version starts
  a new hold. Missing, corrupt or future timestamps start a fresh hold.
- [ ] Set 72 hours and verify recent releases stay held; set 0 and verify no
  hold. Turn the feature off and verify its filters are absent on a new
  request. With Remove WordPress extras' plugin/theme automatic updates
  disabled, WordPress's global disable filters still win.
- [ ] Turn Update canary on locally: no hold. On separate requests with
  `WP_ENVIRONMENT_TYPE` set to `development`, `staging` and `production`,
  verify the same saved value has no effect and the canary field is absent.
- [ ] Plugins and Dashboard → Updates show a readable hold deadline in the
  site's timezone. Check network Updates too. Manual updates from both screens
  and `wp plugin update <slug>` / `wp theme update <stylesheet>` still work.
  No `upgrader_pre_install` hook is added. MainWP updates remain outside scope.
- [ ] After installing an offered version, the next transient write prunes
  its record. Removing a plugin/theme prunes all its versions, without
  removing other installed items' records. Plugin and theme names cannot collide.
- [ ] Uninstall on disposable single-site and multisite removes
  `seoprostack_update_first_seen`; rolling back or switching off simply stops
  holding updates. Run `scripts/lint.sh phpstan` and `scripts/lint.sh`.

## Read-only health audits for several sites

On each host, list a network's sites with `wp site list --field=url`, then run
`wp --url=<url> seoprostack health --format=json` separately for each site.
Do not loop through a network with `switch_to_blog()`: its plugins and hooks
would still belong to the first site. Single installs need one run each.
Keep host names, folders and output naming sites out of issues and commits.

During an aidevops rollout, use `wp-fleet-helper.sh` with
`--audit-argv '["seoprostack","health","--fail-on=critical"]' --audit-read-only`.
A non-zero exit fails rollout verification. The command itself uses neither
`shell_exec()` nor `proc_open()` and needs no `/dev/fd`.

On disposable single-site and multisite sites (the images and setup from
`scripts/smoke-test.sh` and `scripts/smoke-test.sh --multisite`):

- Compare options before and after the command with
  `wp eval 'global $wpdb; echo md5(serialize($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name")));'`.
  On a network also compare the whole `sitemeta` table and each site's options.
  Repeat with no Hosting needs records, expired Hardening probes, an old
  settings version and pending network settings. No audit applies saving work.
- Compare the table with Site Health → Status. Open Site Health once on the
  preview site, then inspect the OPcache and memory results: their values must
  be web PHP's, with the recorded UTC time (older memory peaks have only a
  date), even when WP-CLI uses a much higher memory limit or OPcache is off.
  The OPcache option is not autoloaded, has no scripts list and is updated
  at most hourly. Missing web readings say `not_run`, not “OPcache is off”.
- Set `DISABLE_WP_CRON`, schedule a canary with
  `wp cron event schedule seoprostack_health_canary '2 hours ago'`, and confirm
  `seoprostack_cron` is critical and `--fail-on=critical` exits 1. Without
  `--fail-on` it exits 0. `--fail-on=recommended` exits 1 for either severity.
  Delete the canary afterwards: `wp cron event delete seoprostack_health_canary`.
- Check valid JSON (`tests` and `records`), plain text, no `ABSPATH` in output,
  no writes to options, transients, object cache, events or files, and the
  debug log. Uninstall removes `seoprostack_hosting_opcache` on every site.

## Timing pages on a live site

The smoke test measures queries on a fresh site. To measure a live site:

- Time uncached pages straight to the origin with `curl --resolve`, not from
  the hosting server through Cloudflare: those requests are held to 2.2 s
  whenever PHP takes 1.2–2.1 s, which visitors never see (#285).
- Compare with the feature on and off on the same page, a few times each,
  and with Query Monitor for the queries and HTTP requests it adds.

## Dark mode audit

`scripts/dark-audit.mjs` opens every screen of the admin menu in Dark on a
test site (Chromium through Playwright, at 1440 × 900) and lists, per screen,
the light surfaces (opaque backgrounds with relative luminance above 0.55
and more than 1500 px², brand colours marked) and the text below WCAG AA
against what is behind it. It reads the site and the login from environment
variables only; never commit their values or its output. The user's colour
mode must be Dark. Run it before and after a change to Dark mode
(`DESIGN.md` → Colour mode) and compare:

```bash
export SPS_AUDIT_URL=https://example.test SPS_AUDIT_USER=… SPS_AUDIT_PASS=…
# Playwright: installed globally, or PLAYWRIGHT_PATH=<its folder>
node scripts/dark-audit.mjs login
node scripts/dark-audit.mjs urls urls.txt
node scripts/dark-audit.mjs audit urls.txt before --shots   # report.json, summary.md, screenshots
node scripts/dark-audit.mjs audit urls.txt after --shots
node scripts/dark-audit.mjs compare before/report.json after/report.json
node scripts/dark-audit.mjs shot /wp-admin/edit.php one.png
```

Keep the output outside the repository (it holds the site's addresses).
`--from=N --to=N` audits part of the list. The first visit after a plugin
changes makes its dark copies, so audit twice after deploying, or open the
screens once first. To measure the fallback alone, run the before audit
with `add_filter('seoprostack_admin_dark_fallback', '__return_false');` in
a must-use plugin on the test site, and delete it for the after audit.
Light mode needs no audit: nothing loads there.

## Media in cloud storage

Use an isolated WordPress and MinIO container on the same Docker network, not the shared preview. Create a throwaway bucket with anonymous reading and set the documented constants in wp-config.php (API endpoint uses MinIO's container host and port; public URL uses its published address). Keep credentials out of logs. Size PHP as DEVELOPMENT.md says.

- [ ] Switch on; run `wp seoprostack media-storage test` (PUT, HEAD, DELETE), import three JPEG/PNG images, then `upload` and `status`. Confirm every size, kept original and registered WebP copy appears under the correct prefix. `curl -I` a public object including `?v=`: correct type, size and immutable cache header.
- [ ] Check a published post's image `src`, `srcset` and featured image, plus REST media `source_url` in view context. Include a Unicode filename: responsive candidates must work without changing stored metadata. Admin and REST edit remain local. Run upload again: no unchanged PUTs. Compare local file counts and checksums before and after.
- [ ] Enable WebP copies, watermark then convert: only marked WebP files are served; active watermark batches delay storage. Replace with Keep the file name: contents and version query change, including a rewrite with unchanged file size and modification time. Remove a size and delete an attachment: obsolete objects return 404 after the next batch.
- [ ] With Copy linked images on, save bucket URLs in content, links, excerpts and escaped image block attributes: stored addresses are local and no attachment is imported. Switch off: output local, upload/test refused and no API traffic; delete while off and re-enable to drain cleanup. Missing constants also make no requests.
- [ ] Exercise oversized files: `wp_get_original_image_url()` must keep an unuploaded kept original local and give a confirmed original its own versioned URL. Test a refused PUT and recovery with `--retry-failed`, and multisite `sites/{id}` keys and independent switches. Check the panel, attachment details, safe errors and debug log; credentials must not appear in HTML, options, meta or CLI output. Uninstall clears jobs and records, leaving bucket objects.
- [ ] Remove local copies after upload: save checksums of every uploads file, switch it on and run `upload`: only recorded files go (AVIF copies, unmarked originals stay). Pages, wp-admin, REST edit `source_url` and the editor's `content.raw` show the bucket; saving that content stores it unchanged. REST GET and attachment updates, and `wp eval`, bring nothing back; `wp media regenerate`, watermarking and conversion do, and the re-upload removes them again. Change one file: none of that picture's files go. A changed prefix or a missing constant: restore refuses and nothing changes; pages keep the old bucket address. `restore --all`, then switching removal or the feature off: checksums match the saved list.
- [ ] Run `scripts/lint.sh`, `scripts/smoke-test.sh --wp 6.2 --php 7.4` and `scripts/smoke-test.sh`. Before release the maintainer repeats public query-string delivery against Cloudflare R2.

## HTTPS and security headers (replacing Really Simple Security)

Use a throwaway single site with a trusted HTTPS certificate, Really Simple Security (free, or Pro) active with its redirect, headers and hardening switches on, and FluentAuth installed; then a multisite with it network-active. Not the shared preview or live sites. Size PHP as DEVELOPMENT.md says.

- [ ] Update to settings version 27 while it is active: HTTPS and security headers, Turn off unused remote access (Block PHP files in the uploads folder, Hide usernames from visitors and the others it had on), Tidy the login screen's login message, Tidy admin screens' file editors and Remove WordPress extras' version are switched on, and `rsssl_options` is unchanged. Choices already saved stay. The cards wait; the Plugins screen names only what it still does (its login protection, FluentAuth's job).
- [ ] Deactivate it. `curl -I http://…/path/?q=1` gives one 301 to `https://…/path/?q=1`; a POST, `/.well-known/acme-challenge/x` and WP-CLI are not redirected. Behind Cloudflare (Flexible or Full) and a proxy sending `X-Forwarded-Proto: https`, pages load without a loop.
- [ ] `curl -I https://…` on a page, wp-admin, wp-login.php and a feed: HSTS (one year; two with `includeSubDomains; preload` when chosen), nosniff, `X-Frame-Options: SAMEORIGIN`, `Content-Security-Policy: upgrade-insecure-requests; frame-ancestors 'self'`, Referrer-Policy and Cross-Origin-Opener-Policy, with no X-Powered-By and no header twice. `/embed/` has no frame headers. Each Leave out choice removes its part; a site whose address is `http://` gets nothing.
- [ ] `https://…/wp-content/uploads/test.php` gives 403 (Apache/LiteSpeed; copy the Nginx rule on Nginx); switching off removes the block. Logged out, `/wp-json/wp/v2/users`, `/wp-json/wp/v2/users/1` and `/?author=1` give 401, 401 and 404, and `/wp-sitemap.xml` has no users sitemap; an editor's author list in the block editor still works. A wrong username and a wrong password give the same message, and FluentAuth still counts and limits the attempts.
- [ ] Inspect the debug log for new SEO Pro Stack, Really Simple Security or FluentAuth messages. Run `scripts/lint.sh`, `scripts/smoke-test.sh --wp 6.2 --php 7.4` and `scripts/smoke-test.sh`.

## Developer admins

Use a throwaway single site with two administrators, `dev` and `client`, and a second plugin and theme installed. Not the shared preview site: switching this on there limits its other administrators.

- [ ] As `dev`, switch on Developer admins: only `dev` is ticked under Developers. Untick yourself and save: you are ticked again.
- [ ] As `client`, compare with `wp --user=client eval 'var_dump(current_user_can("install_plugins"));'`: Settings → SEO Pro Stack gives 403 and is not in the menu, the admin bar star and SEO Pro Stack's Plugins row are gone, Add New Plugin gives 403, other plugins have no Activate or Deactivate link, themes have no Activate button, Administrator is not offered in Add New User or Settings → General's New User Default Role, and `dev`'s profile cannot be edited.
- [ ] As `client`, Settings → General shows the addresses and administration email read-only with a note and still saves the site title; Settings → Reading shows search engine visibility read-only; Settings → Permalinks is read-only and a forced POST gives 403.
- [ ] Tick **Update WordPress…** and **Add unfiltered HTML…**: `client` loses the Update buttons and `unfiltered_html`; `dev` keeps everything.
- [ ] Demote `dev` to Editor with WP-CLI: `client` becomes a developer (nobody locked out). Promote `dev` again: `client` is limited again.
- [ ] With Organise the admin menu on, `client` does not see the Developers menu; `dev`'s **Preview the admin as › Client administrator** shows the same limits.
- [ ] Upgrade from 0.12.x with the menu organised and Client safeguards on: Developer admins is on with the code and administrator limits and the same developers.
- [ ] Check the debug log for new messages.

## Removed Modern admin colours (settings version 26)

- [ ] Upgrade a site with Modern admin colours on: the setting is gone from the Admin tab and from `seoprostack_options`, and each user sees the scheme saved in their profile (a user with Sunrise saved sees Sunrise).
- [ ] On WordPress 6.x with it on: users with no `admin_color` or with `fresh` get `modern`; users with another scheme, or with a `seoprostack_own_admin_color` mark, keep theirs, and the marks are removed. On WordPress 7.0 or later, or with it off, no `admin_color` is written.

## Front-end features

For blocks, shortcodes and anything else a visitor sees (Term list, Sticky
posts, Restrict content, Short addresses), check with the Kadence theme in
light and dark mode (`STANDARDS.md` → Front-end styling and dark mode) and
with a second theme.
