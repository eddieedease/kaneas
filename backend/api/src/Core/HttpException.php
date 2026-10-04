<?php

declare(strict_types=1);

namespace Kaneas\Core;

/**
 * An error that is safe to show to the client. `errorCode` is a stable,
 * machine-readable key that the frontend translates (errors.<code>).
 */
final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct($errorCode, $status);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found');
    }

    public static function forbidden(): self
    {
        return new self(403, 'forbidden');
    }

    public static function validation(array $fields): self
    {
        return new self(422, 'validation_failed', $fields);
    }
}
