<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Capability gate for the optional ClasseViva integration.
 *
 * A page must opt in before bootstrap validates the token or renders the
 * global re-authentication dialog. Ordinary UDA, Classroom and GitHub pages
 * therefore remain usable when ClasseViva has never been connected.
 */
final class ClasseVivaCapability
{
    public const CONSTANT = 'REQUIRES_CLASSEVIVA';

    public static function requested(): bool
    {
        return defined(self::CONSTANT) && constant(self::CONSTANT) === true;
    }
}
