<?php

declare(strict_types=1);

namespace DeployKit\Tests\Integration;

final class LaravelRecipeTest extends DeployTestCase
{
    private const RECIPE = __DIR__ . '/../fixtures/deploy-laravel.php';

    private function release(): string
    {
        $current = realpath($this->basePath() . '/current');
        $this->assertNotFalse($current);
        $this->assertSame(realpath($this->basePath() . '/releases'), dirname($current));

        return $current;
    }

    public function test_first_deploy_creates_layout(): void
    {
        $result = $this->deploy(self::RECIPE);
        $this->assertSame(0, $result['exit'], $result['output']);

        $base = $this->basePath();
        $this->assertDirectoryExists($base . '/releases');
        $this->assertDirectoryExists($base . '/shared/storage');
        $this->assertTrue(is_link($base . '/current'));
        $release = $this->release();
        $this->assertFileExists($release . '/artisan');
        foreach (['storage', '.env'] as $entry) {
            $this->assertTrue(is_link($release . '/' . $entry), $entry . ' is not a symlink');
            $this->assertSame(realpath($base . '/shared/' . $entry), realpath($release . '/' . $entry));
        }
    }

    public function test_never_uploads_secrets_or_state(): void
    {
        $src = $this->sourcePath();
        file_put_contents($src . '/.env', "APP_KEY=base64:topsecret\n");
        file_put_contents($src . '/.env.e2e', "E2E=1\n");
        foreach (['storage' => 'secret.txt', 'node_modules' => 'x', '.git' => 'HEAD', 'vendor' => 'x'] as $dir => $file) {
            mkdir($src . '/' . $dir, 0755, true);
            file_put_contents($src . '/' . $dir . '/' . $file, 'x');
        }

        $result = $this->deploy(self::RECIPE, ['DK_UPLOAD_PATHS' => 'artisan,public,storage,.env']);
        $this->assertSame(0, $result['exit'], $result['output']);

        $release = $this->release();
        foreach (['node_modules', '.git', 'vendor', '.env.e2e'] as $entry) {
            $this->assertFalse(file_exists($release . '/' . $entry) || is_link($release . '/' . $entry), $entry);
        }
        foreach (['storage', '.env'] as $entry) {
            $this->assertTrue(is_link($release . '/' . $entry), $entry . ' must stay the shared symlink');
        }
        $this->assertFileDoesNotExist($this->basePath() . '/shared/storage/secret.txt');
        $this->assertStringNotContainsString('topsecret', (string) @file_get_contents($this->basePath() . '/shared/.env'));
    }

    public function test_allowlist_limits_upload(): void
    {
        mkdir($this->sourcePath() . '/docs');
        file_put_contents($this->sourcePath() . '/docs/a.md', 'x');

        $result = $this->deploy(self::RECIPE, ['DK_UPLOAD_PATHS' => 'artisan,public']);
        $this->assertSame(0, $result['exit'], $result['output']);

        $release = $this->release();
        $this->assertFileExists($release . '/artisan');
        $this->assertFileExists($release . '/public/up.txt');
        $this->assertDirectoryDoesNotExist($release . '/docs');
        $this->assertFileDoesNotExist($release . '/composer.json');
    }

    public function test_extra_excludes_apply(): void
    {
        $result = $this->deploy(self::RECIPE, ['DK_UPLOAD_EXCLUDES' => 'composer.json']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertFileDoesNotExist($this->release() . '/composer.json');
        $this->assertFileExists($this->release() . '/artisan');
    }
}
