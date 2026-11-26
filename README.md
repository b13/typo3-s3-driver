![CI](https://github.com/b13/typo3-s3-driver/actions/workflows/ci.yml/badge.svg)

# EXT:s3_driver - Amazon S3 and S3-compatible storages for TYPO3

## Introduction

This extension adds the file storage driver `S3` to TYPO3. A bucket of
Amazon S3, or of any service that speaks the S3 API, becomes a regular
TYPO3 file storage: editors upload, browse and use files from it in the
backend like in `fileadmin/`, and images are processed from it.

It works with Amazon S3 and with services that implement the S3 API, such
as MinIO, Cloudflare R2, Hetzner Object Storage, DigitalOcean Spaces and
Wasabi.

The extension is a thin layer: TYPO3 v15 ships a file storage driver based
on [Flysystem](https://flysystem.thephpleague.com/), and this extension
connects it to the official Flysystem S3 adapter
[`league/flysystem-aws-s3-v3`](https://github.com/thephpleague/flysystem-aws-s3-v3).
Listing, renaming and moving folders, local copies for image processing,
streaming downloads and the file index are all handled by TYPO3 itself.

## Installation

```bash
composer require b13/typo3-s3-driver
```

Requires TYPO3 v15 and PHP 8.5.

## Configuration

Storages are configured in `config/system/file-storages.yaml`. Add a
storage with the driver `S3`:

```yaml
storages:
  2:
    name: 'Media (S3)'
    driver: S3
    configuration:
      bucket: my-media-bucket
      region: eu-central-1
      prefix: fileadmin
      baseUri: 'https://my-media-bucket.s3.eu-central-1.amazonaws.com/fileadmin/'
```

Use `%env()%` placeholders for everything that differs per environment or
must not be committed:

```yaml
    configuration:
      bucket: '%env(S3_BUCKET)%'
      key: '%env(S3_KEY)%'
      secret: '%env(S3_SECRET)%'
```

### Options

| Option                 | Default                                  | Description                                                                                       |
|------------------------|------------------------------------------|---------------------------------------------------------------------------------------------------|
| `bucket`               | (required)                               | Name of the bucket                                                                                |
| `region`               | `AWS_REGION`, `AWS_DEFAULT_REGION`, `us-east-1` | Region of the bucket, `auto` for Cloudflare R2                                             |
| `prefix`               | (none)                                   | Folder in the bucket that is the root of the storage, so several storages can share a bucket     |
| `endpoint`             | (Amazon S3)                              | URL of an S3-compatible service, e.g. `https://fsn1.your-objectstorage.com`                       |
| `usePathStyleEndpoint` | `false`                                  | `true` for services that expect `https://host/bucket/key` instead of `https://bucket.host/key`, e.g. MinIO |
| `key`, `secret`        | (none)                                   | Access key. Without them, the default credential chain of the AWS SDK is used, see below         |
| `sessionToken`         | (none)                                   | Session token for temporary credentials                                                          |
| `acl`                  | (none)                                   | Canned ACL for uploads and copies, e.g. `public-read`. Without it, no ACL is sent                |
| `uploadOptions`        | (none)                                   | Map of S3 parameters for uploads, e.g. `CacheControl`, `ServerSideEncryption`, `StorageClass`    |
| `clientOptions`        | (none)                                   | Map of options for the `S3Client` of the AWS SDK, e.g. timeouts or retries, for special cases    |
| `baseUri`              | (none)                                   | Public URL of the storage root, see below. Handled by TYPO3                                       |
| `caseSensitive`        | `true`                                   | Handled by TYPO3                                                                                  |

Values from `%env()%` placeholders are strings: `usePathStyleEndpoint`
accepts `true`, `false`, `1`, `0`, `yes` and `no` as well.

### Credentials

Without `key` and `secret`, the AWS SDK looks for credentials itself, in
this order: the environment variables `AWS_ACCESS_KEY_ID` and
`AWS_SECRET_ACCESS_KEY`, web identity tokens (IAM roles for service
accounts on Kubernetes), the shared files `~/.aws/credentials` and
`~/.aws/config`, the container credentials of ECS and EKS Pod Identity,
and the instance profile of EC2.

On AWS, prefer roles over access keys: nothing secret ends up in the
configuration of TYPO3.

### Public URLs

Files of a storage with `baseUri` are linked directly, below that URL:
the bucket URL itself, a CloudFront distribution or any other CDN in front
of the bucket. The bucket (or the CDN) must allow public read access for
this.

Without `baseUri`, the storage is not public: TYPO3 delivers its files
through the file dump (`index.php?eID=dumpFile`), which checks access and
streams the file from the bucket. Use this for private buckets.

### ACLs

New Amazon S3 buckets have ACLs disabled, and several S3-compatible
services do not support them at all. That is why the extension sends no
ACL unless the `acl` option is set. Grant public access with a bucket
policy instead, or set `acl: public-read` for buckets that still use ACLs.

### Examples for S3-compatible services

```yaml
# Cloudflare R2
    configuration:
      bucket: my-media
      region: auto
      endpoint: 'https://<account-id>.r2.cloudflarestorage.com'
      key: '%env(R2_ACCESS_KEY_ID)%'
      secret: '%env(R2_SECRET_ACCESS_KEY)%'
      baseUri: 'https://media.example.com/'

# Hetzner Object Storage
    configuration:
      bucket: my-media
      region: fsn1
      endpoint: 'https://fsn1.your-objectstorage.com'
      key: '%env(S3_KEY)%'
      secret: '%env(S3_SECRET)%'

# MinIO
    configuration:
      bucket: my-media
      endpoint: 'http://minio:9000'
      usePathStyleEndpoint: true
      key: '%env(MINIO_ROOT_USER)%'
      secret: '%env(MINIO_ROOT_PASSWORD)%'
```

## Good to know

- **Processed images** are stored in the `_processed_` folder of the same
  storage by default. To keep them elsewhere, e.g. on a local storage,
  set `processingFolder` of the storage, e.g. `1:/_processed_s3/`.
- **Checksums.** TYPO3 stores the SHA-1 hash of every file in its index.
  S3 only offers its own checksum (the ETag), so TYPO3 downloads a file to
  hash it when the file is indexed or changed.
- **Folders** do not exist in S3, only keys with slashes. Folders created
  in TYPO3 are kept as empty marker objects (`folder/`), so empty folders
  stay visible.
- **Counting files** of a folder lists the folder, as S3 has no API to
  count objects.
- **Copies** keep no ACL of the source file, they get the `acl` option of
  the storage like uploads do.

## Development

Run the tests with Docker or Podman:

```bash
Build/Scripts/runTests.sh -s composerInstall
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s functional
```

The functional tests run a TYPO3 file storage against an S3 API server
([adobe/s3mock](https://github.com/adobe/S3Mock)) in a container.

## Open Issues

If you find an issue, feel free to create an [issue on GitHub](https://github.com/b13/typo3-s3-driver/issues) or a [pull request](https://github.com/b13/typo3-s3-driver/pulls).

### Credits

This extension was created by [Benni Mack](https://github.com/bmack) in 2026 for [b13 GmbH](https://b13.com).

[Find more TYPO3 extensions we have developed](https://b13.com/useful-typo3-extensions-from-b13-to-you) that help us deliver value in client projects. As part of the way we work, we focus on testing and best practices to ensure long-term performance, reliability, and results in all our code.
