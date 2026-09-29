# Allstars Plugin Development Workflow

This document outlines the development workflow for the Allstars Plugin to ensure stable and reliable feature implementation.

## Development Principles

1. **Stability First**: The primary goal is maintaining a stable plugin that works reliably
2. **Incremental Changes**: Implement changes in small, manageable increments
3. **Complete Testing**: Every change must be thoroughly tested before integration
4. **Documentation**: All features and changes must be well-documented

## Branch Structure

- `main` - Production-ready code, always stable
- `v0.2.3-stable` - Our current stable development branch
- `feature/v0.2.3-stable/{feature-name}` - Feature branches for new development

## Development Workflow

### 1. Feature Planning

1. Identify a feature from the ROADMAP.md file to implement
2. Review any existing implementation in unstable versions
3. Document the implementation plan in ROADMAP.md
4. Create a new feature branch from the stable base

```bash
git checkout v0.2.3-stable
git checkout -b feature/v0.2.3-stable/sync-guard
```

### 2. Feature Implementation

1. Start with the smallest possible functional change
2. Commit frequently with descriptive commit messages including stability classification
3. Example commit message format:
   ```
   [EXPERIMENTAL] Add basic sync guard detection functionality
   
   - Adds file existence check for .syncing flag
   - Implements conditional loading based on flag
   - Does not yet handle admin notices
   ```

4. Reference the ROADMAP.md and TESTING.md documents while implementing

### 3. Testing

1. Complete all relevant tests from TESTING.md
2. Add feature-specific tests if needed
3. Test in a clean WordPress environment
4. Test with WP_DEBUG enabled
5. Document any issues found and fix them

### 4. Code Review

1. Self-review code for:
   - PHP best practices
   - WordPress coding standards
   - Security considerations
   - Performance implications
   - Error handling

2. Consider peer review if possible

### 5. Integration

1. Create a pull request to merge into the stable branch
2. Summarize changes, testing performed, and any caveats
3. Once approved, merge using `--no-ff` to preserve feature history

```bash
git checkout v0.2.3-stable
git merge --no-ff feature/v0.2.3-stable/sync-guard
```

4. Tag new version if appropriate:

```bash
git tag v0.2.3.1-stable
git push origin v0.2.3.1-stable
```

5. Update STABILITY.md with the new version information

### 6. Post-Integration

1. Deploy to test environment and confirm functionality
2. Update ROADMAP.md to reflect the implemented feature
3. Clean up feature branch if no longer needed

## Handling Unstable Code References

When examining code from unstable versions:

1. **Never copy-paste directly** - Understand the approach and reimplement
2. **Isolate problematic code** - Identify why it might have failed
3. **Take the best ideas** - Implement the concept, not the exact implementation
4. **Document the reference** - Note which version inspired each implementation

## Versioning Scheme

- `vX.Y.Z` - Major.Minor.Patch
- `vX.Y.Z-stable` - Stable development branches
- `vX.Y.Z.N-stable` - Minor updates to stable branches

## Continuous Improvement

- Regularly review and update these development procedures
- Document lessons learned
- Improve testing procedures based on discoveries 