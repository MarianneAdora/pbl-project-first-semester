<?php
// src/Helpers/encrypt.php — field-level encryption for sensitive data at rest.
// Uses AES-256-GCM via openssl_encrypt/openssl_decrypt. Key must be 32 bytes.
// Set ENCRYPTION_KEY in .env to a secret of at least 32 characters.

declare(strict_types=1);

function encryption_key(): string
{
    $key = getenv('ENCRYPTION_KEY');
    if ($key === false || strlen($key) < 32) {
        throw new RuntimeException('ENCRYPTION_KEY must be configured with at least 32 characters.');
    }
    return hash('sha256', $key, true);
}

/**
 * Encrypt a string. Returns base64(iv . auth_tag . ciphertext) or null.
 */
function encrypt_field(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }
    $key = encryption_key();
    $iv = random_bytes(12);
    $cipher = openssl_encrypt(
        $value,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if ($cipher === false) {
        throw new RuntimeException('Field encryption failed.');
    }
    return base64_encode($iv . $tag . $cipher);
}

/**
 * Decrypt a value encrypted by encrypt_field(). Returns null when input is null/empty.
 */
function decrypt_field(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }
    $data = base64_decode($value, true);
    if ($data === false || strlen($data) < 28) {
        return null;
    }
    $iv = substr($data, 0, 12);
    $tag = substr($data, 12, 16);
    $cipher = substr($data, 28);
    $key = encryption_key();
    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if ($plain === false) {
        return null;
    }
    return $plain;
}
