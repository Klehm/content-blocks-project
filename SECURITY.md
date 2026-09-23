# Security policy

## Supported versions

Security fixes land on the latest `1.x` release. Before `1.0.0`, only the
latest release candidate receives them.

| Version | Supported |
|---|---|
| `1.x` (latest minor) | yes |
| `1.0.0-RC*` | latest candidate only, until `1.0.0` |
| `0.x` betas | no |

The policy covers the three packages published from this repository:
`klehm/content-blocks`, `klehm/content-blocks-kit` and
`klehm/content-blocks-i18n`. The sandbox applications under `apps/` are
development fixtures, not something to deploy.

## Reporting a vulnerability

Please **do not open a public issue**. Report it privately through GitHub's
[private vulnerability reporting](https://github.com/klehm/content-blocks-project/security/advisories/new)
on this repository.

Include what you can of:

- the package and version affected
- the steps to reproduce, or a proof of concept
- the impact as you see it: who can trigger it (anonymous visitor, editor,
  admin), and what it reaches

You will get an acknowledgement within a few days. Once a fix is ready, it is
released and credited in the CHANGELOG, unless you prefer to stay anonymous.

## What is in scope

ContentBlocks trusts its **editors** less than its **developers**. Anything an
editor can do through the builder, an import, a pasted section or a template,
that reaches another user or the server, is in scope: stored XSS in the public
render, reading or writing files outside the upload directory, bypassing
`AccessCheckerInterface`, and similar.

Out of scope, by design:

- the kit's `html_raw` block, which renders HTML unescaped and is disabled by
  default for that reason
- a host that wires `AllowAllAccessChecker` or an equivalent outside
  development
- SVG uploads a host has re-allowed without the precautions in
  [Security](docs/guide/security.md)

[Security](docs/guide/security.md) describes the defences the packages rely on.
