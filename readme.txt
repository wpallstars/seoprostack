=== SEO Pro Stack ===
Contributors: wpallstars
Tags: magic login, iframe, schedule posts, auto upload images, admin
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Curated plugins, themes, hosting and tools for WordPress, plus small opt-in quality-of-life features.

== Description ==

SEO Pro Stack is free and open source. There is no pro version and nothing is locked.

Everything is off by default except Hide admin bar items, which hides Comments and + New, No fade between admin screens and Quiet Freemius prompts. Turn features on in **Settings → SEO Pro Stack** (or the star in the admin bar); they save straight away. **Search features** finds a setting by name or by the plugin it replaces. The plugin’s Read Me tab describes every feature in full.

= Admin screens =

* **Organise the admin menu**: the same menu sections on every site, with places chosen from a list, role previews and client safeguards. Replaces Admin Menu Editor.
* **Tidy the dashboard** and **Dashboard and sidebar widgets**: the same Dashboard on every site, without boxes and widgets you never use.
* **Hide admin bar items** (on by default), a **More menu** for plugin items in the admin bar, and **Hide admin notices**, which moves plugin and theme notices behind a bell.
* **Quiet Freemius prompts** (on by default): no opt-in nags, upgrade offers or deactivation surveys from plugins that use Freemius.
* **Admin bar and dashboard access**: hide the admin bar and block wp-admin for roles such as subscribers and customers. Administrators are never affected.
* **Magic login links**: “Email me a login link” on the login screen. Links work once, expire, and log in only after a button press, so email scanners cannot use them up.
* **Modern admin colours**, **No fade between admin screens** (on by default), **Readable list columns** and **Notification emails**, which stops routine emails one by one.

= Writing and publishing =

* **Publishing queue**: posts published without a date are scheduled for the next free time slot.
* **Duplicate posts**, **Staged new versions** (edit a published post as a draft copy) and **Shareable preview links** for people without an account.
* **Sticky posts for any post type** and **Select all across pages** for bulk actions.
* **iFrame block**: embed pages with size, sandbox and permission settings, limited to the domains and roles you choose.
* **Spectra block replacements**: a Term list block, pages built with Spectra that keep working after it is deactivated, and one-click conversion of its blocks.

= Media =

* **Copy linked images to Media Library** when a post is saved, and **Paste into the Media Library**.
* **SVG uploads** for chosen roles, cleaned as they are uploaded.
* **Resize large uploads**, **Replace media files** and **Watermark pictures**, which keeps unmarked originals.
* **WebP and AVIF images**: smaller copies sent to browsers that support them, at the same address.
* **Website screenshots**: a Screenshot block and the `[browser-shot]` shortcode, with pictures saved to the Media Library.
* **Avatars without Gravatar**: avatars served from your own site.

= Links, speed and plugins =

* **Short links** such as /go/offer/, with redirects, categories and click counts, and **Short addresses for custom post types**. **410 Gone** for removed pages.
* **Speed**: load pages before the click, delay chosen scripts until interaction, add Google Analytics 4 without slowing the page, and open the editor faster with Kadence Blocks.
* **Load plugins only where needed**: a faster wp-admin on sites with many plugins, and admin tools you choose skipped on the site, through a must-use file it manages itself.
* **Plugins menu** in the admin bar, **Plugin sizes**, **Clean up deleted plugins**, **Hosting needs** (what to ask your host for) and **Plugin presets**: our settings and starter data for other plugins, on request.

Features that replace another plugin import its settings once, never change that plugin’s settings, and wait while it is active.

= Discover =

* **Theme**: install, activate or customise the Kadence theme.
* **Free Plugins**: recommended plugins from WordPress.org by category, installed and activated in place, for users who can install plugins.
* **Pro Plugins, Hosting, Tools**: directories of products we use and recommend.

= Affiliate disclosure =

Some links in the Pro Plugins, Hosting and Tools directories may be affiliate links. They link directly to the product and never change what is recommended; using them may earn a commission that funds development. Nothing is shown on your public site.

= External services =

* **WordPress.org** (api.wordpress.org): lists recommended plugins and the Kadence theme when you open those tabs, as Plugins → Add New does. See the [WordPress.org privacy policy](https://wordpress.org/about/privacy/).
* **Google Analytics** (www.googletagmanager.com): with Delayed Google Analytics on and a measurement ID entered, visitors’ browsers load gtag.js and send page views to Google Analytics, under Google’s privacy policy. Logged-in users are not tracked.
* **Screenshot services**: with Website screenshots on, your server sends the address of each page to capture to the service you choose, once per screenshot and again when it is renewed, after requesting the page once to check it can be reached. Visitors never contact the service.
* **Thum.io** (image.thum.io), the default: see [Thum.io](https://www.thum.io/).
* **Microlink** (api.microlink.io, or pro.microlink.io with a key): [terms](https://microlink.io/tos), [privacy policy](https://microlink.io/privacy).
* **ApiFlash** (api.apiflash.com): [terms](https://apiflash.com/terms_of_service), [privacy policy](https://apiflash.com/privacy_policy).
* **Screenshot Machine** (api.screenshotmachine.com): [terms](https://www.screenshotmachine.com/termsandconditions.php), [privacy policy](https://www.screenshotmachine.com/privacypolicy.php).
* **GitHub** (api.github.com, github.com), in copies installed from GitHub releases only: with Updates from GitHub on (the default), up to twice a day the site asks for the latest release of SEO Pro Stack and of plugins that name a GitHub repository, and downloads updates you install. Nothing about your site is sent beyond the request. See the [GitHub privacy statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement).

Other features contact only addresses you choose. Copy linked images downloads images already linked in a post when it is saved. The iFrame block makes visitors’ browsers load the pages your editors embed, under those sites’ privacy policies. Magic login links are sent with your site’s normal email. Avatars without Gravatar stops WordPress loading avatars from gravatar.com. Short links that count clicks set a cookie on your site (`sps_link_` and the link’s ID, for a year, holding only “1”); only totals are kept, never IP addresses. No other data is sent.

= Developers =

Settings, tabs and directory entries can be extended with filters such as `seoprostack_settings_schema`, `seoprostack_admin_tabs` and `seoprostack_tools_items`. See the Read Me tab in the plugin for the full list.

== Installation ==

1. Install from Plugins → Add New, or upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to Settings → SEO Pro Stack and turn on the features you want.

== Frequently Asked Questions ==

= Is there a pro version? =

No. The plugin is free and open source under the GPL.

= What happens if I deactivate or delete the plugin? =

Imported images and screenshots stay in the Media Library because your posts use them. Deactivating stops serving WebP and AVIF copies. Deleting removes the settings, cached data, uploaded profile pictures, WebP and AVIF copies, short links and the must-use file. Watermarked pictures stay marked; their originals stay in a `seoprostack-originals-…` folder in uploads.

= Does it work on multisite? =

Yes. Settings are per site, and Free Plugins is shown only to super admins.

= Is the magic login safe? =

Each link is random, stored only as a hash, works once and expires within an hour. A new link cancels the old one. Passwords, cookies and the admin work as before. Two-factor plugins that check the password step itself (rather than `wp_login`) are not asked, so on those sites choose “Everyone except administrators” or leave the feature off.

= What happens to queued posts if I turn the publishing queue off? =

They stay scheduled and publish at their times.

= Where do updates come from? =

Copies from WordPress.org update from WordPress.org. New versions come out on GitHub first; copies installed from a GitHub release update themselves from GitHub, on the Updates screen like any other plugin. Git Updater is not needed.

== Changelog ==

= Unreleased =
* New: copies from GitHub update without Git Updater, also on PHP 7.4.
* New: the Plugins screen lists plugins SEO Pro Stack can replace.
* Change: Tutor LMS replaces MasterStudy LMS in the recommended plugins.
* New: presets for seven more plugins; starter lists, tags and a board for FluentCRM and Fluent Boards.
* New, on by default: Quiet Freemius prompts.
* New: Load plugins only where needed can also skip chosen admin tools on the site.
* Change: Load plugins only where needed also speeds up SEO Pro Stack’s own settings, Appearance › Menus and Editor.
* Fix: the admin menu opens on Kadence Blocks’ lists. Screens that fail with fewer plugins recover with Query Monitor. The Dashboard shows plugins’ boxes again.
* Fix: admin screens no longer jump as they load.
* Change: Tidy the dashboard hides WooCommerce Setup.

= 0.6.0 =
* New, off by default: Faster editor with Kadence Blocks loads Kadence’s design library only when you open it. Plugin presets. Readable list columns.
* Fix: admin menu, Dashboard, notices and blank pages with many plugins active. The menu leaves out upgrade links.
* Change: Load plugins only where needed also speeds up profile, user, Tools and Media › Add New screens.

Every change, and earlier versions: `changelog.txt` in the plugin folder.

== Upgrade Notice ==

= 0.6.0 =
New, off by default: Plugin presets, Faster editor with Kadence Blocks and Readable list columns. A tidier admin menu and fewer blank pages with many plugins active.
