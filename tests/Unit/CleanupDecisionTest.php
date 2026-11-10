<?php

declare(strict_types=1);

namespace DeployKit\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function DeployKit\releasePathFor;
use function DeployKit\releasesToRemove;

final class CleanupDecisionTest extends TestCase
{
    public function test_keeps_current_and_newest_live_releases_up_to_the_keep_count(): void
    {
        // newest first
        $names = ['9', '8', '7', '6', '5', '4', '3', '2', '1'];
        $live = ['9', '8', '7', '6', '5', '4', '3', '2', '1'];

        $this->assertSame(['4', '3', '2', '1'], releasesToRemove($names, $live, '9', 5));
    }

    public function test_failed_releases_never_push_the_previous_live_release_out(): void
    {
        // 1 good, 2-5 failed at migrate (never live), 6 good and current
        $names = ['6', '5', '4', '3', '2', '1'];

        $this->assertSame(['5', '4', '3', '2'], releasesToRemove($names, ['6', '1'], '6', 5));
    }

    public function test_current_is_kept_even_without_a_marker(): void
    {
        $names = ['4', '3', '2', '1'];

        $this->assertSame(['4', '1'], releasesToRemove($names, ['3', '2', '1'], '3', 2));
    }

    public function test_current_is_never_removed_even_with_keep_of_one(): void
    {
        $this->assertSame(['2', '1'], releasesToRemove(['3', '2', '1'], ['3', '2', '1'], '3', 1));
        $this->assertNotContains('3', releasesToRemove(['3', '2', '1'], ['2', '1'], '3', 1));
    }

    public function test_without_any_marker_only_older_surplus_goes(): void
    {
        $names = ['8', '7', '6', '5', '4', '3', '2', '1'];

        // current is 6: the two newer unknown releases stay, as do the newest five overall.
        $this->assertSame(['3', '2', '1'], releasesToRemove($names, [], '6', 5));
    }

    public function test_without_any_marker_or_current_nothing_goes(): void
    {
        $this->assertSame([], releasesToRemove(['3', '2', '1'], [], null, 1));
    }

    public function test_only_releases_present_are_never_removed(): void
    {
        $this->assertSame([], releasesToRemove(['1'], [], '1', 5));
        $this->assertSame([], releasesToRemove(['1'], ['1'], '1', 5));
        $this->assertSame([], releasesToRemove([], [], null, 5));
    }

    public function test_keep_of_zero_or_less_removes_nothing(): void
    {
        $this->assertSame([], releasesToRemove(['3', '2', '1'], ['3', '2', '1'], '3', 0));
    }

    public function test_path_guard_builds_a_path_under_releases(): void
    {
        $this->assertSame('/var/www/app/releases/12', releasePathFor('/var/www/app', '12'));
        $this->assertSame('/var/www/app/releases/12', releasePathFor('/var/www/app/', '12'));
    }

    /** @return array<string, array{string, string}> */
    public static function unsafe(): array
    {
        return [
            'empty deploy path' => ['', '12'],
            'root deploy path' => ['/', '12'],
            'relative deploy path' => ['var/www/app', '12'],
            'empty name' => ['/var/www/app', ''],
            'dot' => ['/var/www/app', '.'],
            'dot dot' => ['/var/www/app', '..'],
            'traversal' => ['/var/www/app', '../shared'],
            'slash' => ['/var/www/app', '1/2'],
            'space' => ['/var/www/app', '1 2'],
            'glob' => ['/var/www/app', '*'],
            'deploy path with dot dot' => ['/var/www/../app', '1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafe')]
    public function test_path_guard_refuses_unsafe_input(string $deployPath, string $name): void
    {
        $this->expectException(\RuntimeException::class);
        releasePathFor($deployPath, $name);
    }
}
