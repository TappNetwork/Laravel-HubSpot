<?php

namespace Tapp\LaravelHubspot\Tests\Unit\Commands;

use Tapp\LaravelHubspot\Commands\DebugHubspotData;
use Tapp\LaravelHubspot\Tests\TestCase;

class DebugHubspotDataTest extends TestCase
{
    /** @test */
    public function it_extends_console_command()
    {
        $command = new DebugHubspotData;

        $this->assertInstanceOf(\Illuminate\Console\Command::class, $command);
    }

    /** @test */
    public function it_has_correct_description()
    {
        $command = new DebugHubspotData;

        $this->assertNotEmpty($command->getDescription());
    }
}
