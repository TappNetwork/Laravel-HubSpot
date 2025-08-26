<?php

namespace Tapp\LaravelHubspot\Tests\Unit\Commands;

use Illuminate\Console\Application;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tapp\LaravelHubspot\Commands\SyncHubspotContacts;
use Tapp\LaravelHubspot\Tests\TestCase;

class SyncHubspotContactsTest extends TestCase
{
    /** @test */
    public function it_has_correct_signature()
    {
        $command = new SyncHubspotContacts();
        $reflection = new \ReflectionClass($command);
        $signatureProperty = $reflection->getProperty('signature');
        $signatureProperty->setAccessible(true);

        $this->assertEquals(
            'hubspot:sync-contacts {model=\App\Models\User} {--delay=0 : Delay between API calls in seconds} {--limit= : Limit the total number of contacts to process}',
            $signatureProperty->getValue($command)
        );
    }

    /** @test */
    public function it_has_correct_description()
    {
        $command = new SyncHubspotContacts();
        $reflection = new \ReflectionClass($command);
        $descriptionProperty = $reflection->getProperty('description');
        $descriptionProperty->setAccessible(true);

        $this->assertEquals('Create missing hubspot contacts.', $descriptionProperty->getValue($command));
    }

    /** @test */
    public function it_has_correct_default_options()
    {
        $command = new SyncHubspotContacts();
        $reflection = new \ReflectionClass($command);
        $signatureProperty = $reflection->getProperty('signature');
        $signatureProperty->setAccessible(true);
        $signature = $signatureProperty->getValue($command);

        // Test that the command has the expected options
        $this->assertStringContainsString('--delay=0', $signature);
        $this->assertStringContainsString('--limit=', $signature);
    }

    /** @test */
    public function it_extends_console_command()
    {
        $command = new SyncHubspotContacts();

        $this->assertInstanceOf(\Illuminate\Console\Command::class, $command);
    }
}
