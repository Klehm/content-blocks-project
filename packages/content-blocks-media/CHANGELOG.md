# Changelog

All notable changes to `klehm/content-blocks-media` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **The media library.** A `cb_media` row per stored file (path, kind, title,
  alt, MIME type, size, dimensions) and `cb_media_folder` to sort them. The
  host's `FileStorageInterface` is decorated so every upload and every imported
  file gets its row; a failed row is logged and never fails the upload.
- **The library counts as a reference for the asset GC**: a file it holds is
  swept only once it has been removed from the library.
- **`content-blocks-media:import`** takes in the files stored before the
  package: by default the ones some content uses, `--all` for every file,
  `--dry-run` to see what it would add.
- `content_blocks_media.kinds` (`[image, video]` by default) picks the fields
  that offer *Browse library*.

Requires `klehm/content-blocks` ^1.6 (`MediaLibraryInterface` is new in the
core). Needs a migration: see the guide.
