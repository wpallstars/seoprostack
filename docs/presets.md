# Plugin presets and starter data

Read this before adding or changing a file in `presets/` or `starters/`.

## Plugin presets

`presets/{plugin-folder}.json` holds SEO Pro Stack's chosen settings for other
plugins: the reference point the owner iterates on. Format and merge rules:
`includes/class-seoprostack-presets.php`. Users apply, reset and undo them on
the Plugins screen (Plugin presets, Plugins tab) or with
`wp seoprostack presets list|diff|apply|reset|undo`. These are the only places
SEO Pro Stack writes other plugins' settings, and only when someone asks.

To add or update a preset, on a throwaway site (never a live one):

1. Install the plugin's current version, activate it and do not configure it.
   Save `wp seoprostack presets export <folder> --like='<prefix>\_%'` as the
   fresh snapshot.
2. Choose the settings in the plugin's own screen, so its save code runs.
   Choose for speed, privacy (no calls to outside services that are not the
   point of the plugin), quiet admin screens (no dashboard boxes, promotions
   or emails that nobody reads), and the Kadence light and dark modes.
3. Run the export again with `--compare=<snapshot>`. Only changed settings
   are kept, with the fresh values as `defaults` (`null` means "not stored":
   the plugin's own default applies).
4. Remove what is not a preference: state (activation redirects, "tested",
   version and timestamp options, caches, counters), anything site-specific
   (addresses, emails, page or post IDs) and anything the export warned about.
   Secrets are left out automatically; never add them by hand.
5. Leave out settings that only work when saved through the plugin's screen
   (it schedules cron, writes files, calls a service or clears a cache kept
   under another name in that code; a cache under the option's own name is
   cleared for you). Read its save handler to check; say so in `notes` if users
   must save once in the plugin.
6. Write `notes` in plain words: what the preset does and why. Keep `tested`
   at the version you checked. Name every setting under `settings` for the
   Apply preset dialog: `label` as the plugin's screen words it, a short
   `description`, and `values` (stored value => what it shows, `"null"` for
   not stored, saying the plugin's default). Take them from the plugin's
    settings screen code; `scripts/preflight-release.sh` fails on a missing one.
    Keep 24 months of analytics or statistics data (owner's default). A number
    the plugin caps on each site (such as by plan) gets `limits` with the
    plugin's own filter, so the preset never writes more than the site allows.
    A plugin that expects all its settings once it stores any gets
    `"when": {"<option>": "option:<option>"}`, so a partial option is never
    written before the plugin saves its own. An option stored as a list of
    records (AI Engine's `mwai_chatbots`) gets `"records": {"<option>":
    "<key that names each record>"}`, and the preset names records by that
    key: never replace such a list whole. A Pro version in its own folder
    gets `"also": {"<folder>": "<name>"}` instead of a copy of the file.
7. Check on a second fresh site: `diff`, `apply`, confirm the plugin behaves
   as intended, `reset`, `undo`.
8. Add a changelog line in `README.md`, `changelog.txt` and `readme.txt`.

Owner's decisions for single presets:

- LiteSpeed Cache leaves CSS and JS Minify off (#285): without Combine,
  LiteSpeed Cache re-minifies every file on each page-cache miss
  (0.25–1.2 s per page on Hostinger), and Combine breaks pages. Don't turn
  them back on; `README.md` → LiteSpeed hosting has the numbers.
- Readabler and AI Engine keep their floating buttons in opposite corners
  (#577): Readabler bottom left, AI Engine's chatbot bubble bottom right,
  as the owner set them on a live site. Colours, chatbot names, instructions
  and models stay each site's own.

## Starter data

`starters/{plugin-folder}.json` holds starter data: the lists, tags, fields,
boards and forms the owner organises plugins' own tables with (FluentCRM,
Fluent Boards, Fluent Forms). Format: `includes/class-seoprostack-starters.php`.
Forms must work with only the free plugin: mark Pro-only fields with
`"when"`, and submit each form as a visitor on the throwaway site. Take it from the
owner's own sites, read-only (structure only: names, slugs, how they link;
never contacts, entries or messages), and write `notes` that explain the
pattern so people can extend it. Adding only fills what is missing; Remove
takes back only unused items SEO Pro Stack added. Check both on a throwaway
site with `wp seoprostack starters diff|add|remove`, with items in use.

## Presets and starter data together

A preset and starter data never set the same setting, so the order they are
used in never matters: Add starter data leaves stored settings alone and Apply
preset overwrites them. A setting goes in one or the other.
`scripts/preflight-plugin.sh` (run by `scripts/preflight-release.sh`) fails if
one is in both.
