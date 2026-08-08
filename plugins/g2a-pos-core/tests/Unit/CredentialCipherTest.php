<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\Crypto\CredentialCipher;
use PHPUnit\Framework\TestCase;

final class CredentialCipherTest extends TestCase
{
    public function test_roundtrip_preserves_array(): void
    {
        $original = ['email' => 'dealer@example.com', 'password' => 'sup3r-secret', 'ftp_user' => 'guns2ammo'];
        $blob = CredentialCipher::encrypt($original);

        $this->assertStringStartsWith('enc2:', $blob);
        $this->assertSame($original, CredentialCipher::decrypt($blob));
    }

    public function test_encrypt_produces_different_ciphertext_each_call_with_same_input(): void
    {
        $payload = ['email' => 'x@y.z', 'password' => 'same'];
        $a = CredentialCipher::encrypt($payload);
        $b = CredentialCipher::encrypt($payload);
        $this->assertNotSame($a, $b, 'IV randomization should produce different ciphertext');
        $this->assertSame($payload, CredentialCipher::decrypt($a));
        $this->assertSame($payload, CredentialCipher::decrypt($b));
    }

    public function test_decrypt_of_empty_string_returns_empty_array(): void
    {
        $this->assertSame([], CredentialCipher::decrypt(''));
    }

    public function test_decrypt_of_garbage_returns_empty_array(): void
    {
        $this->assertSame([], CredentialCipher::decrypt('enc1:!!!not-base64!!!'));
        $this->assertSame([], CredentialCipher::decrypt('enc2:' . base64_encode('too-short')));
    }

    public function test_decrypt_of_tampered_ciphertext_returns_empty_array(): void
    {
        $blob = CredentialCipher::encrypt(['password' => 'real']);
        // Flip a byte in the authenticated ciphertext.
        $raw = base64_decode(substr($blob, 5), true);
        $offset = 28;
        $tampered = substr($raw, 0, $offset) . chr(ord($raw[$offset]) ^ 0xff) . substr($raw, $offset + 1);
        $result = CredentialCipher::decrypt('enc2:' . base64_encode($tampered));
        $this->assertSame([], $result);
    }

    public function test_legacy_base64_fallback_blob_is_decoded(): void
    {
        // Older rows (or hosts with no openssl) used a plain base64-encoded JSON blob.
        $legacy = base64_encode(json_encode(['email' => 'old@example.com']));
        $this->assertSame(['email' => 'old@example.com'], CredentialCipher::decrypt($legacy));
    }
}
