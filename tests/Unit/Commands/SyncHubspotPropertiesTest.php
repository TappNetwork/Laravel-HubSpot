<?php

namespace Tapp\LaravelHubspot\Tests\Unit\Commands;

use Tapp\LaravelHubspot\Commands\SyncHubspotProperties;
use Tapp\LaravelHubspot\Tests\TestCase;

class SyncHubspotPropertiesTest extends TestCase
{
    /** @test */
    public function it_extends_console_command()
    {
        $command = new SyncHubspotProperties();

        $this->assertInstanceOf(\Illuminate\Console\Command::class, $command);
    }

    /** @test */
    public function it_has_correct_description()
    {
        $command = new SyncHubspotProperties();

        $this->assertNotEmpty($command->getDescription());
    }
}

