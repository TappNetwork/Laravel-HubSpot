# Quick Start: Testing in Consuming Project

## 🚀 Fast Testing Checklist

### 1. Clean HubSpot Test Account
```bash
# From package directory
php scripts/clean-hubspot-test-account.php
```

### 2. Configure Your Laravel App
```php
// config/hubspot.php
return [
    'api_key' => env('HUBSPOT_API_KEY'),
    'disabled' => env('HUBSPOT_DISABLED', false),
    'queue_connection' => env('HUBSPOT_QUEUE_CONNECTION', 'sync'),
    'property_group_label' => env('HUBSPOT_PROPERTY_GROUP_LABEL', 'Laravel HubSpot'),
    'contact_properties' => [
        'email' => 'email',
        'firstname' => 'firstname', 
        'lastname' => 'lastname',
        'phone' => 'phone',
        'company' => 'company',
    ],
    'company_properties' => [
        'name' => 'name',
        'domain' => 'domain',
        'phone' => 'phone',
        'city' => 'city',
        'state' => 'state',
        'country' => 'country',
    ],
];
```

### 3. Add Traits to Your Models
```php
// User.php
use Tapp\LaravelHubspot\Models\HubspotContact;

class User extends Authenticatable
{
    use HubspotContact;
    // ... existing code
}

// Company.php  
use Tapp\LaravelHubspot\Models\HubspotCompany;

class Company extends Model
{
    use HubspotCompany;
    // ... existing code
}
```

### 4. Run Property Sync
```bash
php artisan hubspot:sync-properties
```

### 5. Test Contact Sync
```bash
# Fast sync (no delays)
php artisan hubspot:sync-contacts App\Models\User

# Slower sync with rate limiting (optional)
php artisan hubspot:sync-contacts App\Models\User --delay=1
```

### 6. Test User Registration
```php
// Create test company
$company = Company::create([
    'name' => 'Test Company Inc.',
    'domain' => 'testcompany.com',
    'phone' => '+1-555-0123',
    'city' => 'Test City',
    'state' => 'TS',
    'country' => 'US',
]);

// Create test user
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john.doe@testcompany.com',
    'phone' => '+1-555-0124',
    'company_id' => $company->id,
]);
```

### 7. Verify in HubSpot Dashboard
- ✅ Contacts appear in HubSpot CRM
- ✅ Companies appear in HubSpot CRM  
- ✅ Contact-company associations are correct
- ✅ HubSpot IDs saved to local database

## 🔧 Troubleshooting

### API Key Issues
```bash
php artisan tinker
>>> config('hubspot.api_key')
```

### Property Sync Issues
```bash
php artisan hubspot:sync-properties -v
```

### Debug HubSpot Data
```bash
php artisan hubspot:debug
```

## 📊 Expected Results

- **Contacts**: Created in HubSpot with all properties
- **Companies**: Created in HubSpot with all properties  
- **Associations**: Users linked to companies
- **Queue Jobs**: Processed successfully (if using queues)
- **Database**: HubSpot IDs saved to local records

## 🎯 Next Steps

1. Test data updates
2. Test error scenarios
3. Test queue processing
4. Monitor API usage
5. Deploy to production

For detailed testing guide, see `docs/CONSUMING_PROJECT_TESTING.md`
