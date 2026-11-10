<?php

declare(strict_types=1);

namespace DeployKit\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function DeployKit\phpVersionMeets;

final class PhpVersionTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function versions(): array
    {
        return [
            'the minimum itself' => ['8.4.27', true],
            'newer patch' => ['8.4.28', true],
            'newer minor' => ['8.5.0', true],
            'distribution suffix' => ["8.4.27-1+ubuntu24.04.1\n", true],
            'older patch' => ['8.4.26', false],
            'patch compared as a number' => ['8.4.9', false],
            'older minor' => ['8.3.30', false],
            'empty' => ['', false],
            'not a version' => ['command not found', false],
        ];
    }

    #[DataProvider('versions')]
    public function test_compares_the_reported_version_with_the_minimum(string $reported, bool $expected): void
    {
        $this->assertSame($expected, phpVersionMeets($reported, '8.4.27'));
    }
}
