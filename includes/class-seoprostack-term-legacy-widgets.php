<?php
/**
 * TaxoPress' widgets, kept after TaxoPress is deactivated
 * (SEOProStack_Term_Legacy), with the same IDs so widget areas keep them
 * and their saved settings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.11.11
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Terms Display, Terms for Current Post and Related Posts widgets: each
 * shows a display saved in TaxoPress.
 */
class SEOProStack_Taxopress_Display_Widget extends WP_Widget {

    /** Widget ID base => [display type, instance key, name]. */
    const TYPES = array(
        'simpletags-shortcode'    => array('cloud', 'tagcloud_id', 'Terms Display (TaxoPress)'),
        'simpletags-posttags'     => array('post', 'posttags_id', 'Terms for Current Post (TaxoPress)'),
        'simpletags-relatedposts' => array('related', 'relatedposts_id', 'Related Posts (TaxoPress)'),
    );

    /** @var array Display type, instance key, name. */
    private $type;

    /**
     * @param string $id_base Widget ID base, a key of TYPES.
     */
    public function __construct($id_base = 'simpletags-shortcode') {
        $this->type = isset(self::TYPES[$id_base]) ? self::TYPES[$id_base] : self::TYPES['simpletags-shortcode'];
        parent::__construct($id_base, $this->type[2], array(
            'classname'   => 'widget-' . $id_base,
            'description' => __('Kept from TaxoPress by SEO Pro Stack. Use a Term list or Related posts block for new ones.', 'seoprostack'),
        ));
    }

    /**
     * @param array $args     Sidebar arguments.
     * @param array $instance Saved settings.
     */
    public function widget($args, $instance) {
        $id   = isset($instance[$this->type[1]]) ? (string) $instance[$this->type[1]] : '';
        $html = SEOProStack_Term_Legacy::taxopress_display($this->type[0], $id, array());
        if ('' === $html) {
            return;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup, and HTML escaped while built.
        echo $args['before_widget'] . $html . $args['after_widget'];
    }

    /**
     * @param array $instance Saved settings.
     * @return string
     */
    public function form($instance) {
        echo '<p>' . esc_html__('This widget shows a display saved in TaxoPress. Its settings return when TaxoPress is active again.', 'seoprostack') . '</p>';
        return 'noform';
    }

    /**
     * Keep the saved settings.
     *
     * @param array $new_instance New settings.
     * @param array $old_instance Saved settings.
     * @return array
     */
    public function update($new_instance, $old_instance) {
        return (array) $old_instance;
    }
}

/**
 * TaxoPress' older Tag Cloud widget, whose settings are in the widget.
 */
class SEOProStack_Taxopress_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct('simpletags', 'Tag Cloud (TaxoPress)', array(
            'classname'   => 'widget_simpletags',
            'description' => __('Kept from TaxoPress by SEO Pro Stack. Use a Term list block for new ones.', 'seoprostack'),
        ));
    }

    /**
     * @param array $args     Sidebar arguments.
     * @param array $instance Saved settings.
     */
    public function widget($args, $instance) {
        $i      = (array) $instance;
        $config = array(
            'taxonomy'   => isset($i['taxonomy']) ? $i['taxonomy'] : 'post_tag',
            'max'        => isset($i['max']) ? $i['max'] : 45,
            'orderby'    => isset($i['orderby']) ? $i['orderby'] : 'name',
            'smallest'   => isset($i['smini']) ? $i['smini'] : 8,
            'largest'    => isset($i['smax']) ? $i['smax'] : 22,
            'unit'       => isset($i['unit']) ? $i['unit'] : 'pt',
            'format'     => isset($i['format']) ? $i['format'] : 'flat',
            'hide_title' => 1,
        );
        $html = SEOProStack_Term_Legacy::taxopress_render('cloud', $config);
        if ('' === $html) {
            return;
        }
        $title = isset($i['title']) ? apply_filters('widget_title', (string) $i['title'], $i, $this->id_base) : '';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup, and HTML escaped while built.
        echo $args['before_widget'] . ('' !== $title ? $args['before_title'] . esc_html($title) . $args['after_title'] : '') . $html . $args['after_widget'];
    }

    /**
     * @param array $instance Saved settings.
     * @return string
     */
    public function form($instance) {
        echo '<p>' . esc_html__('This tag cloud keeps the settings saved in TaxoPress. They can be changed again when TaxoPress is active.', 'seoprostack') . '</p>';
        return 'noform';
    }

    /**
     * Keep the saved settings.
     *
     * @param array $new_instance New settings.
     * @param array $old_instance Saved settings.
     * @return array
     */
    public function update($new_instance, $old_instance) {
        return (array) $old_instance;
    }
}
