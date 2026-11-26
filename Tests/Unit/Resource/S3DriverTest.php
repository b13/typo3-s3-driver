<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "s3_driver" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\S3Driver\Tests\Unit\Resource;

use B13\S3Driver\Resource\S3AdapterFactory;
use B13\S3Driver\Resource\S3Driver;
use B13\S3Driver\Resource\S3DriverFactory;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class S3DriverTest extends UnitTestCase
{
    #[Test]
    public function theFactoryCreatesTheS3Driver(): void
    {
        $driver = (new S3DriverFactory(new S3AdapterFactory()))->createDriver(['bucket' => 'media']);

        self::assertInstanceOf(S3Driver::class, $driver);
    }

    #[Test]
    public function copyingAFileDoesNotReadItsAcl(): void
    {
        $handler = new RecordingHandler([
            'HeadObject' => ['ContentLength' => 6, 'ContentType' => 'image/svg+xml'],
        ]);
        $driver = new S3Driver([
            'bucket' => 'media',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'clientOptions' => ['handler' => $handler],
        ], new S3AdapterFactory());
        $driver->processConfiguration();

        $newIdentifier = $driver->copyFileWithinStorage('/images/logo.svg', '/images/', 'logo-copy.svg');

        self::assertSame('/images/logo-copy.svg', $newIdentifier);
        self::assertNotContains('GetObjectAcl', $handler->getCommandNames());
        $copy = $handler->getLastCommand('CopyObject');
        self::assertNotNull($copy);
        self::assertSame('images/logo-copy.svg', $copy['Key']);
        self::assertFalse(isset($copy['ACL']));
    }
}
