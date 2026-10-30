<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use RuntimeException;
use Throwable;

/**
 * Throw this from a route, middleware or controller to produce a proper HTTP
 * error response (404, 403, 419, ...). The message of a 4xx exception is shown
 * to the visitor, the message of a 5xx exception never is (outside development).
 */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers extra response headers (e.g. Allow)
     */
    public function __construct(
        private readonly int $statusCode = 500,
        string $message = '',
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return ($this->statusCode >= 400 && $this->statusCode <= 599) ? $this->statusCode : 500;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
