<?php
namespace WaffoPancake\Api;

class Waffo_Api_Exception extends \RuntimeException
{
    private int $status_code;
    private ?string $error_code;

    // status_code默认值0代表网络层错误（如超时/连接失败），请求未真正到达Waffo服务端，因此没有HTTP状态码。
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

    /**
     * 判断该错误是否值得调用方重试。
     * 5xx（服务端错误）和429（限流）通常是瞬时性问题，重试可能成功；
     * 4xx（除429外，如400/401/404）通常是请求本身有问题，重试无意义。
     */
    public function is_retryable(): bool
    {
        return $this->status_code >= 500 || $this->status_code === 429;
    }
}
