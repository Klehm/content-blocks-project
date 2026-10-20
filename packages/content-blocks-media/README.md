# klehm/content-blocks-media

A media library for [ContentBlocks](https://github.com/klehm/content-blocks-project):
pick a file already uploaded instead of uploading it again, sort files into
folders, give them an alt text, and see which pages use them.

```bash
composer require klehm/content-blocks-media
```

The stored value stays a path: rendering, export/import and the asset garbage
collector work exactly as before. A host that already has a media system
(Sylius, Sonata Media, a DAM) implements the core's `MediaLibraryInterface`
instead of installing this package.

Documentation: [Media library](https://klehm.github.io/content-blocks-project/guide/media-library).

## License

MIT
