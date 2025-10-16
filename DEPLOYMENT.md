# SP Laravel API - Deployment Guide

This guide explains how to deploy the `sopheak/sp-laravel-api` package so it can be installed in other Laravel projects using `composer require sopheak/sp-laravel-api`.

## Prerequisites

- Git repository (GitHub, GitLab, or Bitbucket)
- Proper versioning strategy
- Access control for private repositories

## Deployment Methods

### Method 1: Private Git Repository (Recommended)

This is the most straightforward approach for private packages.

#### Step 1: Prepare Your Repository

1. **Initialize Git Repository** (if not already done):
```bash
cd /path/to/sp-laravel-api
git init
git add .
git commit -m "Initial package release v0.1.0"
```

2. **Create Remote Repository**:
   - Create a new repository on GitHub/GitLab/Bitbucket
   - Name it: `sp-laravel-api`
   - Set it as **Private**

3. **Push to Remote**:
```bash
git remote add origin https://github.com/yourusername/sp-laravel-api.git
git branch -M main
git push -u origin main
```

#### Step 2: Version Tagging

Create semantic version tags for releases:

```bash
# Tag the current version
git tag v0.1.0
git push origin v0.1.0

# For future releases
git tag v0.1.1
git push origin v0.1.1
```

#### Step 3: Consumer Project Setup

In any Laravel project that wants to use your package:

1. **Add Repository to composer.json**:
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/yourusername/sp-laravel-api.git"
        }
    ],
    "require": {
        "sopheak/sp-laravel-api": "^0.1.0"
    }
}
```

2. **Install the Package**:
```bash
composer require sopheak/sp-laravel-api
```

3. **Authentication** (for private repos):
```bash
# GitHub Personal Access Token
composer config github-oauth.github.com YOUR_GITHUB_TOKEN

# Or use SSH keys
git config --global url."git@github.com:".insteadOf "https://github.com/"
```

### Method 2: Private Packagist (Professional Solution)

For enterprise-level package management:

1. **Sign up**: https://packagist.com/
2. **Add Repository**: Connect your Git repository
3. **Team Access**: Manage team permissions
4. **Consumer Setup**:
```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://repo.packagist.com/yourcompany/"
        }
    ]
}
```

### Method 3: Satis (Self-hosted)

Create your own private Composer repository:

1. **Install Satis**:
```bash
composer create-project composer/satis --stability=dev --keep-vcs
```

2. **Configure satis.json**:
```json
{
    "name": "Your Company Private Packages",
    "homepage": "https://packages.yourcompany.com",
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/yourusername/sp-laravel-api.git"
        }
    ],
    "require-all": true
}
```

3. **Build Repository**:
```bash
php bin/satis build satis.json public/
```

## Version Management

### Semantic Versioning

Follow semantic versioning (semver.org):

- **MAJOR** (1.0.0): Breaking changes
- **MINOR** (0.1.0): New features, backward compatible
- **PATCH** (0.0.1): Bug fixes, backward compatible

### Release Process

1. **Update Version** in `composer.json`:
```json
{
    "version": "0.2.0"
}
```

2. **Update CHANGELOG.md**:
```markdown
## [0.2.0] - 2024-01-15
### Added
- New audit logging features
- Enhanced cursor pagination

### Fixed
- Query optimization issues
```

3. **Commit and Tag**:
```bash
git add .
git commit -m "Release v0.2.0"
git tag v0.2.0
git push origin main --tags
```

## Security Considerations

### Private Repository Access

1. **GitHub Personal Access Tokens**:
```bash
composer config github-oauth.github.com ghp_xxxxxxxxxxxxxxxxxxxx
```

2. **SSH Key Authentication**:
```bash
# Add to ~/.ssh/config
Host github.com
    HostName github.com
    User git
    IdentityFile ~/.ssh/id_rsa
```

3. **Environment Variables**:
```bash
# In consumer projects
export COMPOSER_AUTH='{"github-oauth":{"github.com":"YOUR_TOKEN"}}'
```

### Team Access Management

- Use organization repositories
- Manage team permissions
- Regular token rotation
- Audit access logs

## CI/CD Integration

### GitHub Actions Example

Create `.github/workflows/release.yml`:

```yaml
name: Release Package

on:
  push:
    tags:
      - 'v*'

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          
      - name: Install Dependencies
        run: composer install --no-dev --optimize-autoloader
        
      - name: Run Tests
        run: vendor/bin/phpunit
        
      - name: Create Release
        uses: actions/create-release@v1
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        with:
          tag_name: ${{ github.ref }}
          release_name: Release ${{ github.ref }}
```

## Troubleshooting

### Common Issues

1. **Authentication Errors**:
```bash
# Clear composer cache
composer clear-cache

# Reconfigure authentication
composer config --global github-oauth.github.com YOUR_NEW_TOKEN
```

2. **Version Conflicts**:
```bash
# Update composer
composer self-update

# Clear cache and reinstall
rm -rf vendor composer.lock
composer install
```

3. **Repository Not Found**:
- Verify repository URL
- Check access permissions
- Ensure proper authentication

### Debug Commands

```bash
# Verbose installation
composer require sopheak/sp-laravel-api -vvv

# Show package information
composer show sopheak/sp-laravel-api

# Validate composer.json
composer validate
```

## Best Practices

1. **Always tag releases** with semantic versioning
2. **Maintain CHANGELOG.md** for version history
3. **Use branch protection** for main/master branch
4. **Implement automated testing** before releases
5. **Document breaking changes** clearly
6. **Keep dependencies updated** regularly
7. **Use environment-specific configurations**

## Support

For issues with package deployment or installation:

1. Check this deployment guide
2. Verify authentication setup
3. Review repository permissions
4. Contact package maintainer

---

**Next Steps**: Choose your preferred deployment method and follow the corresponding setup instructions.