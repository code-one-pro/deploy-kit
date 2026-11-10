<?php

declare(strict_types=1);

namespace DeployKit\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function DeployKit\pendingMigrationNames;

final class PendingMigrationNamesTest extends TestCase
{
    public function test_parses_laravel_table_output(): void
    {
        $output = <<<'OUT'

  Migration name ........................................................ Batch / Status

  2026_01_01_000000_create_x_table .................................... Pending
  2026_02_03_101500_drop_y_from_users_table ........................... Pending

OUT;

        $this->assertSame(
            ['2026_01_01_000000_create_x_table', '2026_02_03_101500_drop_y_from_users_table'],
            pendingMigrationNames($output),
        );
    }

    public function test_parses_bare_names(): void
    {
        $this->assertSame(
            ['2026_01_01_000000_drop_x', '2026_01_02_000000_add_y'],
            pendingMigrationNames("2026_01_01_000000_drop_x\n2026_01_02_000000_add_y\n"),
        );
    }

    public function test_no_pending_message_yields_nothing(): void
    {
        $this->assertSame([], pendingMigrationNames("\n  INFO  No pending migrations.\n"));
    }
}
