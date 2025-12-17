<?php
declare(strict_types=1);

/**
 * Exception thrown when database connection fails
 */
class DatabaseConnectionException extends Exception
{
    public function __construct(string $message = "Unable to connect to database", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
