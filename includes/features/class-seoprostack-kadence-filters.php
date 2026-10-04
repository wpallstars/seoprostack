<?php
/**
 * Kadence query filters scroll to results.
 *
 * When a visitor changes a filter (checkbox, radio button or drop-down) in
 * a Kadence Blocks Pro query loop, the results reload in place. On a long
 * list the visitor is left partway down the page, below where the new
 * results start. This scrolls back to the top of the loop, less an offset
 * for sticky headers, and only when the loop's top is above the window.
 *
 * The script is under 1 KB, printed inline in the footer only on
 * pages where a kadence/query block was drawn: in the post, a Kadence
 * Element, a block template or a widget alike, since every one goes through
 * render_block. No jQuery, no file to download. With reduced motion set the
 * jump is instant.
 *
 * @package SEOProStack
 * @since 0.12.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Filters extends SEOProStack_Feature {

    const KEY = 'kadence_filter_scroll';

    /** Whether a query loop was drawn on this page. @var bool */
    private static $seen = false;

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
                'tab'         => 'content',
                'label'       => __('Kadence query filters scroll to results', 'seoprostack'),
                'description' => __('When a visitor changes a filter in a Kadence Blocks Pro query loop, scroll back up to the results instead of leaving them partway down the page. Only on pages with a query loop. Nothing changes if Kadence Blocks Pro is not active.', 'seoprostack'),
            ),
            'kadence_filter_scroll_offset' => array(
                'type'        => 'int',
                'default'     => 0,
                'min'         => 0,
                'max'         => 500,
                'unit'        => __('px', 'seoprostack'),
                'parent'      => self::KEY,
                'label'       => __('Space above the results', 'seoprostack'),
                'description' => __('Room to leave above the loop, such as the height of a sticky header. The admin bar is allowed for already.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin()) {
            return;
        }
        add_filter('render_block_kadence/query', array(__CLASS__, 'seen'));
        // After blocks that other footer hooks draw, such as Kadence Elements.
        add_action('wp_footer', array(__CLASS__, 'print_script'), 100);
    }

    /**
     * Note that a query loop was drawn.
     *
     * @param string $content Block HTML.
     * @return string Unchanged.
     */
    public static function seen($content) {
        self::$seen = true;
        return $content;
    }

    /**
     * Print the script on pages with a query loop.
     */
    public static function print_script() {
        if (!self::$seen || !defined('KBP_VERSION') || !function_exists('wp_print_inline_script_tag')) {
            return;
        }
        $offset = max(0, (int) SEOProStack_Settings::get('kadence_filter_scroll_offset'));
        wp_print_inline_script_tag(self::script($offset), array('id' => 'seoprostack-kadence-filters'));
    }

    /**
     * The script. Kadence draws each filter in a fieldset.kadence-filter-wrap
     * inside the loop (.wp-block-kadence-query, .kb-query). A filter outside a
     * loop scrolls to the page's only loop, or else to itself.
     *
     * @param int $offset Pixels to leave above the loop.
     * @return string
     */
    private static function script($offset) {
        return '(function(){var o=' . (int) $offset . ',q=".wp-block-kadence-query,.kb-query";'
            . 'function go(t){if(!t||!t.closest)return;var w=t.closest(".kadence-filter-wrap");if(!w)return;'
            . 'var l=w.closest(q);if(!l){var a=document.querySelectorAll(q);l=1===a.length?a[0]:w;}'
            . 'var r=document.documentElement,b=document.getElementById("wpadminbar"),'
            . 'y=l.getBoundingClientRect().top-o-(parseFloat(getComputedStyle(r).scrollPaddingTop)||0)'
            . '-(b&&"fixed"===getComputedStyle(b).position?b.offsetHeight:0);if(y>=0)return;y+=window.pageYOffset;'
            . 'if(window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches){'
            . 'var s=r.style.scrollBehavior;r.style.scrollBehavior="auto";window.scrollTo(0,y);r.style.scrollBehavior=s;}'
            . 'else{window.scrollTo({top:y,behavior:"smooth"});}}'
            . 'document.addEventListener("change",function(e){var t=e.target;'
            . 'if(t&&t.matches&&t.matches("input[type=checkbox],input[type=radio],select"))go(t);});'
            . '})();';
    }
}
