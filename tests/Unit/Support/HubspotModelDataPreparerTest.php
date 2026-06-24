<?php

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Tapp\LaravelHubspot\Commands\SyncHubspotContacts;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;
use Tapp\LaravelHubspot\Observers\HubspotContactObserver;
use Tapp\LaravelHubspot\Services\HubspotContactService;
use Tapp\LaravelHubspot\Support\HubspotModelDataPreparer;
use Tapp\LaravelHubspot\Traits\HubspotModelTrait;

class HubspotParityTestModel extends Model implements HubspotModelInterface
{
    use HubspotModelTrait;

    protected $guarded = [];

    protected $table = 'hubspot_parity_test_models';

    public array $hubspotMap = [
        'email' => 'email',
        'organization' => 'organization_name',
        'user_type' => 'type.name',
    ];

    public function getHubspotProperties(array $map): array
    {
        return [
            'course_progress' => '75%',
        ];
    }

    public function hubspotProperties(array $map): array
    {
        return [
            'certification_status' => 'Active',
        ];
    }

    protected function organizationName(): Attribute
    {
        return Attribute::get(fn () => $this->organization?->name);
    }
}

function makeParityTestModel(): HubspotParityTestModel
{
    $typeModel = new class extends Model
    {
        protected $guarded = [];
    };
    $typeModel->forceFill(['name' => 'Coordinator']);

    $organizationModel = new class extends Model
    {
        protected $guarded = [];
    };
    $organizationModel->forceFill(['name' => 'Acme Health']);

    $model = new HubspotParityTestModel;
    $model->forceFill([
        'id' => 42,
        'email' => 'user@example.com',
    ]);
    $model->setRelation('organization', $organizationModel);
    $model->setRelation('type', $typeModel);

    return $model;
}

test('preparer resolves accessor and dot notation mapped fields', function () {
    $model = makeParityTestModel();

    $data = HubspotModelDataPreparer::fromModel($model);

    expect($data['email'])->toBe('user@example.com');
    expect($data['organization_name'])->toBe('Acme Health');
    expect($data['type.name'])->toBe('Coordinator');
    expect($data['dynamicProperties']['course_progress'])->toBe('75%');
    expect($data['dynamicProperties']['certification_status'])->toBe('Active');
    expect($data['dynamicProperties'])->not->toHaveKey('organization');
    expect($data['dynamicProperties'])->not->toHaveKey('user_type');
});

test('bulk and observer produce identical sync payloads', function () {
    $model = makeParityTestModel();

    $command = new SyncHubspotContacts;
    $commandReflection = new ReflectionClass($command);
    $prepareContactData = $commandReflection->getMethod('prepareContactData');
    $prepareContactData->setAccessible(true);

    $observer = new HubspotContactObserver;
    $observerReflection = new ReflectionClass($observer);
    $prepareJobData = $observerReflection->getMethod('prepareJobData');
    $prepareJobData->setAccessible(true);

    $bulkData = $prepareContactData->invoke($command, $model);
    $observerData = $prepareJobData->invoke($observer, $model);

    expect($bulkData)->toBe($observerData);
});

test('preparer returns empty array for non hubspot models', function () {
    $model = new class extends Model
    {
        protected $guarded = [];
    };

    expect(HubspotModelDataPreparer::fromModel($model))->toBe([]);
});

test('preparer output includes accessor fields in hubspot properties object', function () {
    $model = makeParityTestModel();
    $data = HubspotModelDataPreparer::fromModel($model);

    $service = app(HubspotContactService::class);
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('buildPropertiesObject');
    $method->setAccessible(true);

    $properties = $method->invoke($service, $model->getHubspotMap(), $data);

    expect($properties->getProperties()['organization'])->toBe('Acme Health');
    expect($properties->getProperties()['email'])->toBe('user@example.com');
    expect($properties->getProperties()['course_progress'])->toBe('75%');
});
