<?php
/**
 * Site templates: one bounded, resumable run of a manifest on this site.
 *
 * Holds the site record (ownership, journal and progress) and hands each
 * resource to its applier. Every write is journalled before it happens.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Site_Template_Run {

    /** Resource kind => applier ($run, $id, $item). */
    const APPLIERS = array(
        'images' => array('SEOProStack_Site_Template_Media', 'apply'),
        'pages' => array('SEOProStack_Site_Template_Posts', 'apply_page'),
        'elements' => array('SEOProStack_Site_Template_Posts', 'apply_element'),
        'menus' => array('SEOProStack_Site_Template_Menus', 'apply'),
        'css' => array('SEOProStack_Site_Template_Values', 'apply_css'),
        'option' => array('SEOProStack_Site_Template_Values', 'apply_option'),
    );

    /** @var array Site ownership, journal and resumable progress. */
    public $record;
    /** @var bool Read-only comparison. */
    public $dry;
    /** @var array Comparison rows. */
    public $rows = array();

    /** @param array $record Stored ownership. @param bool $dry Read-only. */
    public function __construct($record, $dry) {
        $this->record = $record;
        $this->dry = $dry;
    }

    /**
     * Apply one bounded batch, or compare without saving anything.
     *
     * @param array $manifest Validated input.
     * @return array|WP_Error
     */
    public function batch($manifest) {
        $signature = hash('sha256', (string) wp_json_encode($manifest));
        $blocked = $this->blocked($signature);
        if ($blocked) {
            return $blocked;
        }
        $ops = SEOProStack_Site_Template_Manifest::resources($manifest);
        if (is_wp_error($ops)) {
            return $ops;
        }
        $this->begin($manifest, $signature);
        $cursor = $this->steps($ops);
        if (is_wp_error($cursor)) {
            return $cursor;
        }
        $pending = !$this->dry && $cursor < count($ops);
        if (!$this->dry && !$pending) {
            $this->finish($manifest);
        }
        return array('pending' => $pending, 'rows' => $this->rows, 'record' => $this->record);
    }

    /** @param string $signature Manifest hash. @return WP_Error|null Why this run may not start. */
    private function blocked($signature) {
        if (!empty($this->record['pending']) && $this->record['pending']['signature'] !== $signature) {
            return new WP_Error('template_pending', 'Finish or undo the pending manifest before replacing it.');
        }
        if ($this->dry) {
            return null;
        }
        if (!empty($this->record['undoing'])) {
            return new WP_Error('template_undo_pending', 'Finish the interrupted undo before applying again.');
        }
        foreach ($this->record['backup']['writes'] ?? array() as $write) {
            if (empty($write['complete']) || !empty($write['undone'])) {
                return new WP_Error('template_interrupted', 'A resource write was interrupted. Undo the partial run before applying again.');
            }
        }
        return null;
    }

    /** @param array $manifest Validated input. @param string $signature Manifest hash. */
    private function begin($manifest, $signature) {
        if ($this->dry) {
            $this->record['pending']['initial'] = empty($this->record['slug']);
            $this->record['slug'] = $manifest['slug'];
            return;
        }
        if (!empty($this->record['pending'])) {
            return;
        }
        $before = $this->record;
        unset($before['backup']);
        $previous_backup = $this->record['backup'] ?? null;
        $this->record['backup'] = array('record' => $before, 'writes' => array());
        $this->record['pending'] = array('signature' => $signature, 'cursor' => 0, 'initial' => empty($before), 'previous_backup' => $previous_backup);
        $this->record['slug'] = $manifest['slug'];
        $this->record['last_result'] = array();
        unset($this->record['error']);
        $this->save();
    }

    /** @param array $ops Resources. @return int|WP_Error Cursor after this batch. */
    private function steps($ops) {
        $start = microtime(true);
        $cursor = $this->dry ? 0 : $this->record['pending']['cursor'];
        $count = 0;
        foreach (array_slice($ops, $cursor) as $op) {
            $result = call_user_func(self::APPLIERS[$op[0]], $this, $op[1], $op[2]);
            if (is_wp_error($result)) {
                return $result;
            }
            ++$cursor;
            ++$count;
            if ($this->dry) {
                continue;
            }
            $this->checkpoint($cursor);
            if ($count >= 20 || microtime(true) - $start > 10) {
                break;
            }
        }
        return $cursor;
    }

    /** @param int $cursor Resources done. */
    private function checkpoint($cursor) {
        foreach ($this->record['backup']['writes'] as &$write) {
            $write['complete'] = true;
        }
        unset($write);
        $this->record['pending']['cursor'] = $cursor;
        $this->save();
    }

    /** @param array $manifest Validated input. */
    private function finish($manifest) {
        $named_posts = array();
        foreach (array('pages', 'elements') as $section) {
            foreach ($manifest[$section] as $item) {
                $named_posts[] = $section . '/' . $item['key'];
            }
        }
        foreach (array_diff(array_keys($this->record['posts'] ?? array()), $named_posts) as $path) {
            if (is_string($path)) {
                $this->report($path, 'removed-from-template');
            }
        }
        $this->record['version'] = $manifest['version'];
        if (!$this->record['backup']['writes'] && !empty($this->record['pending']['previous_backup'])) {
            $this->record['backup'] = $this->record['pending']['previous_backup'];
        }
        unset($this->record['pending']);
        $this->save();
    }

    /** Persist only our own state, never autoload the backup/content. */
    public function save() {
        if (!$this->dry) {
            update_option(SEOProStack_Site_Templates::OPTION, $this->record, false);
            if (get_option(SEOProStack_Site_Templates::OPTION) !== $this->record) {
                throw new RuntimeException('Unable to save the template journal; stopped before further changes.');
            }
        }
    }

    /** @param string $key Resource. @param string $status Outcome. */
    public function report($key, $status) {
        $this->rows[] = array('part' => $key, 'status' => $status);
        $this->record['last_result'][$key] = $status;
    }

    /**
     * Remember a write before executing it. Undo checks the exact after-image
     * so edits made after apply are not overwritten either.
     *
     * @param string     $type Storage kind.
     * @param int|string $id Target.
     * @param mixed      $before Previous value.
     * @param mixed      $after Intended value.
     */
    public function journal($type, $id, $before, $after) {
        $this->record['backup']['writes'][] = array('type' => $type, 'id' => $id, 'before' => $before, 'after' => $after);
        $this->save();
    }

    /** Set a field of the newest journal entry (saved with the next save()). @param string $field Field. @param mixed $value Value. */
    public function amend($field, $value) {
        $last = count($this->record['backup']['writes']) - 1;
        $this->record['backup']['writes'][$last][$field] = $value;
    }

    /** @param mixed $value Value to fingerprint. @return string */
    public static function fingerprint($value) {
        return hash('sha256', is_string($value) ? $value : (string) wp_json_encode($value));
    }

    /** Purge this site's page caches, including deferred cache plugins. */
    public static function purge() {
        delete_option(SEOProStack_Page_Cache::OPTION);
        SEOProStack_Page_Cache::after_update();
    }
}
