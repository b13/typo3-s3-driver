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

use TYPO3\CMS\Core\Attribute\AsFileStorageDriver;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\Driver\FileStorageDriverFactoryInterface;

/**
 * Registers the driver "S3", to be used as "driver" of a storage in
 * config/system/file-storages.yaml.
 */
#[AsFileStorageDriver(identifier: self::IDENTIFIER)]
final readonly class S3DriverFactory implements FileStorageDriverFactoryInterface
{
    public const IDENTIFIER = 'S3';

    public function __construct(
        private S3AdapterFactory $adapterFactory,
    ) {
    }

    public function createDriver(array $configuration): DriverInterface
    {
        return new S3Driver($configuration, $this->adapterFactory);
    }
}
