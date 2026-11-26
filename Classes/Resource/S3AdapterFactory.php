<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "s3_driver" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\S3Driver\Resource;

use Aws\CommandInterface;
use Aws\Credentials\Credentials;
use Aws\Middleware;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\FilesystemAdapter;
use TYPO3\CMS\Core\Resource\Driver\FilesystemAdapterFactoryInterface;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;

/**
 * Creates the Flysystem adapter for Amazon S3 and S3-compatible object
 * storages from the "configuration" of a file storage.
 */
final readonly class S3AdapterFactory implements FilesystemAdapterFactoryInterface
{
    /**
     * Commands that carry an ACL. Without the "acl" option, the ACL is removed
     * from them, as buckets with ACLs disabled and several S3-compatible
     * services reject requests that set one.
     */
    private const COMMANDS_WITH_ACL = ['PutObject', 'CopyObject', 'CreateMultipartUpload'];

    private const DEFAULT_REGION = 'us-east-1';

    public function createAdapter(array $configuration): FilesystemAdapter
    {
        $bucket = $this->getString($configuration, 'bucket');
        if ($bucket === '') {
            throw new InvalidConfigurationException('The S3 storage needs a "bucket" in its configuration.', 1764160801);
        }
        $uploadOptions = $configuration['uploadOptions'] ?? [];
        if (!is_array($uploadOptions)) {
            throw new InvalidConfigurationException('The option "uploadOptions" of the S3 storage must be a map.', 1764160802);
        }
        $acl = $this->getString($configuration, 'acl');
        if ($acl !== '') {
            $uploadOptions['ACL'] = $acl;
        } else {
            unset($uploadOptions['ACL']);
        }

        return new AwsS3V3Adapter(
            client: $this->createClient($configuration),
            bucket: $bucket,
            prefix: trim($this->getString($configuration, 'prefix'), '/'),
            options: $uploadOptions,
        );
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function createClient(array $configuration): S3Client
    {
        $clientOptions = $configuration['clientOptions'] ?? [];
        if (!is_array($clientOptions)) {
            throw new InvalidConfigurationException('The option "clientOptions" of the S3 storage must be a map.', 1764160803);
        }

        $arguments = [
            'version' => 'latest',
            'region' => $this->getString($configuration, 'region') ?: $this->getRegionFromEnvironment(),
            'use_path_style_endpoint' => $this->getBool($configuration, 'usePathStyleEndpoint'),
        ];

        $endpoint = $this->getString($configuration, 'endpoint');
        if ($endpoint !== '') {
            if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
                throw new InvalidConfigurationException('The "endpoint" of the S3 storage must be a URL, e.g. "https://s3.example.com".', 1764160804);
            }
            $arguments['endpoint'] = $endpoint;
        }

        $key = $this->getString($configuration, 'key');
        $secret = $this->getString($configuration, 'secret');
        if (($key === '') !== ($secret === '')) {
            throw new InvalidConfigurationException('The S3 storage needs both "key" and "secret", or neither of them to use the default credential chain of the AWS SDK.', 1764160805);
        }
        if ($key !== '') {
            $arguments['credentials'] = new Credentials($key, $secret, $this->getString($configuration, 'sessionToken') ?: null);
        }

        $client = new S3Client(array_replace($arguments, $clientOptions));

        if ($this->getString($configuration, 'acl') === '') {
            $client->getHandlerList()->appendInit(
                Middleware::mapCommand(static function (CommandInterface $command): CommandInterface {
                    if (in_array($command->getName(), self::COMMANDS_WITH_ACL, true)) {
                        unset($command['ACL']);
                    }
                    return $command;
                }),
                's3_driver.remove_acl'
            );
        }

        return $client;
    }

    private function getRegionFromEnvironment(): string
    {
        foreach (['AWS_REGION', 'AWS_DEFAULT_REGION'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return self::DEFAULT_REGION;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function getString(array $configuration, string $option): string
    {
        $value = $configuration[$option] ?? '';
        if (!is_scalar($value)) {
            throw new InvalidConfigurationException(sprintf('The option "%s" of the S3 storage must be a string.', $option), 1764160806);
        }
        return trim((string)$value);
    }

    /**
     * Accepts booleans and the strings an "%env()%" placeholder resolves to
     *
     * @param array<string, mixed> $configuration
     */
    private function getBool(array $configuration, string $option): bool
    {
        $value = $configuration[$option] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if (!is_scalar($value) || $bool === null) {
            throw new InvalidConfigurationException(sprintf('The option "%s" of the S3 storage must be true or false.', $option), 1764160807);
        }
        return $bool;
    }
}
