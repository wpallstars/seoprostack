=== WP Allstars ===
Contributors: marcusquinn
Tags: admin, images, media library, recommendations, workflow
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Curated plugins, themes, hosting and tools for WordPress, plus small opt-in quality-of-life features.

== Description ==

WP Allstars is free and open source. There is no pro version and nothing is locked.

Everything is off by default. Settings save instantly from **Settings → WP Allstars**.

= Features =

* **Modern admin colours**: use the core “Modern” admin colour scheme for everyone. Switching it sets your own profile to Modern (on) or the WordPress default (off); other users’ choices are left alone.
* **Auto upload images**: when a post is saved, images hosted on other sites are copied into the Media Library, attached to the post, and the content is updated to the local copy. Supports excluded domains, maximum dimensions, and file name and alt text patterns. Existing alt text is kept and repeat images are reused.

= Discover =

* **Theme**: install, activate or customise the Kadence theme.
* **Free Plugins**: recommended plugins from WordPress.org by category, using the core Install and Activate buttons. Only shown to users who can install plugins.
* **Pro Plugins, Hosting, Tools**: filterable directories of products we use and recommend.

= Affiliate disclosure =

Some links in the Pro Plugins, Hosting and Tools directories may be affiliate links. They link directly to the product and never change what is recommended; using them may earn a commission that funds development. Nothing is shown on your public site.

= External services =

This plugin contacts the following services, only from the admin screen and only when you open the relevant tab:

* **WordPress.org Plugins and Themes API** (api.wordpress.org) to list recommended plugins and the Kadence theme, the same as Plugins → Add New. See the [WordPress.org privacy policy](https://wordpress.org/about/privacy/).

When **Auto upload images** is on, saving a post downloads images from the addresses already in that post’s content. No other data is sent.

= Developers =

Settings, tabs and directory entries can be extended with filters such as `wp_allstars_settings_schema`, `wp_allstars_admin_tabs` and `wp_allstars_tools_items`. See the Read Me tab in the plugin for the full list.

== Installation ==

1. Install from Plugins → Add New, or upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to Settings → WP Allstars and turn on the features you want.

== Frequently Asked Questions ==

= Is there a pro version? =

No. The plugin is free and open source under the GPL.

= What happens to my images if I deactivate or delete the plugin? =

Imported images stay in the Media Library because your posts use them. Deleting the plugin removes its settings and cached data.

= Does it work on multisite? =

Yes. Settings are per site. The Free Plugins tab is shown only to users who can install plugins (super admins on multisite).

== Changelog ==

= 0.3.0 =
* New admin screen with grouped tabs, instant-save setting cards, accessible switches and expandable options.
* Settings stored in one option with automatic migration from earlier versions.
* Modern admin colours switches live and updates your profile preference.
* Auto upload images rewritten: safer downloads, de-duplication, domain exclusions, resizing, name and alt patterns.
* Free plugin cards use core install and activate buttons; theme installs in place.
* Filterable Pro Plugins, Hosting and Tools directories.
* Uninstall cleanup, including multisite.

== Upgrade Notice ==

= 0.3.0 =
Settings migrate automatically. Review Settings → WP Allstars after updating.
