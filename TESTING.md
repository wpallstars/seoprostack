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

## Timing pages on a live site

The smoke test measures queries on a fresh site. To measure a live site:

- Time uncached pages straight to the origin with `curl --resolve`, not from
  the hosting server through Cloudflare: those requests are held to 2.2 s
  whenever PHP takes 1.2–2.1 s, which visitors never see (#285).
- Compare with the feature on and off on the same page, a few times each,
  and with Query Monitor for the queries and HTTP requests it adds.

## Media in cloud storage

Use an isolated WordPress and MinIO container on the same Docker network, not the shared preview. Create a throwaway bucket with anonymous reading and set the documented constants in wp-config.php (API endpoint uses MinIO's container host and port; public URL uses its published address). Keep credentials out of logs. Size PHP as DEVELOPMENT.md says.

- [ ] Switch on; run `wp seoprostack media-storage test` (PUT, HEAD, DELETE), import three JPEG/PNG images, then `upload` and `status`. Confirm every size, kept original and registered WebP copy appears under the correct prefix. `curl -I` a public object including `?v=`: correct type, size and immutable cache header.
- [ ] Check a published post's image `src`, `srcset` and featured image, plus REST media `source_url` in view context. Include a Unicode filename: responsive candidates must work without changing stored metadata. Admin and REST edit remain local. Run upload again: no unchanged PUTs. Compare local file counts and checksums before and after.
- [ ] Enable WebP copies, watermark then convert: only marked WebP files are served; active watermark batches delay storage. Replace with Keep the file name: contents and version query change, including a rewrite with unchanged file size and modification time. Remove a size and delete an attachment: obsolete objects return 404 after the next batch.
- [ ] With Copy linked images on, save bucket URLs in content, links, excerpts and escaped image block attributes: stored addresses are local and no attachment is imported. Switch off: output local, upload/test refused and no API traffic; delete while off and re-enable to drain cleanup. Missing constants also make no requests.
- [ ] Exercise oversized files: `wp_get_original_image_url()` must keep an unuploaded kept original local and give a confirmed original its own versioned URL. Test a refused PUT and recovery with `--retry-failed`, and multisite `sites/{id}` keys and independent switches. Check the panel, attachment details, safe errors and debug log; credentials must not appear in HTML, options, meta or CLI output. Uninstall clears jobs and records, leaving bucket objects.
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
