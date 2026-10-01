=== SEO Pro Stack ===
Contributors: marcusquinn
Tags: magic login, iframe, schedule posts, auto upload images, admin
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Curated plugins, themes, hosting and tools for WordPress, plus small opt-in quality-of-life features.

== Description ==

SEO Pro Stack is free and open source. There is no pro version and nothing is locked.

Everything is off by default except two: Hide admin bar items, which hides Comments and + New from the admin bar, and No fade between admin screens. Settings save instantly from **Settings → SEO Pro Stack** (or the star in the admin bar), grouped into Admin, Content, Media, Links, Speed and Plugins tabs. Use **Search features** to find a setting by name or by the plugin it replaces.

= Features =

* **Modern admin colours**: use the core “Modern” admin colour scheme for everyone. Switching it sets your own profile to Modern (on) or the WordPress default (off); other users’ choices are left alone.
* **No fade between admin screens** (on by default): wp-admin screens change straight away, without the fade added in WordPress 7.0, which can flash.
* **Magic login links**: adds “Email me a login link” to the login screen. Links work once, expire after 5–60 minutes (10 by default) and only log in after the person presses a button, so email scanners that open links cannot use them up. Passwords keep working, administrators can be excluded, and core’s `wp_login` and `login_redirect` hooks run so activity logs, redirect rules and two-factor plugins that use `wp_login` (such as Two Factor) still apply. Requests are rate limited and never reveal whether an account exists.
* **Publishing queue**: publishing a post from the editor without choosing a date schedules it for the next free time slot (for example weekdays at 09:00 and 15:00). Dates you choose yourself, updates to published posts, imports and WP-CLI are left alone. Posts become normal “Scheduled” posts, so WordPress publishes them.
* **iFrame block**: embed any page with control over width and height or aspect ratio, lazy loading, sandbox, permissions (camera, autoplay, full screen…), referrer policy and border, and optionally pass the page’s URL parameters (such as UTM tags) to the embedded page. Limit it to a list of domains and to the roles you choose.
* **Website screenshots**: a Screenshot block and the `[browser-shot]` shortcode. Each page is captured once in a 1920 × 1080 browser window, saved to the Media Library and shown from your site, so visitors never contact the screenshot service. Screenshots are renewed in the background after 30 days. Browser Shots content keeps working.
* **Spectra block replacements**: a Term list block shows the terms of any taxonomy as a list, a grid or a drop-down. Pages built with Spectra keep working after it is deactivated, and in the editor its Heading, Image, Buttons, Testimonial and Taxonomy List blocks convert to core blocks with one click. Nothing changes until you save, and the Spectra version is kept as a revision.
* **Copy linked images to Media Library**: when a post is saved, images linked from other sites are copied into the Media Library, resized, attached to the post, and the content is changed to serve the local copy. Supports excluded domains, maximum dimensions, and file name and alt text patterns. Existing alt text is kept and repeat images are reused.
* **Paste into the Media Library**: paste screenshots, pictures and files into the Media Library, the media dialog or the classic editor and they upload straight away, through WordPress’s own uploader. Pasted pictures get a name from a pattern and can be saved as JPEG or WebP.
* **SVG uploads**: let chosen roles upload SVG files. Every SVG is cleaned as it is uploaded: scripts, event handlers, HTML, links to other files and anything that is not a drawing are removed, and files that cannot be cleaned safely are refused.
* **Resize large uploads**: scale pictures larger than a set size down as they are uploaded, instead of keeping the huge original next to a smaller copy. BMP files (and optionally PNG photos) are saved as JPEG. Resize existing pictures from Media → Library or with WP-CLI.
* **Replace media files**: upload a new file for a Media Library item, keeping or changing its file name. The item keeps its title, alt text and every place it is used, and links to the old file in posts and custom fields are changed to the new one.
* **WebP and AVIF images**: save a smaller WebP and AVIF copy of every picture in the Media Library, in the background, and send it to browsers that support it at the same address. Originals and pages are not changed. Works on Apache and LiteSpeed by itself; on Nginx the settings show the lines to add. On multisite one set of rules serves every site.
* **Watermark pictures**: add your site icon, logo or a chosen picture faintly (30% by default) to a corner of pictures as they are uploaded. Small sizes such as thumbnails stay clean. An unmarked original is kept, so watermarks can be removed or changed later from Media → Library or with WP-CLI.
* **Admin bar and dashboard access**: hide the front-end admin bar and block wp-admin for chosen roles, such as subscribers and customers. Administrators are never affected.
* **Organise the admin menu**: group the menu into Content, Communications, SEO, Shop and Admin, the same way on every site, with WordPress’s entries first and plugins’ in A–Z order. Under Admin, Administrators and Developers are menus that open to the side, then Users; developer tools moved out of Settings and Tools share one Settings entry in Developers. Entries keep their flyout menus, sections can fold (off by default), and you can choose any entry’s place from a list. Preview the admin as any role, or as a client administrator, in a new tab. Client safeguards keep people who are not developers out of Developers, plugin and theme installs and code editing, developer accounts and these settings; updates still work. Replaces Admin Menu Editor.
* **Dashboard and sidebar widgets**: hide Dashboard boxes and disable classic widgets you never use.
* **Tidy the dashboard**: the same Dashboard on every site, with writing and activity first, then forms and email statistics, then visitors, SEO and the site. Hides the Welcome panel, WordPress news and plugin promotions, shows Site Health to developers only and statistics to people who can publish. Subscribers and customers see no boxes.
* **Notification emails**: stop routine emails one by one, such as auto-update reports and new user notices.
* **Hide admin notices**: move plugin and theme notices behind a bell in the admin bar, with a dot while there are notices. With Load plugins only where needed, notices of plugins a screen skips still show there. Messages about what you just did, such as “Settings saved”, stay on the page.
* **More menu in the admin bar**: put the items plugins and themes add to the left of the admin bar in one … menu, last on that side, so the bar stays on one line and never covers the page. Choose plugins whose items stay on the bar.
* **Hide admin bar items** (on by default): remove WordPress items you do not use from the admin bar, such as Comments and + New (ticked by default), the WordPress logo menu or Search. The account menu always stays.
* **Avatars without Gravatar**: serve every avatar from your own site, so visitors’ browsers never contact Gravatar. People can upload a profile picture; everyone else gets a silhouette, a pattern that differs per person, or nothing.
* **Duplicate posts**: copy any post, page or custom post type to a new draft from lists, the editor or the admin bar.
* **Staged new versions**: edit a published post as a draft copy, then publish the copy over the original, keeping its address, comments and date.
* **Shareable preview links**: share a draft with people who do not have an account. Links expire and stop working when the post is published.
* **Sticky posts for any post type**: pin pages, products and custom post types to the top of their archives and term pages.
* **Select all across pages**: apply a bulk action to every post that matches the list’s filters, not just the visible page.
* **410 Gone for removed pages**: tell search engines that removed addresses are gone for good.
* **Short addresses for custom post types**: serve products and other custom post types at /item-name/ instead of /product/item-name/. Old addresses redirect, and pages keep their address when names clash.
* **Short links**: short addresses on your site, such as /go/offer/, that send visitors elsewhere with a 301, 302 or 307 redirect, with nofollow and sponsored options, categories and click counts. Starts with Google, Facebook and Trustpilot review links to point at your own review pages. Imports links, click counts and categories from Pretty Links.
* **Speed**: load pages before the click (Speculation Rules), on the site and optionally in the admin, delay chosen scripts until interaction, and add Google Analytics 4 without slowing the page.
* **Plugins menu in the admin bar**: a plugin icon on the right of the admin bar lists every plugin. Switch any plugin on or off after confirming, then return to the page you were on.
* **Plugin sizes**: a Size column on the Plugins screen shows each plugin’s PHP, JavaScript, CSS, media and other files, measured in the background and sortable, with totals for installed and active plugins below the list.
* **Hosting needs**: checks whether your hosting fits the site and what to ask your host for: OPcache room for the PHP code your plugins and theme can load, the PHP memory limit against the most memory a request used in the last 7 days, and the memory each PHP worker needs. Shown below the plugin list and in Tools → Site Health.
* **Clean up deleted plugins**: when plugin folders were deleted outside the Plugins screen, opening that screen removes their leftover uninstall and “Recently active” entries.
* **Load plugins only where needed**: makes wp-admin faster on sites with many plugins. Plugins you tick load only on their own screens, on post, term and list screens where they add boxes, fields or blocks, and where plugins that need them load. Screens are learned the first time they open, the menu stays the same, and saving, background tasks, the Plugins and settings screens and the site itself always load every plugin. A screen that fails loads every plugin from then on, and any screen, or every screen at once, can be reloaded with every plugin and checked again from the admin bar. Uses a small must-use file that it writes and removes itself.

Features that replace another plugin (for example Carbon Copy, Imsanity or Ultimate 410) import its settings once and show “Replaces: …” on their card. The other plugin’s settings are left untouched. While the other plugin is active, the feature waits and that plugin keeps doing the job; deactivate it to switch over.

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

When **Avatars without Gravatar** is on, WordPress no longer loads avatars from Gravatar (gravatar.com), which it does by default. Nothing is sent anywhere: uploaded profile pictures and generated avatars are stored in your uploads folder.

When **Website screenshots** is on, your site sends the address of each page to capture to the screenshot service you choose, from the server, once per screenshot and again when it is renewed. The picture is saved in your Media Library; visitors’ browsers never contact the service. Services:

* **Thum.io** (image.thum.io), the default, no account needed. See [Thum.io](https://www.thum.io/).
* **Microlink** (api.microlink.io, or pro.microlink.io with a key). See the [Microlink terms](https://microlink.io/tos) and [privacy policy](https://microlink.io/privacy).
* **ApiFlash** (api.apiflash.com), with your access key. See the [ApiFlash terms](https://apiflash.com/terms_of_service) and [privacy policy](https://apiflash.com/privacy_policy).
* **Screenshot Machine** (api.screenshotmachine.com), with your key. See the [Screenshot Machine terms](https://www.screenshotmachine.com/termsandconditions.php) and [privacy policy](https://www.screenshotmachine.com/privacypolicy.php).

Before a screenshot is taken, your site also checks that the page can be reached by requesting it once.

When **Short links** counts clicks, a visitor who follows a short link gets a cookie for your site (`sps_link_` and the link’s ID, kept for a year, holding only “1”) so they are counted as a unique visitor once. Only the totals are stored; no IP addresses or other visitor details are kept, and nothing is sent anywhere.

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

Imported images and screenshots stay in the Media Library because your posts use them. Deactivating the plugin stops sending WebP and AVIF copies, so browsers get the originals. Deleting the plugin removes its settings and cached data, the profile pictures uploaded with Avatars without Gravatar, the WebP and AVIF copies, short links, and the must-use file of Load plugins only where needed. Watermarked pictures stay marked; their unmarked originals are kept in a `seoprostack-originals-…` folder in uploads, which you can delete if you do not need them.

= Does it work on multisite? =

Yes. Settings are per site. The Free Plugins tab is shown only to users who can install plugins (super admins on multisite).

= Is the magic login safe? =

Each link is random, stored only as a hash, works once and expires within an hour at most. Requesting a new link cancels the old one. Opening a link shows a “Log in” button rather than logging in straight away, so links opened by email security scanners stay valid. It does not change how passwords, cookies or the admin work; turning it off removes it completely. Two-factor plugins that check the password step itself (rather than `wp_login`) are not asked, so on those sites choose “Everyone except administrators” or leave the feature off.

= What happens to queued posts if I turn the publishing queue off? =

They stay scheduled and publish at their times. Turning it off only stops new posts from being queued.

== Changelog ==

= Unreleased =
* New, off by default: Tidy the dashboard (Admin tab) lays out the Dashboard the same way on every site, hides the Welcome panel, news and plugin promotions, and shows Site Health to developers only and statistics to people who can publish.
* New: a star in the admin bar, next to your name, opens SEO Pro Stack’s settings, for people who can change them.
* New, off by default: Organise the admin menu (Admin tab) groups the menu into sections, with Administrators and Developers menus and Users under Admin, the same way on every site, with places chosen from a list, sections that can fold and role previews, with client safeguards for people who are not developers (on by default). Replaces Admin Menu Editor.
* New, off by default: More menu in the admin bar (Admin tab) puts the items plugins and themes add to the left of the admin bar in one … menu, last on that side, so the bar no longer wraps over the page. It opens on hover, like the other admin bar menus. Choose plugins whose items stay on the bar. On phones the menu also reaches plugin items WordPress hides there.
* New, on by default: Hide admin bar items (Admin tab) removes WordPress items you do not use from the admin bar, for everyone. Comments and + New are ticked by default. Switch it off to keep every item.
* New, on by default: No fade between admin screens (Admin tab) stops the fade WordPress 7.0 added between wp-admin screens, which can flash, so screens change straight away. Switch it off to keep the fade.
* New: Load pages before the click can also preload admin screens (off by default). Only the page is downloaded, and links that change something are skipped.
* Change: Hide the admin bar and Block dashboard access no longer offer Administrator, since administrators are never affected.
* Change: roles that plugins add, such as WooCommerce’s Customer, are ticked in Hide the admin bar and Block dashboard access unless they can write posts. With WooCommerce, blocked users go to My Account unless you chose another page.
* Change: on new sites, SVG uploads also allows authors, contributors and shop managers, and watermarks are 10% of the picture. Existing settings are kept.
* Change: Resize large uploads no longer imports Imsanity’s default limit (1920), so 2560 is used.
* Change: the Plugins menu always sits next to the account menu in the admin bar, with the notices bell to its left and other plugins’ items further left.
* Change: the notices bell is on every admin screen, with a white dot instead of a count while there are notices, so nothing on the admin bar moves as the page loads. With no notices, its panel says so.
* New: with Load plugins only where needed, the notices of plugins a screen skips are kept and shown behind the bell there, so they can be read and dismissed from any screen.
* Fix: only notices are kept from skipped plugins, so buttons a plugin prints above its own list (such as Kadence Blocks’ Export All and Import) no longer appear on the Dashboard and other screens.
* Change: the notices panel opens when you point at the bell and closes when you move away, like the other admin bar menus.
* Change: the notices bell, the Plugins icon and the More menu no longer show a tooltip over their open menus. Screen readers still announce them.
* New: with Load plugins only where needed, the admin bar can also check every screen again. Both reload options ask first and explain what will happen.
* New, off by default: Hosting needs (Plugins tab) shows whether your hosting fits the site, with the OPcache and PHP memory settings to ask your host for and the memory each PHP worker needs. Shown below the plugin list, as two Site Health tests and on the Site Health Info tab.
* New: Short links starts you with /googlereview/, /facebookreview/ and /trustpilotreview/ links in a Review Requests category. Point them at your own review pages, then use them in email signatures and review requests.

= 0.4.0 =
* Settings are grouped into Admin, Content, Media, Links and Speed tabs. Old links still open the right tab.
* New: search features by name, description or the plugin they replace, and change them from the results.
* New Plugins tab, all off by default: a Plugins menu in the admin bar (replaces Plugin Toggle), a Size column on the Plugins screen with totals for installed and active plugins, and cleanup of leftover entries for deleted plugins.
* Free Plugins no longer lists Plugin Toggle or String Locator.
* Free Plugins no longer lists EditorsKit, last updated in May 2024. WordPress and Kadence Blocks cover its features.
* New, off by default: Hide admin notices moves plugin and theme notices behind a bell in the admin bar, with a count (replaces Hide Admin Notices). “Show example notices” adds one of each kind to try it.
* New, off by default: Paste into the Media Library uploads pasted screenshots, pictures and files (replaces The Paste and imports its settings).
* New, off by default: Avatars without Gravatar serves avatars from your own site, with profile picture uploads (replaces Avatar Privacy and copies its profile pictures).
* New, off by default: SVG uploads (replaces Safe SVG), Resize large uploads (replaces Imsanity) and Replace media files (replaces Enable Media Replace), each importing that plugin’s settings.
* New, off by default: WebP and AVIF images makes smaller copies of every picture and sends them to browsers that support them (replaces CompressX, imports its settings and reuses the copies it made).
* New, off by default: Watermark pictures adds the site icon, logo or a chosen picture to uploaded pictures and keeps unmarked originals so watermarks can be removed (replaces Easy Watermark and imports its image watermark).
* New, off by default: Short addresses for custom post types serves items at /item-name/ instead of /type/item-name/ and redirects the old addresses (replaces Remove CPT base and imports its post types).
* New, off by default: Short links (Links tab) makes short addresses that redirect elsewhere, with categories and click counts (replaces Pretty Links: imports its links, click counts and categories, and its defaults for new links).
* New, off by default: Load plugins only where needed (Plugins tab) makes wp-admin faster on sites with many plugins by loading ticked plugins only on the screens that need them. The Plugins menu in the admin bar shows how many loaded and can reload a screen with every plugin to check it again.
* New, off by default: Website screenshots adds a Screenshot block and the `[browser-shot]` shortcode, with pictures saved to the Media Library and served from your site (replaces Browser Shots, whose shortcode and blocks keep working).
* New, off by default: Spectra block replacements adds a Term list block and keeps pages built with Spectra working after it is deactivated, with a Convert button for its blocks in the editor (replaces Spectra).
* Change: while a plugin that a feature replaces is active, the feature waits, so the two no longer run side by side (such as two Plugins menus, or analytics loaded twice). The card says so, with a deactivate link.
* Change: Hide admin notices also moves inline notices printed above the page, notices inside other plugins’ wrappers, notices added by scripts while the page loads, and WooCommerce’s script-drawn notices.
* Fix: `%date%` and `%day%` in Copy linked images’ file name and alt text patterns are no longer lost when saved.

= 0.3.1 =
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

= 0.4.0 =
New tabs and many new features that replace other plugins and import their settings. Everything new is off by default; existing settings are kept.

= 0.3.1 =
Recommendation fixes: free plugin lists update straight away, and plugins now built in are no longer suggested.

= 0.3.0 =
Settings migrate automatically. Review Settings → SEO Pro Stack after updating.
