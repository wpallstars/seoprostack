# SEO Pro Stack design

## Colour mode

Every wp-admin screen has a per-person colour mode (`SEOProStack_Admin_Whole_Theme`).
Light is the default and keeps WordPress's admin colours; on SEO Pro Stack's
own screen only, the scheme colour's brightness is capped (Accent, below).
Dark gives every admin screen the WordPress admin greys below, while the
admin colour scheme (Modern, Default, Sunrise...) keeps its admin menu, admin
bar and accent. System follows `prefers-color-scheme` live. The front end and
the block editor's canvas keep the theme's colours. SEO Pro Stats' own
screens are left to its own colour mode, which follows the same choice.
Screens whose own design does not suit dark can stay light with the
`seoprostack_admin_dark_screen` filter. Tutor LMS's course builder, drawn by
script in fixed colours, is dark through the same recolouring as Tutor's
other screens (GitHub issue #830). The Customizer's controls pane
is dark (it prints its own page, so the mode's classes are set from
`customize_controls_print_styles`); its preview keeps the site's colours.

Every other plugin's screens are dark from their first load through the
fallback (below). Plugins whose screens need more get a section each in the
stylesheet, every selector
behind `html.sps-dark` and on the `--sps-ui-*` palette below. Brand-coloured
buttons, badges, logos and status dots keep their colours; only white and
grey surfaces, borders and dark text change. Plugins with a dark mode of
their own (Fluent plugins, LiteSpeed Cache, Bit Social, WP Sheet Editor's
spreadsheet) are not restyled: their mode is set to match from the head, so
their own dark design shows (FluentCRM's white primary buttons included).
Screens whose own script sets colours under hashed class names (Emotion, on
Tutor LMS) are recoloured by `admin/js/seoprostack-theme-scripted.js` in the
head (`seoprostack_admin_dark_script_styles`). Where a plugin dequeues other
stylesheets (Revive.so, at `admin_enqueue_scripts` 100), the theme
stylesheet is enqueued again at `PHP_INT_MAX`, only when it was registered
for Dark.

An icon in the admin bar (`seoprostack-colour-mode`, pinned just left of the
account menu and right of the star) shows the mode: sun, moon or half-filled
circle. A click turns Dark on or off; its menu offers Light, Dark and System,
with a tick on the current choice. Menu items use `menuitemradio`; arrow keys
open and move through the menu, Home and End jump, Escape closes it and
returns focus. Changes and failed saves are announced with `wp.a11y.speak`.
Without the script, the links save through `admin-post.php` and come back. It
shows in wp-admin only, not on the site or in the network admin, to people
for whom `wpallstars_admin_theme_participants` is not empty.

The choice is shared with SEO Pro Stats (wpallstars/seoprostats#251): user meta
`wpallstars_admin_theme` is read first, then `seoprostack_admin_theme`, then
Light; both are saved with a nonce and `read` capability. A saved change is
sent as `wpallstars-admin-theme` (`{ mode, source }`) on `document`, and the
other plugin's changes are applied without saving or sending again. Uninstall
removes `seoprostack_admin_theme`, and `wpallstars_admin_theme` only when no
other wpallstars plugin is installed. `admin_head` at priority 0 sets `sps-theme-{mode}`
and `sps-dark` before first paint. All dark overrides require
`html.sps-dark`; `sps-themechange` on `document` carries `{ mode, dark }`. The
whole-admin stylesheet loads only while dark or in System mode; the
script loads it on the first switch to dark.

The shared header uses `SEOProStack_Admin_Theme` and the starter's
`admin/css/seoprostack-theme.css` / `admin/js/seoprostack-theme.js` on settings,
network settings and own screens. Whole-admin rules stay off these screens.
The two controls share the saved choice; separate AJAX actions, asset handles
and script globals prevent one control from overwriting the other's data.

`admin/css/seoprostack-whole-theme.css` defines the whole-admin palette once as `--sps-ui-*`:

| Token | Dark value |
| --- | --- |
| canvas | `#101517` |
| surface | `#1d2327` |
| raised | `#2c3338` |
| border | `#3c434a` |
| field-border | `#8c8f94` |
| text | `#f0f0f1` |
| muted | `#c3c4c7` |
| subtle | `#a7aaad` |
| good | `#68de7c` |
| bad | `#ff8085` |
| warning | `#f0c33c` |

Plugin `--sps-*`, WordPress `--wp-components-color-*` and design-token
`--wpds-color-*` variables read this palette. The block editor's theme
provider sets the tokens' light values inline on its wrappers, so the dark
values are set there too with `!important`. Classic admin styles paint fixed
light colours, so core screens (Dashboard, lists, Plugins, comments, terms,
editors, media, Site Health, menus) get matching dark rules; broad element
rules use `:where()` so class and focus rules win, and descriptions and
table lists match core specificity. WordPress's snake_case IDs
(`dashboard_right_now`, `TB_window`) are matched as `[id="..."]`. Colours
ease for 0.2 seconds after load, never with reduced motion.

### Fallback for other plugins

`SEOProStack_Dark_Fallback` makes every other plugin's and theme's screens
dark without a hand-written section. In Dark, each stylesheet from
wp-content (not WordPress's own, not SEO Pro Stack's, not the plugins with a
dark mode of their own) gets a copy holding only its rules that paint a
light surface, a light border or dark grey text, with the palette's colours:
white to `surface`, light greys to `canvas`, mid greys to `raised`, light
tints to a dark tint of the same hue, grey borders to `border`, near-black
text to `text`, dark grey to `muted`. Saturated brand colours, images,
faint overlays and coloured text stay; text on a ground the copy keeps (a
brand button) stays too. The copy loads right after its stylesheet with the
original selectors behind `:where(html.sps-dark)`, so it wins over the light
rule it repeats at the same specificity and loses to the hand-written
sections, which load later. In the block editor the copies leave out
`.editor-styles-wrapper`, so the canvas keeps the theme's colours.

Copies are cached in `uploads/seoprostack-dark`, one per stylesheet, and made
again when the file changes; a screen spends at most 0.15 seconds making
missing ones and leaves the rest to the end of the request. Filters:
`seoprostack_admin_dark_fallback` (per screen) and
`seoprostack_admin_dark_fallback_style` (per stylesheet). Not reached:
inline `style` attributes, `<style>` blocks, colours set by script,
stylesheets from other hosts, and screens whose script imports the other
stylesheets into a cascade layer (Bit Flows, Bit Social), where the
plugin's unlayered rules win; the contrast guard still lightens their
unreadable text, and screens that need more get a hand-written section.

`scripts/dark-audit.mjs` checks the result (TESTING.md → Dark mode audit).
Measured when it was added (GitHub issue #712), on the shared preview site
with every recommended plugin: 272 admin menu screens, 266 of them dark
(the other 6 keep their own look on purpose, such as SEO Pro Stats and
Tutor LMS's course builder). Without the fallback: 227 light surfaces, 218
of them not brand colours, 19 full-width. With it: 32 (86% fewer), 23 not
brand colours, none full-width; text below AA went from 24 to 19. What is
left is mostly drawn by script: Social Engine's (Meow Apps)
styled-components panels, FluentCommunity's first-run wizard, Element Plus inputs and
buttons on Fluent screens, a React Select field, plus a few small notices
and tiles; brand-coloured badges (EventON, Complianz) keep their colours.

### Accent

The accent follows the person's admin colour scheme (Profile → Admin Color
Scheme), as on SEO Pro Stats' screens (its issues #228 and #239). WordPress
sets the scheme's `--wp-admin-theme-color` on `body` (`body.admin-color-*`),
so the accent tokens sit on `body` too: on `html` they would resolve to the
default blue, whatever the scheme. A live change of the body class recolours
the screen at once.

- **Dark** (every admin screen): the logo and switches keep the scheme's
  colour as a fill (`--sps-ui-accent-fill`). Primary buttons are darkened to
  at most OKLCH lightness 0.53 (`--sps-ui-primary`, also
  `--wp-components-color-accent`), so their light labels read at 4.5:1 on
  lighter fills such as Sunrise's. Accent text, links, tabs and outlines keep
  its hue at a lighter tone, `oklch(from accent max(l, 0.75) min(c, 0.15) h)`,
  which reads at 5.4:1 or more on every dark surface. Older browsers use
  `color-mix(accent 55%, #fff)`, then `#72aee6`. Menu items' focus ring and
  external links take it too.
- **Light** (SEO Pro Stack's own screen, `body.sps-own-screen`): the scheme's
  colour keeps its hue, with its luminance lowered to at most 0.15 (0.12 and
  0.096 for the darker tones), so it reads at 4.6:1 or more on `#f0f0f0` and
  behind white labels. Modern and Ectoplasm are under the cap and unchanged;
  Sunrise `#ad631e` becomes `#a05b1b`. The capped colour replaces
  `--wp-admin-theme-color` inside `body`, so WordPress's buttons, fields and
  components take it too. The classic schemes' bright link hover and focus
  (`#0096dd`, 3.3:1) become `#005e8e`. Older browsers keep the scheme's
  colour. Other screens keep WordPress's own light look. These rules live in
  `admin/css/seoprostack-tabs.css`, loaded on the settings screen independently
  of the shared starter palette and the whole-admin stylesheet.

## Banner and icon

Every wpallstars plugin's banner and icon share one picture: the stack of
tiles under the arc of five stars, on the navy background with red and cream
stripes. Each plugin varies only the tiles' colours and the picture on the
top tile (WP Plugin Starter: all red with the WordPress Plugins icon; SEO Pro
Stats: all red with rising bars). SEO Pro Stack's banner
(`.wordpress-org/banner.svg`) and icon (`.wordpress-org/icon.svg`) have red,
cream and blue tiles, blue stars and a blue glow, and a gold lightning bolt
(for speed) laid flat and slightly raised on the top tile. Keep the stack;
change only the colours or the top tile's picture. Draw the top tile's
picture on the tile's isometric axes, as the starter's Plugins icon is: in a
20-unit space through `matrix(4.95 2.871 -4.95 2.871 1214 -57.42)`, so its
straight edges run parallel to the tile's edges; squashing it straight down
is not enough. Keep the two files in step and rebuild with
`scripts/build-banner.sh`.

## Theme colours

Follow the site's theme, not the visitor's system colour preference. Use
`currentColor` for inline icons. Kadence Pro changes its palette on `body`
with `color-switch-light` and `color-switch-dark`; no script or separate
dark icon is needed. Shared styling rules are in `STANDARDS.md`.

WP Grid Builder colours is a default-on Content feature, restricted to active
WP Grid Builder on Kadence (child themes included). Append colour overrides
through WPGB's inline-style filter, so pages without its styles add nothing.
Scope to `body.color-switch-dark`: light mode keeps the authored card design.
Surfaces use palette 9, borders 7, primary scheme text 3/4, secondary text 5
(not decorative palette 6), and accent text 1. Facet inputs, placeholders and
combobox options use the same surface/text pair. Read variables at the element,
never on `:root`. Leave layout, media images, animations and interactions alone;
responsive breakpoints remain WPGB's. Palette choices still need a contrast
check on the real grid; arbitrary site palettes cannot guarantee WCAG ratios.

Fluent Forms placeholders, including a multi-select's search box, are the
input's palette 4 text mixed 75% with palette 9, the field, at full opacity:
readable (about 5.5:1 on Kadence's light palette, 9:1 on its dark one) yet
visibly lighter than typed text, so an empty field is not taken for a filled
one. Never give placeholders the typed text's own colour. Do not start from
decorative palette 6: alone it is 4.02:1 on white. Without `color-mix()`
support the placeholder falls back to muted palette 5 (7.53:1 on Kadence's
white; `#606266`, 6.11:1, without the palette).
Check the site's actual light and dark palettes; custom colours still need
verification.

## Settings panels

A feature's Options panel shows its status and the choices people need.
Bypasses for plugins or themes the feature does not handle go last, in the
closed **Troubleshooting** section (`'group' => 'troubleshooting'`), which
opens by itself when one of them is in use, so a saved choice is never
hidden. Their descriptions name the problem each one fixes. The section uses
the shared `--sps-*` colours, so it follows every admin colour scheme.

## External link icons

`includes/features/class-seoprostack-external-links.php` adds a small
decorative square-and-arrow after external text links, sized at 0.75em with
0.2em logical spacing. Inherit the link's normal, hover and focus colours.
Do not change the link label, target, relationship or underline. Prefer
this to an icon font, downloaded picture or theme-switch listener.

Keep image links, buttons and links with existing inline SVG/icons clear.
The `sps-no-external-icon` class opts out a link or a whole content section.
Unsupported browsers omit the decoration and keep functional links.

## Discover directory links

Keep the existing cards and button groups. Add verified products to their
existing categories, with an actual WordPress.org `free_slug` only when a
free version exists. Free cards and All rows inherit the primary Pro URL
from `admin/data/pro-plugins.php`; do not add a second referral renderer.
Kadence and Fluent/WPManageNinja vendor links keep their product paths and
put referral queries before pricing fragments. WordPress.org install and
details links and GitHub source links remain direct. Theme navigation uses
the same Kadence referral query as the Pro directory, including bundles.

FlyingPress, Link Whisper and the Fluent ThriveCart, MailPoet and Mautic
connectors are deliberately omitted from recommendations. Keep other
relevant Fluent/WPManageNinja free products in their existing categories.
Fluent Query Logger uses Debug and the existing occasional-use note in
both cards and All rows, plus its July 2022 compatibility warning. Do not
invent a Pro version for a free-only product or add legacy vendor plugins
just to fill the directory.

## Responsive behaviour

Inline decoration scales with text on desktop and mobile, including zoom,
uses logical spacing for RTL text and adds no animation or interaction.
It does not create a separate touch target. Verify desktop and mobile
content in both Kadence palettes and keyboard focus.

## Linking tools

Keep the toolkit in the native WordPress **Tools → Links** screen, with clear
Pages, Suggestions, Link health, Click events and Retire Link Whisper tabs.
Use WordPress buttons, notices and striped tables rather than a separate app
shell. Scoped styles load only on this screen; no new public styling is needed.
Tables retain named headers on desktop and labelled cells on narrow screens.
Navigation wraps, long addresses break without overflow, and spacing is logical
for RTL. Actions must remain usable by keyboard and at mobile widths.

Separate evidence from actions: show suggestion context and an explicit approve
button; unsupported content gets a manual-edit message. Explain incomplete scans,
approximate click events and unknown retirement checks without implying safety.
Health checks and click counts are independent off-by-default choices. Never
make a retirement report into a deactivation button or consent claim.
