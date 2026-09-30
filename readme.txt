=== SEO Pro Stack ===
Contributors: marcusquinn
Tags: magic login, iframe, schedule posts, auto upload images, admin
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Curated plugins, themes, hosting and tools for WordPress, plus small opt-in quality-of-life features.

== Description ==

SEO Pro Stack is free and open source. There is no pro version and nothing is locked.

Everything is off by default. Settings save instantly from **Settings → SEO Pro Stack**.

= Features =

* **Modern admin colours**: use the core “Modern” admin colour scheme for everyone. Switching it sets your own profile to Modern (on) or the WordPress default (off); other users’ choices are left alone.
* **Magic login links**: adds “Email me a login link” to the login screen. Links work once, expire after 5–60 minutes (10 by default) and only log in after the person presses a button, so email scanners that open links cannot use them up. Passwords keep working, administrators can be excluded, and core’s `wp_login` and `login_redirect` hooks run so activity logs, redirect rules and two-factor plugins that use `wp_login` (such as Two Factor) still apply. Requests are rate limited and never reveal whether an account exists.
* **Publishing queue**: publishing a post from the editor without choosing a date schedules it for the next free time slot (for example weekdays at 09:00 and 15:00). Dates you choose yourself, updates to published posts, imports and WP-CLI are left alone. Posts become normal “Scheduled” posts, so WordPress publishes them.
* **iFrame block**: embed any page with control over width and height or aspect ratio, lazy loading, sandbox, permissions (camera, autoplay, full screen…), referrer policy and border, and optionally pass the page’s URL parameters (such as UTM tags) to the embedded page. Limit it to a list of domains and to the roles you choose.
* **Copy linked images to Media Library**: when a post is saved, images linked from other sites are copied into the Media Library, resized, attached to the post, and the content is changed to serve the local copy. Supports excluded domains, maximum dimensions, and file name and alt text patterns. Existing alt text is kept and repeat images are reused.
* **Admin bar and dashboard access**: hide the front-end admin bar and block wp-admin for chosen roles, such as subscribers and customers. Administrators are never affected.
* **Dashboard and sidebar widgets**: hide Dashboard boxes and disable classic widgets you never use.
* **Notification emails**: stop routine emails one by one, such as auto-update reports and new user notices.
* **Duplicate posts**: copy any post, page or custom post type to a new draft from lists, the editor or the admin bar.
* **Staged new versions**: edit a published post as a draft copy, then publish the copy over the original, keeping its address, comments and date.
* **Shareable preview links**: share a draft with people who do not have an account. Links expire and stop working when the post is published.
* **Sticky posts for any post type**: pin pages, products and custom post types to the top of their archives and term pages.
* **Select all across pages**: apply a bulk action to every post that matches the list’s filters, not just the visible page.
* **410 Gone for removed pages**: tell search engines that removed addresses are gone for good.
* **Speed**: load pages before the click (Speculation Rules), delay chosen scripts until interaction, and add Google Analytics 4 without slowing the page.

Features that replace another plugin (for example Carbon Copy, Flying Pages or Ultimate 410) import its settings once and show “Replaces: …” on their card. The other plugin’s settings are left untouched.

= Discover =

* **Theme**: install, activate or customise the Kadence theme.
* **Free Plugins**: recommended plugins from WordPress.org by category, using the core Install and Activate buttons. Only shown to users who can install plugins.
* **Pro Plugins, Hosting, Tools**: filterable directories of products we use and recommend.

= Affiliate disclosure =

Some links in the Pro Plugins, Hosting and Tools directories may be affiliate links. They link directly to the product and never change what is recommended; using them may earn a commission that funds development. Nothing is shown on your public site.

= External services =

This plugin contacts the following services, only from the admin screen and only when you open the relevant tab:

* **WordPress.org Plugins and Themes API** (api.wordpress.org) to list recommended plugins and the Kadence theme, the same as Plugins → Add New. See the [WordPress.org privacy policy](https://wordpress.org/about/privacy/).

When **Copy linked images to Media Library** is on, saving a post downloads images from the addresses already in that post’s content.

When **Magic login links** is on, login links are sent with your site’s normal email (`wp_mail()`), the same way as password reset emails.

The **iFrame block** shows pages from the addresses your editors enter. Visitors’ browsers load those pages directly, so the embedded site’s own privacy policy applies. Use the allowed domains setting to control which sites can be embedded.

When **Delayed Google Analytics** is on and a measurement ID is entered, visitors’ browsers load Google’s gtag.js from www.googletagmanager.com and send page views to Google Analytics, under Google’s privacy policy. Logged-in users are not tracked.

No other data is sent.

= Developers =

Settings, tabs and directory entries can be extended with filters such as `seoprostack_settings_schema`, `seoprostack_admin_tabs` and `seoprostack_tools_items`. See the Read Me tab in the plugin for the full list.

== Installation ==

1. Install from Plugins → Add New, or upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to Settings → SEO Pro Stack and turn on the features you want.

== Frequently Asked Questions ==

= Is there a pro version? =

No. The plugin is free and open source under the GPL.

= What happens to my images if I deactivate or delete the plugin? =

Imported images stay in the Media Library because your posts use them. Deleting the plugin removes its settings and cached data.

= Does it work on multisite? =

Yes. Settings are per site. The Free Plugins tab is shown only to users who can install plugins (super admins on multisite).

= Is the magic login safe? =

Each link is random, stored only as a hash, works once and expires within an hour at most. Requesting a new link cancels the old one. Opening a link shows a “Log in” button rather than logging in straight away, so links opened by email security scanners stay valid. It does not change how passwords, cookies or the admin work; turning it off removes it completely. Two-factor plugins that check the password step itself (rather than `wp_login`) are not asked, so on those sites choose “Everyone except administrators” or leave the feature off.

= What happens to queued posts if I turn the publishing queue off? =

They stay scheduled and publish at their times. Turning it off only stops new posts from being queued.

== Changelog ==

= Unreleased =
* Free plugin lists show changes straight away instead of after the 12-hour cache expires.
* The Flying Press card no longer recommends Flying Analytics, Flying Pages or Flying Scripts, which the Speed tab replaces.

= 0.3.0 =
* Renamed from WP Allstars to SEO Pro Stack. Settings are migrated automatically.
* New: magic login links, publishing queue and iFrame block (all off by default).
* New, replacing separate plugins and importing their settings: admin bar and dashboard access, widget control, notification emails, duplicate posts, staged new versions, shareable preview links, sticky posts for any post type, select all across pages, 410 Gone addresses, page preloading, delayed scripts and delayed Google Analytics.
* New Speed tab.
* Kadence links point to the current Kadence pages at Liquid Web.
* Setting cards keep their rounded corners and “on” marker when hovered.
* New admin screen with grouped tabs, instant-save setting cards, accessible switches and expandable options.
* Settings stored in one option with automatic migration from earlier versions.
* Modern admin colours switches live and updates your profile preference.
* Auto upload images rewritten and renamed “Copy linked images to Media Library”: safer downloads, de-duplication, domain exclusions, resizing, name and alt patterns.
* Free plugin cards use core install and activate buttons; theme installs in place.
* Filterable Pro Plugins, Hosting and Tools directories.
* Uninstall cleanup, including multisite.

== Upgrade Notice ==

= 0.3.0 =
Settings migrate automatically. Review Settings → SEO Pro Stack after updating.
