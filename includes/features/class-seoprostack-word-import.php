<?php
/**
 * Word documents in the editor.
 *
 * Drop or paste a .docx file into the block editor (or pick one from the
 * Options menu) and it becomes blocks. The server reads the document with
 * ZipArchive and DOM and returns plain HTML: headings, paragraphs, bold,
 * italic, underline, strikethrough, superscript, subscript, links, nested
 * lists, quotes, tables and pictures. The editor turns that HTML into core
 * blocks with wp.blocks.rawHandler(). Pictures are copied to the Media
 * Library, attached to the post.
 *
 * Replaces Mammoth .docx converter, without its 600 KB JavaScript library or
 * meta box.
 *
 * @package SEOProStack
 * @since 0.9.1
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.CodeAnalysis.AssignmentInTernaryCondition.FoundInTernaryCondition -- "($n = child()) ? attr($n) : default" reads an optional XML child once.

class SEOProStack_Word_Import extends SEOProStack_Feature {

    const KEY = 'word_import';

    /** Largest document accepted, in bytes. */
    const MAX_BYTES = 31457280;

    /** Largest part of a document read (the text, or one picture), in bytes. */
    const MAX_PART = 52428800;

    /** Most pictures copied from one document. */
    const MAX_IMAGES = 100;

    /** Image MIME types copied => extension. */
    const IMAGE_TYPES = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    );

    /** @var ZipArchive Open document. */
    private $zip;

    /** @var int Post the pictures are attached to. */
    private $post_id = 0;

    /** @var array<string,array{target:string,external:bool}> Relationships by ID. */
    private $rels = array();

    /** @var array<string,array> Paragraph styles by ID. */
    private $styles = array();

    /** @var array<string,array<int,bool>> numId => level => ordered. */
    private $numbering = array();

    /** @var array<string,string> Copied pictures: part path => HTML. */
    private $images = array();

    /** @var int Pictures that could not be copied. */
    private $failed_images = 0;

    /** @var string Document name, without .docx, for naming pictures. */
    private $doc_name = '';

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
                'label'       => __('Word documents in the editor', 'seoprostack'),
                'description' => __('Drop or paste a Word document (.docx) into the block editor, or choose one from the editor’s Options menu, and it becomes blocks: headings, lists, tables, links and pictures, with the pictures added to your Media Library.', 'seoprostack'),
                'replaces'    => array('mammoth-docx-converter' => 'Mammoth .docx converter'),
            ),
        );
    }

    /**
     * Switch on while Mammoth is active (it has no settings).
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        return isset(self::active_plugins()['mammoth-docx-converter']) ? self::import_setting($options, self::KEY, true) : $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor_assets'));
    }

    /**
     * Editor script, for people who can upload files.
     */
    public static function editor_assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || 'post' !== $screen->base || !current_user_can('upload_files')) {
            return;
        }
        $file = 'admin/js/seoprostack-word-import.js';
        // wp-edit-post: before WordPress 6.6, PluginMoreMenuItem is only
        // there. Only the post editor gets this far, and it loads it anyway.
        $deps = array('wp-api-fetch', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n', 'wp-plugins', 'wp-notices', 'wp-editor', 'wp-edit-post');
        wp_enqueue_script('seoprostack-word-import', SEOPROSTACK_URL . $file, $deps, (string) filemtime(SEOPROSTACK_DIR . $file), true);
        wp_add_inline_script('seoprostack-word-import', 'window.seoprostackWordImport = ' . wp_json_encode(array(
            'canRead'  => class_exists('ZipArchive'),
            'maxBytes' => min(self::MAX_BYTES, (int) wp_max_upload_size()),
        )) . ';', 'before');
    }

    /**
     * REST route that converts a document.
     */
    public static function register_routes() {
        register_rest_route('seoprostack/v1', '/word-document', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rest_convert'),
            'permission_callback' => function ($request) {
                $post_id = (int) $request['post'];
                return current_user_can('upload_files') && ($post_id ? current_user_can('edit_post', $post_id) : current_user_can('edit_posts'));
            },
            'args'                => array(
                'post' => array('type' => 'integer', 'default' => 0),
            ),
        ));
    }

    /**
     * REST: convert the uploaded document to HTML.
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function rest_convert($request) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('seoprostack_word_zip', __('This server cannot open Word documents: ask your host to turn on PHP’s zip extension.', 'seoprostack'), array('status' => 501));
        }
        $files = $request->get_file_params();
        $file  = isset($files['file']) && is_array($files['file']) ? $files['file'] : null;
        if (!$file || !empty($file['error']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('seoprostack_word_upload', __('The document did not arrive. Try again, or check the largest upload your site allows.', 'seoprostack'), array('status' => 400));
        }
        if (!preg_match('/\.docx$/i', (string) $file['name'])) {
            return new WP_Error('seoprostack_word_type', __('Only Word documents (.docx) can be converted. Save older .doc files as .docx first.', 'seoprostack'), array('status' => 415));
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            return new WP_Error('seoprostack_word_size', __('The document is too large.', 'seoprostack'), array('status' => 413));
        }

        $converter = new self();
        $result    = $converter->convert($file['tmp_name'], (int) $request['post'], (string) $file['name']);
        if (is_wp_error($result)) {
            $result->add_data(array('status' => 422));
        }
        return $result;
    }

    /*
     * ------------------------------------------------------------------
     * Conversion
     * ------------------------------------------------------------------
     */

    /**
     * Convert a .docx file.
     *
     * @param string $path    File path.
     * @param int    $post_id Post the pictures are attached to.
     * @param string $name    Document file name.
     * @return array{html:string,images:int,failedImages:int}|WP_Error
     */
    public function convert($path, $post_id = 0, $name = '') {
        $this->zip      = new ZipArchive();
        $this->post_id  = (int) $post_id;
        $this->doc_name = preg_replace('/\.docx$/i', '', wp_basename((string) $name));
        if (true !== $this->zip->open($path)) {
            return new WP_Error('seoprostack_word_open', __('This is not a Word document WordPress can read.', 'seoprostack'));
        }

        $document = $this->xml('word/document.xml');
        if (!$document) {
            $this->zip->close();
            return new WP_Error('seoprostack_word_open', __('This is not a Word document WordPress can read.', 'seoprostack'));
        }
        $this->read_rels();
        $this->read_styles();
        $this->read_numbering();

        $body = $this->child($document->documentElement, 'body');
        $html = $body ? $this->blocks($body) : '';
        $this->zip->close();

        return array(
            'html'         => $html,
            'images'       => count($this->images),
            'failedImages' => $this->failed_images,
        );
    }

    /**
     * Parse an XML part of the document.
     *
     * @param string $name Part path.
     * @return DOMDocument|null
     */
    private function xml($name) {
        $stat = $this->zip->statName($name);
        if (!$stat || $stat['size'] > self::MAX_PART) {
            return null;
        }
        $xml = $this->zip->getFromName($name);
        // Word never writes a DOCTYPE; refusing one rules out entity tricks.
        if (!is_string($xml) || '' === $xml || false !== stripos($xml, '<!DOCTYPE')) {
            return null;
        }
        $dom      = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $loaded ? $dom : null;
    }

    /**
     * Read the document's relationships (links and pictures).
     */
    private function read_rels() {
        $dom = $this->xml('word/_rels/document.xml.rels');
        if (!$dom) {
            return;
        }
        foreach ($dom->documentElement->childNodes as $rel) {
            if (!$rel instanceof DOMElement || 'Relationship' !== $rel->localName) {
                continue;
            }
            $this->rels[$rel->getAttribute('Id')] = array(
                'target'   => $rel->getAttribute('Target'),
                'external' => 'External' === $rel->getAttribute('TargetMode'),
            );
        }
    }

    /**
     * Read paragraph styles: heading level, quote, list.
     */
    private function read_styles() {
        $dom = $this->xml('word/styles.xml');
        if (!$dom) {
            return;
        }
        foreach ($dom->documentElement->childNodes as $style) {
            if (!$style instanceof DOMElement || 'style' !== $style->localName) {
                continue;
            }
            $name    = ($n = $this->child($style, 'name')) ? strtolower($this->attr($n, 'val')) : '';
            $based   = ($b = $this->child($style, 'basedOn')) ? $this->attr($b, 'val') : '';
            $ppr     = $this->child($style, 'pPr');
            $outline = $ppr && ($o = $this->child($ppr, 'outlineLvl')) ? (int) $this->attr($o, 'val') + 1 : 0;
            $level   = 0;
            if (preg_match('/^heading\s*([1-9])$/', $name, $m)) {
                $level = (int) $m[1];
            } elseif ('title' === $name) {
                $level = 1;
            } elseif ($outline >= 1 && $outline <= 9) {
                $level = $outline;
            }
            $this->styles[$this->attr($style, 'styleId')] = array(
                'level'   => min(6, $level),
                // "Quote", "Intense Quote", and "Block Text" from Google Docs and pandoc.
                'quote'   => (bool) preg_match('/\bquote\b|^block text$/', $name),
                'caption' => (bool) preg_match('/\bcaption\b/', $name),
                'num'   => $ppr ? $this->num_pr($ppr) : null,
                'based' => $based,
            );
        }
    }

    /**
     * Read list numbering: which levels are numbered and which are bullets.
     */
    private function read_numbering() {
        $dom = $this->xml('word/numbering.xml');
        if (!$dom) {
            return;
        }
        $abstract = array();
        $nums     = array();
        foreach ($dom->documentElement->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ('abstractNum' === $node->localName) {
                $levels = array();
                foreach ($node->childNodes as $lvl) {
                    if ($lvl instanceof DOMElement && 'lvl' === $lvl->localName) {
                        $fmt = ($f = $this->child($lvl, 'numFmt')) ? $this->attr($f, 'val') : 'bullet';
                        $levels[(int) $this->attr($lvl, 'ilvl')] = !in_array($fmt, array('bullet', 'none', ''), true);
                    }
                }
                $abstract[$this->attr($node, 'abstractNumId')] = $levels;
            } elseif ('num' === $node->localName && ($a = $this->child($node, 'abstractNumId'))) {
                $nums[$this->attr($node, 'numId')] = $this->attr($a, 'val');
            }
        }
        foreach ($nums as $num_id => $abstract_id) {
            $this->numbering[$num_id] = isset($abstract[$abstract_id]) ? $abstract[$abstract_id] : array();
        }
    }

    /**
     * List membership from a paragraph's (or style's) properties.
     *
     * @param DOMElement $ppr pPr element.
     * @return array{id:string,level:int}|null
     */
    private function num_pr(DOMElement $ppr) {
        $num = $this->child($ppr, 'numPr');
        if (!$num) {
            return null;
        }
        $id  = ($i = $this->child($num, 'numId')) ? $this->attr($i, 'val') : '';
        $lvl = ($l = $this->child($num, 'ilvl')) ? (int) $this->attr($l, 'val') : 0;
        return '' === $id ? null : array('id' => $id, 'level' => max(0, min(8, $lvl)));
    }

    /**
     * A style's value, following basedOn.
     *
     * @param string $style_id Style ID.
     * @param string $key      level|quote|caption|num.
     * @return mixed
     */
    private function style_value($style_id, $key) {
        for ($i = 0; $i < 10 && '' !== $style_id && isset($this->styles[$style_id]); $i++) {
            $style = $this->styles[$style_id];
            if (!empty($style[$key])) {
                return $style[$key];
            }
            $style_id = $style['based'];
        }
        return null;
    }

    /**
     * Convert block-level content: paragraphs, lists and tables.
     *
     * @param DOMElement $parent body, table cell or content control.
     * @return string
     */
    private function blocks(DOMElement $parent) {
        $html  = '';
        $items = array();
        $top   = '';
        foreach ($this->block_nodes($parent) as $node) {
            if ('tbl' === $node->localName) {
                $html .= $this->lists($items) . $this->table($node);
                $items = array();
                continue;
            }
            $para = $this->paragraph($node);
            if ($para['list']) {
                // A new list starts when the top-level numbering changes
                // (nested levels often have numbering of their own).
                if (0 === $para['list']['level']) {
                    if ($items && $top !== $para['list']['id']) {
                        $html .= $this->lists($items);
                        $items = array();
                    }
                    $top = $para['list']['id'];
                }
                $items[] = $para;
                continue;
            }
            $html .= $this->lists($items);
            $items = array();
            // A caption straight after a picture or table becomes its caption.
            if ($para['caption'] && '' !== $para['inline'] && '</figure>' === substr($html, -9)) {
                $html = substr($html, 0, -9) . '<figcaption>' . $para['inline'] . '</figcaption></figure>';
                continue;
            }
            $html .= $para['html'];
        }
        return $html . $this->lists($items);
    }

    /**
     * Paragraphs and tables, looking inside content controls and tracked
     * insertions.
     *
     * @param DOMElement $parent Parent element.
     * @return DOMElement[]
     */
    private function block_nodes(DOMElement $parent) {
        $nodes = array();
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ('p' === $node->localName || 'tbl' === $node->localName) {
                $nodes[] = $node;
            } elseif (in_array($node->localName, array('sdt', 'sdtContent', 'customXml', 'ins', 'smartTag'), true)) {
                $nodes = array_merge($nodes, $this->block_nodes($node));
            }
        }
        return $nodes;
    }

    /**
     * Convert a paragraph.
     *
     * @param DOMElement $p w:p.
     * @return array{html:string,inline:string,list:array|null,ordered:bool,caption:bool}
     */
    private function paragraph(DOMElement $p) {
        $ppr   = $this->child($p, 'pPr');
        $style = $ppr && ($s = $this->child($ppr, 'pStyle')) ? $this->attr($s, 'val') : '';
        $num   = $ppr ? $this->num_pr($ppr) : null;
        if (!$num && '' !== $style) {
            $num = $this->style_value($style, 'num');
        }
        if ($num && ('0' === $num['id'] || !isset($this->numbering[$num['id']]))) {
            $num = null;
        }
        $level = '' !== $style ? (int) $this->style_value($style, 'level') : 0;
        if (!$level && $ppr && ($o = $this->child($ppr, 'outlineLvl'))) {
            $outline = (int) $this->attr($o, 'val') + 1;
            $level   = $outline <= 6 ? $outline : 0;
        }

        $parts  = $this->inline($p);
        $inline = trim(implode('', array_filter($parts, 'is_string')));
        $inline = preg_replace('#^(<br>\s*)+|(<br>\s*)+$#', '', $inline);

        if ($num) {
            $images = implode('', array_map(function ($part) {
                return is_array($part) ? $part['html'] : '';
            }, $parts));
            return array(
                'html'    => '',
                'inline'  => $inline . $images,
                'list'    => $num,
                'ordered' => !empty($this->numbering[$num['id']][$num['level']]),
                'caption' => false,
            );
        }

        if ($level) {
            $tag = 'h' . $level;
        } elseif ('' !== $style && $this->style_value($style, 'quote')) {
            $tag = 'blockquote';
        } else {
            $tag = 'p';
        }

        // Pictures become blocks of their own, between the text around them.
        $html = '';
        $text = '';
        foreach ($parts as $part) {
            if (is_array($part)) {
                $html .= $this->wrap($tag, $text) . '<figure>' . $part['html'] . '</figure>';
                $text  = '';
            } else {
                $text .= $part;
            }
        }
        $html .= $this->wrap($tag, $text);
        return array(
            'html'    => $html,
            'inline'  => $inline,
            'list'    => null,
            'ordered' => false,
            'caption' => '' !== $style && !$level && (bool) $this->style_value($style, 'caption'),
        );
    }

    /**
     * Wrap text in a tag, or nothing when it is empty.
     *
     * @param string $tag  Tag.
     * @param string $text Inline HTML.
     * @return string
     */
    private function wrap($tag, $text) {
        $text = trim(preg_replace('#^(<br>\s*)+|(<br>\s*)+$#', '', trim($text)));
        if ('' === trim(wp_strip_all_tags($text))) {
            return '';
        }
        return 'blockquote' === $tag ? '<blockquote><p>' . $text . '</p></blockquote>' : '<' . $tag . '>' . $text . '</' . $tag . '>';
    }

    /**
     * Inline content of a paragraph: formatted text, links and pictures.
     *
     * @param DOMElement $parent Paragraph, hyperlink or similar.
     * @param string     $href   Link around this content.
     * @return array<int,string|array{html:string}> HTML strings, and pictures.
     */
    private function inline(DOMElement $parent, $href = '') {
        $runs = array();
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            switch ($node->localName) {
                case 'r':
                    $runs = array_merge($runs, $this->run($node, $href));
                    break;
                case 'hyperlink':
                    $link = '';
                    $id   = $this->attr($node, 'id');
                    if ('' !== $id && isset($this->rels[$id]) && $this->rels[$id]['external']) {
                        $link = esc_url_raw($this->rels[$id]['target'], array('http', 'https', 'mailto', 'tel'));
                    }
                    $runs = array_merge($runs, $this->inline($node, '' !== $link ? $link : $href));
                    break;
                case 'ins':
                case 'smartTag':
                case 'customXml':
                case 'sdt':
                case 'sdtContent':
                case 'fldSimple':
                    $runs = array_merge($runs, $this->inline($node, $href));
                    break;
            }
        }
        return 'p' === $parent->localName ? $this->merge($runs) : $runs;
    }

    /**
     * A run of text with one format.
     *
     * @param DOMElement $r    w:r.
     * @param string     $href Link around it.
     * @return array<int,array> Text pieces {text, format, href} and pictures {image}.
     */
    private function run(DOMElement $r, $href) {
        $format = array();
        $rpr    = $this->child($r, 'rPr');
        if ($rpr) {
            foreach (array('b' => 'strong', 'i' => 'em', 'strike' => 's', 'dstrike' => 's') as $prop => $tag) {
                $el = $this->child($rpr, $prop);
                if ($el && !in_array(strtolower($this->attr($el, 'val')), array('0', 'false', 'off'), true)) {
                    $format[$tag] = true;
                }
            }
            $u = $this->child($rpr, 'u');
            if ($u && !in_array(strtolower($this->attr($u, 'val')), array('none', '0', 'false'), true) && '' === $href) {
                $format['u'] = true;
            }
            $va = $this->child($rpr, 'vertAlign');
            if ($va && 'superscript' === $this->attr($va, 'val')) {
                $format['sup'] = true;
            } elseif ($va && 'subscript' === $this->attr($va, 'val')) {
                $format['sub'] = true;
            }
        }

        $pieces = array();
        $text   = '';
        foreach ($r->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            switch ($node->localName) {
                case 't':
                    $text .= esc_html($node->textContent);
                    break;
                case 'tab':
                    $text .= ' ';
                    break;
                case 'br':
                case 'cr':
                    if ('page' !== $this->attr($node, 'type')) {
                        $text .= '<br>';
                    }
                    break;
                case 'noBreakHyphen':
                    $text .= '-';
                    break;
                case 'drawing':
                case 'pict':
                case 'object':
                    if ('' !== $text) {
                        $pieces[] = array('text' => $text, 'format' => $format, 'href' => $href);
                        $text     = '';
                    }
                    $image = $this->image($node);
                    if ('' !== $image) {
                        $pieces[] = array('image' => $image);
                    }
                    break;
            }
        }
        if ('' !== $text) {
            $pieces[] = array('text' => $text, 'format' => $format, 'href' => $href);
        }
        return $pieces;
    }

    /**
     * Join runs with the same format and link into HTML.
     *
     * @param array $runs Pieces from run().
     * @return array<int,string|array{html:string}>
     */
    private function merge(array $runs) {
        $out     = array();
        $buffer  = '';
        $current = null;
        $flush   = function () use (&$out, &$buffer, &$current) {
            if (null !== $current && '' !== $buffer) {
                // Spaces at the ends stay outside the formatting: "word <strong>bold</strong> word".
                if (!preg_match('/^(\s*)(.*?)(\s*)$/s', $buffer, $m)) {
                    $m = array($buffer, '', $buffer, ''); // A PCRE limit: keep the text, untrimmed.
                }
                $html = $m[2];
                if ('' !== $html) {
                    // Formats inside links: <a><strong>…</strong></a>.
                    foreach (array('sub', 'sup', 'u', 's', 'em', 'strong') as $tag) {
                        if (!empty($current['format'][$tag])) {
                            $html = '<' . $tag . '>' . $html . '</' . $tag . '>';
                        }
                    }
                    if ('' !== $current['href']) {
                        $html = '<a href="' . esc_url($current['href']) . '">' . $html . '</a>';
                    }
                }
                $out[] = $m[1] . $html . $m[3];
            }
            $buffer  = '';
            $current = null;
        };
        foreach ($runs as $run) {
            if (isset($run['image'])) {
                $flush();
                $out[] = array('html' => $run['image']);
                continue;
            }
            $key = array('format' => $run['format'], 'href' => $run['href']);
            if (null !== $current && $current !== $key) {
                $flush();
            }
            $current = $key;
            $buffer .= $run['text'];
        }
        $flush();
        return $out;
    }

    /**
     * Build nested lists from list paragraphs.
     *
     * @param array $items Paragraphs from paragraph() with a list.
     * @return string
     */
    private function lists(array $items) {
        if (!$items) {
            return '';
        }
        $html  = '';
        $stack = array();
        foreach ($items as $item) {
            $depth = $item['list']['level'] + 1;
            $tag   = $item['ordered'] ? 'ol' : 'ul';
            if ($depth > count($stack)) {
                while (count($stack) < $depth) {
                    if ($stack && count($stack) < $depth - 1) {
                        $html .= '<li>';
                    }
                    $html   .= '<' . $tag . '>';
                    $stack[] = $tag;
                }
                $html .= '<li>' . $item['inline'];
                continue;
            }
            while (count($stack) > $depth) {
                $html .= '</li></' . array_pop($stack) . '>';
            }
            $html .= '</li><li>' . $item['inline'];
        }
        while ($stack) {
            $html .= '</li></' . array_pop($stack) . '>';
        }
        return $html;
    }

    /**
     * Convert a table. Cells merged down are left empty.
     *
     * @param DOMElement $tbl w:tbl.
     * @return string
     */
    private function table(DOMElement $tbl) {
        $head = '';
        $body = '';
        foreach ($tbl->childNodes as $tr) {
            if (!$tr instanceof DOMElement || 'tr' !== $tr->localName) {
                continue;
            }
            $trpr      = $this->child($tr, 'trPr');
            $is_header = '' === $body && $trpr && $this->child($trpr, 'tblHeader');
            $cells     = '';
            foreach ($tr->childNodes as $tc) {
                if (!$tc instanceof DOMElement || 'tc' !== $tc->localName) {
                    continue;
                }
                $tcpr = $this->child($tc, 'tcPr');
                $span = $tcpr && ($g = $this->child($tcpr, 'gridSpan')) ? max(1, (int) $this->attr($g, 'val')) : 1;
                $text = array();
                foreach ($this->block_nodes($tc) as $node) {
                    if ('p' === $node->localName) {
                        $para = $this->paragraph($node);
                        if ('' !== $para['inline']) {
                            $text[] = $para['inline'];
                        }
                    }
                }
                $tag    = $is_header ? 'th' : 'td';
                $cells .= '<' . $tag . ($span > 1 ? ' colspan="' . $span . '"' : '') . '>' . implode('<br>', $text) . '</' . $tag . '>';
            }
            if ($is_header) {
                $head .= '<tr>' . $cells . '</tr>';
            } else {
                $body .= '<tr>' . $cells . '</tr>';
            }
        }
        if ('' === $head && '' === $body) {
            return '';
        }
        // A bare <table>: the editor turns <figure><table> into Custom HTML.
        return '<table>' . ('' !== $head ? '<thead>' . $head . '</thead>' : '') . '<tbody>' . $body . '</tbody></table>';
    }

    /**
     * Copy a picture to the Media Library and return its <img>.
     *
     * @param DOMElement $node w:drawing, w:pict or w:object.
     * @return string
     */
    private function image(DOMElement $node) {
        $rid = '';
        $alt = '';
        foreach ($node->getElementsByTagName('*') as $el) {
            if ('' === $rid && ('blip' === $el->localName || 'imagedata' === $el->localName)) {
                $rid = $this->attr($el, 'embed');
                $rid = '' !== $rid ? $rid : $this->attr($el, 'id');
            }
            if ('' === $alt && 'docPr' === $el->localName) {
                $alt = trim($el->getAttribute('descr'));
            }
        }
        if ('' === $rid || !isset($this->rels[$rid]) || $this->rels[$rid]['external']) {
            return '';
        }
        $part = $this->part_path($this->rels[$rid]['target']);
        if (isset($this->images[$part])) {
            return $this->images[$part];
        }
        if (count($this->images) >= self::MAX_IMAGES) {
            ++$this->failed_images;
            return '';
        }
        $id = $this->copy_image($part, $alt);
        if (!$id) {
            ++$this->failed_images;
            return '';
        }
        if ('' !== $alt) {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($alt));
        }
        $src = wp_get_attachment_image_url($id, 'large');
        $this->images[$part] = '<img src="' . esc_url((string) $src) . '" alt="' . esc_attr($alt) . '" class="wp-image-' . (int) $id . '">';
        return $this->images[$part];
    }

    /**
     * Resolve a relationship target to a path inside the document.
     *
     * @param string $target Target, relative to word/.
     * @return string
     */
    private function part_path($target) {
        $path  = 0 === strpos($target, '/') ? ltrim($target, '/') : 'word/' . $target;
        $parts = array();
        foreach (explode('/', $path) as $segment) {
            if ('..' === $segment) {
                array_pop($parts);
            } elseif ('' !== $segment && '.' !== $segment) {
                $parts[] = $segment;
            }
        }
        return implode('/', $parts);
    }

    /**
     * Copy one picture from the document.
     *
     * @param string $part Path inside the document.
     * @param string $alt  Its alt text.
     * @return int Attachment ID, or 0.
     */
    private function copy_image($part, $alt) {
        $stat = $this->zip->statName($part);
        if (!$stat || $stat['size'] > self::MAX_PART) {
            return 0;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam($part);
        if (!$tmp || false === file_put_contents($tmp, $this->zip->getFromName($part))) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary file for media_handle_sideload().
            return 0;
        }
        $mime = wp_get_image_mime($tmp);
        if (!isset(self::IMAGE_TYPES[$mime])) {
            wp_delete_file($tmp);
            return 0;
        }
        // Name it after its alt text, or the document ("Report.docx" => report-image.png).
        $base = '' !== $alt ? $alt : $this->doc_name . ' image';
        $base = sanitize_title(wp_html_excerpt($base, 60, ''));
        $name = ('' !== $base ? $base : 'word-image') . '.' . self::IMAGE_TYPES[$mime];
        $id   = media_handle_sideload(array('name' => $name, 'tmp_name' => $tmp), $this->post_id);
        if (is_wp_error($id)) {
            wp_delete_file($tmp);
            return 0;
        }
        return (int) $id;
    }

    /**
     * First child element with a local name.
     *
     * @param DOMElement $parent Parent.
     * @param string     $name   Local name.
     * @return DOMElement|null
     */
    private function child(DOMElement $parent, $name) {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $name === $node->localName) {
                return $node;
            }
        }
        return null;
    }

    /**
     * Attribute by local name, whatever its namespace (Word uses two sets).
     *
     * @param DOMElement $el   Element.
     * @param string     $name Local name.
     * @return string
     */
    private function attr(DOMElement $el, $name) {
        foreach ($el->attributes as $attribute) {
            if ($name === $attribute->localName) {
                return (string) $attribute->value;
            }
        }
        return '';
    }
}
