<?php

declare(strict_types=1);

namespace ContentBlocks\Seo;

/**
 * The names of the audit's checks, for config validation. The checks
 * themselves run in the browser, in `assets/audit.js`.
 */
final class SeoChecks
{
    public const ALL = [
        'heading_h1_missing',
        'heading_h1_multiple',
        'heading_level_skipped',
        'heading_empty',
        'image_alt_missing',
        'image_alt_empty',
        'link_text_empty',
        'link_text_generic',
        'paragraph_too_long',
    ];

    /** English, French, German and Spanish; a host's list replaces it. */
    public const GENERIC_LINK_TEXTS = [
        'click here', 'here', 'read more', 'more', 'learn more', 'link', 'this link',
        'this page', 'continue', 'details', 'more info', 'see more',
        'cliquez ici', 'cliquer ici', 'ici', 'en savoir plus', 'lire la suite', 'la suite',
        'plus', 'voir plus', 'ce lien', 'lien', "plus d'infos", 'suite',
        'hier klicken', 'hier', 'mehr', 'weiterlesen', 'mehr erfahren',
        'haga clic aquí', 'clic aquí', 'aquí', 'leer más', 'más', 'ver más',
    ];
}
