<?php
namespace WaffoPancake\Api;

class Waffo_Api_Exception extends \RuntimeException
{
    private int $status_code;
    private ?string $error_code;

    public function __construct(string $message, int $status_code = 0, ?string $error_code = null)
    {
        parent::__construct($message);
        $this->status_code = $status_code;
        $this->error_code = $error_code;
    }

    public function getStatusCode(): int
    {
        return $this->status_code;
    }

    public function getErrorCode(): ?string
    {
        return $this->error_code;
    }
}
