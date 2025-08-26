<?php

namespace Tapp\LaravelHubspot\Tests\Unit\Observers;

use Illuminate\Database\Eloquent\Model;
use Mockery;
use Tapp\LaravelHubspot\Observers\HubspotContactObserver;
use Tapp\LaravelHubspot\Tests\TestCase;

class HubspotContactObserverTest extends TestCase
{
    protected HubspotContactObserver $observer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->observer = new HubspotContactObserver();
    }

    /** @test */
    public function it_includes_dynamic_properties_from_overridden_hubspot_properties_method()
    {
        // Create a test model that overrides hubspotProperties method
        $testModel = new class extends Model {
            public array $hubspotMap = [
                'email' => 'email',
                'firstname' => 'first_name',
                'lastname' => 'last_name',
            ];

            public function hubspotProperties(array $map): array
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
        $this->assertArrayHasKey('email', $jobData);
        $this->assertArrayHasKey('first_name', $jobData);
        $this->assertArrayHasKey('last_name', $jobData);

        // Verify that dynamic properties are included in the dynamicProperties array
        $this->assertArrayHasKey('dynamicProperties', $jobData);
        $this->assertArrayHasKey('course_progress', $jobData['dynamicProperties']);
        $this->assertArrayHasKey('courses_completed', $jobData['dynamicProperties']);
        $this->assertArrayHasKey('last_course_access', $jobData['dynamicProperties']);

        $this->assertEquals('test@example.com', $jobData['email']);
        $this->assertEquals('John', $jobData['first_name']);
        $this->assertEquals('Doe', $jobData['last_name']);
        $this->assertEquals('75%', $jobData['dynamicProperties']['course_progress']);
        $this->assertEquals('3', $jobData['dynamicProperties']['courses_completed']);
        $this->assertEquals('2024-01-15', $jobData['dynamicProperties']['last_course_access']);
    }



    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
