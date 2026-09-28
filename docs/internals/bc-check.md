# BC check — roave against the promised surface

`scripts/bc-check/run.sh` runs
[roave/backward-compatibility-check](https://github.com/Roave/BackwardCompatibilityCheck)
on each package, comparing the working tree with the last `v*` tag. CI runs it
as the `bc-check` job, and the split `needs` it: a break in a 1.x release
reaches neither the mirrors nor Packagist.

```bash
scripts/bc-check/run.sh                          # the three packages, vs the last tag
scripts/bc-check/run.sh content-blocks-kit       # one package
scripts/bc-check/run.sh --from=v1.0.0-RC17       # another baseline
```

It installs roave in `var/bc-check-tools/` on first run (set `ROAVE_BIN` to
use another copy). Each run downloads both sides' dependencies from Packagist,
so it takes a minute or two.

## Why a copy of each package, not the monorepo

Roave clones the repository it runs in and reads the `autoload` of the root
`composer.json`. Here the root autoloads nothing: the classes live in
`packages/*`. So for each package the script builds a throwaway repository with
two commits, `base` (that package's `composer.json` and `src/` at the tag) and
`head` (the same from the working tree, uncommitted changes included), and runs
roave in it.

The kit and i18n path-repo the core through `../content-blocks`, which does not
exist in the copy. `prepare.php` points them at a copy of the core **from the
same side**, so each side's parent classes and interfaces are the ones it was
written against.

## Why the promise is read from the BC page

Roave's own perimeter is "every class not marked `@internal`". Ours is
narrower: [the backward compatibility page](../guide/backward-compatibility.md)
says it is the whole promise, and that a class it does not list is internal
whether or not it carries the marker. Run as-is, roave would fail a pull request
for changing a controller's constructor, which a minor release is allowed to do.

So `prepare.php` marks `@internal`, in the copy only, every class-like whose
short name is not in backticks under the page's `## What is covered` section.
The kit's block classes are the one exception, kept by namespace: the page
promises "the 19 kit block classes" as a set without naming them. The page stays
the only list; adding a class to the promise is adding it to the page.

Two things follow:

- **The match is by short name**, so the check errs on the side of covering too
  much: a promised name reused by an internal class elsewhere is checked too.
- **Member-level `@internal` still counts**: the published-state setters on the
  entities, or the constructors of `ImportResult` and its siblings, are marked
  in the code and roave skips them.

What the check cannot see is the rest of the promise: config keys and defaults,
routes, Twig names, `cb:*` events, CSS tokens, tables. Those stay a review
matter (CSS tokens have their own check, `docs/scripts/check-tokens.mjs`).

## When a break is intended

Inside 1.x, it is not: keep the old path, mark it `@deprecated`, add the new
one. A false positive, or the start of a 2.x cycle, goes in
`scripts/bc-check/baseline/<package>.xml`, which the script copies in as roave's
configuration:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<roave-bc-check
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/roave/backward-compatibility-check/Resources/schema.xsd">
    <baseline>
        <ignored-regex>#\[BC\] CHANGED: .*RenderContext#__construct\(\)#</ignored-regex>
    </baseline>
</roave-bc-check>
```

Once a new major is tagged the baseline is empty again: the check compares
against the last tag, so it moves with the release.
