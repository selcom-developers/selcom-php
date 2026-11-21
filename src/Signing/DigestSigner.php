<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Signing;

/**
 * The digest step, pluggable so that RS256 can be added without touching request building.
 */
interface DigestSigner
{
    /** The value sent as `Digest-Method`: "HS256" or "RS256". */
    public function method(): string;

    /** The base64 digest of the signing string. Never empty. */
    public function sign(#[\SensitiveParameter] string $signingString): string;
}
