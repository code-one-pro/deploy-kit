<?php

declare(strict_types=1);

namespace DeployKit\Tests\Unit;

use DeployKit\MigrationScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationScannerTest extends TestCase
{
    private function migration(string $up, string $down = ''): string
    {
        return "<?php\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n{$up}\n    }\n\n    public function down(): void\n    {\n{$down}\n    }\n};\n";
    }

    public function test_additive_up_is_clean(): void
    {
        $source = $this->migration(<<<'PHP'
        Schema::create('t', function (Blueprint $table) {
            $table->id();
            $table->string('x')->nullable();
            $table->index(['x']);
        });
PHP);

        $this->assertSame([], (new MigrationScanner())->scan($source));
    }

    /** @return array<string, array{string, string}> */
    public static function breakingStatements(): array
    {
        return [
            'dropColumn' => ["\$table->dropColumn('a');", 'dropColumn'],
            'dropColumns' => ["\$table->dropColumns(['a']);", 'dropColumn'],
            'renameColumn' => ["\$table->renameColumn('a','b');", 'renameColumn'],
            'rename' => ["Schema::rename('a','b');", 'rename'],
            'drop' => ["Schema::drop('a');", 'drop'],
            'dropIfExists' => ["Schema::dropIfExists('a');", 'drop'],
            'change' => ["\$table->string('a')->change();", 'change'],
            'dropForeign' => ["\$table->dropForeign(['a']);", 'dropForeign'],
            'raw drop column' => ["DB::statement('ALTER TABLE t DROP COLUMN a');", 'raw-sql'],
            'raw rename column' => ["DB::statement('alter table t rename column a to b');", 'raw-sql'],
            'raw alter column' => ["DB::statement('ALTER TABLE t ALTER COLUMN a SET NOT NULL');", 'raw-sql'],
        ];
    }

    #[DataProvider('breakingStatements')]
    public function test_flags_each_breaking_statement(string $statement, string $label): void
    {
        $source = $this->migration("        \$x = 1;\n        {$statement}");

        $findings = (new MigrationScanner())->scan($source);

        $this->assertSame([['line' => 8, 'text' => $statement, 'pattern' => $label]], $findings);
    }

    public function test_down_is_ignored(): void
    {
        $source = $this->migration(
            "        \$table->string('x')->nullable();",
            "        Schema::dropIfExists('t');\n        \$table->dropColumn('x');",
        );

        $this->assertSame([], (new MigrationScanner())->scan($source));
    }

    public function test_reports_every_finding_in_order(): void
    {
        $source = $this->migration("        \$table->dropColumn('a');\n        \$table->index('b');\n        Schema::rename('a', 'b');");

        $findings = (new MigrationScanner())->scan($source);

        $this->assertSame([7, 9], array_column($findings, 'line'));
        $this->assertSame(['dropColumn', 'rename'], array_column($findings, 'pattern'));
    }

    public function test_source_without_up_method_is_clean(): void
    {
        $source = "<?php\n\nclass Foo\n{\n    public function down(): void\n    {\n        Schema::drop('a');\n    }\n}\n";

        $this->assertSame([], (new MigrationScanner())->scan($source));
    }

    public function test_commented_lines_and_braces_in_strings_are_handled(): void
    {
        $source = $this->migration(<<<'PHP'
        // $table->dropColumn('a');
        # Schema::drop('a');
        /* $table->dropColumn('b');
           Schema::rename('a', 'b'); */
        $s = '}';
        $table->dropForeign(['a']);
PHP);

        $findings = (new MigrationScanner())->scan($source);

        $this->assertSame([['line' => 12, 'text' => "\$table->dropForeign(['a']);", 'pattern' => 'dropForeign']], $findings);
    }
}
