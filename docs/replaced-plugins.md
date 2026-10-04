# Features that replace another plugin

Read this before adding a feature that replaces another plugin, or one
modelled on another plugin. `STANDARDS.md` → Structure has the shared rules
(`'replaces'`, importing settings in `migrate()`); these steps are SEO Pro
Stack's own.

1. Remove the plugin's slug from `admin/data/free-plugins.php`, with a
   comment saying which feature replaces it (`docs/plugin-directory.md`).
2. Add a row to `README.md` → Features table (replaced plugins) and to
   `README.md` → Credits: the plugin, its maker, its WordPress.org page, its
   maker's own source repository, and the feature. Check each link with
   `gh api repos/{owner}/{repo}` or the WordPress.org plugin API; never link
   a mirror or guess a URL. A feature modelled on another plugin gets the
   same Credits row.
3. Commit, then run `php scripts/replaced-plugins.php --write` to update the
   count and download sizes above the Features table, and commit that.
   `scripts/preflight-release.sh` fails when the count is out of date.
