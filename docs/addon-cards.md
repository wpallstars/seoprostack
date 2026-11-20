# Add-on setup cards

When a website add-on plugin is switched on for a site, its setup tasks are
added to that site's **Website Setup** board in Fluent Boards, so the site
owner, or their AI assistant, can set it up from plain steps. Delivering an
add-on is turning the plugin on; the cards are the guide.

| Add-on | Plugins | Starter |
|--------|---------|---------|
| CRM | `fluent-crm`, `fluentcampaign-pro` | `starters/fluent-crm.json` |
| Support Desk | `fluent-support`, `fluent-support-pro` | `starters/fluent-support.json` |
| Project Management | `fluent-boards`, `fluent-boards-pro` | `starters/fluent-boards.json` |
| Courses | `tutor`, `tutor-pro` | `starters/tutor.json` |

## How it works

- A card is a `fluentboards_tasks` item in the plugin's starter with
  `"addon": "<plugin folder>"` and the same folder as `"when"`. Its board
  is defined once, in `starters/fluent-boards.json`, and made from there if
  missing; the add-on's starter does not repeat it. Put a Pro plugin's cards
  in the same starter with the Pro plugin's folder.
- `SEOProStack_Plugin_Presets::ADDONS` maps each starter to its plugin files.
  `addon_cards()` runs on `admin_init` (priority 99) and on a single event
  that `activated_plugin` schedules. A plugin that Ultimate Multisite switches
  on (`force_active`) may not fire `activated_plugin`; it is in the site's
  active plugins on the next admin request, which is enough.
- It runs only for plugins in the site's active plugins (network-activated
  ones too), and only while Fluent Boards is running; until then it waits.
- `SEOProStack_Starters::add($slug, $folder)` adds only that add-on's cards
  and their board, never the rest of the starter (lists, tags, forms).
  A task already on the board (matched by title, archived ones too) is not
  added again.
- The per-site option `seoprostack_addon_cards` records each plugin file once
  its cards are on the board, so switching it off and on again adds nothing.
  A plugin whose cards would not add is tried three times.
- Cards are also added by hand from the plugin's row on the Plugins screen
  (**Add starter data**) or with `wp seoprostack starters add <folder>`, which
  adds the rest of the starter too; by hand they go on the Website Setup
  board only when it exists (add Fluent Boards' starter data first).

## Writing cards

Plain steps a non-developer or their AI can follow: what the add-on is for,
then numbered steps, then when it is done. Never ask for or store a password
or key in a task. Never send a customer's email through a vendor's hosted
relay (see `AGENTS.md`). After editing `starters/fluent-boards.json`, run
`node scripts/readme-setup-tasks.js`.

## Checking

- `bash scripts/preflight-plugin.sh .` checks the starters' JSON.
- On a disposable multisite, assign a membership with the CRM add-on to a
  site, then open that site's wp-admin (a forced activation may not fire
  `activated_plugin`, so the next admin request adds the cards): FluentCRM is
  active and the Website Setup board has the CRM tasks once; switching it off
  and on again, and opening wp-admin, adds nothing new.
