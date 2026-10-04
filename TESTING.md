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

## Really Simple Security redirect preset

Use two fresh, isolated single-site Apache/LiteSpeed WordPress sites with Really Simple Security 9.8.3, trusted working HTTPS and an existing writable root `.htaccess`. Do not use the shared preview or live sites.

- [ ] Export fresh `rsssl_options`: activation stores nothing; compare after selecting **Redirect method → 301 .htaccess redirect (read instructions first)** in the plugin's own screen. Confirm only `redirect` enters the preset, with accurate labels/defaults.
- [ ] Enable Plugin presets and use the Plugins-screen dialog; confirm Apply selected setting, Reset and Undo change the preference and dedicated **Really Simple Security Redirect** block together.
- [ ] Run `wp --user=<administrator> seoprostack presets list`, `diff really-simple-ssl`, `apply really-simple-ssl --only=rsssl_options.redirect`, `reset really-simple-ssl` and `undo really-simple-ssl`. CLI needs a plugin-recognised server type; use its existing server override only for a verified test server.
- [ ] A second Apply changes zero settings, keeps the undo snapshot and does not duplicate rules or probe HTTPS.
- [ ] Change an unrelated security setting after Apply, then Undo: the newer unrelated setting and unrelated `.htaccess` blocks survive.
- [ ] HTTP paths with query strings return 301 to the same HTTPS path/query. Certificate validation succeeds, redirects terminate and login/admin remain accessible.
- [ ] Missing/inactive plugin, unsupported version/API/server, multisite, SSL disabled, unwritable/symlinked rule file and plugin-managed write lockout cause no preference/rule mutation. Explicit actions return an explanation.
- [ ] Broken/untrusted HTTPS fails before mutation. A native rule-save failure returns an error, reports whether rollback completed and does not replace or consume the previous undo snapshot. Retry after resolving the native writer error.
- [ ] Inspect the standard PHP/debug log for new SEO Pro Stack or affected-plugin diagnostics; run existing PHP lint, PHPCS, PHPStan and release preset metadata checks.

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

## Modern admin colours

- [ ] Switch it on: every user sees the "Modern" scheme, and your profile is set to Modern.
- [ ] Switch it off: your profile goes back to the WordPress default; other users keep their own choice.

## Front-end features

For blocks, shortcodes and anything else a visitor sees (Term list, Sticky
posts, Restrict content, Short addresses), check with the Kadence theme in
light and dark mode (`STANDARDS.md` → Front-end styling and dark mode) and
with a second theme.
