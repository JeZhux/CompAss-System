<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ?string $csrfSessionCookie = null;

    protected ?string $csrfTokenCookie = null;

    /**
     * ARCH-005 §2: bootstrap the SPA CSRF flow — GET /sanctum/csrf-cookie and
     * capture the session + XSRF cookie values so a subsequent stateful
     * request can relay them (array session driver gives every cookie-less
     * request a fresh session id, which would orphan the token).
     */
    protected function csrfBootstrap(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'laravel_session') {
                $this->csrfSessionCookie = $cookie->getValue();
            }

            if ($cookie->getName() === 'XSRF-TOKEN') {
                $this->csrfTokenCookie = $cookie->getValue();
            }
        }
    }

    /**
     * @return array{Referer: string, X-XSRF-TOKEN: string}
     */
    protected function csrfHeaders(): array
    {
        return [
            'Referer' => 'http://localhost/',
            'X-XSRF-TOKEN' => (string) $this->csrfTokenCookie,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function withSessionCookie(array $headers): array
    {
        if ($this->csrfSessionCookie !== null) {
            $headers['Cookie'] = 'laravel_session=' . $this->csrfSessionCookie;
        }

        return $headers;
    }

    /**
     * Stateful POST helper: Referer + session cookie, optional X-XSRF-TOKEN.
     *
     * @param  array<string, mixed>  $data
     * @return \Illuminate\Testing\TestResponse
     */
    protected function statefulPost(string $uri, array $data = [], bool $withToken = true)
    {
        $headers = $this->csrfHeaders();

        if (! $withToken) {
            unset($headers['X-XSRF-TOKEN']);
        }

        return $this->post($uri, $data, $this->withSessionCookie($headers));
    }
}
