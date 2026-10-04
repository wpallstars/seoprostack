# SEO Pro Stack Plugin Testing Guide

This document provides testing checklist for the SEO Pro Stack Plugin to ensure stability and functionality.

## General Testing Guidelines

1. Test each feature in isolation before testing integrated functionality
2. Test on multiple WordPress versions (6.2+)
3. Test with different themes activated
4. Test with and without other plugins activated
5. Test on different screen sizes (desktop, tablet, mobile)

## Feature-Specific Testing

### Really Simple Security redirect preset

Use two fresh, isolated single-site Apache/LiteSpeed WordPress sites with Really Simple Security 9.8.3, trusted working HTTPS and an existing writable root `.htaccess`. Do not use the shared preview or live sites.

- [ ] Export fresh `rsssl_options`: activation stores nothing; compare after selecting **Redirect method → 301 .htaccess redirect (read instructions first)** in the plugin's own screen. Confirm only `redirect` enters the preset, with accurate labels/defaults.
- [ ] Enable Plugin presets and use the Plugins-screen dialog; confirm Apply selected setting, Reset and Undo change the preference and dedicated **Really Simple Security Redirect** block together.
- [ ] Run `wp --user=<administrator> seoprostack presets list`, `diff really-simple-ssl`, `apply really-simple-ssl --only=rsssl_options.redirect`, `reset really-simple-ssl` and `undo really-simple-ssl`. CLI needs a plugin-recognised server type; use its existing server override only for a verified test server.
- [ ] A second Apply changes zero settings, keeps the undo snapshot and does not duplicate rules or probe HTTPS.
- [ ] Change an unrelated security setting after Apply, then Undo: the newer unrelated setting and unrelated `.htaccess` blocks survive.
- [ ] HTTP paths with query strings return 301 to the same HTTPS path/query. Certificate validation succeeds, redirects terminate and login/admin remain accessible.
- [ ] Missing/inactive plugin, unsupported version/API/server, multisite, SSL disabled, unwritable/symlinked rule file and plugin-managed write lockout cause no preference/rule mutation. Explicit actions return an explanation.
- [ ] Broken/untrusted HTTPS fails before mutation. A native rule-save failure returns an error, reports whether rollback completed and does not replace or consume the previous undo snapshot. Retry after resolving the native writer error.
- [ ] Inspect the standard PHP/debug log for new SEO Pro Stack or affected-plugin diagnostics; run existing PHP lint, PHPCS, PHPStan and release preset metadata checks.

### Multisite Category

- [ ] Verify "Multisite" category appears in plugin list filters
- [ ] Verify plugins in the multisite category appear when the filter is selected
- [ ] Verify network-plugin-auditor appears in multisite category
- [ ] Test installation of network-plugin-auditor via the plugin interface

### Sync Guard

- [ ] Create a .syncing file in the plugin root directory
- [ ] Verify plugin displays "syncing" notice instead of loading
- [ ] Remove .syncing file
- [ ] Verify plugin loads normally after removing .syncing file

### Admin Colors Feature

- [ ] Verify Admin Colors toggle appears in settings
- [ ] Toggle the admin colors option to "Modern"
- [ ] Verify admin UI updates with modern colors
- [ ] Toggle back to "Default"
- [ ] Verify admin UI returns to default colors
- [ ] Test color switching on different pages of the admin
- [ ] Verify setting is saved when toggling

### Basic Admin UI Enhancements

- [ ] Verify basic UI components are properly styled (buttons, forms, etc.)
- [ ] Test responsive behavior on different screen sizes
- [ ] Verify card components display properly with headers and footers
- [ ] Test accordion functionality (expand/collapse)
- [ ] Verify notification components display properly with different types (success, error, warning)
- [ ] Test dismissible notifications
- [ ] Verify that UI components are properly styled across different WordPress admin pages
- [ ] Test keyboard accessibility for interactive components

## Integration Testing

- [ ] Verify all features work together without conflicts
- [ ] Test performance impact with all features enabled
- [ ] Verify plugin loads without errors on plugin activation
- [ ] Test deactivation and reactivation

## Browser Compatibility

Test on the following browsers:
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)

## Performance Testing

- [ ] Measure page load time before and after enabling the plugin
- [ ] Check for any JavaScript errors in browser console
- [ ] Verify CSS is properly cached
- [ ] Ensure assets are properly minified

## Version Upgrade Testing

When testing a new version:
- [ ] Test upgrading from previous version
- [ ] Verify settings are preserved during upgrade
- [ ] Check for any deprecation warnings or errors

## Security Testing

- [ ] Verify all admin pages are properly secured
- [ ] Check for proper escaping of output
- [ ] Verify nonce checks on form submissions
- [ ] Test with user roles other than administrator

## Final Pre-Release Checklist

- [ ] All tests passed on multiple environments
- [ ] Version numbers updated in all files
- [ ] Changelog updated
- [ ] Documentation reflects new features
- [ ] All scripts and styles properly enqueued
- [ ] `scripts/preflight-release.sh` and `scripts/plugin-check.sh` report no errors (steps: `RELEASING.md`; the WordPress.org checklist: `LAUNCH.md`)

## Regression Testing

After implementing new features, perform regression testing on previously implemented features to ensure they still function correctly.

## Pre-Merge Testing Checklist

### Basic Functionality Tests

- [ ] Plugin activates without errors
- [ ] Plugin deactivates without errors
- [ ] Admin menu appears correctly
- [ ] All submenu items load without errors
- [ ] Settings can be saved without errors
- [ ] Plugin works with WordPress debug mode enabled

### Feature-Specific Tests

Each new feature should have its own testing checklist added here.

#### Multisite Category Feature

- [ ] "Multisite" category appears in the correct location in the UI
- [ ] Network Plugin Auditor appears in the Multisite category
- [ ] Category filter works when clicked
- [ ] Plugin can be installed from the category

#### Sync Guard Feature

- [ ] Plugin doesn't load when .syncing file is present
- [ ] .syncing file is created during sync operations
- [ ] .syncing file is removed after sync completes
- [ ] User is notified when plugin is in sync mode

### Compatibility Tests

- [ ] Plugin works with latest WordPress version
- [ ] Plugin works with PHP 7.4+
- [ ] Plugin works with common themes (Twenty Twenty-Three, Kadence)
- [ ] Plugin co-exists with other popular plugins without conflicts

### Browser Compatibility

- [ ] UI works correctly in Chrome
- [ ] UI works correctly in Firefox
- [ ] UI works correctly in Safari
- [ ] UI works correctly in Edge

### Mobile Responsiveness

- [ ] Admin interface is usable on mobile devices
- [ ] No layout issues on small screens

## Testing Process

1. Create a clean WordPress installation for testing
2. Install and activate the plugin
3. Enable WordPress debug mode (WP_DEBUG = true)
4. Complete all tests in the checklist
5. Document any issues found
6. Fix issues and retest
7. Only mark as [STABLE] when all tests pass

## Continuous Integration

Every pull request runs syntax checks, WordPress Coding Standards,
PHPStan, the release preflight, Plugin Check and a smoke test on
WordPress 6.2 with PHP 7.4 and on the latest WordPress (`DEVELOPMENT.md` →
Checks). They do not replace the checks above: they catch PHP errors and
broken screens, not wrong behaviour.
