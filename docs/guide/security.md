---
title: Security
---

# Security

## CSRF

AJAX endpoints (`/_content-blocks/*` by default) require an `X-CSRF-Token` header bound to the token id `content_blocks`. Stimulus controllers read it from a `data-cb-csrf-token` attribute rendered by the bundle. Your app needs:

- `framework.session: true` (CSRF tokens are session-bound)
- `framework.csrf_protection.enabled: true`

::: tip Symfony 7.x
Symfony 7.x defaults CSRF to stateless — the token id `content_blocks` falls through to the session-based fallback automatically, so no extra config is needed beyond enabling CSRF and the session.
:::

## Firewalls & access control

The bundle exposes two URL families with different exposure:

| Path prefix | Audience | Mode |
|---|---|---|
| `/_content-blocks/public/*` | Anyone (loaded inside the public iframe) | Public |
| `/_content-blocks/*` (everything else) | Authenticated admin (block CRUD, section CRUD, sidebars, upload) | Admin-only |

The public sub-prefix is intentional: it lets you lock the admin endpoints down without breaking the iframe's CSS and overlay JS.

::: tip Simplest: mount the admin endpoints under your admin path
Both families can be mounted wherever you like. With the builder endpoints under `/admin/content-blocks`, a `^/admin` firewall or `access_control` rule already covers them, and none of the patterns below is needed. See [Mounting the routes](./routing.md).
:::

The rest of this section assumes the default mount.

**With a single firewall**, an `access_control` split is enough:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/_content-blocks/public, roles: PUBLIC_ACCESS }
        - { path: ^/_content-blocks,        roles: ROLE_ADMIN }
```

**With separate admin and front-office firewalls**, extend the admin firewall's pattern to cover the admin endpoints (and exclude the public sub-prefix), otherwise the builder's AJAX calls run unauthenticated:

```yaml
security:
    firewalls:
        admin:
            pattern: ^/(admin|_content-blocks(?!/public))
            # ...
        main:
            # public site — handles the iframe URL, no admin auth here
            pattern: ^/
```

### When the session expires

A form-login firewall answers an unauthenticated request with a redirect to the
login page. For the builder's own calls, the bundle turns that redirect into a
`401` carrying `{"error": "session_expired"}` and the
`X-Content-Blocks-Session: expired` header, so the builder can tell a lost
session from a save. The editor sees a *Session expired* banner with a link that
opens the page again in a new tab. Their unsaved edit stays on screen and is
sent again once they are logged back in. Full-page navigations are left alone
and still reach your login form.

Nothing to configure. The builder does not keep the session alive: your session
lifetime still applies.

### Cross-firewall auth detection

The render template auto-detects preview mode by calling `AccessCheckerInterface::canEdit()` while serving the public URL — i.e. the request passes through the **public/main** firewall, but the user authenticated against the **admin** firewall. With separate firewall contexts (`context: admin`), Symfony's standard `Security::isGranted()` will not see the admin token from the main firewall and the iframe falls back to public mode (no editing UI, even when an admin opens the builder).

If your firewalls use isolated contexts, the access checker has to read the admin token directly from the session:

```php
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Entity\ContentArea;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class PageAccessChecker implements AccessCheckerInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly RequestStack $requests,
    ) {}

    public function canEdit(ContentArea $contentArea): bool
    {
        return $this->isAdmin() && $this->ownsArea($contentArea);
    }

    public function canView(ContentArea $contentArea): bool { return true; }

    private function isAdmin(): bool
    {
        // 1) Standard path: a token is in the current firewall's storage.
        $token = $this->tokens->getToken();
        if ($token && \in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return true;
        }

        // 2) Cross-firewall fallback: the iframe runs under the public
        // firewall, so the admin token isn't visible via $tokens. Read
        // the serialized admin token from the session directly. The key
        // is `_security_<context_or_firewall_name>` — `_security_admin`
        // when `context: admin` or the firewall name is `admin`.
        $request = $this->requests->getMainRequest();
        if (!$request || !$request->hasSession()) {
            return false;
        }

        $serialized = $request->getSession()->get('_security_admin');
        if (!\is_string($serialized)) {
            return false;
        }

        $adminToken = unserialize($serialized);
        return $adminToken instanceof TokenInterface
            && \in_array('ROLE_ADMIN', $adminToken->getRoleNames(), true);
    }

    private function ownsArea(ContentArea $area): bool
    {
        // your app's ownership check
    }
}
```

See [host services](./host-services.md#accesscheckerinterface-authorization) for the base `AccessCheckerInterface` contract, and [Rendering](./rendering.md#preview-vs-public-mode) for how preview mode depends on it.

## Block data sanitization

::: info The block's form _is_ the whitelist + validator
A block's `data` is never written raw. `BlockComponent::persistDraft()` submits the form built by the block's `buildForm()`, and only on success writes `$form->getData()` to the draft.
:::

Two guarantees fall out of this:

- **Key whitelist** — the compound form only maps its declared children, so an unexpected key in the POST is dropped; it never reaches `data`.
- **Value validation** — each field's `constraints` (e.g. `Assert\Choice`, `Assert\Length`) run on submit; a failure re-renders the form with errors and writes nothing. Nested collections validate via their `entry_type`'s own constraints.

There is **no** `getAllowedDataKeys()` / `sanitizeData()` / `processData()` hook — a custom block secures its data purely by what it declares in `buildForm()` (fields + constraints). The kit's `AbstractKitBlock::choiceConstraint()` derives an `Assert\Choice` from the field's full coded choice set for exactly this reason.

### The clipboard goes through the same door

Copy/paste stores its entry in the browser's `localStorage`, which is what lets a copy survive leaving the page — and what makes the payload **user-writable**. So a paste is not a restore: every block in it is replayed through its own form (`ContentBlocks\Clipboard\BlockDataReplayer`) before anything is written. A key your block type does not declare never reaches `Block.data`; a value your `constraints` refuse is reset to the type's default and reported to the editor, rather than costing the whole block.

This is stricter than the two older restore paths (section-template insert, area import), and deliberately so: they keep block data verbatim — unknown keys warn instead of dropping, and collection-entry ids survive so translations still match. An import file is still input, though, so what those paths keep is held elsewhere: the structure is checked on arrival (below), and the kit's views guard HTML, links and colours at render ([Editor HTML and links](#editor-html-and-links)). Nothing extra to do in a custom block — the form you already wrote is the filter for everything typed in the builder.

### Restored structure

An import, a section template and a pasted section all rebuild sections, columns and blocks from a payload. The structure is checked on the way in: a section layout is kept only if this install's `SectionLayoutRegistry` knows it (otherwise the section is `full`), a column preset only if it is `col-1` … `col-12` (otherwise `col-12`), and a block without a string `type` is dropped. Column settings go through `ColumnSettings::sanitize()`.

::: danger Raw-HTML caveat
The kit's `html_raw` block renders `{{ html|raw }}`, so it trusts its editors. It is **disabled by default** (`content_blocks_kit.blocks.html_raw.enabled: false`) and must be explicitly opted in.
:::

### Editor HTML and links

Some stored values reach a page without passing through the block's form — an
import, a section template, a translation, machine-translation output — so the
kit's views guard them again at render:

- **`rich_text` is sanitized.** Its HTML goes through `cb_kit_rich_html`, backed
  by `symfony/html-sanitizer`: the W3C safe elements, `class`, and a `style`
  attribute reduced to formatting properties (colour, alignment, sizes, margins
  — no positioning, no `url()`). Script, event handlers, `<iframe>` and unsafe
  link schemes are removed. To sanitize differently, redefine the service
  `content_blocks_kit.rich_text_sanitizer` with any `HtmlSanitizerInterface`.
- **Links keep safe schemes only.** Every kit link (button, button group, image,
  gallery, card, breadcrumb) goes through `cb_kit_safe_url`: http(s), `mailto:`,
  `tel:` or a scheme-less URL. Anything else — `javascript:`, `data:` — renders
  as no link (`#` for a button). The form refuses the same values on save
  (`SafeLinkConstraint`). A custom block with a link field can use both.

`html_raw` stays the one block that renders editor HTML untouched.

## File upload

The upload endpoint (`content_blocks_upload`) checks the CSRF token, the size (`content_blocks.upload.max_size`) and the MIME type sniffed from the file (`content_blocks.upload.allowed_mime_types`) before handing it to your `FileStorageInterface`.

An **import** writes files too, and goes through the same checks: each file's MIME type is sniffed from its bytes and checked against the same list, and its stored extension is derived from that type. The `mimeType` and `extension` written in the export are ignored, so a forged export cannot place a `.php` or `.html` file under your public upload prefix. Each file is also hashed on arrival and kept only if it is one the export lists, and the content is resolved only against files the server itself checked, so a forged manifest cannot point a block at a file of its choosing. A refused file stops the import before the content is written.

`image/svg+xml` is **not** in the default list. An SVG can carry script, which runs when the file is opened directly from your domain — stored XSS on your own origin. Add it to `allowed_mime_types` only if your editors are fully trusted, and then serve the upload directory with `Content-Security-Policy: sandbox` (or `script-src 'none'`).

### Stored paths are confined

A stored file path is editor input: the image widget accepts a pasted path, and
any text field can hold a string that starts with your public prefix. The
exporter reads every such path into the zip, so a path must never resolve
outside the upload directory. `LocalFileStorage` refuses `.`/`..`/empty
segments, backslashes and NUL bytes, then requires the resolved real path to be
a file under the real upload directory — a symlink pointing out is not
followed.

If you aliased `FileStorageInterface` to your own implementation, hold
`read()`, `remove()` and `isStoredPath()` to the same rule. Flysystem already
refuses path traversal by default (`PathTraversalDetected`).
