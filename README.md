# WP Allstars

Curated plugins, themes, hosting and workflow tools for WordPress, plus a few small quality-of-life features.

Version: {WP_ALLSTARS_VERSION}

## Where to find it

Go to **Settings → WP Allstars**. The screen has three groups of tabs:

- **Settings**: General and Workflow. Changes save instantly; there is no Save button.
- **Discover**: Theme, Free Plugins, Pro Plugins, Hosting and Tools.
- **About**: this Read Me.

## Features

### Modern admin colours (General)

Uses the WordPress “Modern” admin colour scheme for every user while enabled. Switching it also updates your own profile: on selects Modern, off selects the WordPress default. Other users’ saved choices are not changed and return when the setting is off.

### Auto upload images (Workflow)

When a post is saved, images hosted on other sites are copied into the Media Library, attached to the post, and the content is updated to use the local copy.

- Works with the block editor, the classic editor and programmatic saves by users who can upload files.
- Images already imported from the same address are reused instead of uploaded again.
- Optional maximum width and height: larger images are scaled down before upload.
- Excluded domains (and their subdomains) are left alone. Your own site is always excluded.
- File name and alt text patterns support tokens such as `%filename%`, `%post_title%` and `%date%`. Existing alt text is kept.
- Up to 10 images are imported per save; the rest are imported on the next save.

### Discover

- **Theme**: install, activate or customise the Kadence theme.
- **Free Plugins**: recommended plugins from WordPress.org by category, with the same Install and Activate buttons as Plugins → Add New. Shown only to users who can install plugins (on multisite, super admins).
- **Pro Plugins, Hosting, Tools**: filterable directories with links to each product. Pro plugins show a badge when their free version is already on the site.

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later

## Extending

Developers can add settings, tabs and directory entries with filters:

- `wp_allstars_settings_schema`: add or change settings. Each entry sets `type` (bool, int, text or domains), `default`, `label`, `description` and either `tab` or `parent`. Settings render and save automatically.
- `wp_allstars_admin_tabs`: add or reorder admin tabs. Each tab sets `label`, `group` (settings, discover or about), a `render` callback and an optional `capability`; tabs the current user lacks the capability for are hidden.
- `wp_allstars_pro_items`, `wp_allstars_hosting_items`, `wp_allstars_tools_items`: change directory entries.
- `wp_allstars_auto_upload_process_post`: skip auto upload for specific posts.
- `wp_allstars_auto_upload_limit`: change the per-save import limit.

Actions:

- `wp_allstars_setting_saved`: a setting was saved from the admin screen.
- `wp_allstars_image_imported`: an external image was imported.
- `wp_allstars_image_upload_error`: an image could not be imported.

Read a setting with `WP_Allstars_Settings::get( 'key' )`.

## Uninstall

Deleting the plugin removes its settings and cached data. Imported media stays in the Media Library because your posts use it.

## Changelog

### 0.3.0

- New admin screen: grouped tabs, instant-save setting cards, accessible switches and expandable options.
- Settings stored in one option with automatic migration from earlier versions.
- Modern admin colours switches live, sets your profile to Modern (on) or the WordPress default (off), and leaves other users’ choices alone.
- Setting cards: save status sits beside the title; clicking a card header opens its options.
- Tools: Tabby replaces iTerm2.
- Auto upload images rewritten: safer downloads, de-duplication, domain exclusions, resizing, name and alt patterns.
- Free plugin cards use core install and activate buttons; theme installs in place.
- Filterable Pro Plugins, Hosting and Tools directories.
- Removed unused debug files and duplicate scripts and styles; added uninstall cleanup.
- Removed Closte from hosting recommendations.

## License

GPL-2.0-or-later.
