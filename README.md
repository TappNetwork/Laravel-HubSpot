# This is my package laravel-hubspot

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tappnetwork/laravel-hubspot.svg?style=flat-square)](https://packagist.org/packages/tappnetwork/laravel-hubspot)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/tappnetwork/laravel-hubspot/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/tappnetwork/laravel-hubspot/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/tappnetwork/laravel-hubspot/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/tappnetwork/laravel-hubspot/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/tappnetwork/laravel-hubspot.svg?style=flat-square)](https://packagist.org/packages/tappnetwork/laravel-hubspot)

A Laravel package for seamless integration with HubSpot CRM. This package provides automatic synchronization of Laravel models with HubSpot contacts and companies, with support for queued operations for improved performance.

## Installation

You can install the package via composer:

```bash
composer require tapp/laravel-hubspot
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="hubspot-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-hubspot-config"
```

This is the contents of the published config file:

```php
return [
    'disabled' => env('HUBSPOT_DISABLED', false),
    'api_key' => env('HUBSPOT_TOKEN'),
    'log_requests' => env('HUBSPOT_LOG_REQUESTS', false),
    'property_group' => env('HUBSPOT_PROPERTY_GROUP', 'app_user_profile'),
    'property_group_label' => env('HUBSPOT_PROPERTY_GROUP_LABEL', 'App User Profile'),
    'queue' => [
        'enabled' => env('HUBSPOT_QUEUE_ENABLED', true),
        'connection' => env('HUBSPOT_QUEUE_CONNECTION', 'default'),
        'queue' => env('HUBSPOT_QUEUE_NAME', 'hubspot'),
        'retry_attempts' => env('HUBSPOT_QUEUE_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('HUBSPOT_QUEUE_RETRY_DELAY', 60),
    ],
];
```

## Usage

### API Key
Publish the config, add your api key to the env

### User Model
Add the trait to your user model and define any fields to the $hubspotMap property that will determine the data sent to HubSpot. You may use dot notation to access data from relations. For further customization, use [Laravel's accessor pattern](https://laravel.com/docs/11.x/eloquent-mutators#defining-an-accessor)

```php
use Tapp\LaravelHubspot\Models\HubspotContact;

class User extends Authenticatable 
{
    use HubspotContact; 

    public array $hubspotMap = [
        'email' => 'email',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'user_type' => 'type.name',
    ];
```

### Dynamic Properties
You can override the `hubspotProperties` method to add dynamic properties that are calculated at runtime. This is useful for properties that depend on relationships or computed values:

```php
public function hubspotProperties(array $map): array
{
    $properties = [];

    foreach ($map as $key => $value) {
        if (strpos($value, '.')) {
            $properties[$key] = data_get($this, $value);
        } else {
            $properties[$key] = $this->$value;
        }
    }

    // Add dynamic course progress properties
    if (method_exists($this, 'courses')) {
        foreach (Course::all() as $course) {
            $extid = $course->external_id;
            $properties[$extid.'_progress'] = round($course->getCompletionPercentageForUser($this->id));
            $properties[$extid.'_started_at'] = $course->startedByUserAt($this->id);
            $properties[$extid.'_completed_at'] = $course->completedByUserAt($this->id);
        }
    }

    return $properties;
}
```

The dynamic properties will be automatically included when syncing to HubSpot, whether using the trait's model events or the observer pattern.

### Register Observers (Recommended)
For better separation of concerns, register the HubSpot observers in your `AppServiceProvider`:

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

This approach provides better performance and cleaner architecture by:
- Only syncing when HubSpot-relevant fields change
- Separating HubSpot logic from your models
- Providing better testability

### Create HubSpot Properties
run the following command to create the property group and properties.

``` bash
php artisan hubspot:sync-properties
```

### Sync to HubSpot
The package uses model events to create or update contacts in HubSpot. Try registering a user and see that they have been created in HubSpot with properties from the $hubspotMap array.

### Queuing (Optional)
The package supports queuing HubSpot API calls for better performance. By default, queuing is enabled and will process HubSpot operations asynchronously.

#### Environment Variables
```env
HUBSPOT_QUEUE_ENABLED=true
HUBSPOT_QUEUE_CONNECTION=default
HUBSPOT_QUEUE_NAME=hubspot
HUBSPOT_QUEUE_RETRY_ATTEMPTS=3
HUBSPOT_QUEUE_RETRY_DELAY=60
```

#### Queue Configuration
- `HUBSPOT_QUEUE_ENABLED`: Enable/disable queuing (default: true)
- `HUBSPOT_QUEUE_CONNECTION`: Queue connection to use (default: default)
- `HUBSPOT_QUEUE_NAME`: Queue name for HubSpot jobs (default: hubspot)
- `HUBSPOT_QUEUE_RETRY_ATTEMPTS`: Number of retry attempts for failed jobs (default: 3)
- `HUBSPOT_QUEUE_RETRY_DELAY`: Delay between retries in seconds (default: 60)

#### Running Queue Workers
Make sure you have queue workers running to process the jobs:

```bash
php artisan queue:work --queue=hubspot
```

#### Disabling Queuing
To disable queuing and use synchronous operations, set `HUBSPOT_QUEUE_ENABLED=false` in your environment.

## Testing

This package includes comprehensive testing with both unit tests (mocked) and integration tests (real API calls).

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

### Test Categories

#### Unit Tests (`tests/Unit/`)
- **Fast execution** - No external API calls
- **Mocked dependencies** - Uses Mockery to mock HubSpot API
- **Business logic testing** - Tests your package logic without external dependencies
- **CI/CD friendly** - Can run in any environment

#### Integration Tests (`tests/Integration/`)
- **Real API calls** - Tests actual HubSpot API integration
- **End-to-end testing** - Verifies complete functionality
- **Requires API key** - Needs valid HubSpot test API key
- **Test data cleanup** - Automatically cleans up test data

### Setting Up Tests

1. **Create test environment file** (`.env.testing`):
```env
HUBSPOT_TEST_API_KEY=your_test_api_key_here
HUBSPOT_DISABLED=false
HUBSPOT_LOG_REQUESTS=true
HUBSPOT_USE_REAL_API=false  # Set to true to use real API calls
HUBSPOT_PROPERTY_GROUP=test_property_group
HUBSPOT_QUEUE_ENABLED=false
```

2. **Get HubSpot test API key**:
   - Go to HubSpot Developer Account
   - Create Private App with required scopes:
     - `crm.objects.contacts.read`
     - `crm.objects.contacts.write`
     - `crm.objects.companies.read`
     - `crm.objects.companies.write`
     - `crm.objects.owners.read`

3. **Set up test property group**:
```bash
export HUBSPOT_TEST_API_KEY=your_test_api_key_here
php artisan hubspot:sync-properties
```

### Running Tests

#### Flexible Testing (Recommended)

The package supports flexible testing that can easily switch between mocked and real API calls:

```bash
# Run with mocks (fast, no API calls)
HUBSPOT_USE_REAL_API=false composer test

# Run with real API calls (requires API key)
HUBSPOT_USE_REAL_API=true composer test

# Run specific test categories
HUBSPOT_USE_REAL_API=false composer test-unit
HUBSPOT_USE_REAL_API=true composer test-integration
```

#### Traditional Testing

```bash
# Run all tests
composer test

# Run specific test categories
composer test-unit
composer test-integration
composer test-feature

# Run with coverage
composer test-coverage
composer test-coverage-unit

# Run specific test file
vendor/bin/pest tests/Integration/HubspotApiIntegrationTest.php

# Run flexible tests (switches between mock/real based on config)
vendor/bin/pest tests/Unit/HubspotContactServiceFlexibleTest.php

# Run with verbose output
vendor/bin/pest --verbose
```

### Test Best Practices

- **Flexible testing** - Use `HUBSPOT_USE_REAL_API` to switch between mock/real API
- **Development workflow** - Use mocks for fast feedback, real API for confidence
- **CI/CD strategy** - Use mocks for speed, real API tests on schedule
- **Unique test data** - Always use unique identifiers (emails, names) in tests
- **Cleanup after tests** - Integration tests automatically clean up test data
- **Rate limiting** - Tests include delays to respect HubSpot API limits
- **Mock validation** - Run same tests with mocks vs real API to ensure consistency

### Continuous Integration

For CI/CD pipelines:

```yaml
# Example GitHub Actions workflow
- name: Run Unit Tests
  run: composer test-unit

- name: Run Integration Tests (scheduled)
  run: composer test-integration
  if: github.event_name == 'schedule'
```

See [INTEGRATION_TESTING.md](tests/INTEGRATION_TESTING.md) for detailed setup instructions.

## Testing in Consuming Projects

For testing the package in a real Laravel application:

- **[Quick Start Guide](docs/QUICK_START_TESTING.md)** - Fast testing checklist
- **[Comprehensive Testing Guide](docs/CONSUMING_PROJECT_TESTING.md)** - Detailed testing strategy

### Quick Testing Steps

1. **Clean test account**: `php scripts/clean-hubspot-test-account.php`
2. **Configure your app**: Add traits to models and configure HubSpot settings
3. **Sync properties**: `php artisan hubspot:sync-properties`
4. **Test sync commands**: `php artisan hubspot:sync-contacts App\Models\User`
5. **Test user registration**: Create users/companies and verify in HubSpot dashboard

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
