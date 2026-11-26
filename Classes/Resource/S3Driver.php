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

use League\Flysystem\Config;
use TYPO3\CMS\Core\Resource\Driver\FlysystemDriver;

/**
 * The Flysystem driver of TYPO3 for S3 storages.
 *
 * Copies do not retain the visibility of the source: the AWS adapter would
 * read the ACL of every copied object, which costs a request per file and
 * fails on services without ACL support such as Cloudflare R2. TYPO3 does
 * not use the visibility of files, the ACL of a copy follows the "acl"
 * option of the storage like any other upload.
 */
class S3Driver extends FlysystemDriver
{
    protected array $copyOptions = [Config::OPTION_RETAIN_VISIBILITY => false];
}
