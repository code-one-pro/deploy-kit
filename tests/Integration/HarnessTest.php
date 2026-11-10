<?php

declare(strict_types=1);

namespace DeployKit\Tests\Integration;

final class HarnessTest extends DeployTestCase
{
    public function test_fake_artisan_logs_and_fails_on_request(): void
    {
        $env = ['DK_FAIL_ON' => 'migrate'];

        $this->assertSame(0, $this->runFakeArtisan(['config:cache'], $env));
        $this->assertSame(1, $this->runFakeArtisan(['migrate'], $env));
        $this->assertSame(['config:cache', 'migrate'], $this->artisanLog());
    }
}
