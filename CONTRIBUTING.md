# Contributing to SEO Pro Stack

Thank you for helping. Bug reports, fixes and ideas are all welcome.

## Reporting a problem

Open an issue with the **Bug report** form. Include the versions, the steps
and any messages from `wp-content/debug.log`. Security problems go through
`SECURITY.md`, never a public issue.

## Suggesting a feature

Open an issue with the **Feature request** form before writing code. SEO Pro
Stack makes choices for its users, so a new setting has to earn its place:
say what the feature does on its own and what problem it solves.

## Pull requests

1. Read `AGENTS.md` (how features, settings, migrations and front-end
   styles work here, and the code rules) and `DEVELOPMENT.md` (set-up and
   checks).
2. Keep each pull request to one change. Features are off by default.
3. Run `scripts/lint.sh` and fix what it finds in the code.
4. Test on a real WordPress site, including WordPress 6.2 with PHP 7.4 if
   you use a core function, and Kadence light and dark mode if you change
   front-end styles.
5. Add a line to the changelogs: `README.md` (details), `changelog.txt`
   (for users) and `readme.txt` (one short line).

CI runs the checks on every pull request. Write the pull request description
so a reviewer knows what changed, why, and how you tested it.

## Licence

SEO Pro Stack is GPL-2.0-or-later. By contributing, you agree your work is
released under the same licence.
