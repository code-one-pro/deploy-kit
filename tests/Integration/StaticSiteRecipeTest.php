<?php

declare(strict_types=1);

namespace DeployKit\Tests\Integration;

final class StaticSiteRecipeTest extends DeployTestCase
{
    private const RECIPE = __DIR__ . '/../fixtures/deploy-static.php';

    protected function fixtureName(): string
    {
        return 'dist';
    }

    /** Replace the build with one asset file and the given index content, then deploy. */
    private function deployBuild(string $asset, string $index = 'index'): array
    {
        $src = $this->sourcePath();
        foreach (glob($src . '/assets/*') ?: [] as $old) {
            unlink($old);
        }
        file_put_contents($src . '/assets/' . $asset, $asset);
        file_put_contents($src . '/index.html', $index);

        $result = $this->deploy(self::RECIPE);
        $this->assertSame(0, $result['exit'], $result['output']);

        return $result;
    }

    /** @return list<string> */
    private function liveAssets(): array
    {
        $files = array_map('basename', glob($this->basePath() . '/current/assets/*') ?: []);
        sort($files);

        return $files;
    }

    public function test_deploys_dist_behind_current(): void
    {
        $result = $this->deploy(self::RECIPE);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertFileExists($this->basePath() . '/current/index.html');
    }

    public function test_previous_assets_survive_one_deploy(): void
    {
        $this->deployBuild('app.aaa.js');
        $this->deployBuild('app.bbb.js');

        $this->assertSame(['app.aaa.js', 'app.bbb.js'], $this->liveAssets());
    }

    public function test_assets_do_not_accumulate(): void
    {
        $this->deployBuild('app.aaa.js');
        $this->deployBuild('app.bbb.js');
        $this->deployBuild('app.ccc.js');

        $this->assertSame(['app.bbb.js', 'app.ccc.js'], $this->liveAssets());
    }

    public function test_index_is_always_the_new_one(): void
    {
        $this->deployBuild('app.aaa.js', 'first');
        $this->deployBuild('app.bbb.js', 'second');

        $this->assertSame('second', file_get_contents($this->basePath() . '/current/index.html'));
    }

    public function test_release_without_manifest_owns_all_its_assets(): void
    {
        $this->deployBuild('app.aaa.js');
        unlink($this->currentTarget() . '/.dk-own-assets');
        $this->deployBuild('app.bbb.js');

        $this->assertSame(['app.aaa.js', 'app.bbb.js'], $this->liveAssets());
    }

    public function test_failed_health_rolls_back_static(): void
    {
        $this->deployBuild('app.aaa.js');
        $first = $this->currentTarget();

        $src = $this->sourcePath();
        unlink($src . '/index.html');
        $result = $this->deploy(self::RECIPE);

        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('ROLLED BACK', $result['output']);
        $this->assertSame($first, $this->currentTarget());
    }
}
