---
title: Security
---

# Security

## CSRF

AJAX endpoints (`/_content-blocks/*`) require an `X-CSRF-Token` header bound to the token id `content_blocks`. Stimulus controllers read it from a `data-cb-csrf-token` attribute rendered by the bundle. Your app needs:

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

This is stricter than the two older restore paths (section-template insert, area import), and deliberately so: those replay rows *this* application wrote, so they keep unknown keys and merely warn. Nothing extra to do in a custom block — the form you already wrote is the filter.

::: danger Raw-HTML caveat
The kit's `html_raw` block renders `{{ html|raw }}`, so it trusts its editors. It is **disabled by default** (`content_blocks_kit.blocks.html_raw.enabled: false`) and must be explicitly opted in.
:::
