<?php

declare(strict_types=1);

namespace Platform\Dashboard;

use RuntimeException;

/** An error that should be shown to the person as a page, with this HTTP status. */
final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
