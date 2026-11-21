<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

/**
 * One stored card. The card token is sensitive: it is hidden from dumps and must never be logged.
 */
final class StoredCard
{
    public function __construct(
        /** The card's resource ID, used to delete it. */
        public readonly ?string $id,
        public readonly ?string $maskedCard,
        public readonly ?string $creationDate,
        /** The cardholder. */
        public readonly ?string $name,
        /** `001` Visa, `002` Mastercard. */
        public readonly ?string $cardType,
        /** Used to charge the card. */
        public readonly ?string $cardToken,
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function fromEntry(array $entry): self
    {
        $str = static fn (string $key): ?string => isset($entry[$key]) && is_scalar($entry[$key]) ? (string) $entry[$key] : null;

        return new self(
            $str('id'),
            $str('masked_card'),
            $str('creation_date'),
            $str('name'),
            $str('card_type'),
            $str('card_token'),
        );
    }

    /** @return array<string, string|null> */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'maskedCard' => $this->maskedCard,
            'creationDate' => $this->creationDate,
            'name' => $this->name === null ? null : '[REDACTED]',
            'cardType' => $this->cardType,
            'cardToken' => $this->cardToken === null ? null : '[REDACTED]',
        ];
    }
}
