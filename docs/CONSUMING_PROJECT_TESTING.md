# Testing Laravel HubSpot Package in a Consuming Project

This guide explains how to test the Laravel HubSpot package in a real Laravel application to ensure it works correctly in production scenarios.

## 🎯 Testing Strategy

### Phase 1: Setup and Cleanup
1. **Clean HubSpot Test Account** - Remove all existing test data
2. **Sync HubSpot Properties** - Ensure all custom properties are available
3. **Verify Configuration** - Test package configuration and connections

### Phase 2: Core Functionality Testing
1. **Contact Sync Command** - Test bulk contact synchronization
2. **User Registration Flow** - Test new user + company creation
3. **Data Updates** - Test updating existing records
4. **Error Handling** - Test various error scenarios

### Phase 3: Integration Testing
1. **Queue Jobs** - Test background processing
2. **Model Observers** - Test automatic sync triggers
3. **API Rate Limiting** - Test with realistic data volumes

## 🚀 Step-by-Step Testing Process

### Step 1: Clean HubSpot Test Account

First, clean your test HubSpot account to start with a fresh slate:

```bash
# From the package directory
php scripts/clean-hubspot-test-account.php
```

This script will:
- Delete all contacts from the test account
- Delete all companies from the test account
- Provide a summary of what was cleaned

### Step 2: Configure Your Consuming Project

In your Laravel application, ensure you have the package properly configured:

```php
// config/hubspot.php
return [
    'api_key' => env('HUBSPOT_API_KEY'),
    'disabled' => env('HUBSPOT_DISABLED', false),
    'queue_connection' => env('HUBSPOT_QUEUE_CONNECTION', 'default'),
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

### Step 3: Sync HubSpot Properties

Run the property sync command to ensure all custom properties exist:

```bash
php artisan hubspot:sync-properties
```

This will:
- Create the property group if it doesn't exist
- Create all configured contact properties
- Create all configured company properties
- Show a summary of what was created/updated

### Step 4: Test Contact Sync Command

Test the bulk contact synchronization:

```bash
# Fast sync (no delays by default)
php artisan hubspot:sync-contacts App\Models\User

# Sync with rate limiting (optional)
php artisan hubspot:sync-contacts App\Models\User --limit=10 --delay=1

# Sync with custom delay
php artisan hubspot:sync-contacts App\Models\User --delay=2
```

**Expected Results:**
- Contacts should be created in HubSpot
- HubSpot IDs should be saved to your local database
- Check HubSpot dashboard to verify contacts appear

**Note**: The command runs without delays by default for faster execution. If you encounter rate limiting errors, add the `--delay=1` option to add 1-second delays between API calls.

### Step 5: Test User Registration Flow

Create a test user registration that triggers the HubSpot integration:

```php
// In your User model
use Tapp\LaravelHubspot\Models\HubspotContact;

class User extends Authenticatable
{
    use HubspotContact;
    
    // ... your existing code
}

// In your Company model
use Tapp\LaravelHubspot\Models\HubspotCompany;

class Company extends Model
{
    use HubspotCompany;
    
    // ... your existing code
}

// Test registration
$company = Company::create([
    'name' => 'Test Company Inc.',
    'domain' => 'testcompany.com',
    'phone' => '+1-555-0123',
    'city' => 'Test City',
    'state' => 'TS',
    'country' => 'US',
]);

$user = User::create([
    'name' => 'John Doe',
    'email' => 'john.doe@testcompany.com',
    'phone' => '+1-555-0124',
    'company_id' => $company->id,
]);
```

**Expected Results:**
- Company should be created in HubSpot
- User should be created in HubSpot
- User should be associated with the company
- HubSpot IDs should be saved to local database

### Step 6: Test Data Updates

Test updating existing records:

```php
// Update user
$user->update([
    'name' => 'John Smith',
    'phone' => '+1-555-0125',
]);

// Update company
$company->update([
    'name' => 'Updated Company Inc.',
    'phone' => '+1-555-0126',
]);
```

**Expected Results:**
- Changes should be reflected in HubSpot
- Updates should be processed via queue jobs
- Check HubSpot dashboard for updated information

### Step 7: Test Error Scenarios

Test various error conditions:

```php
// Test duplicate email
$duplicateUser = User::create([
    'name' => 'Jane Doe',
    'email' => 'john.doe@testcompany.com', // Same email
    'phone' => '+1-555-0127',
]);

// Test invalid data
$invalidUser = User::create([
    'name' => 'Invalid User',
    'email' => 'invalid-email', // Invalid email
    'phone' => 'not-a-phone',
]);
```

**Expected Results:**
- Duplicate emails should be handled gracefully
- Invalid data should trigger validation errors
- Errors should be logged appropriately

## 🔍 Verification Checklist

After running through the testing process, verify:

### ✅ HubSpot Dashboard
- [ ] Contacts appear in HubSpot CRM
- [ ] Companies appear in HubSpot CRM
- [ ] Contact-company associations are correct
- [ ] Custom properties are populated correctly
- [ ] Data updates are reflected in real-time

### ✅ Local Database
- [ ] HubSpot IDs are saved to local records
- [ ] Queue jobs are processed successfully
- [ ] Error logs contain appropriate messages
- [ ] No duplicate records are created

### ✅ Performance
- [ ] API calls are rate-limited appropriately
- [ ] Queue jobs process in reasonable time
- [ ] No memory leaks or excessive resource usage
- [ ] Database queries are optimized

## 🐛 Troubleshooting

### Common Issues

1. **API Key Issues**
   ```bash
   # Check API key configuration
   php artisan tinker
   >>> config('hubspot.api_key')
   ```

2. **Property Sync Issues**
   ```bash
   # Re-run property sync with verbose output
   php artisan hubspot:sync-properties -v
   ```

3. **Queue Issues**
   ```bash
   # Check queue status
   php artisan queue:work --once
   php artisan queue:failed
   ```

4. **Database Issues**
   ```bash
   # Check migrations
   php artisan migrate:status
   php artisan migrate
   ```

### Debug Commands

```bash
# Debug HubSpot data
php artisan hubspot:debug

# Test API connection
php artisan tinker
>>> Hubspot::crm()->contacts()->basicApi()->getPage(1)
```

## 📊 Testing Metrics

Track these metrics during testing:

- **Success Rate**: Percentage of successful API calls
- **Error Rate**: Percentage of failed operations
- **Processing Time**: Average time for sync operations
- **Queue Performance**: Job processing speed
- **API Usage**: Rate limit compliance

## 🎯 Next Steps

After successful testing:

1. **Production Deployment**: Deploy to staging/production
2. **Monitoring Setup**: Configure error tracking and monitoring
3. **Documentation**: Update team documentation
4. **Training**: Train team on HubSpot integration features

## 📝 Test Data Management

For ongoing testing, consider:

- Using a dedicated HubSpot test account
- Creating test data factories for consistent testing
- Implementing automated cleanup scripts
- Setting up test environment isolation

This comprehensive testing approach ensures your Laravel HubSpot integration works reliably in production environments.
