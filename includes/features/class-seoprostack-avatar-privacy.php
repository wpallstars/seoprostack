<?php
/**
 * Avatars without Gravatar.
 *
 * Every avatar is served from this site, so visitors' browsers never contact
 * Gravatar and hashes of email addresses are not published:
 * - people with a profile picture uploaded on their profile screen get it,
 *   cropped to a square and stored in a few sizes;
 * - everyone else gets the default chosen under Settings → Discussion,
 *   drawn locally: a silhouette, a pattern that differs per person, or blank.
 *
 * Avatars set by other plugins (anything not from Gravatar) are kept.
 * Everything goes through core's `pre_get_avatar_data`, so get_avatar(),
 * the admin bar, comment and user lists, the REST API (avatar_urls) and the
 * block editor all follow.
 *
 * Replaces "Avatar Privacy". Its uploaded profile pictures are copied (its
 * uninstaller deletes them), and its generated default styles map to the
 * pattern. It lets people opt in to Gravatar; this feature never uses it.
 * While Avatar Privacy is loaded it keeps handling avatars.
 *
 * Files: `uploads/seoprostack-avatars/` (on multisite, profile pictures live
 * in the main site's uploads because users are shared by every site).
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Avatar_Privacy extends SEOProStack_Feature {

    const KEY = 'avatar_privacy';

    /** User meta: array('files' => array(size => file), 'type' => mime, 'from' => Avatar Privacy file). */
    const META = 'seoprostack_avatar';

    /** Folder in uploads. */
    const DIR = 'seoprostack-avatars';

    /** Stored picture sizes in pixels, smallest first. */
    const SIZES = array(64, 128, 256, 512);

    const AP_FILE = 'avatar-privacy/avatar-privacy.php';
    const AP_META = 'avatar_privacy_user_avatar';

    /** Pictures imported per request by the bulk import. */
    const IMPORT_LIMIT = 25;

    /** Default styles (core and Avatar Privacy) drawn as a per-person pattern. */
    const GENERATED = array('identicon', 'wavatar', 'monsterid', 'retro', 'robohash', 'bird', 'cat', 'rings');

    /** @var string|null Upload error to report on the profile screen. */
    private static $upload_error = null;

    /** @var array<string,bool> Generated files known to exist this request. */
    private static $written = array();

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'admin',
                'label'       => __('Avatars without Gravatar', 'seoprostack'),
                'description' => __('Show avatars from this site instead of Gravatar, so visitors’ browsers never contact Gravatar and email hashes are not published. People without a picture get a silhouette, a pattern or nothing, as chosen under Settings → Discussion. While Avatar Privacy is active, it keeps handling avatars.', 'seoprostack'),
                'replaces'    => array('avatar-privacy' => 'Avatar Privacy'),
            ),
            'avatar_privacy_uploads' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Profile pictures', 'seoprostack'),
                'description' => __('People can upload a picture on their profile screen. It is cropped to a square and stored on this site.', 'seoprostack'),
            ),
        );
    }

    /**
     * Switch on where Avatar Privacy is active, and copy its profile pictures.
     * Its settings need no import: it has no Gravatar-free equivalent of its
     * opt-in, and its default styles are read from core's option.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $active = in_array(self::AP_FILE, (array) get_option('active_plugins', array()), true);
        if (!$active && is_multisite()) {
            $active = array_key_exists(self::AP_FILE, (array) get_site_option('active_sitewide_plugins', array()));
        }
        if ($active) {
            $options = self::import_setting($options, self::KEY, true);
        }
        if (!empty($options[self::KEY])) {
            self::import_pictures();
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }

        add_action('deactivated_plugin', array(__CLASS__, 'plugin_deactivated'));
        add_action(is_multisite() ? 'wpmu_delete_user' : 'delete_user', array(__CLASS__, 'delete_picture'));

        // Avatar Privacy keeps handling avatars until it is deactivated.
        if (defined('AVATAR_PRIVACY_PLUGIN_FILE')) {
            return;
        }

        add_filter('pre_get_avatar_data', array(__CLASS__, 'avatar_data'), 10000, 2);
        add_filter('option_avatar_default', array(__CLASS__, 'map_default'));
        add_filter('avatar_defaults', array(__CLASS__, 'avatar_defaults'), 10000);

        if (!is_admin()) {
            return;
        }
        add_filter('user_profile_picture_description', array(__CLASS__, 'profile_field'), 10, 2);
        if (SEOProStack_Settings::get('avatar_privacy_uploads')) {
            add_action('user_edit_form_tag', array(__CLASS__, 'form_tag'));
            add_action('personal_options_update', array(__CLASS__, 'save_profile'));
            add_action('edit_user_profile_update', array(__CLASS__, 'save_profile'));
            add_action('user_profile_update_errors', array(__CLASS__, 'profile_errors'));
        }
    }

    /* ------------------------------------------------------------------
     * Avatars
     * ------------------------------------------------------------------ */

    /**
     * Serve the avatar from this site.
     *
     * @param array $args        Avatar arguments.
     * @param mixed $id_or_email User ID, email, WP_User, WP_Post or WP_Comment.
     * @return array
     */
    public static function avatar_data($args, $id_or_email) {
        // Keep avatars from other plugins, unless they point at Gravatar.
        if (!empty($args['url']) && !self::is_gravatar($args['url'])) {
            return $args;
        }

        // Core shows no avatar for pingbacks and other comment types.
        if ($id_or_email instanceof WP_Comment && !is_avatar_comment_type(get_comment_type($id_or_email))) {
            $args['url']          = false;
            $args['found_avatar'] = false;
            return $args;
        }

        list($user_id, $identity) = self::identify($id_or_email);
        $size = isset($args['size']) ? max(1, (int) $args['size']) : 96;
        $url  = '';

        if ($user_id && empty($args['force_default'])) {
            $url = self::picture_url($user_id, $size);
        }
        if ('' === $url) {
            $url = self::default_url(isset($args['default']) ? $args['default'] : '', $identity);
        }

        $args['url']          = $url;
        $args['found_avatar'] = '' !== $identity;
        return $args;
    }

    /**
     * Whether a URL points at Gravatar.
     *
     * @param mixed $url URL.
     * @return bool
     */
    private static function is_gravatar($url) {
        $host = wp_parse_url((string) $url, PHP_URL_HOST);
        return is_string($host) && (bool) preg_match('/(^|\.)gravatar\.com$/i', $host);
    }

    /**
     * The user (for uploaded pictures) and a stable identity (for patterns).
     *
     * Email addresses and guest comments are never matched to accounts, so a
     * guest cannot show someone else's picture by using their address.
     *
     * @param mixed $id_or_email Avatar subject.
     * @return array{0:int,1:string} User ID (or 0) and identity (or '').
     */
    private static function identify($id_or_email) {
        $user = null;
        $key  = '';

        if (is_numeric($id_or_email)) {
            $user = get_user_by('id', absint($id_or_email));
        } elseif ($id_or_email instanceof WP_User) {
            $user = $id_or_email;
        } elseif ($id_or_email instanceof WP_Post) {
            $user = get_user_by('id', (int) $id_or_email->post_author);
        } elseif ($id_or_email instanceof WP_Comment) {
            if (!empty($id_or_email->user_id)) {
                $user = get_user_by('id', (int) $id_or_email->user_id);
            }
            if (!$user) {
                $key = '' !== trim((string) $id_or_email->comment_author_email)
                    ? (string) $id_or_email->comment_author_email
                    : 'name:' . (string) $id_or_email->comment_author;
            }
        } elseif (is_string($id_or_email)) {
            $key = $id_or_email;
        }

        if ($user instanceof WP_User && $user->exists()) {
            return array((int) $user->ID, (string) $user->user_email);
        }
        $key = strtolower(trim($key));
        return array(0, 'name:' === $key ? '' : $key);
    }

    /**
     * Keep the Discussion setting on a style this feature can draw, so the
     * Discussion screen shows the one in use. The stored option is unchanged.
     *
     * @param mixed $value Stored default avatar.
     * @return mixed
     */
    public static function map_default($value) {
        $style = self::style($value);
        if ('url' === $style) {
            return $value;
        }
        $keys = array(
            'blank'      => 'blank',
            'pattern'    => 'identicon',
            'silhouette' => 'mystery',
        );
        return $keys[$style];
    }

    /**
     * The default styles offered under Settings → Discussion.
     *
     * @return array<string,string>
     */
    public static function avatar_defaults() {
        return array(
            'mystery'   => __('Silhouette', 'seoprostack'),
            'identicon' => __('Pattern (different for each person)', 'seoprostack'),
            'blank'     => __('Blank', 'seoprostack'),
        );
    }

    /**
     * Style for a default avatar value.
     *
     * @param mixed $default Core or Avatar Privacy default, or an image URL.
     * @return string blank, pattern, silhouette or url.
     */
    private static function style($default) {
        $default = is_string($default) ? $default : '';
        if (preg_match('#^https?://#i', $default)) {
            return self::is_gravatar($default) ? 'silhouette' : 'url';
        }
        if (in_array($default, array('blank', '404'), true)) {
            return 'blank';
        }
        return in_array($default, self::GENERATED, true) ? 'pattern' : 'silhouette';
    }

    /**
     * URL of the default avatar, drawn on this site.
     *
     * @param mixed  $default  Default style or image URL.
     * @param string $identity Email address or name key.
     * @return string
     */
    private static function default_url($default, $identity) {
        $style = self::style($default);
        if ('url' === $style) {
            return (string) $default;
        }
        if ('blank' === $style) {
            return includes_url('images/blank.gif');
        }
        if ('pattern' === $style && '' !== $identity) {
            $hash = hash_hmac('sha256', $identity, wp_salt('auth'));
            $url  = self::generated('p1-' . substr($hash, 0, 20) . '.svg', self::pattern_svg($hash));
        } else {
            $url = self::generated('silhouette.svg', self::silhouette_svg());
        }
        return '' !== $url ? $url : includes_url('images/blank.gif');
    }

    /**
     * Write a generated image once and return its URL ('' if it cannot be
     * written). File names never change, so cached pages keep working.
     *
     * @param string $name File name.
     * @param string $svg  Contents.
     * @return string
     */
    private static function generated($name, $svg) {
        $base = self::site_base();
        if (null === $base) {
            return '';
        }
        $path = $base['dir'] . '/' . $name;
        if (!isset(self::$written[$path]) && !file_exists($path)) {
            if (!wp_mkdir_p($base['dir']) || !self::fs()->put_contents($path, $svg, 0644)) {
                return '';
            }
        }
        self::$written[$path] = true;
        return $base['url'] . '/' . rawurlencode($name);
    }

    /**
     * A symmetric 5 × 5 pattern in a colour taken from the hash.
     *
     * @param string $hash Hex hash.
     * @return string
     */
    private static function pattern_svg($hash) {
        $hue  = hexdec(substr($hash, 0, 3)) % 360;
        $bits = hexdec(substr($hash, 3, 4));
        $path = '';
        for ($i = 0; $i < 15; $i++) {
            if (!(($bits >> $i) & 1)) {
                continue;
            }
            $col = intdiv($i, 5);
            $row = $i % 5;
            foreach (array_unique(array($col, 4 - $col)) as $x) {
                $path .= 'M' . ($x + 1) . ' ' . ($row + 1) . 'h1v1h-1z';
            }
        }
        if ('' === $path) {
            $path = 'M3 1h1v5h-1z';
        }
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 7 7" shape-rendering="crispEdges"><rect width="7" height="7" fill="%1$s"/><path fill="%2$s" d="%3$s"/></svg>',
            self::hsl_hex($hue, 0.45, 0.92),
            self::hsl_hex($hue, 0.55, 0.42),
            $path
        );
    }

    /**
     * A head-and-shoulders silhouette.
     *
     * @return string
     */
    private static function silhouette_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#c3c4c7"/><circle cx="32" cy="25" r="12" fill="#f6f7f7"/><path d="M10 64c0-13 10-22 22-22s22 9 22 22z" fill="#f6f7f7"/></svg>';
    }

    /**
     * HSL to a #rrggbb colour.
     *
     * @param int   $h Hue, 0-359.
     * @param float $s Saturation, 0-1.
     * @param float $l Lightness, 0-1.
     * @return string
     */
    private static function hsl_hex($h, $s, $l) {
        $c   = (1 - abs(2 * $l - 1)) * $s;
        $x   = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m   = $l - $c / 2;
        $rgb = array(array($c, $x, 0), array($x, $c, 0), array(0, $c, $x), array(0, $x, $c), array($x, 0, $c), array($c, 0, $x));
        $rgb = $rgb[intdiv($h, 60) % 6];
        return sprintf('#%02x%02x%02x', round(($rgb[0] + $m) * 255), round(($rgb[1] + $m) * 255), round(($rgb[2] + $m) * 255));
    }

    /* ------------------------------------------------------------------
     * Profile pictures
     * ------------------------------------------------------------------ */

    /**
     * URL of a user's picture at the smallest stored size that covers $size.
     *
     * @param int $user_id User ID.
     * @param int $size    Requested size in pixels.
     * @return string '' if the user has no picture.
     */
    private static function picture_url($user_id, $size) {
        $picture = self::picture($user_id);
        $base    = self::users_base();
        if (!$picture || null === $base) {
            return '';
        }
        $files = $picture['files'];
        ksort($files);
        $file = end($files);
        foreach ($files as $stored => $name) {
            if ((int) $stored >= $size) {
                $file = $name;
                break;
            }
        }
        return $base['url'] . '/' . rawurlencode(basename($file));
    }

    /**
     * A user's stored picture, importing Avatar Privacy's if it is newer.
     *
     * @param int $user_id User ID.
     * @return array|null
     */
    private static function picture($user_id) {
        $meta = get_user_meta($user_id, self::META, true);
        if (self::needs_import($user_id, $meta)) {
            $meta = self::import_user($user_id);
        }
        return (is_array($meta) && !empty($meta['files']) && is_array($meta['files'])) ? $meta : null;
    }

    /**
     * Whether Avatar Privacy holds a picture we have not copied: the user has
     * none of ours yet, or ours was copied from a different file of theirs.
     * Pictures uploaded or removed here are never replaced.
     *
     * @param int   $user_id User ID.
     * @param mixed $meta    Our meta.
     * @return bool
     */
    private static function needs_import($user_id, $meta) {
        if (is_array($meta) && !isset($meta['from'])) {
            return false;
        }
        if (is_array($meta) && isset($meta['retry']) && time() < (int) $meta['retry']) {
            return false;
        }
        $theirs = get_user_meta($user_id, self::AP_META, true);
        if (!is_array($theirs) || empty($theirs['file']) || !is_string($theirs['file'])) {
            return false;
        }
        return !is_array($meta) || basename($theirs['file']) !== $meta['from'];
    }

    /**
     * Copy a user's Avatar Privacy picture.
     *
     * @param int $user_id User ID.
     * @return array|string Our meta, or '' if there was nothing to copy.
     */
    private static function import_user($user_id) {
        $theirs = get_user_meta($user_id, self::AP_META, true);
        if (!is_array($theirs) || empty($theirs['file']) || !is_string($theirs['file'])) {
            return '';
        }
        $before = get_user_meta($user_id, self::META, true);
        $from   = basename($theirs['file']);
        $source = self::ap_file($theirs['file']);
        $stored = $source ? self::store_picture($user_id, $source) : null;
        $keep   = false;

        if (null === $source) {
            // Missing file: record it, so it is not looked for on every page.
            $meta = array('files' => array(), 'from' => $from);
        } elseif (is_wp_error($stored)) {
            // Could not process it: keep any earlier copy and try again tomorrow.
            $meta          = is_array($before) ? $before : array('files' => array(), 'from' => '');
            $meta['retry'] = time() + DAY_IN_SECONDS;
            $stored        = null;
            $keep          = true;
        } else {
            $meta         = $stored;
            $meta['from'] = $from;
        }

        // A picture uploaded or removed while this was copying wins.
        wp_cache_delete($user_id, 'user_meta');
        $current = get_user_meta($user_id, self::META, true);
        if ($current !== $before) {
            self::remove_files($stored);
            return $current;
        }

        if (!$keep) {
            self::remove_files($before);
        }
        update_user_meta($user_id, self::META, $meta);
        return $meta;
    }

    /**
     * Locate an Avatar Privacy file. It stores absolute paths, which break
     * when a site moves, so fall back to its folder in the main uploads.
     *
     * @param string $path Stored path.
     * @return string|null
     */
    private static function ap_file($path) {
        if (is_file($path)) {
            return $path;
        }
        $uploads = self::main_uploads();
        $guess   = $uploads['basedir'] . '/avatar-privacy/user-avatar/' . basename($path);
        return is_file($guess) ? $guess : null;
    }

    /**
     * Copy up to IMPORT_LIMIT Avatar Privacy pictures that are not copied
     * yet. The rest are copied when first shown.
     */
    public static function import_pictures() {
        $ids = get_users(array(
            'blog_id'    => 0,
            'fields'     => 'ID',
            'number'     => self::IMPORT_LIMIT,
            'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off import, bounded.
                'relation' => 'AND',
                array('key' => self::AP_META, 'compare' => 'EXISTS'),
                array('key' => self::META, 'compare' => 'NOT EXISTS'),
            ),
        ));
        foreach ($ids as $user_id) {
            self::import_user((int) $user_id);
        }
    }

    /**
     * Copy pictures when Avatar Privacy is deactivated, before it can be
     * deleted (its uninstaller deletes them).
     *
     * @param string $plugin Plugin file.
     */
    public static function plugin_deactivated($plugin) {
        if (self::AP_FILE === $plugin) {
            self::import_pictures();
        }
    }

    /**
     * Crop an image to a square and store it in each size.
     *
     * @param int    $user_id User ID.
     * @param string $source  Image path.
     * @return array|WP_Error Our meta.
     */
    private static function store_picture($user_id, $source) {
        $base = self::users_base();
        if (null === $base || !wp_mkdir_p($base['dir'])) {
            return new WP_Error('seoprostack_avatar_dir', __('The uploads folder is not writable.', 'seoprostack'));
        }
        $index = $base['dir'] . '/index.php';
        if (!file_exists($index)) {
            self::fs()->put_contents($index, "<?php\n// Silence is golden.\n", 0644);
        }

        $editor = wp_get_image_editor($source);
        if (is_wp_error($editor)) {
            return $editor;
        }
        $dims = $editor->get_size();
        $side = (int) min($dims['width'], $dims['height']);
        if ($side < 1) {
            return new WP_Error('seoprostack_avatar_size', __('The picture could not be read.', 'seoprostack'));
        }

        // JPEG and WebP stay as they are; PNG, GIF and others become PNG
        // (animations are not kept).
        $mime = (string) wp_get_image_mime($source);
        if (!in_array($mime, array('image/jpeg', 'image/webp'), true) || !$editor->supports_mime_type($mime)) {
            $mime = 'image/png';
        }
        $ext = array(
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/png'  => 'png',
        );

        $master = min($side, max(self::SIZES));
        // Resizing through crop() also strips photo metadata such as location.
        $cropped = $editor->crop((int) (($dims['width'] - $side) / 2), (int) (($dims['height'] - $side) / 2), $side, $side, $master, $master);
        if (is_wp_error($cropped)) {
            return $cropped;
        }

        $token = strtolower(wp_generate_password(10, false));
        $files = array();
        foreach (array_reverse(self::SIZES) as $size) {
            if ($size > $master && !empty($files)) {
                continue;
            }
            $target = min($size, $master);
            $now    = $editor->get_size();
            if ($target < $now['width']) {
                $resized = $editor->resize($target, $target, true);
                if (is_wp_error($resized)) {
                    break;
                }
            }
            $name  = sprintf('%d-%s-%d.%s', $user_id, $token, $target, $ext[$mime]);
            $saved = $editor->save($base['dir'] . '/' . $name, $mime);
            if (is_wp_error($saved)) {
                self::remove_files(array('files' => $files));
                return $saved;
            }
            $files[$target] = $name;
        }
        ksort($files);

        return array(
            'files' => $files,
            'type'  => $mime,
        );
    }

    /**
     * Delete a picture's files.
     *
     * @param mixed $meta Our meta.
     */
    private static function remove_files($meta) {
        $base = self::users_base();
        if (!is_array($meta) || empty($meta['files']) || !is_array($meta['files']) || null === $base) {
            return;
        }
        foreach ($meta['files'] as $name) {
            $path = $base['dir'] . '/' . basename((string) $name);
            if (is_file($path)) {
                wp_delete_file($path);
            }
        }
    }

    /**
     * Delete a user's picture when the user is deleted.
     *
     * @param int $user_id User ID.
     */
    public static function delete_picture($user_id) {
        self::remove_files(get_user_meta((int) $user_id, self::META, true));
    }

    /* ------------------------------------------------------------------
     * Profile screen
     * ------------------------------------------------------------------ */

    /**
     * Allow file uploads in the profile form.
     */
    public static function form_tag() {
        echo ' enctype="multipart/form-data"';
    }

    /**
     * Replace "change your profile picture on Gravatar" with the upload field.
     *
     * @param string       $description Core description.
     * @param WP_User|null $user        Profile user.
     * @return string
     */
    public static function profile_field($description, $user = null) {
        if (!SEOProStack_Settings::get('avatar_privacy_uploads') || !$user instanceof WP_User || !current_user_can('edit_user', $user->ID)) {
            return '';
        }

        $html  = '<label for="seoprostack-avatar">' . esc_html__('Upload a picture:', 'seoprostack') . '</label> ';
        $html .= '<input type="file" id="seoprostack-avatar" name="seoprostack_avatar" accept="image/jpeg,image/png,image/gif,image/webp" /><br />';
        $html .= esc_html__('JPEG, PNG, GIF or WebP. It is cropped to a square and stored on this site, not on Gravatar.', 'seoprostack');
        if (self::picture($user->ID)) {
            $html .= '<br /><label><input type="checkbox" name="seoprostack_avatar_remove" value="1" /> ' . esc_html__('Remove this picture', 'seoprostack') . '</label>';
        }
        return $html;
    }

    /**
     * Save an uploaded or removed picture from the profile screen. Core has
     * checked the form's nonce and the edit_user capability before this runs.
     *
     * @param int $user_id User ID.
     */
    public static function save_profile($user_id) {
        $user_id = (int) $user_id;
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }
        check_admin_referer('update-user_' . $user_id);

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- file upload array; validated below.
        $file = isset($_FILES['seoprostack_avatar']) && is_array($_FILES['seoprostack_avatar']) ? $_FILES['seoprostack_avatar'] : array();

        if (!empty($file['name']) && isset($file['error'], $file['tmp_name'])) {
            $stored = self::handle_upload($user_id, $file);
            if (is_wp_error($stored)) {
                self::$upload_error = $stored->get_error_message();
                return;
            }
            self::remove_files(get_user_meta($user_id, self::META, true));
            update_user_meta($user_id, self::META, $stored);
            return;
        }

        if (!empty($_POST['seoprostack_avatar_remove'])) {
            self::remove_files(get_user_meta($user_id, self::META, true));
            // An empty record, so Avatar Privacy's copy is not imported again.
            update_user_meta($user_id, self::META, array('files' => array()));
        }
    }

    /**
     * Validate and store an uploaded picture.
     *
     * @param int   $user_id User ID.
     * @param array $file    Entry from $_FILES.
     * @return array|WP_Error
     */
    private static function handle_upload($user_id, array $file) {
        if (UPLOAD_ERR_OK !== (int) $file['error']) {
            $message = in_array((int) $file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)
                ? __('The picture is larger than the upload limit.', 'seoprostack')
                : __('The picture could not be uploaded.', 'seoprostack');
            return new WP_Error('seoprostack_avatar_upload', $message);
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return new WP_Error('seoprostack_avatar_upload', __('The picture could not be uploaded.', 'seoprostack'));
        }
        $size = (int) filesize($tmp);
        if ($size > wp_max_upload_size()) {
            return new WP_Error('seoprostack_avatar_upload', __('The picture is larger than the upload limit.', 'seoprostack'));
        }
        // No picture is this small; checking it would log a PHP notice on older WordPress.
        if ($size < 12) {
            return new WP_Error('seoprostack_avatar_type', __('Choose a JPEG, PNG, GIF or WebP picture.', 'seoprostack'));
        }
        $check = wp_check_filetype_and_ext($tmp, sanitize_file_name((string) $file['name']), array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
        ));
        if (empty($check['type'])) {
            return new WP_Error('seoprostack_avatar_type', __('Choose a JPEG, PNG, GIF or WebP picture.', 'seoprostack'));
        }
        return self::store_picture($user_id, $tmp);
    }

    /**
     * Show an upload problem with the profile screen's other errors.
     *
     * @param WP_Error $errors Profile errors.
     */
    public static function profile_errors($errors) {
        if (null !== self::$upload_error && $errors instanceof WP_Error) {
            /* translators: %s: reason */
            $errors->add('seoprostack_avatar', sprintf(__('Profile picture not saved: %s', 'seoprostack'), self::$upload_error));
        }
    }

    /* ------------------------------------------------------------------
     * Paths
     * ------------------------------------------------------------------ */

    /**
     * Folder and URL for generated images on the current site.
     *
     * @return array{dir:string,url:string}|null
     */
    private static function site_base() {
        static $cache = array();
        $site = get_current_blog_id();
        if (!array_key_exists($site, $cache)) {
            $uploads      = wp_get_upload_dir();
            $cache[$site] = empty($uploads['error']) && !empty($uploads['basedir'])
                ? array('dir' => $uploads['basedir'] . '/' . self::DIR, 'url' => $uploads['baseurl'] . '/' . self::DIR)
                : null;
        }
        return $cache[$site];
    }

    /**
     * Folder and URL for profile pictures (the main site's uploads, since
     * users are shared by every site of a network).
     *
     * @return array{dir:string,url:string}|null
     */
    private static function users_base() {
        static $base = false;
        if (false === $base) {
            $uploads = self::main_uploads();
            $base    = empty($uploads['error']) && !empty($uploads['basedir'])
                ? array('dir' => $uploads['basedir'] . '/' . self::DIR . '/users', 'url' => $uploads['baseurl'] . '/' . self::DIR . '/users')
                : null;
        }
        return $base;
    }

    /**
     * The main site's upload folder.
     *
     * @return array
     */
    private static function main_uploads() {
        static $uploads = null;
        if (null === $uploads) {
            $switch = is_multisite() && !is_main_site();
            if ($switch) {
                switch_to_blog(get_main_site_id());
            }
            $uploads = wp_get_upload_dir();
            if ($switch) {
                restore_current_blog();
            }
        }
        return $uploads;
    }

    /**
     * Direct file access for writing generated images (no credentials are
     * needed inside the uploads folder).
     *
     * @return WP_Filesystem_Direct
     */
    private static function fs() {
        static $fs = null;
        if (null === $fs) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            $fs = new WP_Filesystem_Direct(null);
        }
        return $fs;
    }
}
