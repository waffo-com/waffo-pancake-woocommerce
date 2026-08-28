<?php
namespace WaffoPancake\Signing;

use WaffoPancake\Api\Waffo_Api_Exception;

class Waffo_Signer
{
    private string $private_key_pem;

    public function __construct(string $private_key_pem)
    {
        $this->private_key_pem = $private_key_pem;
    }

    public function sign(string $method, string $path, int $timestamp_ms, string $body): string
    {
        $path_without_query = strtok($path, '?');
        $body_hash = base64_encode(hash('sha256', $body, true));

        $canonical = strtoupper($method) . "\n" . $path_without_query . "\n" . $timestamp_ms . "\n" . $body_hash;

        $private_key = openssl_pkey_get_private($this->private_key_pem);
        if ($private_key === false) {
            // status_code默认值0：这是本地校验失败，请求从未到达Waffo服务端，
            // 与Waffo_Api_Exception其余用法中"网络层错误"的status_code=0档位语义一致。
            throw new Waffo_Api_Exception('Invalid RSA private key');
        }

        if (openssl_sign($canonical, $signature, $private_key, OPENSSL_ALGO_SHA256) === false) {
            throw new Waffo_Api_Exception('Failed to sign request: ' . openssl_error_string());
        }

        return base64_encode($signature);
    }
}
