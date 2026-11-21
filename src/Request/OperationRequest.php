<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

use SelcomDevelopers\Selcom\Credentials;
use SelcomDevelopers\Selcom\Exception\ValidationException;
use SelcomDevelopers\Selcom\Operation;

/**
 * A typed request for one operation. It knows which operation it is and builds its parameters,
 * in send order, with the credentials that apply (vendor, and the float PIN for payouts).
 */
interface OperationRequest
{
    public function operation(): Operation;

    /**
     * The parameters to sign and send, in send order. Nested groups (billing, shipping) may be
     * nested arrays; signing flattens them to dotted keys.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException when the PIN is needed and not configured
     */
    public function params(Credentials $credentials): array;
}
