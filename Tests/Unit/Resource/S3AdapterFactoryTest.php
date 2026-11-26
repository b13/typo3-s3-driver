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
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class S3AdapterFactoryTest extends UnitTestCase
{
    private const CREDENTIALS = ['key' => 'test-key', 'secret' => 'test-secret'];

    protected function tearDown(): void
    {
        putenv('AWS_REGION');
        putenv('AWS_DEFAULT_REGION');
        parent::tearDown();
    }

    public static function invalidConfigurationDataProvider(): \Generator
    {
        yield 'no bucket' => [[], 1764160801];
        yield 'empty bucket' => [['bucket' => ' '], 1764160801];
        yield 'upload options not a map' => [['bucket' => 'media', 'uploadOptions' => 'public'], 1764160802];
        yield 'client options not a map' => [['bucket' => 'media', 'clientOptions' => 'fast'], 1764160803];
        yield 'endpoint not a URL' => [['bucket' => 'media', 'endpoint' => 's3.example.com'], 1764160804];
        yield 'key without secret' => [['bucket' => 'media', 'key' => 'test-key'], 1764160805];
        yield 'secret without key' => [['bucket' => 'media', 'secret' => 'test-secret'], 1764160805];
        yield 'bucket not a string' => [['bucket' => ['media']], 1764160806];
        yield 'path style not a boolean' => [['bucket' => 'media', 'usePathStyleEndpoint' => 'sometimes'], 1764160807];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('invalidConfigurationDataProvider')]
    #[Test]
    public function createAdapterRejectsInvalidConfiguration(array $configuration, int $expectedCode): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionCode($expectedCode);

        (new S3AdapterFactory())->createAdapter($configuration);
    }

    #[Test]
    public function createAdapterReturnsTheAwsAdapter(): void
    {
        self::assertInstanceOf(AwsS3V3Adapter::class, (new S3AdapterFactory())->createAdapter(['bucket' => 'media'] + self::CREDENTIALS));
    }

    #[Test]
    public function createClientUsesTheConfiguredConnection(): void
    {
        $client = (new S3AdapterFactory())->createClient([
            'region' => 'eu-central-1',
            'endpoint' => 'https://s3.example.com',
            'usePathStyleEndpoint' => 'true',
            'sessionToken' => 'test-token',
        ] + self::CREDENTIALS);

        self::assertSame('eu-central-1', $client->getRegion());
        self::assertSame('https://s3.example.com', (string)$client->getEndpoint());
        self::assertTrue($client->getConfig('use_path_style_endpoint'));
        $credentials = $client->getCredentials()->wait();
        self::assertSame('test-key', $credentials->getAccessKeyId());
        self::assertSame('test-secret', $credentials->getSecretKey());
        self::assertSame('test-token', $credentials->getSecurityToken());
    }

    #[Test]
    public function createClientTakesTheRegionFromTheEnvironment(): void
    {
        putenv('AWS_REGION=eu-west-3');

        self::assertSame('eu-west-3', (new S3AdapterFactory())->createClient(self::CREDENTIALS)->getRegion());
    }

    #[Test]
    public function createClientFallsBackToUsEast1(): void
    {
        putenv('AWS_REGION');
        putenv('AWS_DEFAULT_REGION');

        self::assertSame('us-east-1', (new S3AdapterFactory())->createClient(self::CREDENTIALS)->getRegion());
    }

    #[Test]
    public function clientOptionsOverrideTheDefaults(): void
    {
        $client = (new S3AdapterFactory())->createClient(['region' => 'eu-central-1', 'clientOptions' => ['region' => 'auto']] + self::CREDENTIALS);

        self::assertSame('auto', $client->getRegion());
    }

    #[Test]
    public function uploadsSendNoAclByDefault(): void
    {
        $handler = new RecordingHandler();
        $filesystem = $this->createFilesystem(['uploadOptions' => ['CacheControl' => 'max-age=86400']], $handler);

        $filesystem->write('images/logo.svg', '<svg/>');

        $command = $handler->getLastCommand('PutObject');
        self::assertNotNull($command);
        self::assertFalse(isset($command['ACL']));
        self::assertSame('max-age=86400', $command['CacheControl']);
        self::assertSame('images/logo.svg', $command['Key']);
    }

    #[Test]
    public function uploadsSendTheConfiguredAcl(): void
    {
        $handler = new RecordingHandler();
        $filesystem = $this->createFilesystem(['acl' => 'public-read'], $handler);

        $filesystem->write('images/logo.svg', '<svg/>');

        self::assertSame('public-read', $handler->getLastCommand('PutObject')['ACL'] ?? null);
    }

    #[Test]
    public function thePrefixIsPutInFrontOfEveryKey(): void
    {
        $handler = new RecordingHandler();
        $filesystem = $this->createFilesystem(['prefix' => '/typo3/fileadmin/'], $handler);

        $filesystem->write('images/logo.svg', '<svg/>');

        self::assertSame('typo3/fileadmin/images/logo.svg', $handler->getLastCommand('PutObject')['Key'] ?? null);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function createFilesystem(array $configuration, RecordingHandler $handler): Filesystem
    {
        $configuration['bucket'] = 'media';
        $configuration['clientOptions'] = ['handler' => $handler];
        return new Filesystem((new S3AdapterFactory())->createAdapter($configuration + self::CREDENTIALS));
    }
}
