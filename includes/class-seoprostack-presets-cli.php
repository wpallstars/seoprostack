<?php
/**
 * WP-CLI commands for plugin presets: `wp seoprostack presets …`.
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * List, compare, apply, reset, undo and make plugin presets.
 *
 * Presets set other plugins to SEO Pro Stack's preferred settings. Licence
 * keys, API keys, passwords and similar are never stored or changed.
 */
class SEOProStack_Presets_CLI {

    /**
     * List the presets and whether this site's settings match.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * @subcommand list
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Options.
     */
    public function list_($args, $assoc_args) {
        $installed = $this->installed();
        $rows      = array();
        foreach (SEOProStack_Presets::all() as $slug => $preset) {
            $undo   = SEOProStack_Presets::undo_info($slug);
            $rows[] = array(
                'plugin'    => $slug,
                'name'      => $preset['name'],
                'tested'    => $preset['tested'],
                'installed' => isset($installed[$slug]) ? $installed[$slug] : '',
                'differ'    => count(SEOProStack_Presets::differences($slug)),
                'undo'      => $undo ? ('defaults' === $undo['set'] ? 'reset ' : 'apply ') . gmdate('Y-m-d H:i', $undo['time']) : '',
            );
        }
        WP_CLI\Utils\format_items(isset($assoc_args['format']) ? $assoc_args['format'] : 'table', $rows, array('plugin', 'name', 'tested', 'installed', 'differ', 'undo'));
    }

    /**
     * Show the settings that differ from a preset.
     *
     * ## OPTIONS
     *
     * <plugin>
     * : Plugin folder, such as antispam-bee.
     *
     * [--defaults]
     * : Compare with the plugin's defaults instead.
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Options.
     */
    public function diff($args, $assoc_args) {
        $slug = $this->preset_slug($args[0]);
        $set  = WP_CLI\Utils\get_flag_value($assoc_args, 'defaults') ? 'defaults' : 'options';
        $preset = SEOProStack_Presets::get($slug);
        $rows   = array();
        foreach (SEOProStack_Presets::differences($slug, $set) as $path => $values) {
            $label  = isset($preset['settings'][$path]) ? $preset['settings'][$path]['label'] : '';
            $rows[] = array('setting' => $path, 'name' => $label, 'now' => $this->show($values[0]), 'preset' => $this->show($values[1]));
        }
        if (!$rows) {
            WP_CLI::success('defaults' === $set ? 'Settings are at the defaults.' : 'Settings match the preset.');
            return;
        }
        WP_CLI\Utils\format_items('table', $rows, array('setting', 'name', 'now', 'preset'));
    }

    /**
     * Set plugins to SEO Pro Stack's preset. The settings before are kept for undo.
     *
     * ## OPTIONS
     *
     * [<plugin>...]
     * : Plugin folders.
     *
     * [--all]
     * : Every plugin with a preset that is installed.
     *
     * [--only=<settings>]
     * : Comma-separated settings to change, as `diff` names them
     * (tutor_option.course_retake_feature). The preset's others stay as they are.
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Options.
     */
    public function apply($args, $assoc_args) {
        $this->write($args, $assoc_args, 'options');
    }

    /**
     * Set the preset's settings to the plugins' own defaults. The settings before are kept for undo.
     *
     * ## OPTIONS
     *
     * [<plugin>...]
     * : Plugin folders.
     *
     * [--all]
     * : Every plugin with a preset that is installed.
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Options.
     */
    public function reset($args, $assoc_args) {
        $this->write($args, $assoc_args, 'defaults');
    }

    /**
     * Put back the settings from before the last apply or reset.
     *
     * ## OPTIONS
     *
     * <plugin>...
     * : Plugin folders.
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Options.
     */
    public function undo($args, $assoc_args) {
        unset($assoc_args);
        foreach ($args as $slug) {
            $result = SEOProStack_Presets::undo($slug);
            if (is_wp_error($result)) {
                WP_CLI::warning($slug . ': ' . $result->get_error_message());
                continue;
            }
            WP_CLI::success(sprintf('%s: %d %s put back.', $slug, $result, 1 === (int) $result ? 'option' : 'options'));
        }
    }

    /**
     * Print a plugin's settings as a preset (JSON), without secrets.
     *
     * Run it on a fresh install before changing anything and save the output,
     * change the settings in the plugin's own screen, then run it again with
     * --compare=<saved file>: only the changed settings are kept, with the
     * fresh values as the defaults.
     *
     * ## OPTIONS
     *
     * <plugin>
     * : Plugin folder, such as antispam-bee.
     *
     * [--options=<names>]
     * : Option names, comma-separated.
     *
     * [--like=<patterns>]
     * : Option name patterns for SQL LIKE, comma-separated, such as 'cfturnstile\_%'.
     * Transients are left out.
     *
     * [--compare=<file>]
     * : An earlier export. Keep only settings that differ from it.
     *
     * Without --options or --like, the preset's own option names are used.
     *
     * @param array $args       Arguments.
     * @param array $assoc_args Options.
     */
    public function export($args, $assoc_args) {
        global $wpdb;
        $slug  = $args[0];
        $names = array();
        if (!empty($assoc_args['options'])) {
            $names = array_map('trim', explode(',', (string) $assoc_args['options']));
        }
        if (!empty($assoc_args['like'])) {
            foreach (array_map('trim', explode(',', (string) $assoc_args['like'])) as $pattern) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off export from the command line.
                $found = $wpdb->get_col($wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_name",
                    $pattern,
                    $wpdb->esc_like('_transient_') . '%',
                    $wpdb->esc_like('_site_transient_') . '%'
                ));
                $names = array_merge($names, $found);
            }
        }
        $preset = SEOProStack_Presets::get($slug);
        if (!$names && $preset) {
            $names = array_keys($preset['options']);
        }
        if (!$names) {
            WP_CLI::error('Name the options with --options or --like.');
        }

        $compare = null;
        if (!empty($assoc_args['compare'])) {
            $file = (string) $assoc_args['compare'];
            if (!is_readable($file)) {
                WP_CLI::error(sprintf('Cannot read %s.', $file));
            }
            $compare = json_decode((string) file_get_contents($file), true); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file named on the command line.
            if (!is_array($compare)) {
                WP_CLI::error(sprintf('%s is not JSON.', $file));
            }
        }

        $export    = SEOProStack_Presets::export($names, $compare);
        $installed = $this->installed(true);
        $out       = array(
            'name'    => isset($installed[$slug]) ? $installed[$slug]['name'] : ($preset ? $preset['name'] : $slug),
            'tested'  => isset($installed[$slug]) ? $installed[$slug]['version'] : '',
            'updated' => gmdate('Y-m-d'),
            'notes'   => $preset ? $preset['notes'] : '',
            'options' => $export['options'] ? $export['options'] : new stdClass(),
        );
        if (null !== $compare) {
            $out['defaults'] = $export['defaults'] ? $export['defaults'] : new stdClass();
        } elseif ($preset && $preset['defaults']) {
            $out['defaults'] = $preset['defaults'];
        }
        foreach ($export['skipped'] as $skipped) {
            WP_CLI::warning(sprintf('Left out %s: it looks like a secret.', $skipped));
        }
        foreach ($export['warnings'] as $warning) {
            WP_CLI::warning($warning);
        }
        WP_CLI::line((string) wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Apply or reset.
     *
     * @param array  $args       Plugin folders.
     * @param array  $assoc_args Options.
     * @param string $set        options or defaults.
     */
    private function write($args, $assoc_args, $set) {
        if (WP_CLI\Utils\get_flag_value($assoc_args, 'all')) {
            $args = array_keys(array_intersect_key(SEOProStack_Presets::all(), $this->installed()));
        }
        if (!$args) {
            WP_CLI::error('Name a plugin, or use --all.');
        }
        $only = null;
        if ('options' === $set && !empty($assoc_args['only'])) {
            $only = array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['only']))));
        }
        foreach ($args as $slug) {
            $result = SEOProStack_Presets::write($slug, $set, $only);
            if (is_wp_error($result)) {
                WP_CLI::warning($slug . ': ' . $result->get_error_message());
                continue;
            }
            WP_CLI::success(sprintf('%s: %d %s changed.', $slug, $result, 1 === (int) $result ? 'setting' : 'settings'));
        }
    }

    /**
     * A plugin folder that has a preset, or stop.
     *
     * @param string $slug Plugin folder.
     * @return string
     */
    private function preset_slug($slug) {
        if (!SEOProStack_Presets::get($slug)) {
            WP_CLI::error(sprintf('There is no preset for %s. See wp seoprostack presets list.', $slug));
        }
        return $slug;
    }

    /**
     * Installed plugins by folder.
     *
     * @param bool $details Name and version instead of the version only.
     * @return array
     */
    private function installed($details = false) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $out = array();
        foreach (get_plugins() as $file => $data) {
            $slug       = SEOProStack_Presets::slug_of($file);
            $out[$slug] = $details ? array('name' => (string) $data['Name'], 'version' => (string) $data['Version']) : (string) $data['Version'];
        }
        return $out;
    }

    /**
     * A value as short text.
     *
     * @param mixed $value Value.
     * @return string
     */
    private function show($value) {
        if (null === $value) {
            return '(not set)';
        }
        return is_scalar($value) ? var_export($value, true) : (string) wp_json_encode($value); // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- command-line output.
    }
}
