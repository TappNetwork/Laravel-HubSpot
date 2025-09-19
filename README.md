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

Add the trait to your User model, implement the required interface, and define the HubSpot property mapping:

```php
use Tapp\LaravelHubspot\Models\HubspotContact;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;

class User extends Authenticatable implements HubspotModelInterface
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

### Interface Requirement

**Important**: Models must implement `HubspotModelInterface` to enable automatic synchronization. This interface ensures your models have the required methods for HubSpot integration:

- `getHubspotMap()` - Returns the property mapping array
- `getHubspotUpdateMap()` - Returns update-specific property mapping
- `getHubspotCompanyRelation()` - Returns the company relationship name
- `getHubspotProperties()` - Returns dynamic properties
- `getHubspotId()` / `setHubspotId()` - Manages the HubSpot ID

The traits (`HubspotContact`, `HubspotCompany`) provide the implementation for these methods, so you only need to implement the interface and define your `$hubspotMap` array.

### Company Model Setup

For company models, use the `HubspotCompany` trait:

```php
use Tapp\LaravelHubspot\Models\HubspotCompany;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;

class Company extends Model implements HubspotModelInterface
{
    use HubspotCompany;

    public array $hubspotMap = [
        'name' => 'name',
        'domain' => 'domain',
        'industry' => 'industry',
    ];
}
```

### Dynamic Properties

Override the `hubspotProperties` method for computed values using trait aliasing:

```php
use Tapp\LaravelHubspot\Models\HubspotContact {
    hubspotProperties as traitHubspotProperties;
}

class User extends Authenticatable implements HubspotModelInterface
{
    use HubspotContact;

    public function hubspotProperties(array $map): array
    {
        // Get the base properties from the trait
        $properties = $this->traitHubspotProperties($map);
        
        // Add computed properties
        $properties['full_name'] = $this->first_name . ' ' . $this->last_name;
        $properties['display_name'] = $this->getDisplayName();
        $properties['account_age_days'] = $this->created_at->diffInDays(now());

        return $properties;
    }
}
```

### Observers (Required for Automatic Sync)

**Important**: Observers are **required** for automatic synchronization. Register observers in your `AppServiceProvider` to enable automatic sync when models are created/updated:

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

### Manual Sync (Alternative)

If you prefer manual control over when syncing occurs, you can use the provided commands instead of observers:

```bash
# Sync all contacts from a specific model
php artisan hubspot:sync-contacts App\Models\User

# Sync with options
php artisan hubspot:sync-contacts App\Models\User --delay=1 --limit=100
```

**Note**: Without observers, models will only sync when you explicitly run these commands.

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
HUBSPOT_DISABLED=true composer test

# Run with real API calls (requires API key)
HUBSPOT_DISABLED=false composer test
```

### Testing Documentation

- **[Quick Start Guide](docs/QUICK_START_TESTING.md)** - Fast testing checklist
- **[Comprehensive Testing Guide](docs/CONSUMING_PROJECT_TESTING.md)** - Detailed testing strategy

## Upgrading

**⚠️ Upgrading from a previous version?** Please see the [Upgrade Guide](UPGRADE.md) for breaking changes and migration instructions.

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
