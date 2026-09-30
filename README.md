# SEO Pro Stack

Curated plugins, themes, hosting and workflow tools for WordPress, plus a few small quality-of-life features.

Version: {SEOPROSTACK_VERSION}

## Where to find it

Go to **Settings → SEO Pro Stack**. The screen has three groups of tabs:

- **Settings**: General, Workflow, Speed and Advanced. Changes save instantly; there is no Save button.
- **Discover**: Theme, Free Plugins, Pro Plugins, Hosting and Tools.
- **About**: this Read Me.

## Features

Every feature is off by default. Features that replace a separate plugin say so on their card (“Replaces: …”) and import that plugin’s settings once when SEO Pro Stack is updated. The other plugin’s own settings are never changed or deleted, so you can compare, then deactivate it.

| Feature | Tab | Replaces |
| --- | --- | --- |
| Hide the admin bar, Block dashboard access | General | Admin Bar & Dashboard Access Control |
| Hide dashboard widgets, Disable sidebar widgets | General | Widget Disable |
| Notification emails | General | Manage Notification E-mails |
| Duplicate posts | Workflow | Carbon Copy, Yoast Duplicate Post |
| Staged new versions, Shareable preview links | Workflow | Post Draft Preview, Public Post Preview |
| Sticky posts for any post type | Workflow | Sticky Posts Switch |
| Select all across pages | Workflow | Bulk Actions Select All |
| Load pages before the click | Speed | Flying Pages |
| Delay scripts until interaction | Speed | Flying Scripts |
| Delayed Google Analytics | Speed | Flying Analytics |
| 410 Gone for removed pages | Advanced | Ultimate 410 |

### Modern admin colours (General)

Uses the WordPress “Modern” admin colour scheme for every user while enabled. Switching it also updates your own profile: on selects Modern, off selects the WordPress default. Other users’ saved choices are not changed and return when the setting is off.

### Magic login links (General)

Adds “Email me a login link” to the login screen, next to “Lost your password?”.

- The link works once and expires after 5–60 minutes (10 by default). Requesting a new link cancels the previous one.
- Opening the link shows a **Log in** button; the login happens when it is pressed. Email security scanners that open links therefore cannot use them up.
- The response is the same whether or not the account exists, and requests are rate limited per IP address and per user.
- Links are stored only as a keyed hash and are removed when used, when they expire, and on uninstall.
- Passwords keep working. Administrators can be required to use their password.
- Uses the core login screen and core `wp_login` / `login_redirect` hooks, so activity logs, redirect rules and two-factor plugins that use `wp_login` still apply. Two-factor plugins that only check the password step are not asked; on those sites exclude administrators or leave the feature off.

### Admin bar and dashboard access (General)

- **Hide the admin bar** on the front end for chosen roles (subscribers and customers by default).
- **Block dashboard access** for chosen roles: opening wp-admin sends them to the home page or a page you choose, such as `/my-account/`. AJAX, uploads and other background requests keep working.
- People who can manage options are never affected.

### Dashboard and sidebar widgets (General)

- **Hide dashboard widgets** for everyone, such as WordPress Events and News, the welcome panel or plugin promotions. Widgets added by plugins appear in the list after the Dashboard is next opened.
- **Disable sidebar widgets** you never use (the Meta widget by default); they disappear from the Widgets screen, the Customizer and sidebars.

### Notification emails (General)

Stop routine emails one by one: new user notices, password and email change notices, comment notices, and WordPress, plugin and theme auto-update reports. Each is stopped with the core filter that sends it, so nothing else changes. Password reset links are only ever stopped for administrators, and failed core updates are still reported.

### Duplicate posts (Workflow)

Adds **Duplicate** to post lists, the editor and the admin bar for the post types you choose. The copy is always a new draft; the original is never changed. Choose what else is copied: excerpt, author, featured image, terms, custom fields (including SEO settings), template, format, menu order, password and date.

### Staged new versions (Workflow)

**New version** on a published post creates a draft copy that remembers its original. Edit, preview or share it while the live post stays as it is. Publishing the copy, now or scheduled, copies it over the original — title, content, excerpt, terms, custom fields, featured image, template and format — so the address, comments and publish date are kept and WordPress stores a revision. The copy is then deleted.

- The copy never goes live at its own address, so sharing, pings and sitemaps are not triggered for it.
- In the block editor, custom field boxes are saved before the copy is merged. If the tab closes first, a background task finishes the merge within minutes.
- If the original cannot be updated, the copy stays as a draft so no edits are lost.

### Shareable preview links (Workflow)

Tick **Share a preview link** in the editor of a draft, pending or scheduled post to get an address anyone can open without an account.

- Links expire after 1–90 days (7 by default) and stop working when turned off or when the post is published.
- Preview pages send `noindex`, `no-referrer` and no-cache headers and ask page caches not to store them.

### Sticky posts for any post type (Workflow)

Adds a star column to the lists of the post types you choose, and a **Stick to the top** option in the editor for types other than posts. Sticky items lead the first page of the blog home, their post type archive and chosen term archives (categories, tags or custom taxonomies). It uses core’s sticky list, so existing sticky posts, themes and blocks keep working.

### Select all across pages (Workflow)

Tick the “select all” box in a post list with more than one page and a bar offers **Select all N items**: every item matching the current filters, search and status. Bulk actions (Move to Bin, Restore, Delete Permanently, Edit and plugin actions) then apply to all of them, still checked against each item’s permissions. Unticking any row clears the choice.

### Load pages before the click (Speed)

Uses the browser’s Speculation Rules to download (or fully prepare) a page when a visitor points at or starts to tap a link to it. On WordPress 6.8 and later it tunes core’s built-in speculative loading; earlier versions get the rules added directly. Admin, login, file and query-string links are always skipped; add your own exclusions such as `/cart` or `logout`. Browsers without Speculation Rules ignore it. Logged-in users are not affected.

### Delay scripts until interaction (Speed)

Scripts whose tag or code contains a keyword you list (for example a chat widget) are held back until the visitor moves, scrolls, taps or types, or until a time limit passes, then run in their original order. Pages can be excluded by address. Add `data-seoprostack-nodelay` to a script tag to never delay it. Logged-in users, previews and feeds are not affected.

### Delayed Google Analytics (Speed)

Adds Google Analytics 4 (standard gtag.js) with your measurement ID, loaded after the first interaction or after a few seconds so it does not compete with the page. Logged-in users are not tracked. Turn off any other plugin that adds the same ID.

### 410 Gone for removed pages (Advanced)

Answers “410 Gone” instead of “404 Not Found” for addresses you list, so search engines drop them sooner. Visitors still see the theme’s not-found page.

- One address per line, as a path (`/old-page/`) or a full address on this site. End with `*` to include everything below it (`/old-shop/*`).
- Only addresses that would otherwise be “not found” are affected, so a live page cannot be taken down by mistake.
- Optionally, published content deleted from the bin is added to the list automatically.

### Publishing queue (Workflow)

Publishing a post from the editor without choosing a date schedules it for the next free time slot instead.

- Slots are times of day (for example `09:00, 15:00`) on the days you choose, in the site timezone. Each slot takes one post.
- Dates you choose yourself (future or past), updates to posts that are already published or scheduled, and saves from imports, WP-CLI, cron, XML-RPC or API clients are left alone.
- Queued posts are normal “Scheduled” posts and WordPress publishes them. Turning the feature off leaves them scheduled.
- Choose which content types use the queue.

### iFrame block (Workflow)

Adds an **iFrame** block to the editor (Embed category).

- Width and height, or an aspect ratio (16:9, 4:3, 1:1, 9:16…).
- Lazy loading, full screen, border.
- Sandbox with per-permission checkboxes (on by default), permissions policy (camera, microphone, autoplay, payment…) and referrer policy.
- Optionally pass the page’s URL parameters (such as UTM tags) to the embedded page.
- Allowed domains: limit which sites can be embedded (subdomains included).
- Who can add iFrames: contributors, authors, editors, or only users who can add any HTML. Checked in the editor and again when the page is shown, using the post author’s role.
- Output is built on the server from validated settings, so stored content cannot inject HTML. Turning the feature off hides existing iFrame blocks.

### Copy linked images to Media Library (Workflow)

When a post is saved, images linked from other sites are copied into the Media Library, resized, attached to the post, and the content is changed to serve the local copy.

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

- `seoprostack_features`: register a feature class that extends `SEOProStack_Feature` (declare settings in `settings()`, add hooks in `boot()`, and optionally import another plugin’s settings in `migrate()`).
- `seoprostack_settings_schema`: add or change settings. Each entry sets `type` (bool, int, text, url, lines, domains, select, multi or times), `default`, `label`, `description` and either `tab` or `parent`; select and multi also take `options` (an array or a callable), and multi takes `open` to keep saved values that are not currently registered. `replaces` (slug => name) shows which plugin a feature replaces. Settings render and save automatically.
- `seoprostack_admin_tabs`: add or reorder admin tabs. Each tab sets `label`, `group` (settings, discover or about), a `render` callback and an optional `capability`; tabs the current user lacks the capability for are hidden.
- `seoprostack_pro_items`, `seoprostack_hosting_items`, `seoprostack_tools_items`: change directory entries.
- `seoprostack_auto_upload_process_post`: skip image copying for specific posts.
- `seoprostack_auto_upload_limit`: change the per-save import limit.
- `seoprostack_magic_login_allowed`: allow or refuse login links for a user.
- `seoprostack_magic_login_email`: change the login link email.
- `seoprostack_magic_login_ip_limit`: requests allowed per IP address per 15 minutes (default 5).
- `seoprostack_post_scheduler_applies`: skip the publishing queue for specific posts.
- `seoprostack_duplicate_post_data`: change the data of a duplicated post before it is created.
- `seoprostack_duplicate_skip_meta`: custom field keys that are never copied (by Duplicate posts and Staged new versions).

Actions:

- `seoprostack_setting_saved`: a setting was saved from the admin screen.
- `seoprostack_magic_login_link_sent`: a login link was emailed.
- `seoprostack_post_queued`: a post was scheduled by the publishing queue.
- `seoprostack_post_duplicated`: a post was duplicated.
- `seoprostack_version_published`: a staged new version was copied over its original.
- `seoprostack_image_imported`: an external image was imported.
- `seoprostack_image_upload_error`: an image could not be imported.

Read a setting with `SEOProStack_Settings::get( 'key' )`.

## Uninstall

Deleting the plugin removes its settings and cached data. Imported media stays in the Media Library because your posts use it.

## Changelog

### 0.3.0

- Renamed from WP Allstars to SEO Pro Stack (slug `seoprostack`). Settings, and images imported by earlier versions, carry over automatically.
- New features, all off by default: magic login links, publishing queue, iFrame block.
- New features that replace separate plugins and import their settings once: admin bar and dashboard access by role, dashboard and sidebar widget control, notification emails, duplicate posts, staged new versions, shareable preview links, sticky posts for any post type, select all across pages, 410 Gone addresses, page preloading, delayed scripts and delayed Google Analytics.
- New Speed tab. Replaced plugins are no longer listed under Free Plugins.
- Features are self-contained classes registered with `seoprostack_features`; settings support select, multi-choice, time-list, address and line-list fields.
- Setting cards keep their rounded corners and “on” marker when hovered.
- Kadence links point to the current Kadence pages at Liquid Web.
- New admin screen: grouped tabs, instant-save setting cards, accessible switches and expandable options.
- Settings stored in one option with automatic migration from earlier versions.
- Modern admin colours switches live, sets your profile to Modern (on) or the WordPress default (off), and leaves other users’ choices alone.
- Setting cards: save status sits beside the title; clicking a card header opens its options.
- Tools: Tabby replaces iTerm2.
- Auto upload images rewritten and renamed “Copy linked images to Media Library”: safer downloads, de-duplication, domain exclusions, resizing, name and alt patterns.
- Free plugin cards use core install and activate buttons; theme installs in place.
- Filterable Pro Plugins, Hosting and Tools directories.
- Removed unused debug files and duplicate scripts and styles; added uninstall cleanup.
- Removed Closte from hosting recommendations.

## License

GPL-2.0-or-later.
