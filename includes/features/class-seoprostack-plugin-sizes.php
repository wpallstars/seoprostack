<?php
/**
 * Size column on the Plugins screen.
 *
 * Shows how much disk space each plugin uses, split into PHP, JavaScript,
 * CSS, media and other files, so heavy plugins stand out. Large PHP and
 * JavaScript totals often (not always) mean more work on every page.
 *
 * Sizes are measured in the background: the screen renders from the cache,
 * and the script asks for missing sizes in small, time-limited batches.
 * Rows below the list total every installed plugin and the active ones.
 * Each plugin's size is kept until its version changes. Cached in one
 * network-wide option (plugins are shared by every site), removed on
 * uninstall. The Recommended plugins list (All) shows the same sizes for
 * installed plugins whether or not this setting is on.
 *
 * Each cell also shows how much OPcache memory the plugin's compiled PHP
 * takes now (opcache_get_status() with scripts, read once per screen and
 * never stored), since that memory is what each PHP worker shares and what
 * a full cache evicts first. Only files PHP has loaded since OPcache last
 * restarted are counted, so an inactive plugin shows none.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Plugin_Sizes extends SEOProStack_Feature {

    const KEY = 'plugin_sizes';

    /** Network-wide option: plugin file => array(v => version, s => sizes). */
    const CACHE = 'seoprostack_plugin_sizes';

    /** Column ID. */
    const COLUMN = 'seoprostack_size';

    /** AJAX action. */
    const AJAX = 'seoprostack_plugin_sizes';

    /** Seconds one AJAX request may spend measuring. */
    const BUDGET = 3;

    /** Size groups, in display order. */
    const GROUPS = array('php', 'js', 'css', 'media', 'other');

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
                'tab'         => 'plugins',
                'label'       => __('Plugin sizes', 'seoprostack'),
                'description' => __('Add a Size column to the Plugins screen with each plugin’s PHP, JavaScript, CSS, media and other files, and the OPcache memory its compiled code takes now, so heavy plugins stand out. Click the column heading to sort. Totals for installed and active plugins are shown below the list.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!is_admin()) {
            return;
        }
        // Always: the Recommended plugins list shows sizes whether or not the column is on.
        add_action('wp_ajax_' . self::AJAX, array(__CLASS__, 'ajax_measure'));
        if (self::enabled()) {
            add_action('load-plugins.php', array(__CLASS__, 'load_screen'));
        }
    }

    /**
     * Hook the list table on the Plugins screen.
     */
    public static function load_screen() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        $screen = get_current_screen();
        $id     = $screen ? $screen->id : 'plugins';
        add_filter('manage_' . $id . '_columns', array(__CLASS__, 'add_column'));
        add_action('manage_plugins_custom_column', array(__CLASS__, 'render_column'), 10, 2);
        add_action('admin_print_footer_scripts', array(__CLASS__, 'script'));
        add_action('admin_head', array(__CLASS__, 'style'));
    }

    /**
     * Add the column.
     *
     * @param array $columns Columns.
     * @return array
     */
    public static function add_column($columns) {
        $columns[self::COLUMN] = __('Size', 'seoprostack');
        return $columns;
    }

    /**
     * Render a cell from the cache, or a placeholder the script fills in.
     *
     * @param string $column Column ID.
     * @param string $file   Plugin file.
     */
    public static function render_column($column, $file) {
        if (self::COLUMN !== $column) {
            return;
        }
        echo self::cell_for($file); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in cell_for().
    }

    /**
     * A plugin's cell from the cache, or a placeholder the script fills in.
     * Also used by the Recommended plugins list.
     *
     * @param string $file Plugin file.
     * @return string
     */
    public static function cell_for($file) {
        $sizes = self::cached($file);
        if (null === $sizes) {
            return sprintf(
                '<span class="sps-size is-pending" data-sps-size-file="%1$s">%2$s</span>',
                esc_attr($file),
                esc_html__('Measuring…', 'seoprostack')
            );
        }
        return self::cell($sizes, self::opcache_for(array($file)));
    }

    /**
     * OPcache memory now used by each plugin's compiled scripts.
     *
     * Read once per request. Empty when OPcache is off, its scripts list is
     * kept from this site (opcache.restrict_api) or PHP runs from the
     * command line, where OPcache is a different cache from the web's.
     *
     * @return array<string,array{bytes:int,files:int}>|null Keyed by plugin folder (or file, for single-file plugins); null when unknown.
     */
    public static function opcache() {
        static $by_plugin = false;
        if (false !== $by_plugin) {
            return $by_plugin;
        }
        $by_plugin = null;
        if ('cli' === PHP_SAPI || !function_exists('opcache_get_status') || !filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)) {
            return $by_plugin;
        }
        // False when opcache.restrict_api keeps this script out.
        $status = @opcache_get_status(true); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if (!is_array($status) || empty($status['opcache_enabled']) || !isset($status['scripts']) || !is_array($status['scripts'])) {
            return $by_plugin;
        }
        $root      = wp_normalize_path(trailingslashit(WP_PLUGIN_DIR));
        $length    = strlen($root);
        $by_plugin = array();
        foreach ($status['scripts'] as $path => $script) {
            $path = wp_normalize_path((string) (isset($script['full_path']) ? $script['full_path'] : $path));
            if (0 !== strncmp($path, $root, $length)) {
                continue;
            }
            $relative = substr($path, $length);
            $slash    = strpos($relative, '/');
            $key      = false === $slash ? $relative : substr($relative, 0, $slash);
            if (!isset($by_plugin[$key])) {
                $by_plugin[$key] = array('bytes' => 0, 'files' => 0);
            }
            $by_plugin[$key]['bytes'] += isset($script['memory_consumption']) ? (int) $script['memory_consumption'] : 0;
            $by_plugin[$key]['files']++;
        }
        return $by_plugin;
    }

    /**
     * OPcache memory for some plugins together.
     *
     * @param string[] $files Plugin files.
     * @return int|null Bytes; null when OPcache cannot be read.
     */
    private static function opcache_for(array $files) {
        $by_plugin = self::opcache();
        if (null === $by_plugin) {
            return null;
        }
        $bytes = 0;
        foreach ($files as $file) {
            $dir  = dirname($file);
            $key  = '.' === $dir ? $file : $dir;
            $bytes += isset($by_plugin[$key]) ? $by_plugin[$key]['bytes'] : 0;
        }
        return $bytes;
    }

    /**
     * Cell markup.
     *
     * @param array    $sizes   Sizes in bytes, keyed by group plus total.
     * @param int|null $opcache OPcache memory in bytes; null when OPcache cannot be read.
     * @return string
     */
    public static function cell(array $sizes, $opcache = null) {
        $labels = array(
            'php'   => __('PHP', 'seoprostack'),
            'js'    => __('JS', 'seoprostack'),
            'css'   => __('CSS', 'seoprostack'),
            'media' => __('Media', 'seoprostack'),
            'other' => __('Other', 'seoprostack'),
        );
        $parts = array();
        foreach (self::GROUPS as $group) {
            if (!empty($sizes[$group])) {
                $parts[] = sprintf('<span>%1$s %2$s</span>', esc_html($labels[$group]), esc_html((string) size_format($sizes[$group], 1)));
            }
        }
        $memory = '';
        if ($opcache) {
            $memory = sprintf(
                '<span class="sps-size__opcache" title="%1$s">%2$s</span>',
                esc_attr__('OPcache memory its compiled PHP takes now. Only files PHP has loaded since OPcache last restarted count, so a plugin that is not active shows none.', 'seoprostack'),
                /* translators: %s: memory size, such as 2.1 MB. */
                esc_html(sprintf(__('OPcache %s', 'seoprostack'), (string) size_format($opcache, 1)))
            );
        }
        return sprintf(
            '<span class="sps-size" data-sps-size="%1$d"><strong>%2$s</strong>%3$s%4$s</span>',
            (int) $sizes['total'],
            esc_html((string) size_format($sizes['total'], 1)),
            $parts ? '<span class="sps-size__parts">' . implode(' ', $parts) . '</span>' : '',
            $memory
        );
    }

    /**
     * Cached sizes for a plugin at its installed version.
     *
     * @param string $file Plugin file.
     * @return array|null
     */
    private static function cached($file) {
        static $cache = null, $plugins = null;
        if (null === $cache) {
            $cache   = (array) get_site_option(self::CACHE, array());
            $plugins = get_plugins();
        }
        return isset($plugins[$file]) ? self::valid($cache, $file, $plugins[$file]) : null;
    }

    /**
     * A cache entry's sizes, if it matches the installed version.
     *
     * @param array  $cache  Cache option.
     * @param string $file   Plugin file.
     * @param array  $plugin Plugin headers.
     * @return array|null
     */
    private static function valid(array $cache, $file, array $plugin) {
        // Entries from before PHP files were counted are measured again.
        if (!isset($cache[$file]['v'], $cache[$file]['s']['php_files'])) {
            return null;
        }
        $version = isset($plugin['Version']) ? (string) $plugin['Version'] : '';
        return $cache[$file]['v'] === $version ? (array) $cache[$file]['s'] : null;
    }

    /**
     * Totals rows for all installed plugins and the active ones.
     *
     * Covers every plugin, not only those in the current view, so the
     * numbers are the same on every tab of the Plugins screen.
     *
     * @param array $cache   Cache option.
     * @param array $plugins Installed plugins, from get_plugins().
     * @param bool  $network Count network-activated plugins as active (Network Plugins screen).
     * @return array|null Rows keyed installed and active, each with label, cell and missing; null with no plugins.
     */
    private static function totals(array $cache, array $plugins, $network) {
        if (!$plugins) {
            return null;
        }
        $zero   = array('sizes' => array_fill_keys(array_merge(self::GROUPS, array('total')), 0), 'count' => 0, 'missing' => 0, 'files' => array());
        $totals = array('installed' => $zero, 'active' => $zero);
        foreach ($plugins as $file => $plugin) {
            $sizes  = self::valid($cache, $file, (array) $plugin);
            $active = $network ? is_plugin_active_for_network($file) : SEOProStack_Plugin_Loader::is_active($file);
            foreach ($active ? array('installed', 'active') : array('installed') as $key) {
                $totals[$key]['count']++;
                $totals[$key]['files'][] = $file;
                if (null === $sizes) {
                    $totals[$key]['missing']++;
                    continue;
                }
                foreach (array_keys($totals[$key]['sizes']) as $group) {
                    $totals[$key]['sizes'][$group] += isset($sizes[$group]) ? (int) $sizes[$group] : 0;
                }
            }
        }

        $rows = array();
        foreach ($totals as $key => $total) {
            $count = number_format_i18n($total['count']);
            if ('installed' === $key) {
                /* translators: %s: number of plugins. */
                $label = sprintf(_n('%s installed plugin', '%s installed plugins', $total['count'], 'seoprostack'), $count);
            } elseif ($network) {
                /* translators: %s: number of plugins. */
                $label = sprintf(_n('%s network active plugin', '%s network active plugins', $total['count'], 'seoprostack'), $count);
            } else {
                /* translators: %s: number of plugins. */
                $label = sprintf(_n('%s active plugin', '%s active plugins', $total['count'], 'seoprostack'), $count);
            }
            $cell = $total['sizes']['total'] ? self::cell($total['sizes'], self::opcache_for($total['files'])) : ''; // Nothing measured yet, or no plugins.
            if ($total['missing']) {
                $cell .= sprintf(
                    '<span class="sps-size__missing">%s</span>',
                    /* translators: %s: number of plugins. */
                    esc_html(sprintf(_n('%s plugin not measured', '%s plugins not measured', $total['missing'], 'seoprostack'), number_format_i18n($total['missing'])))
                );
            }
            $rows[$key] = array(
                'label'   => esc_html($label),
                'cell'    => $cell,
                'missing' => $total['missing'],
            );
        }
        return $rows;
    }

    /**
     * Measure the requested plugins until the time budget runs out.
     */
    public static function ajax_measure() {
        check_ajax_referer(self::AJAX, 'nonce');
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(array('message' => __('You are not allowed to do that.', 'seoprostack')), 403);
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $files   = isset($_POST['files']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['files'])) : array();
        $cache   = (array) get_site_option(self::CACHE, array());
        $cache   = array_intersect_key($cache, $plugins); // Forget deleted plugins.
        $network = is_multisite() && !empty($_POST['network']);
        $start   = microtime(true);
        $done    = array();

        if (!$files && !empty($_POST['rest'])) {
            // Nothing left on screen: measure plugins outside the current view, for the totals.
            foreach ($plugins as $file => $plugin) {
                if (null === self::valid($cache, $file, (array) $plugin)) {
                    $files[] = $file;
                }
            }
        }

        foreach (array_slice(array_unique($files), 0, 50) as $file) {
            if (!isset($plugins[$file])) {
                continue; // Only installed plugins, so no other paths can be measured.
            }
            if (microtime(true) - $start > self::BUDGET) {
                break;
            }
            $sizes        = self::measure($file);
            $cache[$file] = array('v' => (string) $plugins[$file]['Version'], 's' => $sizes);
            $done[$file]  = self::cell($sizes, self::opcache_for(array($file)));
        }

        self::save($cache);
        wp_send_json_success(array(
            'cells'  => $done,
            'totals' => self::totals($cache, $plugins, $network),
        ));
    }

    /**
     * Store the cache.
     *
     * @param array $cache Cache option.
     */
    private static function save(array $cache) {
        if (is_multisite()) {
            update_site_option(self::CACHE, $cache);
        } else {
            update_option(self::CACHE, $cache, false); // Only the Plugins screen and Hosting needs read it.
        }
    }

    /**
     * PHP code in some plugins, from the cache, measuring missing plugins
     * until the time budget runs out. Used by Hosting needs, which works
     * whether or not the Size column is on.
     *
     * @param string[] $files  Plugin files.
     * @param float    $budget Seconds to spend measuring; 0 reads the cache only.
     * @return array bytes, files (PHP files) and missing (plugins not measured).
     */
    public static function php_code(array $files, $budget) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $cache   = (array) get_site_option(self::CACHE, array());
        $start   = microtime(true);
        $changed = false;
        $code    = array('bytes' => 0, 'files' => 0, 'missing' => 0);
        foreach (array_unique($files) as $file) {
            if (!isset($plugins[$file])) {
                continue;
            }
            $sizes = self::valid($cache, $file, (array) $plugins[$file]);
            if (null === $sizes && $budget > 0 && microtime(true) - $start < $budget) {
                $sizes        = self::measure($file);
                $cache[$file] = array('v' => (string) $plugins[$file]['Version'], 's' => $sizes);
                $changed      = true;
            }
            if (null === $sizes) {
                $code['missing']++;
                continue;
            }
            $code['bytes'] += isset($sizes['php']) ? (int) $sizes['php'] : 0;
            $code['files'] += isset($sizes['php_files']) ? (int) $sizes['php_files'] : 0;
        }
        if ($changed) {
            self::save(array_intersect_key($cache, $plugins));
        }
        return $code;
    }

    /**
     * Add up a plugin's files by type.
     *
     * @param string $file Plugin file, relative to the plugins directory.
     * @return array Bytes per group, plus total, and php_files (number of PHP files).
     */
    public static function measure($file) {
        $sizes = array_fill_keys(self::GROUPS, 0);
        $types = array(
            'php'   => array('php', 'phtml', 'inc'),
            'js'    => array('js', 'mjs', 'cjs', 'jsx', 'ts', 'map'),
            'css'   => array('css', 'scss', 'sass', 'less'),
            'media' => array('png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'mp4', 'webm', 'mov', 'mp3', 'ogg', 'wav', 'pdf'),
        );
        $group_of = array();
        foreach ($types as $group => $extensions) {
            foreach ($extensions as $extension) {
                $group_of[$extension] = $group;
            }
        }

        $php_files = 0; // For OPcache's file limit (Hosting needs).
        $dir       = dirname($file);
        if ('.' === $dir) {
            // Single-file plugin such as Hello Dolly.
            $path         = WP_PLUGIN_DIR . '/' . $file;
            $sizes['php'] = is_file($path) ? (int) filesize($path) : 0;
            $php_files    = $sizes['php'] ? 1 : 0;
        } else {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(WP_PLUGIN_DIR . '/' . $dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY,
                    RecursiveIteratorIterator::CATCH_GET_CHILD
                );
                foreach ($iterator as $item) {
                    if ($item->isFile() && !$item->isLink()) {
                        $extension = strtolower($item->getExtension());
                        $group     = isset($group_of[$extension]) ? $group_of[$extension] : 'other';
                        $sizes[$group] += (int) $item->getSize();
                        if ('php' === $group) {
                            $php_files++;
                        }
                    }
                }
            } catch (Exception $e) {
                // Unreadable folder: report what was counted.
                unset($e);
            }
        }

        $sizes['total']     = array_sum($sizes);
        $sizes['php_files'] = $php_files;
        return $sizes;
    }

    /**
     * Column styles.
     */
    public static function style() {
        ?>
        <style>
            .column-<?php echo esc_attr(self::COLUMN); ?> { width: 11em; }
            .sps-size { display: block; }
            .sps-size.is-pending { color: #646970; }
            .sps-size__parts { display: flex; flex-wrap: wrap; gap: 0 8px; margin-top: 2px; font-size: 12px; color: #646970; }
            .sps-size-sort { padding: 0; font: inherit; color: inherit; background: none; border: 0; cursor: pointer; }
            .sps-size-sort:hover, .sps-size-sort:focus { color: var(--wp-admin-theme-color, #2271b1); }
            .sps-size-sort .dashicons { font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; }
            .sps-size-totals td { background: #f6f7f7; }
            .sps-size-totals tr:first-child td { border-top: 2px solid #c3c4c7; }
            .sps-size-totals tr + tr td { border-top: 1px solid #dcdcde; }
            .sps-size__opcache { display: block; margin-top: 2px; font-size: 12px; color: #646970; }
            .sps-size__missing { display: block; margin-top: 2px; font-size: 12px; color: #646970; font-style: italic; }
            @media screen and (max-width: 782px) {
                .sps-size-totals td:not(.column-primary):not(.column-<?php echo esc_attr(self::COLUMN); ?>) { display: none !important; }
                .sps-size-totals td.column-<?php echo esc_attr(self::COLUMN); ?>:not(.hidden) { display: block !important; }
            }
        </style>
        <?php
    }

    /**
     * Fill in missing sizes, and sort by size when the heading is clicked.
     */
    public static function script() {
        $network = is_network_admin();
        $data    = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX,
            'nonce'   => wp_create_nonce(self::AJAX),
            'column'  => self::COLUMN,
            'network' => $network,
            'totals'  => self::totals((array) get_site_option(self::CACHE, array()), get_plugins(), $network),
            'i18n'    => array(
                'failed'   => __('Could not measure', 'seoprostack'),
                'sort'     => __('Sort by size', 'seoprostack'),
                'largest'  => __('Sorted by size, largest first', 'seoprostack'),
                'smallest' => __('Sorted by size, smallest first', 'seoprostack'),
            ),
        );
        ?>
        <script>
        (function ($, cfg) {
            var $table = $('.wp-list-table.plugins');
            if (!$table.length) { return; }

            // Totals for all installed plugins and the active ones, below the list.
            var $totals = $('<tbody class="sps-size-totals"></tbody>');
            var missing = cfg.totals ? cfg.totals.installed.missing : 0;
            function totals(rows) {
                if (!rows) { return; }
                var $columns = $table.find('thead tr').first().children();
                $totals.empty();
                $.each(['installed', 'active'], function (i, key) {
                    var $tr = $('<tr></tr>');
                    $columns.each(function () {
                        var $head = $(this);
                        var $td = $('<td></td>').addClass('column-' + this.id);
                        if ('cb' === this.id) { $td.addClass('check-column'); }
                        if ($head.hasClass('column-primary')) { $td.addClass('column-primary').html('<strong>' + rows[key].label + '</strong>'); }
                        if ($head.hasClass('hidden')) { $td.addClass('hidden'); }
                        if (cfg.column === this.id) { $td.html(rows[key].cell); }
                        $tr.append($td);
                    });
                    $totals.append($tr);
                });
                if (!$totals.parent().length) { $table.find('tbody#the-list').after($totals); }
            }
            totals(cfg.totals);

            // Measure missing sizes a batch at a time; the server stops after a few seconds.
            // Plugins on screen go first, then the rest so the totals are complete.
            function measure() {
                var files = $table.find('[data-sps-size-file]').map(function () { return $(this).data('sps-size-file'); }).get();
                if (!files.length && !missing) { return; }
                $.post(cfg.ajaxUrl, { action: cfg.action, nonce: cfg.nonce, files: files.slice(0, 20), rest: files.length ? 0 : 1, network: cfg.network ? 1 : 0 })
                    .done(function (response) {
                        var data = (response && response.success && response.data) || {};
                        var measured = 0;
                        $.each(data.cells || {}, function (file, html) {
                            $table.find('[data-sps-size-file]').filter(function () { return $(this).data('sps-size-file') === file; }).replaceWith(html);
                            measured++;
                        });
                        if (data.totals) {
                            totals(data.totals);
                            missing = data.totals.installed.missing;
                        }
                        if (measured) { measure(); } else { fail(); }
                    })
                    .fail(fail);
            }
            function fail() {
                missing = 0;
                $table.find('[data-sps-size-file]').removeAttr('data-sps-size-file').text(cfg.i18n.failed);
            }
            measure();

            // Sort by size. A plugin's row is followed by its update/notice rows; keep them together.
            var $th = $table.find('thead th.column-' + cfg.column + ', tfoot th.column-' + cfg.column);
            var order = 0;
            $th.each(function () {
                var $button = $('<button type="button" class="sps-size-sort"></button>')
                    .attr('aria-label', cfg.i18n.sort)
                    .text($(this).text())
                    .append(' <span class="dashicons dashicons-sort" aria-hidden="true"></span>');
                $(this).empty().append($button);
            });
            $table.on('click', '.sps-size-sort', function () {
                order = order === -1 ? 1 : -1;
                var $body = $table.find('tbody#the-list');
                var groups = [];
                $body.children('tr').each(function () {
                    // Core's update rows also carry data-plugin, so start a group only at a plugin row.
                    if ($(this).is('[data-plugin]:not(.plugin-update-tr)') || !groups.length) {
                        groups.push([this]);
                    } else {
                        groups[groups.length - 1].push(this);
                    }
                });
                groups.sort(function (a, b) {
                    var sa = parseInt($(a[0]).find('[data-sps-size]').data('sps-size'), 10) || 0;
                    var sb = parseInt($(b[0]).find('[data-sps-size]').data('sps-size'), 10) || 0;
                    return order === -1 ? sb - sa : sa - sb;
                });
                $.each(groups, function (i, rows) { $body.append(rows); });
                $table.find('.sps-size-sort .dashicons').attr('class', 'dashicons ' + (order === -1 ? 'dashicons-arrow-down' : 'dashicons-arrow-up'));
                $th.attr('aria-sort', order === -1 ? 'descending' : 'ascending');
                if (window.wp && wp.a11y) { wp.a11y.speak(order === -1 ? cfg.i18n.largest : cfg.i18n.smallest); }
            });
        })(jQuery, <?php echo wp_json_encode($data); ?>);
        </script>
        <?php
    }
}
