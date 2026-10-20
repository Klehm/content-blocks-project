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
- **The picker**, a builder shell fragment answering *Browse library*: the
  files of the field's kind, newest first, with search, folders, upload into
  the current folder and *Insert* (or a double click). It needs no Stimulus
  controller and no asset build: its script and stylesheet are served by the
  package. Routes under `/_content-blocks/media` by default,
  `config/routes/bare.php` to mount them elsewhere.
- `content_blocks_media.kinds` (`[image, video]` by default) picks the fields
  that offer *Browse library*.

Requires `klehm/content-blocks` ^1.6 (`MediaLibraryInterface` is new in the
core). Needs a migration: see the guide.
