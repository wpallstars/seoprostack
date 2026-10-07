<?php
/**
 * Load the next page before the visitor clicks.
 *
 * Uses the browser's Speculation Rules: when a visitor hovers or starts to
 * tap a link to another page on this site, the browser fetches (or fully
 * prepares) that page so it opens almost instantly. On WordPress 6.8+ this
 * tunes core's built-in speculative loading; on older versions it adds the
 * rules itself. Browsers without support simply ignore them. Replaces
 * "Flying Pages"; its ignore list is imported once.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Preload_Pages extends SEOProStack_Feature {

    const KEY = 'preload_pages';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'speed',
                'label'       => __('Load pages before the click', 'seoprostack'),
                'description' => __('When a visitor points at or starts to tap a link on your site, the browser starts loading that page so it opens almost instantly.', 'seoprostack'),
                'replaces'    => array('flying-pages' => 'Flying Pages'),
            ),
            'preload_pages_mode' => array(
                'type'    => 'select',
                'default' => 'prefetch',
                'parent'  => self::KEY,
                'label'   => __('How much to load', 'seoprostack'),
                'options' => array(
                    'prefetch'  => __('Download the page (safe for every site)', 'seoprostack'),
                    'prerender' => __('Fully prepare the page (fastest; may count a visit in analytics before the click)', 'seoprostack'),
                ),
            ),
            'preload_pages_eagerness' => array(
                'type'    => 'select',
                'default' => 'moderate',
                'parent'  => self::KEY,
                'label'   => __('When to start', 'seoprostack'),
                'options' => array(
                    'conservative' => __('When the link is pressed', 'seoprostack'),
                    'moderate'     => __('When the pointer rests on the link', 'seoprostack'),
                    'eager'        => __('As soon as the pointer moves onto the link', 'seoprostack'),
                ),
            ),
            'preload_pages_exclude' => array(
                'type'        => 'lines',
                'default'     => "/cart\n/checkout\n/my-account\nadd-to-cart\nlogout",
                'rows'        => 5,
                'parent'      => self::KEY,
                'label'       => __('Never preload links containing', 'seoprostack'),
                'description' => __('One per line: a path such as /cart or any text in the address such as logout. Admin, login, file and query-string links on the site are always skipped.', 'seoprostack'),
            ),
            'preload_pages_admin' => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Also in the admin', 'seoprostack'),
                'description' => __('Download admin screens when you point at their links, so they open faster. Only the page is downloaded. Links that edit, add, change or download something are skipped, as are updates and the Customizer. When you click, the menu and the new screen’s title show straight away while it loads.', 'seoprostack'),
            ),
        );
    }

    /**
     * Admin screens never preloaded: opening them changes something (a new
     * draft, an editing lock, update checks, database upgrades), they handle
     * requests rather than show a page, or they are slow to build.
     *
     * @return string[] File names in wp-admin.
     */
    private static function admin_skipped_screens() {
        return array(
            'post.php', 'post-new.php', 'customize.php', 'site-editor.php',
            'update-core.php', 'update.php', 'upgrade.php', 'plugin-install.php', 'theme-install.php',
            'admin-ajax.php', 'admin-post.php', 'async-upload.php',
        );
    }

    /**
     * Import Flying Pages settings.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (false === get_option('FLYING_PAGES_VERSION')) {
            return $options;
        }
        $keywords = get_option('flying_pages_config_ignore_keywords');
        $keep     = array();
        if (is_array($keywords)) {
            foreach ($keywords as $keyword) {
                $keyword = trim((string) $keyword);
                // Covered by the built-in exclusions (admin, login, query strings, fragments, files).
                if ('' === $keyword || in_array($keyword, array('/wp-admin', '/wp-login.php', '#', '?'), true) || preg_match('/^\.[a-z0-9]{2,5}$/i', $keyword)) {
                    continue;
                }
                $keep[] = $keyword;
            }
        }

        $options = self::import_setting($options, self::KEY, true);
        return self::import_setting($options, 'preload_pages_exclude', $keywords ? implode("\n", $keep) : null);
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        if (is_admin()) {
            if (SEOProStack_Settings::get('preload_pages_admin')) {
                add_action('admin_print_footer_scripts', array(__CLASS__, 'print_admin_rules'));
                add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_navigation_style'));
                add_action('admin_print_footer_scripts', array(__CLASS__, 'print_admin_navigation'));
            }
            return;
        }
        if (function_exists('wp_get_speculation_rules_configuration')) {
            add_filter('wp_speculation_rules_configuration', array(__CLASS__, 'configuration'), 20);
            add_filter('wp_speculation_rules_href_exclude_paths', array(__CLASS__, 'core_exclusions'), 20);
        } else {
            add_action('wp_footer', array(__CLASS__, 'print_rules'), 20);
        }
    }

    /**
     * Visitors only: logged-in pages differ (admin bar, previews).
     *
     * @return bool
     */
    private static function active() {
        return !is_user_logged_in() && !is_customize_preview() && get_option('permalink_structure');
    }

    /**
     * Core configuration (WordPress 6.8+).
     *
     * @param array|null $config Core configuration.
     * @return array|null
     */
    public static function configuration($config) {
        if (!self::active()) {
            return $config;
        }
        return array(
            'mode'      => (string) SEOProStack_Settings::get('preload_pages_mode'),
            'eagerness' => (string) SEOProStack_Settings::get('preload_pages_eagerness'),
        );
    }

    /**
     * Exclusions as URL patterns relative to the site root.
     *
     * @return string[]
     */
    public static function patterns() {
        $patterns = array();
        foreach (preg_split('/\n/', (string) SEOProStack_Settings::get('preload_pages_exclude'), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            // URL pattern syntax: escape its special characters, then match the text anywhere or as a path prefix.
            $escaped    = preg_replace('/([\\\\:*?+(){}])/', '\\\\$1', $line);
            $patterns[] = '/' === $line[0] ? $escaped . '*' : '/*' . $escaped . '*';
        }
        return array_values(array_unique($patterns));
    }

    /**
     * Add exclusions to core's list (WordPress 6.8+).
     *
     * @param string[] $paths Core exclusions.
     * @return string[]
     */
    public static function core_exclusions($paths) {
        return array_merge((array) $paths, self::patterns());
    }

    /**
     * Print rules on WordPress before 6.8.
     */
    public static function print_rules() {
        if (!self::active() || is_404()) {
            return;
        }
        $home    = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        $exclude = array_merge(
            array('/wp-*.php', '/wp-admin/*', '/wp-content/*', '/wp-includes/*', '/*\\?(.+)'),
            self::patterns()
        );
        $exclude = array_map(function ($pattern) use ($home) {
            return $home . $pattern;
        }, $exclude);

        $rules = array(
            (string) SEOProStack_Settings::get('preload_pages_mode') => array(
                array(
                    'source'    => 'document',
                    'where'     => array(
                        'and' => array(
                            array('href_matches' => $home . '/*'),
                            array('not' => array('href_matches' => $exclude)),
                            array('not' => array('selector_matches' => 'a[rel~="nofollow"]')),
                            array('not' => array('selector_matches' => '.no-prefetch, .no-prefetch a')),
                        ),
                    ),
                    'eagerness' => (string) SEOProStack_Settings::get('preload_pages_eagerness'),
                ),
            ),
        );
        printf('<script type="speculationrules">%s</script>' . "\n", wp_json_encode($rules, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Print rules on admin screens. Always a download (prefetch): preparing
     * a screen in full would run its scripts, such as autosave and the
     * heartbeat, before the click.
     */
    public static function print_admin_rules() {
        $home  = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        $admin = trailingslashit((string) wp_parse_url(admin_url(), PHP_URL_PATH));

        $exclude = array();
        foreach (self::admin_skipped_screens() as $screen) {
            $exclude[] = $admin . '*' . $screen;
        }
        // Query strings that act (action=, bulk actions), carry a nonce
        // (anything that changes something), dismiss a notice (some plugins
        // do this without a nonce) or download a file.
        foreach (array('action', 'nonce', 'dismiss', 'download') as $word) {
            $exclude[] = $admin . '*\\?*' . $word . '*';
        }
        foreach (self::patterns() as $pattern) {
            $exclude[] = $home . $pattern;
        }

        $rules = array(
            'prefetch' => array(
                array(
                    'source'    => 'document',
                    'where'     => array(
                        'and' => array(
                            array('href_matches' => $admin . '*'),
                            array('not' => array('href_matches' => $exclude)),
                            array('not' => array('selector_matches' => 'a[href^="#"], a[download], .no-prefetch, .no-prefetch a')),
                        ),
                    ),
                    'eagerness' => (string) SEOProStack_Settings::get('preload_pages_eagerness'),
                ),
            ),
        );
        printf('<script type="speculationrules">%s</script>' . "\n", wp_json_encode($rules, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Whether this admin request shows the full admin (menu and admin bar),
     * not a screen inside a frame such as Add Media or plugin details.
     *
     * @return bool
     */
    private static function full_admin_screen() {
        return !(defined('IFRAME_REQUEST') && IFRAME_REQUEST);
    }

    /**
     * Admin styles for what shows while the next screen loads
     * (print_admin_navigation()): the screen's title with dots that appear
     * one by one (all three at once when motion is not welcome), in core's
     * own heading style so the page's styles cannot move it.
     *
     * While it shows (sps-nav-shell on <html>), the leaving screen's own
     * look is put back to core's: the usual background (many plugins colour
     * their screens), the content area's usual padding (WooCommerce, Fluent
     * Forms and others remove it, so the next screen's content would jump
     * sideways), and their bars outside the content area hidden. From the
     * click (sps-nav-busy), menu entries lose backgrounds a screen's styles
     * gave them: Fluent Boards colours `.toplevel_page_fluent-boards`, which
     * WordPress also gives its menu entry and link, so the entry turned white
     * once it was no longer the current one. The dots show one, two, three,
     * then none, each for 0.4 s.
     *
     * No fade between screens: WordPress 7.0's fade is what No fade between
     * admin screens (SEOProStack_Admin_Page_Fade) turns off.
     */
    public static function admin_navigation_style() {
        if (!self::full_admin_screen()) {
            return;
        }
        $shell = 'html.sps-nav-shell';
        $css   = '@media (prefers-reduced-motion:no-preference){'
            . '.sps-nav-wait__dots span{animation:sps-nav-dot1 1.6s linear infinite}'
            . '.sps-nav-wait__dots span+span{animation-name:sps-nav-dot2}'
            . '.sps-nav-wait__dots span+span+span{animation-name:sps-nav-dot3}'
            . '}'
            . '@keyframes sps-nav-dot1{0%,74%{opacity:1}75%,100%{opacity:0}}'
            . '@keyframes sps-nav-dot2{0%,24%{opacity:0}25%,74%{opacity:1}75%,100%{opacity:0}}'
            . '@keyframes sps-nav-dot3{0%,49%{opacity:0}50%,74%{opacity:1}75%,100%{opacity:0}}'
            . '#sps-nav-wait{margin:10px 20px 0 2px;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif}'
            . '#sps-nav-wait .sps-nav-wait__title{display:block;margin:0;padding:9px 0 4px;font-size:23px;font-weight:400;line-height:1.3;color:inherit}'
            . '#sps-nav-wait.is-tab{clear:both;margin:16px 2px}'
            . '.sps-nav-wait__dots span{margin-inline-start:.2em}'
            . '#wpbody-content.sps-nav-waiting>:not(#sps-nav-wait),.sps-nav-hidden{display:none!important}'
            . 'html.sps-nav-busy #adminmenu li.menu-top:not(:hover):not(.current):not(.wp-has-current-submenu),'
            . 'html.sps-nav-busy #adminmenu li.menu-top:not(:hover):not(.opensub):not(.current):not(.wp-has-current-submenu)>a.menu-top:not(:focus){background-color:transparent}'
            . "{$shell},{$shell} body{background:#f0f0f1!important}"
            . "{$shell} #wpwrap,{$shell} #wpcontent,{$shell} #wpbody,{$shell} #wpbody-content{background:transparent!important}"
            . "{$shell} #wpcontent{padding-inline-start:20px!important;padding-inline-end:0!important}"
            . "{$shell} #wpbody,{$shell} #wpbody-content{margin-top:0!important;padding-top:0!important}"
            . "{$shell} body>:not(#wpwrap),{$shell} #wpwrap>:not(#adminmenumain):not(#wpcontent),{$shell} #wpcontent>:not(#wpadminbar):not(#wpbody),{$shell} #wpbody>:not(#wpbody-content){display:none!important}"
            . '@media screen and (max-width:782px){'
            . "{$shell} #wpcontent{padding-inline-start:10px!important}"
            . '#sps-nav-wait{margin:10px 12px 0 0}'
            . '}';
        wp_add_inline_style('wp-admin', $css);
    }

    /**
     * Print the script that answers a click on an admin link straight
     * away: the admin menu marks the new screen, and the content area shows
     * its title, with dots, on core's usual layout until the page arrives.
     * A link in the screen's own navigation (tabs, a `nav` element, or the
     * All | Published views above a list) keeps that navigation in view,
     * marks the clicked entry and shows the dots below it instead. Screens
     * still change at once when the page arrives (no fade). The page still
     * loads as normal, so every screen and plugin works as before; only
     * what shows while waiting changes.
     *
     * It acts when the browser starts to leave the page (beforeunload) and
     * nothing has asked to confirm leaving, such as unsaved changes. It
     * waits 100 ms before the placeholder, so a screen that opens at once
     * (already downloaded by the rules above) does not flash it, and puts
     * the screen back if the page stays: on a key or pointer press (Esc
     * stops the load), when shown again from the back-forward cache, and
     * after 30 seconds.
     */
    public static function print_admin_navigation() {
        if (!self::full_admin_screen() || !function_exists('wp_print_inline_script_tag')) {
            return;
        }
        $paths = array((string) wp_parse_url(admin_url(), PHP_URL_PATH));
        if (is_multisite()) {
            $paths[] = (string) wp_parse_url(network_admin_url(), PHP_URL_PATH);
        }
        $data = array(
            'paths'   => array_values(array_unique(array_filter($paths))),
            'loading' => __('Loading…', 'seoprostack'),
        );
        wp_print_inline_script_tag(
            'window.seoprostackAdminNav = ' . wp_json_encode($data) . ";\n" . self::admin_navigation_script(),
            array('id' => 'sps-admin-nav')
        );
    }

    /**
     * The click script (print_admin_navigation()).
     *
     * @return string JavaScript.
     */
    private static function admin_navigation_script() {
        return <<<'JS'
(function (cfg) {
	'use strict';
	if (!cfg || window.self !== window.top || !document.addEventListener || !Element.prototype.closest) {
		return;
	}
	var pending = null, pendingTimer = 0, showTimer = 0, undoTimer = 0, changed = [], hidden = [];
	// A screen's own navigation, and the classes that mark its current entry.
	var NAV = '.nav-tab-wrapper, nav, [role="tablist"], .subsubsub';
	var ACTIVE = ['nav-tab-active', 'is-active', 'active', 'current', 'selected'];

	// An admin screen this page would leave for, opened in this tab.
	function linkFor(e) {
		if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
			return null;
		}
		var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
		var target = a ? a.getAttribute('target') : '';
		if (!a || a.hasAttribute('download') || (target && target !== '_self') || a.origin !== location.origin) {
			return null;
		}
		var inAdmin = cfg.paths.some(function (path) { return a.pathname.indexOf(path) === 0; });
		if (!inAdmin || /\/(admin-ajax|admin-post|async-upload)\.php$/.test(a.pathname) || /download|export/i.test(a.search)) {
			return null;
		}
		if (a.pathname === location.pathname && a.search === location.search) {
			return null;
		}
		return a;
	}

	// The admin menu entry for that screen, if the menu has one.
	function menuLinkFor(a) {
		var menu = document.getElementById('adminmenu');
		if (!menu) {
			return null;
		}
		if (menu.contains(a)) {
			return a;
		}
		var links = menu.querySelectorAll('a[href]');
		for (var i = 0; i < links.length; i++) {
			if (links[i].href === a.href) {
				return links[i];
			}
		}
		return null;
	}

	function edit(el, add, remove) {
		changed.push([el, el.className, el.getAttribute('aria-current')]);
		el.classList.remove.apply(el.classList, remove);
		el.classList.add.apply(el.classList, add);
	}

	// Mark the entry current as WordPress will on the next screen.
	function highlight(link) {
		var menu = document.getElementById('adminmenu');
		var top = link.closest('li.menu-top');
		var topLink = top ? top.querySelector('a.menu-top') : null;
		if (!top || !topLink) {
			return;
		}
		var old = menu.querySelectorAll('.current, .wp-has-current-submenu, [aria-current]');
		for (var i = 0; i < old.length; i++) {
			edit(old[i], old[i].classList.contains('wp-has-submenu') ? ['wp-not-current-submenu'] : [], ['current', 'wp-has-current-submenu', 'wp-menu-open']);
			old[i].removeAttribute('aria-current');
		}
		if (!top.classList.contains('wp-has-submenu')) {
			edit(top, ['current'], []);
			edit(topLink, ['current'], []);
			topLink.setAttribute('aria-current', 'page');
			return;
		}
		edit(top, ['wp-has-current-submenu', 'wp-menu-open'], ['wp-not-current-submenu']);
		edit(topLink, ['wp-has-current-submenu', 'wp-menu-open'], ['wp-not-current-submenu']);
		var sub = link;
		if (link === topLink) {
			sub = null;
			var subs = top.querySelectorAll('.wp-submenu a[href]');
			for (var j = 0; j < subs.length; j++) {
				if (subs[j].href === link.href) {
					sub = subs[j];
					break;
				}
			}
		}
		if (sub && sub.closest('li')) {
			edit(sub.closest('li'), ['current'], []);
			edit(sub, ['current'], []);
			sub.setAttribute('aria-current', 'page');
		}
	}

	// The screen's name from its menu entry, without counts or icons.
	function titleFor(link) {
		if (!link) {
			return '';
		}
		var copy = link.cloneNode(true);
		var extra = copy.querySelectorAll('.wp-menu-image, .awaiting-mod, .update-plugins, .menu-counter, .screen-reader-text, [class*="count"], [class*="badge"], svg, img');
		for (var i = 0; i < extra.length; i++) {
			if (extra[i].parentNode) {
				extra[i].parentNode.removeChild(extra[i]);
			}
		}
		return copy.textContent.replace(/\s+/g, ' ').trim();
	}

	// The screen's own navigation the link is in, if any.
	function navFor(a) {
		var body = document.getElementById('wpbody-content');
		var nav = a.closest(NAV);
		return body && nav && body.contains(nav) ? nav : null;
	}

	function activeClasses(el) {
		return ACTIVE.filter(function (name) { return el.classList.contains(name); });
	}

	// Mark the clicked entry of that navigation current, as the next screen will.
	function markTab(nav, a) {
		var marks = [];
		var links = nav.querySelectorAll('a');
		for (var i = 0; i < links.length; i++) {
			var items = [links[i]];
			if (links[i].parentNode && 'LI' === links[i].parentNode.tagName) {
				items.push(links[i].parentNode);
			}
			for (var j = 0; j < items.length; j++) {
				var classes = activeClasses(items[j]);
				var current = items[j].getAttribute('aria-current');
				if (classes.length || null !== current) {
					marks.push([j, classes, current]);
					edit(items[j], [], classes);
					items[j].removeAttribute('aria-current');
				}
			}
		}
		var item = a.parentNode && 'LI' === a.parentNode.tagName ? a.parentNode : null;
		marks.forEach(function (mark) {
			var el = mark[0] && item ? item : a;
			edit(el, mark[1], []);
			if (null !== mark[2]) {
				el.setAttribute('aria-current', mark[2]);
			}
		});
	}

	// Title (if any) followed by dots that appear one by one.
	function placeholder(title, tab) {
		var wait = document.createElement('div');
		wait.id = 'sps-nav-wait';
		wait.className = tab ? 'sps-nav-wait is-tab' : 'sps-nav-wait';
		wait.setAttribute('role', 'status');
		var line = document.createElement(tab ? 'div' : 'h1');
		line.className = 'sps-nav-wait__title';
		line.textContent = title;
		var dots = document.createElement('span');
		dots.className = 'sps-nav-wait__dots';
		dots.setAttribute('aria-hidden', 'true');
		for (var i = 0; i < 3; i++) {
			dots.appendChild(document.createElement('span')).textContent = '.';
		}
		line.appendChild(dots);
		var label = document.createElement('span');
		label.className = 'screen-reader-text';
		label.textContent = cfg.loading;
		wait.appendChild(line);
		wait.appendChild(label);
		return wait;
	}

	// Another screen: its title on core's usual layout, nothing else.
	function show(title) {
		var body = document.getElementById('wpbody-content');
		if (!body || document.getElementById('sps-nav-wait')) {
			return;
		}
		body.insertBefore(placeholder(title, false), body.firstChild);
		body.classList.add('sps-nav-waiting');
		body.setAttribute('aria-busy', 'true');
		document.documentElement.classList.add('sps-nav-shell');
		window.scrollTo(0, 0);
	}

	// Another part of this screen: keep everything up to its navigation and
	// show the dots below it. Hidden from the navigation's own block (the
	// child of the .wrap it is in) outwards, so a header around it stays.
	function showTab(nav) {
		var body = document.getElementById('wpbody-content');
		if (!body || document.getElementById('sps-nav-wait') || !body.contains(nav)) {
			return;
		}
		var wrap = nav.closest('.wrap');
		var container = wrap && body.contains(wrap) ? wrap : body;
		var keep = nav;
		while (keep.parentNode && keep.parentNode !== container) {
			keep = keep.parentNode;
		}
		for (var el = keep; el && el !== body; el = el.parentNode) {
			for (var next = el.nextElementSibling; next; next = next.nextElementSibling) {
				if (!next.classList.contains('sps-nav-hidden')) {
					next.classList.add('sps-nav-hidden');
					hidden.push(next);
				}
			}
		}
		keep.parentNode.insertBefore(placeholder('', true), keep.nextSibling);
		body.setAttribute('aria-busy', 'true');
	}

	function undo() {
		clearTimeout(showTimer);
		clearTimeout(undoTimer);
		document.removeEventListener('keydown', undo, true);
		document.removeEventListener('pointerdown', undo, true);
		var wait = document.getElementById('sps-nav-wait');
		if (wait && wait.parentNode) {
			wait.parentNode.removeChild(wait);
		}
		var body = document.getElementById('wpbody-content');
		if (body) {
			body.classList.remove('sps-nav-waiting');
			body.removeAttribute('aria-busy');
		}
		document.documentElement.classList.remove('sps-nav-shell', 'sps-nav-busy');
		hidden.forEach(function (el) { el.classList.remove('sps-nav-hidden'); });
		hidden = [];
		for (var i = changed.length - 1; i >= 0; i--) {
			changed[i][0].className = changed[i][1];
			if (changed[i][2] === null) {
				changed[i][0].removeAttribute('aria-current');
			} else {
				changed[i][0].setAttribute('aria-current', changed[i][2]);
			}
		}
		changed = [];
	}

	function leaving(e) {
		var a = pending;
		pending = null;
		// Another handler asked to confirm leaving (unsaved changes): leave the
		// screen alone. returnValue is a string, empty unless a handler set it.
		if (!a || e.defaultPrevented || ('string' === typeof e.returnValue && '' !== e.returnValue)) {
			return;
		}
		undo();
		document.documentElement.classList.add('sps-nav-busy');
		var link = menuLinkFor(a);
		if (link) {
			highlight(link);
		}
		var nav = navFor(a);
		if (nav) {
			markTab(nav, a);
			showTimer = setTimeout(function () { showTab(nav); }, 100);
		} else {
			var title = titleFor(link);
			showTimer = setTimeout(function () { show(title); }, 100);
		}
		undoTimer = setTimeout(undo, 30000);
		document.addEventListener('keydown', undo, true);
		document.addEventListener('pointerdown', undo, true);
	}

	document.addEventListener('click', function (e) {
		var a = linkFor(e);
		if (!a) {
			return;
		}
		pending = a;
		clearTimeout(pendingTimer);
		pendingTimer = setTimeout(function () { pending = null; }, 1000);
		// Added again on every click, so it runs after every handler the page
		// has added by then, including ones that ask to confirm leaving.
		window.removeEventListener('beforeunload', leaving);
		window.addEventListener('beforeunload', leaving);
	});

	window.addEventListener('pageshow', function (e) {
		if (e.persisted) {
			undo();
		}
	});
})(window.seoprostackAdminNav);
JS;
    }
}
