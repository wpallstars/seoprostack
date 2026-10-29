<?php
/**
 * Kadence author box fields.
 *
 * The Kadence theme's author box, under posts, shows the author's
 * occupation and links to their profiles elsewhere from user meta
 * (`occupation`, `facebook`, `twitter`, `linkedin` and the rest, read in
 * template-parts/content/entry_author.php). The theme has no fields for
 * them: Kadence Starter Templates adds them to the profile screen
 * (KadenceWP\KadenceStarterTemplates\Author_Meta). It is a set-up plugin,
 * often deactivated once a site is built, and then the author box keeps
 * showing values that nobody can see or change.
 *
 * While the Kadence theme (or a child theme of it) is active and Starter
 * Templates' fields are not on the screen, the profile and edit user
 * screens get the same fields, under the same meta keys, for people who
 * can write posts. A field for a key that is already a contact method
 * (Rank Math adds Facebook and X, as `facebook` and `twitter`) is left to
 * the screen's Contact Info section, as Starter Templates leaves those two
 * to Rank Math. Fields are saved only when this form carried them (its
 * nonce), so a save where Starter Templates drew the fields never clears
 * them; an empty field deletes its meta.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.104
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Author_Fields extends SEOProStack_Feature {

    const KEY = 'kadence_author_fields';

    /** Nonce action and field. */
    const NONCE = 'seoprostack_author_fields';

    /** Starter Templates' class that draws the same fields. */
    const STARTER_CLASS = 'KadenceWP\\KadenceStarterTemplates\\Author_Meta';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // Safe on any site: fields for values the theme already shows.
                'default'     => true,
                'tab'         => 'admin',
                'label'       => __('Kadence author box fields', 'seoprostack'),
                'description' => __('With the Kadence theme, the profile screen keeps the Occupation and profile link fields that its author box shows under posts, after Kadence Starter Templates (which adds them) is deactivated. Nothing changes with another theme, or while Starter Templates shows them.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!is_admin() || !self::enabled()) {
            return;
        }
        add_action('show_user_profile', array(__CLASS__, 'render'), 20);
        add_action('edit_user_profile', array(__CLASS__, 'render'), 20);
        add_action('personal_options_update', array(__CLASS__, 'save'));
        add_action('edit_user_profile_update', array(__CLASS__, 'save'));
    }

    /**
     * Whether the Kadence theme, or a child theme of it, is active.
     *
     * @return bool
     */
    private static function kadence_theme() {
        return 'kadence' === get_template();
    }

    /**
     * The fields the author box reads, in its order, without those that
     * are contact methods already.
     *
     * @param WP_User $user Person whose profile it is.
     * @return array<string,string> meta key => label
     */
    private static function fields($user) {
        $fields = array(
            'occupation' => __('Occupation', 'seoprostack'),
            'facebook'   => 'Facebook',
            'twitter'    => 'X',
            'instagram'  => 'Instagram',
            'threads'    => 'Threads',
            'youtube'    => 'YouTube',
            'flickr'     => 'Flickr',
            'vimeo'      => 'Vimeo',
            'linkedin'   => 'LinkedIn',
            'pinterest'  => 'Pinterest',
            'dribbble'   => 'Dribbble',
            'amazon'     => 'Amazon',
            'medium'     => 'Medium',
            'goodreads'  => 'Goodreads',
            'bookbub'    => 'BookBub',
        );
        return array_diff_key($fields, wp_get_user_contact_methods($user));
    }

    /**
     * Whether the fields belong on this person's profile.
     *
     * @param WP_User $user Person whose profile it is.
     * @return bool
     */
    private static function applies($user) {
        return $user instanceof WP_User && self::kadence_theme()
            && user_can($user, 'edit_posts') && current_user_can('edit_user', $user->ID);
    }

    /**
     * Draw the fields, unless Starter Templates draws them.
     *
     * @param WP_User $user Person whose profile it is.
     */
    public static function render($user) {
        if (!self::applies($user) || class_exists(self::STARTER_CLASS, false)) {
            return;
        }
        wp_nonce_field(self::NONCE, self::NONCE);
        ?>
        <h2><?php esc_html_e('Author box', 'seoprostack'); ?></h2>
        <p class="description"><?php esc_html_e('Shown with the biography in the author box under posts. Leave a field empty to leave it out.', 'seoprostack'); ?></p>
        <table class="form-table" role="presentation">
            <?php foreach (self::fields($user) as $key => $label) : ?>
                <?php $id = 'seoprostack-author-' . $key; ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label></th>
                    <td>
                        <?php if ('occupation' === $key) : ?>
                            <input type="text" name="seoprostack_author[occupation]" id="<?php echo esc_attr($id); ?>" class="regular-text" value="<?php echo esc_attr((string) get_user_meta($user->ID, $key, true)); ?>" />
                            <p class="description"><?php esc_html_e('A job title or role, shown under the name.', 'seoprostack'); ?></p>
                        <?php else : ?>
                            <input type="url" name="seoprostack_author[<?php echo esc_attr($key); ?>]" id="<?php echo esc_attr($id); ?>" class="regular-text code" placeholder="https://" value="<?php echo esc_attr((string) get_user_meta($user->ID, $key, true)); ?>" />
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    /**
     * Save the fields this form carried.
     *
     * @param int $user_id Person whose profile it is.
     */
    public static function save($user_id) {
        $nonce = isset($_POST[self::NONCE]) ? sanitize_key(wp_unslash($_POST[self::NONCE])) : '';
        if ('' === $nonce || !wp_verify_nonce($nonce, self::NONCE)) {
            return;
        }
        $user = get_userdata((int) $user_id);
        if (!$user || !self::applies($user)) {
            return;
        }
        $posted = isset($_POST['seoprostack_author']) && is_array($_POST['seoprostack_author'])
            ? wp_unslash($_POST['seoprostack_author']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised below.
            : array();
        foreach (array_keys(self::fields($user)) as $key) {
            if (!isset($posted[$key]) || !is_string($posted[$key])) {
                continue;
            }
            $value = 'occupation' === $key ? sanitize_text_field($posted[$key]) : esc_url_raw(trim($posted[$key]));
            if ('' === $value) {
                delete_user_meta($user->ID, $key);
            } else {
                update_user_meta($user->ID, $key, $value);
            }
        }
    }
}
