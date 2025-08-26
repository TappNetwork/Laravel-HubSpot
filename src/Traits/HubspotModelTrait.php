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
     * Get dynamic HubSpot properties for this model.
     */
    public function getHubspotProperties(array $hubspotMap): array
    {
        return [];
    }

    /**
     * Get the HubSpot ID for this model.
     */
    public function getHubspotId(): ?string
    {
        return $this->hubspot_id ?? null;
    }

    /**
     * Set the HubSpot ID for this model.
     */
    public function setHubspotId(?string $hubspotId): void
    {
        $this->hubspot_id = $hubspotId;
    }
}
