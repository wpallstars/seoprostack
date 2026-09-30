<?php
/**
 * Base class for SEO Pro Stack features.
 *
 * A feature is a self-contained class that:
 * - declares its settings in settings() (the on/off switch first, keyed by KEY,
 *   then any options with 'parent' => KEY);
 * - registers its hooks in boot(), which runs on `init` (priority 0, before
 *   widgets_init) for every registered feature. Disabled features should
 *   return early so they cost nothing;
 * - optionally imports settings from the plugin it replaces in migrate().
 *
 * Register extra features with the `seoprostack_features` filter.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class SEOProStack_Feature {

    /** Setting key of the feature's on/off switch. */
    const KEY = '';

    /**
     * Settings schema entries for this feature.
     *
     * @return array<string,array>
     */
    public static function settings() {
        return array();
    }

    /**
     * Whether the feature is switched on.
     *
     * @return bool
     */
    public static function enabled() {
        return '' !== static::KEY && (bool) SEOProStack_Settings::get(static::KEY);
    }

    /**
     * Import settings once, when the stored settings version is older than
     * SEOProStack_Settings::DB_VERSION. Only fill keys that are not stored yet.
     *
     * @param array $options      Stored settings (raw, without defaults).
     * @param int   $from_version Stored settings version before this upgrade.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        return $options;
    }

    /**
     * Set a setting during migrate() unless it is already stored.
     * (Named import_setting() so features may keep their own import() methods.)
     *
     * @param array  $options Stored settings.
     * @param string $key     Setting key.
     * @param mixed  $value   Raw value; null skips.
     * @return array
     */
    protected static function import_setting(array $options, $key, $value) {
        $schema = SEOProStack_Settings::schema();
        if (null !== $value && !array_key_exists($key, $options) && isset($schema[$key])) {
            $options[$key] = SEOProStack_Settings::sanitize_value($value, $schema[$key]);
        }
        return $options;
    }

    /**
     * Role options for multi settings.
     *
     * @return array<string,string>
     */
    public static function role_options() {
        $roles = array();
        foreach (wp_roles()->get_names() as $role => $name) {
            $roles[$role] = translate_user_role($name);
        }
        return $roles;
    }

    /**
     * Whether the current user has one of the roles. Users who can manage
     * options are never matched, so admins cannot lock themselves out.
     *
     * @param mixed $roles Role slugs.
     * @return bool
     */
    public static function current_user_in_roles($roles) {
        $user = wp_get_current_user();
        if (!$user->exists() || user_can($user, 'manage_options')) {
            return false;
        }
        return (bool) array_intersect((array) $user->roles, (array) $roles);
    }

    /**
     * Register hooks.
     */
    abstract public static function boot();
}
