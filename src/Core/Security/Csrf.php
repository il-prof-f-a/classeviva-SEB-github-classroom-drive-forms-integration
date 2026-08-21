<?php

declare(strict_types=1);

namespace App\Core\Security;

use RuntimeException;

final class Csrf
{
    private const SESSION_KEY = '_app_csrf_token';

    /** @param array<string,mixed> $session */
    public static function token(array &$session): string
    {
        $existing = $session[self::SESSION_KEY] ?? null;
        if (!is_string($existing) || !preg_match('/^[a-f0-9]{64}$/', $existing)) {
            $existing = bin2hex(random_bytes(32));
            $session[self::SESSION_KEY] = $existing;
        }
        return $existing;
    }

    /** @param array<string,mixed> $session */
    public static function assertValid(array $session, mixed $provided): void
    {
        $expected = $session[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || !is_string($provided) || $provided === ''
            || !hash_equals($expected, $provided)) {
            throw new RuntimeException('Token CSRF non valido. Ricarica la pagina e riprova.');
        }
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $server */
    public static function providedToken(array $post, array $server): ?string
    {
        foreach (['_csrf_token', 'csrf_token'] as $field) {
            $candidate = $post[$field] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        $header = $server['HTTP_X_CSRF_TOKEN'] ?? null;
        return is_string($header) && $header !== '' ? $header : null;
    }

    /** @param array<string,mixed> $session */
    public static function hiddenField(array &$session): string
    {
        $token = htmlspecialchars(self::token($session), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
    }

    public static function injectIntoHtml(string $html, string $token): string
    {
        if ($html === '' || stripos($html, '</head>') === false || str_contains($html, 'id="app-csrf-bridge"')) {
            return $html;
        }

        $encodedToken = json_encode(
            $token,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $bridge = <<<'HTML'
<script id="app-csrf-bridge">
(() => {
    'use strict';
    const token = __TOKEN__;
    const isUnsafe = method => !['GET', 'HEAD', 'OPTIONS', 'TRACE'].includes(String(method || 'GET').toUpperCase());
    const isSameOrigin = value => {
        try { return new URL(value, window.location.href).origin === window.location.origin; }
        catch (_) { return false; }
    };

    const protectForm = form => {
        if (!(form instanceof HTMLFormElement) || !isUnsafe(form.method)) return;
        if (!isSameOrigin(form.action || window.location.href)) return;
        let field = form.querySelector('input[name="_csrf_token"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = '_csrf_token';
            form.appendChild(field);
        }
        field.value = token;
    };
    document.addEventListener('submit', event => {
        protectForm(event.target);
    }, true);
    const nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function() {
        protectForm(this);
        return nativeSubmit.call(this);
    };

    const nativeFetch = window.fetch;
    if (typeof nativeFetch === 'function') {
        window.fetch = function(input, init = {}) {
            const request = input instanceof Request ? input : null;
            const method = init.method || (request ? request.method : 'GET');
            const url = request ? request.url : String(input);
            if (isUnsafe(method) && isSameOrigin(url)) {
                const headers = new Headers(init.headers || (request ? request.headers : undefined));
                headers.set('X-CSRF-Token', token);
                init = {...init, headers};
            }
            return nativeFetch.call(this, input, init);
        };
    }

    const nativeOpen = XMLHttpRequest.prototype.open;
    const nativeSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function(method, url, ...rest) {
        this.__appCsrfUnsafe = isUnsafe(method) && isSameOrigin(url);
        return nativeOpen.call(this, method, url, ...rest);
    };
    XMLHttpRequest.prototype.send = function(body) {
        if (this.__appCsrfUnsafe) this.setRequestHeader('X-CSRF-Token', token);
        return nativeSend.call(this, body);
    };
})();
</script>
HTML;
        $bridge = str_replace('__TOKEN__', $encodedToken, $bridge);
        return preg_replace('/<head([^>]*)>/i', '<head$1>' . $bridge, $html, 1) ?? $html;
    }

    /** @param array<string,mixed> $session */
    public static function installHtmlBridge(array &$session): void
    {
        $token = self::token($session);
        ob_start(static fn(string $html): string => self::injectIntoHtml($html, $token));
    }

    /** @param array<string,mixed> $server @param array<string,mixed> $get */
    public static function shouldInstallHtmlBridge(array $server, array $get): bool
    {
        if (strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET')) !== 'GET') return false;
        $script = strtolower(basename((string)($server['SCRIPT_NAME'] ?? '')));
        $action = strtolower((string)($get['action'] ?? ''));
        if (str_starts_with($script, 'download_') || str_starts_with($action, 'download')
            || str_starts_with($action, 'export')) {
            return false;
        }
        $accept = strtolower((string)($server['HTTP_ACCEPT'] ?? ''));
        return $accept === '' || str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml');
    }
}
