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

`phpstan-baseline.neon` is empty: every finding fails the check. Fix the
code. Where PHPStan or the stubs are wrong (a custom `wp_hash()` scheme, a
check for a method newer WordPress versions have, variables a closure
changes by reference), add an entry under `ignoreErrors` in
`phpstan.neon.dist` with the identifier, the file and the reason. Never put
findings in the baseline to get a change through.

### Secrets in history

Scan the whole Git history before the repository goes public, and after
importing code from elsewhere:

```bash
docker run --rm -v "$PWD:/repo:ro" ghcr.io/gitleaks/gitleaks:latest git /repo --redact --no-banner
```

In a linked worktree, also mount the main repository's `.git` folder at
the same path. `.gitleaks.toml` lists the false positives with reasons (the
Fluent Forms field keys in `starters/fluentform.json`). A real secret is
rotated first, then removed from history.

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

## While private: checks are advisory

The repository is private until it is released, so Actions minutes cost
money and most code-review services are paid. Until then, work moves fast:

- No branch protection or required checks on `main`. A failing check does
  not block a merge, so a merge never waits on an unrelated failure.
- Fix any failure your change causes before merging. For a failure your
  change did not cause, open an issue with the run link and the first error,
  and merge anyway.
- Draft pull requests run only the lint job. A new push cancels the run for
  the previous one.
- The build zips and Plugin Check reports are kept for seven days on each
  run (artifact `seoprostack-build-…`) for testing a branch on a site.
- Review apps that are already installed (CodeRabbit, qlty, Socket) give
  advice only. A rate-limited or missing review never holds up a merge.

## At public launch: full sweep

Making the repository public needs the owner's say. Do this sweep in the
same step, while the code-review services are free for
public repositories. It goes through the whole codebase once, then keeps
it at that standard:

1. Turn on the free reviewers for the whole codebase, not just new
   changes: CodeRabbit full review, Codacy, SonarCloud (SonarQube Cloud)
   and qlty, plus GitHub's CodeQL (PHP and JavaScript), Dependabot
   security alerts, secret scanning with push protection, and OpenSSF
   Scorecard. Socket keeps checking dependencies.
2. Fix what they find in the code, in small pull requests by area
   (security first). Each finding is either fixed, explained in an inline
   comment, or marked as a false positive in that service with the reason.
3. Raise the PHPStan level one step at a time (6, then higher if the
   findings are real bugs and not noise). The baseline is already empty.
4. Require the CI checks on `main` (Lint, Release build, both Smoke
   tests) with a branch ruleset, without "branch must be up to date": the
   checks are fast, and changelog lines conflict on every merge.
5. Turn on private vulnerability reporting (Settings → Security), which
   `SECURITY.md` asks reporters to use, and add the CI badge to
   `README.md`. `SECURITY.md`, `CONTRIBUTING.md` and the issue and pull
   request templates are already in place.
6. Run `workflows/public-launch-checklist.md` from the AI DevOps framework
   for anything public: no private paths, site names or secrets in the code,
   history, issues or docs. Run the history scan above again for commits
   made since the last one.

Done ahead of launch (issue #218): history scan (no secrets; four false
positives allowed in `.gitleaks.toml`), PHPStan baseline emptied, a
Plugin URI of its own, `readme.txt` headroom, and the community files.
