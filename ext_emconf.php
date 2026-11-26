<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'S3 Driver',
    'description' => 'Amazon S3 and S3-compatible object storages (MinIO, Cloudflare R2, Hetzner, DigitalOcean Spaces, Wasabi) as TYPO3 file storages, based on the Flysystem drivers of TYPO3 v15.',
    'category' => 'misc',
    'author' => 'b13 GmbH',
    'author_email' => 'typo3@b13.com',
    'author_company' => 'b13 GmbH',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '15.0.0-15.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
