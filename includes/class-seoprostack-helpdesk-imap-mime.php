<?php
/**
 * Fluent Support email by IMAP: an email's text, HTML and attachment names,
 * from its MIME parts.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.118
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Helpdesk_Imap_Mime {

    /** Attachment names kept. */
    const MAX_FILES = 50;

    /**
     * The first plain-text part, as UTF-8.
     *
     * @var string
     */
    public $text = '';

    /**
     * The first HTML part, as UTF-8.
     *
     * @var string
     */
    public $html = '';

    /**
     * Attachment file names.
     *
     * @var string[]
     */
    public $files = array();

    /**
     * Connection.
     *
     * @var SEOProStack_Helpdesk_Imap_Connection
     */
    private $conn;

    /**
     * UID.
     *
     * @var int
     */
    private $uid;

    /**
     * An email to read.
     *
     * @param SEOProStack_Helpdesk_Imap_Connection $conn Connection.
     * @param int                                  $uid  UID.
     */
    public function __construct($conn, $uid) {
        $this->conn = $conn;
        $this->uid  = $uid;
    }

    /**
     * Read the email's parts.
     *
     * @return SEOProStack_Helpdesk_Imap_Mime
     */
    public function read() {
        $structure = $this->conn->structure($this->uid);
        if (!$structure) {
            return $this;
        }
        $parts = (array) self::prop($structure, 'parts', array());
        if (!$parts) {
            $this->part($structure, '1');
            return $this;
        }
        foreach (array_values($parts) as $index => $part) {
            $this->part($part, (string) ($index + 1));
        }
        return $this;
    }

    /**
     * Read one part, and the parts inside it.
     *
     * @param object $part    Part structure.
     * @param string $section Section number, such as 1.2.
     */
    private function part($part, $section) {
        $params = self::params($part);
        $name   = self::file_name($params);
        $type   = (int) self::prop($part, 'type', TYPETEXT);
        $parts  = (array) self::prop($part, 'parts', array());

        if (TYPEMULTIPART === $type && $parts) {
            foreach (array_values($parts) as $index => $child) {
                $this->part($child, $section . '.' . ($index + 1));
            }
            return;
        }
        if ('' !== $name || TYPETEXT !== $type || 'attachment' === strtolower((string) self::prop($part, 'disposition', ''))) {
            $this->add_file($name, $type);
            return;
        }
        $this->add_text($part, $section, $params);
    }

    /**
     * Note an attachment's name.
     *
     * @param string $name File name, maybe encoded.
     * @param int    $type TYPE* constant.
     */
    private function add_file($name, $type) {
        if (TYPEMESSAGE === $type && '' === $name) {
            $name = __('forwarded email', 'seoprostack');
        }
        if ('' !== $name && count($this->files) < self::MAX_FILES) {
            $this->files[] = sanitize_file_name(self::decode_header($name));
        }
    }

    /**
     * Keep the first plain-text and the first HTML part.
     *
     * @param object               $part    Part structure.
     * @param string               $section Section number.
     * @param array<string,string> $params  Part parameters.
     */
    private function add_text($part, $section, array $params) {
        $subtype = strtolower((string) self::prop($part, 'subtype', 'plain'));
        if (!in_array($subtype, array('plain', 'html'), true)) {
            return;
        }
        $have = 'html' === $subtype ? $this->html : $this->text;
        if ('' !== $have) {
            return;
        }
        $params += array('charset' => '');
        $raw     = $this->conn->section($this->uid, $section);
        $text    = self::to_utf8(self::decode_part($raw, (int) self::prop($part, 'encoding', ENC7BIT)), $params['charset']);
        if ('html' === $subtype) {
            $this->html = $text;
            return;
        }
        $this->text = $text;
    }

    /**
     * A part's file name, if it has one.
     *
     * @param array<string,string> $params Part parameters.
     * @return string
     */
    private static function file_name(array $params) {
        $params += array('filename' => '', 'name' => '');
        return '' !== $params['filename'] ? $params['filename'] : $params['name'];
    }

    /**
     * A property of a structure imap_fetchstructure() gave, which lists only
     * the properties a part has.
     *
     * @param object $structure Structure.
     * @param string $name      Property.
     * @param mixed  $fallback  Value when missing.
     * @return mixed
     */
    private static function prop($structure, $name, $fallback = null) {
        return isset($structure->$name) ? $structure->$name : $fallback;
    }

    /**
     * A part's parameters (charset, name, filename), lower-case names.
     *
     * @param object $part Part structure.
     * @return array<string,string>
     */
    private static function params($part) {
        $params = array();
        foreach (array('parameters', 'dparameters') as $list) {
            foreach ((array) self::prop($part, $list, array()) as $param) {
                if (isset($param->attribute, $param->value)) {
                    $params[strtolower(rtrim((string) $param->attribute, '*'))] = (string) $param->value;
                }
            }
        }
        return $params;
    }

    /**
     * Undo a part's transfer encoding.
     *
     * @param string $raw      Raw part.
     * @param int    $encoding ENC* constant.
     * @return string
     */
    private static function decode_part($raw, $encoding) {
        if (ENCBASE64 === $encoding) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- MIME transfer encoding of an email part.
            return (string) base64_decode($raw);
        }
        return ENCQUOTEDPRINTABLE === $encoding ? quoted_printable_decode($raw) : $raw;
    }

    /**
     * Text in a character set, as UTF-8.
     *
     * @param string $text    Text.
     * @param string $charset Character set.
     * @return string
     *
     * An unknown character set warns on PHP 7 (and throws on PHP 8); the text
     * is then kept as it is, without invalid UTF-8.
     * @SuppressWarnings("PHPMD.ErrorControlOperator")
     */
    public static function to_utf8($text, $charset) {
        $charset = strtoupper(trim($charset));
        if (in_array($charset, array('', 'UTF-8', 'US-ASCII'), true) || !function_exists('mb_convert_encoding')) {
            return wp_check_invalid_utf8($text, true);
        }
        try {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unknown character set warns on PHP 7.
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
        } catch (Throwable $e) {
            $converted = false;
        }
        return is_string($converted) ? $converted : wp_check_invalid_utf8($text, true);
    }

    /**
     * A header that may hold encoded words (=?UTF-8?B?…?=), as UTF-8.
     *
     * @param string $value Header value.
     * @return string
     */
    public static function decode_header($value) {
        $out = '';
        foreach ((array) imap_mime_header_decode((string) $value) as $piece) {
            $out .= self::to_utf8((string) $piece->text, 'default' === $piece->charset ? '' : (string) $piece->charset);
        }
        return trim($out);
    }
}
