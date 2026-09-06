<?php
/**
 * Parent sign-in for the dashboard.
 *
 * The board is readable by anyone with the link and that is deliberate — she
 * should be able to glance at it without a login. But the controls that write
 * to the record are the parent's alone, so they are gated behind a signed
 * cookie obtained with the password the service already has.
 *
 * There is no session table: the cookie carries its own expiry and a signature
 * over it, so a stolen cookie cannot be extended and a forged one cannot be
 * made without the key. Changing TRACKER_PASSWORD invalidates every existing
 * cookie, because the password's fingerprint is part of what is signed.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const PARENT_COOKIE = 'tracker_parent';
const PARENT_TTL    = 60 * 60 * 24 * 120;   // 120 days: a login per device, per term.

/**
 * The signing key, generated once and kept in meta. Deriving it from the
 * password instead would mean the password itself had to be reachable to
 * verify a cookie, and would leak its strength into every signature.
 */
function parent_key(Store $store): string
{
    $key = $store->meta('parent_cookie_key');
    if ($key === null || strlen($key) < 32) {
        $key = bin2hex(random_bytes(32));
        $store->setMeta('parent_cookie_key', $key);
    }
    return $key;
}

/** A short fingerprint of the current password, so changing it signs everyone out. */
function parent_password_fingerprint(string $password): string
{
    return substr(hash_hmac('sha256', 'password-fingerprint', $password), 0, 16);
}

function parent_sign(Store $store, string $payload): string
{
    return hash_hmac('sha256', $payload, parent_key($store));
}

/** Mint the cookie value for a successful login. */
function parent_issue(Store $store, string $password): string
{
    $payload = 'v1|' . (time() + PARENT_TTL) . '|' . parent_password_fingerprint($password);
    return $payload . '|' . parent_sign($store, $payload);
}

/**
 * Is this request the parent? Signature first, then expiry, then the password
 * fingerprint — all three, and the signature compared in constant time.
 */
function parent_signed_in(Store $store, string $password): bool
{
    $raw = (string) ($_COOKIE[PARENT_COOKIE] ?? '');
    if ($raw === '' || substr_count($raw, '|') !== 3) {
        return false;
    }
    [$v, $expires, $fingerprint, $sig] = explode('|', $raw);
    $payload = $v . '|' . $expires . '|' . $fingerprint;
    if (!hash_equals(parent_sign($store, $payload), $sig)) {
        return false;
    }
    if ($v !== 'v1' || (int) $expires < time()) {
        return false;
    }
    return hash_equals(parent_password_fingerprint($password), $fingerprint);
}

function parent_set_cookie(string $value, int $maxAge): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    setcookie(PARENT_COOKIE, $value, [
        'expires'  => $maxAge > 0 ? time() + $maxAge : 1,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,          // never readable from script
        'samesite' => 'Lax',         // a cross-site POST carries no cookie
    ]);
}

/**
 * A CSRF token bound to this cookie. SameSite=Lax already blocks a cross-site
 * form post, but that is one browser behaviour standing between a stranger and
 * the record, and one is not enough.
 */
function parent_csrf(Store $store): string
{
    $raw = (string) ($_COOKIE[PARENT_COOKIE] ?? '');
    return substr(hash_hmac('sha256', 'csrf|' . $raw, parent_key($store)), 0, 32);
}

function parent_check_csrf(Store $store, array $body): bool
{
    $sent = (string) ($body['csrf'] ?? '');
    return $sent !== '' && hash_equals(parent_csrf($store), $sent);
}

/** Only ever redirect back into this site. */
function parent_safe_next(mixed $next): string
{
    $n = (string) ($next ?? '');
    if ($n === '' || $n[0] !== '/' || str_starts_with($n, '//')) {
        return '/';
    }
    return $n;
}

function parent_redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}
