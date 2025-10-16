# Contributing to SP Laravel API

Thank you for considering contributing to SP Laravel API! This document provides guidelines and information for contributors.

## 🚀 Getting Started

### Prerequisites

- PHP 8.1 or higher
- Composer
- Laravel 10.x or 11.x
- MySQL 8.0+ or PostgreSQL 13+ (SQLite for testing)
- Redis (optional, for caching and queues)

### Development Setup

1. **Fork and Clone**
   ```bash
   git clone https://github.com/your-username/sp-laravel-api.git
   cd sp-laravel-api
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Set Up Testing Environment**
   ```bash
   cp .env.example .env.testing
   php artisan key:generate --env=testing
   ```

4. **Run Tests**
   ```bash
   vendor/bin/phpunit
   ```

## 🧪 Testing

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test suites
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Feature

# Run with coverage
vendor/bin/phpunit --coverage-html coverage
```

### Writing Tests

- **Unit Tests**: Test individual classes and methods in isolation
- **Feature Tests**: Test complete functionality including HTTP requests
- **Integration Tests**: Test interactions between components

Example test structure:
```php
<?php

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_example_functionality(): void
    {
        // Arrange
        $service = new ExampleService();
        
        // Act
        $result = $service->doSomething();
        
        // Assert
        $this->assertTrue($result);
    }
}
```

## 🔧 Code Quality

### Code Style

We use PHP CS Fixer for code formatting:

```bash
# Check code style
vendor/bin/php-cs-fixer fix --dry-run --diff

# Fix code style
vendor/bin/php-cs-fixer fix
```

### Static Analysis

We use PHPStan for static analysis:

```bash
# Run static analysis
vendor/bin/phpstan analyse
```

### Pre-commit Checks

Before committing, ensure:

1. All tests pass
2. Code style is correct
3. Static analysis passes
4. No linting errors

```bash
# Run all checks
composer test
composer cs-fix
composer analyse
```

## 📝 Coding Standards

### PHP Standards

- Follow PSR-12 coding standards
- Use strict types: `declare(strict_types=1);`
- Use type hints for all parameters and return types
- Write descriptive variable and method names

### Laravel Conventions

- Use Laravel naming conventions
- Follow repository pattern for data access
- Use service classes for business logic
- Implement proper error handling

### Documentation

- Add PHPDoc blocks for all public methods
- Include parameter and return type documentation
- Provide usage examples for complex functionality

Example:
```php
/**
 * Retrieve paginated records with optional filtering
 *
 * @param string $table The table name to query
 * @param array<string, mixed> $filters Optional filters to apply
 * @param int $perPage Number of records per page
 * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
 * 
 * @throws \InvalidArgumentException When table is not configured
 * @throws \Illuminate\Database\QueryException When query fails
 */
public function getPaginatedRecords(string $table, array $filters = [], int $perPage = 15): LengthAwarePaginator
{
    // Implementation
}
```

## 🐛 Bug Reports

When reporting bugs, please include:

1. **Clear Description**: What happened vs. what you expected
2. **Steps to Reproduce**: Detailed steps to reproduce the issue
3. **Environment**: PHP version, Laravel version, database type
4. **Code Examples**: Minimal code that demonstrates the issue
5. **Error Messages**: Full error messages and stack traces

Use the bug report template:

```markdown
## Bug Description
Brief description of the bug

## Steps to Reproduce
1. Step one
2. Step two
3. Step three

## Expected Behavior
What should happen

## Actual Behavior
What actually happens

## Environment
- PHP Version: 8.2
- Laravel Version: 10.x
- Package Version: 2.x
- Database: MySQL 8.0

## Additional Context
Any additional information
```

## 💡 Feature Requests

For new features:

1. **Check Existing Issues**: Ensure the feature hasn't been requested
2. **Describe Use Case**: Explain why this feature is needed
3. **Provide Examples**: Show how the feature would be used
4. **Consider Alternatives**: Discuss alternative solutions

## 🔄 Pull Request Process

### Before Submitting

1. **Create an Issue**: Discuss the change before implementing
2. **Fork the Repository**: Work on your own fork
3. **Create Feature Branch**: Use descriptive branch names
4. **Write Tests**: Ensure new functionality is tested
5. **Update Documentation**: Update README and docs as needed

### Pull Request Guidelines

1. **Clear Title**: Descriptive title explaining the change
2. **Detailed Description**: Explain what changed and why
3. **Link Issues**: Reference related issues
4. **Test Coverage**: Ensure tests cover new functionality
5. **Documentation**: Update relevant documentation

### PR Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
- [ ] Tests pass locally
- [ ] New tests added for new functionality
- [ ] Manual testing completed

## Checklist
- [ ] Code follows project style guidelines
- [ ] Self-review completed
- [ ] Documentation updated
- [ ] No breaking changes (or clearly documented)
```

## 🏗️ Architecture Guidelines

### Package Structure

```
src/
├── Console/          # Artisan commands
├── Enums/           # Enumeration classes
├── Http/            # Controllers, middleware, requests
├── Interfaces/      # Contracts and interfaces
├── Jobs/            # Queue jobs
├── Models/          # Eloquent models
├── Services/        # Business logic services
├── Support/         # Helper classes and utilities
├── Traits/          # Reusable traits
└── Types/           # Type classes for configuration
```

### Design Principles

1. **Single Responsibility**: Each class should have one reason to change
2. **Open/Closed**: Open for extension, closed for modification
3. **Dependency Injection**: Use Laravel's container for dependencies
4. **Interface Segregation**: Create focused interfaces
5. **Composition over Inheritance**: Prefer composition when possible

### Service Layer Pattern

```php
<?php

namespace Sopheak\Core\Services;

use Sopheak\Core\Interfaces\RecordServiceInterface;

class RecordService implements RecordServiceInterface
{
    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private readonly CacheService $cacheService
    ) {}

    public function getRecord(string $table, int $id): array
    {
        // Implementation
    }
}
```

## 🔒 Security Guidelines

### Security Best Practices

1. **Input Validation**: Validate all user inputs
2. **SQL Injection Prevention**: Use parameterized queries
3. **XSS Prevention**: Escape output appropriately
4. **Authentication**: Implement proper authentication
5. **Authorization**: Check permissions before actions

### Reporting Security Issues

For security vulnerabilities:

1. **Do NOT** create public issues
2. Email security issues to: security@example.com
3. Include detailed description and reproduction steps
4. Allow time for fix before public disclosure

## 📚 Resources

### Documentation

- [Laravel Documentation](https://laravel.com/docs)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [PSR Standards](https://www.php-fig.org/psr/)

### Tools

- [PHP CS Fixer](https://cs.symfony.com/)
- [PHPStan](https://phpstan.org/)
- [Composer](https://getcomposer.org/)

## 🤝 Community

### Communication

- **GitHub Issues**: Bug reports and feature requests
- **GitHub Discussions**: General questions and discussions
- **Pull Requests**: Code contributions

### Code of Conduct

We are committed to providing a welcoming and inclusive environment. Please:

1. Be respectful and inclusive
2. Welcome newcomers and help them learn
3. Focus on constructive feedback
4. Respect different viewpoints and experiences

## 📄 License

By contributing to SP Laravel API, you agree that your contributions will be licensed under the MIT License.

---

Thank you for contributing to SP Laravel API! 🚀