<?php

namespace EasyBusyChats\Exception;

final class IntegrationException extends \RuntimeException
{
    private string $errorCode;
    private array $details;
    private bool $retryable;

    public function __construct(string $message, string $code, bool $retryable = false, array $details = [])
    {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->retryable = $retryable;
        $this->details = $details;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
