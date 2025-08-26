<?php

namespace Tapp\LaravelHubspot\Services;

use HubSpot\Client\Crm\Associations\V4\ApiException as AssociationsApiException;
use HubSpot\Client\Crm\Associations\V4\Model\AssociationSpec;
use HubSpot\Client\Crm\Companies\ApiException as CompaniesApiException;
use HubSpot\Client\Crm\Companies\Model\Filter;
use HubSpot\Client\Crm\Companies\Model\FilterGroup;
use HubSpot\Client\Crm\Companies\Model\PublicObjectSearchRequest as CompanySearch;
use Illuminate\Support\Facades\Log;
use Tapp\LaravelHubspot\Facades\Hubspot;

class HubspotCompanyService
{
    /**
     * Create or find a company in HubSpot.
     */
    public function createOrFindCompany(array $companyData): ?string
    {
        try {
            // Extract company name - handle translatable fields
            $companyName = $this->extractCompanyName($companyData['name'] ?? '');

            Log::info('Looking for existing company', [
                'company_name' => $companyName,
                'company_data' => $companyData,
            ]);

            // First try to find existing company by name
            try {
                $existingCompany = $this->findCompanyByName($companyName);
                if ($existingCompany) {
                    Log::info('Found existing company', [
                        'company_name' => $companyName,
                        'hubspot_id' => $existingCompany['id'],
                    ]);

                    return $existingCompany['id'];
                }
            } catch (CompaniesApiException $e) {
                // If it's a rate limit, re-throw to let the job retry
                if ($e->getCode() === 429) {
                    throw $e;
                }
                // For other API errors, log and continue to create
                Log::warning('API error during company search, will attempt to create', [
                    'company_name' => $companyName,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('No existing company found, creating new one', [
                'company_name' => $companyName,
            ]);

            // Small delay to prevent race conditions
            usleep(100000); // 0.1 second delay

            // Double-check if company was created by another process
            $existingCompany = $this->findCompanyByName($companyName);
            if ($existingCompany) {
                Log::info('Company was created by another process', [
                    'company_name' => $companyName,
                    'hubspot_id' => $existingCompany['id'],
                ]);

                return $existingCompany['id'];
            }

            // Create new company if not found
            $properties = [
                'name' => $companyName,
            ];

            // Add additional properties if available
            if (! empty($companyData['address'])) {
                $properties['address'] = $companyData['address'];
            }
            if (! empty($companyData['city'])) {
                $properties['city'] = $companyData['city'];
            }
            if (! empty($companyData['state'])) {
                $properties['state'] = $companyData['state'];
            }
            if (! empty($companyData['zip'])) {
                $properties['zip'] = $companyData['zip'];
            }

            // Try to create the company, handle duplicates gracefully
            try {
                $newCompany = Hubspot::crm()->companies()->basicApi()->create(['properties' => $properties]);

                // Check if response is an Error object
                if ($newCompany instanceof \HubSpot\Client\Crm\Companies\Model\Error) {
                    throw new \Exception('HubSpot API returned an error: '.$newCompany->getMessage());
                }

                $companyId = is_array($newCompany) ? $newCompany['id'] : $newCompany->getId();

                Log::info('Created new company in HubSpot', [
                    'company_name' => $companyName,
                    'hubspot_id' => $companyId,
                ]);

                return $companyId;
            } catch (CompaniesApiException $e) {
                // Handle 409 conflict (duplicate company created by another process)
                if ($e->getCode() === 409) {
                    Log::info('Company creation conflict, searching for existing company', [
                        'company_name' => $companyName,
                    ]);

                    // Wait a moment and search again
                    usleep(200000); // 0.2 second delay
                    $existingCompany = $this->findCompanyByName($companyName);
                    if ($existingCompany) {
                        Log::info('Found company after conflict resolution', [
                            'company_name' => $companyName,
                            'hubspot_id' => $existingCompany['id'],
                        ]);

                        return $existingCompany['id'];
                    }
                }

                // Re-throw other errors
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Failed to create or find company in HubSpot', [
                'company_name' => $companyData['name'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Find company by name in HubSpot.
     */
    public function findCompanyByName(string $name): ?array
    {
        try {
            // Clean the name for better matching
            $cleanName = $this->cleanCompanyName($name);

            // First try exact match
            $filter = new Filter([
                'value' => $cleanName,
                'property_name' => 'name',
                'operator' => 'EQ',
            ]);

            $filterGroup = new FilterGroup([
                'filters' => [$filter],
            ]);

            $companySearch = new CompanySearch([
                'filter_groups' => [$filterGroup],
            ]);

            $searchResults = Hubspot::crm()->companies()->searchApi()->doSearch($companySearch);

            if ($searchResults['total'] > 0) {
                $result = $searchResults['results'][0];

                // Convert object to array if needed
                if (is_object($result)) {
                    $result = [
                        'id' => $result->getId(),
                        'properties' => $result->getProperties() ?? [],
                    ];
                }

                Log::info('Found company by exact name match', [
                    'search_name' => $cleanName,
                    'found_name' => $result['properties']['name'] ?? 'unknown',
                    'company_id' => $result['id'],
                ]);

                return $result;
            }

            // If no exact match, try partial match
            $filter = new Filter([
                'value' => $cleanName,
                'property_name' => 'name',
                'operator' => 'CONTAINS_TOKEN',
            ]);

            $filterGroup = new FilterGroup([
                'filters' => [$filter],
            ]);

            $companySearch = new CompanySearch([
                'filter_groups' => [$filterGroup],
            ]);

            $searchResults = Hubspot::crm()->companies()->searchApi()->doSearch($companySearch);

            if ($searchResults['total'] > 0) {
                // Find the best match by comparing names
                $bestMatch = $this->findBestNameMatch($cleanName, $searchResults['results']);
                if ($bestMatch) {
                    // Convert object to array if needed
                    if (is_object($bestMatch)) {
                        $bestMatch = [
                            'id' => $bestMatch->getId(),
                            'properties' => $bestMatch->getProperties() ?? [],
                        ];
                    }

                    Log::info('Found company by partial name match', [
                        'search_name' => $cleanName,
                        'found_name' => $bestMatch['properties']['name'] ?? 'unknown',
                        'company_id' => $bestMatch['id'],
                    ]);

                    return $bestMatch;
                }
            }
        } catch (CompaniesApiException $e) {
            // Handle rate limiting specifically - retry the job
            if ($e->getCode() === 429) {
                Log::warning('Rate limit hit while searching for company, will retry', [
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);
                throw $e; // Let the job retry mechanism handle it
            }

            // Handle 404 - company not found, this is expected
            if ($e->getCode() === 404) {
                Log::info('Company not found by name (404), will create new one', [
                    'name' => $name,
                ]);

                return null; // Return null to indicate company should be created
            }

            // Handle other API errors
            Log::warning('API error while searching for company by name', [
                'name' => $name,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            throw $e; // Re-throw other API errors
        } catch (\Exception $e) {
            Log::warning('Unexpected error while searching for company by name', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return null;
    }

    /**
     * Associate a company with a contact in HubSpot.
     */
    public function associateCompanyWithContact(string $companyId, string $contactId): void
    {
        try {
            // First verify the company exists in HubSpot
            try {
                $company = Hubspot::crm()->companies()->basicApi()->getById($companyId);

                // Check if response is an Error object
                if ($company instanceof \HubSpot\Client\Crm\Companies\Model\Error) {
                    throw new \Exception('HubSpot API returned an error: '.$company->getMessage());
                }

                Log::info('Company verified in HubSpot before association', [
                    'company_id' => $companyId,
                    'company_name' => $company->getProperties()['name'] ?? 'unknown',
                ]);
            } catch (CompaniesApiException $e) {
                if ($e->getCode() === 404) {
                    Log::error('Company does not exist in HubSpot, cannot associate', [
                        'company_id' => $companyId,
                        'contact_id' => $contactId,
                    ]);
                    throw new \Exception("Company with ID {$companyId} does not exist in HubSpot");
                }
                throw $e;
            } catch (\Exception $e) {
                // Re-throw any other exceptions
                throw $e;
            }

            $associationSpec = new AssociationSpec([
                'association_category' => 'HUBSPOT_DEFINED',
                'association_type_id' => 1, // Company to Contact association
            ]);

            Hubspot::crm()->associations()->v4()->basicApi()->create(
                'contact',
                $contactId,
                'company',
                $companyId,
                [$associationSpec]
            );
        } catch (AssociationsApiException $e) {
            Log::error('Failed to associate company with contact', [
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Clean company name for better matching.
     */
    protected function cleanCompanyName(string $name): string
    {
        // Convert to lowercase and trim whitespace
        $name = strtolower(trim($name));

        // Remove common punctuation and extra spaces
        $name = preg_replace('/[^\w\s]/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    /**
     * Find the best matching company name from search results.
     */
    protected function findBestNameMatch(string $searchName, array $results): ?array
    {
        $searchName = strtolower($searchName);
        $bestMatch = null;
        $bestScore = 0;

        foreach ($results as $result) {
            // Convert object to array if needed
            if (is_object($result)) {
                $resultName = strtolower($result->getProperties()['name'] ?? '');
            } else {
                $resultName = strtolower($result['properties']['name'] ?? '');
            }

            $score = similar_text($searchName, $resultName, $percent);

            if ($percent > $bestScore && $percent > 80) { // Require at least 80% similarity
                $bestScore = $percent;
                $bestMatch = $result;
            }
        }

        return $bestMatch;
    }

    /**
     * Extract company name from translatable field or string.
     */
    protected function extractCompanyName($name): string
    {
        if (is_array($name)) {
            // Handle translatable fields - try to get the first available language
            if (isset($name['en'])) {
                return $name['en'];
            }

            // If no 'en' key, get the first value
            return (string) reset($name);
        }

        return (string) $name;
    }
}
