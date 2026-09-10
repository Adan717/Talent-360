<?php

namespace Tests\Concerns;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

trait SignsSocialCredentials
{
    private function credential(array $changes = [], string $provider = 'google'): array
    {
        config(["services.$provider.client_id" => 'talent-client']);
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rsa, $private);
        $key = openssl_pkey_get_details($rsa)['rsa'];
        $base64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Cache::forget('social-jwks:'.$provider);
        Http::fake(['*.googleapis.com/*' => Http::response(['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'test', 'n' => $base64($key['n']), 'e' => $base64($key['e'])]]]), 'appleid.apple.com/auth/keys' => Http::response(['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'test', 'n' => $base64($key['n']), 'e' => $base64($key['e'])]]])]);
        Cache::put('social:attempt', ['nonce' => 'nonce-test', 'browser' => hash('sha256', 'browser-test')], 600);
        return ['provider' => $provider, 'state' => 'attempt', 'id_token' => JWT::encode(array_merge([
            'iss' => $provider === 'google' ? 'https://accounts.google.com' : 'https://appleid.apple.com', 'aud' => 'talent-client', 'iat' => time() - 1, 'exp' => time() + 600,
            'sub' => 'real-subject', 'nonce' => 'nonce-test', 'email' => 'qa@gmail.com', 'email_verified' => true, 'name' => 'Nombre del proveedor',
        ], $changes), $private, 'RS256', 'test')];
    }

}
