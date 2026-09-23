<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Security;

use Symfony\Component\Validator\Constraint;

/**
 * Refuses a link whose scheme {@see SafeLink} does not allow.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class SafeLinkConstraint extends Constraint
{
    public string $message = 'cb_kit.link.unsafe_scheme';

    public function validatedBy(): string
    {
        return SafeLinkConstraintValidator::class;
    }
}
