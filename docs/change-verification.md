# Change Verification Procedure

This document outlines the procedures for verifying changes in the `sp-laravel-api` package.

## 1. Manual Verification

Before running automated tests, perform these manual checks:

### Code Review
- [ ] Check for strict typing compliance.
- [ ] Verify variable naming conventions.
- [ ] Ensure no debug code (`dd`, `dump`) is left.
- [ ] Review comments for clarity.

### Configuration Check
- [ ] Validate `config/record.php` structure.
- [ ] Ensure new configuration keys have default values.

### Local Runtime Check
- [ ] Spin up the example application (`sp-laravel-api-example`).
- [ ] Manually hit modified endpoints using Postman or curl.
- [ ] Verify log output for errors or warnings.

## 2. Automated Testing Requirements

All changes must pass the following test suites:

### Unit Tests
*   **Location:** `tests/Unit`
*   **Command:** `composer test -- --testsuite=Unit`
*   **Coverage:** 100% path coverage for new logic.

### Feature Tests
*   **Location:** `tests/Feature`
*   **Command:** `composer test -- --testsuite=Feature`
*   **Focus:** API endpoints, database interactions, integration flow.

### Integration Tests (Example App)
*   **Location:** `../sp-laravel-api-example`
*   **Command:** `php artisan test`
*   **Purpose:** Verifies the package works correctly when installed in a Laravel application.

## 3. Version Control Integration

### Branching Strategy
*   **Feature Branches:** `feature/description-of-change`
*   **Bug Fixes:** `fix/issue-description`
*   **Hotfixes:** `hotfix/critical-issue`

### Commit Messages
*   Follow Conventional Commits (e.g., `feat: add new filter operator`, `fix: resolve N+1 query`).

### Pull Request Process
1.  Create PR against `main` or `develop`.
2.  Ensure CI pipeline passes (Lint, Analyze, Test).
3.  Request review from a senior developer.
4.  Squash and merge upon approval.
