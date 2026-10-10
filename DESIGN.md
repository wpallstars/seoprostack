# SEO Pro Stack design

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
