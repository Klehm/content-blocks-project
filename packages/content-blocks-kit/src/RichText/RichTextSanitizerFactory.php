<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

use ContentBlocks\Kit\Security\SafeLink;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * The default sanitizer of rich-text HTML: what the shipped editors write,
 * minus script, event handlers and unsafe link schemes.
 *
 * @see docs/guide/security.md#editor-html-and-links
 */
final class RichTextSanitizerFactory
{
    public static function create(): HtmlSanitizerInterface
    {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowAttribute('class', '*')
            ->allowAttribute('style', '*')
            ->withAttributeSanitizer(new RichTextStyleSanitizer())
            ->allowLinkSchemes(SafeLink::SCHEMES)
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            // The default 20 000-byte cap truncates a long article silently.
            ->withMaxInputLength(-1);

        return new HtmlSanitizer($config);
    }
}
