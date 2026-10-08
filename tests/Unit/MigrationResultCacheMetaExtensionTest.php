<?php

declare(strict_types=1);

namespace Tests\Unit;

use Larastan\Larastan\Properties\MigrationHelper;
use Larastan\Larastan\Properties\MigrationResultCacheMetaExtension;
use Larastan\Larastan\Properties\Schema\MySqlDataTypeToPhpTypeConverter;
use Larastan\Larastan\Properties\SquashedMigrationHelper;
use PHPStan\File\FileHelper;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function array_map;
use function clearstatcache;
use function file_put_contents;
use function glob;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function time;
use function touch;
use function uniqid;

#[CoversClass(MigrationResultCacheMetaExtension::class)]
class MigrationResultCacheMetaExtensionTest extends PHPStanTestCase
{
    private string $baseDir;

    private string $migrationsDir;

    private string $schemaDir;

    protected function setUp(): void
    {
        $this->baseDir       = sys_get_temp_dir() . '/larastan_meta_' . uniqid();
        $this->migrationsDir = $this->baseDir . '/migrations';
        $this->schemaDir     = $this->baseDir . '/schema';

        mkdir($this->migrationsDir, 0777, true);
        mkdir($this->schemaDir, 0777, true);

        file_put_contents($this->migrationsDir . '/2020_01_01_000000_create_users_table.php', '<?php // users');
        file_put_contents($this->schemaDir . '/mysql-schema.sql', 'CREATE TABLE `users` (`id` int);');
    }

    protected function tearDown(): void
    {
        foreach ([$this->migrationsDir, $this->schemaDir] as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }

        rmdir($this->baseDir);
    }

    #[Test]
    public function it_returns_the_same_hash_for_unchanged_files(): void
    {
        $this->assertSame($this->createExtension()->getHash(), $this->createExtension()->getHash());
    }

    #[Test]
    public function it_ignores_modification_time(): void
    {
        $hash = $this->createExtension()->getHash();

        touch($this->migrationsDir . '/2020_01_01_000000_create_users_table.php', time() + 10);
        touch($this->schemaDir . '/mysql-schema.sql', time() + 10);
        clearstatcache();

        $this->assertSame($hash, $this->createExtension()->getHash());
    }

    #[Test]
    public function it_changes_when_a_migration_changes(): void
    {
        $hash = $this->createExtension()->getHash();

        file_put_contents($this->migrationsDir . '/2020_01_01_000000_create_users_table.php', '<?php // users with email');

        $this->assertNotSame($hash, $this->createExtension()->getHash());
    }

    #[Test]
    public function it_changes_when_a_migration_is_added(): void
    {
        $hash = $this->createExtension()->getHash();

        file_put_contents($this->migrationsDir . '/2020_01_02_000000_create_posts_table.php', '<?php // posts');

        $this->assertNotSame($hash, $this->createExtension()->getHash());
    }

    #[Test]
    public function it_changes_when_a_schema_dump_changes(): void
    {
        $hash = $this->createExtension()->getHash();

        file_put_contents($this->schemaDir . '/mysql-schema.sql', 'CREATE TABLE `users` (`id` bigint);');

        $this->assertNotSame($hash, $this->createExtension()->getHash());
    }

    private function createExtension(): MigrationResultCacheMetaExtension
    {
        $fileHelper = self::getContainer()->getByType(FileHelper::class);

        return new MigrationResultCacheMetaExtension(
            new MigrationHelper(
                self::getContainer()->getService('currentPhpVersionSimpleDirectParser'),
                [$this->migrationsDir],
                $fileHelper,
                false,
                $this->createReflectionProvider(),
                self::getContainer()->getByType(InitializerExprTypeResolver::class),
            ),
            new SquashedMigrationHelper(
                [$this->schemaDir],
                $fileHelper,
                new MySqlDataTypeToPhpTypeConverter(),
                self::getContainer()->getService('sqlParser'),
                false,
            ),
        );
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
