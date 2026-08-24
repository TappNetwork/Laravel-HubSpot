<?php

namespace Tapp\LaravelHubspot\Traits;

trait HubspotModelTrait
{
    /**
     * Get the HubSpot property mapping for this model.
     */
    public function getHubspotMap(): array
    {
        return $this->hubspotMap ?? [];
    }

    /**
     * Get the HubSpot update property mapping for this model.
     */
    public function getHubspotUpdateMap(): array
    {
        return $this->hubspotUpdateMap ?? [];
    }

    /**
     * Get the HubSpot company relation for this model.
     */
    public function getHubspotCompanyRelation(): ?string
    {
        return $this->hubspotCompanyRelation ?? null;
    }

    /**
     * Model fields that stay in `$hubspotMap` (so they still sync) but do not
     * trigger an observer job when they are the only dirty attributes.
     *
     * @return list<string>
     */
    public function getHubspotSyncIgnoredFields(): array
    {
        $fields = $this->hubspotSyncIgnoredFields ?? [];

        if (! is_array($fields)) {
            return [];
        }

        return array_values(array_filter($fields, fn (mixed $field): bool => is_string($field) && $field !== ''));
    }

    /**
     * Get dynamic HubSpot properties for this model.
     */
    public function getHubspotProperties(array $hubspotMap): array
    {
        return [];
    }

    /**
     * Column name for the HubSpot ID on this model. Override in HubspotCompany to use company_id_column.
     */
    protected function getHubspotIdColumn(): string
    {
        return config('hubspot.contact_id_column', 'hubspot_id');
    }

    /**
     * Get the HubSpot ID for this model.
     * Uses getHubspotIdColumn() so projects can configure column name (e.g. hubspot_contact_id).
     */
    public function getHubspotId(): ?string
    {
        $value = $this->getAttribute($this->getHubspotIdColumn());

        return $value !== null ? (string) $value : null;
    }

    /**
     * Set the HubSpot ID for this model.
     */
    public function setHubspotId(?string $hubspotId): void
    {
        $this->setAttribute($this->getHubspotIdColumn(), $hubspotId);
    }
}
