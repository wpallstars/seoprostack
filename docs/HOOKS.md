<!-- SPDX-License-Identifier: GPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: 2026 Marcus Quinn -->
# SEO Pro Stack hooks

Every `seoprostack_*` filter and action in the code, for site customisations
(SEO Pro Custom and other site code). `scripts/check-hooks.mjs` (run by
`.github/workflows/hooks.yml` and before a release) fails when this list and the code disagree.

## Status

- **supported**: for site code. Kept, or kept working through
  `apply_filters_deprecated()` / `do_action_deprecated()`, for at least two
  minor releases before it is removed (Deprecation rule below).
- **internal**: no stability promise. For SEO Pro Stack's own use (other
  plugins in the stack, tests, presets), or a filter for site owners that is
  unsupported (such as `seoprostack_used_css_sources`). May change or go
  without notice.

## Deprecation rule

A supported hook is never removed or renamed in one step:

1. Keep it, or keep the old name working by calling the new hook's old name
   through `apply_filters_deprecated()` / `do_action_deprecated()` (with the
   version and the replacement), for at least two minor releases.
2. Name the hook, the replacement and the release it will go in the
   changelog (`changelog.txt`) when it is deprecated and again when removed.
3. When it is gone, delete its row here in the same change. An internal hook
   may be removed at once, with its row.

The check keeps this honest: a supported hook in this list that is neither in
the code nor called through a `_deprecated` function fails, and so does a
`seoprostack_*` hook in the code that is not listed. Mark a hook deprecated
by leaving its row here with the status `supported` until the removal release.

## Adding a hook

Add the row in the same change as the code, in alphabetical order, with the
status `internal` unless site code is meant to use it. Columns:

- **Hook**: the name, in backticks.
- **Type**: `filter` (returns the first argument, changed or not) or `action`
  (returns nothing).
- **Status**: `supported` or `internal`.
- **Since**: the first release that has the hook.
- **Feature**: the file (feature) that calls it.
- **Arguments**: for a filter, the value filtered first, then the extra
  arguments; for an action, all arguments. Written as at the call.

README.md → Extending describes the most used ones in words.

## Hooks

| Hook | Type | Status | Since | Feature | Arguments |
|---|---|---|---|---|---|
| `seoprostack_admin_bar_more_items` | filter | supported | 0.5.0 | admin bar more | `$move`; `$wp_admin_bar` |
| `seoprostack_admin_bar_star` | filter | supported | 0.5.0 | admin bar | `true` |
| `seoprostack_admin_dark_contrast_guard` | filter | supported | 1.3.35 | admin whole theme | `true`; `$screen` |
| `seoprostack_admin_dark_fallback` | filter | supported | 1.3.45 | dark fallback | `true`; `$screen` |
| `seoprostack_admin_dark_fallback_style` | filter | supported | 1.3.45 | dark fallback | `bool`, true unless the file matches `self::OWN_DARK` (`!preg_match(self::OWN_DARK, wp_normalize_path($file))`); `$handle`, `$href` |
| `seoprostack_admin_dark_screen` | filter | supported | 1.3.33 | admin whole theme | `$applies`; `$screen` |
| `seoprostack_admin_dark_script_styles` | filter | supported | 1.3.42 | admin whole theme | `$tutor \|\| $meow`; `$screen` |
| `seoprostack_admin_dark_standalone_styles` | filter | internal | 1.3.36 | admin whole theme | `array('rank-math-wizard')` |
| `seoprostack_admin_enqueue` | action | supported | 0.11.1 | admin manager | `$page_tab` |
| `seoprostack_admin_menu_catalog` | filter | supported | 0.5.0 | admin menu | `$data` |
| `seoprostack_admin_script_data` | filter | supported | 0.11.0 | admin manager | `array{ajaxUrl: string, nonce: string, tab: string, tabs: array, titles: array, network: bool, i18n: array<string,string>}`; `$tab` |
| `seoprostack_admin_script_deps` | filter | supported | 0.11.0 | admin manager | `$deps`; `$page_tab` |
| `seoprostack_admin_tabs` | filter | supported | 0.3.0 | admin manager | `$tabs` |
| `seoprostack_admin_theme_screens` | filter | internal | 1.3.43 | admin theme | `array( SEOProStack_Admin_Manager::hook(), SEOProStack_Network_Manager::hook() )` (screen IDs) |
| `seoprostack_agency_order_started` | action | supported | 0.9.0 | agency orders | `$entry_id`, `$order['task']`, `$order['ticket']`, `$order['user']` |
| `seoprostack_agency_update_message` | filter | supported | 0.9.0 | agency orders | `$message`; `$task`, `$stage`, `$order` |
| `seoprostack_ai_engine_later` | filter | supported | 1.3.161 | ai engine later | `true` |
| `seoprostack_auto_upload_limit` | filter | supported | 0.3.0 | auto upload | `10` |
| `seoprostack_auto_upload_process_post` | filter | supported | 0.3.0 | auto upload | `true`; `$data` |
| `seoprostack_block_visible` | filter | supported | 0.11.0 | restrict content | `$visible`; `$block`, `$rule` |
| `seoprostack_can_change_settings` | filter | supported | 0.5.0 | settings | `true` |
| `seoprostack_can_see_content` | filter | supported | 0.11.0 | restrict content | `$allowed`; `$post_id`, `$rules` |
| `seoprostack_cloudflare_edge_time` | filter | supported | 1.3.204 | cloudflare purge | `(int) $seconds` |
| `seoprostack_cloudflare_purge_post_urls` | filter | supported | 1.3.203 | cloudflare purge | `$files`; `$post` |
| `seoprostack_core_plugins` | filter | supported | 1.3.106 | core plugins | `$plugins` |
| `seoprostack_crawler_sitemap` | filter | supported | 1.3.19 | litespeed | `is_string($url) ? $url : ''` |
| `seoprostack_dashboard_layout` | filter | supported | 0.5.0 | dashboard layout | `is_array($data) ? $data : array()` |
| `seoprostack_dashboard_redirect` | filter | internal | 0.3.0 | admin access | `$target ? $target : home_url('/')`; `wp_get_current_user()` |
| `seoprostack_duplicate_post_data` | filter | supported | 0.3.0 | duplicate posts | `$data`; `$post` |
| `seoprostack_duplicate_skip_meta` | filter | supported | 0.3.0 | duplicate posts | `string[]` meta keys, by default `_edit_lock`, `_edit_last`, `_wp_old_slug`, `_wp_old_date`, `_thumbnail_id`, `_wp_page_template`, `_wp_trash_meta_status`, `_wp_trash_meta_time`, `_encloseme` and more (see the call) |
| `seoprostack_editor_dev_panels` | filter | supported | 1.3.41 | editor dev tab | `self::PANELS` |
| `seoprostack_email_protection_skip` | filter | supported | 1.3.85 | email protection | `false`; `$html` |
| `seoprostack_external_plugins` | filter | supported | 0.5.0 | plugin manager | `array()` |
| `seoprostack_features` | filter | supported | 0.3.0 | seoprostack | `self::$core_features` |
| `seoprostack_field_search_field` | filter | supported | 1.3.38 | field search | `!$listed`; `$field`, `$context` |
| `seoprostack_fluentforms_later` | filter | supported | 1.3.190 | fluentforms later | `true` |
| `seoprostack_font_fallbacks` | filter | supported | 1.3.177 | font fallbacks | `$out` |
| `seoprostack_form_policy_paths` | filter | supported | 1.3.97 | form policy links | `$paths` |
| `seoprostack_form_submitted` | action | supported | 1.3.97 | form keys | `$key`, `$data`, `$form`, `(int) $entry_id` |
| `seoprostack_free_plugin_note` | filter | supported | 0.11.0 | plugin manager | `$note`; `$slug` |
| `seoprostack_free_plugins` | filter | supported | 0.5.0 | free plugins | `array<string,string[]>` category => plugin slugs (`minimal`, `admin`, ...) |
| `seoprostack_github_plugins` | filter | supported | 0.7.0 | github updates | `(array) $plugins` |
| `seoprostack_github_token` | filter | supported | 0.7.0 | github updates | `$token`; `$repo` |
| `seoprostack_hand_ordered` | action | supported | 0.9.0 | hand order | `'post'`, `$type`, `array_keys($changed)` |
| `seoprostack_helpdesk_imap_password` | filter | supported | 1.3.118 | helpdesk imap config | `$password` |
| `seoprostack_host_plugins` | filter | supported | 0.12.1 | host plugins | `$plugins` |
| `seoprostack_hosting_items` | filter | supported | 0.5.0 | link cards | `array` directory entries; built as `seoprostack_{$id}_items` |
| `seoprostack_hosting_sample_rate` | filter | supported | 0.5.0 | hosting needs | `self::SAMPLE` |
| `seoprostack_image_imported` | action | supported | 0.3.0 | auto upload | `$attachment_id`, `$url`, `$post_id` |
| `seoprostack_image_loading_exclude` | filter | supported | 0.12.2 | image loading | `$keywords` |
| `seoprostack_image_upload_error` | action | supported | 0.3.0 | auto upload | `$url`, `$error` |
| `seoprostack_integrity_ignore` | filter | supported | 1.3.56 | integrity scan | `array()` |
| `seoprostack_is_developer` | filter | supported | 0.5.0 | developers | `$cache[$cache_key]`; `$user_id` |
| `seoprostack_is_writer` | filter | supported | 0.10.0 | admin menu | `$writer`; `$user_id` |
| `seoprostack_jquery_footer` | filter | supported | 1.3.165 | jquery footer | `true` |
| `seoprostack_kadence_bg_sizes` | filter | supported | 1.3.171 | kadence rows | `true`; `$block` |
| `seoprostack_kadence_bg_slider_width` | filter | supported | 1.3.170 | kadence rows | `true`; `$block` |
| `seoprostack_kadence_column_sizes` | filter | supported | 1.3.198 | kadence column sizes | `true`; `$post` |
| `seoprostack_kadence_column_wait` | filter | supported | 1.3.171 | kadence rows | `true`; `$block` |
| `seoprostack_kadence_custom_css` | filter | supported | 1.3.51 | kadence colour check | `$sources` |
| `seoprostack_kadence_head_preload` | filter | supported | 1.3.171 | kadence early | `true`; `$attrs` |
| `seoprostack_kadence_hero_sizes` | filter | supported | 1.3.150 | kadence hero | `true`; `$post` |
| `seoprostack_kadence_row_wait` | filter | supported | 1.3.168 | kadence rows | `true`; `$block` |
| `seoprostack_kadence_scrollbar` | filter | supported | 1.3.204 | kadence scrollbar | `true` |
| `seoprostack_kadence_slider_markup` | filter | supported | 1.3.205 | kadence slider markup | `true` |
| `seoprostack_kadence_slider_sizes` | filter | supported | 1.3.169 | kadence slider | `true`; `$block` |
| `seoprostack_kadence_slider_wait` | filter | supported | 1.3.166 | kadence slider | `true`; `$block` |
| `seoprostack_keep_cached_max` | filter | supported | 1.3.194 | keep cached pages | `$max` |
| `seoprostack_keep_cached_slots` | filter | supported | 1.3.194 | keep cached queue | `self::SLOTS` |
| `seoprostack_keep_cached_slow` | filter | supported | 1.3.194 | keep pages cached | `self::SLOW` |
| `seoprostack_language_flag` | filter | supported | 1.3.138 | language switcher | `$country`; `$locale` |
| `seoprostack_language_switcher_skip` | filter | supported | 1.3.142 | language switcher | `false` |
| `seoprostack_licence_call` | filter | supported | 0.7.0 | licence calls | `$is`; `$url`, `$args` |
| `seoprostack_link_health_provider` | filter | supported | 0.11.10 | link index | `$provider` |
| `seoprostack_link_suggestion_candidates` | filter | internal | 0.11.10 | link suggestions | `array_slice($candidates, 0, 80)`; `$post`, `$direction` |
| `seoprostack_lists_actions` | filter | supported | 1.3.63 | post reactions | `$actions`; `$context` |
| `seoprostack_lists_login_trigger` | filter | supported | 1.3.68 | post reactions | `'.header-account-button[data-toggle-target]'` |
| `seoprostack_lists_login_url` | filter | supported | 1.3.63 | post reactions | `wp_login_url()` |
| `seoprostack_lists_page` | filter | supported | 1.3.68 | reaction access | `(int) $cached` |
| `seoprostack_lists_register_url` | filter | supported | 1.3.63 | post reactions | `$register` |
| `seoprostack_litespeed_crawler_duration` | filter | supported | 1.3.19 | plugin fixes | `self::LSC_CRAWLER_DURATION` |
| `seoprostack_magic_login_allowed` | filter | supported | 0.3.0 | magic login | `$allowed`; `$user` |
| `seoprostack_magic_login_email` | filter | supported | 0.3.0 | magic login | `array{to: string, subject: string, message: string, headers: array}` (all four keys are used by `wp_mail()`, keep them); `$user`, `$link` |
| `seoprostack_magic_login_ip_limit` | filter | supported | 0.3.0 | magic login | `self::IP_LIMIT` |
| `seoprostack_magic_login_link_sent` | action | supported | 0.3.0 | magic login | `$user`, `$sent` |
| `seoprostack_maintenance_capability` | filter | supported | 0.8.0 | maintenance | `'manage_options'` |
| `seoprostack_maps_later` | filter | supported | 1.3.190 | maps later | `true` |
| `seoprostack_media_files_changed` | action | supported | 1.3.37 | nextgen images | `$attachment_id` |
| `seoprostack_media_replaced` | action | supported | 0.4.0 | replace media | `$attachment_id`, `$old_file`, `$new_file`, `$posts` |
| `seoprostack_media_storage_batch_limit` | filter | supported | 1.3.144 | media storage | `$limit` |
| `seoprostack_media_storage_batch_requests` | filter | supported | 1.3.144 | media storage | `min(64, 4 * $concurrency)` |
| `seoprostack_media_storage_cache_control` | filter | supported | 1.3.37 | media storage | `'public, max-age=31536000, immutable'` |
| `seoprostack_media_storage_concurrency` | filter | supported | 1.3.54 | media storage | `20` |
| `seoprostack_media_storage_group_bytes` | filter | supported | 1.3.87 | media storage | `32 * MB_IN_BYTES` |
| `seoprostack_media_storage_parallel_pictures` | filter | supported | 1.3.144 | media storage | `5` |
| `seoprostack_menu_item_visible` | filter | supported | 0.9.0 | menu visibility | `$visible`; `$item`, `$rule` |
| `seoprostack_modal_links_selectors` | filter | supported | 1.3.82 | modal links | `array()` |
| `seoprostack_network_plugins_keep` | filter | supported | 0.14.0 | network plugins | `$keep`; `$network` |
| `seoprostack_network_setting_panel` | action | supported | 1.3.41 | settings manager | `$key`, `$field` |
| `seoprostack_network_settings_tab_after` | action | supported | 1.3.41 | settings manager | `$tab` |
| `seoprostack_nextgen_avif_speed` | filter | supported | 1.3.147 | nextgen images | `7` |
| `seoprostack_nextgen_runners` | filter | supported | 1.3.149 | nextgen images | `1` |
| `seoprostack_outbound_never_block` | filter | supported | 1.2.0 | outbound calls | `$never`; `$host`, `$source` |
| `seoprostack_page_cache_purge` | action | supported | 1.3.77 | page cache | `SEOPROSTACK_VERSION` |
| `seoprostack_page_cache_purge_post` | action | supported | 1.3.89 | page cache | `$id` |
| `seoprostack_plugin_preset_changed` | action | supported | 0.10.1 | presets | `$slug`, `$names` |
| `seoprostack_plugin_presets` | filter | supported | 0.6.0 | presets | `$presets` |
| `seoprostack_plugins_skipped` | filter | supported | 0.11.0 | feature | `false` |
| `seoprostack_post_duplicated` | action | supported | 0.3.0 | duplicate posts | `$new_id`, `$post` |
| `seoprostack_post_queued` | action | supported | 0.3.0 | post scheduler | `$post_id`, `$slot` |
| `seoprostack_post_scheduler_applies` | filter | supported | 0.3.0 | post scheduler | `true`; `$data`, `$postarr` |
| `seoprostack_post_type_switched` | action | supported | 0.9.0 | post type switch | `(int) $post_id`, `$post->post_type`, `$from` |
| `seoprostack_preset_condition` | filter | supported | 0.10.1 | presets | `$holds`; `$condition` |
| `seoprostack_preset_store` | filter | supported | 0.12.8 | presets | `null`; `$slug`, `$name`, `$value`. Return `true` when your code saved the value, or a `WP_Error` to stop |
| `seoprostack_preset_write_check` | filter | supported | 0.12.8 | presets | `null`; `$slug`. Return a `WP_Error` to stop the write |
| `seoprostack_pro_items` | filter | supported | 0.5.0 | link cards | `array` directory entries; built as `seoprostack_{$id}_items` |
| `seoprostack_quick_404` | filter | supported | 1.3.199 | quick 404 | `self::is_file_path($path)`; `$path` |
| `seoprostack_redirect_links` | filter | supported | 1.3.106 | redirect links | `(array) $map['exact']` |
| `seoprostack_redundant_plugins` | filter | supported | 1.3.133 | replaced plugins | `array()` |
| `seoprostack_related_posts_args` | filter | internal | 0.12.0 | term displays | `$args`; `$post` |
| `seoprostack_replaced_plugin_extras` | filter | supported | 0.8.0 | replaced plugins | `array()`; `$slug` |
| `seoprostack_replaces_link` | filter | internal | 1.3.60 | settings manager | `$link`; `(string) $slug`, `$site[$slug]`, `(string) $name` |
| `seoprostack_restored_menu_capability` | filter | supported | 1.3.70 | plugin loading | `$item[1]`; `(string) $item[2]` |
| `seoprostack_robots_txt_ai_crawlers` | filter | supported | 1.3.197 | robots.txt | `self::AI_TRAINING` user-agent tokens |
| `seoprostack_robots_txt_rules` | filter | supported | 1.3.197 | robots.txt | `$rules` [directive, path] pairs |
| `seoprostack_robots_txt_sitemap` | filter | supported | 1.3.197 | robots.txt | `SEOProStack_Litespeed::sitemap_index()` URL, or `''` |
| `seoprostack_saved_item` | filter | supported | 1.3.58 | post reactions | `$item`; `$p`, `$list` |
| `seoprostack_saved_panel_actions` | filter | supported | 1.3.58 | post reactions | `''`; `'shortlist'` |
| `seoprostack_screenshot_failed` | action | supported | 0.4.0 | screenshots | `$url`, `$result` |
| `seoprostack_screenshot_request` | filter | supported | 0.4.0 | screenshots | `$request`; `$url`, `$width`, `$height`, `$service` |
| `seoprostack_screenshot_saved` | action | supported | 0.4.0 | screenshots | `$result`, `$url`, `$post_id` |
| `seoprostack_setting_panel` | action | supported | 0.4.0 | settings manager | `$key`, `$field` |
| `seoprostack_setting_saved` | action | supported | 0.3.0 | network sites | `$key`, `$value` |
| `seoprostack_settings_changed` | action | supported | 1.3.41 | network sites | `$old`, `$new` |
| `seoprostack_settings_schema` | filter | supported | 0.3.0 | settings | `$schema` |
| `seoprostack_settings_tab_after` | action | supported | 0.9.0 | settings manager | `$tab` |
| `seoprostack_short_address_types` | filter | supported | 1.3.30 | remove cpt base | `self::chosen()` |
| `seoprostack_short_link_count_click` | filter | supported | 0.4.0 | short links | `true`; `$link['id']` |
| `seoprostack_short_link_target` | filter | supported | 0.4.0 | short links | `$link['url']`; `$link['id']` |
| `seoprostack_shortlist_max` | filter | supported | 1.3.58 | reaction lists | `50` |
| `seoprostack_starters` | filter | supported | 0.7.0 | starters | `$starters` |
| `seoprostack_stored_active_plugins` | filter | supported | 0.11.0 | feature | `$files` |
| `seoprostack_switchable_post_types` | filter | supported | 0.9.0 | post type switch | `$types` |
| `seoprostack_taxopress_taxonomy_args` | filter | internal | 0.12.0 | term legacy | `$args`; `$name`, `$tax` |
| `seoprostack_term_list_args` | filter | supported | 0.4.0 | term list | `$args`; `$a` |
| `seoprostack_term_merged` | action | supported | 0.9.0 | term tools | `$target`, `$term` |
| `seoprostack_terms_moved` | action | supported | 0.9.0 | term tools | `array_keys($moving)`, `$to`, `$from` |
| `seoprostack_translatepress_defer` | filter | supported | 1.3.195 | translatepress defer | `true` |
| `seoprostack_tools_items` | filter | supported | 0.5.0 | link cards | `array` directory entries; built as `seoprostack_{$id}_items` |
| `seoprostack_troubleshooting_open` | filter | supported | 1.3.8 | settings manager | `false`; `$key` |
| `seoprostack_turnstile_later` | filter | supported | 1.3.163 | turnstile later | `true` |
| `seoprostack_tutor_light_page` | filter | supported | 1.3.161 | tutor light page | `$page` |
| `seoprostack_used_css_classes` | filter | supported | 1.3.145 | used css match | `self::CLASSES` |
| `seoprostack_used_css_sources` | filter | internal | 1.3.145 | used css links | `string[]` folders with a trailing slash |
| `seoprostack_version_published` | action | supported | 0.3.0 | post versions | `$original->ID`, `$post_id` |
| `seoprostack_vulnerability_feed_slugs` | filter | supported | 1.3.57 | vulnerability feed check | `array{plugin: array<string,string>, theme: array<string,string>}` folder => feed slug (default `plugin` => `hello` => `hello-dolly`, `theme` => empty) |
| `seoprostack_watermark_attachment` | filter | supported | 0.4.0 | watermark images | `true`; `$attachment_id` |
| `seoprostack_watermark_runners` | filter | supported | 1.3.149 | watermark images | `1` |
| `seoprostack_woo_light_shop_page` | filter | supported | 0.8.0 | woo light | `$shop` |
| `seoprostack_writer_hides_page` | filter | supported | 0.10.0 | admin menu | `$hide`; `$slug`, `$cap` |
| `seoprostack_writer_hides_post_type` | filter | supported | 0.10.0 | admin menu | `$hide`; `$type` |
