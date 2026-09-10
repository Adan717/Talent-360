<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Identidades firmadas por el proveedor, ligadas a este navegador y a un solo intento. */
class SocialIdentity
{
    public const COOKIE = 'talent_social_challenge';

    public function configuration(): array
    {
        return [
            'google_client_id' => config('services.google.client_id') ?: null,
            'apple_client_id' => $this->appleReady() ? config('services.apple.client_id') : null,
            'apple_redirect_uri' => $this->appleReady() ? config('services.apple.redirect_uri') : null,
        ];
    }

    private function appleReady(): bool
    {
        foreach (['client_id', 'team_id', 'key_id', 'private_key_path', 'redirect_uri'] as $key) {
            if (!config("services.apple.$key")) return false;
        }
        return is_readable(config('services.apple.private_key_path'));
    }

    public function challenge()
    {
        $state = Str::random(48);
        $secret = Str::random(48);
        $nonce = Str::random(48);
        Cache::put('social:'.$state, ['nonce' => $nonce, 'browser' => hash('sha256', $secret)], 600);

        return response()->json(['state' => $state, 'nonce' => $nonce])
            ->header('Cache-Control', 'no-store')
            ->cookie(cookie(self::COOKIE, $secret, 10, '/', null, app()->isProduction(), true, false, 'Strict'));
    }

    public function verify(Request $request): array
    {
        $provider = $request->string('provider')->toString();
        if (!in_array($provider, ['google', 'apple'], true)) {
            throw new HttpException(501, 'Este proveedor de acceso no está disponible.');
        }
        $clientId = config("services.$provider.client_id");
        if (!$clientId || ($provider === 'apple' && !$this->appleReady())) {
            throw new HttpException(501, 'El acceso con '.ucfirst($provider).' aún no está habilitado.');
        }
        $state = $request->string('state')->toString();
        if (!$state || strlen($state) > 100) throw new HttpException(401, 'Reinicia el inicio de sesión.');

        return Cache::lock('social-lock:'.$state, 20)->block(3, function () use ($state, $request, $provider, $clientId) {
            $challenge = Cache::get('social:'.$state);
            $secret = $request->cookie(self::COOKIE);
            if (!$challenge || !$secret || !hash_equals($challenge['browser'], hash('sha256', $secret))) {
                throw new HttpException(401, 'El intento de acceso venció. Intenta de nuevo.');
            }
            // Un intento no se puede reutilizar, incluso tras una respuesta inválida del proveedor.
            Cache::forget('social:'.$state);
            $claims = $this->decode($provider, $request->string('id_token')->toString(), $clientId, $challenge['nonce']);
            if ($provider === 'apple') {
                $code = $request->string('code')->toString();
                if (!$code || strlen($code) > 4096) throw new HttpException(401, 'Falta la autorización de Apple.');
                $key = file_get_contents(config('services.apple.private_key_path'));
                $clientSecret = JWT::encode([
                    'iss' => config('services.apple.team_id'), 'iat' => time(), 'exp' => time() + 300,
                    'aud' => 'https://appleid.apple.com', 'sub' => $clientId,
                ], $key, 'ES256', config('services.apple.key_id'));
                try {
                    $response = Http::asForm()->connectTimeout(5)->timeout(10)->post('https://appleid.apple.com/auth/token', [
                        'client_id' => $clientId, 'client_secret' => $clientSecret, 'code' => $code,
                        'grant_type' => 'authorization_code', 'redirect_uri' => config('services.apple.redirect_uri'),
                    ]);
                } catch (\Throwable $e) {
                    throw new HttpException(503, 'Apple no respondió. Intenta de nuevo.');
                }
                if (!$response->successful() || !$response->json('id_token')) throw new HttpException(401, 'Apple rechazó la autorización.');
                $confirmed = $this->decode('apple', $response->json('id_token'), $clientId, $challenge['nonce']);
                if ($claims['sub'] !== $confirmed['sub']) throw new HttpException(401, 'Identidad de Apple inconsistente.');
                $claims = $confirmed;
            }
            return $claims;
        });
    }

    private function decode(string $provider, string $token, string $audience, string $nonce): array
    {
        $url = $provider === 'google' ? 'https://www.googleapis.com/oauth2/v3/certs' : 'https://appleid.apple.com/auth/keys';
        try {
            $jwks = Cache::remember('social-jwks:'.$provider, 3600, function () use ($url) {
                return Http::connectTimeout(5)->timeout(10)->get($url)->throw()->json();
            });
        } catch (\Throwable $e) {
            throw new HttpException(503, 'No se pudo consultar al proveedor de identidad.');
        }
        try {
            // Sólo RS256: nunca aceptar el algoritmo enviado por un atacante como política.
            $jwks['keys'] = array_values(array_filter($jwks['keys'] ?? [], fn ($k) => ($k['kty'] ?? '') === 'RSA' && ($k['alg'] ?? 'RS256') === 'RS256'));
            $claims = (array) JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'));
        } catch (\Throwable $e) {
            throw new HttpException(401, 'La credencial es inválida o ha vencido.');
        }
        $issuers = $provider === 'google' ? ['accounts.google.com', 'https://accounts.google.com'] : ['https://appleid.apple.com'];
        if (!in_array($claims['iss'] ?? '', $issuers, true) || ($claims['aud'] ?? null) !== $audience
            || !is_numeric($claims['exp'] ?? null) || $claims['exp'] <= time()
            || !is_string($claims['sub'] ?? null) || !$claims['sub']
            || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw new HttpException(401, 'La credencial no corresponde a este intento de acceso.');
        }
        if (!in_array($claims['email_verified'] ?? false, [true, 'true'], true)
            || !filter_var($claims['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(401, 'El proveedor no confirmó un correo válido.');
        }
        $claims['email'] = strtolower($claims['email']);
        // Google sólo garantiza posesión actual del correo de Gmail o Workspace.
        $claims['email_authoritative'] = $provider === 'apple'
            || str_ends_with($claims['email'], '@gmail.com') || !empty($claims['hd']);
        return $claims;
    }
}
