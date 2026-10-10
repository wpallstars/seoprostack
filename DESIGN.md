# SEO Pro Stack design

## Colour mode

Only the settings screen from `SEOProStack_Admin_Manager::hook()` has a
per-person colour mode. Light is the default and leaves WordPress's admin
colours untouched. Dark uses the WordPress admin greys below; System follows
`prefers-color-scheme` live. The front end, editor and other admin screens
keep their own colours. This matches SEO Pro Stats' colour mode (#219).

An icon button after **Buy me a coffee** opens Light (sun), Dark (moon) and
System (half-filled circle), with a tick on the current choice. Menu items
use `menuitemradio`, arrow keys, Home, End and Escape; closing after selection
or Escape returns focus. Changes and failed saves are announced with
`wp.a11y.speak`. The header wraps at narrow widths; logical properties keep
the menu aligned in RTL. Its items have a 36px minimum height.

The choice is user meta `seoprostack_admin_theme`, saved with a nonce and
`read` capability, and removed on uninstall. `admin_head` at priority 1 sets
`sps-theme-{mode}` and `sps-dark` before first paint. All dark overrides require
`html.sps-dark`; `sps-themechange` on `document` carries `{ mode, dark }`.

`admin/css/seoprostack-theme.css` defines the palette once as `--sps-ui-*`:

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

Plugin `--sps-*` and WordPress `--wp-components-color-*` tokens read this
palette. Primary buttons keep the admin colour scheme's fill. Accent text
uses `color-mix(in srgb, var(--wp-admin-theme-color) 55%, #fff)` with a
`#72aee6` fallback. Classic element rules use `:where()` to preserve focus
and class rules; descriptions and table lists match core specificity.
Colours ease for 0.2 seconds after load, never with reduced motion.

## Banner and icon

The banner (`.wordpress-org/banner.svg`) and icon (`.wordpress-org/icon.svg`)
show a gold lightning bolt with a red edge and cream outline under the
wpallstars arc of five stars, on the shared navy background with red and
cream stripes. The bolt stands for speed and keeps SEO Pro Stack apart from
WP Plugin Starter, whose picture is a plugin stack. Keep the two pictures in
step and rebuild with `scripts/build-banner.sh`.

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
