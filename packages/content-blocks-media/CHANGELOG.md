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
- **Managing the library from the picker**: create, rename and delete folders
  (a deleted folder hands its files to the root), edit a file's title and alt
  text, move it, see the pages that use it, remove it from the library (the
  file itself stays). Every change needs `MediaManagerInterface`, denied by
  default (`AllowAllMediaManager` lets every editor manage).
- **The picker behaves like a file browser**: drop files from the desktop on
  it to upload them into the folder shown, select several with Ctrl/Cmd- or
  Shift-click (Ctrl/Cmd-A for all), drag them onto a folder or a crumb of the
  trail to move them, remove several at once, and let go of the selection with
  a click on the empty zone, on the selected file, or Escape.
- **Pages load as the grid scrolls**, a page of 40 at a time, with *Show more*
  as the fallback where `IntersectionObserver` is missing.
- **Errors in the editor's language**: every error of the package's endpoints
  carries a translated `message` beside its `code` in `error`; the picker
  shows the message, never the code.
- **The picker in the i18n workbench**: it stands alone, so the workbench
  renders it and offers it on media rows (`klehm/content-blocks-i18n` with
  this release).
- **The library's alt text fills the block's** when a file is inserted into an
  empty alt field.
- `content_blocks_media.kinds` (`[image, video]` by default) picks the fields
  that offer *Browse library*.

Requires `klehm/content-blocks` ^1.6 (`MediaLibraryInterface` is new in the
core). Needs a migration: see the guide.
