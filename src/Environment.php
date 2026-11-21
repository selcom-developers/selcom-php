<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom;

use SelcomDevelopers\Selcom\Exception\ConfigurationException;

/**
 * The gateway environment. There is deliberately no default.
 */
enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://apigwtest.selcommobile.com',
            self::Production => 'https://apigw.selcommobile.com',
        };
    }

    public static function fromName(?string $name): self
    {
        if ($name === null || trim($name) === '') {
            throw new ConfigurationException(
                'The environment is required: choose "sandbox" or "production". There is no default.'
            );
        }

        return self::tryFrom(strtolower(trim($name))) ?? throw new ConfigurationException(
            'Unknown environment "' . $name . '": choose "sandbox" or "production".'
        );
    }
}
