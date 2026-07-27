<?php

declare(strict_types=1);

namespace App\Support\TwoFactor;

/**
 * RFC 6238 TOTP (and the RFC 4648 base32 it needs), implemented here rather than pulled in
 * as a dependency: it is ~60 lines of well-specified maths and the release bundle ships
 * vendor/ prebuilt, so fewer third-party packages is fewer things to keep patched.
 *
 * Defaults match every mainstream authenticator app (Google Authenticator, 1Password,
 * Authy): SHA-1, 6 digits, 30-second steps.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const DIGITS = 6;

    private const PERIOD = 30;

    /** A fresh base32 secret (160 bits, the RFC-recommended size for SHA-1). */
    public static function generateSecret(): string
    {
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    /**
     * The `otpauth://` URI an authenticator app consumes (via QR or manual entry).
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);

        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.$query;
    }

    /**
     * Verify a user-supplied code, allowing $window steps of clock drift either way
     * (±30s by default — standard tolerance for phones with a slightly off clock).
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv(time(), self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            // hash_equals: constant-time, so a wrong code leaks no timing information.
            if (hash_equals(self::codeAt($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** The code an authenticator app would display for this secret right now. */
    public static function currentCode(string $secret): string
    {
        return self::codeAt($secret, intdiv(time(), self::PERIOD));
    }

    /** The 6-digit code for a given 30-second counter (HOTP over the counter, RFC 4226). */
    private static function codeAt(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);

        if ($key === '') {
            return '';
        }

        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Decode an RFC 4648 base32 secret to raw bytes ('' when malformed). */
    private static function base32Decode(string $secret): string
    {
        $secret = rtrim(strtoupper(trim($secret)), '=');
        $bits = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }

    /**
     * One-time recovery codes for when the phone is lost.
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(4)));
        }

        return $codes;
    }
}
