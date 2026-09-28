<?php

declare(strict_types=1);

namespace Kasera\Pay;

/**
 * A non-2xx answer (or no answer: status 0, code network_error). errorCode is
 * the stable machine-readable code, e.g. validation_failed; fields maps the
 * offending dotted JSON paths to what was wrong; quote requestId to support.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $status);
    }
}
