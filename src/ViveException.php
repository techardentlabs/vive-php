<?php

declare(strict_types=1);

namespace Vive;

/** Every non-2xx response from the API. */
class ViveException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $fields = [],
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }

    /** Retrying this exact request may succeed. */
    public function isRetryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }

    public function code(): ?string
    {
        return $this->fields['reason'] ?? null;
    }
}
