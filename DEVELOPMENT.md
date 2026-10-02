# Developing SEO Pro Stack

How changes are made, checked and released. Rules for features, presets,
code and styling: `AGENTS.md`. Releases: `RELEASING.md`. Manual test
checklists: `TESTING.md`.

## Workflow

1. Open or pick an issue that says what should change and how to check it.
2. Work on a branch in its own worktree, never on `main`. Branch names:
   `feature/…`, `bugfix/…`, `chore/…`.
3. Commit small, working steps. Run `scripts/lint.sh` before pushing.
4. Push and open a pull request (`Resolves #N`). Open it as a draft while
   the work is in progress: CI lints every push, and the longer release and
   smoke-test jobs start when the pull request is marked ready for review.
5. Check the change on the shared preview site (`scripts/preview-site.sh`,
   `AGENTS.md` → Testing), in light and dark mode for front-end styles.
6. Merge once CI passes. Releases are separate: `RELEASING.md`.

## Set up

Needs PHP 7.4 or later, Composer 2, Node.js (syntax checks only),
ShellCheck and Docker (release checks and smoke test). actionlint is
optional locally; CI runs it.

```bash
composer install
```

`composer.json` lists development tools only. The plugin has no Composer
dependencies, and `vendor/`, `composer.*`, the tool configuration and
`.github/` are left out of release zips (`.distignore`; the preflight fails
if one gets in).

## Checks

Every pull request and every push to `main` runs these in GitHub Actions
(`.github/workflows/ci.yml`). Each one runs the same way locally.

| Check | Command | What it finds |
| --- | --- | --- |
| PHP syntax | `scripts/lint.sh php` | Syntax errors; CI uses PHP 7.4, the minimum. |
| JavaScript syntax | `scripts/lint.sh js` | Syntax errors (`node --check`). |
| Shell scripts | `scripts/lint.sh shell` | ShellCheck findings in `scripts/`. |
| Workflows | `scripts/lint.sh workflows` | actionlint findings in `.github/workflows/`. |
| Coding standards | `scripts/lint.sh phpcs` | WordPress Coding Standards: escaping, sanitising, nonces, prepared SQL, i18n, PHP 7.4 and WordPress 6.2 compatibility (`phpcs.xml.dist`). |
| Static analysis | `scripts/lint.sh phpstan` | Unknown functions, classes and methods, wrong argument counts and types, dead code (PHPStan level 5, `phpstan.neon.dist`). |
| Release build | `scripts/preflight-release.sh --offline` | Versions, headers, `readme.txt`, presets and the contents of both zips. |
| Plugin Check | `scripts/plugin-check.sh` | The WordPress.org review tool, on both zips. |
| Smoke test | `scripts/smoke-test.sh --wp 6.2 --php 7.4` and `scripts/smoke-test.sh` | Installs the GitHub zip, loads the site and admin screens with default settings and with every feature on, runs cron, uninstalls. Fails on any PHP message, a failed page or leftover options. |

`scripts/lint.sh` with no arguments runs the first six.

### Coding standards

`phpcs.xml.dist` uses the WordPress ruleset. The plugin's own style
differs from it in a few places (spacing, `array()`, file names, Yoda
conditions), and those sniffs are off; the comments in the file say why.
Security, database and compatibility sniffs stay on. Fix findings in the
code. Where a finding is intended, add an inline
`// phpcs:ignore Sniff.Name -- reason` on that line only.

`vendor/bin/phpcbf` fixes what it can automatically.

### Static analysis

PHPStan reads the code with WordPress's stubs and PHP 7.4's functions.
`scripts/phpstan-bootstrap.php` defines the constants WordPress and the
plugin set while loading. Classes and functions of other plugins (WP-CLI,
Fluent, Freemius, WooCommerce, Kadence) are ignored in `phpstan.neon.dist`,
because the code uses them only after checking they are loaded.

`phpstan-baseline.neon` lists findings that were in the code when PHPStan
was added. They do not fail the check; new findings do. Most are the
stubs being stricter than WordPress (custom `wp_hash()` schemes,
`wp_register_script()` with no file) or checks kept for older WordPress
versions. When you change code with a baseline entry, fix it and run
`composer baseline` so the list shrinks. Never add entries to get a
change through.

### Smoke test

`scripts/smoke-test.sh` starts a throwaway WordPress in Docker (MariaDB,
Apache and WP-CLI images for the chosen PHP version), so nothing touches
the shared preview site. CI runs it on WordPress 6.2 with PHP 7.4 and on
the latest WordPress with PHP 8.3. Every feature is switched on at once,
except maintenance mode (it would answer every visitor page with its
notice). `--keep-log FILE` saves `debug.log`; CI keeps it as an artifact
when the test fails.

## Dependencies

Dependabot (`.github/dependabot.yml`) opens one pull request a week for
the GitHub Actions and one for the Composer tools. Actions are pinned to
commit SHAs with the version in a comment; keep it that way when editing
workflows. Do not add third-party actions that only save a few lines of
shell.

## CI cost

The repository is private, so Actions minutes count. Draft pull requests
run only the lint job. A new push cancels the run for the previous one.
The build zips and Plugin Check reports are kept for seven days on each
run (artifact `seoprostack-build-…`) for testing a branch on a site.
