<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Core\ValueObject\PhpVersion;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    // 1) Define which directories Rector should process:
    $rectorConfig->paths([
        __DIR__ . '/cron',
        __DIR__ . '/deploy',
    ]);

    // 2) Target modern PHP language features up through PHP 8.4.
    $rectorConfig->phpVersion(PhpVersion::PHP_84);
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_84,
    ]);

    // 3) If you had specific rules previously (like AddVoidReturnTypeWhereNoReturnRector),
    //    remove them or skip them because those rules add modern type hints:
    $rectorConfig->skip([
        // e.g. Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector::class,
    ]);

    // 4) (Optional) Add additional sets for code quality/cleanup if they don't
    //    introduce modern syntax. For example:
    // $rectorConfig->sets([
    //     SetList::DEAD_CODE,  // But be careful that it doesn't introduce PHP 7+ array destructuring, etc.
    // ]);
};
