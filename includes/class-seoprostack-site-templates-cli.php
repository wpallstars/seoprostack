<?php
/**
 * Site template WP-CLI commands.
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

class SEOProStack_Site_Templates_CLI {

    /**
     * List shipped templates.
     *
     * ## OPTIONS
     *
     * [--all-sites]
     * : Visit every active site sequentially; stop at the first failure.
     *
     * @subcommand list
     * @param array $args Arguments.
     * @param array $assoc_args Flags.
     */
    public function list_($args, $assoc_args) {
        $this->dispatch('list', $args, $assoc_args);
    }

    /**
     * Show ownership, current part status and the last run's result.
     *
     * ## OPTIONS
     *
     * [--all-sites]
     * : Visit every active site sequentially; stop at the first failure.
     *
     * @param array $args Arguments.
     * @param array $assoc_args Flags.
     */
    public function status($args, $assoc_args) {
        $this->dispatch('status', $args, $assoc_args);
    }

    /**
     * Compare a shipped manifest without writing or claiming this site.
     *
     * ## OPTIONS
     *
     * [<slug>]
     * : Template slug; defaults to this site's recorded template.
     *
     * [--all-sites]
     * : Visit every active site sequentially; stop at the first failure.
     *
     * @param array $args Arguments.
     * @param array $assoc_args Flags.
     */
    public function diff($args, $assoc_args) {
        $this->dispatch('diff', $args, $assoc_args);
    }

    /**
     * Apply or resume a template; an explicit slug can initialise a new site.
     *
     * ## OPTIONS
     *
     * [<slug>]
     * : Template slug; defaults to this site's recorded template.
     *
     * [--all-sites]
     * : Update recorded sites only, never initialise an entire network.
     *
     * @param array $args Arguments.
     * @param array $assoc_args Flags.
     */
    public function apply($args, $assoc_args) {
        $this->dispatch('apply', $args, $assoc_args);
    }

    /**
     * Undo the last run, refusing to overwrite edits made after that run.
     *
     * ## OPTIONS
     *
     * [--all-sites]
     * : Visit recorded active sites sequentially; stop at the first failure.
     *
     * @param array $args Arguments.
     * @param array $assoc_args Flags.
     */
    public function undo($args, $assoc_args) {
        $this->dispatch('undo', $args, $assoc_args);
    }

    /** @param string $command Operation. @param array $args Arguments. @param array $flags Flags. */
    private function dispatch($command, $args, $flags) {
        $error = empty($flags['all-sites']) ? $this->site($command, $args) : $this->all_sites($command, $args);
        if (is_wp_error($error)) {
            WP_CLI::error($error->get_error_message());
        }
    }

    /**
     * Visit every active site in turn; stop at the first failure.
     *
     * @param string $command Operation.
     * @param array  $args Arguments.
     * @return true
     */
    private function all_sites($command, $args) {
        if (!is_multisite()) {
            WP_CLI::error('--all-sites needs a multisite network.');
        }
        // Without a slug, diff has nothing to compare on an unrecorded site.
        $recorded_only = in_array($command, array('apply', 'undo'), true) || ('diff' === $command && empty($args[0]));
        $offset = 0;
        do {
            $ids = get_sites(array('fields' => 'ids', 'number' => 50, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC', 'deleted' => 0, 'spam' => 0, 'archived' => 0));
            foreach ($ids as $id) {
                $error = $this->visit((int) $id, $command, $args, $recorded_only);
                if (is_wp_error($error)) {
                    WP_CLI::error($error->get_error_message());
                }
            }
            $offset += 50;
            $more = 50 === count($ids);
        } while ($more);
        return true;
    }

    /**
     * @param int    $id Site.
     * @param string $command Operation.
     * @param array  $args Arguments.
     * @param bool   $recorded_only Leave sites without a template record alone.
     * @return true|WP_Error
     */
    private function visit($id, $command, $args, $recorded_only) {
        switch_to_blog($id);
        try {
            WP_CLI::log('Site ' . $id);
            if ($recorded_only && !get_option(SEOProStack_Site_Templates::OPTION, array())) {
                WP_CLI::log('No template record; untouched.');
                return true;
            }
            return $this->site($command, $args);
        } finally {
            restore_current_blog();
        }
    }

    /** @param string $command Operation. @param array $args Arguments. @return true|WP_Error */
    private function site($command, $args) {
        if ('list' === $command) {
            return $this->list_templates();
        }
        if ('undo' === $command) {
            return $this->undo_site();
        }
        $record = get_option(SEOProStack_Site_Templates::OPTION, array());
        if ('status' === $command && !$record) {
            WP_CLI::log('No template record; untouched.');
            return true;
        }
        if ('status' === $command) {
            $this->show_status($record);
        }
        $slug = $args[0] ?? ($record['slug'] ?? '');
        if (!$slug) {
            return new WP_Error('template_slug', 'Name a template slug to initialise this site.');
        }
        return $this->run($command, $slug);
    }

    /** @return true */
    private function list_templates() {
        $rows = array();
        foreach (SEOProStack_Site_Templates::manifests() as $slug => $manifest) {
            $rows[] = array('slug' => $slug, 'name' => $manifest['name'], 'version' => $manifest['version']);
        }
        WP_CLI\Utils\format_items('table', $rows, array('slug', 'name', 'version'));
        return true;
    }

    /** @param array $record This site's template record. */
    private function show_status($record) {
        WP_CLI::log('Template: ' . ($record['slug'] ?? '') . '; applied version: ' . ($record['version'] ?? 'pending'));
        WP_CLI::log('Automatic updates: ' . (SEOProStack_Settings::get(SEOProStack_Site_Templates::KEY) ? 'on' : 'off'));
        if (!empty($record['error'])) {
            WP_CLI::warning($record['error']);
        }
        if (!empty($record['last_result'])) {
            WP_CLI::log('Last run: ' . (string) wp_json_encode($record['last_result']));
        }
    }

    /** @return true|WP_Error */
    private function undo_site() {
        $result = SEOProStack_Site_Templates::undo();
        if (!is_wp_error($result)) {
            WP_CLI::success('Last template run undone; newly created posts are retained as drafts.');
        }
        return $result;
    }

    /**
     * Compare, or apply every batch, and print each batch's rows.
     *
     * @param string $command Operation: apply writes, status and diff compare.
     * @param string $slug Template.
     * @return true|WP_Error
     */
    private function run($command, $slug) {
        do {
            $result = SEOProStack_Site_Templates::run($slug, 'apply' !== $command);
            if (is_wp_error($result)) {
                return $result;
            }
            WP_CLI\Utils\format_items('table', $result['rows'], array('part', 'status'));
        } while ($result['pending']);
        if ('apply' === $command) {
            update_option(SEOProStack_Site_Templates::SEEN, SEOPROSTACK_VERSION, false);
            WP_CLI::success('Template applied. Edited and removed site content was kept.');
        }
        return true;
    }
}
