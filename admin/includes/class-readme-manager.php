<?php
/**
 * WP Allstars Read Me tab.
 *
 * Renders README.md with a small, escaping Markdown subset
 * (headings, lists, bold, italic, inline code and http(s) links).
 *
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Readme_Manager {

    /**
     * Render the tab.
     */
    public static function display_tab_content() {
        $readme = wp_allstars_get_readme_content();
        ?>
        <article class="wpa-card wpa-readme">
            <?php echo self::parse_markdown($readme['content']); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped text. ?>
        </article>
        <?php
    }

    /**
     * Convert Markdown to HTML. Input is escaped first; only the generated
     * tags and validated http(s) links are emitted.
     *
     * @param string $markdown Markdown.
     * @return string HTML.
     */
    public static function parse_markdown($markdown) {
        $markdown = str_replace('{WP_ALLSTARS_VERSION}', WP_ALLSTARS_VERSION, $markdown);
        $lines    = preg_split('/\r\n|\r|\n/', $markdown);
        $html     = '';
        $list     = '';

        $close_list = function () use (&$html, &$list) {
            if ($list) {
                $html .= '</' . $list . '>';
                $list  = '';
            }
        };

        foreach ($lines as $line) {
            $trim = trim($line);

            if ('' === $trim || '#' === $trim) {
                $close_list();
                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.+)$/', $trim, $m)) {
                $close_list();
                $level = min(4, strlen($m[1]) + 1); // h1 is reserved for the page title.
                $html .= sprintf('<h%1$d>%2$s</h%1$d>', $level, self::inline($m[2]));
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trim, $m) || preg_match('/^\d+\.\s+(.+)$/', $trim, $n)) {
                $type = isset($n[1]) ? 'ol' : 'ul';
                $text = isset($n[1]) ? $n[1] : $m[1];
                if ($list !== $type) {
                    $close_list();
                    $html .= '<' . $type . '>';
                    $list  = $type;
                }
                $html .= '<li>' . self::inline($text) . '</li>';
                unset($n);
                continue;
            }

            $close_list();
            $html .= '<p>' . self::inline($trim) . '</p>';
        }
        $close_list();

        return $html;
    }

    /**
     * Inline Markdown on escaped text.
     *
     * @param string $text Raw text.
     * @return string HTML.
     */
    private static function inline($text) {
        $text = esc_html($text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<em>$1</em>', $text);

        return preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            $url = esc_url(html_entity_decode($m[2]), array('http', 'https'));
            if ('' === $url) {
                return $m[1];
            }
            return sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', $url, $m[1]);
        }, $text);
    }
}
