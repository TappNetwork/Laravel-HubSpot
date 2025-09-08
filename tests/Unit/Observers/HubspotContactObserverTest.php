<?php

use Illuminate\Database\Eloquent\Model;
use Tapp\LaravelHubspot\Contracts\HubspotModelInterface;
use Tapp\LaravelHubspot\Models\HubspotContact;
use Tapp\LaravelHubspot\Observers\HubspotContactObserver;
use Tapp\LaravelHubspot\Traits\HubspotModelTrait;

beforeEach(function () {
    $this->observer = new HubspotContactObserver;
});

// Test model that uses HubspotModelTrait to ensure it gets analyzed
class BaseModelTestModel extends Model implements HubspotModelInterface
{
    use HubspotModelTrait;

    protected $fillable = ['name', 'hubspot_id'];

    protected $table = 'base_model_test_models';

    public array $hubspotMap = [
        'name' => 'name',
    ];

    public function getHubspotMap(): array
    {
        return $this->hubspotMap;
    }

    public function getHubspotUpdateMap(): array
    {
        return $this->hubspotMap;
    }

    public function getHubspotCompanyRelation(): ?string
    {
        return null;
    }
}

// Test model that uses HubspotContact trait to ensure it gets analyzed
class ContactObserverTestModel extends Model implements HubspotModelInterface
{
    use HubspotContact;
    use HubspotModelTrait;

    protected $fillable = ['email', 'first_name', 'last_name', 'hubspot_id'];

    protected $table = 'contact_observer_test_models';

    public array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
    ];

    public function getHubspotMap(): array
    {
        return $this->hubspotMap;
    }

    public function getHubspotUpdateMap(): array
    {
        return $this->hubspotMap;
    }

    public function getHubspotCompanyRelation(): ?string
    {
        return null;
    }
}

test('it includes dynamic properties from overridden hubspot properties method', function () {
    // Create a test model that overrides hubspotProperties method
    $testModel = new class extends Model implements HubspotModelInterface
    {
        use HubspotModelTrait;

        public array $hubspotMap = [
            'email' => 'email',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
        ];

        public function getHubspotMap(): array
        {
            return $this->hubspotMap;
        }

        public function getHubspotUpdateMap(): array
        {
            return $this->hubspotUpdateMap ?? [];
        }

        public function getHubspotCompanyRelation(): ?string
        {
            return $this->hubspotCompanyRelation ?? null;
        }

        public function getHubspotProperties(array $map): array
        {
            $properties = [];

            foreach ($map as $key => $value) {
                if (strpos($value, '.')) {
                    $properties[$key] = data_get($this, $value);
                } else {
                    $properties[$key] = $this->$value;
                }
            }

            // Add dynamic course progress properties
            $properties['course_progress'] = '75%';
            $properties['courses_completed'] = '3';
            $properties['last_course_access'] = '2024-01-15';

            return $properties;
        }

        public function getHubspotId(): ?string
        {
            return $this->hubspot_id ?? null;
        }

        public function setHubspotId(?string $hubspotId): void
        {
            $this->hubspot_id = $hubspotId;
        }
    };

    $testModel->id = 1;
    $testModel->email = 'test@example.com';
    $testModel->first_name = 'John';
    $testModel->last_name = 'Doe';

    // Use reflection to test the protected method
    $reflection = new \ReflectionClass($this->observer);
    $method = $reflection->getMethod('prepareJobData');
    $method->setAccessible(true);

    $jobData = $method->invoke($this->observer, $testModel);

    // Verify that mapped properties are included
    expect($jobData)->toHaveKey('email');
    expect($jobData)->toHaveKey('first_name');
    expect($jobData)->toHaveKey('last_name');

    // Verify that dynamic properties are included in the dynamicProperties array
    expect($jobData)->toHaveKey('dynamicProperties');
    expect($jobData['dynamicProperties'])->toHaveKey('course_progress');
    expect($jobData['dynamicProperties'])->toHaveKey('courses_completed');
    expect($jobData['dynamicProperties'])->toHaveKey('last_course_access');

    expect($jobData['email'])->toBe('test@example.com');
    expect($jobData['first_name'])->toBe('John');
    expect($jobData['last_name'])->toBe('Doe');
    expect($jobData['dynamicProperties']['course_progress'])->toBe('75%');
    expect($jobData['dynamicProperties']['courses_completed'])->toBe('3');
    expect($jobData['dynamicProperties']['last_course_access'])->toBe('2024-01-15');
});
