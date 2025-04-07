# WP Allstars Plugin Development Roadmap

This document outlines features from later versions that we plan to integrate into our stable development branch. The goal is to incrementally implement these features in a stable manner, without introducing critical errors.

## Feature Backlog

| Feature | Source Version | Priority | Complexity | Risk | Status |
|---------|----------------|----------|------------|------|--------|
| Multisite Category | v0.2.6 | High | Low | Low | ✅ Implemented in v0.2.3-stable |
| Sync Guard | v0.2.6-fix | High | Low | Low | 🔄 To be implemented |
| More Robust File Loading | v0.2.6-fix | High | Low | Low | ✅ Implemented in v0.2.3-stable |
| Enhanced Admin UI | v0.2.4+ | Medium | Medium | Medium | 🔄 To be implemented |
| Plugin Dashboard | v0.2.6-fix | Medium | Medium | Medium | 🔄 To be implemented |
| New Category Pages | v0.2.4+ | Medium | Low | Low | 🔄 To be implemented |

## Implementation Strategy

### Multisite Category with Network Plugin Auditor
- **Description**: Add a "Multisite" filter after Advanced and include network-plugin-auditor
- **Why it failed before**: Didn't properly update all required files for the feature
- **Implementation plan**: Add to free-plugins.php and update class-free-plugins-manager.php to display the category correctly
- **Testing criteria**: Verify category appears in UI and plugin can be installed

### Sync Guard
- **Description**: Prevent plugin loading during rsync operations to avoid partial file loading
- **Why it failed before**: Added to wrong location, dependent on missing files
- **Implementation plan**: 
  1. Create a simpler version of the sync guard
  2. Update post-commit hook to handle sync operations better
  3. Use file flag to signal when sync is in progress
- **Testing criteria**: Verify plugin doesn't attempt to load during sync operations

### Enhanced Admin UI
- **Description**: Improve the admin interface with better styling and more intuitive navigation
- **Why it failed before**: Too many changes at once without proper testing
- **Implementation plan**:
  1. Small, incremental UI improvements
  2. Focus on one component at a time
  3. Thorough testing after each change
- **Testing criteria**: UI changes shouldn't affect functionality, should be responsive

## Development Order

The suggested implementation order is:

1. Sync Guard 
2. Enhanced Admin UI (component by component)
3. Plugin Dashboard improvements
4. New Category Pages

Each feature should be developed in its own branch and only merged after thorough testing. 