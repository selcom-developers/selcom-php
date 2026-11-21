<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Result;

/**
 * Result of storedCards.
 */
final class StoredCardsResult extends Result
{
    /** @return list<StoredCard> */
    public function cards(): array
    {
        return array_map(static fn (array $entry): StoredCard => StoredCard::fromEntry($entry), $this->data);
    }
}
