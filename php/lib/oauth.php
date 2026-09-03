<?php
/**
 * A deliberately small OAuth 2.1 authorisation server.
 *
 * Claude.ai custom connectors do not accept a static bearer token on a personal
 * account — static_headers is a beta feature gated behind an organisation
 * administrator — but OAuth with Dynamic Client Registration is supported out
 * of the box. So this implements exactly the subset Claude's connector flow
 * needs:
 *
 *   - RFC 9728 protected resource metadata
 *   - RFC 8414 authorisation server metadata
 *   - RFC 7591 dynamic client registration
 *   - authorisation code grant with mandatory PKCE (S256)
 *   - refresh tokens with rotation
 *
 * There is exactly one human user, so "authentication" is a single shared
 * password on the consent screen rather than a user table.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const CODE_TTL   = 600;    // 10 minutes
const ACCESS_TTL = 3600;   // 1 hour
const OAUTH_SCOPE = 'tracker.read tracker.write';

function oauth_token_value(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function oauth_s256(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

/**
 * The bearer token, or null. Under CGI/FastCGI Apache does not pass the
 * Authorization header into the environment by default; .htaccess copies it
 * into HTTP_AUTHORIZATION, and mod_rewrite may prefix it with REDIRECT_.
 */
function oauth_bearer(): ?string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    ];
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $candidates[] = $v;
            }
        }
    }
    foreach ($candidates as $header) {
        if ($header && preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return $m[1];
        }
    }
    return null;
}

/**
 * Bearer auth guard. Emits the 401 + WWW-Authenticate shape Claude needs in
 * order to discover where the authorisation server lives, and stops.
 */
function require_token(Store $store, string $publicUrl): void
{
    $challenge = 'Bearer resource_metadata="' . $publicUrl . '/.well-known/oauth-protected-resource"';
    $token     = oauth_bearer();

    if ($token === null) {
        header('WWW-Authenticate: ' . $challenge);
        send_json(['error' => 'unauthorized', 'error_description' => 'Bearer token required.'], 401);
    }

    $st = $store->db->prepare("SELECT * FROM oauth_tokens WHERE token = ? AND kind = 'access'");
    $st->execute([$token]);
    $row = $st->fetch();

    if (!$row || ($row['expires_at'] !== null && (int) $row['expires_at'] < now_ms())) {
        header('WWW-Authenticate: ' . $challenge);
        send_json(['error' => 'invalid_token', 'error_description' => 'Token expired or unknown.'], 401);
    }
}

/** Milliseconds, matching what the Node version stored in expires_at. */
function now_ms(): int
{
    return (int) (microtime(true) * 1000);
}

function oauth_protected_resource_metadata(string $publicUrl): array
{
    return [
        'resource'                 => $publicUrl . '/mcp',
        'authorization_servers'    => [$publicUrl],
        'scopes_supported'         => explode(' ', OAUTH_SCOPE),
        'bearer_methods_supported' => ['header'],
    ];
}

function oauth_authorization_server_metadata(string $publicUrl): array
{
    return [
        'issuer'                                => $publicUrl,
        'authorization_endpoint'                => $publicUrl . '/oauth/authorize',
        'token_endpoint'                        => $publicUrl . '/oauth/token',
        'registration_endpoint'                 => $publicUrl . '/oauth/register',
        'scopes_supported'                      => explode(' ', OAUTH_SCOPE),
        'response_types_supported'              => ['code'],
        'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
        'code_challenge_methods_supported'      => ['S256'],
    ];
}

// ---- dynamic client registration (RFC 7591) -----------------------------

function oauth_register(Store $store, array $body): void
{
    $uris = is_array($body['redirect_uris'] ?? null) ? $body['redirect_uris'] : [];
    if (!$uris) {
        send_json([
            'error'             => 'invalid_redirect_uri',
            'error_description' => 'redirect_uris must contain at least one URI',
        ], 400);
    }
    $clientId = 'c_' . bin2hex(random_bytes(12));
    $name     = (string) ($body['client_name'] ?? 'unknown');

    $st = $store->db->prepare(
        'INSERT INTO oauth_clients (client_id, client_secret, redirect_uris, client_name)
         VALUES (?, NULL, ?, ?)'
    );
    $st->execute([$clientId, json_encode(array_values($uris)), $name]);

    send_json([
        'client_id'                  => $clientId,
        'client_id_issued_at'        => time(),
        'redirect_uris'              => array_values($uris),
        'token_endpoint_auth_method' => 'none',
        'grant_types'                => ['authorization_code', 'refresh_token'],
        'response_types'             => ['code'],
        'client_name'                => $name,
    ], 201);
}

// ---- authorisation ------------------------------------------------------

function oauth_authorize_form(Store $store, string $publicUrl, array $q): void
{
    $st = $store->db->prepare('SELECT * FROM oauth_clients WHERE client_id = ?');
    $st->execute([(string) ($q['client_id'] ?? '')]);
    $client = $st->fetch();

    if (!$client) {
        send_text('Unknown client_id.', 400);
    }
    $allowed = json_decode((string) $client['redirect_uris'], true) ?: [];
    if (empty($q['redirect_uri']) || !in_array($q['redirect_uri'], $allowed, true)) {
        send_text('redirect_uri does not match registration.', 400);
    }
    if (($q['code_challenge_method'] ?? '') !== 'S256' || empty($q['code_challenge'])) {
        send_text('PKCE with S256 is required.', 400);
    }

    $hidden = '';
    foreach (['client_id', 'redirect_uri', 'state', 'code_challenge', 'scope'] as $k) {
        $hidden .= '<input type="hidden" name="' . $k . '" value="' . h($q[$k] ?? '') . '">';
    }

    $url = h($publicUrl);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="en-GB"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Authorise tracker access</title>
<style>
 body{font-family:Georgia,serif;background:#fcfcf9;color:#1c1917;display:flex;
      min-height:100vh;align-items:center;justify-content:center;margin:0;padding:1rem}
 .card{background:#fff;border:1px solid #d6d3d1;border-radius:12px;padding:2rem;max-width:26rem;width:100%}
 h1{font-size:1.25rem;margin:0 0 .5rem}
 p{color:#57534e;font-size:.9rem;line-height:1.5}
 input[type=password]{width:100%;padding:.6rem;border:1px solid #d6d3d1;border-radius:6px;
      font-size:1rem;box-sizing:border-box;margin:.75rem 0}
 button{width:100%;padding:.65rem;background:#1c1917;color:#fff;border:0;border-radius:6px;
      font-size:1rem;cursor:pointer}
 code{background:#f5f5f4;padding:.1rem .3rem;border-radius:3px;font-size:.8rem}
</style></head><body>
<div class="card">
  <h1>Authorise tracker access</h1>
  <p>A client is asking to read and update your study tracker at
     <code>{$url}</code>. Enter the tracker password to allow it.</p>
  <form method="POST" action="/oauth/authorize">
    {$hidden}
    <input type="password" name="password" placeholder="Tracker password" autofocus required>
    <button type="submit">Allow access</button>
  </form>
</div></body></html>
HTML;
    exit;
}

function oauth_authorize_submit(Store $store, string $password, array $b): void
{
    if (empty($b['password']) || !hash_equals($password, (string) $b['password'])) {
        send_text('Incorrect password. Go back and try again.', 401);
    }
    $st = $store->db->prepare('SELECT * FROM oauth_clients WHERE client_id = ?');
    $st->execute([(string) ($b['client_id'] ?? '')]);
    $client = $st->fetch();
    if (!$client) {
        send_text('Unknown client_id.', 400);
    }
    $allowed = json_decode((string) $client['redirect_uris'], true) ?: [];
    if (!in_array($b['redirect_uri'] ?? '', $allowed, true)) {
        send_text('redirect_uri does not match registration.', 400);
    }

    $code = oauth_token_value();
    $st   = $store->db->prepare(
        'INSERT INTO oauth_codes (code, client_id, redirect_uri, code_challenge, scope, expires_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $code,
        $b['client_id'],
        $b['redirect_uri'],
        $b['code_challenge'] ?? '',
        ($b['scope'] ?? '') ?: OAUTH_SCOPE,
        now_ms() + CODE_TTL * 1000,
    ]);

    $sep = str_contains((string) $b['redirect_uri'], '?') ? '&' : '?';
    $to  = $b['redirect_uri'] . $sep . 'code=' . rawurlencode($code);
    if (!empty($b['state'])) {
        $to .= '&state=' . rawurlencode((string) $b['state']);
    }
    header('Location: ' . $to, true, 302);
    exit;
}

// ---- token --------------------------------------------------------------

function oauth_issue(Store $store, string $clientId, string $scope): array
{
    $access  = oauth_token_value();
    $refresh = oauth_token_value();
    $st      = $store->db->prepare(
        'INSERT INTO oauth_tokens (token, kind, client_id, scope, expires_at) VALUES (?, ?, ?, ?, ?)'
    );
    $st->execute([$access, 'access', $clientId, $scope, now_ms() + ACCESS_TTL * 1000]);
    $st->execute([$refresh, 'refresh', $clientId, $scope, null]);

    return [
        'access_token'  => $access,
        'token_type'    => 'Bearer',
        'expires_in'    => ACCESS_TTL,
        'refresh_token' => $refresh,
        'scope'         => $scope,
    ];
}

function oauth_token_endpoint(Store $store, array $b): void
{
    $grant = $b['grant_type'] ?? '';

    if ($grant === 'authorization_code') {
        $code = (string) ($b['code'] ?? '');
        $st   = $store->db->prepare('SELECT * FROM oauth_codes WHERE code = ?');
        $st->execute([$code]);
        $row = $st->fetch();

        // Single use, whatever happens next.
        $store->db->prepare('DELETE FROM oauth_codes WHERE code = ?')->execute([$code]);

        if (!$row || (int) $row['expires_at'] < now_ms()) {
            send_json(['error' => 'invalid_grant', 'error_description' => 'Code invalid or expired.'], 400);
        }
        if ($row['client_id'] !== ($b['client_id'] ?? null) || $row['redirect_uri'] !== ($b['redirect_uri'] ?? null)) {
            send_json(['error' => 'invalid_grant', 'error_description' => 'Client or redirect mismatch.'], 400);
        }
        if (empty($b['code_verifier']) || !hash_equals((string) $row['code_challenge'], oauth_s256((string) $b['code_verifier']))) {
            send_json(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.'], 400);
        }
        send_json(oauth_issue($store, (string) $row['client_id'], (string) $row['scope']));
    }

    if ($grant === 'refresh_token') {
        $st = $store->db->prepare("SELECT * FROM oauth_tokens WHERE token = ? AND kind = 'refresh'");
        $st->execute([(string) ($b['refresh_token'] ?? '')]);
        $row = $st->fetch();
        if (!$row) {
            send_json(['error' => 'invalid_grant', 'error_description' => 'Refresh token not recognised.'], 400);
        }
        // Rotate: public clients must not reuse refresh tokens.
        $store->db->prepare('DELETE FROM oauth_tokens WHERE token = ?')->execute([$b['refresh_token']]);
        send_json(oauth_issue($store, (string) $row['client_id'], (string) $row['scope']));
    }

    send_json([
        'error'             => 'unsupported_grant_type',
        'error_description' => 'Use authorization_code or refresh_token.',
    ], 400);
}

/**
 * Housekeeping. The Node version ran this on an hourly timer; without a
 * long-lived process it rides on requests instead, rarely enough to cost
 * nothing and often enough that expired rows never accumulate.
 */
function oauth_sweep_expired(Store $store): void
{
    if (random_int(1, 200) !== 1) {
        return;
    }
    $now = now_ms();
    $store->db->prepare('DELETE FROM oauth_codes WHERE expires_at < ?')->execute([$now]);
    $store->db->prepare(
        "DELETE FROM oauth_tokens WHERE kind = 'access' AND expires_at IS NOT NULL AND expires_at < ?"
    )->execute([$now]);
}
