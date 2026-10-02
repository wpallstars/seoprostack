![WPALLSTARS SEO Pro Stack: WP Admin – Solved!](admin/images/banner.svg)

# SEO Pro Stack

Curated plugins, themes, hosting and workflow tools for WordPress, plus a few small quality-of-life features.

Version: {SEOPROSTACK_VERSION}

## Where to find it

Go to **Settings → SEO Pro Stack**, or click the star next to your name in the admin bar (shown to people who can change these settings). The screen has three groups of tabs:

- **Settings**: Admin, Content, Media, Links, Speed, Plugins and Maintenance. Changes save instantly; there is no Save button.
- **Search features** (next to the plugin name) finds settings on every tab by name, description or the plugin they replace. Results can be switched on and changed in place.
- **Discover**: Theme, Free Plugins, Pro Plugins, Hosting and Tools.
- **About**: this Read Me.

**Report a problem**, at the top right of the screen, opens the plugin’s [GitHub issues](https://github.com/wpallstars/seoprostack/issues) in a new tab. Say what you did, what you expected and what happened, with the versions of WordPress, PHP and SEO Pro Stack. Leave out passwords, licence keys and personal data, since issues are public.

## Features

Every feature is off by default except three: Hide admin bar items, which hides Comments and + New from the admin bar, No fade between admin screens and Quiet Freemius prompts. Copies installed from GitHub releases also have Updates from GitHub on. Features that replace a separate plugin say so on their card (“Replaces: …”) and import that plugin’s settings once when SEO Pro Stack is updated. The other plugin’s own settings are never changed or deleted. While that plugin is active, the feature waits and the plugin keeps doing the job, so the two never run side by side; the card says so, with a deactivate link. Deactivate the plugin to switch over. The Plugins screen lists installed plugins that SEO Pro Stack can replace, with what to do next for each: deactivate it (its setting is on), switch the setting on, or delete it once inactive (single sites; on multisite another site may use it). When an active plugin does things on the site that SEO Pro Stack does not (such as Disable Bloat’s REST API switches), the line names them instead of saying the plugin can go. **Hide** hides the lines shown for that person until another step is needed.

| Feature | Tab | Replaces |
| --- | --- | --- |
| Hide the admin bar, Block dashboard access | Admin | Admin Bar & Dashboard Access Control |
| Turn off unused remote access | Admin | Hostinger Tools and Disable Bloat (PRO), in part |
| Organise the admin menu | Admin | Admin Menu Editor (Pro) |
| Hide dashboard widgets, Disable sidebar widgets | Admin | Widget Disable |
| Tidy admin screens, Tidy WooCommerce admin, Tidy the login screen, Simpler block editor, Limit post revisions, Remove WordPress extras, Fewer Heartbeat requests, Lighter WooCommerce pages | Admin, Content, Speed | Disable Bloat (PRO), in part |
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
| Maintenance mode | Maintenance | Hostinger Tools, in part |
| Plugins menu in the admin bar | Plugins | Plugin Toggle |
| Clean up deleted plugins | Plugins | Fix ‘Plugin file does not exist’ Notices |

These plugins showed what sites need. [Credits](#credits) thanks their makers and links to each one.

### Modern admin colours (Admin)

Uses the WordPress “Modern” admin colour scheme for every user while enabled. Switching it also updates your own profile: on selects Modern, off selects the WordPress default. Other users’ saved choices are not changed and return when the setting is off.

### No fade between admin screens (Admin)

WordPress 7.0 fades from one wp-admin screen to the next, using the browser’s view transitions. The fade can flash, and each screen change waits for it. With this on, screens change straight away, as before 7.0. **On by default**; switch it off to get the fade back.

- Removes core’s `wp-view-transitions-admin` style and adds `@view-transition { navigation: none; }` to admin screens, so fades that other plugins add the same way stop too.
- Only page changes are affected. Animations inside a screen, such as in the site editor, stay.
- No effect before WordPress 7.0, which has no fade, or for people whose system asks for reduced motion, who never get it.
- Goes well with Load pages before the click’s **Also in the admin**, which downloads admin screens when you point at their links.

### Turn off unused remote access (Admin)

Off by default, with two independent choices, both unchecked:

- **Turn off XML-RPC**: direct requests to `xmlrpc.php` return HTTP 403, including unauthenticated methods such as pingbacks. Removes the `X-Pingback` response header and RSD discovery link. Jetpack and some mobile apps need XML-RPC; leave this unchecked if you use them.
- **Turn off application passwords**: core stops accepting application passwords and hides their profile section and the Authorize application screen. Existing application passwords are not deleted; they work again when this choice is unchecked, if core allows them. Normal password login and the REST API stay available.

Imports enabled switches from active Hostinger Tools (`hostinger_tools`) and Disable Bloat once, without overwriting choices already saved here or changing their options. The feature waits until those plugins are deactivated. Only these two functions are replaced here, and Hostinger’s maintenance mode by [Maintenance mode](#maintenance-mode-maintenance): the Plugins screen names Hostinger’s HTTPS and www redirects, llms.txt generation and MCP connection when they are on, instead of suggesting removal. Keep Hostinger Tools if you still use those or its hosting tools. No separate options or files are stored; uninstall removes these settings with the rest.

Uses core’s `xmlrpc_enabled`, `wp_headers` and `wp_is_application_passwords_available` filters, removes `rsd_link` from `wp_head`, and refuses XML-RPC during `init`.

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
- **Fit the menu to its names** (on by default): the sidebar widens, up to 280 px, so that entry names fit on one line instead of wrapping, including every menu’s entries as they show when their menu is open (third-level lists with their indent). Every menu and folded section counts, so the width is the same on every screen and does not change when a section opens. It is measured as the page loads, before the page is drawn, and checked again once it has loaded (only ever wider, for styles printed later), and the content, submenus and the open menu’s list move over with it. Other plugins’ own bars and panels that are fixed to the window and laid out for WordPress’s usual 160 px menu (such as Fluent Support’s setup footer, Kadence’s and WP-Optimize’s headers, or Freemius dialogs) move over or narrow by the extra width too. They are found by where they sit, not from a list of plugins, as they appear and whenever they change; dialogs centred on the window and menus that a script places itself are left alone. Entry icons in the Administrators and Developers menus sit beside the name, so a name too long even for 280 px wraps under its first line, not under the icon. Only on screens wider than 960 px with the sidebar expanded; the collapsed sidebar and phones are unchanged, as is the block editor in full screen mode.
- Plugins that take other plugins’ scripts off their own screens (Fluent Forms, Fluent Booking and Fluent Boards do) also took the menu script, so the menu there was not fitted or folded and had no third level. The menu script is then printed with the menu, and the plugin’s choice stands for every other script. FluentCRM keeps only the scripts on its own list, so the menu script is added to that list (`fluent_crm_asset_listed_slugs`).
- FluentSMTP, moved from Settings to Communications, shows its own logo instead of a cog, drawn in the menu’s colours like the other entries.
- Fluent plugins’ names are spaced like Fluent Forms and Fluent Boards: FluentCRM shows as Fluent CRM, FluentSMTP as Fluent SMTP, FluentCommunity as Fluent Community and FluentCart as Fluent Cart. Only the name in the menu changes.
- **Shop** starts with the shop plugins’ own menus, WooCommerce then FluentCart, followed by the rest A–Z.
- **Preview the admin as** a role: the options panel links to each role, and to **Client administrator** (an administrator who is not a developer). Each opens the admin in a new tab with only that role’s permissions, never more than your own, and a **Stop** link in the corner and the admin bar. The preview is kept in a signed cookie in this browser, so every tab shows it until you stop it or two hours pass. Only developers who can manage options can start one.
- Places come from a list of common menus and plugins (`admin/data/admin-menu.php`), made from real sites. Plugin pages under Settings or Tools that belong elsewhere move too: SEO plugins to SEO; caching, security and developer tools to Developers. Pages inside a plugin’s own menu stay there. Unknown plugins go to Administrators; post types to Content.
- **Left out**: upgrade links (an entry called only Upgrade, Upgrade to Pro or premium, Go Pro, Get Pro, Unlock Pro, Premium Upgrade or Pricing), entries without a name, pages registered without a menu (such as WooCommerce’s Setup Wizard, which WordPress itself never shows), and entries listed as hidden: Freesoul Deactivate Plugins’ holder menu, the Freemius opt-in prompts of WP Sheet Editor add-ons, and AutomatorWP’s advert for ShortLinks Pro. Their pages still open and keep their place for the safeguards. The list of entries marks them “(hidden)”; choosing another place, or a line in Move menu entries, shows one.
- A menu logo that a plugin puts in its title as a picture (Meow Apps) becomes the entry’s icon, so it looks like the others on every screen, including where the plugin’s own styles are not loaded and in the Administrators and Developers menus.
- In the Administrators and Developers menus, plugins’ SVG icons are drawn in the menu’s text colour, as WordPress paints them at the top level, so dark ones (Site Assist, ICS Calendar, Bit Social, FluentCRM) show on the dark menu and follow hover and the current page. Picture icons keep their colours. An icon from a plugin’s own icon font (AutomatorWP) shows a cog on screens where that plugin is skipped. Code Snippets draws its icon with its own styles on its top-level entry only, so its entry shows its logo, kept in SEO Pro Stack.
- In Developers › Settings, pages with the same name get the menu they came from after it, such as Plugin Check (Tools) and Plugin Check (Settings).
- **Choose places**: the options panel lists this site’s entries (every top-level entry, and plugins’ pages in WordPress’s menus) with a **Place** list for each: a section, or for pages, inside any menu. Choosing a place writes its line in **Move menu entries**; choosing the usual place removes it. Reload to see the menu change.
- **Move menu entries**: one per line, a menu address or plugin folder, `=`, then a place: `top`, `content`, `communications`, `seo`, `shop`, `admin-heading` (under the Admin heading, after the two menus), `administrators` (or `admin`), `developers` (or `super-admin`), or another menu’s address to go inside it, for example `elementor = content` or `wpcf7 = administrators`. To move one page out of a menu, write `menu>page = place`.
- Moved pages keep their usual addresses, and WordPress checks access to them as before: the menu is only rearranged while it is printed. Nothing runs on the front end. Which plugin owns each page is worked out once and kept in the `seoprostack_admin_menu` option until plugins change. With Load plugins only where needed, plugins a screen skips still count as active, so the kept owners stay the same on every screen, and a skipped plugin’s menu entries keep their places using the owner learned on screens that load it.
- Some plugins move menu entries with their own scripts, matching them by name (for example moving any entry called “Analytics” above Posts). The menu script puts the printed entries back in order as the page loads; entries such scripts add are left where they put them.
- **Client safeguards** (on by default): people who are not developers do not see the Developers menu or open its pages, cannot install, delete or edit the code of plugins and themes, do not see or switch developer plugins (those placed in Developers, and SEO Pro Stack) on the Plugins screen, cannot edit or delete developers, cannot make anyone an administrator or change an administrator’s role, and cannot change SEO Pro Stack’s settings. Updates keep working.
- **Developers** are super admins on multisite. On single sites, tick them under **Developers**; when the feature is switched on with nobody ticked, every administrator is ticked, so untick client accounts. You always stay on the list, and if no ticked person is an administrator any more, every administrator counts as a developer, so nobody is locked out.
- Replaces Admin Menu Editor and Admin Menu Editor Pro. Their settings are not imported: places come from the rules above, so every site gets the same menu. Admin Menu Editor’s own settings are left alone; while it is active, this feature waits.

### Dashboard and sidebar widgets (Admin)

- **Hide dashboard widgets** for everyone, such as WordPress Events and News, the welcome panel or plugin promotions. Widgets added by plugins appear in the list after the Dashboard is next opened.
- **Disable sidebar widgets** you never use (the Meta widget by default); they disappear from the Widgets screen, the Customizer and sidebars.
- While Disable Bloat is active, its Dashboard box switches (WooCommerce Status and Setup, Elementor, Yoast, and PRO’s WordPress boxes) and widget switches (WooCommerce widgets, PRO’s WordPress widgets) are imported once.

### Tidy the dashboard (Admin)

Lays out the Dashboard the same way on every site, from rules made from 12 sites arranged by hand with Admin Menu Editor Pro (`admin/data/dashboard.php`):

- **Columns**: first WordPress’s browser and PHP update warnings when shown, then Quick Draft, Activity and WooCommerce reviews; then Fluent Forms, Fluent Support and FluentSMTP statistics; then visitor statistics (Burst), Rank Math, At a Glance and Site Health. Widgets that are not listed stay in the column their plugin chose, below the listed ones.
- **Hidden for everyone**: the Welcome panel, WordPress Events and News, WooCommerce Status and WooCommerce Setup (they repeat WooCommerce → Home), Pretty Links quick add, Link Whisper’s link health box and Debug Log Manager (it reads the whole debug log on every Dashboard load; its screen under Tools shows the same entries).
- **Developers only**: Site Health status. Developers are the ones chosen in Organise the admin menu; without it, people who can manage options (super admins on multisite).
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
- Empty boxes printed on the notice hooks, which a plugin’s script fills later (such as announcements loaded from the plugin maker’s site), stay hidden while empty. Filled with a notice or banner, they go behind the bell.
- The block editor has its own notices and is left alone.
- Add the class `sps-keep` to a notice to keep it on the page.
- **Show example notices** (off by default) adds one notice of each kind to every admin screen for administrators, to see where they go: information, success, warning and error behind the bell, and one with `sps-keep` on the page. Reload the page after changing it.

### Quiet Freemius prompts (Admin)

Freemius is a sales and licensing kit that some plugins bundle, such as WP Sheet Editor and its add-ons and Git Updater. Each copy asks for attention on its own: an “Opt in to make … better!” notice on every admin screen, Opt In, Upgrade and Add-Ons links on the Plugins screen, trial and affiliate offers, a jump to its opt-in page when the plugin is activated, and a “Quick feedback” survey when it is deactivated. The Plugins screen carries a hidden copy of the survey or opt-in dialog for each of them.

This turns those prompts off. On a test site with six such plugins, the five opt-in notices went from every screen, the Opt In and Upgrade links from the Plugins screen, and the hidden dialogs there from nine to two (Git Updater’s licence dialogs). Deactivating goes straight through, and activating stays on the Plugins screen. **On by default**; switch it off to get the prompts back.

- Freemius itself still loads: the plugins call it to check licences and build their menus, so stopping it would break them. Only its prompts are turned off, through the per-plugin filters Freemius provides.
- Nothing is saved in the other plugins: none is opted in or out, and turning this off brings every prompt back.
- Kept: licence activation and its dialogs, the Account, Contact Us and Support pages, licence, trial-ending and payment notices, Opt Out for plugins you opted in to, and “Complete activation now” for plugins that only work with a licence.
- A plugin that has not been opted in or skipped yet still shows its opt-in page in place of its own first screen. Choose **Skip** there once; the plugin keeps that choice.
- Upgrade links that plugins add themselves, without Freemius, are not touched. Organise the admin menu leaves many of them out of the menu.

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
- With Disable Bloat active and its “Remove the WordPress logo” switch on, the logo menu is added to the default items, once, if no items were chosen yet.

### Tidy admin screens (Admin)

Removes WordPress text and tools from wp-admin that most people never need, for everyone. Core hooks and constants only; nothing is stored.

- **Remove**: the “Thank you for creating with WordPress” footer and the “WordPress X is available” notice for people who cannot update WordPress (both ticked by default), and the theme and plugin file editors, for developers too (refused through `map_meta_cap` as `DISALLOW_FILE_EDIT` does, so switching it off brings them back).
- Replaces part of Disable Bloat: its matching switches are imported once while it is active.

### Tidy WooCommerce admin (Admin)

Removes WooCommerce’s promotions and prompts from wp-admin with the filters WooCommerce provides. Orders, products, reports and WooCommerce’s own settings are not changed. Does nothing without WooCommerce.

- **Remove**: marketplace suggestions (`woocommerce_allow_marketplace_suggestions`), the Extensions menu entry (the page still opens at its address), “Connect your store to WooCommerce.com” notices (`woocommerce_helper_suppress_admin_notices`) and “Get the WooCommerce app” in new order emails, all ticked by default; also offered, Marketing → Overview (recommended marketing extensions and courses), so the Marketing menu opens Coupons. Since WooCommerce 11.1 the Marketing menu always loads, so only its entry is removed; the page still opens at its address and other plugins’ Marketing pages stay.
- Replaces part of Disable Bloat: its matching switches are imported once while it is active.

### Tidy the login screen (Admin)

- **Change**: the logo links to your site instead of WordPress.org and is named after your site (`login_headerurl`, `login_headertext`); the WordPress logo picture is hidden, keeping the name for screen readers; the language menu is hidden (`login_display_language_dropdown`; only shown on sites with more than one language). All but the language menu are ticked by default.
- Replaces part of Disable Bloat: its matching switches are imported once while it is active.

### Readable list columns (Admin)

Keeps the title column of post, page, user, media and plugins’ lists wide enough to read. WordPress gives its list columns fixed widths, and the title gets what is left; every plugin that adds a column with a width of its own (SEO details, privacy scans, page views, post type, sticky) takes from it, until the title is a letter wide and runs down the page.

- When the main column of a list is narrower than a fifth of the table, or another column has been squeezed to nothing (columns without a width of their own get none once the others add up to more than the table), it gives the main column a quarter of the table and narrows the other columns towards the narrowest they can be without breaking words. Narrow columns whose content fits (checkboxes, icons, counts) keep their width. When even that is not enough room, the main column gives up some of its quarter, down to 120 px (or 12% of the table), before other columns break words. Lists with room to spare stay as they are.
- Each list is fitted before it is first shown, so it never flashes up squeezed: the script loads in the page head and fits a list as soon as its rows are in. Until then the list is hidden; if the script never gets to it, the list shows after two seconds as WordPress lays it out. Without JavaScript, nothing is hidden.
- Checked again when columns are switched on or off in Screen Options and when the window changes size. On phones, where WordPress stacks columns under the title, nothing changes.
- **Columns to remove** (all ticked by default): Complianz’s Website Scan, Post Type Switcher’s Type and Burst Statistics’ Pageviews are taken out of every list, and out of Screen Options. Admin Columns still offers them in its column editor, so a layout made there can add them back. To hide other columns for yourself only, untick them in Screen Options.
- **Author and date last** (on by default): on lists of posts, pages, media and other content, Author and Date are moved to the end, after the columns plugins add. A column layout chosen in Admin Columns still wins.
- Rank Math’s **SEO Details** column gets room for its longest line (“Schema: Article (BlogPosting)”), and each of its lines stays on one line.
- A small script on list screens; only the two settings above are stored.

### Avatars without Gravatar (Admin)

Serves every avatar from your own site. By default WordPress loads avatars from Gravatar, which tells Gravatar who visits your pages and publishes a hash of each commenter’s email address. With this on, nothing is loaded from or sent to Gravatar.

- **Profile pictures**: people upload a picture on their profile screen (Users → Profile), where WordPress would otherwise point them to Gravatar. It is cropped to a square, stored in four sizes (64 to 512 pixels) and the smallest size that fits is used. Photo details such as location are removed. JPEG, PNG, GIF and WebP are accepted. Replacing or removing a picture, or deleting the user, deletes the files. This part can be turned off.
- **Everyone else** gets the default chosen under Settings → Discussion, drawn on your site: a silhouette, a pattern that differs per person (made from a keyed hash of the email address, so it cannot be traced back), or nothing. Other stored styles, such as Identicon, Retro or Avatar Privacy’s Birds, show the pattern; Mystery Person and the rest show the silhouette.
- Guests are never matched to accounts by email address, so a guest cannot show someone else’s picture.
- Avatars that other plugins set (anything not from Gravatar) are kept. The admin bar, comment and user lists, the REST API (`avatar_urls`) and the block editor all use the same avatars.
- Generated files keep their names, so cached pages keep working. Files are stored in `uploads/seoprostack-avatars/` (on multisite, profile pictures are in the main site’s uploads).
- Switches on if Avatar Privacy is active, and copies its uploaded profile pictures, since deleting Avatar Privacy deletes them. Pictures are also copied when Avatar Privacy is deactivated, and otherwise the first time they are shown. A picture that cannot be processed is tried again the next day, keeping any earlier copy, and a picture uploaded or removed during a copy is never overwritten. Avatar Privacy lets people opt in to Gravatar; this feature never uses Gravatar, so that choice is not imported. While Avatar Privacy is active, it keeps handling avatars.

### TranslatePress switcher colours (Content)

Makes TranslatePress's floating and shortcode language switchers follow the site's colours, including Kadence's light and dark mode switcher. **Off by default**; nothing changes without TranslatePress.

- Text and backgrounds use Kadence's palette on the switcher itself, so changing the palette on the body takes effect straight away. Without Kadence, the browser's canvas colours follow the site's colour scheme. Hover backgrounds use a translucent neutral; menu labels inherit their links' theme colours.
- Covers the current and legacy switchers. Sizes, positions, flags, borders' widths, dropdowns and language links stay as TranslatePress sets them.
- Only a small front-end stylesheet is added. No scripts, outside services or changes to TranslatePress's saved settings. Turning it off restores TranslatePress's own colours on the next page load.

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

### Simpler block editor (Content)

Turns off parts of the block editor most people never use, for everyone.

- **Welcome guides** (ticked by default): closed whenever an editor opens (post, site, widgets and Customizer widgets editors). They are each person’s editor preferences, which core prints into admin screens from the `{prefix}persisted_preferences` user meta; SEO Pro Stack reads them as off (`get_user_metadata`), so no script runs and nothing is saved. Help → Welcome Guide still opens a guide for that visit.
- **Block directory** (ticked by default): searching for a block no longer offers blocks to install from WordPress.org.
- **WordPress’s own block patterns** and those from the Pattern Directory: the theme’s and plugins’ patterns stay.
- **Template editor** in the post editor, for classic themes only (block themes need it).
- **Fullscreen mode**: the post editor opens with the admin menu, read as off the same way as the welcome guides.
- **Block widget editor**: Appearance → Widgets and the Customizer use the classic widgets screen (`use_widgets_block_editor`, as the Classic Widgets plugin does). Block themes have no widgets screen.
- Replaces part of Disable Bloat: its matching switches are imported once while it is active.

### Limit post revisions (Content)

WordPress keeps every revision of every post. This keeps the newest few (10 by default, 0 to 100) with core’s `wp_revisions_to_keep` filter, or fewer where another filter or `WP_POST_REVISIONS` asks for fewer. Core removes older ones the next time a post is saved; with 0, it stops saving revisions and leaves the saved ones alone. Autosaves still work. Nothing is stored or deleted by SEO Pro Stack.

- Replaces Disable Bloat PRO’s post revisions switch, imported once as 0 while it is active.

### Load pages before the click (Speed)

Uses the browser’s Speculation Rules to download (or fully prepare) a page when a visitor points at or starts to tap a link to it. On WordPress 6.8 and later it tunes core’s built-in speculative loading; earlier versions get the rules added directly. Admin, login, file and query-string links are always skipped; add your own exclusions such as `/cart` or `logout`. Browsers without Speculation Rules ignore it. Logged-in users are not affected.

**Also in the admin** (off by default) downloads admin screens when you point at their links, with the same “When to start” setting. Screens are only downloaded (prefetch), never fully prepared, so their scripts do not run before the click. Skipped: links whose query string contains `action`, `nonce`, `dismiss` or `download`; screens that change something when opened or are slow to build (`post.php`, `post-new.php`, `customize.php`, `site-editor.php`, `update-core.php`, `update.php`, `upgrade.php`, `plugin-install.php`, `theme-install.php`, `admin-ajax.php`, `admin-post.php`, `async-upload.php`); `#` and `download` links, anything in a `.no-prefetch` element, and your own exclusions.

### Delay scripts until interaction (Speed)

Scripts whose tag or code contains a keyword you list (for example a chat widget) are held back until the visitor moves, scrolls, taps or types, or until a time limit passes, then run in their original order. Pages can be excluded by address. Add `data-seoprostack-nodelay` to a script tag to never delay it. Logged-in users, previews and feeds are not affected.

### Delayed Google Analytics (Speed)

Adds Google Analytics 4 (standard gtag.js) with your measurement ID, loaded after the first interaction or after a few seconds so it does not compete with the page. Logged-in users are not tracked. Turn off any other plugin that adds the same ID.

### Remove WordPress extras (Speed)

Leaves out scripts and tags WordPress adds to every page that most sites never use, with core’s own hooks. Nothing is stored.

- **Ticked by default**: the emoji script and styles (browsers show emoji themselves; feeds and emails no longer swap emoji for pictures from WordPress.org), the WordPress version in the page head and feeds, the RSD and Windows Live Writer links, the shortlink tag and header, and the comment reply script on pages without comments to reply to.
- **Also offered**: Dashicons for visitors who do not see the admin bar (a style that needs it still loads it), jQuery Migrate on the site (old plugin and theme code may need it), links made from web addresses typed in comments, feed links in the page head, feeds themselves (sent to the home page with a 301), embed links in the page head (other sites can no longer show your posts as cards; the oEmbed route stays, since the editor’s previews of other sites use it), and the password strength meter on the site, except on wp-login.php’s password screens, My account and checkout (a script that needs it still loads it).
- Replaces part of Disable Bloat: its matching switches are imported once while it is active.

### Fewer Heartbeat requests (Speed)

WordPress’s Heartbeat asks the server for news every minute while an admin screen is open (and on the site, where a plugin uses it); each request loads WordPress and every plugin. The post, site and widgets editors and the Customizer keep it as it is, for post locks, autosave and the “session expired” prompt.

- **Limit** (both ticked by default): on the site, Heartbeat is left out (a script that needs it still loads it, slowed); on other admin screens it runs once an hour (`heartbeat_settings`; before WordPress 6.7, every 2 minutes, its longest). News such as an expired login waits for the next page.
- Replaces Disable Bloat’s Heartbeat switch, which turns it off everywhere, editors included. It is imported once (both choices) while Disable Bloat is active.

### Lighter WooCommerce pages (Speed)

WooCommerce loads its scripts and styles on every page, so that add-to-cart buttons work wherever they appear. On a test site, a plain page loaded `woocommerce.js`, `wc-add-to-cart`, `wc-cart-fragments` and WooCommerce’s styles. With this on, they load only on shop pages (the shop, products, product categories and tags, cart, checkout and My account) and on pages whose content has WooCommerce blocks or shortcodes. Does nothing without WooCommerce.

- **Leave out**: WooCommerce scripts and styles outside shop pages (ticked by default; add-to-cart buttons there reload the page or open the product, and the store notice keeps its script), cart fragments outside shop pages (they ask the server for the cart on every page; a header cart there may show an old count on cached pages), and Stripe’s Apple Pay and Google Pay buttons on product pages (`wc_stripe_hide_payment_request_on_product_page`; they stay in the cart and checkout).
- Only dequeued: a script or style that another one needs still loads. WooCommerce’s order attribution script is left alone, since it records where orders come from from the first page a visitor sees.
- Products shown by a theme’s template parts are not seen; return true from `seoprostack_woo_light_shop_page` for those pages.
- Replaces part of Disable Bloat: its matching switches are imported once while it is active.

### Faster editor with Kadence Blocks (Speed)

Kadence Blocks prints its whole design library, with every pattern’s HTML, into each block editor screen as the `kadence_blocks_params_library` script variable. On a test site with the library cached that was about 15 MB, so a new post screen weighed about 24 MB. This turns the preload off with Kadence’s own `kadence_blocks_preload_design_library` filter (Kadence does the same when Gravity Forms is active). The editor then fetches the library from Kadence’s `kb-design-library/v1/get_library` REST route the first time you open the design library, so only people who use it wait for it. Without Kadence Blocks it does nothing. A filter of your own on `kadence_blocks_preload_design_library` at a priority above 10 still decides.

Kadence already keeps what it downloads in `wp-content/uploads/kadence_blocks_library`, so opening the library again does not reach Kadence’s servers, but each new editor page asked the site for the whole library again, and sent each request twice. The editor now keeps Kadence’s answers in the browser (Cache Storage, `seoprostack-kadence-library-*`) and sends each request once:

- Only Kadence’s own section, page and template libraries are kept: never cloud or custom libraries (Kadence checks their expiry on each request), licence, account or AI data.
- The kept copy belongs to a token from Kadence’s cache files (names, sizes and times), the versions of Kadence Blocks and Kadence Blocks Pro, its licence (in a salted hash), the user and the site. When Kadence’s copy changes, the next editor page drops the old one and fetches again once.
- Kadence’s Sync button always goes to the site and Kadence’s servers, and drops the kept copy.
- Nothing is stored on the server; browsers without Cache Storage (sites not on HTTPS) still send each request once.
- The library lays out all of its patterns at once (833 on the test site, a list about 70,000 px tall). Each pattern gets `content-visibility: auto`, so the browser lays out and draws only the ones on screen and the rest as they are scrolled to. Browsers without it ignore the rule.

On a test site, in one browser, alternating editor pages with and without the browser copy (four of each, opening the library from a new page each time): the library showed after 5.9 seconds without it (median; three library requests taking 2–2.7 seconds) and 3.4 seconds with it (no requests). Skipping off-screen patterns brought the time to the first drawn library from 2.7 to 2.0 seconds. The rest is Kadence building its pattern list. The first opening in each browser still loads the library from the site, and opening it again in the same editor page was already quick, since Kadence keeps it in the page. Sync still fetched new designs.

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

### Database key cleanup (Plugins)

Off by default. Turn it on, then open **Tools → Database keys**. It reads indexes on this site's core tables only, on demand. The list shows table, key, ordered columns (including prefix lengths), why it is offered and an optional InnoDB size estimate. On multisite only super admins see shared tables; their indexes are read-only because a plugin on another site may use them.

- Offers ordinary non-unique indexes covered by another index's left prefix, and leftover `wpi_` / `spro_` indexes when Scalability Pro is not active. These prefixes belong to Scalability Pro, not Index WP MySQL For Speed.
- Never offers PRIMARY or unique keys, names from the running WordPress version's core schema, known active-plugin keys, keys touching foreign-key columns, or unsupported types. Unknown `wdbi_` ownership needs manual review and is protected. Stored site and network activation states count even when a plugin is skipped on the screen.
- **Review removal** shows the exact table, key and restore statement; **Remove this key** confirms one key on this site. It checks eligibility and definition again immediately before removal. Removing one duplicate cannot make the last remaining key eligible in a later request.
- Back up first and use a quiet period: removing an index does not delete rows, but may slow queries and briefly block writes. No cleanup is automatic. Save the displayed `CREATE INDEX` line; run it through your database tool to restore the key.
- The non-autoloaded `seoprostack_database_keys_log` option records time, administrator ID, key, result and restore SQL, saved before each removal. A pending entry means the result was not recorded; check the database before restoring. Uninstall removes the log but does not undo changes to keys. Copy the log first.
- The `admin_post_seoprostack_database_keys` handler checks `manage_options`, feature state and a nonce on both steps. A database advisory lock serializes removals and log writes without waiting; if it is unavailable, nothing is removed. Confirmation renders on the normal Tools page, with its own review nonce.

### Plugin presets (Plugins)

SEO Pro Stack keeps its chosen settings for other plugins, chosen for speed, privacy and quiet admin screens. With this on, each plugin that has a preset says on the Plugins screen whether its settings match (**Preset: settings match**, or **Preset: 3 settings differ**). Its row offers:

- **Apply preset** (or clicking **Preset: 3 settings differ**): opens a dialog with the preset's notes and each setting that differs, with a tickbox, ticked to start. Each is named as on the plugin's own settings screen, with a line on what it does and its value now and the preset's in the same words (**Now: Off (not set) → Preset: On**); the stored name is shown small underneath. **Apply 3 settings** changes the ticked ones (the button counts them); unticked ones and the plugin's other settings stay as they are. **Cancel** or Esc closes it with nothing changed. Without JavaScript, Apply preset applies all of them.
- **Reset to defaults**: set the same settings to the plugin’s own defaults.
- **Undo preset** or **Undo reset**: put back the settings from before the last apply or reset.

Each asks first, naming the plugin. **Apply presets** and **Reset presets to defaults** are also bulk actions; plugins without a preset are skipped. Presets never store or change licence keys, API keys, site keys, passwords, tokens or similar: option names and keys that look like one are left out wherever they appear, and stay as they are when settings change or are undone. Only people who can manage options and activate plugins see this; on multisite it works on each site’s Plugins screen.

Presets so far:

| Plugin | What the preset does |
|---|---|
| Antispam Bee | Time check on with the other spam checks; no Gravatar checks, Dashboard spam chart or count, or email per spam comment. |
| Burst Statistics | No non-critical Burst dashboard notices; critical notices, tracking settings and the generated tracking script unchanged. |
| Code Snippets | No upgrade notices or Snippets admin bar menu; editor defaults left alone. Per-site settings only, not unified network settings. |
| EventON Lite | Scripts and styles only on pages with a calendar or event; no Google Fonts. |
| FluentCRM | Contacts' IP addresses anonymised; FluentCRM's own avatar instead of Gravatar and ui-avatars.com. |
| Fluent Forms | No weekly email summary, no IP address stored with entries, no admin bar menu. |
| Kadence Blocks | Google Fonts served from the site, in the blocks and (with the Kadence theme) the theme. |
| Lasso Lite (Simple URLs) | No affiliate programme bar or Dashboard banner. |
| LiteSpeed Cache | No news and promotions fetched from LiteSpeed and shown in the admin. |
| Rank Math SEO | No Frontend Stats Bar below the admin bar; modules and email schedules unchanged. |
| Simple CAPTCHA with Cloudflare Turnstile | Login, registration, lost password, comment and Fluent Forms protected; widget follows light or dark mode and shows only when needed. |
| Tutor LMS | Student profile pages private; course reviews wait for approval; courses written in the block editor; a quiz is submitted when its time runs out; courses complete when every lesson, quiz and assignment is done, and can be retaken. |
| WooCommerce | No usage data, remote error logs, marketplace suggestions or Order attribution tracking. |
| WP-Optimize | No caching menu in the admin bar; caching, images, minification and cleanup schedules unchanged. |

They are JSON files in `presets/`, one per plugin folder. Each names its settings for the dialog under `settings`: setting path => `label` (the plugin's own wording), `description` and `values` (stored value => what it means, with `"null"` for not stored). AGENTS.md → Plugin presets explains how they are made and checked. A setting named in a preset whose defaults are all "not stored" is removed again on reset, and an option left empty that way is deleted, so the plugin's own defaults apply as on a fresh install.

Presets can also name caches to clear after Apply, Reset or Undo: `"cache": {"groups": ["plugin_group"], "keys": {"plugin_group": ["settings_key"]}}`. Only named groups are flushed, when the object cache supports group flushing; otherwise (or if flushing fails), listed keys are deleted. Include both for caches without group flushing; keys in other groups are always deleted. The whole site's cache is never flushed. Code Snippets' group includes its version, so its active `Code_Snippets\CACHE_GROUP` and `Code_Snippets\Settings\CACHE_KEY` constants override the preset's tested group and key. This matches its settings save handler; no cache version counter needs changing.

WP-CLI works whether or not the setting is on:

- `wp seoprostack presets list`: each preset, the version it was chosen with, the installed version, how many settings differ and what can be undone.
- `wp seoprostack presets diff <plugin> [--defaults]`: the settings that differ from the preset or from the defaults, with their names.
- `wp seoprostack presets apply <plugin>… | --all [--only=<settings>]` (`--only` takes settings as `diff` names them, comma-separated, such as `tutor_option.course_retake_feature`), `reset <plugin>… | --all`, `undo <plugin>…`.
- `wp seoprostack presets export <plugin> [--options=<names>] [--like=<patterns>] [--compare=<file>]`: print settings as a preset, without secrets, warning about values that hold the site’s address or an email address. With `--compare` (an earlier export from a fresh install) only changed settings are kept, with the earlier values as the defaults.

#### Starter data

Some plugins keep the way you organise your data in their own tables rather than in settings. For those, SEO Pro Stack has **starter data**: the lists, tags, fields and boards we start our own sites with, so a new site begins organised and shows by example how to add more. An active plugin with starter data says on the Plugins screen what the site does not have yet (**Starter data: 35 items to add**, which opens to list them and explain the pattern) and offers:

- **Add starter data**: add the missing items. Items already there, matched by slug (lists, tags, fields) or title (boards, forms), stay exactly as they are, and settings already stored are not changed.
- **Remove starter data**: remove what SEO Pro Stack added, while it is unused. Lists and tags with contacts, contact fields with values, boards with tasks, forms with entries or placed on a page, and settings and forms changed since stay, and so do lists and tags a remaining FluentCRM setting or Fluent Forms feed still points at (role-based tagging, WooCommerce or Tutor LMS sync). The message says how many stay.

A plugin's preset and its starter data never set the same setting, so they can be used in either order, or only one of them. `scripts/preflight-release.sh` stops a release if one setting is in both.

Each asks first. Items are added through the plugin's own models, with its own created and deleted actions.

| Plugin | Starter data |
|---|---|
| FluentCRM | Lists named after where a contact came from: Website Contact Form, Booking Form, Newsletter Subscription Form, User Registration Form, Post Comment Form and Data Subject Access Request Form, plus Website Shop Customers with WooCommerce and free and paid course lists with Tutor LMS. Tags for who the contact is to you (Customer / Client, VIP, Supplier, Partner, Distributor, Reseller, Job Applicant, Staff, Employee, Contractor, Quote Requested) and what they are on the website (Website User, Admin, Editor, Author, Contributor, Commenter, Newsletter Subscriber, and Shop Manager, Course Student and Tutor where those plugins are active). Contact fields Company, Role and Website. Role-based tagging (FluentCRM Pro) tags contacts by WordPress role; WooCommerce customers and Tutor LMS students go to their list and tag as pending. Signing up from comments, checkout and registration stays off. |
| Fluent Forms | A **Contact Form**, **Discovery Call Form** (company, role, website, message and preferred times), **Subscription Form** and **Data Subject Access Request Form** (who is asking, what they want, and entries deleted after 10 years). With FluentCRM active, each sends people to the FluentCRM list named after it, and tags them from their answers: the newsletter tick box adds Website Newsletter Subscriber, and the access request's types add Customer / Client, Supplier, Employee, Contractor or Website User. Without Fluent Forms Pro, phone fields and the proof of identity and address uploads are left out. |
| Fluent Boards | A **Website** board for requests and feedback, with To-Do, In Progress, Done (please check) and Closed (closes tasks) and the default colour labels. |
| Fluent Booking | A free, 30-minute **Discovery Call** for the administrator adding it, using their existing host calendar or a new one named after their display name. New calendars share only this event; existing sharing settings stay as they are. With FluentCRM active, confirmed bookings join Website Booking Form. Review the default availability, timezone and meeting location before sharing the booking page. Remove keeps events with bookings and calendars with other events or shared host use. |

They are JSON files in `starters/`, one per plugin folder; `includes/class-seoprostack-starters.php` describes the format. What was added is recorded in the `seoprostack_starters_added` option (not autoloaded); uninstalling SEO Pro Stack removes the record but leaves the lists, tags, fields and boards, which are that plugin's data.

- `wp seoprostack starters list`: each starter, whether its plugin is active, how many items are missing and how many SEO Pro Stack added.
- `wp seoprostack starters diff <plugin>`: the items this site does not have yet.
- `wp seoprostack starters add <plugin>… | --all`: add the missing items. Boards and booking events are created by `--user`, or the first administrator. Booking events are matched by title within that user's host calendar; existing events and calendars are left alone.
- `wp seoprostack starters remove <plugin>…`: remove what was added, while unused.

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

### Ask before licence checks (Plugins)

Premium plugins and themes check their licence with their maker’s server, often while a page loads, so the page waits for that server (on owner sites, some checked on every admin screen or every visit). With this on, each plugin’s licence check waits for the site owner’s say. The first time a plugin tries, an administrator is asked in a dialog on the next admin screen, per plugin and server:

- **Allow once now**: the plugin’s next check goes through when it next asks (within the hour; checks made of several calls get five minutes), then the owner is asked again.
- **Once a day**: the first check each day goes through, and the plugin gets that same answer for the rest of the day without waiting. A failed check is tried again after an hour.
- **Never**: no check is made.

Until a choice is made the check is held: nothing is sent, and the plugin gets WordPress’s usual “request failed” error (`http_request_failed`, saying SEO Pro Stack held it), as if the server could not be reached. Plugins already handle that, usually by keeping their last known licence state. **Ask me again** tomorrow, in a week, in a month or in a year hides the plugins it shows for that long, for that administrator, each plugin with its own time; the checks stay held meanwhile, and a plugin that starts checking later is still asked about. The Plugins screen has a **Licence checks** link above the list and in each such plugin’s row, to see the choices and choose again. There, **Forget my choice** puts one plugin back to asking at its next check, and **Forget all choices** does so for every plugin and drops the kept answers.

Licence checks are calls whose address or form names a licence (`licence`, `license`, `licensing`, a `license` or `license_key` field, EDD’s check, activate and deactivate actions), plus any request the `seoprostack_licence_call` filter marks. Never held: WordPress’s update checks, update details and downloads (by call stack, and addresses that ask for versions, update data or packages), so updates keep working; WordPress.org; the site itself; and calls made by WordPress or SEO Pro Stack. It runs after other `pre_http_request` filters, so a request another plugin (such as HTTP Requests Manager) already answered or blocked is left alone.

Stored: the choices and times in `seoprostack_licence_calls` (not autoloaded, written when something changes, “last seen” at most once a minute), one day’s answers in `seoprostack_lc_*` transients and “Ask me again” in the `seoprostack_licence_later` user meta, all removed on uninstall. Request forms, which can hold licence keys, are never stored; a hash tells answers apart. Tested on WordPress 6.2 and 7.1 with PHP 7.4, with a stand-in licence server and with Kadence Blocks 3.7.12, Kadence Blocks Pro 2.8.19.1 and Kadence Pro 1.2.5. Kadence makes three licence checks (Kadence Pro on its settings screen and in the block editor, Kadence Blocks’ StellarWP account check in the block editor), each when its own cache runs out; all three are caught, Once a day answers repeats from the kept answer, and Never sends nothing, with no PHP messages. Kadence does not cache a failed check, so while one is held it tries again on the next block editor load (held at once, no wait), and with `WP_DEBUG` on Kadence Blocks writes each failure to the debug log, licence key included, as it does whenever its server cannot be reached.

### Load plugins only where needed (Plugins)

Makes wp-admin faster on sites with many plugins. Tick the plugins that should load only where they are needed:

- on their own screens: the admin pages they add, and the lists and editors of the post types and taxonomies they register;
- on post, term and list screens where they add boxes, fields, blocks, editor features or Quick Edit fields, for that post type or taxonomy only;
- on post, media, term and user lists where they add a column that shows, a filter, a view, a row link or a bulk action (such as Rank Math’s SEO columns and filters). A plugin whose columns are all removed, by Readable list columns or anything else, does not count for its columns;
- on the Profile, Add user and Edit user screens where they show or save profile fields, or change roles and permissions;
- on Tools and Media › Add New where they add tools or change the upload form;
- on the Dashboard where they show a box, after Hide dashboard widgets and Tidy the dashboard have removed the ones nobody sees (so hiding a box there also stops its plugin loading on the Dashboard);
- on Appearance › Menus where they add fields, columns or boxes, change menus or their items, or save item fields, and where their post types or taxonomies can be added to menus or already are in one; if a skipped plugin adds a menu location, Menus loads every plugin from then on, because saving a menu would otherwise take it out of a location that was not shown;
- on Appearance › Editor where they register blocks, add editor features, templates or styles, and, with a block theme, where their post types or taxonomies can have templates (learned again after switching themes);
- on the Customizer where they add controls, preview features, blocks, widgets, widget areas or menus; missing widgets, areas or menu locations, or a changed widget editor, reload with every plugin before the form opens and keep the screen fully loaded until an explicit check. Theme previews and existing changesets always load every plugin;
- on Import where they register an importer, and on Export where they change the form or own post types and taxonomies it offers;
- on SEO Pro Stack’s own settings where they register post types, taxonomies or widgets that the settings offer as choices, change permissions, or add to the settings through `seoprostack_*` hooks;
- wherever a plugin that needs them loads.

Other screens, such as the About screens, skip them. On a test site with 191 active plugins, all ticked, the Dashboard (without plugin boxes) went from about 5.2 to 0.15 seconds, the Posts list from 3.9 to 0.7 seconds, and a plugin’s own page to about 0.25 seconds. The post editor gains least, because most of those plugins add something to it.

- **Learned, not configured.** The first time an administrator opens a screen, it loads every plugin and SEO Pro Stack notes what the screen needs. What was learned is forgotten when plugins are activated, deactivated or updated.
- **Always every plugin**: saving (form posts, links with an action or nonce, admin-ajax, REST), cron, WP-CLI, the site itself (unless you choose plugins to skip there, below), and the Plugins, updates, settings, widgets and Site Health screens. Site Health diagnoses active plugins and live PHP state, such as sessions; skipping plugins would make its tests and support report inaccurate even if they add no health callbacks. Customizer preview pages, theme previews and existing changesets, importer execution and export downloads also load every plugin. Opening Widgets saves the widget areas without widgets and areas that are not registered, so a skipped plugin's widgets would be lost. SEO Pro Stack’s own settings save through admin-ajax, so they are checked with every plugin loaded, and on a screen with fewer plugins a list of choices also shows saved choices it cannot offer there, so saving never drops them. Saving a profile loads every plugin too, so a plugin that saves profile fields always loads on the profile screens and its fields are never left out of the form.
- **The menu stays the same.** Skipped plugins’ entries are put back as links; opening one loads what that page needs. Each person only gets back entries they could open. Administrators also see entries for capabilities that a skipped plugin grants itself; the page checks access when it opens. A logo that a skipped plugin puts in its menu title as a picture becomes the entry’s icon, since the plugin’s styles for it are not loaded.
- **Shared code.** Plugins that bundle Freemius share one copy, loaded from whichever plugin has the newest, and Freemius takes over their welcome and opt-in pages. Those pages load the plugin Freemius works for, not the plugin that holds the shared copy. Plugin families that each bundle one framework and use the first copy that loads (such as WP Sheet Editor’s spreadsheets for posts, users, products and terms) are the other way round: the framework registers every spreadsheet’s page, so its pages load every plugin in the family that bundles it.
- **Dependencies follow.** Plugins that need a ticked plugin (`Requires Plugins`, `WC requires at least`, `Elementor tested up to`, or named as a WooCommerce, Elementor or Contact Form 7 add-on) load where it loads, and a ticked plugin loads wherever a plugin that needs it loads.
- **Safe fallback.** If a screen hits a fatal error or a plugin tries to deactivate itself there, that screen loads every plugin from then on; the error message says to reload. This includes errors shown by another error handler, such as Query Monitor’s, which ends the page with a 500 status instead of WordPress’s message. A page WordPress would refuse reloads at once with every plugin; for administrators it also loads every plugin from then on, while other people, who may simply not be allowed there, change nothing. Nothing is ever deactivated.
- On screens that load fewer, the top of the Plugins menu in the admin bar says how many loaded, with **Reload with every plugin and check this screen again**: it loads every plugin once and learns again what that screen needs, for when a box, field or block is missing. **Reload with every plugin and check every screen again** forgets what every screen needs (including screens set to load every plugin after an error), then reloads the screen; each other screen loads every plugin until an administrator next opens it. Both ask first and explain what will happen. With the Plugins menu off, the admin bar shows “N of M plugins” with the same links. `?seoprostack-load-all=1` does the same, and the `SEOPROSTACK_LOAD_ALL_PLUGINS` constant switches filtering off.
- On screens that load every plugin, the top of the Plugins menu says why: the screen always does, SEO Pro Stack is checking it, it needs every ticked plugin, or a plugin failed there with fewer loaded (with the link to check every screen again).
- Skipped plugins’ notices do not show on those screens, unless Hide admin notices is on: then the notices they printed where they loaded are kept behind the bell on every screen until dismissed.
- Plugins that change the login address or the list of active plugins always load. Plugins that change user permissions are marked in the list; leave security, login and role plugins unticked.
- **On the site.** **Plugins to skip on the site** lists every active plugin; ticked ones do not load when someone views a page of the site. It is for admin tools that add nothing to those pages, so pages that are not cached open faster. Nothing is ticked by default.
  - **What each plugin adds.** The first page view after the feature is switched on, or after plugins change, loads every plugin (one request at a time) and notes what each one adds: shortcodes, blocks, widgets or public content types; hooks that change pages, addresses or what they show (`wp_head`, `wp_footer`, `template_redirect`, `the_content`, `render_block`, `pre_get_posts` and others); login checks; email; the admin bar. The list shows this next to each plugin, or “nothing seen on the site”, and says when Freesoul Deactivate Plugins already skips a plugin on the whole site (its own lists are only read). Plugins that extend one that adds to pages, such as WooCommerce extensions, are marked too.
  - **Always loads.** Plugins that change who is logged in or where logins go (`determine_current_user`, auth cookie and login, logout or lost password address hooks), filter the plugin list, or replace a WordPress function in `pluggable.php` (such as `wp_mail`) always load, even when ticked. So does a ticked plugin that a loading plugin needs.
  - **Only plain page views.** Skipping applies to GET requests for pages of the site through `index.php`, with no query arguments other than search, page numbers and campaign tags (`s`, `paged`, `page`, `p`, `page_id`, `utm_*`, `gclid`, `fbclid` and the like). Logins, `wp-login.php`, form posts, links with other arguments (unsubscribe, download, add to cart), REST, admin-ajax, cron, XML-RPC and WP-CLI load every plugin. People who are logged in get every plugin unless **Also skip them for people who are logged in** is on; then the Plugins menu in the admin bar says how many loaded on the page, with a reload that loads every plugin and checks the site again.
  - **Safe fallback.** If a page fails with a fatal error, or a plugin tries to deactivate itself, while plugins are skipped, the whole site loads every plugin from then on and the settings say which page, when and in which plugin. Saving the list tries again. A separate `seoprostack_plugin_front_failed` option holds that failure so a concurrent page learning request cannot erase it; it is removed on uninstall. While plugins are skipped, the stored rewrite rules are kept, so skipped plugins’ addresses are never lost.
  - What was learned is kept in the `seoprostack_plugin_front` option (with `seoprostack_plugin_front_lock` while learning), forgotten with the rest when plugins change, and removed on uninstall.
  - **Learn which plugins each page needs** is a separate opt-in, off by default. A new public address first loads every plugin, then remembers its content owners: post types and taxonomies, shortcodes, blocks, secondary loops, global template parts and widgets, and scripts or styles still used after dequeuing. The next visit can skip content-only plugins that the page does not use. Unknown global page hooks, security and permission hooks, and plugins needed by other loading plugins keep loading. **Always load these plugins on the site** opts a plugin out, including one ticked in the older site-wide list.
  - **WooCommerce PHP.** With Lighter WooCommerce pages on and both its scripts and cart fragments chosen, page learning can skip WooCommerce itself on plain pages. Shop, product, category, cart, checkout and account pages, WooCommerce shortcodes and blocks, mini carts and cart values printed by a theme keep it. Store notices and configured WooCommerce widgets keep it everywhere. Remaining WooCommerce assets (including tracking scripts) keep it on the pages using them. An extension that keeps loading also keeps WooCommerce; extensions chosen in the site-wide list follow the content owner on pages using it. New or unknown WooCommerce security callbacks keep it everywhere.
  - **Public pages only.** Page learning never skips plugins for logged-in people, requests with cookies or authentication headers, searches, pagination, feeds, 404s or requests that change state. Query arguments, including campaign tags that can change tracking or cookies, load every plugin. It stores at most 100 hashed addresses for an hour; unknown or expired addresses load every plugin again. Content, metadata, term, theme and stored-setting changes invalidate the decisions. The `seoprostack_plugin_front_revision` option prevents an in-flight request from saving an older decision after those changes. It is removed on uninstall. No visitor identity or session is stored, and page-cache plugins themselves are not automatically skipped. Clear your page cache after switching the feature or changing its choices. Themes or custom code with unreported plugin dependencies should use the opt-out list.
- Works from a small must-use file, `wp-content/mu-plugins/seoprostack-plugin-loading.php`, written when the feature is switched on and removed when it is switched off or SEO Pro Stack is deactivated or deleted. If that folder is not writable, the settings say so. On multisite the file serves every site and filters only where the feature is on; network-activated plugins always load.

### Updates from GitHub (Maintenance)

Only in builds from GitHub releases (see [Updates and releases](#updates-and-releases)). **On by default**, because copies installed from GitHub would otherwise never hear of a new version. Replaces [Git Updater](https://git-updater.com/) for plugins on GitHub: one plugin less, and it works on PHP 7.4 (Git Updater needs PHP 8.0).

New releases of SEO Pro Stack, and of any other installed plugin that names its GitHub repository, show on the Updates and Plugins screens like updates from WordPress.org. SEO Pro Stack only tells WordPress that a release exists and where its zip is; WordPress does the rest itself: **View details**, **Update now**, bulk updates, auto-updates (the Plugins screen offers **Enable auto-updates** for these plugins too), WP-CLI (`wp plugin update`), and putting the old version back if an update fails.

- **Which plugins.** Any plugin in its own folder whose main file has a `GitHub Plugin URI: owner/repo` header (the header Git Updater reads, so a plugin works with both). Add `Release Asset: true` to install only zips attached to releases; without it, a release without a matching zip installs GitHub’s source zip of the tag and is put in the plugin’s own folder. Developers can add or remove plugins with the `seoprostack_github_plugins` filter.
- **Which release.** The repository’s latest release (GitHub leaves out drafts and pre-releases) whose tag is a plain version number (`1.2.3` or `v1.2.3`). The zip is the release asset whose name starts with the plugin’s folder name (`seoprostack-1.2.3.zip`); the WordPress.org build (`wordpress-org-…`) is never picked. `Requires at least` and `Requires PHP` are read from the released main file, so WordPress does not offer a version the site cannot run.
- **How often.** GitHub is asked when WordPress checks for updates, at most once every 12 hours per repository (an hour after a failure, keeping the last answer), and again when **Check again** is pressed on the Updates screen. Answers are kept in the `seoprostack_github_releases` site transient, removed on uninstall. Unauthenticated GitHub API requests are limited to 60 an hour per server; one plugin costs one or two requests per check.
- **Private repositories** need a GitHub token with read access to the repository’s contents: `define( 'SEOPROSTACK_GITHUB_TOKEN', '…' );` in `wp-config.php`, or the `seoprostack_github_token` filter (token, owner/repo) for a token per repository. The token goes only to api.github.com: downloads ask the API for the file and fetch it from the short-lived address GitHub gives, without the token. It is never stored or shown.
- **Plugins also on WordPress.org** keep their WordPress.org updates. **Early updates from GitHub** (off by default) takes them from GitHub instead, as soon as they are released.
- **With Git Updater active**, this waits and Git Updater keeps updating (Early updates from GitHub is passed on through its `gu_override_dot_org` filter). The Plugins screen suggests deactivating and deleting it. GitLab, Bitbucket, Gitea and themes are not covered; keep Git Updater if you need those.

### Discover

- **Theme**: install, activate or customise the Kadence theme.
- **Free Plugins**: recommended plugins from WordPress.org by category. Install, Activate, Deactivate and Uninstall all work in place, so you can set up many in a row without leaving the page: an installed plugin shows Activate and Uninstall, an active one shows Deactivate. **All** lists every recommended plugin grouped by category, with a checkbox per plugin and per group and bulk actions (Install and activate, Install, Activate, Deactivate, Uninstall) that run one plugin at a time with progress, and can be stopped. Bulk Uninstall deactivates active plugins first. Plugins that need a newer WordPress or PHP, or that WordPress.org has closed, cannot be selected. Activate and Deactivate accept only WordPress.org plugins on the recommended list; install and uninstall use WordPress’s own requests, so FTP details are asked for where needed. Shown only to users who can install plugins (on multisite, super admins); plugins active for the whole network are left alone.
- **Pro Plugins, Hosting, Tools**: filterable directories with links to each product. Pro plugins show a badge when their free version is already on the site.

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later

## Updates and releases

There are two builds of each version:

- **GitHub release** (`seoprostack-X.Y.Z.zip` on the repository’s Releases page): everything, including Updates from GitHub (`includes/features/class-seoprostack-github-updates.php`), so sites get each release as a normal update.
- **WordPress.org** (once listed): the same files without `includes/features/class-seoprostack-github-updates.php` (listed in `.distignore-wporg`) and without the `GitHub Plugin URI`, `Primary Branch` and `Release Asset` header lines, because plugins hosted there may not install or update code from elsewhere. SEO Pro Stack loads that file only when it is present.

GitHub releases can go out as often as needed, so they work as the early channel; WordPress.org gets the versions that have settled.

Releasing on GitHub:

1. Merge the version change (`Version:` and `SEOPROSTACK_VERSION` in `seoprostack.php`, `Stable tag:` in `readme.txt`) to `main`.
2. Straight away, tag that commit `vX.Y.Z` and publish a GitHub release with `seoprostack-X.Y.Z.zip` attached. `scripts/build-release.sh --ref vX.Y.Z` builds it (and the WordPress.org zip) from the tag with `.distignore` applied, everything inside a `seoprostack/` folder; `scripts/preflight-release.sh` and `scripts/plugin-check.sh` check them first. Sites pick the latest release whose tag is a plain version number and the asset whose name starts with `seoprostack`, so never attach the WordPress.org zip. Full steps and the WordPress.org checklist: `RELEASING.md`.
3. Sites offer the update when they next check (within 12 hours, or at once with **Check again** on the Updates screen).

Mark test builds as pre-releases on GitHub (or tag them with letters, such as `v1.2.0-rc1`): sites never offer those. Do not add an `Update URI` header: WordPress.org rejects it, and Plugin Check reports it as an updater. Sites that still use Git Updater compare the `Version:` header on `main` instead, so keep pre-release versions out of `main` too, and publish the release in the same sitting as the version merge.

### Maintenance mode (Maintenance)

Off by default. Visitors see the site name and a plain-text message, with a 503 response, a one-hour Retry-After and noindex. Administrators see the normal site and a **Maintenance mode is on** admin-bar link to the setting. Login, admin screens, AJAX, cron, WP-CLI, robots.txt, sitemaps and logged-in REST requests keep working. Public REST requests receive a 503 unless they have a bypass cookie.

Switch it on and reload to show the **Bypass link** in its options. Share it only with people who should see the site: it sets a 24-hour HttpOnly cookie (Secure on HTTPS, SameSite=Lax), holding a keyed hash rather than the link's token, and redirects to remove the token from the address. **New link** invalidates the old link and all its cookies. **Allow the bypass link** can stop bypass access without changing the link. This is visitor access, not a login or permission to edit anything.

Clear your host's, CDN's and plugin's page caches when switching on or off; pages served before WordPress runs cannot be held by this feature. Maintenance and bypass responses ask caches not to store them. The small page has no styles or outside resources, so it uses the browser's default colours.

Imports `hostinger_tools[maintenance_mode]` once (settings version 7), without overwriting an existing choice. Hostinger Tools keeps handling maintenance while active. Its XML-RPC and application-password switches are covered by [Turn off unused remote access](#turn-off-unused-remote-access-admin); its HTTPS, www, llms.txt and MCP switches are not replaced, and the Plugins screen names them when they are on before suggesting it can go. Hostinger's bypass code is never reused. The new `seoprostack_maintenance_token` option is removed on uninstall; the switch and message live in the main settings option.

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
- `seoprostack_maintenance_capability`: capability needed to view the site during maintenance without a bypass link (default `manage_options`). Changing the bypass link still requires permission to change SEO Pro Stack's settings.
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
- `seoprostack_licence_call`: whether an outgoing request is a licence check that Ask before licence checks holds (bool, address, request arguments). Update checks are never held, whatever it returns.
- `seoprostack_hosting_sample_rate`: Hosting needs records the time of 1 in this many requests (default 20; 0 stops recording traffic).
- `seoprostack_plugin_presets`: add or change plugin presets (plugin folder => `name`, `tested`, `updated`, `notes`, `options`, `defaults`, optional `settings` and `cache`, as in `presets/*.json`). Secret-looking names are removed after the filter runs.
- `seoprostack_github_plugins` (GitHub builds): change which plugins update from GitHub releases (plugin file => `repo` as owner/repo, `asset_only`, `version`, `name`).
- `seoprostack_github_token` (GitHub builds): GitHub token for a repository (token, owner/repo), for private repositories; defaults to the `SEOPROSTACK_GITHUB_TOKEN` constant.
- `seoprostack_starters`: add or change starter data (plugin folder => `name`, `tested`, `updated`, `notes` and `items`, as in `starters/*.json`).
- `seoprostack_woo_light_shop_page`: whether a page keeps WooCommerce’s scripts with Lighter WooCommerce pages on (bool), for products the content check does not see.
- `seoprostack_replaced_plugin_extras`: what a replaced plugin does on this site that SEO Pro Stack does not (plain names, plugin folder). The Plugins screen names them instead of saying the plugin can go.

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

Deleting the plugin removes its settings and cached data, the profile pictures and generated avatars in `uploads/seoprostack-avatars/`, the WebP and AVIF copies of pictures, including copies left by pictures deleted while the plugin was inactive, and short links with their categories and click counts (Pretty Links’ own links are left alone), and the must-use file of Load plugins only where needed, the cached GitHub releases, and who hid lines of the Plugins screen notice about replaced plugins (and, from older versions, who dismissed the Git Updater notice). Imported media and screenshots stay in the Media Library because your posts use them. Watermarked pictures stay marked, and their unmarked originals stay in the `uploads/seoprostack-originals-…` folder so they are not lost; delete that folder if you do not need them.

Deactivating the plugin removes the WebP and AVIF rules from the uploads folder’s `.htaccess` (for every site when network-deactivated) and the must-use file of Load plugins only where needed (on multisite, when network-deactivated).

## Changelog

### Unreleased

- Changed: Free Plugins no longer lists Disable All WordPress Updates. The Speed and Plugins features cover why it was used, and it stops WordPress’s own update request, which also hides updates from GitHub releases.
- New: a **Report a problem** button at the top right of Settings → SEO Pro Stack opens the plugin’s GitHub issues in a new tab.
- Changed: Load plugins only where needed learns the Customizer, Import and Export forms. Widgets and widget-area owners, menu contributors, importers and exportable content owners stay loaded where needed. Saving, previews, theme previews, existing changesets, imports and export downloads keep every plugin. Missing Customizer registrations reload before the form opens; switching themes learns it again. Site Health stays fully loaded so its diagnostics describe the real site. Map version 6 learns every screen again once.
- Changed: Faster editor with Kadence Blocks keeps Kadence’s design library in the browser until Kadence’s own copy changes, sends each library request once instead of twice, and lets the browser skip drawing patterns that are off screen. On a test site, in a new editor page, the library was drawn in about 2 seconds instead of 5.9, with no requests. Only Kadence’s own section, page and template libraries are kept, never licence, account, AI or cloud library data; Sync still fetches new designs. No new options.
- New: a banner in the WPALLSTARS colours (navy, red and cream, Zilla Slab, the logo’s arc of stars above a stack with the WordPress Plugins icon on top, red and cream stripes), “SEO Pro Stack: WP Admin – Solved!”, at the top of the Read Me tab and for the WordPress.org plugin page. The Read Me tab now shows images from the plugin’s own folder written on a line of their own (`![alt](admin/images/banner.svg)`); other images and addresses are left out, and the file’s time is added to its address so browsers show a changed image straight away. The source is `.wordpress-org/banner.svg`; `scripts/build-banner.sh` (Inkscape and the Zilla Slab font) writes `admin/images/banner.svg` with the words as shapes, and `banner-772x250.png` and `banner-1544x500.png` in `.wordpress-org/` for the SVN `assets/` folder, which stays out of the plugin zip.

### 0.8.1

- New: Pro Plugins lists FluentCart Pro (licences, inventory tracking, order bumps, advanced reports and more payment gateways), with links to its home page, pricing and Free vs Pro comparison (`admin/data/pro-plugins.php`).
- Fixed: Organise the admin menu showed no icon for Code Snippets in the Administrators and Developers menus. Code Snippets registers its menu with the icon `none` and draws it with CSS on `.toplevel_page_snippets` only. Entries with the icon `none`, `div` or none at all now take one from `SEOProStack_Admin_Menu::ICONS` by menu address (Code Snippets' `assets/menu-icon.svg`, with a viewBox added).
- Changed: Organise the admin menu spaces the Fluent plugins’ top-level names like Fluent Forms and Fluent Boards: Fluent CRM, Fluent SMTP, Fluent Community and Fluent Cart (`brand_title()`; only the menu name changes). Shop starts with WooCommerce, then FluentCart (`LEAD`), then the rest A–Z; FluentCart (`fluent-cart`) is placed in Shop.
- New: Free Plugins lists FluentCart under eCommerce.

### 0.8.0

- New, off by default: Maintenance mode, with a plain-text 503 page, administrator reminder and revocable 24-hour visitor bypass links. Imports Hostinger Tools' maintenance switch, leaving its other jobs alone.
- New: small display presets for Rank Math SEO (no Frontend Stats Bar), WP-Optimize (no caching menu in the admin bar) and Burst Statistics (no non-critical dashboard notices). Modules, tracking services and schedules are not changed. Burst's preset contains only its admin notice preference, not the six settings its generated tracking script contains; Apply, Reset and Undo leave that file unchanged.
- New: Fluent Booking starter data (`fluentbooking_events`): a free, single-host, 30-minute Discovery Call, with the adding administrator's host calendar and default schedule. Uses Booking's own calendar/event handlers and FluentCRM integration save service. Confirmed bookings join `website-booking-form` when FluentCRM is active. Remove keeps events with any bookings, removes only calendars this starter created while unused, and keeps CRM lists referenced by Booking feeds. The Fluent Boards Pro roadmap is not included: its creation service and page metadata need verification with Pro available.
- New, off by default: Turn off unused remote access (Admin tab), with independent, unchecked choices to turn off XML-RPC (HTTP 403, no pingback header or RSD link) and application passwords (no profile section or authorization screen). Settings version 7 imports the enabled switches from active Hostinger Tools and Disable Bloat, preserving saved choices and the source plugins’ options.
- New, off by default: Database key cleanup (Plugins tab, Tools → Database keys) reviews leftover and redundant indexes on core tables, with confirmation per key and logged restore SQL. Core, unique, foreign-key and known active-plugin keys are protected. New nonce-checked `seoprostack_database_keys` admin-post action and non-autoloaded `seoprostack_database_keys_log` option, removed on uninstall.
- New, off by default: Load plugins only where needed can learn content owners per public page, with an opt-out list. Cookie-free pages that do not use WooCommerce can skip its PHP when Lighter WooCommerce pages removes its global assets; shop pages and cart displays keep it. Unknown routes and sessions load everything, dependencies follow their content owners, and content or settings changes invalidate the bounded, hour-long map. Permission filters now always load on the site.
- New, off by default: TranslatePress switcher colours (Content tab) makes floating and shortcode language switchers follow Kadence's light and dark palettes, with menu labels inheriting theme colours. Current and legacy switchers keep their layout and behaviour; turning it off restores TranslatePress's own colours without changing its settings.
- New, off by default: six features that take over most of Disable Bloat (#90): Tidy admin screens, Tidy WooCommerce admin and Tidy the login screen (Admin tab), Simpler block editor (Content tab), Remove WordPress extras and Lighter WooCommerce pages (Speed tab). Each uses core’s or WooCommerce’s own hooks and stores nothing. While Disable Bloat (free or PRO) is active, its switches are imported once (settings version 6, `SEOProStack_Disable_Bloat::MAP`), including its W logo (Hide admin bar items), Dashboard boxes and widgets (Dashboard and sidebar widgets); the features wait until it is deactivated. Tested on a throwaway site with WooCommerce 11.1.2 and Disable Bloat 4.0.0: imports matched the seeded switches; a plain page lost WooCommerce’s scripts, styles, emoji and shortlink, while the shop, product and a page with a WooCommerce block kept them.
- New, off by default: Fewer Heartbeat requests (Speed tab) and Limit post revisions (Content tab), and more Disable Bloat choices in the earlier features (#122): embed links and the password strength meter (Remove WordPress extras), the block widget editor (Simpler block editor) and Marketing → Overview (Tidy WooCommerce admin). Their Disable Bloat switches are imported with the rest (settings version 6). Its WooCommerce block style switches need nothing in their place: WooCommerce 11.1 loads a block’s styles only on pages that show it (`SEOProStack_Disable_Bloat::NOT_NEEDED`). Tested on a throwaway site with WooCommerce 11.1.2 and Disable Bloat 4.0.0, and on WordPress 6.2 with PHP 7.4: imports matched the seeded switches; pages lost embed links, Heartbeat and the password meter; the dashboard’s Heartbeat ran hourly (every 2 minutes on 6.2) and the post editor’s every minute; the widgets screen was classic; Marketing opened Coupons; revisions kept matched the setting.
- Changed: the Plugins screen names what a replaced plugin still does on the site that SEO Pro Stack does not (new `seoprostack_replaced_plugin_extras` filter), instead of saying it can go. Disable Bloat’s WooCommerce Admin, payment providers, merchant emails, REST API, resource hints, version numbers on scripts, autosave, block editor, auto-update and third-party plugin switches are not covered; XML-RPC and application passwords are covered when selected under Turn off unused remote access.
- Removed: Pro Plugins no longer lists Disable Bloat PRO, Pretty Links Pro (Short links replaces it) or Admin Menu Editor Pro (Organise the admin menu replaces it) (`admin/data/pro-plugins.php`).
- Fixed: Organise the admin menu drew plugins’ SVG icons in the Administrators and Developers menus as they are, unpainted (core’s `svg-painter.js` paints only top-level icons), so icons with no fill, `currentColor` or a grey fill (Site Assist, ICS Calendar, Bit Social, FluentCRM, Bit Integrations) were black or near-black on the dark menu. Base64 SVG icons are now a CSS mask over the text colour (`.sps-menu-icon-svg`), so they follow hover and the current page; other images are unchanged. A plugin icon-font class whose stylesheet is not loaded (AutomatorWP where Load plugins only where needed skips it) now shows core’s cog instead of nothing.
- New: starter data for Fluent Forms (`starters/fluentform.json`, item type `fluentform_forms`): Contact Form, Discovery Call Form, Subscription Form and Data Subject Access Request Form, taken from our own sites. Forms are created as Fluent Forms' own screens do: `FormService::store()` from a template, for its default settings and notification, then `Updater::update()` with our fields, which sanitises them and sets the primary email field. With FluentCRM active each gets a FluentCRM feed to the list named after it, with tag routing from its answers; the lists and tags it uses come with the starter (`"when": "fluent-crm"`), so it does not matter whether FluentCRM's starter data is added first. A field can have `"when"` too: phone and file upload fields need Fluent Forms Pro, which the free plugin does not show but would still require, so they are left out without it. Remove keeps forms with entries, forms placed in a post, page or block, and forms changed since, and keeps FluentCRM lists and tags that any Fluent Forms feed still uses. Items of a plugin that is inactive are left for later instead of stopping Add or Remove. Tested on a site with 85 plugins: 13 items added, a second run added none, a form whose title was taken was left alone; visitor submissions joined the right list with the right tags (contact form newsletter tick; access request types Customer and Supplier Contact); Remove kept the form with an entry, forms on pages and the tags and lists contacts hold; without FluentCRM, forms were added without feeds and FluentCRM's records left alone.
- Removed: Free Plugins and Pro Plugins no longer list Freesoul Deactivate Plugins. Load plugins only where needed does its job in wp-admin and for the whole site. The Plugins screen does not suggest removing it yet: its page-by-page rules on the site are not covered so far.
- Changed: the Apply preset dialog names each setting as the plugin's own screen does, says what it does in a line, and shows values in words (**Now: Off (not set) → Preset: On** instead of `null` and `on`), with the stored name small underneath. Presets gain a `settings` section (path => `label`, `description`, `values`); all ten presets have it. An option the plugin has not stored yet is now listed setting by setting too (FluentCRM showed one row of JSON), and its settings can be applied one at a time. `wp seoprostack presets diff` adds a name column. Plugin Check no longer warns about the dialog's `only[]` input.
- New: Code Snippets preset hides upgrade notices and the Snippets admin bar menu. Presets can clear named object-cache groups and keys after Apply, Reset and Undo, including Code Snippets' current versioned settings cache on persistent caches such as Redis.
- Fixed: Ask before licence checks: "Ask me again" kept one time and one list of hidden checks per person and replaced both on every click, so hiding a plugin that started checking later (Starter Templates after Kadence Blocks) brought back the one hidden before, and the dialog kept coming back. Each hidden check now keeps its own time in `seoprostack_licence_later` (`hidden`: ID => `first`, `until`), new hides are added to the ones still standing, and the earlier shape is still read.
- Fixed: Load plugins only where needed skipped plugins that only add columns, filters, views, row links or bulk actions to lists, so Rank Math’s SEO Details, SEO Score and Keyword columns and its filters were missing from the Posts list. Lists now learn which plugins change them while every plugin loads (each plugin callback on the list’s column, row link, view, bulk action, filter and cell hooks is watched for what it adds or prints), and those plugins load there. The map version goes to 5, so every screen is learned again once.
- Fixed: with Load plugins only where needed, WP Sheet Editor’s spreadsheets (such as Edit Posts) were blank. The page belongs to the copy of the shared WP Sheet Editor framework that loaded first (here from Bulk Edit Categories and Tags), so the posts spreadsheet plugin was skipped there. Pages whose code is a framework other plugins bundle too (the same file at the same place in their folder, declaring the same class or function) now load every plugin that bundles it. Freemius is unchanged.
- Fixed: More menu in the admin bar: TranslatePress lays out its Translate Site item as a flex row with `justify-content: space-between`, which spread its arrow, icon, label and badge across the wider menu. Items in the menu now use `justify-content: flex-start`, so they start at the left like the others.
- New: Readable list columns can remove columns few people need (Columns to remove: Complianz’s Website Scan, Post Type Switcher’s Type and Burst Statistics’ Pageviews, all ticked by default; Admin Columns still offers them), puts Author and Date last on content lists (Author and date last, on by default), and gives Rank Math’s SEO Details column room for its longest line so its lines do not wrap.
- New: Organise the admin menu can fit the sidebar to its names (Fit the menu to its names, on by default): it widens up to 280 px so entries do not wrap, on wide screens with the sidebar expanded, with the content, footer, flyout submenus, the open menu’s list and the block editor moving over with it. The width is the same on every screen. Other plugins’ fixed headers, footers and panels laid out for the usual 160 px menu move over or narrow with it, so the menu no longer covers them.
- Changed: FluentSMTP’s menu entry shows its logo instead of a cog (`ICONS` in the admin menu feature takes base64 SVG icons, which core paints in the menu’s colours).
- Changed: the Read Me tab shows tables as tables (they were lines of `|` text), and links to sections, such as Credits, jump to them.
- Docs: new Credits section thanks the makers of the plugins SEO Pro Stack’s features replace or learned from, with links to each plugin’s WordPress.org page and source repository.
- Development: `scripts/preflight-release.sh` also fails when a setting a preset changes has no label, or a label names a setting the preset does not change.

### 0.7.0

- New, off by default: Ask before licence checks (Plugins tab). Premium plugins’ and themes’ licence checks wait for the site owner’s say, per plugin and server: Allow once now, Once a day (the day’s answer is reused, so pages do not wait for the maker’s server) or Never. Until then nothing is sent and the plugin gets `http_request_failed`. Asked in a dialog on the next admin screen, or again tomorrow, in a week, a month or a year; a Licence checks link on the Plugins screen shows the choices, changes them and forgets them. WordPress’s update checks and downloads, WordPress.org and the site itself are never held. New `seoprostack_licence_call` filter, nonce-checked `seoprostack_licence_calls` admin-post action, `seoprostack_licence_calls` option (not autoloaded), `seoprostack_lc_*` transients and `seoprostack_licence_later` user meta, removed on uninstall.
- New: Plugin presets: **Apply preset** (or the row's **Preset: N settings differ**) opens a dialog, like the Licence checks one, listing each setting that differs with its value now and the preset's and a tickbox (ticked to start), and **Apply N settings** changes only the ticked ones, so you can see and choose exactly what changes. `SEOProStack_Presets::write()` takes the chosen paths (as `differences()` names them) and builds the part of the preset that covers them, so named keys in the same option still merge and the rest stay as stored; undo puts back the whole option as before. WP-CLI: `wp seoprostack presets apply <plugin> --only=<settings>`.
- Development: `scripts/preflight-release.sh` checks that every preset and starter file is valid JSON and that no setting is in both a preset and starter data, so Apply preset and Add starter data never depend on the order they are used in.
- Changed: Tidy the dashboard also hides WooCommerce Setup (`wc_admin_dashboard_setup`), which repeats the task list on WooCommerce → Home.
- Changed: Tidy the dashboard hides Debug Log Manager's box (`debug_log_manager_widget`) for everyone instead of showing it to developers. It reads and parses the whole debug log on every Dashboard load: on a test site with a 32 MB log it took 6.3 of the Dashboard's 7 seconds. Debug Log Manager keeps its log in files, with no database queries to index. With Load plugins only where needed, the Dashboard also skips the plugin once its box is gone. Tools › Debug Log Manager shows the same entries.
- New, on by default (GitHub builds): Updates from GitHub replaces Git Updater. SEO Pro Stack adds the latest GitHub release of itself and of any installed plugin with a `GitHub Plugin URI` header to WordPress’s own update check (`pre_set_site_transient_update_plugins`), with View details (`plugins_api`) and auto-updates; WordPress downloads, installs and rolls back. Releases are asked for at most every 12 hours per plugin and on Check again (`seoprostack_github_releases` site transient, removed on uninstall). Works on PHP 7.4, which Git Updater does not. Private repositories take a token from `SEOPROSTACK_GITHUB_TOKEN` or the `seoprostack_github_token` filter, sent only to api.github.com. New `seoprostack_github_plugins` filter. Early updates from GitHub now applies to every such plugin that is also on WordPress.org, unless WordPress.org already offers the same or a newer version. While Git Updater is active it keeps the job. Removed: the Install and activate Git Updater notice, its installer and its Free Plugins card.
- New: the Plugins screen lists installed plugins that SEO Pro Stack can replace: active ones whose replacing settings are on, with a Deactivate link (the settings wait until then); active ones whose settings are off, with a link to the setting; and, on single sites, inactive ones no longer needed, with a Delete link. **Hide** hides the lines shown for that person (`seoprostack_replaced_plugins_hidden` user meta, removed on uninstall).
- Fixed: copies installed from GitHub made Git Updater ask GitHub on every page load once an error had been cached and expired (on the test site, a request every few seconds, each logging “Git Updater Error: SEO Pro Stack … API rate limit exceeded” or “Not Found” while the repository is private). Git Updater 14.4.2 keeps the first expiry time when it caches the next error. SEO Pro Stack now gives a new error in this plugin’s Git Updater error cache (`ghu-` plus the MD5 of `seoprostack_error`) its own 5 or 60 minutes, as Git Updater’s develop branch does. Only that cache is touched, and only when Git Updater saves it.
- Removed: MasterStudy LMS is no longer recommended; Tutor LMS is the recommended LMS. Free Plugins and Pro Plugins no longer list it, and Organise the admin menu and Tidy the dashboard no longer carry rules for it (its menu place, its “Unlock this addon” pages and its StylemixThemes dashboard boxes). The general fixes found with it stay: empty notice boxes filled later stay hidden, and the menu keeps its order when a plugin’s script moves entries.
- New: starter data for FluentCRM and Fluent Boards, with Plugin presets on (Plugins tab): **Add starter data** and **Remove starter data** on the Plugins screen and `wp seoprostack starters list|diff|add|remove`. It adds the lists, tags, contact fields, role-based tagging and WooCommerce and Tutor LMS sync settings we organise FluentCRM with, and a Website board with a "Done (please check)" step, taken from our own sites. Only missing items are added; existing ones and stored settings are left alone. Remove takes away only what was added and is still unused. Tested on a site with 85 plugins: 35 items added, a second run added none, an existing tag kept its title, Remove kept the list and tag holding a contact, the field holding a value, a changed setting and the board holding a task, and removed the rest; shop and course items are left out without WooCommerce and Tutor LMS. New `starters/` folder, `seoprostack_starters` filter and `seoprostack_starters_added` option (not autoloaded), removed on uninstall.
- New: Tutor LMS preset, taken from the settings on our production course site and checked on a fresh Tutor LMS 4.1.0 install, saved through Tutor's own settings save handler: `student_public_profile_layout` `private` (Tutor already treats an unstored value as private in `templates/public-profile.php`, but its screen shows Classic and the first save makes student profiles public), `enable_course_review_moderation` and `enable_gutenberg_course_edit` `on`, `quiz_when_time_expires` `auto_submit` (a fresh install stores `autosubmit`, which is not one of the screen's choices), and the teaching choices from the same site: `course_completion_process` `strict`, `auto_course_complete_on_all_lesson_completion` and `course_retake_feature` `on`. Permalink bases and instructor publishing and trashing are left out: Tutor flushes rewrite rules and changes instructor capabilities only when its own screen saves them. Payments, pages and emails differ per site.
- New: plugin presets for EventON Lite, FluentCRM, Fluent Forms, Kadence Blocks, Lasso Lite (Simple URLs), LiteSpeed Cache and WooCommerce, chosen on a fresh test install of each plugin's current version from its source (where it reads each setting and what its save code does) and checked there with apply, reset and undo: EventON Lite loads its files only on calendar pages (three fewer stylesheets on other pages) and drops its Google Fonts; Kadence Blocks serves Google Fonts from the site (`kadence_blocks_font_settings` and the Kadence theme's `load_fonts_local` theme mod, since the theme prints the blocks' fonts); FluentCRM anonymises IP addresses and stops Gravatar and ui-avatars.com requests; Fluent Forms stops the weekly summary, IP logging and its admin bar menu; WooCommerce stops remote logging, marketplace suggestions and Order attribution; LiteSpeed Cache and Lasso Lite stop their promotions. Settings that only work when saved in the plugin (they schedule cron, write files or call a service) were left out.
- Fixed: Plugin presets: resetting a preset whose defaults are "not stored" left an empty array behind (`a:0:{}`), and an option the preset had created stayed stored. Named keys that are all "not stored" now store nothing, and an option left empty by a reset is deleted.
- New, on by default: Quiet Freemius prompts (Admin tab) turns off the opt-in notices, Opt In, Upgrade and Add-Ons links, trial and affiliate offers, activation redirect and deactivation survey of plugins that bundle Freemius, through Freemius’s own `fs_{tag}_{plugin}` filters, and removes the survey and opt-out dialogs it prints on the Plugins screen for plugins that are not opted in. Nothing is stored in Freemius or the other plugins. On a test site with six Freemius plugins, the Plugins screen went from nine hidden Freemius dialogs, five notices and three Freemius stylesheets to two dialogs (Git Updater’s licence ones), no notices and two stylesheets.
- New: Load plugins only where needed can also skip chosen plugins on the site (**Plugins to skip on the site**, off until you tick plugins, and **Also skip them for people who are logged in**). One page view with every plugin notes what each plugin adds to the site, shown next to each plugin; plugins that change logins or replace WordPress functions, and plugins a loading plugin needs, always load. Only plain page views skip plugins. A page that fails while plugins are skipped makes the whole site load every plugin until the list is saved again. On a test site with 85 active plugins and 15 admin tools ticked, 14 pages (posts, pages, shop, courses, events, archives, search, 404, feed, sitemap and robots.txt) gave the same output with and without them. New `seoprostack_plugin_front` and `seoprostack_plugin_front_lock` options, removed on uninstall.
- Changed: with Load plugins only where needed, SEO Pro Stack’s own settings no longer load every plugin. They load the plugins whose public post types, taxonomies or widgets the settings offer as choices, plugins that change permissions and plugins that use `seoprostack_*` hooks. On a test site with 85 active plugins, every tab went from 2.0–4.2 seconds and about 4.5 MB to 1.2–1.8 seconds and 2.9 MB with 30 plugins, showing the same settings, choices, text and menu. SEO Pro Stack now reads the stored list of active plugins wherever it shows or decides what is active, so plugins skipped on a screen still count as active (replaced plugins, the Plugin loading list, Free Plugins, Plugin sizes, Hosting needs, More menu sources and Git Updater). What every screen needs is learned again once after updating.
- Fixed: Tidy the dashboard forgot dashboard widgets of plugins skipped on the Dashboard, so they were no longer offered to hide.
- Fixed: with Load plugins only where needed, the Dashboard left out the boxes of ticked plugins. It now loads the plugins whose boxes are still there once Hide dashboard widgets and Tidy the dashboard have removed theirs, and skips the rest. On a test site with 85 active plugins and 68 ticked, the Dashboard loads 23 plugins and shows the same 11 boxes as with every plugin, in 0.8–1.6 seconds instead of 2.5–3.0 (0.55 without the boxes). What every screen needs is learned again once after updating.
- Changed: with Load plugins only where needed, Appearance › Menus no longer loads every plugin. It loads plugins that add fields, columns or boxes there, change menus or their items, or save item fields, and the plugins whose post types and taxonomies can be added to menus or are in one. A skipped plugin that adds a menu location makes the screen load every plugin. On a test site with 85 active plugins and 68 ticked, Menus loaded 24 plugins in 1.5–2.1 seconds instead of 2.1–2.5, with the same boxes, locations and form fields (including Nav Menu Roles' item fields). Widgets still loads every plugin: opening it saves the widget areas without unregistered widgets.
- Changed: with Load plugins only where needed, Appearance › Editor no longer loads every plugin. It loads plugins that register blocks or add editor features, templates or styles, and with a block theme the owners of post types and taxonomies that can have templates. On a test site with 85 active plugins and 68 ticked (Kadence, a classic theme), the Editor loaded 39 plugins in 2.1 seconds instead of 2.8–3.0, with the same 406 blocks, patterns, block categories, templates, template parts and global styles. Only settings of skipped plugins (Kadence Starter Templates, Syndication Links) were left out of the settings it reads.
- Fixed: Organise the admin menu did not open the Administrators or Developers menu, or mark the entry, on lists of post types that a plugin shows in its own menu (Kadence Blocks’ Forms, Navigations and Headers). Core passes such a list’s own address to `parent_file` and finds the real menu only afterwards; the menu now uses the entry that matches the page.
- Fixed: with Load plugins only where needed, a screen whose fatal error was shown by Query Monitor kept failing (Admin Columns’ settings, where Admin Columns calls WooCommerce’s `OrderUtil` while WooCommerce is skipped). Query Monitor shows the error and exits, so WordPress’s error message never runs and no last error is left; a 500 status at shutdown now also sets the screen to load every plugin.
- Fixed: Hide admin notices left empty boxes printed on the notice hooks in place, so a script that filled them later moved the page down (one LMS plugin printed an empty box on `all_admin_notices` on every screen and filled it with an empty 8px announcements box from its maker’s site). Empty boxes printed straight above the page are now marked `data-sps-notice="wait"` and stay hidden while empty; if a script fills one with a notice or banner it goes behind the bell, and anything else shows where it is.
- Fixed: Tidy the dashboard (with rearranging off) left core’s 250px drop area in a column without boxes once core’s script marked it empty, which pushed the next column’s boxes down as the Dashboard loaded. The empty column now takes no space, and the style is printed in the page head.

### 0.6.0

- Changed: Load plugins only where needed also covers the Profile, Edit user and Add user screens, Tools, Media › Add New and the About screens, which loaded every plugin before. Profile and user screens load the plugins that show or save profile fields (`show_user_profile`, `edit_user_profile`, `personal_options_update`, `user_contactmethods` and the other user form hooks) and those that change permissions; Tools loads plugins on `tool_box`; Media › Add New loads plugins that change the upload form. On a test site with 85 active plugins, Profile went from about 1.9 seconds to under half a second. The top of the Plugins menu in the admin bar now also says why a screen loads every plugin. Settings screens still load every plugin, because saving them clears settings whose fields are missing.
- New, off by default: Readable list columns (Admin tab) keeps the title column of post, page, user and other lists readable when plugins add columns of their own (on a test site the Posts title was 0 px wide with Complianz, Post Type Switcher, Burst, Rank Math and Sticky posts columns). When the main column is under a fifth of the table, or a column is squeezed to nothing, the other columns narrow towards the narrowest they can be without breaking words, and the main column gets a quarter, or what is left down to 120 px. With every Posts column shown at 1100 px wide, the first row went from 2,642 px tall to under 400 px and the three Rank Math columns and Page views reappeared; with the default columns at 1100 px no word breaks. Lists are fitted before they are first painted (on a throttled connection, 0 squeezed frames instead of about 39 seconds of them). No new options.
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

## Credits

SEO Pro Stack’s features stand on the work of the plugins below. Each one solved a real problem well, and SEO Pro Stack learned from how they did it: what to offer, which defaults make sense, and which settings people rely on. Thank you to their makers and contributors. If you need more than SEO Pro Stack’s version does, they are all still there, and most are free.

Where a feature replaces one of them, SEO Pro Stack imports the settings it can, once, so switching is easy, and never changes or deletes that plugin’s own settings (see [Features](#features)).

| Plugin | Made by | Links | SEO Pro Stack feature |
| --- | --- | --- | --- |
| Admin Bar & Dashboard Access Control | Collins Agbonghama | [WordPress.org](https://wordpress.org/plugins/admin-bar-dashboard-control/) | Admin bar and dashboard access |
| Admin Menu Editor (and Pro) | Janis Elsts | [WordPress.org](https://wordpress.org/plugins/admin-menu-editor/) | Organise the admin menu |
| Widget Disable | required | [WordPress.org](https://wordpress.org/plugins/wp-widget-disable/), [GitHub](https://github.com/wearerequired/WP-Widget-Disable) | Dashboard and sidebar widgets |
| Hostinger Tools | Hostinger | [WordPress.org](https://wordpress.org/plugins/hostinger/), [source (WordPress.org SVN)](https://plugins.svn.wordpress.org/hostinger/trunk/) | Turn off unused remote access (XML-RPC and application passwords), Maintenance mode |
| Disable Bloat for WordPress & WooCommerce (and PRO) | Rock Solid | [WordPress.org](https://wordpress.org/plugins/disable-dashboard-for-woocommerce/), [website](https://disablebloat.com/) | Tidy admin screens, Tidy WooCommerce admin, Tidy the login screen, Simpler block editor, Limit post revisions, Remove WordPress extras, Fewer Heartbeat requests, Lighter WooCommerce pages, Turn off unused remote access (XML-RPC and application passwords) |
| Manage Notification E-mails | 3D Virge | [WordPress.org](https://wordpress.org/plugins/manage-notification-emails/) | Notification emails |
| Hide Admin Notices | PontetLabs | [WordPress.org](https://wordpress.org/plugins/hide-admin-notices/), [GitHub](https://github.com/jonpontet/hide-admin-notices) | Hide admin notices |
| Avatar Privacy | Der Mundschenk & Compagnie | [WordPress.org](https://wordpress.org/plugins/avatar-privacy/), [GitHub](https://github.com/mundschenk-at/avatar-privacy) | Avatars without Gravatar |
| Carbon Copy | Manny Rodrigues | [WordPress.org](https://wordpress.org/plugins/carbon-copy/) | Duplicate posts |
| Yoast Duplicate Post | Yoast | [WordPress.org](https://wordpress.org/plugins/duplicate-post/), [GitHub](https://github.com/Yoast/duplicate-post) | Duplicate posts |
| Post Draft Preview | WP Served | [WordPress.org](https://wordpress.org/plugins/post-draft-preview/), [GitHub](https://github.com/wpserved/post-draft-preview) | Shareable preview links |
| Public Post Preview | Dominik Schilling | [WordPress.org](https://wordpress.org/plugins/public-post-preview/), [GitHub](https://github.com/ocean90/public-post-preview) | Shareable preview links |
| Sticky Posts – Switch | Markus Wiesenhofer | [WordPress.org](https://wordpress.org/plugins/sticky-posts-switch/) | Sticky posts for any post type |
| Bulk Actions Select All | Jesper van Engelen | [WordPress.org](https://wordpress.org/plugins/bulk-actions-select-all/), [GitHub](https://github.com/engelen/bulk-actions-select-all) | Select all across pages |
| Browser Shots | Kevin Leary, Ben Gillbanks | [WordPress.org](https://wordpress.org/plugins/browser-shots/), [GitHub](https://github.com/BinaryMoon/browser-shots) | Website screenshots |
| Spectra | Brainstorm Force | [WordPress.org](https://wordpress.org/plugins/ultimate-addons-for-gutenberg/), [GitHub](https://github.com/brainstormforce/wp-spectra) | Spectra block replacements |
| The Paste | Jörn Lund | [WordPress.org](https://wordpress.org/plugins/the-paste/), [GitHub](https://github.com/mcguffin/the-paste) | Paste into the Media Library |
| Safe SVG | 10up | [WordPress.org](https://wordpress.org/plugins/safe-svg/), [GitHub](https://github.com/10up/safe-svg) | SVG uploads |
| Imsanity | Shane Bishop | [WordPress.org](https://wordpress.org/plugins/imsanity/), [GitHub](https://github.com/nosilver4u/imsanity) | Resize large uploads |
| Enable Media Replace | ShortPixel | [WordPress.org](https://wordpress.org/plugins/enable-media-replace/), [GitHub](https://github.com/short-pixel-optimizer/enable-media-replace) | Replace media files |
| CompressX | CompressX | [WordPress.org](https://wordpress.org/plugins/compressx/) | WebP and AVIF images, Resize large uploads |
| Easy Watermark | Wojtek Szałkiewicz | [WordPress.org](https://wordpress.org/plugins/easy-watermark/) | Watermark pictures |
| Flying Pages | Gijo Varghese | [WordPress.org](https://wordpress.org/plugins/flying-pages/), [GitHub](https://github.com/gijo-varghese/flying-pages) | Load pages before the click |
| Flying Scripts | Gijo Varghese | [WordPress.org](https://wordpress.org/plugins/flying-scripts/) | Delay scripts until interaction |
| Flying Analytics | Gijo Varghese | [WordPress.org](https://wordpress.org/plugins/flying-analytics/), [GitHub](https://github.com/gijo-varghese/flying-analytics) | Delayed Google Analytics |
| Ultimate 410 Gone Status Code | tiny web | [WordPress.org](https://wordpress.org/plugins/ultimate-410/) | 410 Gone for removed pages |
| Remove CPT base | kubiq | [WordPress.org](https://wordpress.org/plugins/remove-cpt-base/) | Short addresses for custom post types |
| Pretty Links | Blair Williams | [WordPress.org](https://wordpress.org/plugins/pretty-link/) | Short links |
| Plugin Toggle | Cedaro | [WordPress.org](https://wordpress.org/plugins/plugin-toggle/), [GitHub](https://github.com/cedaro/plugin-toggle) | Plugins menu in the admin bar |
| Fix ‘Plugin file does not exist’ Notices | WP All Stars | [GitHub](https://github.com/wpallstars/wp-fix-plugin-does-not-exist-notices) | Clean up deleted plugins |
| Freesoul Deactivate Plugins | Jose Mortellaro | [WordPress.org](https://wordpress.org/plugins/freesoul-deactivate-plugins/), [GitHub](https://github.com/JoseMortellaro/freesoul-deactivate-plugins) | Load plugins only where needed (not a replacement yet: its page-by-page rules on the site are not covered) |
| Git Updater | Andy Fragen | [GitHub](https://github.com/afragen/git-updater), [website](https://git-updater.com/) | Updates from GitHub (GitHub builds only) |

## License

GPL-2.0-or-later.
