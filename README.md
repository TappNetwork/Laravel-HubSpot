# This is my package laravel-hubspot

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tappnetwork/laravel-hubspot.svg?style=flat-square)](https://packagist.org/packages/tappnetwork/laravel-hubspot)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/tappnetwork/laravel-hubspot/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/tappnetwork/laravel-hubspot/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/tappnetwork/laravel-hubspot/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/tappnetwork/laravel-hubspot/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/tappnetwork/laravel-hubspot.svg?style=flat-square)](https://packagist.org/packages/tappnetwork/laravel-hubspot)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

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
    'id' => env('HUBSPOT_ID'),
    'api_key' => env('HUBSPOT_TOKEN'),
    'log_requests' => env('HUBSPOT_LOG_REQUESTS', false),
    'property_group' => env('HUBSPOT_PROPERTY_GROUP', 'app_user_profile'),
    'property_group_label' => env('HUBSPOT_PROPERTY_GROUP_LABEL', 'App User Profile'),
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

### Create HubSpot Properties
run the following command to create the property group and properties.

``` bash
php artisan hubspot:sync-properties
```

### Sync to HubSpot
The package uses model events to create or update contacts in HubSpot. Try registering a user and see that they have been created in HubSpot with properties from the $hubspotMap array.

## Testing

```bash
composer test
```

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
