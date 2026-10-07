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
     * Admin styles for moving between screens: the placeholder shown while
     * the next screen loads, and a short fade between screens in browsers
     * with cross-document view transitions, with the menu and admin bar
     * held still. Both pages of a move must opt in, so this is on every
     * admin screen. No fade when the visitor asks for reduced motion.
     */
    public static function admin_navigation_style() {
        if (!self::full_admin_screen()) {
            return;
        }
        $css = '@media (prefers-reduced-motion:no-preference){'
            . '@view-transition{navigation:auto}'
            . '#adminmenuwrap{view-transition-name:sps-admin-menu}'
            . '#wpadminbar{view-transition-name:sps-admin-bar}'
            . '::view-transition-group(root){animation-duration:.15s}'
            . '::view-transition-group(sps-admin-menu),::view-transition-group(sps-admin-bar),::view-transition-new(sps-admin-menu),::view-transition-new(sps-admin-bar){animation:none}'
            . '::view-transition-old(sps-admin-menu),::view-transition-old(sps-admin-bar){display:none}'
            . '.sps-nav-wait__line{animation:sps-nav-wait 1s ease-in-out infinite alternate}'
            . '}'
            . '@keyframes sps-nav-wait{to{opacity:.14}}'
            . '#wpbody-content.sps-nav-waiting>:not(#sps-nav-wait){display:none!important}'
            . '.sps-nav-wait__line{max-width:46em;height:.9em;margin:1.2em 0;border-radius:3px;background:currentColor;opacity:.07}'
            . '.sps-nav-wait__line:nth-child(3n+2){max-width:34em}'
            . '.sps-nav-wait__line:nth-child(3n){max-width:40em}';
        wp_add_inline_style('wp-admin', $css);
    }

    /**
     * Print the script that answers a click on an admin link straight
     * away: the admin menu marks the new screen, and the content area shows
     * its title and a placeholder until the page arrives. The page still
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
	var pending = null, pendingTimer = 0, showTimer = 0, undoTimer = 0, listening = false, changed = [];

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

	function show(title) {
		var body = document.getElementById('wpbody-content');
		if (!body || document.getElementById('sps-nav-wait')) {
			return;
		}
		var wait = document.createElement('div');
		wait.id = 'sps-nav-wait';
		wait.className = 'wrap sps-nav-wait';
		wait.setAttribute('role', 'status');
		if (title) {
			var h1 = document.createElement('h1');
			h1.textContent = title;
			wait.appendChild(h1);
		}
		var label = document.createElement('span');
		label.className = 'screen-reader-text';
		label.textContent = cfg.loading;
		wait.appendChild(label);
		for (var i = 0; i < 6; i++) {
			var line = document.createElement('div');
			line.className = 'sps-nav-wait__line';
			wait.appendChild(line);
		}
		body.insertBefore(wait, body.firstChild);
		body.classList.add('sps-nav-waiting');
		body.setAttribute('aria-busy', 'true');
		window.scrollTo(0, 0);
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
		// Another handler asked to confirm leaving (unsaved changes): leave the screen alone.
		if (!a || e.defaultPrevented || e.returnValue) {
			return;
		}
		undo();
		var link = menuLinkFor(a);
		if (link) {
			highlight(link);
		}
		var title = titleFor(link);
		showTimer = setTimeout(function () { show(title); }, 100);
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
		// Added on the first click, after the page's own handlers, so they run first.
		if (!listening) {
			listening = true;
			window.addEventListener('beforeunload', leaving);
		}
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
