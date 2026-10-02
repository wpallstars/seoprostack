<?php
/**
 * SEO Pro Stack Read Me tab.
 *
 * Renders README.md with a small, escaping Markdown subset
 * (headings with GitHub-style IDs, lists, tables, bold, italic, inline code,
 * http(s) links and links to headings).
 *
 * @package SEOProStack
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Readme_Manager {

    /**
     * Render the tab.
     */
    public static function display_tab_content() {
        $readme = seoprostack_get_readme_content();
        ?>
        <article class="sps-card sps-readme">
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
        $markdown = str_replace('{SEOPROSTACK_VERSION}', SEOPROSTACK_VERSION, $markdown);
        $lines    = preg_split('/\r\n|\r|\n/', $markdown);
        $html     = '';
        $list     = '';
        $table    = ''; // '', 'head' (header row written) or 'body'.
        $ids      = array();

        $close_list = function () use (&$html, &$list, &$table) {
            if ($list) {
                $html .= '</' . $list . '>';
                $list  = '';
            }
            if ($table) {
                $html .= ('head' === $table ? '</thead>' : '</tbody>') . '</table></div>';
                $table = '';
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
                $html .= sprintf('<h%1$d id="%2$s">%3$s</h%1$d>', $level, esc_attr(self::anchor($m[2], $ids)), self::inline($m[2]));
                continue;
            }

            // Tables: a header row, a |---| separator, then body rows.
            if (strlen($trim) > 1 && '|' === $trim[0] && '|' === substr($trim, -1)) {
                if ('head' === $table && preg_match('/^\|[\s:|-]+\|$/', $trim)) {
                    $html .= '</thead><tbody>';
                    $table = 'body';
                    continue;
                }
                if (!$table) {
                    $close_list();
                    $html .= '<div class="sps-readme-table"><table><thead>';
                    $table = 'head';
                }
                $tag   = 'head' === $table ? 'th' : 'td';
                $html .= '<tr>';
                foreach (explode('|', substr($trim, 1, -1)) as $cell) {
                    $html .= '<' . $tag . '>' . self::inline(trim($cell)) . '</' . $tag . '>';
                }
                $html .= '</tr>';
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
     * Heading ID as GitHub makes it, so README links such as
     * `[Credits](#credits)` work in the tab too. Repeats get -1, -2...
     *
     * @param string $text Heading Markdown.
     * @param array  $ids  IDs used so far (updated).
     * @return string ID.
     */
    private static function anchor($text, array &$ids) {
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);
        $text = str_replace(array('`', '*'), '', $text);
        $id   = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $id   = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $id);
        $id   = preg_replace('/\s/u', '-', trim($id));
        $base = '' === $id ? 'section' : $id;
        $id   = $base;
        $n    = 0;
        while (isset($ids[$id])) {
            $id = $base . '-' . (++$n);
        }
        $ids[$id] = true;

        return $id;
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
            if ('#' === $m[2][0]) {
                return sprintf('<a href="#%1$s">%2$s</a>', esc_attr(substr($m[2], 1)), $m[1]);
            }
            if ('' === $url) {
                return $m[1];
            }
            return sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', $url, $m[1]);
        }, $text);
    }
}
