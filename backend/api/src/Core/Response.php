<?php

declare(strict_types=1);

namespace Kaneas\Core;

final class Response
{
    public function __construct(public readonly mixed $data = null, public readonly int $status = 200)
    {
    }

    public static function created(mixed $data): self
    {
        return new self($data, 201);
    }

    public static function noContent(): self
    {
        return new self(null, 204);
    }

    public function send(): void
    {
        http_response_code($this->status);
        if ($this->status === 204) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
