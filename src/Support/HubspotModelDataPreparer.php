<?php

namespace Tapp\LaravelHubspot\Support;

use Illuminate\Database\Eloquent\Model;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;

class HubspotModelDataPreparer
{
    /**
     * Build a flat data array for HubSpot contact sync from a model.
     *
     * @return array<string, mixed>
     */
    public static function fromModel(Model $model): array
    {
        if (! $model instanceof HubspotModelInterface) {
            return [];
        }

        $map = $model->getHubspotMap();

        $data = [
            'id' => $model->getKey(),
            'hubspot_id' => $model->getHubspotId(),
            'hubspotMap' => $map,
            'hubspotUpdateMap' => $model->getHubspotUpdateMap(),
            'hubspotCompanyRelation' => $model->getHubspotCompanyRelation(),
        ];

        foreach ($map as $hubspotField => $modelField) {
            $data[$modelField] = data_get($model, $modelField);
        }

        $dynamicProperties = $model->getHubspotProperties($map);

        if (method_exists($model, 'hubspotProperties')) {
            $dynamicProperties = array_merge(
                $dynamicProperties,
                $model->hubspotProperties($map)
            );
        }

        if ($dynamicProperties !== []) {
            $data['dynamicProperties'] = [];

            foreach ($dynamicProperties as $hubspotField => $value) {
                if (! array_key_exists($hubspotField, $map)) {
                    $data['dynamicProperties'][$hubspotField] = $value;

                    continue;
                }

                $modelField = $map[$hubspotField];

                if (data_get($data, $modelField) === null && $value !== null) {
                    $data['dynamicProperties'][$hubspotField] = $value;
                }
            }
        }

        $companyRelation = $model->getHubspotCompanyRelation();

        if (! empty($companyRelation)) {
            $company = $model->getRelationValue($companyRelation);

            if ($company) {
                $data['hubspotCompanyRelation'] = [
                    'id' => $company->getKey(),
                    'hubspot_id' => $company instanceof HubspotModelInterface
                        ? $company->getHubspotId()
                        : ($company->hubspot_id ?? null),
                    'name' => $company->name ?? $company->getAttribute('name'),
                ];
            }
        }

        return $data;
    }
}
