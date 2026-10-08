<?php

declare(strict_types=1);

namespace Larastan\Larastan\Properties;

use PHPStan\Analyser\ResultCacheMetaExtension;
use SplFileInfo;

use function array_map;
use function array_merge;
use function hash;
use function hash_file;
use function implode;
use function sort;
use function sprintf;

/**
 * Invalidates PHPStan's result cache when migrations or schema dumps change.
 *
 * Model property types are read from these files, but no analysed code references
 * them, so PHPStan cannot see the dependency on its own. File contents are hashed
 * rather than modification times so that fresh CI checkouts can reuse the cache.
 */
final class MigrationResultCacheMetaExtension implements ResultCacheMetaExtension
{
    public function __construct(
        private MigrationHelper $migrationHelper,
        private SquashedMigrationHelper $squashedMigrationHelper,
    ) {
    }

    public function getKey(): string
    {
        return 'larastan-migrations';
    }

    public function getHash(): string
    {
        $metadata = array_merge(
            array_map(static fn (SplFileInfo $file): string => sprintf('M:%s:%s', $file->getPathname(), self::hashFile($file)), $this->migrationHelper->getMigrationFiles()),
            array_map(static fn (SplFileInfo $file): string => sprintf('S:%s:%s', $file->getPathname(), self::hashFile($file)), $this->squashedMigrationHelper->getSchemaFiles()),
        );

        sort($metadata);

        return hash('xxh128', implode('|', $metadata));
    }

    private static function hashFile(SplFileInfo $file): string
    {
        return hash_file('xxh128', $file->getPathname()) ?: '';
    }
}
