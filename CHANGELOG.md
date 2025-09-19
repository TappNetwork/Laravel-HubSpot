# Changelog

All notable changes to `Laravel-Hubspot` will be documented in this file.

## v2.0.0 - 2025-01-XX

### ⚠️ BREAKING CHANGES

This is a major version release with significant breaking changes. Please review the migration guide in the README.

### What's Changed

#### Removed Deprecated Code
- **Removed all synchronous methods** from `HubspotContact` and `HubspotCompany` traits
- **Removed trait boot methods** that automatically handled model events
- **Removed duplicate property conversion logic** from multiple classes
- **Removed duplicate company search logic** from job classes

#### Architecture Improvements
- **Created `PropertyConverter` class** to consolidate property conversion logic
- **Simplified trait architecture** - traits now only handle property mapping and data access
- **Updated commands** to use services instead of deprecated trait methods
- **Removed ~800-1000 lines** of deprecated and duplicate code

#### New Requirements
- **Models must implement `HubspotModelInterface`** for automatic sync
- **Observers must be registered** in `AppServiceProvider` for automatic sync
- **Use services directly** for programmatic API operations

### Migration Required

See the [Migration Guide](README.md#breaking-changes-in-v20) in the README for detailed upgrade instructions.

### Benefits
- **Cleaner architecture** with clear separation of concerns
- **Better maintainability** with single source of truth for each responsibility
- **Improved performance** with optimized property conversion
- **Enhanced testability** with focused, single-purpose classes

## v1.1.4 - 2025-09-08

### What's Changed

* Bump actions/checkout from 4 to 5 by @dependabot[bot] in https://github.com/TappNetwork/Laravel-HubSpot/pull/25

**Full Changelog**: https://github.com/TappNetwork/Laravel-HubSpot/compare/v1.1.3...v1.1.4

## v1.1.3 - 2025-08-26

### What's Changed

* simplify implementation by @scottgrayson in https://github.com/TappNetwork/Laravel-HubSpot/pull/27
* queue by @scottgrayson in https://github.com/TappNetwork/Laravel-HubSpot/pull/26

**Full Changelog**: https://github.com/TappNetwork/Laravel-HubSpot/compare/v1.1.2...v1.1.3

## v1.1.2 - 2025-08-20

### What's Changed

* company sync updates by @scottgrayson in https://github.com/TappNetwork/Laravel-HubSpot/pull/24

**Full Changelog**: https://github.com/TappNetwork/Laravel-HubSpot/compare/v1.1.1...v1.1.2

## v1.1.1 - 2025-08-20

### What's Changed

* Bump stefanzweifel/git-auto-commit-action from 5 to 6 by @dependabot[bot] in https://github.com/TappNetwork/Laravel-HubSpot/pull/18
* Sync Updates: Fix hubspot_id persistence and API exception handling by @scottgrayson in https://github.com/TappNetwork/Laravel-HubSpot/pull/23

**Full Changelog**: https://github.com/TappNetwork/Laravel-HubSpot/compare/v1.1.0...v1.1.1

## v1.1.0 - 2025-08-01

### What's Changed

* Bump aglipanci/laravel-pint-action from 2.5 to 2.6 by @dependabot[bot] in https://github.com/TappNetwork/Laravel-HubSpot/pull/21
* Add hubspot id to config by @swilla in https://github.com/TappNetwork/Laravel-HubSpot/pull/22

**Full Changelog**: https://github.com/TappNetwork/Laravel-HubSpot/compare/v1.0.15...v1.1.0

## v1.0.15 - 2025-07-08

### What's Changed

* Bump dependabot/fetch-metadata from 2.3.0 to 2.4.0 by @dependabot in https://github.com/TappNetwork/Laravel-HubSpot/pull/16
* Use HubSpot properties method instead of array for property sync. by @scottgrayson in https://github.com/TappNetwork/Laravel-HubSpot/pull/19
* Hubspot update map by @scottgrayson in https://github.com/TappNetwork/Laravel-HubSpot/pull/20

**Full Changelog**: https://github.com/TappNetwork/Laravel-HubSpot/compare/v1.0.14...v1.0.15

## v1.0.14 - 2025-05-09

### What's Changed

* update readme to reflect proper hubspot namespace by @johnwesely in https://github.com/TappNetwork/Laravel-HubSpot/pull/13
* allow different property groups for update and create by @scottgrayson in https://github.com/TappNetwork/Laravel-HubSpot/pull/15

### New Contributors

* @johnwesely made their first contribution in https://github.com/TappNetwork/Laravel-HubSpot/pull/13

**Full Changelog**: https://github.com/TappNetwork/Laravel-HubSpot/compare/v1.0.13...v1.0.14

## v1.0.13 - 2025-02-06

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.12...v1.0.13

## v1.0.12 - 2025-02-06

### What's Changed

* Bump dependabot/fetch-metadata from 2.2.0 to 2.3.0 by @dependabot in https://github.com/TappNetwork/Laravel-Hubspot/pull/11
* Bump aglipanci/laravel-pint-action from 2.4 to 2.5 by @dependabot in https://github.com/TappNetwork/Laravel-Hubspot/pull/12

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.11...v1.0.12

## v1.0.11 - 2025-01-22

### What's Changed

* Better Exception Logging by @swilla in https://github.com/TappNetwork/Laravel-Hubspot/pull/10

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.10...v1.0.11

## v1.0.10 - 2025-01-22

### What's Changed

* return after company update exception by @swilla in https://github.com/TappNetwork/Laravel-Hubspot/pull/9

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.9...v1.0.10

## v1.0.9 - 2025-01-14

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.8...v1.0.9

## v1.0.8 - 2025-01-13

### What's Changed

* Hubspot Company by @scottgrayson in https://github.com/TappNetwork/Laravel-Hubspot/pull/8
* Update hubspot/api-client requirement from ^11.1 to ^12.0 by @dependabot in https://github.com/TappNetwork/Laravel-Hubspot/pull/5

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.6...v1.0.8

## v1.0.7 - 2025-01-13

### What's Changed

* Hubspot Company by @scottgrayson in https://github.com/TappNetwork/Laravel-Hubspot/pull/8

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.6...v1.0.7

## v1.0.6 - 2024-12-23

### What's Changed

* Action updates by @swilla in https://github.com/TappNetwork/Laravel-Hubspot/pull/7

### New Contributors

* @swilla made their first contribution in https://github.com/TappNetwork/Laravel-Hubspot/pull/7

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.5...v1.0.6

## v1.0.5 - 2024-12-03

### What's Changed

* sync hubspot contacts by @scottgrayson in https://github.com/TappNetwork/Laravel-Hubspot/pull/6

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.4...v1.0.5

## v1.0.4 - 2024-10-25

### What's Changed

* Bump dependabot/fetch-metadata from 2.1.0 to 2.2.0 by @dependabot in https://github.com/TappNetwork/Laravel-Hubspot/pull/2
* getById instead of email by @scottgrayson in https://github.com/TappNetwork/Laravel-Hubspot/pull/4

### New Contributors

* @scottgrayson made their first contribution in https://github.com/TappNetwork/Laravel-Hubspot/pull/4

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.3...v1.0.4

## v1.0.3 - 2024-06-27

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.2...v1.0.3

## v1.0.2 - 2024-06-03

**Full Changelog**: https://github.com/TappNetwork/Laravel-Hubspot/compare/v1.0.1...v1.0.2

## v1.0.1 - 2024-05-08

bugfix
