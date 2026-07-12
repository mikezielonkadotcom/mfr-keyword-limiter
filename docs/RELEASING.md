# Releasing

Releases are automated from `vX.Y.Z` tags. The workflow builds and validates a
versioned plugin ZIP, creates a GitHub Release, publishes the same ZIP and full
plugin metadata to `https://updatemachine.com/api/admin/plugins`, claims the MZV
route, and verifies the flat manifest at
`https://updatemachine.com/mfr-keyword-limiter/update.json`.

Before tagging:

1. Update the plugin header version, `MFR_KEYWORD_LIMITER_VERSION`, the readme
   stable tag, and changelog together.
2. Run `bash scripts/validate-release.sh`.
3. Build and inspect the final package with `bash scripts/build-release.sh` and
   `bash scripts/validate-release.sh --zip release/mfr-keyword-limiter-X.Y.Z.zip`.
4. Confirm the repository has an `UM_ADMIN_TOKEN` Actions secret. The plugin is
   free and uses zero-config registration; no license or site key is required.
5. Push an annotated `vX.Y.Z` tag and monitor the Release workflow.

The committed `includes/um-updater.php` is the canonical release copy. Release
automation never downloads or syncs updater code from another repository.
