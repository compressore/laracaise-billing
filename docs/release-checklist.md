# Release Checklist

Use this checklist before tagging any release. Complete every item before pushing the tag.

---

## Every release

- [ ] All tests pass locally: `composer test`
- [ ] PHPStan reports 0 errors: `composer analyse`
- [ ] Pint reports no formatting issues: `composer format -- --test`
- [ ] CI is green on `main` (all three jobs: Pest, PHPStan, Pint)
- [ ] `CHANGELOG.md` has a dated entry for the new version
- [ ] The new version entry lists all breaking changes, new features, and fixes
- [ ] The compare links at the bottom of `CHANGELOG.md` are updated

---

## Before tagging v1.0.0 (stable public API)

### Static analysis
- [ ] `composer analyse` reports 0 errors
- [ ] All `ignoreErrors` entries in `phpstan.neon` have a comment explaining why the suppression is necessary

### Testing
- [ ] `composer test` passes with all 279+ tests green
- [ ] `composer test:coverage` shows ≥ 90% overall coverage (requires Xdebug or PCOV)
- [ ] Grace-period paths, suspension paths, and feature-gate paths all have test coverage

### Configuration
- [ ] `minimum-stability` in `composer.json` is changed from `dev` to `stable`
- [ ] All `require` and `require-dev` packages have stable releases available
- [ ] `composer.json` version constraints are tightened where appropriate
- [ ] `composer validate` passes without warnings

### Documentation
- [ ] README "Work in progress" banner is removed
- [ ] README Quick Start section matches actual package API
- [ ] All code examples in `docs/` have been tested against the current codebase
- [ ] `docs/public-api.md` reflects all public methods on `BillingContext`
- [ ] `docs/integration-guides.md` examples produce working output
- [ ] `CHANGELOG.md` is complete from v0.1.0 to v1.0.0

### Package metadata
- [ ] `composer.json` `homepage` points to the correct GitHub repo
- [ ] `composer.json` `support.issues` URL is reachable
- [ ] Author name and email are correct
- [ ] Keywords are accurate and help discoverability on Packagist

### Security
- [ ] No secrets, credentials, or test keys are committed
- [ ] `CONTRIBUTING.md` references the correct security contact email
- [ ] README security section references the correct security contact email

### Tagging
- [ ] Merge to `main` and confirm CI passes
- [ ] Tag: `git tag v1.0.0`
- [ ] Push tag: `git push origin v1.0.0`
- [ ] Create GitHub Release from the tag and paste the `CHANGELOG.md` entry

---

## After publishing to Packagist

- [ ] Verify Packagist listing shows the correct description, keywords, and version
- [ ] Install into a fresh Laravel 12 app using `composer require laracaise/billing` and follow the README — it should work end-to-end without reading any other file first
- [ ] Confirm the GitHub Actions badge in the README links to the correct workflow
