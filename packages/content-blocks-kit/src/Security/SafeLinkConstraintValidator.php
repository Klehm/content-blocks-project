<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Security;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class SafeLinkConstraintValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SafeLinkConstraint) {
            throw new UnexpectedTypeException($constraint, SafeLinkConstraint::class);
        }
        if ($value === null || $value === '' || !\is_string($value)) {
            return;
        }
        if (!SafeLink::isSafe($value)) {
            $this->context->buildViolation($constraint->message)
                ->setTranslationDomain('content_blocks_kit')
                ->addViolation();
        }
    }
}
