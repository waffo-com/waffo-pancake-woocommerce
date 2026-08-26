<?php
namespace WaffoPancake\Signing;

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
            throw new \RuntimeException('Invalid RSA private key');
        }

        openssl_sign($canonical, $signature, $private_key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }
}
