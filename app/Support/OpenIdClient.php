<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;

class OpenIdClient extends OpenIDConnectClient
{
    /** Scopes asked when the settings name none: openid is always added. */
    public const DEFAULT_SCOPES = 'openid profile email';

    /**
     * Scopes to request besides openid, which the library always adds, from a
     * list separated by spaces or commas.
     *
     * @return list<string>
     */
    public static function scopes(?string $configured): array
    {
        $scopes = preg_split('/[\s,]+/', trim((string) ($configured ?: self::DEFAULT_SCOPES)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_diff(array_unique($scopes), ['openid']));
    }

    public function authenticateRequest(array $query): bool
    {
        $original = $_REQUEST;
        $_REQUEST = $query;

        try {
            return $this->authenticate();
        } finally {
            $_REQUEST = $original;
        }
    }

    protected function getSessionKey(string $key): mixed
    {
        return session()->get('oidc.'.$key, false);
    }

    protected function setSessionKey(string $key, $value): void
    {
        session()->put('oidc.'.$key, $value);
    }

    protected function unsetSessionKey(string $key): void
    {
        session()->forget('oidc.'.$key);
    }

    protected function commitSession(): void {}

    public function redirect(string $url): never
    {
        $this->requireHttps($url);

        if (! $this->getCodeVerifier()) {
            throw new OpenIDConnectClientException('Le fournisseur doit prendre en charge PKCE S256.');
        }

        throw new HttpResponseException(redirect()->away($url));
    }

    protected function fetchURL(string $url, ?string $post_body = null, array $headers = []): string
    {
        $this->requireHttps($url);

        $request = Http::timeout(8)->withoutRedirecting();
        foreach ($headers as $header) {
            [$name, $value] = explode(':', $header, 2);
            $request = $request->withHeader($name, trim($value));
        }

        $response = $post_body === null
            ? $request->get($url)
            : $request->withBody($post_body, 'application/x-www-form-urlencoded')->post($url);

        if (! $response->successful()) {
            throw new OpenIDConnectClientException('Échec de la requête OpenID Connect.');
        }

        if ($url === rtrim($this->getProviderURL(), '/').'/.well-known/openid-configuration'
            && $response->json('issuer') !== $this->getProviderURL()) {
            throw new OpenIDConnectClientException('L’émetteur annoncé ne correspond pas aux paramètres.');
        }

        return $response->body();
    }

    /**
     * Claims of the userinfo endpoint, for providers that leave the profile
     * out of the ID token (Zitadel by default). They only count when they
     * describe the same subject as the verified ID token.
     *
     * @return array<string, mixed>
     */
    public function userInfo(): array
    {
        try {
            $endpoint = $this->getProviderConfigValue('userinfo_endpoint');
        } catch (OpenIDConnectClientException) {
            return [];
        }
        $token = $this->getAccessToken();
        if (! is_string($endpoint) || ! is_string($token) || $token === '') {
            return [];
        }

        $claims = json_decode($this->fetchURL($endpoint, null, ['Authorization: Bearer '.$token, 'Accept: application/json']), true);

        return is_array($claims) && is_string($claims['sub'] ?? null) && $claims['sub'] === $this->getVerifiedClaims('sub') ? $claims : [];
    }

    public function verifyJWTSignature(string $jwt): bool
    {
        $header = $this->decodeJWT($jwt);

        return in_array($header->alg ?? null, ['RS256', 'RS384', 'RS512', 'PS256', 'PS512'], true)
            && ! isset($header->jwk)
            && parent::verifyJWTSignature($jwt);
    }

    protected function verifyJWTClaims($claims, ?string $accessToken = null): bool
    {
        return is_string($claims->sub ?? null) && $claims->sub !== ''
            && is_string($claims->iss ?? null)
            && (is_array($claims->aud ?? null) || is_string($claims->aud ?? null))
            && (count((array) $claims->aud) === 1 || isset($claims->azp))
            && (! isset($claims->azp) || $claims->azp === $this->getClientID())
            && is_int($claims->exp ?? null) && $claims->exp > time()
            && is_int($claims->iat ?? null) && $claims->iat <= time() + 60
            && is_string($claims->nonce ?? null)
            && $claims->nonce === $this->getNonce()
            && parent::verifyJWTClaims($claims, $accessToken);
    }

    private function requireHttps(string $url): void
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! parse_url($url, PHP_URL_HOST)) {
            throw new OpenIDConnectClientException('OpenID Connect exige HTTPS.');
        }
    }
}
