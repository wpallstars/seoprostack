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
- `boot()` returns early unless `self::enabled()`. Features are **off by default**.
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

## Testing

No automated suite ships with the plugin. Verify on real WordPress:

1. `php -l` every PHP file and `node --check` every JS file.
2. Copy the worktree you are working in into a local test site. Use this
   instead of Git hooks: hooks are shared by every worktree and would deploy
   the wrong checkout.

   ```bash
   rsync -a --delete --delete-excluded --exclude-from=.distignore ./ "<site>/wp-content/plugins/seoprostack/"
   ```

   The site then holds exactly what a release build contains.

3. Exercise the changed feature through the admin UI or HTTP, and check
   `wp-content/debug.log`. For settings imports, seed the replaced plugin's
   options and delete `seoprostack_options` and `seoprostack_db_version` while
   the plugin is inactive, then activate it.
4. For changes that touch core APIs, also smoke-test on WordPress 6.2 with
   PHP 7.4, for example the `wordpress:php7.4-apache` Docker image with
   `wp core download --version=6.2 --force`.

Note: since WordPress 5.6, posts restored from the Bin become drafts. Republish
test posts after bulk-trash tests.

## Release build

`.distignore` lists files kept out of the release zip. Add new development-only
files there, then check the build with Plugin Check.
