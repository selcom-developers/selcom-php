<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

/**
 * Keeps secret values outside the objects that use them, so that var_dump, var_export,
 * print_r and (array) casts of those objects show no secret. Entries disappear with their owner.
 *
 * @internal
 */
final class SecretVault
{
    /** @var \WeakMap<object, array<string, string|null>>|null */
    private static ?\WeakMap $store = null;

    /**
     * @param array<string, string|null> $values
     */
    public static function put(object $owner, #[\SensitiveParameter] array $values): void
    {
        self::$store ??= new \WeakMap();
        self::$store[$owner] = $values;
    }

    public static function get(object $owner, string $key): ?string
    {
        return self::$store[$owner][$key] ?? null;
    }
}
