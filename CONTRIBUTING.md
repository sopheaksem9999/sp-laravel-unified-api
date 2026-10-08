# Contributing to SP Laravel Unified API

Thank you for contributing to **SP Laravel Unified API** (`sopheak/sp-laravel-api`)! This guide details our development workflow, coding standards, CI/CD automation, and release publishing process.

---

## 🚀 Getting Started

### Prerequisites

- **PHP**: `^8.2`, `^8.3`, `^8.4`, or `^8.5` (PHP 8.3+ is required for the AI SDK record tools)
- **Laravel Framework**: `^12.0` or `^13.0`
- **Composer**: `2.2` or higher
- **Databases**: MySQL 8.0+, PostgreSQL 13+, or SQLite (in-memory SQLite `:memory:` is used for PHPUnit test suites)
- **Optional Dependencies**:
  - `aws/aws-sdk-php` & `league/flysystem-aws-s3-v3`: Required for S3/Cloudflare R2 presigned and multipart uploads.
  - `laravel/ai` (^1.0.1): Required for AI SDK record tools.
  - `laravel/mcp` (^1.0.1): Required for the opt-in Laravel MCP driver (`record.mcp.driver = 'laravel'`).
  - `redis`: Optional, for caching and queue workers.

### Development Setup

1. **Clone the Repository**
   ```bash
   git clone git@github.com:sopheaksem9999/sp-laravel-unified-api.git
   cd sp-laravel-api
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Verify Tests & Quality Pipeline**
   ```bash
   composer quality
   ```

---

## 🧪 Testing

We use **PHPUnit 10/11** along with **Orchestra Testbench 10/11** against an in-memory SQLite database (`:memory:`) with randomized test order.

### Running Tests

```bash
# Run all tests
composer test

# Run specific test suites
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Feature

# Run a single test file or filter
vendor/bin/phpunit tests/Feature/RecordCrudTest.php
vendor/bin/phpunit --filter=test_record_can_be_created

# Generate HTML coverage report (output to ./coverage)
composer test-coverage
```

### Writing Tests

- **Unit Tests (`tests/Unit/`)**: Test individual classes, helpers, converters, and types in isolation.
- **Feature Tests (`tests/Feature/`)**: Test end-to-end API requests, database interactions, authorization, audit logging, and tenant isolation.
- **Always add tests for core behavior changes**: Any updates to tenancy, authentication, filters, field-level permissions, triggers, or API envelope formatting must have regression tests.

Example test:
```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Tests\TestCase;

final class ExampleFeatureTest extends TestCase
{
    public function test_record_endpoint_respects_tenant_scoping(): void
    {
        // Arrange & Act
        $response = $this->withHeaders([
            'X-Tenant-ID' => 'tenant-123',
        ])->getJson('/api/records/orders');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', 'success');
    }
}
```

---

## 🔧 Code Quality & Analysis

We maintain strict static analysis and automated code formatting. Always run the quality suite before submitting a pull request.

### Quality Pipeline

```bash
# Run the full quality check: format-check -> analyse -> test
composer quality
```

### Static Analysis (PHPStan + Larastan)

We use PHPStan 2.x with Larastan 3.x:

```bash
# Run static analysis
composer analyse

# Run with increased memory for large inspections
vendor/bin/phpstan analyse src tests --memory-limit=1G
```

### Code Formatting & Refactoring (Rector)

We use Rector 2.0 to enforce automated refactoring, type declarations, and code cleanliness:

```bash
# Check code style (dry-run without modifying files)
composer format-check

# Automatically fix code style and apply refactors
composer format
```

### Documentation Validation

Documentation integrity is strictly enforced to prevent drift:

```bash
# Validate markdown files, cross-links, and code blocks in docs/
composer docs:validate
# Or directly:
php bin/validate-docs.php
```

---

## 📝 Coding Standards & Architecture

### PHP & Laravel Standards

- **Strict Types**: Every PHP file must declare strict types: `declare(strict_types=1);`.
- **PSR-12**: Adhere strictly to PSR-12 code style conventions.
- **Type Safety**: Use explicit scalar, object, and return types for all properties, parameters, and methods.
- **Namespace**: Core classes live in `Sopheak\Core\` (`src/`), and test classes in `Sopheak\Core\Tests\` (`tests/`).

### Config-Driven CRUD Architecture

- **`RecordTableType`**: Use named arguments for `RecordTableType` and all relationship type constructors (`RecordHasManyType`, `RecordBelongsToType`, etc.).
- **Auth Flags**: Use `isAuthRead` and `isAuthWrite` (primary). The legacy `public` attribute is deprecated.
- **Availability Flags**: Explicitly control operations with `canRead`, `canCreate`, `canUpdate`, `canDelete`, and `canUpsert`.
- **Multi-Database Compatibility**: Must remain compatible across MySQL, PostgreSQL, and SQLite. Avoid database-specific SQL functions unless wrapped in database-agnostic abstractions.
- **Backward Compatibility**: Preserve existing API contracts and public method signatures unless a breaking change is explicitly agreed upon.

### Package Directory Map

```
src/
├── Attributes/       # Discovery and metadata attributes
├── Authorization/    # Gates, permission providers, and scoping
├── Config/           # Package configuration loaders
├── Console/          # 16 Artisan CLI commands (setup, agent, record, export, etc.)
├── Constants/        # Package-wide constants
├── Enums/            # PHP 8.2+ enumerations
├── Events/           # Domain events
├── Exceptions/       # Package exceptions and API error handlers
├── Http/             # Controllers, middlewares, requests
├── Interfaces/       # Public contracts and service interfaces
├── Jobs/             # Asynchronous queue jobs (bulk actions, audit sync)
├── Listeners/        # Event listeners
├── Models/           # Core Eloquent models (AuditLog, Attachment, etc.)
├── Resources/        # API transformers and response serializers
├── Services/         # Core business logic services
├── Support/          # Internal utilities and helpers
├── Traits/           # Reusable traits (e.g., HasControllerHelpers)
├── Triggers/         # Record lifecycle triggers
├── Types/            # RecordTableType and relationship definition classes
└── Utilities/        # Query building, sorting, and filter utilities
```

### Documentation Strategy (`docs/` and `docs/guide/*`)

1. **AI-Facing Chunked Guides (`docs/guide/*`)**:
   - Split into `api/`, `features/`, `modules/`, and `records/`.
   - **Mandatory**: Whenever you add a feature, modify core behavior (CRUD, auth, tenancy, hooks, OpenAPI), or alter config keys, you **must update the corresponding page in `docs/guide/*`**.
2. **User-Facing Documentation (`docs/`)**:
   - Built with VitePress, Mermaid diagrams, and OpenAPI specifications.
   - Do **NOT** edit `sp-laravel-api-docs/` directly (it is an automated export synchronized by build scripts). Edit only `docs/`.
   - Always run `php bin/validate-docs.php` before committing doc changes.

---

## 🔄 Git Branching & Contribution Workflow

We follow a GitFlow-style development model:

| Branch | Purpose | Stability |
|---|---|---|
| `develop` | Default integration branch for features, fixes, and beta pre-releases | In development / Beta |
| `main` | Production branch reflecting official stable releases | Production / Stable |
| `feature/*` | Feature development branches (branched from and merged into `develop`) | Work in progress |
| `fix/*` | Bug fixes (branched from and merged into `develop`) | Work in progress |

### Pull Request Process

1. **Branch off `develop`**:
   ```bash
   git checkout develop
   git pull origin develop
   git checkout -b feature/your-feature-name
   ```
2. **Implement Changes & Tests**:
   - Write clean, well-tested code.
   - Update matching documentation under `docs/` and `docs/guide/*`.
3. **Run Pre-Commit Verification**:
   ```bash
   composer quality
   php bin/validate-docs.php
   ```
4. **Submit PR**:
   - Target the **`develop`** branch.
   - Fill out the PR template with a description of the change, test coverage evidence, and links to any related issues.

---

## 📦 Automated Release & Publishing Plan

Our release pipeline is fully automated via GitHub Actions with a mandatory security gate preceding any release.

### Workflow Pipeline Overview

```
Push to branch (develop or main)
             │
             ▼
      ┌─────────────┐
      │ security.yml │   ← Secrets, CVEs, Semgrep SAST, File Audit
      └──────┬──────┘
             │ All security checks passed?
      ┌──────┴──────┐
      │             │
     Yes            No
      │             │
      ▼             ▼
 ┌─────────────┐   Release aborted
 │ release.yml │
 └─────────────┘
```

### 1. Automated Security Gate (`security.yml`)

Runs on every push and pull request to `main` and `develop`. All four jobs must pass:
- **`scan-secrets`**: Gitleaks deep git history scanning for private keys, AWS credentials, and API tokens.
- **`scan-dependencies`**: Audit of `composer.lock` against the PHP Security Advisories database.
- **`scan-sast`**: Semgrep static analysis for OWASP Top 10, unsafe PHP functions, and Laravel vulnerabilities.
- **`scan-files`**: File audit detecting suspicious scripts, webshell signatures, or unauthorized executables.

### 2. Automated Release Workflow (`release.yml`)

Triggers automatically via `workflow_run` once `security.yml` passes:

- **Beta Pre-Releases (`develop` branch)**:
  - Triggered on every merge/push to `develop`.
  - Creates a pre-release tag: `{version}-beta.{github_run_number}` (e.g. `0.5.04-beta.207`).
  - Publishes a **GitHub Pre-Release** with automatically generated release notes.

- **Stable Releases (`main` branch)**:
  - Triggered when changes are merged into `main`.
  - Reads the version from `composer.json` (`"version": "X.Y.Z"`).
  - Validates strict SemVer format (`X.Y.Z`).
  - Generates the git tag `X.Y.Z` and publishes an official **GitHub Release**.

### 3. Publishing to Packagist (Release Plan)

To publish and distribute new versions of `sopheak/sp-laravel-api`:

#### For Public Packagist ([packagist.org](https://packagist.org))
1. The repository is connected to Packagist with the package name `sopheak/sp-laravel-api`.
2. A GitHub Webhook (`Packagist` service integration) automatically notifies Packagist upon every new git tag push.
3. Packagist immediately ingests the new tag (`X.Y.Z` or `{version}-beta.*`), making it installable via:
   ```bash
   composer require sopheak/sp-laravel-api
   ```

#### For Private / Internal Distribution
If the package is distributed privately or as proprietary software, consuming Laravel applications can reference the repository directly:
```json
"repositories": [
  {
    "type": "vcs",
    "url": "git@github.com:sopheaksem9999/sp-laravel-unified-api.git"
  }
],
"require": {
  "sopheak/sp-laravel-api": "^0.5.04"
}
```

#### Step-by-Step Stable Publishing Checklist

When preparing to publish a new stable release:

1. **Prepare Release on `develop`**:
   - Ensure all features and fixes are merged into `develop`.
   - Bump `"version"` in `composer.json` to the new SemVer (e.g., `"version": "0.5.05"`).
   - Document changes in `docs/changelog.md` and update `docs/versions.md`.
2. **Run Local Verification**:
   ```bash
   composer quality
   php bin/validate-docs.php
   ```
3. **Create Release PR (`develop` → `main`)**:
   - Open a PR from `develop` into `main`.
   - Confirm CI security and quality checks pass.
   - Review and merge the pull request into `main`.
4. **Automated Publishing**:
   - The push to `main` triggers `security.yml`.
   - Upon successful scan, `release.yml` triggers `release-stable`.
   - Git tag `X.Y.Z` and GitHub Release are automatically created.
   - Packagist webhook triggers and updates the package registry.

---

## 🔒 Security Guidelines

### Best Practices

1. **Tenant Isolation**: Always verify that database queries and relationship queries are scoped by `X-Tenant-ID`.
2. **Authorization**: Use Laravel Gates and explicit permission checks (`isAuthRead`, `isAuthWrite`, `viewOwn`).
3. **SQL Injection**: Always use parameterized queries or Eloquent/Query Builder bindings. Never interpolate raw user inputs into query strings.
4. **Input Sanitization**: Validate all inputs using request validation or `RecordTableType` schema validation.

### Reporting Vulnerabilities

If you discover a security vulnerability in this package:
- **Do NOT** file a public GitHub issue.
- Please email details and reproduction steps to: `security@example.com` (or contact the maintainers directly).
- Maintainers will investigate, prepare a patch, and coordinate release before public disclosure.

---

## 📄 License

This package is licensed as proprietary software as designated in `composer.json`. All contributions remain subject to this license.

---

Thank you for helping build and maintain **SP Laravel Unified API**!

