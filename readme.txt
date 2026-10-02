=== SEO Pro Stack ===
Contributors: wpallstars
Tags: magic login, iframe, schedule posts, auto upload images, admin
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Curated plugins, themes, hosting and tools for WordPress, plus small opt-in quality-of-life features.

== Description ==

SEO Pro Stack is free and open source. There is no pro version and nothing is locked.

Everything is off by default except Hide admin bar items (Comments and + New), No fade between admin screens, Quiet Freemius prompts and Fixes for other plugins. Turn features on in **Settings → SEO Pro Stack**; **Search features** finds one by name or by the plugin it replaces. The Read Me tab there describes each in full.

= Admin screens =

* **Organise the admin menu**: the same menu sections on every site, with role previews and client safeguards. Replaces Admin Menu Editor.
* **Tidy the dashboard** and **Dashboard and sidebar widgets**: no Dashboard boxes or widgets you never use.
* **Hide admin bar items** (on by default), a **More menu** for plugin items in the admin bar, and **Hide admin notices**, which moves plugin and theme notices behind a bell.
* **Quiet Freemius prompts** (on by default): no opt-in nags, upgrade offers or deactivation surveys from plugins that use Freemius.
* **Admin bar and dashboard access**: hide the admin bar and block wp-admin for roles such as subscribers and customers, never administrators.
* **Magic login links**: “Email me a login link” on the login screen, with one-time links that email scanners cannot use up.
* **Turn off unused remote access**: XML-RPC and application passwords, both off by default.
* **Modern admin colours**, **No fade between admin screens** (on by default), **Readable list columns** and **Notification emails**, which stops routine emails one by one.
* **Tidy admin screens**, **Tidy WooCommerce admin**, **Tidy the login screen**, **Simpler block editor**, **Remove WordPress extras**, **Fewer Heartbeat requests**, **Limit post revisions** and **Lighter WooCommerce pages** replace most of Disable Bloat.

= Writing and publishing =

* **Publishing queue**: posts published without a date are scheduled for the next free time slot.
* **Duplicate posts**, **Staged new versions** (edit a published post as a draft copy) and **Shareable preview links** for people without an account.
* **Sticky posts for any post type** and **Select all across pages** for bulk actions.
* **Menu item visibility** by login or role, **Change post type**, **Order by hand** and **Term tools** (merge, move, set parent).
* **TranslatePress colours**: Kadence light/dark switchers.
* **iFrame block**: embed pages with size, sandbox and permission settings, limited to the domains and roles you choose.
* **Spectra block replacements**: a Term list block, and Spectra pages that keep working after it is deactivated or convert in one click.

= Media =

* **Copy linked images to Media Library** when a post is saved, and **Paste into the Media Library**.
* **SVG uploads** for chosen roles, cleaned as they are uploaded.
* **Resize large uploads**, **Replace media files** and **Watermark pictures**, which keeps unmarked originals.
* **WebP and AVIF images**: smaller copies sent to browsers that support them, at the same address.
* **Website screenshots**: a Screenshot block and the `[browser-shot]` shortcode, with pictures saved to the Media Library.
* **Avatars without Gravatar**: avatars served from your own site.

= Links, speed and plugins =

* **Short links** such as /go/offer/, with redirects, categories and click counts, and **Short addresses for custom post types**. **410 Gone** for removed pages.
* **Speed**: load pages before the click, delay chosen scripts until interaction, add Google Analytics 4 without slowing the page, open the editor faster with Kadence Blocks, and **Ask before licence checks** (once now, once a day or never).
* **Load plugins only where needed**: a faster wp-admin, admin tools skipped on the site, and optional page-by-page content learning with an opt-out list.
* **Plugins menu** in the admin bar, **Plugin sizes**, **Clean up deleted plugins**, **Hosting needs** (what to ask your host for) and **Plugin presets**: our settings and starter data for other plugins, on request.
* **Fixes for other plugins** (on by default): works around their bugs, such as Lasso Lite contacting its server on every admin screen.
* **Maintenance mode**: a temporary 503 page with a message and 24-hour bypass links you can revoke.
* **Database key cleanup**: review leftover and duplicate indexes, confirm each removal and keep restore SQL. Back up first.

Features that replace another plugin import its settings once, never change that plugin’s settings, and wait while it is active.

= Discover =

* **Theme**: install, activate or customise the Kadence theme.
* **Free Plugins**: recommended plugins from WordPress.org by category, installed and activated in place.
* **Pro Plugins, Hosting, Tools**: directories of products we use and recommend.

= Affiliate disclosure =

Some links in the Pro Plugins, Hosting and Tools directories may be affiliate links, which may earn a commission that funds development. They never change what is recommended, and nothing is shown on your public site.

= External services =

* **WordPress.org** (api.wordpress.org): lists recommended plugins and the Kadence theme when you open those tabs, as Plugins → Add New does. See the [WordPress.org privacy policy](https://wordpress.org/about/privacy/).
* **Google Analytics** (www.googletagmanager.com): with Delayed Google Analytics on and a measurement ID entered, visitors’ browsers load gtag.js and send page views to Google Analytics, under Google’s privacy policy. Logged-in users are not tracked.
* **Screenshot services**: with Website screenshots on, your server sends the address of each page to capture to the service you choose, when a screenshot is made or renewed. Visitors never contact the service.
* **Thum.io** (image.thum.io), the default: see [Thum.io](https://www.thum.io/).
* **Microlink** (api.microlink.io, or pro.microlink.io with a key): [terms](https://microlink.io/tos), [privacy policy](https://microlink.io/privacy).
* **ApiFlash** (api.apiflash.com): [terms](https://apiflash.com/terms_of_service), [privacy policy](https://apiflash.com/privacy_policy).
* **Screenshot Machine** (api.screenshotmachine.com): [terms](https://www.screenshotmachine.com/termsandconditions.php), [privacy policy](https://www.screenshotmachine.com/privacypolicy.php).
* **GitHub** (github.com, api.github.com), in copies from GitHub releases only: up to twice a day, asks for new releases of plugins that name a GitHub repository. Nothing about your site is sent. See the [GitHub privacy statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement).

Other features contact only addresses you choose. Copy linked images downloads images already linked in a post when it is saved. The iFrame block makes visitors’ browsers load the pages your editors embed, under those sites’ privacy policies. Avatars without Gravatar stops WordPress loading avatars from gravatar.com. Short links that count clicks set a cookie on your site (`sps_link_` and the link’s ID, for a year, holding only “1”); only totals are kept, never IP addresses. No other data is sent.

= Developers =

Settings, tabs and directory entries can be extended with filters such as `seoprostack_settings_schema`. See the Read Me tab for the full list.

== Installation ==

1. Install from Plugins → Add New, or upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to Settings → SEO Pro Stack and turn on the features you want.

== Frequently Asked Questions ==

= Is there a pro version? =

No. The plugin is free and open source under the GPL.

= What happens if I deactivate or delete the plugin? =

Imported images and screenshots stay in the Media Library. Deleting removes the settings, cached data, uploaded profile pictures, WebP and AVIF copies, short links and the must-use file. Watermarked pictures stay marked; originals stay in a `seoprostack-originals-…` folder in uploads.

= Does it work on multisite? =

Yes. Settings are per site, and Free Plugins is shown only to super admins.

= Is the magic login safe? =

Each link is random, stored only as a hash, works once and expires within an hour. Two-factor plugins that check only the password step are not asked, so with those choose “Everyone except administrators” or leave it off.

= What happens to queued posts if I turn the publishing queue off? =

They stay scheduled and publish at their times.

= Where do updates come from? =

Copies from WordPress.org update from there. New versions come out on GitHub first, and copies from GitHub update from GitHub.

= Where do I report a problem? =

Click **Report a problem** at the top right of Settings → SEO Pro Stack, or open an issue on [GitHub](https://github.com/wpallstars/seoprostack/issues).

== Changelog ==

= Unreleased =
* Change: Custom order link on sorted lists; Plugins screen notes on replaceable plugins.
* New: Fixes for other plugins, on by default.
* Change: Free Plugins no longer suggests Disable All WordPress Updates.
* New: Menu item visibility, Change post type, Order by hand and Term tools.
* Change: block editors open faster.
* New: Report a problem button.
* Change: faster Customizer, Import and Export forms; saving, previews and Site Health keep every plugin.
* Change: Kadence's design library is kept in your browser, so it opens faster.
* New: Read Me banner.

= 0.8.1 =
* New: spaced Fluent menu names; shop plugins first in Shop; FluentCart suggested.
* Fix: Code Snippets shows its logo in the Administrators and Developers menus.

Every change, and earlier versions: `changelog.txt` in the plugin folder.

== Upgrade Notice ==

= 0.8.1 =
Tidier admin menu (spaced Fluent names, shop plugins first, Code Snippets logo) and FluentCart suggestions.
