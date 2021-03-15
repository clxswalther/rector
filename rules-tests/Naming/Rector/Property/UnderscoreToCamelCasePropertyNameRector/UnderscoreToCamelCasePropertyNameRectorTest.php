<?php

declare(strict_types=1);

namespace Rector\Tests\Naming\Rector\Property\UnderscoreToCamelCasePropertyNameRector;

use Iterator;
use Rector\Naming\Rector\Property\UnderscoreToCamelCasePropertyNameRector;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;
use Symplify\SmartFileSystem\SmartFileInfo;

final class UnderscoreToCamelCasePropertyNameRectorTest extends AbstractRectorTestCase
{
    /**
     * @dataProvider provideData()
     */
    public function test(SmartFileInfo $fileInfo): void
    {
        $this->doTestFileInfo($fileInfo);
    }

    /**
     * @return Iterator<SmartFileInfo>
     */
    public function provideData(): Iterator
    {
        return $this->yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/configured_rule.php';
    }
}
