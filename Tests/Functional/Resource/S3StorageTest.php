<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "s3_driver" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\S3Driver\Tests\Functional\Resource;

use B13\S3Driver\Resource\S3AdapterFactory;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Configuration\FileStorageConfigurationWriter;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Runs a TYPO3 file storage with the S3 driver against an S3 API server,
 * started by Build/Scripts/runTests.sh -s functional.
 */
final class S3StorageTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['b13/typo3-s3-driver'];

    private ResourceStorage $storage;

    protected function setUp(): void
    {
        $endpoint = getenv('S3_TEST_ENDPOINT');
        if (!is_string($endpoint) || $endpoint === '') {
            self::markTestSkipped('Needs an S3 API server in S3_TEST_ENDPOINT, run the tests with Build/Scripts/runTests.sh -s functional.');
        }
        parent::setUp();

        $configuration = [
            'bucket' => 'typo3-' . bin2hex(random_bytes(6)),
            'prefix' => 'fileadmin',
            'region' => 'us-east-1',
            'endpoint' => $endpoint,
            'usePathStyleEndpoint' => true,
            'key' => 'test-key',
            'secret' => 'test-secret',
            'baseUri' => 'https://media.example.com/fileadmin/',
        ];
        (new S3AdapterFactory())->createClient($configuration)->createBucket(['Bucket' => $configuration['bucket']]);

        $this->get(FileStorageConfigurationWriter::class)->write(2, [
            'name' => 'S3 test storage',
            'driver' => 'S3',
            'configuration' => $configuration,
        ]);
        $storage = $this->get(StorageRepository::class)->findByUid(2);
        self::assertInstanceOf(ResourceStorage::class, $storage);
        $this->storage = $storage;
    }

    #[Test]
    public function storageUsesTheS3Driver(): void
    {
        self::assertSame('S3', $this->storage->getDriverType());
        self::assertTrue($this->storage->isOnline());
        self::assertTrue($this->storage->isWritable());
        self::assertTrue($this->storage->isPublic());
    }

    #[Test]
    public function addedFileIsIndexedAndCanBeReadBack(): void
    {
        $folder = $this->storage->getRootLevelFolder()->createFolder('images');

        $file = $this->storage->addFile($this->createLocalFile('Hello S3'), $folder, 'hello.txt');

        self::assertInstanceOf(File::class, $file);
        self::assertSame('/images/hello.txt', $file->getIdentifier());
        self::assertGreaterThan(0, $file->getUid());
        self::assertSame('Hello S3', $file->getContents());
        self::assertSame(8, $file->getSize());
        self::assertSame('text/plain', $file->getMimeType());
        self::assertSame(sha1('Hello S3'), $file->getSha1());
        self::assertSame('https://media.example.com/fileadmin/images/hello.txt', $file->getPublicUrl());
        self::assertSame('Hello S3', file_get_contents($file->getForLocalProcessing(false)));
    }

    #[Test]
    public function foldersAndFilesAreListed(): void
    {
        $root = $this->storage->getRootLevelFolder();
        $images = $root->createFolder('images');
        $root->createFolder('documents');
        $this->storage->addFile($this->createLocalFile('File A'), $images, 'a.txt');
        $this->storage->addFile($this->createLocalFile('File B'), $images, 'b.txt');

        self::assertSame(['/documents/', '/images/'], array_keys($root->getSubfolders()));
        self::assertSame(['a.txt', 'b.txt'], array_keys($images->getFiles()));
        self::assertSame(2, $images->getFileCount());
    }

    #[Test]
    public function filesAreCopiedRenamedMovedAndDeleted(): void
    {
        $root = $this->storage->getRootLevelFolder();
        $images = $root->createFolder('images');
        $archive = $root->createFolder('archive');
        $file = $this->storage->addFile($this->createLocalFile('original'), $images, 'photo.txt');

        $copy = $file->copyTo($images, 'photo-copy.txt');
        self::assertSame('/images/photo-copy.txt', $copy->getIdentifier());
        self::assertSame('original', $copy->getContents());

        $copy->rename('renamed.txt');
        self::assertSame('/images/renamed.txt', $copy->getIdentifier());

        $copy->moveTo($archive);
        self::assertSame('/archive/renamed.txt', $copy->getIdentifier());
        self::assertTrue($this->storage->hasFile('/archive/renamed.txt'));
        self::assertFalse($this->storage->hasFile('/images/renamed.txt'));

        $copy->delete();
        self::assertFalse($this->storage->hasFile('/archive/renamed.txt'));
        self::assertTrue($this->storage->hasFile('/images/photo.txt'));
    }

    #[Test]
    public function fileContentsCanBeReplaced(): void
    {
        $file = $this->storage->addFile($this->createLocalFile('first version'), $this->storage->getRootLevelFolder(), 'notes.txt');

        $file->setContents('second version');

        self::assertSame('second version', $this->storage->getFileContents($file));
        self::assertSame(14, $file->getSize());
    }

    #[Test]
    public function addingAnExistingFileNameRenamesTheNewFile(): void
    {
        $root = $this->storage->getRootLevelFolder();
        $this->storage->addFile($this->createLocalFile('Report one'), $root, 'report.txt');

        $second = $this->storage->addFile($this->createLocalFile('Report two'), $root, 'report.txt', DuplicationBehavior::RENAME);

        self::assertSame('/report_01.txt', $second->getIdentifier());
    }

    #[Test]
    public function foldersAreRenamedAndDeletedWithTheirContent(): void
    {
        $folder = $this->storage->getRootLevelFolder()->createFolder('campaign');
        $this->storage->addFile($this->createLocalFile('banner'), $folder->createFolder('banners'), 'banner.txt');

        $renamed = $folder->rename('campaign-2026');

        self::assertSame('/campaign-2026/', $renamed->getIdentifier());
        self::assertTrue($this->storage->hasFile('/campaign-2026/banners/banner.txt'));
        self::assertFalse($this->storage->hasFolder('/campaign/'));

        $renamed->delete(true);

        self::assertFalse($this->storage->hasFolder('/campaign-2026/'));
        self::assertFalse($this->storage->hasFile('/campaign-2026/banners/banner.txt'));
    }

    private function createLocalFile(string $contents): string
    {
        $path = Environment::getVarPath() . '/transient/' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($path, $contents);
        return $path;
    }
}
