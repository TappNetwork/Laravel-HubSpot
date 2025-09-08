# Upgrade Guide

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
