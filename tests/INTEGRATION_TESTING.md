# HubSpot API Integration Testing

This document explains how to set up and run comprehensive tests that can easily switch between mocked and real HubSpot API calls.

## Setup

### 1. Create Test Environment File

Create a `.env.testing` file in your project root with the following configuration:

```env
# HubSpot API Configuration for Testing
HUBSPOT_TEST_API_KEY=your_test_api_key_here
HUBSPOT_DISABLED=false
HUBSPOT_LOG_REQUESTS=true

# Test Mode Configuration
HUBSPOT_USE_REAL_API=false  # Set to true to use real API calls instead of mocks

# Test Property Group Configuration
HUBSPOT_PROPERTY_GROUP=test_property_group
HUBSPOT_PROPERTY_GROUP_LABEL=Test Property Group

# Queue Configuration for Testing (disabled for faster tests)
HUBSPOT_QUEUE_ENABLED=false
HUBSPOT_QUEUE_CONNECTION=sync
HUBSPOT_QUEUE_NAME=default
HUBSPOT_QUEUE_RETRY_ATTEMPTS=1
HUBSPOT_QUEUE_RETRY_DELAY=1

# Database Configuration for Testing
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
```

### 2. Get HubSpot Test API Key

1. Go to your HubSpot Developer Account
2. Create a new Private App or use an existing one
3. Ensure it has the following scopes:
   - `crm.objects.contacts.read`
   - `crm.objects.contacts.write`
   - `crm.objects.companies.read`
   - `crm.objects.companies.write`
   - `crm.objects.owners.read`

### 3. Set Up Test Property Group

Before running integration tests, you need to create a test property group in HubSpot:

```bash
# Set your test API key
export HUBSPOT_TEST_API_KEY=your_test_api_key_here

# Run the property sync command
php artisan hubspot:sync-properties
```

## Running Tests

### Flexible Testing Mode

The package now supports flexible testing that can easily switch between mocked and real API calls:

```bash
# Run with mocks (fast, no API calls)
HUBSPOT_USE_REAL_API=false composer test

# Run with real API calls (requires API key)
HUBSPOT_USE_REAL_API=true composer test

# Run specific test categories
HUBSPOT_USE_REAL_API=false composer test-unit
HUBSPOT_USE_REAL_API=true composer test-integration
```

### Test Modes

#### Mocked Mode (Default)
- **Fast execution** - No external API calls
- **No API key required** - Works in any environment
- **CI/CD friendly** - Perfect for automated testing
- **Development friendly** - Quick feedback during development

#### Real API Mode
- **Actual API calls** - Tests real HubSpot integration
- **Requires API key** - Needs valid HubSpot test API key
- **Comprehensive testing** - Verifies complete functionality
- **Confidence building** - Ensures everything works with real API

### Run All Tests

```bash
# Run with test environment
php artisan test --env=testing

# Or run specific integration test
php artisan test tests/Integration/HubspotApiIntegrationTest.php --env=testing

# Run flexible tests (switches between mock/real based on config)
php artisan test tests/Unit/HubspotContactServiceFlexibleTest.php --env=testing
```

### Run Only Unit Tests (Skip Integration)

```bash
# Run tests but skip integration tests
php artisan test --exclude=Integration --env=testing
```

### Run with Coverage

```bash
# Run tests with coverage report
php artisan test --coverage --env=testing
```

## Test Categories

### Flexible Tests (`tests/Unit/HubspotContactServiceFlexibleTest.php`)

These tests automatically switch between mocked and real API calls based on configuration:

- **Automatic switching** - Uses `HUBSPOT_USE_REAL_API` environment variable
- **Same test logic** - Identical assertions for both modes
- **Easy comparison** - Run same tests with mocks vs real API
- **Confidence building** - Verify mocks match real API behavior

### Integration Tests (`tests/Integration/`)

These tests make actual API calls to HubSpot and should be run in a controlled environment:

- **API Connection Tests**: Verify basic connectivity
- **CRUD Operations**: Create, read, update, delete contacts and companies
- **Error Handling**: Test API error responses and edge cases
- **Rate Limiting**: Verify graceful handling of API limits
- **Data Validation**: Test property mapping and data conversion

### Unit Tests (`tests/Unit/`)

These tests mock the HubSpot API and test your package logic:

- **Service Layer**: Test business logic without API calls
- **Model Traits**: Test trait functionality with mocked data
- **Configuration**: Test config loading and validation
- **Queue Jobs**: Test job logic with mocked dependencies

## Best Practices

### 1. Test Mode Selection

- **Development**: Use mocked tests for fast feedback
- **Pre-deployment**: Use real API tests for confidence
- **CI/CD**: Use mocked tests for speed, real API tests on schedule
- **Debugging**: Use real API tests to verify actual behavior

### 2. Test Data Management

- Always use unique test data (use `uniqid()` for emails and names)
- Clean up test data after each test
- Use test-specific property groups to avoid conflicts

### 3. Rate Limiting

- Add delays between API calls in tests
- Use `usleep(100000)` for 0.1 second delays
- Test rate limiting scenarios gracefully

### 4. Error Handling

- Test both successful and failed API responses
- Verify proper exception handling
- Test edge cases like duplicate contacts

### 5. Test Isolation

- Each test should be independent
- Use database transactions where possible
- Clean up any created resources

### 6. Mock vs Real API Validation

- Run flexible tests in both modes to ensure consistency
- Use real API tests to validate mock behavior
- Update mocks when API behavior changes

## Continuous Integration

For CI/CD pipelines, consider:

1. **Separate Test Environment**: Use a dedicated HubSpot test account
2. **Mocked Tests**: Run unit tests without API calls
3. **Integration Test Schedule**: Run integration tests on a schedule, not on every commit
4. **Test Data Cleanup**: Ensure proper cleanup in CI environment

### Example CI/CD Workflow

```yaml
# GitHub Actions example
name: Tests

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]
  schedule:
    - cron: '0 2 * * *'  # Daily at 2 AM

jobs:
  unit-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Run Unit Tests (Mocked)
        run: |
          HUBSPOT_USE_REAL_API=false composer test-unit

  integration-tests:
    runs-on: ubuntu-latest
    if: github.event_name == 'schedule'  # Only run on schedule
    steps:
      - uses: actions/checkout@v3
      - name: Run Integration Tests (Real API)
        env:
          HUBSPOT_USE_REAL_API: true
          HUBSPOT_TEST_API_KEY: ${{ secrets.HUBSPOT_TEST_API_KEY }}
        run: |
          composer test-integration
```

## Troubleshooting

### Common Issues

1. **API Key Issues**: Ensure your test API key has proper permissions
2. **Rate Limiting**: Add delays between tests if hitting rate limits
3. **Property Group Issues**: Ensure test property group exists in HubSpot
4. **Test Data Conflicts**: Use unique identifiers for all test data

### Debug Mode

Enable debug logging for tests:

```env
HUBSPOT_LOG_REQUESTS=true
APP_DEBUG=true
```

This will log all HubSpot API requests and responses during tests.
