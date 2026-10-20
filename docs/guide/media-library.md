# Media library

The image and video fields upload a new file, take a dropped one, or a pasted
path. A media library adds a fourth way in: **Browse library**, to pick a file
that is already on the site instead of uploading it again.

The core ships the button and a browser event, never the library itself. A
host with its own media system (Sylius, Sonata Media, a DAM) wires it in with
one PHP service and one dialog.

## Showing the button

The button appears on a field only when a service implements
`ContentBlocks\Storage\MediaLibraryInterface` and its `supports()` accepts the
field's kind: `image` for `ImageUploadType`, `video` for `VideoUploadType`.

```php
use ContentBlocks\Storage\MediaLibraryInterface;

final class DamMediaLibrary implements MediaLibraryInterface
{
    public function supports(string $kind): bool
    {
        return $kind === 'image'; // our DAM holds no videos
    }
}
```

```yaml
# config/services.yaml
ContentBlocks\Storage\MediaLibraryInterface:
    class: App\Media\DamMediaLibrary
```

Without a service, nothing changes on screen. The button is rendered on the
server, so it is there on every field the editor opens.

## The browse event

A click on the button dispatches `cb:media:browse` on the widget. It bubbles,
so a listener on `document` hears every field. Your dialog **claims** it with
`preventDefault()`; unclaimed, the widget says no library answered.

| `detail` | |
|---|---|
| `kind` | `image` or `video` |
| `accept` | the field's `accept` attribute, e.g. `image/*` |
| `value` | the path the field holds now, `''` if none |
| `pick(item)` | call it with `{ path, alt }` once the editor has chosen |

```js
document.addEventListener('cb:media:browse', (event) => {
    event.preventDefault();
    openMyDialog({ kind: event.detail.kind }).then((file) => {
        if (file) event.detail.pick({ path: file.url, alt: file.alt });
    });
});
```

`pick()` writes the path the way an upload does: the preview, the hidden input
and the autosave all follow. A same-origin absolute URL is stored as its path,
like a pasted one. The stored value is a path, so rendering, export and the
asset garbage collector see no difference.

Ship the listener as a [shell fragment](./host-services.md#adding-your-own-ui-to-the-builder-shell)
when it lives in a bundle: its `<dialog>` and `<script type="module">` then
appear in every builder without a Stimulus controller to declare.

## Alt text

`pick()` takes an optional `alt`. It fills the field's alt only when that field
is empty: the editor's own alt is never overwritten. From there it is an
ordinary field of the block, translated like any other.

`ImageUploadType`'s `cb_alt_field` option names the sibling field to fill. The
kit's `image` block and gallery items set it; set it on your own blocks:

```php
$builder
    ->add('src', ImageUploadType::class, ['cb_alt_field' => 'alt'])
    ->add('alt', TextType::class, ['required' => false]);
```

## The `klehm/content-blocks-media` package

A host without a media system of its own can install the package instead of
implementing the interface:

```bash
composer require klehm/content-blocks-media
```

### Installing the library

The package adds two tables, `cb_media` and `cb_media_folder`. Create them with
your usual tool:

```bash
php bin/console doctrine:migrations:diff     # or copy the sandbox migration
php bin/console doctrine:migrations:migrate
```

The sandbox's [Version20261020120000](https://github.com/klehm/content-blocks-project/blob/master/apps/content-blocks-sandbox/migrations/Version20261020120000.php)
is the MySQL version.

From then on, every file stored through `FileStorageInterface` gets its row:
uploads from the builder, and the files an import brings in. The package wraps
whichever storage you configured, so local storage, S3 or your own adapter all
work the same way.

```yaml
# config/packages/content_blocks_media.yaml
content_blocks_media:
    kinds: [image, video]   # the fields that offer Browse library
```

### Files uploaded before the library

Files stored before the package was installed have no row yet. Take them in
once:

```bash
php bin/console content-blocks-media:import --dry-run   # what it would add
php bin/console content-blocks-media:import
```

By default it takes only the files some content uses. A file in the library is
never swept by the [asset garbage collector](./asset-lifecycle.md), so importing
every orphan would keep them for good; `--all` takes them anyway. The command
needs a storage that can list its files (`AssetInventoryInterface`, which
`LocalFileStorage` implements).

### Removing a file from the library

Removing a file deletes its row, never the file. A page that still uses it keeps
working, and the garbage collector sweeps the file once nothing references it,
the same rule as every other editor action.
