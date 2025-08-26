<?php

namespace Tapp\LaravelHubspot\Contracts;

interface HubspotModelInterface
{
    /**
     * Get the HubSpot property mapping for this model.
     */
    public function getHubspotMap(): array;

    /**
     * Get the HubSpot update property mapping for this model.
     */
    public function getHubspotUpdateMap(): array;

    /**
     * Get the HubSpot company relation for this model.
     */
    public function getHubspotCompanyRelation(): ?string;

    /**
     * Get dynamic HubSpot properties for this model.
     */
    public function getHubspotProperties(array $hubspotMap): array;

    /**
     * Get the HubSpot ID for this model.
     */
    public function getHubspotId(): ?string;

    /**
     * Set the HubSpot ID for this model.
     */
    public function setHubspotId(?string $hubspotId): void;
}
