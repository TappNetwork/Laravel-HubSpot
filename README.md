# Laravel HubSpot Integration

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tappnetwork/laravel-hubspot.svg?style=flat-square)](https://packagist.org/packages/tappnetwork/laravel-hubspot)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/tappnetwork/laravel-hubspot/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/tappnetwork/laravel-hubspot/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/tappnetwork/laravel-hubspot/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/tappnetwork/laravel-hubspot/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/tappnetwork/laravel-hubspot.svg?style=flat-square)](https://packagist.org/packages/tappnetwork/laravel-hubspot)

A Laravel package for seamless integration with HubSpot CRM. Provides automatic synchronization of Laravel models with HubSpot contacts and companies, with support for queued operations.

## Installation

```bash
composer require tapp/laravel-hubspot
php artisan vendor:publish --tag="laravel-hubspot-config"
php artisan vendor:publish --tag="hubspot-migrations"
php artisan migrate
```

## Configuration

Add your HubSpot API key to your `.env` file:

```env
HUBSPOT_ID=your_hubspot_id
HUBSPOT_TOKEN=your_api_key
HUBSPOT_DISABLED=false
HUBSPOT_LOG_REQUESTS=false
HUBSPOT_PROPERTY_GROUP=app_user_profile
HUBSPOT_PROPERTY_GROUP_LABEL=App User Profile
```

## Usage

### User Model Setup

Add the trait to your User model and define the HubSpot property mapping:

```php
use Tapp\LaravelHubspot\Models\HubspotContact;

class User extends Authenticatable
{
    use HubspotContact;

    public array $hubspotMap = [
        'email' => 'email',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'user_type' => 'type.name', // Supports dot notation for relations
    ];
}
```

### Dynamic Properties

Override the `hubspotProperties` method for computed values:

```php
public function hubspotProperties(array $map): array
{
    $properties = parent::hubspotProperties($map);
    
    // Add computed properties
    $properties['full_name'] = $this->first_name . ' ' . $this->last_name;

    return $properties;
}
```

### Observers (Recommended)

Register observers in your `AppServiceProvider` for better separation of concerns:

```php
use App\Models\User;
use App\Models\Company;
use Tapp\LaravelHubspot\Observers\HubspotContactObserver;
use Tapp\LaravelHubspot\Observers\HubspotCompanyObserver;

public function boot(): void
{
    User::observe(HubspotContactObserver::class);
    Company::observe(HubspotCompanyObserver::class);
}
```

### Sync Properties

Create the property group and properties in HubSpot:

```bash
php artisan hubspot:sync-properties
```

## Queuing

The package supports queued operations for better performance. Configure in your `.env`:

```env
HUBSPOT_QUEUE_ENABLED=true
HUBSPOT_QUEUE_CONNECTION=default
HUBSPOT_QUEUE_NAME=hubspot
HUBSPOT_QUEUE_RETRY_ATTEMPTS=3
HUBSPOT_QUEUE_RETRY_DELAY=60
```

Run queue workers:

```bash
php artisan queue:work --queue=hubspot
```

## Testing

### Quick Start

```bash
# Run all tests
composer test

# Run only unit tests (fast, no API calls)
composer test-unit

# Run only integration tests (requires HubSpot API key)
composer test-integration

# Run with coverage report
composer test-coverage
```

### Setup Integration Tests

1. Create `.env.testing`:
```env
HUBSPOT_TEST_API_KEY=your_test_api_key_here
HUBSPOT_DISABLED=false
HUBSPOT_LOG_REQUESTS=true
HUBSPOT_USE_REAL_API=false
HUBSPOT_PROPERTY_GROUP=test_property_group
HUBSPOT_QUEUE_ENABLED=false
```

2. Get HubSpot test API key with scopes:
   - `crm.objects.contacts.read`
   - `crm.objects.contacts.write`
   - `crm.objects.companies.read`
   - `crm.objects.companies.write`

3. Sync test properties:
```bash
export HUBSPOT_TEST_API_KEY=your_test_api_key_here
php artisan hubspot:sync-properties
```

### Flexible Testing

Switch between mocked and real API calls:

```bash
# Run with mocks (fast, no API calls)
HUBSPOT_USE_REAL_API=false composer test

# Run with real API calls (requires API key)
HUBSPOT_USE_REAL_API=true composer test
```

## Testing in Consuming Projects

- **[Quick Start Guide](docs/QUICK_START_TESTING.md)** - Fast testing checklist
- **[Comprehensive Testing Guide](docs/CONSUMING_PROJECT_TESTING.md)** - Detailed testing strategy

### Quick Testing Steps

1. **Clean test account**: `php scripts/clean-hubspot-test-account.php`
2. **Configure your app**: Add traits to models and configure HubSpot settings
3. **Sync properties**: `php artisan hubspot:sync-properties`
4. **Test sync commands**: `php artisan hubspot:sync-contacts App\Models\User`
5. **Test user registration**: Create users/companies and verify in HubSpot dashboard

## Breaking Changes in v2.0

**⚠️ This is a major version release with breaking changes. Please review the migration guide below.**

### What Changed

- **Removed deprecated synchronous methods** from `HubspotContact` and `HubspotCompany` traits
- **Simplified trait architecture** - traits now only handle property mapping and data access
- **Consolidated property conversion logic** into a dedicated `PropertyConverter` class
- **Removed duplicate code** between services, jobs, and traits
- **Updated commands** to use services instead of deprecated trait methods

### Migration Guide

#### 1. Update Your Models

**Before (v1.x):**
```php
use Tapp\LaravelHubspot\Models\HubspotContact;

class User extends Authenticatable
{
    use HubspotContact;
    
    // Trait automatically handled sync via boot methods
}
```

**After (v2.0):**
```php
use Tapp\LaravelHubspot\Models\HubspotContact;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;

class User extends Authenticatable implements HubspotModelInterface
{
    use HubspotContact;
    
    // Must register observer for automatic sync
}
```

#### 2. Register Observers

**Required in v2.0:**
```php
// In AppServiceProvider::boot()
use Tapp\LaravelHubspot\Observers\HubspotContactObserver;
use Tapp\LaravelHubspot\Observers\HubspotCompanyObserver;

User::observe(HubspotContactObserver::class);
Company::observe(HubspotCompanyObserver::class);
```

#### 3. Removed Methods

The following methods have been **removed** from traits and should not be used:

**From HubspotContact trait:**
- `createHubspotContact()`
- `updateHubspotContact()`
- `getContactByEmailOrId()`
- `validateHubspotContactExists()`
- `findContactByEmail()`
- `associateCompanyWithContact()`
- `associateCompanyIfNeeded()`
- `saveHubspotId()`

**From HubspotCompany trait:**
- `createHubspotCompany()`
- `updateHubspotCompany()`
- `getCompanyByIdOrName()`
- `validateHubspotCompanyExists()`
- `findCompanyByName()`
- `findOrCreateCompany()`
- `saveHubspotId()`

#### 4. Use Services for Direct API Calls

**For direct API operations, use services:**
```php
use Tapp\LaravelHubspot\Services\HubspotContactService;
use Tapp\LaravelHubspot\Services\HubspotCompanyService;

$contactService = app(HubspotContactService::class);
$companyService = app(HubspotCompanyService::class);

// Create contact
$contactService->createContact($data, User::class);

// Update contact
$contactService->updateContact($data);
```

### Benefits of v2.0

- **Cleaner architecture** with clear separation of concerns
- **Reduced code duplication** by ~800-1000 lines
- **Better maintainability** with single source of truth for each responsibility
- **Improved performance** with optimized property conversion
- **Enhanced testability** with focused, single-purpose classes

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [TappNetwork](https://github.com/Scott Grayson)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
