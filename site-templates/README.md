# Site templates

No manifests ship yet. The agency's builder produces these files; SEO Pro
Stack only consumes them. A manifest is `site-templates/<slug>.json` and
its local pictures are `site-templates/<slug>/images/<filename>`.

## Format

JSON root fields:

- `slug` (lowercase letters, numbers and hyphens), `name`, `version` (string),
  `updated` (date), `notes` (text).
- `pages`, `elements`: ordered lists of objects with `key`, `title`, `slug`,
  optional `template` and `meta` (object), and `parts` (ordered list of
  `{ "key": "hero", "markup": "<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->" }`).
  Elements use Kadence Pro's `kadence_element` post type; inactive elements
  are skipped and reported. Keep all keys stable. Keys use lowercase letters,
  numbers, underscores and hyphens, at most 80 characters.
- `custom_css`: the template's leading CSS string. Include its own trailing
  newline. Everything following this exact base belongs to the site.
- `options`, `widgets`: objects mapping option names to scalar values or
  nested named-key objects. Widget instance options and `sidebars_widgets`
  use WordPress's own stored structure. Only named leaves are merged; other
  keys are retained. Do not ship credentials or activation/security settings.
- `theme_mods`: named-key tree for the active stylesheet only.
- `menus`: theme location => `{ "name": "Main", "items": { "contact":
  { "title": "Contact", "url": "/contact/" } } }`. Items are custom links;
  no existing site-owned menu is adopted or deleted.
- `images`: ordered list of `{ "key": "hero", "filename": "hero.webp",
  "sha256": "<64 lowercase hex digits>", "placeholder": true }`.
  Raster pictures only. A picture is imported once, with its attachment ID
  and hash recorded. An unchanged placeholder may advance to a new image:
  a new attachment is imported and only unedited parts take its URL. Original
  attachments are never altered or deleted. Once a site swaps the attachment,
  or an image is no longer a placeholder, that key stays with the site's image.
  `{{image:hero}}` in markup resolves to the recorded attachment URL.
- Forms: put the existing Form by key block or
  `[seoprostack_form key="contact" panel="0"]` in a Shortcode block. Reuse
  starter form keys through `SEOProStack_Form_Keys`; never embed numeric IDs
  or duplicate forms. Parts using these forms wait while Fluent Forms is inactive.

Manifests are limited to 2 MB and 500 resources. No remote files are fetched.
Use only trusted, reviewed manifests: block markup is intentional site content.

## Ownership and updates

`wp seoprostack site-templates apply <slug>` explicitly initialises a site.
Pages are never adopted over an existing slug. Each part gets a stable
Gutenberg Group wrapper (`sps-template-part-<key>`); keep that group while
editing. The per-site `seoprostack_site_template` option holds the slug,
applied version, post IDs and hashes, last result and last-run undo journal.
Ultimate Multisite copies this option with the site's tables; nothing is
cleared on clone.

An update changes a part only if its current serialised block still has
the hash recorded when written. Unknown site blocks, edited parts, removed
parts and unmentioned settings are kept. Removed manifest parts remain and
are reported, never deleted. New parts are appended without moving existing
site content. Titles, slugs and named metadata have their own ownership checks.

The Content setting **Update unedited template parts automatically** defaults
on; the normal network settings screen can control it, including individual
sites' settings. A site with no template record is never scheduled or changed.
On a plugin version change each site queues its own single cron event. Each
batch handles at most 20 resources or ten seconds between resources, saves a
cursor, and continues on the next event. One resource is bounded by the manifest
size. A changed manifest cannot replace an unfinished run: finish or undo first.
Errors stay in the record and are shown by `status`. Database connection-owned
locks exclude overlapping apply/undo runs and are released after a crash.
An interruption inside a resource write stops further applies until `undo`
restores the partial run; it never retries a potentially duplicated creation.
Interrupted undo must finish before another apply can begin. Normal completed
batches resume automatically. A no-change run keeps the preceding undo backup.

## Commands

- `wp seoprostack site-templates list`: shipped slugs and versions.
- `wp seoprostack site-templates status`: site record, last result and current parts.
- `wp seoprostack site-templates diff [<slug>]`: read-only comparison.
- `wp seoprostack site-templates apply [<slug>]`: apply/resume; a slug is
  required for the first run. Explicit CLI apply works with automatic updates off.
- `wp seoprostack site-templates undo`: restore the last run, refusing to
  overwrite edits made since that run. Newly created pages/elements/menu items
  are retained as drafts and imported attachments/menu containers are kept,
  never deleted. Previous content, named settings and the CSS remainder return.

All commands accept WordPress's global `--url=<site>` selector and
`--all-sites` on multisite. Network mode visits active sites sequentially in
batches of 50 and stops on the first error, restoring blog context. Apply/undo
in network mode skip sites without a record, even if a slug is supplied.
Run the explicit initial apply on a template site, not an entire network.

Uninstall removes ownership, journals, schedules and ownership metadata;
pages, elements, menus, imported pictures and the site's settings remain.
