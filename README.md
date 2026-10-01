# SEO Pro Stack

Curated plugins, themes, hosting and workflow tools for WordPress, plus a few small quality-of-life features.

Version: {SEOPROSTACK_VERSION}

## Where to find it

Go to **Settings → SEO Pro Stack**, or click the star next to your name in the admin bar (shown to people who can change these settings). The screen has three groups of tabs:

- **Settings**: Admin, Content, Media, Links, Speed and Plugins, plus Maintenance in builds from GitHub releases. Changes save instantly; there is no Save button.
- **Search features** (next to the plugin name) finds settings on every tab by name, description or the plugin they replace. Results can be switched on and changed in place.
- **Discover**: Theme, Free Plugins, Pro Plugins, Hosting and Tools.
- **About**: this Read Me.

## Features

Every feature is off by default except two: Hide admin bar items, which hides Comments and + New from the admin bar, and No fade between admin screens. Features that replace a separate plugin say so on their card (“Replaces: …”) and import that plugin’s settings once when SEO Pro Stack is updated. The other plugin’s own settings are never changed or deleted. While that plugin is active, the feature waits and the plugin keeps doing the job, so the two never run side by side; the card says so, with a deactivate link. Deactivate the plugin to switch over.

| Feature | Tab | Replaces |
| --- | --- | --- |
| Hide the admin bar, Block dashboard access | Admin | Admin Bar & Dashboard Access Control |
| Organise the admin menu | Admin | Admin Menu Editor (Pro) |
| Hide dashboard widgets, Disable sidebar widgets | Admin | Widget Disable |
| Notification emails | Admin | Manage Notification E-mails |
| Hide admin notices | Admin | Hide Admin Notices |
| Avatars without Gravatar | Admin | Avatar Privacy |
| Duplicate posts | Content | Carbon Copy, Yoast Duplicate Post |
| Staged new versions, Shareable preview links | Content | Post Draft Preview, Public Post Preview |
| Sticky posts for any post type | Content | Sticky Posts Switch |
| Select all across pages | Content | Bulk Actions Select All |
| Website screenshots | Content | Browser Shots |
| Spectra block replacements | Content | Spectra |
| Paste into the Media Library | Media | The Paste |
| SVG uploads | Media | Safe SVG |
| Resize large uploads | Media | Imsanity |
| Replace media files | Media | Enable Media Replace |
| WebP and AVIF images | Media | CompressX |
| Watermark pictures | Media | Easy Watermark |
| Load pages before the click | Speed | Flying Pages |
| Delay scripts until interaction | Speed | Flying Scripts |
| Delayed Google Analytics | Speed | Flying Analytics |
| 410 Gone for removed pages | Links | Ultimate 410 |
| Short addresses for custom post types | Links | Remove CPT base |
| Short links | Links | Pretty Links |
| Plugins menu in the admin bar | Plugins | Plugin Toggle |
| Clean up deleted plugins | Plugins | Fix ‘Plugin file does not exist’ Notices |

### Modern admin colours (Admin)

Uses the WordPress “Modern” admin colour scheme for every user while enabled. Switching it also updates your own profile: on selects Modern, off selects the WordPress default. Other users’ saved choices are not changed and return when the setting is off.

### No fade between admin screens (Admin)

WordPress 7.0 fades from one wp-admin screen to the next, using the browser’s view transitions. The fade can flash, and each screen change waits for it. With this on, screens change straight away, as before 7.0. **On by default**; switch it off to get the fade back.

- Removes core’s `wp-view-transitions-admin` style and adds `@view-transition { navigation: none; }` to admin screens, so fades that other plugins add the same way stop too.
- Only page changes are affected. Animations inside a screen, such as in the site editor, stay.
- No effect before WordPress 7.0, which has no fade, or for people whose system asks for reduced motion, who never get it.
- Goes well with Load pages before the click’s **Also in the admin**, which downloads admin screens when you point at their links.

### Magic login links (Admin)

Adds “Email me a login link” to the login screen, next to “Lost your password?”.

- The link works once and expires after 5–60 minutes (10 by default). Requesting a new link cancels the previous one.
- Opening the link shows a **Log in** button; the login happens when it is pressed. Email security scanners that open links therefore cannot use them up.
- The response is the same whether or not the account exists, and requests are rate limited per IP address and per user.
- Links are stored only as a keyed hash and are removed when used, when they expire, and on uninstall.
- Passwords keep working. Administrators can be required to use their password.
- Uses the core login screen and core `wp_login` / `login_redirect` hooks, so activity logs, redirect rules and two-factor plugins that use `wp_login` still apply. Two-factor plugins that only check the password step are not asked; on those sites exclude administrators or leave the feature off.

### Admin bar and dashboard access (Admin)

- **Hide the admin bar** on the front end for chosen roles.
- **Block dashboard access** for chosen roles: opening wp-admin sends them to the home page or a page you choose, such as `/my-account/`. AJAX, uploads and other background requests keep working. With WooCommerce active and no page chosen, its My Account page is filled in.
- Both lists start with every role that can neither manage options nor write posts (subscribers, customers and similar roles from plugins). Roles that plugins add later are ticked in both lists unless they can write posts, so staff roles such as Shop manager are never locked out. The roles seen so far are kept in the `seoprostack_access_roles` option.
- People who can manage options are never affected, so roles that can (such as Administrator) are not offered.

### Organise the admin menu (Admin)

Groups the admin menu under the headings **Content**, **Communications**, **SEO**, **Shop** and **Admin**, the same way on every site, so plugins’ entries are where you expect them. Dashboard stays at the top. Under **Admin** come two menus, **Administrators** and **Developers**, then **Users**.

- Within a section, WordPress’s own entries come first, then plugins’ in A–Z order, with a thin line between them. Settings, Tools and Appearance are sorted the same way.
- Entries under the headings keep their normal flyout submenus. **Administrators** and **Developers** open to the side like any menu, listing their entries with icons; each entry’s own submenu opens one level further to the side. On a page inside them, the menu opens in place with the page’s list shown under its entry.
- In **Developers**, plugin pages moved out of WordPress’s menus (such as Scheduled Actions, WP Crontrol or a debug log viewer) share one **Settings** entry instead of each showing a cog. SEO Pro Stack keeps its own entry, with a star icon.
- **Fold sections** (off by default): click a heading to fold it; folded sections are remembered for each person, and the section of the page you are on always opens. With the sidebar collapsed, headings become lines and every entry shows.
- **Preview the admin as** a role: the options panel links to each role, and to **Client administrator** (an administrator who is not a developer). Each opens the admin in a new tab with only that role’s permissions, never more than your own, and a **Stop** link in the corner and the admin bar. The preview is kept in a signed cookie in this browser, so every tab shows it until you stop it or two hours pass. Only developers who can manage options can start one.
- Places come from a list of common menus and plugins (`admin/data/admin-menu.php`), made from real sites. Plugin pages under Settings or Tools that belong elsewhere move too: SEO plugins to SEO; caching, security and developer tools to Developers. Pages inside a plugin’s own menu stay there. Unknown plugins go to Administrators; post types to Content.
- **Left out**: upgrade links (an entry called only Upgrade, Upgrade to Pro or premium, Go Pro, Get Pro, Unlock Pro, Premium Upgrade or Pricing), entries without a name, pages registered without a menu (such as WooCommerce’s Setup Wizard, which WordPress itself never shows), and entries listed as hidden: Freesoul Deactivate Plugins’ holder menu, the Freemius opt-in prompts of WP Sheet Editor add-ons, AutomatorWP’s advert for ShortLinks Pro and MasterStudy LMS’s “Unlock this addon” pages (PRO Addons still lists them). Their pages still open and keep their place for the safeguards. The list of entries marks them “(hidden)”; choosing another place, or a line in Move menu entries, shows one.
- A menu logo that a plugin puts in its title as a picture (Meow Apps) becomes the entry’s icon, so it looks like the others on every screen, including where the plugin’s own styles are not loaded and in the Administrators and Developers menus.
- In Developers › Settings, pages with the same name get the menu they came from after it, such as Plugin Check (Tools) and Plugin Check (Settings).
- **Choose places**: the options panel lists this site’s entries (every top-level entry, and plugins’ pages in WordPress’s menus) with a **Place** list for each: a section, or for pages, inside any menu. Choosing a place writes its line in **Move menu entries**; choosing the usual place removes it. Reload to see the menu change.
- **Move menu entries**: one per line, a menu address or plugin folder, `=`, then a place: `top`, `content`, `communications`, `seo`, `shop`, `admin-heading` (under the Admin heading, after the two menus), `administrators` (or `admin`), `developers` (or `super-admin`), or another menu’s address to go inside it, for example `elementor = content` or `wpcf7 = administrators`. To move one page out of a menu, write `menu>page = place`.
- Moved pages keep their usual addresses, and WordPress checks access to them as before: the menu is only rearranged while it is printed. Nothing runs on the front end. Which plugin owns each page is worked out once and kept in the `seoprostack_admin_menu` option until plugins change. With Load plugins only where needed, plugins a screen skips still count as active, so the kept owners stay the same on every screen, and a skipped plugin’s menu entries keep their places using the owner learned on screens that load it.
- Some plugins move menu entries with their own scripts, matching them by name (MasterStudy LMS moves any entry called “Analytics” above Posts). The menu script puts the printed entries back in order as the page loads; entries such scripts add are left where they put them.
- **Client safeguards** (on by default): people who are not developers do not see the Developers menu or open its pages, cannot install, delete or edit the code of plugins and themes, do not see or switch developer plugins (those placed in Developers, and SEO Pro Stack) on the Plugins screen, cannot edit or delete developers, cannot make anyone an administrator or change an administrator’s role, and cannot change SEO Pro Stack’s settings. Updates keep working.
- **Developers** are super admins on multisite. On single sites, tick them under **Developers**; when the feature is switched on with nobody ticked, every administrator is ticked, so untick client accounts. You always stay on the list, and if no ticked person is an administrator any more, every administrator counts as a developer, so nobody is locked out.
- Replaces Admin Menu Editor and Admin Menu Editor Pro. Their settings are not imported: places come from the rules above, so every site gets the same menu. Admin Menu Editor’s own settings are left alone; while it is active, this feature waits.

### Dashboard and sidebar widgets (Admin)

- **Hide dashboard widgets** for everyone, such as WordPress Events and News, the welcome panel or plugin promotions. Widgets added by plugins appear in the list after the Dashboard is next opened.
- **Disable sidebar widgets** you never use (the Meta widget by default); they disappear from the Widgets screen, the Customizer and sidebars.

### Tidy the dashboard (Admin)

Lays out the Dashboard the same way on every site, from rules made from 12 sites arranged by hand with Admin Menu Editor Pro (`admin/data/dashboard.php`):

- **Columns**: first WordPress’s browser and PHP update warnings when shown, then Quick Draft, Activity and WooCommerce reviews; then Fluent Forms, Fluent Support and FluentSMTP statistics; then visitor statistics (Burst), Rank Math, At a Glance and Site Health. Widgets that are not listed stay in the column their plugin chose, below the listed ones.
- **Hidden for everyone**: the Welcome panel, WordPress Events and News, WooCommerce Status (it repeats WooCommerce → Home), Pretty Links quick add and Link Whisper’s link health box.
- **Developers only**: Site Health status and Debug Log Manager. Developers are the ones chosen in Organise the admin menu; without it, people who can manage options (super admins on multisite).
- **People who can publish**: At a Glance and the form, support and email statistics, so contributors do not see them. People who cannot edit posts, such as subscribers and customers, see no boxes.
- Everyone gets the same layout and boxes cannot be dragged. **Let people rearrange boxes** (off by default) allows dragging and keeps each person’s own arrangement; until someone rearranges, they get the layout above. Saved arrangements are never deleted, so switching the feature off gives them back.
- Works alongside Hide dashboard widgets: boxes it hides stay hidden.

### Notification emails (Admin)

Stop routine emails one by one: new user notices, password and email change notices, comment notices, and WordPress, plugin and theme auto-update reports. Each is stopped with the core filter that sends it, so nothing else changes. Password reset links are only ever stopped for administrators, and failed core updates are still reported.

### Hide admin notices (Admin)

Moves plugin and theme notices behind a bell at the right of the admin bar, so pages open at their content. The bell opens a panel over the page with the notices, which can still be read and dismissed there. The admin bar is the one place no admin screen draws over, so the bell never sits on a plugin’s own header, and the page never moves.

- Kept on the page: messages about what you just did (such as “Settings saved”), inline notices inside the page’s content (inline notices printed above the page are moved), and notices that scripts add after you first click or type, since they answer what you did.
- Optionally keep errors, or warnings and the WordPress update message, on the page. On screens that hide every notice themselves, such as WooCommerce’s and Rank Math’s, kept notices go in the panel so they can still be read.
- Also caught: notices printed inside another plugin’s wrapper, notices that scripts add while the page loads, WooCommerce’s lasting notices (such as connect prompts and database updates, which use the same `#message` id as “Settings saved”), and plain boxes of text, links and pictures printed above the page without notice classes (such as MainWP Child’s connect message). Boxes with headings, tabs, lists, forms or buttons are a plugin’s own header or tools and stay. A notice drawn by React or Vue (such as WooCommerce Analytics’) stays hidden in its place and the panel shows a copy; dismissing the copy dismisses the original.
- The bell sits next to the account menu (“Hi, …”), or left of the Plugins menu when that is on. Other plugins’ admin bar items go to the left of it.
- The bell is on every admin screen. A dot in the admin bar’s text colour (white with the default colours) shows there are notices; with none, the panel says “No notices.” Since the bell never appears, disappears or changes width, nothing on the bar moves when the notices are counted. Screen readers hear the count (“Notices (2)”). On phones the bell joins WordPress’s icons and the panel fills the width.
- **With Load plugins only where needed**: a screen that skips a plugin also skips its notices. Notices that skipped plugins print on screens that load every plugin are kept for each person (only visible notice boxes; other things printed there, such as buttons above a plugin’s own list, belong to that screen and are not kept) and shown behind the bell on screens that skip them, so they can be read and dismissed anywhere. A kept notice goes when its plugin stops printing it on the screen it came from, when it is dismissed (the dismiss button, or a link or button such as “Dismiss”, “Hide” or “No thanks”), or after 12 hours, since links in notices carry security tokens that expire. On screens that load the plugin, the plugin shows its own notice as usual. Kept notices are in the `{prefix}seoprostack_stored_notices` user option, removed on uninstall.
- Like the other admin bar menus, pointing at the bell opens the panel and moving the mouse away closes it. It stays open while you type in a field inside it.
- The bell works from the keyboard and on touch screens: Enter, Space or a tap opens the panel and keeps it open; Escape, the bell again or clicking elsewhere closes it.
- Notices are hidden with CSS until they are moved, so they do not flash or push the page down. Without JavaScript they stay on the page.
- The block editor has its own notices and is left alone.
- Add the class `sps-keep` to a notice to keep it on the page.
- **Show example notices** (off by default) adds one notice of each kind to every admin screen for administrators, to see where they go: information, success, warning and error behind the bell, and one with `sps-keep` on the page. Reload the page after changing it.

### More menu in the admin bar (Admin)

When many plugins add items to the admin bar, it wraps onto a second line that covers the top of the page and makes it hard to click. With this on, the items plugins and themes add to the left of the bar go into one **…** menu, last on the left side: after WordPress’s own items (+ New, Edit and the like) and any items you keep on the bar, in wp-admin and on the site. Point at **…** to open it, like the other admin bar menus; it closes when you move away. Moved items keep their own submenus.

- **Keep on the bar**: tick plugins (or a theme or must-use plugin) whose items should stay where they are. Those that have added items are listed first, marked “adds items”. SEO Pro Stack’s own items (such as Duplicate) stay by default.
- Settings save straight away, but the bar on the settings page was drawn before the change, so the saved message asks you to reload the page.
- WordPress’s own items and the right of the bar (the account menu, the notices bell, the Plugins menu) are not moved. To remove WordPress items, use Hide admin bar items.
- Items are matched to the plugin that added them. If another plugin replaces WordPress’s admin bar class, WordPress’s own items are recognised by name, every other item goes in the menu, and Keep on the bar does not apply.
- Items that show only an icon or numbers get their plugin’s name added in the menu.
- On phones and tablets, tap **…** to open it. WordPress hides plugins’ items there; the menu shows them, with their submenus open.
- From the keyboard, Enter opens it and Escape closes it. Opening and closing are WordPress’s own, as for every admin bar menu.

### Hide admin bar items (Admin)

Removes WordPress items you do not use from the admin bar, for everyone, in wp-admin and on the site. **On by default**, so the bar starts tidy; switch it off to get every item back.

- **Hide**: Comments and + New are ticked by default. Also offered: the WordPress logo menu, My Sites, Site name, Edit site, Customise, Updates, the command palette, Edit, View and Preview, Shortlink and Search. Their submenus go with them.
- The account menu (with Log Out) and the menu button on phones in wp-admin are never offered, so they always stay.
- Some items appear only on certain screens, such as Edit on the site or View in the editor.
- Reload the page after a change to see it; the saved message says so.
- Works with or without the More menu.

### Readable list columns (Admin)

Keeps the title column of post, page, user, media and plugins’ lists wide enough to read. WordPress gives its list columns fixed widths, and the title gets what is left; every plugin that adds a column with a width of its own (SEO details, privacy scans, page views, post type, sticky) takes from it, until the title is a letter wide and runs down the page.

- When the main column of a list is narrower than a fifth of the table, or another column has been squeezed to nothing (columns without a width of their own get none once the others add up to more than the table), it gives the main column a quarter of the table and narrows the other columns towards the narrowest they can be without breaking words. Narrow columns whose content fits (checkboxes, icons, counts) keep their width. When even that is not enough room, the main column gives up some of its quarter, down to 120 px (or 12% of the table), before other columns break words. Lists with room to spare stay as they are.
- Checked again when columns are switched on or off in Screen Options and when the window changes size. On phones, where WordPress stacks columns under the title, nothing changes.
- A small script on list screens; nothing is stored. To remove columns rather than narrow them, untick them in Screen Options.

### Avatars without Gravatar (Admin)

Serves every avatar from your own site. By default WordPress loads avatars from Gravatar, which tells Gravatar who visits your pages and publishes a hash of each commenter’s email address. With this on, nothing is loaded from or sent to Gravatar.

- **Profile pictures**: people upload a picture on their profile screen (Users → Profile), where WordPress would otherwise point them to Gravatar. It is cropped to a square, stored in four sizes (64 to 512 pixels) and the smallest size that fits is used. Photo details such as location are removed. JPEG, PNG, GIF and WebP are accepted. Replacing or removing a picture, or deleting the user, deletes the files. This part can be turned off.
- **Everyone else** gets the default chosen under Settings → Discussion, drawn on your site: a silhouette, a pattern that differs per person (made from a keyed hash of the email address, so it cannot be traced back), or nothing. Other stored styles, such as Identicon, Retro or Avatar Privacy’s Birds, show the pattern; Mystery Person and the rest show the silhouette.
- Guests are never matched to accounts by email address, so a guest cannot show someone else’s picture.
- Avatars that other plugins set (anything not from Gravatar) are kept. The admin bar, comment and user lists, the REST API (`avatar_urls`) and the block editor all use the same avatars.
- Generated files keep their names, so cached pages keep working. Files are stored in `uploads/seoprostack-avatars/` (on multisite, profile pictures are in the main site’s uploads).
- Switches on if Avatar Privacy is active, and copies its uploaded profile pictures, since deleting Avatar Privacy deletes them. Pictures are also copied when Avatar Privacy is deactivated, and otherwise the first time they are shown. A picture that cannot be processed is tried again the next day, keeping any earlier copy, and a picture uploaded or removed during a copy is never overwritten. Avatar Privacy lets people opt in to Gravatar; this feature never uses Gravatar, so that choice is not imported. While Avatar Privacy is active, it keeps handling avatars.

### Duplicate posts (Content)

Adds **Duplicate** to post lists, the editor and the admin bar for the post types you choose. The copy is always a new draft; the original is never changed. Choose what else is copied: excerpt, author, featured image, terms, custom fields (including SEO settings), template, format, menu order, password and date.

### Staged new versions (Content)

**New version** on a published post creates a draft copy that remembers its original. Edit, preview or share it while the live post stays as it is. Publishing the copy, now or scheduled, copies it over the original — title, content, excerpt, terms, custom fields, featured image, template and format — so the address, comments and publish date are kept and WordPress stores a revision. The copy is then deleted.

- The copy never goes live at its own address, so sharing, pings and sitemaps are not triggered for it.
- In the block editor, custom field boxes are saved before the copy is merged. If the tab closes first, a background task finishes the merge within minutes.
- If the original cannot be updated, the copy stays as a draft so no edits are lost.

### Shareable preview links (Content)

Tick **Share a preview link** in the editor of a draft, pending or scheduled post to get an address anyone can open without an account.

- Links expire after 1–90 days (7 by default) and stop working when turned off or when the post is published.
- Preview pages send `noindex`, `no-referrer` and no-cache headers and ask page caches not to store them.

### Sticky posts for any post type (Content)

Adds a star column to the lists of the post types you choose, and a **Stick to the top** option in the editor for types other than posts. Sticky items lead the first page of the blog home, their post type archive and chosen term archives (categories, tags or custom taxonomies). It uses core’s sticky list, so existing sticky posts, themes and blocks keep working.

### Select all across pages (Content)

Tick the “select all” box in a post list with more than one page and a bar offers **Select all N items**: every item matching the current filters, search and status. Bulk actions (Move to Bin, Restore, Delete Permanently, Edit and plugin actions) then apply to all of them, still checked against each item’s permissions. Unticking any row clears the choice.

### Load pages before the click (Speed)

Uses the browser’s Speculation Rules to download (or fully prepare) a page when a visitor points at or starts to tap a link to it. On WordPress 6.8 and later it tunes core’s built-in speculative loading; earlier versions get the rules added directly. Admin, login, file and query-string links are always skipped; add your own exclusions such as `/cart` or `logout`. Browsers without Speculation Rules ignore it. Logged-in users are not affected.

**Also in the admin** (off by default) downloads admin screens when you point at their links, with the same “When to start” setting. Screens are only downloaded (prefetch), never fully prepared, so their scripts do not run before the click. Skipped: links whose query string contains `action`, `nonce`, `dismiss` or `download`; screens that change something when opened or are slow to build (`post.php`, `post-new.php`, `customize.php`, `site-editor.php`, `update-core.php`, `update.php`, `upgrade.php`, `plugin-install.php`, `theme-install.php`, `admin-ajax.php`, `admin-post.php`, `async-upload.php`); `#` and `download` links, anything in a `.no-prefetch` element, and your own exclusions.

### Delay scripts until interaction (Speed)

Scripts whose tag or code contains a keyword you list (for example a chat widget) are held back until the visitor moves, scrolls, taps or types, or until a time limit passes, then run in their original order. Pages can be excluded by address. Add `data-seoprostack-nodelay` to a script tag to never delay it. Logged-in users, previews and feeds are not affected.

### Delayed Google Analytics (Speed)

Adds Google Analytics 4 (standard gtag.js) with your measurement ID, loaded after the first interaction or after a few seconds so it does not compete with the page. Logged-in users are not tracked. Turn off any other plugin that adds the same ID.

### Faster editor with Kadence Blocks (Speed)

Kadence Blocks prints its whole design library, with every pattern’s HTML, into each block editor screen as the `kadence_blocks_params_library` script variable. On a test site with the library cached that was about 15 MB, so a new post screen weighed about 24 MB. This turns the preload off with Kadence’s own `kadence_blocks_preload_design_library` filter (Kadence does the same when Gravity Forms is active). The editor then fetches the library from Kadence’s `kb-design-library/v1/get_library` REST route the first time you open the design library, so only people who use it wait for it. Without Kadence Blocks it does nothing. A filter of your own on `kadence_blocks_preload_design_library` at a priority above 10 still decides.

### 410 Gone for removed pages (Links)

Answers “410 Gone” instead of “404 Not Found” for addresses you list, so search engines drop them sooner. Visitors still see the theme’s not-found page.

- One address per line, as a path (`/old-page/`) or a full address on this site. End with `*` to include everything below it (`/old-shop/*`).
- Only addresses that would otherwise be “not found” are affected, so a live page cannot be taken down by mistake.
- Optionally, published content deleted from the bin is added to the list automatically.

### Short addresses for custom post types (Links)

Serves items of the post types you choose at `/item-name/` instead of `/type/item-name/`, the way pages are served, for example `/blue-shirt/` instead of `/product/blue-shirt/`.

- Links everywhere (menus, sitemaps, the editor, shop listings) use the short address, because WordPress builds them through `post_type_link`.
- Old addresses keep working and redirect (301) to the short one, including split pages (`/product/item/2/`) and query strings. Rewrite rules do not change, so nothing needs flushing and turning the feature off restores the old addresses.
- Pages, posts and categories keep their addresses. When a published page or post has the same name as an item, the item keeps its old address, and the options panel lists those names. Items of hierarchical types keep their parents in the address (`/parent/child/`).
- Feeds, embeds and comment pages of an item work at the short address. Archives such as `/product/` keep theirs.
- Only post types with a fixed base are offered; a base with tags such as `%product_cat%` cannot be removed.
- Replaces Remove CPT base and imports its chosen post types.

### Short links (Links)

Short addresses on your site, such as `/go/offer/`, that send visitors to another address. Manage them under **Short links** in the admin menu; anyone who can edit pages can add and change them.

- Each link has a name (only shown in the admin), a short address, where it goes, a 301, 302 or 307 redirect, a note and categories. New links get a random address, starting with the prefix you choose (such as `go/`), and the defaults from the settings panel.
- **Review links**: the first time someone who can add links opens the admin with the feature on, three published 302 links are added: `/googlereview/` (to `https://google.com`), `/facebookreview/` (to `https://facebook.com`) and `/trustpilotreview/` (to `https://trustpilot.com`), in the **Review Requests** category (the site’s own category of that name if it has one). Under **Goes to**, each says where to find the brand’s real review page to put there, and suggests using the short link in email signatures and review requests. An address already used by a short link, a page or post, or a Pretty Links link still to import is skipped. They are added once per site, so deleted ones do not come back.
- **Nofollow** sends `X-Robots-Tag: noindex, nofollow` with the redirect, and **sponsored** adds `sponsored`, the same as Pretty Links. Redirects are sent with no-cache headers, so changing where a link goes takes effect straight away.
- **Clicks**: each click, and each visitor’s first click (a one-year cookie per link, holding only `1`), is counted after the visitor has been sent on. Bots, link previews, scripts and HEAD requests are not counted. Only totals are stored. The list can be sorted by clicks and searched by name, address or target.
- Only published links work: save a link as a draft, or move it to the bin, to turn it off. Addresses ignore case and query strings, and WordPress’s own addresses (`wp-admin`, `feed`, `sitemap.xml` and so on) cannot be used. Saving a link with the address of a page or post warns that the link now takes over that address.
- Live links are kept in one small autoloaded option, so requests that are not short links cost an array lookup and no database query. The link is matched before WordPress looks up the page.
- Replaces Pretty Links: imports its defaults for new links (redirect, nofollow, sponsored, click counting and the Pro address prefix), and switches on if Pretty Links is active with links. Its links are imported with their address, target, redirect, options, name, description, date, on or off, click and unique visitor counts and categories when Pretty Links is deactivated, with **Import links** in the settings panel, or with `wp seoprostack short-links import`. Links already imported, deleted in Pretty Links, or whose address a short link already has are left out, so importing again is safe. Pretty Links’ data is only read. Cloaked and other redirect types become 302 redirects. Its click history (individual clicks, referrers, IP addresses) and Pro keyword replacements are not imported.

### Publishing queue (Content)

Publishing a post from the editor without choosing a date schedules it for the next free time slot instead.

- Slots are times of day (for example `09:00, 15:00`) on the days you choose, in the site timezone. Each slot takes one post.
- Dates you choose yourself (future or past), updates to posts that are already published or scheduled, and saves from imports, WP-CLI, cron, XML-RPC or API clients are left alone.
- Queued posts are normal “Scheduled” posts and WordPress publishes them. Turning the feature off leaves them scheduled.
- Choose which content types use the queue.

### iFrame block (Content)

Adds an **iFrame** block to the editor (Embed category).

- Width and height, or an aspect ratio (16:9, 4:3, 1:1, 9:16…).
- Lazy loading, full screen, border.
- Sandbox with per-permission checkboxes (on by default), permissions policy (camera, microphone, autoplay, payment…) and referrer policy.
- Optionally pass the page’s URL parameters (such as UTM tags) to the embedded page.
- Allowed domains: limit which sites can be embedded (subdomains included).
- Who can add iFrames: contributors, authors, editors, or only users who can add any HTML. Checked in the editor and again when the page is shown, using the post author’s role.
- Output is built on the server from validated settings, so stored content cannot inject HTML. Turning the feature off hides existing iFrame blocks.

### Website screenshots (Content)

Adds a **Screenshot** block (Embed category) and Browser Shots’ `[browser-shot]` shortcode. Each page is captured once by a screenshot service, saved to the Media Library and shown from your site, so visitors never contact the service and free allowances are used once per screenshot, not once per page view.

- Services: Thum.io (default, no key), Microlink (free daily allowance, key optional), ApiFlash and Screenshot Machine (your key). Set the key in the settings or as `SEOPROSTACK_SCREENSHOTS_KEY` in `wp-config.php`.
- Pages are captured in a browser window 1920 × 1080 by default. The block’s shape (16:9, 4:3, square…) sets the window’s height. Saved as JPEG (default) or PNG, with the usual smaller sizes and `srcset`.
- Block settings: alt text, caption, shape, width on the page, alignment, margin, and border, rounded corners and shadow (shadow needs WordPress 6.5 or later), which frame the picture and leave the caption outside. Link to the captured page, the post, another address or nothing, with new tab, nofollow, sponsored and user-generated (`ugc`) options.
- The editor takes the screenshot when you enter the address, for users who can upload files. Otherwise it is taken in the background when the post is published or first viewed; until then a box with the site name shows. A toolbar button takes a new one.
- Screenshots are renewed in the background after 30 days (0 keeps them). The new one replaces the old one in the Media Library.
- Pages that cannot be reached (unknown sites, private or local addresses, 404 and 410) are not captured and are tried again after an hour. The last failure shows in the settings panel.
- Browser Shots content keeps working: `[browser-shot url="…" width="600" height="450"]Caption[/browser-shot]` with its `alt`, `link` (or `href`, and `PERMALINK`), `target`, `class`, `image_class`, `rel`, `display_link` and `post_links` attributes, and its block, which the editor offers to convert to a Screenshot block. The width and height set the shape; the browser window keeps the set width.
- Switches on if Browser Shots is active (it has no settings to import). Browser Shots loads mShots images from WordPress.com on every page view; after switching, pictures come from your Media Library.
- Screenshots are never watermarked by Watermark pictures.

### Spectra block replacements (Content)

Lets a site stop using Spectra (Ultimate Addons for Gutenberg) without losing content, so the theme and core blocks, or Kadence Blocks, do the work.

- Adds a **Term list** block (Widgets category): the terms of any public taxonomy as a list (with child terms, post counts, list markers, spacing and link colours; palette colours are stored as palette references, so they follow dark mode switchers such as Kadence’s), a grid of boxes with “3 Posts”-style counts, or a drop-down that opens the chosen term. Built on the server, with up to 1,000 terms.
- Spectra’s Taxonomy List saves no HTML, so after deactivating Spectra its blocks would show nothing. They are drawn by the Term list instead, with the same taxonomy, layout, counts, hierarchy, colours and spacing.
- Other Spectra blocks keep their saved text, links and pictures. Until they are converted, pages with Spectra images, buttons or testimonials get a small stylesheet in place of Spectra’s (an option, on by default). Spectra’s per-block colours and sizes are not kept.
- In the editor, Spectra Heading, Image, Buttons, Testimonial and Taxonomy List blocks get a **Convert** button (and **Convert all**) that rebuilds them as core Heading and Paragraph, Image, Buttons, Quote and Term list blocks, keeping text, links (new tab, nofollow), alt text, captions, alignment and text colours. Other Spectra blocks are left as they are. Nothing changes until the post is saved. Before a post with Spectra blocks changes, its current version is stored as a revision, even if it was never edited before (imported posts, for example), so Revisions can bring it back.
- The options panel lists the posts that still contain Spectra blocks, with edit links.
- Switches on if Spectra is active and its blocks are in use (settings version 5). Spectra has no settings to import; its blocks carry their own styles.

### Copy linked images to Media Library (Media)

When a post is saved, images linked from other sites are copied into the Media Library, resized, attached to the post, and the content is changed to serve the local copy.

- Works with the block editor, the classic editor and programmatic saves by users who can upload files.
- Images already imported from the same address are reused instead of uploaded again.
- Optional maximum width and height: larger images are scaled down before upload.
- Excluded domains (and their subdomains) are left alone. Your own site is always excluded.
- File name and alt text patterns support tokens such as `%filename%`, `%post_title%` and `%date%`. Existing alt text is kept.
- Up to 10 images are imported per save; the rest are imported on the next save.

### Paste into the Media Library (Media)

Paste screenshots, pictures and files from the clipboard (Cmd/Ctrl+V) and they upload straight away.

- **Media Library, media dialog and Add New Media File**: pasted files go through WordPress’s own uploader, the same way as dropped files, so allowed file types, size limits and permissions are unchanged and progress shows as usual. The media dialog works in the block and classic editors.
- **Classic editor**: pictures are uploaded, attached to the post and inserted as a normal image (large size where there is one). Anything copied with text, such as part of a web page or document, is left to the editor. This part can be turned off.
- Pictures from the clipboard have no name of their own, so they are named from a pattern (`%post_title%`, `%user%`, `%date%`, `%time%`); without a post title, `%post_title%` becomes `pasted-image`. Copied files keep their names.
- Optionally save pasted pictures as JPEG or WebP at a chosen quality. WebP falls back to JPEG when the site does not allow WebP uploads. The original is kept when the converted file is not smaller or the browser cannot write the format. GIFs and copied files are never converted.
- The block editor already uploads pasted images and is left alone.
- Imports The Paste’s image quality, classic editor switch and file name, and switches on if The Paste is active. Its per-user options are not imported. While The Paste is active it keeps handling the classic editor, so images are not uploaded twice.

### SVG uploads (Media)

Lets chosen roles upload SVG files, and cleans every SVG as it is uploaded.

- Only known SVG drawing elements and attributes are kept. Scripts, event handlers, `<foreignObject>` and HTML, `set` and `animate` (which can change links), comments, style sheets that import or load other files, `data-*` attributes and links to other files are removed. Links on `<a>` may go to web and email addresses; `<image>` may only hold embedded PNG, JPEG, GIF or WebP pictures.
- Files are refused when they are not well-formed SVG, use a DOCTYPE with entities, use an encoding other than UTF-8 or Latin-1, are larger than 10 MB, or repeat shapes with `<use>` so often (or in a loop) that browsers would hang.
- Uploads through the Media Library, the editors, REST and sideloads are cleaned before they are stored. Files added without an upload (importers, XML-RPC) are cleaned when WordPress makes their metadata.
- SVGs get a width and height from their `width`/`height` or `viewBox`, so they show and insert like other images; every image size points at the same file.
- Who can upload SVGs: chosen roles (default administrators, editors, authors, contributors and shop managers) who can also upload files. On multisite, network admins always can.
- Imports Safe SVG’s upload roles (or, when it had none, every role that can upload files), and switches on if Safe SVG is active. The Safe SVG block is not replaced; none of the surveyed sites used it.

### Resize large uploads (Media)

Scales pictures larger than a set width or height (default 2560, WordPress’s own limit) down when they are uploaded. WordPress keeps the huge original next to a scaled copy; this saves only the smaller picture, under the uploaded name.

- Works for the Media Library, the editors, REST and sideloads. The same limit is used as WordPress’s “big image” limit, so WordPress 7.1 uploads that the browser processes are scaled to it too.
- JPEG and WebP quality (default 82). A resized picture is only kept when its file is smaller.
- BMP pictures are saved as JPEG (on by default). PNG photos can be saved as JPEG when that is smaller; PNGs with transparency are left alone.
- Animated GIFs and files with “noresize” in their name are left alone. Pictures are turned upright, and camera details, captions and credits are kept.
- Optionally delete the original WordPress keeps for pictures it scales, rotates or converts, after it has made the smaller sizes.
- Existing pictures: **Resize to … px** row and bulk actions in Media → Library (list view), or `wp seoprostack resize-images [--dry-run]`. Files keep their names and addresses; bulk resizing stops after 20 seconds and says how many are left.
- Imports Imsanity’s largest size limit (unless it is Imsanity’s default, 1920, so 2560 is used), quality, BMP and PNG conversion and “delete originals”, and switches on if Imsanity is active. When CompressX is active with its resizing on, switches on with CompressX’s limit, since WebP and AVIF images replaces the rest of CompressX.

### Replace media files (Media)

Adds **Replace file** to Media Library items (row action in list view, and a button in the attachment details). Upload a new file and the item keeps its ID, title, alt text, caption and every place it is used.

- **Keep the file name**: the new file takes the old name and address, so links from other sites keep working. It must be the same type. Browsers may show the old file until their cache clears.
- **Use the new file’s name**: any type; the file is saved in the old file’s folder.
- The old file, its smaller sizes and any edited copies are deleted, and new sizes are made. The upload date can be kept or changed to now.
- Links to the old file and each size are changed to the new file (and the same size, or the full file when the new file has no such size) in post content and excerpts, and in custom fields holding text, JSON (page builders) or serialized arrays. Published, scheduled, draft, pending and private posts are updated; custom fields holding objects are left alone.
- The new file goes through the normal upload checks, so allowed file types, SVG cleaning and Resize large uploads apply.
- Only people who can upload files and edit the item can replace it.
- Imports Enable Media Replace’s last-used choices (file name and date), and switches on if it is active.

### WebP and AVIF images (Media)

Saves a smaller WebP and AVIF copy of every JPEG and PNG picture in the Media Library, and of every size of it, next to the file (`photo.jpg.webp`, `photo.jpg.avif`; WebP pictures get an AVIF copy). Browsers that accept those formats are sent the copy at the same address, so pages, the editors and the Media Library keep the original addresses and nothing else changes. Originals are never changed.

- Pictures are converted in the background with WP-Cron, newest first, 20 seconds at a time: new uploads, edited and replaced pictures, and every existing picture. The settings panel shows progress; the attachment details show each copy’s size.
- A copy is only kept when it is smaller than the original. Animated WebP and PNG pictures are left alone. Pictures are turned upright.
- Quality: WebP 80 and AVIF 60 by default; changes apply to pictures converted afterwards (`wp seoprostack convert-images --force` makes every copy again). PNG conversion can be turned off.
- AVIF needs WordPress 6.5 or later and an image library that can write it; the setting says whether this server can. Without it only WebP copies are made.
- **Apache and LiteSpeed**: rules in the uploads folder’s `.htaccess` send the copy when the browser’s `Accept` header allows it, with `Vary: Accept`. **Keep pictures out of shared caches** (on by default) also sends `Cache-Control: private`, so CDNs that ignore `Vary`, such as Cloudflare’s free plan, never give AVIF to a browser that cannot show it. If the file cannot be written, the panel shows the lines to add.
- **Nginx** does not read `.htaccess`; the panel shows the configuration lines to add.
- **Multisite**: every site’s uploads are below the main site’s uploads folder, so one block of rules serves the whole network. It serves the formats any site uses and is removed when no site uses the feature. Each site converts its own pictures with its own settings.
- Existing pictures: **Make WebP and AVIF copies** bulk action in Media → Library (list view), or `wp seoprostack convert-images [--force] [--dry-run]`.
- Deleting a picture deletes its copies. Deactivating the plugin removes the rules, so browsers get the originals; deleting it also deletes the copies.
- Imports CompressX’s formats, quality level, PNG exclusion and cache setting, and its resize limit into Resize large uploads, and switches on if CompressX is active. Copies CompressX made in `wp-content/compressx-nextgen` are moved next to the pictures instead of being made again, so they keep working after CompressX is deleted.

### Watermark pictures (Media)

Lays your site icon, site logo or a picture you choose faintly over pictures as they are uploaded. By default the site icon goes in the bottom-right corner at 30% opacity, at up to 10% of the picture’s width and height, so it looks the same on every size.

- Position (nine places), size and distance from the edge are shares of each picture, so the mark sits in the same place on the full picture and its sizes. A picture with a transparent background works best; SVG watermarks cannot be used.
- The file, the original WordPress keeps for big pictures and every size at least 400 pixels on its shorter side are marked; thumbnails and small sizes stay clean. Files with `nowatermark` in the name, animated pictures, site icons, logos and headers are left alone. The `seoprostack_watermark_attachment` filter can leave others alone.
- Marked after WordPress has made every size and after Resize large uploads, including WordPress 7.1 uploads that the browser processes.
- **Keep unmarked originals** (on by default) copies the original into a folder with a random name in uploads, so a watermark can be removed, or added again with new settings. Pictures marked while it is off cannot be unmarked.
- Existing pictures: **Add watermark** and **Remove watermark** row and bulk actions in Media → Library (list view), or `wp seoprostack watermark-images [--remove] [--dry-run]`. Adding to a marked picture marks it again from its original with the current settings. **Mark new uploads** can be turned off to mark only chosen pictures.
- Replacing a picture’s file marks the new file; deleting a picture deletes its kept original. WebP and AVIF copies are made again from marked pictures.
- Uses Imagick or GD, whichever WordPress uses.
- Imports Easy Watermark’s image watermark (preferring one added to new uploads): the picture, position, opacity, scale and backup choice, and switches on if Easy Watermark is active with it added to uploads. Text watermarks are not imported. Pictures Easy Watermark already marked are left alone.

### Plugins menu in the admin bar (Plugins)

Adds a plugin icon to the right of the admin bar, in wp-admin and on the site. It opens a one-column list of every plugin, which scrolls when it is taller than the window; active ones are bold. Choosing one asks “Activate …?” or “Deactivate …?”, then runs WordPress’s own activate or deactivate action and returns you to the page you were on.

- The icon sits next to the account menu, with the notices bell (when Hide admin notices is on) and then other plugins’ admin bar items to its left.
- Only shown to people who can activate plugins, and only lists plugins they may switch.
- With Load plugins only where needed on, plugins skipped on the current screen still show as active; hover one to see that it is not loaded there. Above the list, a line says how many plugins the screen loaded, with a link to reload it with every plugin.
- If the page you were on belonged to the plugin you switched off, you land on the Plugins screen instead of an error.
- Network-activated plugins are left to the Network Plugins screen.
- Plugin names are cached and refreshed when plugins change or the Plugins screen opens, so page loads do not read plugin files.

### Plugin sizes (Plugins)

Adds a **Size** column to the Plugins screen: each plugin’s total disk use, split into PHP, JavaScript, CSS, media and other files. Click the heading to sort largest or smallest first. Large PHP and JavaScript totals often, but not always, mean more work on every page.

- Two rows below the list total every installed plugin and the active ones (network-activated ones on the Network Plugins screen), whichever view is open. Plugins outside the view are measured after those on screen.
- The screen opens straight away; missing sizes are measured in the background a few seconds at a time.
- Sizes are kept until the plugin’s version changes.

### Plugin presets (Plugins)

SEO Pro Stack keeps its chosen settings for other plugins, chosen for speed, privacy and quiet admin screens. With this on, each plugin that has a preset says on the Plugins screen whether its settings match (**Preset: settings match**, or **Preset: 3 settings differ**, which opens to list them, what the preset changes and why). Its row offers:

- **Apply preset**: set the chosen settings. Other settings stay as they are.
- **Reset to defaults**: set the same settings to the plugin’s own defaults.
- **Undo preset** or **Undo reset**: put back the settings from before the last apply or reset.

Each asks first, naming the plugin. **Apply presets** and **Reset presets to defaults** are also bulk actions; plugins without a preset are skipped. Presets never store or change licence keys, API keys, site keys, passwords, tokens or similar: option names and keys that look like one are left out wherever they appear, and stay as they are when settings change or are undone. Only people who can manage options and activate plugins see this; on multisite it works on each site’s Plugins screen.

Presets so far: Antispam Bee and Simple CAPTCHA with Cloudflare Turnstile. They are JSON files in `presets/`, one per plugin folder. AGENTS.md → Plugin presets explains how they are made and checked.

WP-CLI works whether or not the setting is on:

- `wp seoprostack presets list`: each preset, the version it was chosen with, the installed version, how many settings differ and what can be undone.
- `wp seoprostack presets diff <plugin> [--defaults]`: the settings that differ from the preset or from the defaults.
- `wp seoprostack presets apply <plugin>… | --all`, `reset <plugin>… | --all`, `undo <plugin>…`.
- `wp seoprostack presets export <plugin> [--options=<names>] [--like=<patterns>] [--compare=<file>]`: print settings as a preset, without secrets, warning about values that hold the site’s address or an email address. With `--compare` (an earlier export from a fresh install) only changed settings are kept, with the earlier values as the defaults.

### Hosting needs (Plugins)

Checks whether the hosting fits the site, and says what to ask the host for. It uses numbers PHP reports, not rules of thumb:

- **OPcache**, which keeps compiled PHP in memory so it is not compiled again on every request. Its memory, file and shared-string limits are compared with how full it is and with the PHP code that can load: WordPress, the theme, must-use plugins, drop-ins and active plugins (not all of it loads on every request, so this is an upper limit). Compiled code takes more memory than its source: the ratio is measured from OPcache’s own list of scripts when the server allows it, and taken as 2.6 otherwise (measured on a WooCommerce test site). When it is nearly full, has had to start again, or has fewer file slots than the site has PHP files, it suggests `opcache.memory_consumption`, `opcache.max_accelerated_files` or `opcache.interned_strings_buffer` values large enough for all the code that can load, so a full OPcache does not hide how much it needs. The Site Health Info tab also gives the settings for the code of every installed plugin, for sites about to switch more on. Hosts that keep OPcache’s status private (`opcache.restrict_api`) get a check against the code size only.
- **PHP memory**: the most memory a request used in the last 7 days, for pages, the admin (with AJAX) and the REST API with cron, against the limit that request ran under. From 80% of the limit it suggests a higher `memory_limit` (`WP_MAX_MEMORY_LIMIT` for the admin). Each request compares its peak with the day’s highest when it ends and writes only a new highest, so there are a few writes a day, to one small option. WP-CLI is not recorded.
- **Memory per PHP worker**: the highest use plus 32 MB for PHP itself, with OPcache’s memory shared by all workers.
- **Traffic**: 1 in 20 requests that reach PHP records how long it took and the hour it ran in, in a small option that is not autoloaded, kept for 7 days. Requests a page cache serves never reach PHP, so they are not counted, which is what sizing PHP needs. Change the rate with the `seoprostack_hosting_sample_rate` filter (0 stops it).
- **The site**: database size, rows of post data, products and options loaded on every request (from table statistics, read at most hourly), whether a page cache or persistent object cache is in use, and whether shop, membership, course or community plugins are active, where more visitors skip the page cache and pages take longer. It advises a page cache when none is found, an object cache above 500,000 rows of post data or 10,000 products, trimming options loaded on every request above 1 MB, and PHP 8.2 or later.

**Hosting to buy.** A table gives PHP workers, RAM, CPU cores, object cache and kind of hosting for low, medium and high traffic (10,000, 100,000 and 1,000,000 visits a month), and for the traffic measured now once 30 requests are sampled, with the OPcache settings and PHP memory limit that suit them all. Only traffic is assumed, and the table says how:

- Visits open 2.5 pages; the busiest hour is 3 times the average hour, with bursts 3 times that; a quarter more requests reach PHP for the REST API, AJAX, cron and bots.
- A page cache serves all but 15% of page views (40% on shop, membership and course sites). With no page cache found, every view reaches PHP.
- Time per request is the time 95% of sampled visitor pages took, once 30 are sampled; until then 0.5 seconds (1 second on shop and similar sites). Admin screens, the REST API and cron are timed and shown in Site Health, but not used: they take far longer and are not what visitor traffic needs.
- PHP workers = requests per second that reach PHP in a burst × time per request, plus one for cron and the admin, with at least 2, 4 and 8 for low, medium and high traffic. RAM = workers × memory per worker + OPcache + the database (its size plus a fifth, 128 MB to 4 GB) + the system (0.5 to 1.5 GB) + an object cache from medium traffic (at any traffic on sites with a lot of data), with a quarter extra for bursts, rounded up to a common plan size. CPU cores allow 0.7 of a core per busy worker, with at least 1, 2 and 4.

These are starting points to compare plans with, not guarantees: hosts count workers, memory and CPU differently. The constants are in `includes/class-seoprostack-hosting-plans.php`.

Shown in three places:

- A **Hosting needs** row below the plugin list (after the Size totals when Plugin sizes is on). It fills in after the screen opens, measuring plugins’ code a few seconds at a time, from the same cache as the Size column.
- Two **Site Health** tests (Tools → Site Health): OPcache size and PHP memory, with the settings to ask for. On WordPress 7.0 and later, core’s own test reports OPcache being off.
- A **Hosting needs** section on the Site Health **Info** tab, included when you copy the site info for your host.

### Clean up deleted plugins (Plugins)

When plugin folders are deleted outside the Plugins screen (by FTP, a file manager or a migration), WordPress keeps their uninstall entries, which load on every request, and their “Recently active” entries. With this on, opening the Plugins screen removes entries for plugins that no longer exist and says which ones. WordPress itself already switches off missing active plugins on that screen.

### Load plugins only where needed (Plugins)

Makes wp-admin faster on sites with many plugins. Tick the plugins that should load only where they are needed:

- on their own screens: the admin pages they add, and the lists and editors of the post types and taxonomies they register;
- on post, term and list screens where they add boxes, fields, blocks, editor features or Quick Edit fields, for that post type or taxonomy only;
- on the Profile, Add user and Edit user screens where they show or save profile fields, or change roles and permissions;
- on Tools and Media › Add New where they add tools or change the upload form;
- wherever a plugin that needs them loads.

Other screens, such as the Dashboard and the About screens, skip them. On a test site with 191 active plugins, all ticked, the Dashboard went from about 5.2 to 0.15 seconds, the Posts list from 3.9 to 0.7 seconds, and a plugin’s own page to about 0.25 seconds. The post editor gains least, because most of those plugins add something to it.

- **Learned, not configured.** The first time an administrator opens a screen, it loads every plugin and SEO Pro Stack notes what the screen needs. What was learned is forgotten when plugins are activated, deactivated or updated.
- **Always every plugin**: saving (form posts, links with an action or nonce, admin-ajax, REST), cron, WP-CLI, the site itself, and the Plugins, updates, settings, widgets, menus, Customizer, Site Health, import and export screens, and SEO Pro Stack’s own settings. Saving a profile loads every plugin too, so a plugin that saves profile fields always loads on the profile screens and its fields are never left out of the form.
- **The menu stays the same.** Skipped plugins’ entries are put back as links; opening one loads what that page needs. Each person only gets back entries they could open. Administrators also see entries for capabilities that a skipped plugin grants itself; the page checks access when it opens. A logo that a skipped plugin puts in its menu title as a picture becomes the entry’s icon, since the plugin’s styles for it are not loaded.
- **Shared code.** Plugins that bundle Freemius share one copy, loaded from whichever plugin has the newest, and Freemius takes over their welcome and opt-in pages. Those pages load the plugin Freemius works for, not the plugin that holds the shared copy.
- **Dependencies follow.** Plugins that need a ticked plugin (`Requires Plugins`, `WC requires at least`, `Elementor tested up to`, or named as a WooCommerce, Elementor or Contact Form 7 add-on) load where it loads, and a ticked plugin loads wherever a plugin that needs it loads.
- **Safe fallback.** If a screen hits a fatal error or a plugin tries to deactivate itself there, that screen loads every plugin from then on; the error message says to reload. A page WordPress would refuse reloads at once with every plugin; for administrators it also loads every plugin from then on, while other people, who may simply not be allowed there, change nothing. Nothing is ever deactivated.
- On screens that load fewer, the top of the Plugins menu in the admin bar says how many loaded, with **Reload with every plugin and check this screen again**: it loads every plugin once and learns again what that screen needs, for when a box, field or block is missing. **Reload with every plugin and check every screen again** forgets what every screen needs (including screens set to load every plugin after an error), then reloads the screen; each other screen loads every plugin until an administrator next opens it. Both ask first and explain what will happen. With the Plugins menu off, the admin bar shows “N of M plugins” with the same links. `?seoprostack-load-all=1` does the same, and the `SEOPROSTACK_LOAD_ALL_PLUGINS` constant switches filtering off.
- On screens that load every plugin, the top of the Plugins menu says why: the screen always does, SEO Pro Stack is checking it, it needs every ticked plugin, or a plugin failed there with fewer loaded (with the link to check every screen again).
- Skipped plugins’ notices do not show on those screens, unless Hide admin notices is on: then the notices they printed where they loaded are kept behind the bell on every screen until dismissed.
- Plugins that change the login address or the list of active plugins always load. Plugins that change user permissions are marked in the list; leave security, login and role plugins unticked.
- Works from a small must-use file, `wp-content/mu-plugins/seoprostack-plugin-loading.php`, written when the feature is switched on and removed when it is switched off or SEO Pro Stack is deactivated or deleted. If that folder is not writable, the settings say so. On multisite the file serves every site and filters only where the feature is on; network-activated plugins always load.

### Early updates from GitHub (Maintenance)

Only in builds from GitHub releases (see [Updates and releases](#updates-and-releases)). Off by default.

SEO Pro Stack gets its updates from GitHub through [Git Updater](https://git-updater.com/), a free plugin that reads the `GitHub Plugin URI` header and offers each GitHub release on the Updates screen like any other update. SEO Pro Stack never checks for updates itself.

- **Install prompt.** While Git Updater is not active, people who can install plugins (on multisite, super admins in the network admin) see a notice on the Dashboard, Plugins, Updates and SEO Pro Stack screens. **Install and activate Git Updater** downloads its latest release from GitHub, installs it and activates it (network-wide on multisite, which Git Updater needs). If it is installed but inactive, the button activates it. **Dismiss** hides the notice for that person. Git Updater needs PHP 8.0; on older PHP the notice says so instead of offering a button. On multisite the prompt is in the network admin; an install started from a site’s Free Plugins tab reports back on that tab.
- **Free Plugins** lists Git Updater first in Minimal, with the same install button.
- **The setting.** Once SEO Pro Stack is on WordPress.org, Git Updater takes its updates from there, a little later than GitHub. Switch this on to stay on GitHub releases, which come out first. It adds the plugin to Git Updater’s `gu_override_dot_org` filter. Until SEO Pro Stack is on WordPress.org, every update comes from GitHub whether it is on or off.

### Discover

- **Theme**: install, activate or customise the Kadence theme.
- **Free Plugins**: recommended plugins from WordPress.org by category. Install, Activate, Deactivate and Uninstall all work in place, so you can set up many in a row without leaving the page: an installed plugin shows Activate and Uninstall, an active one shows Deactivate. **All** lists every recommended plugin grouped by category, with a checkbox per plugin and per group and bulk actions (Install and activate, Install, Activate, Deactivate, Uninstall) that run one plugin at a time with progress, and can be stopped. Bulk Uninstall deactivates active plugins first. Plugins that need a newer WordPress or PHP, or that WordPress.org has closed, cannot be selected. Activate and Deactivate accept only WordPress.org plugins on the recommended list; install and uninstall use WordPress’s own requests, so FTP details are asked for where needed. Shown only to users who can install plugins (on multisite, super admins); plugins active for the whole network are left alone. Builds from GitHub releases also list Git Updater, which is not on WordPress.org; it keeps its own Install and Activate links and is not part of bulk actions.
- **Pro Plugins, Hosting, Tools**: filterable directories with links to each product. Pro plugins show a badge when their free version is already on the site.

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later

## Updates and releases

There are two builds of each version:

- **GitHub release** (`seoprostack-X.Y.Z.zip` on the repository’s Releases page): everything, including Early updates from GitHub (`includes/features/class-seoprostack-github-updates.php`). With Git Updater, sites get each release as a normal update.
- **WordPress.org** (once listed): the same files without `includes/features/class-seoprostack-github-updates.php` (listed in `.distignore-wporg`) and without the Git Updater header lines, because plugins hosted there may not install or update code from elsewhere. SEO Pro Stack loads that file only when it is present.

GitHub releases can go out as often as needed, so they work as the early channel; WordPress.org gets the versions that have settled.

Releasing on GitHub:

1. Merge the version change (`Version:` and `SEOPROSTACK_VERSION` in `seoprostack.php`, `Stable tag:` in `readme.txt`) to `main`.
2. Straight away, tag that commit `vX.Y.Z` and publish a GitHub release with `seoprostack-X.Y.Z.zip` attached. `scripts/build-release.sh --ref vX.Y.Z` builds it (and the WordPress.org zip) from the tag with `.distignore` applied, everything inside a `seoprostack/` folder; `scripts/preflight-release.sh` and `scripts/plugin-check.sh` check them first. Git Updater picks the newest release whose tag has no letters and whose asset name starts with `seoprostack`, so never attach the WordPress.org zip. Full steps and the WordPress.org checklist: `RELEASING.md`.
3. Git Updater compares the `Version:` header of `seoprostack.php` on `main` with the installed version, and installs the newest release zip. Until the release exists, sites that check in between are offered the new version but get the previous zip, so publish the release in the same sitting as the merge.

Do not put pre-release versions (`-beta1`, `-rc1`) in the `Version:` header on `main`: Git Updater would offer them to every site. Do not add an `Update URI` header: WordPress.org rejects it, and Plugin Check reports it as an updater.

## Extending

Developers can add settings, tabs and directory entries with filters:

- `seoprostack_features`: register a feature class that extends `SEOProStack_Feature` (declare settings in `settings()`, add hooks in `boot()`, and optionally import another plugin’s settings in `migrate()`).
- `seoprostack_settings_schema`: add or change settings. Each entry sets `type` (bool, int, text, url, lines, domains, select, multi, times or media, a picture from the Media Library stored as its attachment ID), `default`, `label`, `description` and either `tab` or `parent`; select and multi also take `options` (an array or a callable), and multi takes `open` to keep saved values that are not currently registered. `reload` (true) makes the saved message ask to reload the page, for changes that show only after a page load. `replaces` (slug => name) shows which plugin a feature replaces. Settings render and save automatically. Tabs are `admin`, `content`, `media`, `links`, `speed`, `plugins` and `maintenance`; the pre-0.4 slugs `general`, `workflow` and `advanced` still work and map to `admin`, `content` and `links`.
- `seoprostack_admin_tabs`: add or reorder admin tabs. Each tab sets `label`, `group` (settings, discover or about), a `render` callback and an optional `capability`; tabs the current user lacks the capability for are hidden.
- `seoprostack_pro_items`, `seoprostack_hosting_items`, `seoprostack_tools_items`: change directory entries.
- `seoprostack_free_plugins`: change the Free Plugins list (category => slugs).
- `seoprostack_external_plugins`: card data for listed plugins that are not on WordPress.org (slug => `name`, `description`, `author`, `url`, `file` when installed, `network`, `install_url`, `requires_php`, `source`). Their cards are built from this data instead of the WordPress.org API, with an Install Now link to `install_url`.
- `seoprostack_auto_upload_process_post`: skip image copying for specific posts.
- `seoprostack_auto_upload_limit`: change the per-save import limit.
- `seoprostack_magic_login_allowed`: allow or refuse login links for a user.
- `seoprostack_magic_login_email`: change the login link email.
- `seoprostack_magic_login_ip_limit`: requests allowed per IP address per 15 minutes (default 5).
- `seoprostack_post_scheduler_applies`: skip the publishing queue for specific posts.
- `seoprostack_duplicate_post_data`: change the data of a duplicated post before it is created.
- `seoprostack_duplicate_skip_meta`: custom field keys that are never copied (by Duplicate posts and Staged new versions).
- `seoprostack_watermark_attachment`: return false to leave a picture unmarked (attachment ID).
- `seoprostack_short_link_target`: change where a short link sends a visitor (target address, link post ID); return `''` to leave the address to WordPress.
- `seoprostack_short_link_count_click`: return false to not count a click (link post ID).
- `seoprostack_screenshot_request`: change the request sent to the screenshot service (`url`, `headers`, `json`; page URL, browser width, height, service), for example to use another service. The response must be a JPEG, PNG or WebP picture, or JSON with `data.screenshot.url` when `json` is true.
- `seoprostack_term_list_args`: change the `get_terms()` arguments of a Term list (arguments, block attributes), for example to order by count or exclude terms.
- `seoprostack_admin_bar_more_items`: change which top-level admin bar items go in the More menu (item IDs in bar order, `WP_Admin_Bar`).
- `seoprostack_admin_menu_catalog`: change where Organise the admin menu puts entries: `menus` (address => place), `plugins` (plugin folder => place) and `hidden` (addresses, or `menu>address`, left out of the menu); a place is a section key or another menu’s address.
- `seoprostack_is_developer`: whether a user is a developer for the admin menu’s client safeguards (bool, user ID).
- `seoprostack_dashboard_layout`: change how Tidy the dashboard lays out widgets: `columns` (column => widget IDs), `hidden`, `developers` and `reports` (widget IDs).
- `seoprostack_can_change_settings`: return false to stop the current user changing SEO Pro Stack’s settings (on top of `manage_options`).
- `seoprostack_admin_bar_star`: return false to hide the admin bar star that opens SEO Pro Stack’s settings.
- `seoprostack_hosting_sample_rate`: Hosting needs records the time of 1 in this many requests (default 20; 0 stops recording traffic).
- `seoprostack_plugin_presets`: add or change plugin presets (plugin folder => `name`, `tested`, `updated`, `notes`, `options` and `defaults`, as in `presets/*.json`). Secret-looking names are removed after the filter runs.

Actions:

- `seoprostack_setting_saved`: a setting was saved from the admin screen.
- `seoprostack_setting_panel`: print status at the top of a setting’s options panel (setting key, schema entry); wrap it in `<div class="sps-panel-note">`.
- `seoprostack_magic_login_link_sent`: a login link was emailed.
- `seoprostack_post_queued`: a post was scheduled by the publishing queue.
- `seoprostack_post_duplicated`: a post was duplicated.
- `seoprostack_version_published`: a staged new version was copied over its original.
- `seoprostack_image_imported`: an external image was imported.
- `seoprostack_image_upload_error`: an image could not be imported.
- `seoprostack_media_replaced`: a Media Library item’s file was replaced (attachment ID, old path, new path, IDs of posts whose links changed). Use it to purge caches.
- `seoprostack_screenshot_saved`: a screenshot was saved to the Media Library (attachment ID, page URL, post ID or 0).
- `seoprostack_screenshot_failed`: a screenshot could not be taken (page URL, `WP_Error`).

Read a setting with `SEOProStack_Settings::get( 'key' )`.

## Uninstall

Deleting the plugin removes its settings and cached data, the profile pictures and generated avatars in `uploads/seoprostack-avatars/`, the WebP and AVIF copies of pictures, including copies left by pictures deleted while the plugin was inactive, and short links with their categories and click counts (Pretty Links’ own links are left alone), and the must-use file of Load plugins only where needed, and who dismissed the Git Updater notice. Git Updater itself stays installed. Imported media and screenshots stay in the Media Library because your posts use them. Watermarked pictures stay marked, and their unmarked originals stay in the `uploads/seoprostack-originals-…` folder so they are not lost; delete that folder if you do not need them.

Deactivating the plugin removes the WebP and AVIF rules from the uploads folder’s `.htaccess` (for every site when network-deactivated) and the must-use file of Load plugins only where needed (on multisite, when network-deactivated).

## Changelog

### Unreleased

- Changed: Load plugins only where needed also covers the Profile, Edit user and Add user screens, Tools, Media › Add New and the About screens, which loaded every plugin before. Profile and user screens load the plugins that show or save profile fields (`show_user_profile`, `edit_user_profile`, `personal_options_update`, `user_contactmethods` and the other user form hooks) and those that change permissions; Tools loads plugins on `tool_box`; Media › Add New loads plugins that change the upload form. On a test site with 85 active plugins, Profile went from about 1.9 seconds to under half a second. The top of the Plugins menu in the admin bar now also says why a screen loads every plugin. Settings screens still load every plugin, because saving them clears settings whose fields are missing.
- New, off by default: Readable list columns (Admin tab) keeps the title column of post, page, user and other lists readable when plugins add columns of their own (on a test site the Posts title was 0 px wide with Complianz, Post Type Switcher, Burst, Rank Math and Sticky posts columns). When the main column is under a fifth of the table, or a column is squeezed to nothing, the other columns narrow towards the narrowest they can be without breaking words, and the main column gets a quarter, or what is left down to 120 px. With every Posts column shown at 1100 px wide, the first row went from 2,642 px tall to under 400 px and the three Rank Math columns and Page views reappeared; with the default columns at 1100 px no word breaks. No new options.
- Fixed: with Load plugins only where needed, WP Sheet Editor’s pages opened blank. Freemius takes over plugins’ welcome pages, and the page was taken to belong to the plugin holding the shared Freemius copy (Git Updater). Such pages now belong to the plugin Freemius works for. What every screen needs is learned again once after updating.
- Fixed: Meow Apps’ logo, a picture in its menu title, showed full size next to a cog on screens that skip Meow Apps’ plugins and in the Administrators and Developers menus. Organise the admin menu, and Load plugins only where needed for entries it puts back, make such a logo the entry’s icon.
- Changed: Organise the admin menu leaves out upgrade links (Upgrade, Go Pro, Get Pro, Unlock Pro and the like), entries without a name, pages registered without a menu, which WordPress never shows (WooCommerce’s Setup Wizard appeared in Shop), Freesoul Deactivate Plugins’ holder menu (shown twice in Developers), WP Sheet Editor add-ons’ Freemius opt-in prompts, AutomatorWP’s ShortLinks Pro advert and MasterStudy LMS’s 29 “Unlock this addon” pages. Pages still open; a place chosen by hand shows an entry again. Pages with the same name in Developers › Settings show their menu, as in Plugin Check (Tools). New `hidden` key in `seoprostack_admin_menu_catalog`.
- New, off by default: Faster editor with Kadence Blocks (Speed tab) stops Kadence Blocks printing its whole design library into every block editor screen; the editor fetches it from Kadence’s REST route when the design library is opened. On a test site the new post screen went from about 24 MB to 8.7 MB. Uses Kadence’s `kadence_blocks_preload_design_library` filter. No new options.
- Fixed: with Load plugins only where needed, Organise the admin menu kept a different list of page owners on screens that skip plugins, so skipped plugins’ entries could land in the wrong section (Plugin Groups under Plugins instead of Developers) and the list was rebuilt as screens changed. Plugins a screen skips now count as active, and their entries use the owner learned where they load.
- Fixed: MasterStudy LMS’s script moved WooCommerce’s Analytics above Posts; the menu keeps the order it was printed in. Entries whose whole title is in a `span` are sorted by that text.
- Changed: Organise the admin menu places MasterStudy LMS in Content, Lasso Lite and Revive.so in SEO, and GOTMLS, FluentSnippets, Easy Code Manager, Hreflang Manager Lite, Plugin Check, Git Updater, Disable WordPress Updates and the Hreflang Manager connections page in Developers.
- Changed: Tidy the dashboard also hides StylemixThemes’ (MasterStudy) announcements and news and WP Sheet Editor’s usage stats.
- Fixed: Hide admin notices moves WooCommerce’s lasting notices and plain message boxes printed without notice classes (such as MainWP Child’s) behind the bell.
- New, off by default: Plugin presets (Plugins tab). The Plugins screen says whether a plugin’s settings match SEO Pro Stack’s preset and offers Apply preset, Reset to defaults and Undo, each after a confirmation, plus Apply presets and Reset presets to defaults bulk actions. Licence keys, API keys, passwords and similar are never stored or changed. First presets: Antispam Bee and Simple CAPTCHA with Cloudflare Turnstile. New `wp seoprostack presets` command (list, diff, apply, reset, undo, export), `seoprostack_plugin_presets` filter, nonce-checked `seoprostack_plugin_preset` admin-post action and `seoprostack_plugin_presets_undo` option (not autoloaded), removed on uninstall.
- Change: `readme.txt` is under 10 KB for WordPress.org, with one line per feature and only the newest version's changelog, in short. Every version's changelog is in the new `changelog.txt`, which ships in both zips; preflight checks both for the version. Contributors is `wpallstars`, the WordPress.org account.

### 0.5.0

- New: Free Plugins installs, activates, deactivates and uninstalls plugins without leaving the page. Activate becomes Deactivate, and a deactivated plugin offers Uninstall. A new **All** view lists every recommended plugin by category with checkboxes and bulk actions (Install and activate, Install, Activate, Deactivate, Uninstall) for setting up new sites quickly. A request that a just-activated plugin redirects to its welcome screen is retried once. Free Plugins uses the full screen width, and its categories stay on one line, scrolling sideways when they do not fit.
- New, off by default: Tidy the dashboard (Admin tab) lays out the Dashboard the same way on every site: writing and activity first, then forms and email statistics, then visitors, SEO and the site. It hides the Welcome panel, WordPress news and plugin promotions, shows Site Health and Debug Log Manager to developers only and statistics to people who can publish, and shows no boxes to people who cannot edit posts. Boxes cannot be dragged unless Let people rearrange boxes is on. New `seoprostack_dashboard_layout` filter. No new options.
- New: updates from GitHub. The plugin header names the repository for Git Updater (`GitHub Plugin URI`, `Primary Branch`, `Release Asset`), so sites with Git Updater get each GitHub release on the Updates screen. Builds from GitHub releases show a notice, while Git Updater is not active, that installs and activates its latest release in one click (nonce-checked `seoprostack_install_git_updater` and `seoprostack_dismiss_git_updater` admin-post actions; `seoprostack_git_updater_dismissed` user meta, removed on uninstall), and list it first in Free Plugins → Minimal.
- New, off by default, GitHub builds only: Early updates from GitHub (Maintenance tab) keeps the site on GitHub releases once SEO Pro Stack is also on WordPress.org.
- New: `seoprostack_free_plugins` and `seoprostack_external_plugins` filters; Free Plugins can list plugins from outside WordPress.org. Features in `SEOProStack::$optional_features` load only when their file is present, so the WordPress.org build can leave out GitHub updates.
- Development: `scripts/build-release.sh` builds the GitHub release zip and the WordPress.org zip (without GitHub updates and the Git Updater header lines, files listed in `.distignore-wporg`) from a Git ref; `scripts/preflight-release.sh` checks a version before release and `scripts/plugin-check.sh` runs Plugin Check on both zips in a disposable Docker site. Steps and the WordPress.org checklist are in `RELEASING.md`, which, like the scripts, is not in either zip.
- New: a star in the admin bar, next to your name, opens SEO Pro Stack’s settings, for people who can change them. New `seoprostack_admin_bar_star` filter.
- New, off by default: Organise the admin menu (Admin tab) groups the menu into Content, Communications, SEO, Shop and Admin, the same way on every site, with WordPress’s entries first and plugins’ in A–Z order. Under Admin, Administrators and Developers are menus with side flyouts, then Users; in Developers, plugin pages moved out of WordPress’s menus share one Settings entry, and SEO Pro Stack gets a star icon. Sections can fold (Fold sections, off by default). Places can be chosen from a list per entry or written in Move menu entries. Preview the admin as a role or a client administrator in a new tab (signed cookie, two hours). Client safeguards (on by default) keep people who are not developers out of Developers, plugin and theme installs and code editing, developer accounts and SEO Pro Stack’s settings. Replaces Admin Menu Editor (Pro), without importing its settings; Free Plugins no longer lists it. New `seoprostack_admin_menu_catalog`, `seoprostack_is_developer` and `seoprostack_can_change_settings` filters and `seoprostack_admin_menu` option, removed on uninstall.
- New, off by default: More menu in the admin bar (Admin tab) moves the items plugins and themes add to the left of the admin bar into one … menu, last on that side, so the bar stays on one line instead of wrapping over the page. It opens on hover, like the other admin bar menus. Keep on the bar lists the plugins that add items; ticked ones keep theirs on the bar. New `seoprostack_admin_bar_more_items` filter, and a `reload` schema key that makes the saved message ask to reload the page.
- New, on by default: Hide admin bar items (Admin tab) removes chosen WordPress items from the admin bar for everyone; Comments and + New are ticked by default. It is on by default, so sites that update get a tidier bar; switch it off to keep every item. The account menu is never offered.
- New, on by default: No fade between admin screens (Admin tab) stops the fade WordPress 7.0 added between wp-admin screens, which can flash, so screens change straight away. It removes core’s `wp-view-transitions-admin` style and prints `@view-transition { navigation: none; }` on admin screens. Switch it off to keep the fade.
- New: Load pages before the click can also preload admin screens (**Also in the admin**, off by default). Only the page is downloaded; links that act, carry a nonce, dismiss or download, and screens that change something when opened, are skipped.
- Changed: Hide the admin bar and Block dashboard access no longer offer Administrator (or any role that can manage options), since those roles are never affected.
- Changed: Hide the admin bar and Block dashboard access tick roles that plugins add, such as WooCommerce’s Customer, unless they can write posts. Lists still at the 0.4.0 default get the roles added since they were saved. With WooCommerce active, an empty “Send them to” is set to its My Account page.
- Changed: new defaults on new sites: SVG uploads also allows authors, contributors and shop managers, and Watermark pictures uses 10% of the picture. Sites that already have the plugin keep their settings.
- Changed: Resize large uploads no longer imports Imsanity’s default limit (1920), so 2560 is used unless Imsanity was set to something else.
- Changed: on the right of the admin bar, the Plugins menu always sits next to the account menu, with the notices bell to its left and other plugins’ items further left. New `SEOProStack_Admin_Bar::pin( $id, $rank )` keeps a `top-secondary` node there (rank 0 nearest the account menu).
- Changed: the notices panel opens when you point at the bell and closes when you move away, like the other admin bar menus. Keys and taps still open it until you close it.
- Changed: the notices bell, the Plugins icon and the More menu’s “…” no longer show a browser tooltip, which covered their open menus. Screen readers still hear “Notices (N)”, “Plugins: N of M active” and “More” from hidden text, as with core’s icons; the plugin count is at the top of the Plugins menu.
- Changed: the notices bell is on every admin screen, with a dot instead of a count while there are notices, so nothing on the admin bar moves as the page loads. With no notices the panel says “No notices.” Screen readers still hear the count.
- New: with Load plugins only where needed, Hide admin notices keeps the notices of skipped plugins (from screens that load them, per person, for up to 12 hours) and shows them behind the bell on screens that skip those plugins, where they can be dismissed. New `{prefix}seoprostack_stored_notices` user option and `seoprostack_forget_notice` AJAX action; the option is removed on uninstall.
- Fixed: kept notices of skipped plugins are only visible notice boxes, as the bell picks them on the page. Before, everything those plugins printed on the notice hooks was kept, so Kadence Blocks’ Export All and Import buttons from its Forms list showed above the Dashboard and other screens, and half of WooCommerce’s notice wrapper could be printed elsewhere. Entries kept before the fix are checked again when shown.
- New: with Load plugins only where needed, the admin bar also offers **Reload with every plugin and check every screen again**, which forgets what every screen needs and learns each again as administrators open it (nonce-checked `seoprostack_plugin_loading_reset` admin-post action). Both reload links, and the stand-alone “N of M plugins” count, now ask first and explain what will happen, instead of a tooltip.
- New, off by default: Hosting needs (Plugins tab) checks whether the hosting fits the site: OPcache’s memory, file and string limits against how full it is and the PHP code that can load, and the PHP memory limit against the highest use per kind of request over 7 days. It suggests settings to ask the host for and the memory each PHP worker needs, and hosting to buy (PHP workers, RAM, CPU cores, object cache) for low, medium and high traffic and for the traffic measured now, from 1 in 20 requests’ times, the database and whether a page cache is in use. Shown in a row below the plugin list, two Site Health tests and a section on the Site Health Info tab. New `seoprostack_hosting_sample_rate` filter. New `seoprostack_hosting_memory` (autoloaded, a few writes a day), `seoprostack_hosting_code` and `seoprostack_hosting_traffic` (not autoloaded) options and `seoprostack_hosting_facts` transient, removed on uninstall.
- Changed: Plugin sizes also counts each plugin’s PHP files, for Hosting needs. Sizes measured before this are measured again once.
- New: Short links adds three review links once, `/googlereview/`, `/facebookreview/` and `/trustpilotreview/`, as 302 redirects to the services’ home pages in the Review Requests category, with advice under **Goes to** on replacing them with the brand’s own review page. Addresses already in use are skipped, and deleted links do not come back. New `seoprostack_short_links_presets` option, removed on uninstall.

### 0.4.0

- Settings tabs regrouped by area: Admin, Content, Media, Links and Speed, with Plugins and Maintenance ready for new features. Old tab links and the `general`, `workflow` and `advanced` tab slugs still work.
- New feature search: find a setting on any tab by name, description or the plugin it replaces, and change it from the results.
- `SEOProStack_Settings_Manager::render_general_tab()` and the other per-tab render methods are gone; settings tabs render through `render_tab()`.
- New Plugins tab, all off by default: a Plugins menu in the admin bar (replaces Plugin Toggle), a Size column on the Plugins screen with totals for installed and active plugins, and cleanup of leftover entries for deleted plugins (replaces Fix ‘Plugin file does not exist’ Notices).
- Free Plugins no longer lists Plugin Toggle, which the Plugins tab replaces, or String Locator: searching code is better done in an editor or with WP-CLI.
- Free Plugins no longer lists EditorsKit (`block-options`): its last update was in May 2024, and WordPress and Kadence Blocks cover its features. Block visibility by login state uses Kadence's Row Layout settings or Kadence Blocks Pro conditional display; hiding a block uses the Hide option in WordPress 6.9 and later.
- New, off by default: Hide admin notices (Admin tab) moves plugin and theme notices behind a bell with a count in the admin bar. Replaces Hide Admin Notices, which Free Plugins no longer lists.
- New, off by default: Paste into the Media Library (Media tab) uploads screenshots, pictures and files pasted into the Media Library, the media dialog and the classic editor. Replaces The Paste and imports its settings (settings version 5); Free Plugins no longer lists it.
- New, off by default: Avatars without Gravatar (Admin tab) serves avatars from your own site, with profile picture uploads and locally drawn defaults. Replaces Avatar Privacy and copies its uploaded profile pictures (settings version 5); Free Plugins no longer lists it.
- New, off by default, on the Media tab: SVG uploads (replaces Safe SVG) cleans every SVG as it is uploaded; Resize large uploads (replaces Imsanity) scales big pictures down on upload, with row and bulk actions and `wp seoprostack resize-images` for existing ones; Replace media files (replaces Enable Media Replace) uploads a new file for a Media Library item and updates links to it. Each imports the replaced plugin’s settings (settings version 5); Free Plugins no longer lists them.
- New, off by default: WebP and AVIF images (Media tab) saves smaller WebP and AVIF copies of every picture in the background and sends them to browsers that support them at the same address, through `.htaccess` rules (Apache, LiteSpeed) or shown Nginx lines, with one set of rules for a whole multisite network. Bulk action and `wp seoprostack convert-images`. Replaces CompressX: imports its settings (settings version 5), moves its copies next to the pictures, and passes its resize limit to Resize large uploads; Free Plugins no longer lists it.
- New, off by default: Watermark pictures (Media tab) adds the site icon, logo or a chosen picture faintly to a corner of uploaded pictures (30% opacity by default), keeps unmarked originals so watermarks can be removed, with row and bulk actions and `wp seoprostack watermark-images`. Replaces Easy Watermark and imports its image watermark (settings version 5); Free Plugins no longer lists it.
- New, off by default: Short addresses for custom post types (Links tab) serves items of chosen post types at `/item-name/` instead of `/type/item-name/` and redirects the old addresses. Pages and posts keep their addresses when names clash. Replaces Remove CPT base and imports its post types (settings version 5); Free Plugins no longer lists it.
- New, off by default: Short links (Links tab) makes short addresses that redirect elsewhere (301, 302 or 307), with nofollow and sponsored options, categories, and click and unique visitor counts, served from an autoloaded list with no query for other requests. Replaces Pretty Links: imports its defaults for new links (settings version 5) and its links with their click counts and categories (on deactivation, from the settings panel, or `wp seoprostack short-links import`); Free Plugins no longer lists it. New `seoprostack_short_link_target` and `seoprostack_short_link_count_click` filters.
- New, off by default: Load plugins only where needed (Plugins tab) makes wp-admin faster on sites with many plugins. Ticked plugins load only on their own screens, on post, term and list screens where they add boxes, fields or blocks, and where plugins that need them load. Screens are learned the first time they open, the menu stays the same, and a screen that fails loads every plugin from then on. The Plugins menu in the admin bar says how many plugins a screen loaded and can reload it with every plugin, which learns the screen again. Runs from a must-use file that the feature writes and removes.
- New for developers: `multi` settings with more than 12 choices get “Select all” and “Clear” buttons and a scrolling list.
- New, off by default: Website screenshots (Content tab) adds a Screenshot block and the `[browser-shot]` shortcode. Pages are captured once in a 1920 × 1080 browser window by Thum.io (default), Microlink, ApiFlash or Screenshot Machine, saved to the Media Library and served from your site, and renewed in the background. Replaces Browser Shots, whose shortcode and blocks keep working and whose blocks convert to Screenshot blocks; switches on while it is active (settings version 5), and Free Plugins no longer lists it. New `seoprostack_screenshot_request` filter and `seoprostack_screenshot_saved` and `seoprostack_screenshot_failed` actions.
- New, off by default: Spectra block replacements (Content tab) adds a Term list block (list, grid or drop-down of any taxonomy’s terms) and keeps pages built with Spectra working after it is deactivated: Taxonomy List blocks are drawn by the Term list, and a small stylesheet stands in for Spectra’s on its images, buttons and testimonials. In the editor, Spectra Heading, Image, Buttons, Testimonial and Taxonomy List blocks convert to core blocks or a Term list. Replaces Spectra; switches on while it is active and its blocks are in use (settings version 5), and Free Plugins no longer lists it. New `seoprostack_term_list_args` filter.
- New for developers: a `media` setting type (a picture from the Media Library, chosen in the media dialog) and the `seoprostack_watermark_attachment` filter.
- New for developers: the `seoprostack_setting_panel` action prints status in a setting’s options panel, and features may define a static `deactivate( $network_wide )` method that runs when the plugin is deactivated.
- Changed: while a plugin that a feature replaces is active, the feature waits and that plugin keeps doing the job, so the two no longer run side by side (for example two Plugins menus in the admin bar, or Google Analytics loaded twice with Flying Analytics). The card says so, with a deactivate link. `SEOProStack_Feature::enabled()` is false while waiting; `switched_on()` reads the switch alone, and `replaced_active( $key )` lists the active plugins.
- Changed: Hide admin notices also moves inline notices printed above the page. Inline notices inside a page’s content stay where they are.
- Changed: Hide admin notices also catches notices inside other plugins’ wrappers, notices that scripts add while the page loads, and React-drawn notices (shown as a copy in the panel). The script moved to `admin/js/seoprostack-admin-notices.js`.
- Fixed: pattern settings with tokens (such as Copy linked images’ file name and alt text) lost `%date%` and `%day%` when saved, because WordPress’s text sanitiser removes “%” followed by two hex digits.

### 0.3.1

- Free plugin cards are cached under a name that includes a hash of the category's slugs, so edits to `admin/data/free-plugins.php` show straight away instead of after the 12-hour cache expires.
- The Flying Press card no longer recommends Flying Analytics, Flying Pages or Flying Scripts, which the Speed tab replaces.

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
