# Coding Standards (Shared)

## General
- Keep changes minimal and consistent with existing style
- Prefer small functions and clear naming
- Avoid breaking public APIs without discussion
- Never add secrets
- Follow PSR-12 coding standards

## Project Conventions
- **Naming**: Use descriptive names, camelCase for variables/methods, PascalCase for classes
- **Folder conventions**: Follow Laravel package structure patterns
- **Error handling**: Use Laravel exceptions and proper HTTP status codes
- **Logging**: Use Laravel's logging facade with appropriate context
- **Testing expectations**: All new features should include tests, use PHPUnit

## Database Compatibility
- Support MySQL, PostgreSQL, and SQLite
- Use database-agnostic SQL when possible
- Avoid database-specific functions unless wrapped in compatibility layers
- Test across all supported databases

## API Design
- Use consistent JSON response format
- Follow RESTful principles
- Include proper error handling and validation
- Support field selection and filtering