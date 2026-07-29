<?php
/**
 * Application-level encryption for health data (AES-256-GCM).
 *
 * COPPA-COMPLIANCE.md specifies column-level encryption for athlete health fields.
 * A previous implementation of this file was documented as deployed (Heroku v164,
 * Feb 2026) but is absent from the tree and from git history entirely — the same
 * loss pattern as the communications backend. This is a fresh implementation of
 * that contract.
 *
 * Format:  enc:v1:<base64( iv[12] || tag[16] || ciphertext )>
 *
 * The version tag is part of the payload so a future key rotation or cipher change
 * can be told apart from existing data rather than guessed at.
 *
 * Key: MEDICAL_ENCRYPTION_KEY, a base64-encoded 32-byte value.
 *
 * Reads fall back to plaintext. Writes never do — if the key is missing, encrypt()
 * throws rather than quietly persisting PHI in the clear, which is the failure mode
 * this whole exercise exists to prevent.
 */

require_once __DIR__ . '/../config/env.php';

class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'enc:v1:';
    private const IV_LEN = 12;   // GCM standard nonce length
    private const TAG_LEN = 16;

    /** @var string|null Cached raw key bytes. */
    private static $key = null;

    /**
     * Raw 32-byte key, or null when unconfigured.
     */
    private static function key(): ?string
    {
        if (self::$key !== null) {
            return self::$key ?: null;
        }

        $raw = Env::get('MEDICAL_ENCRYPTION_KEY', '');
        if (!is_string($raw) || trim($raw) === '') {
            self::$key = '';
            return null;
        }

        $decoded = base64_decode(trim($raw), true);
        if ($decoded === false || strlen($decoded) !== 32) {
            // A malformed key is a configuration error, not a reason to write
            // plaintext. Treat it as absent so encrypt() throws.
            error_log('Encryption: MEDICAL_ENCRYPTION_KEY must be base64 of exactly 32 bytes');
            self::$key = '';
            return null;
        }

        self::$key = $decoded;
        return self::$key;
    }

    /** Is a usable key configured? */
    public static function isAvailable(): bool
    {
        return self::key() !== null;
    }

    /** Does this value already look encrypted by us? */
    public static function isEncrypted($value): bool
    {
        return is_string($value) && strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /**
     * Encrypt a value. Null and empty string pass through untouched so the column
     * keeps meaning "not provided" rather than becoming ciphertext-of-nothing.
     *
     * @throws RuntimeException when no usable key is configured.
     */
    public static function encrypt($plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain === null ? null : '';
        }
        if (self::isEncrypted($plain)) {
            return $plain; // already encrypted; never double-wrap
        }

        $key = self::key();
        if ($key === null) {
            throw new RuntimeException('MEDICAL_ENCRYPTION_KEY is not configured; refusing to store health data in plaintext');
        }

        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt((string) $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Decrypt a value written by encrypt().
     *
     * Anything not carrying our prefix is returned unchanged — that is the
     * documented plaintext fallback for legacy rows, and it is what lets the
     * is_encrypted flags on medical_records / medications / allergies keep working.
     *
     * A value that IS prefixed but fails to authenticate returns null rather than
     * garbage: GCM authentication failing means the data was tampered with or the
     * key is wrong, and surfacing a corrupted allergy list would be worse than
     * surfacing nothing.
     */
    public static function decrypt($value)
    {
        if (!self::isEncrypted($value)) {
            return $value; // plaintext / null / non-string
        }

        $key = self::key();
        if ($key === null) {
            error_log('Encryption: ciphertext found but no key configured');
            return null;
        }

        $blob = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($blob === false || strlen($blob) <= self::IV_LEN + self::TAG_LEN) {
            error_log('Encryption: malformed ciphertext payload');
            return null;
        }

        $iv     = substr($blob, 0, self::IV_LEN);
        $tag    = substr($blob, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($blob, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            error_log('Encryption: authentication failed (wrong key or tampered data)');
            return null;
        }

        return $plain;
    }

    /**
     * The athlete_medical columns that carry free-text PHI.
     *
     * Deliberately excludes the fields the application reasons about —
     * allergy_severity, has_epipen, has_asthma, the dates, height/weight — so
     * alerting and any future filtering keep working without a decrypt pass.
     */
    public static function athleteMedicalFields(): array
    {
        return [
            'allergies', 'medical_conditions', 'medications', 'special_instructions',
            'concussion_history', 'physician_name', 'physician_phone', 'physician_address',
            'insurance_provider', 'insurance_policy_number', 'insurance_group_number',
            'blood_type', 'inhaler_location', 'epipen_location',
        ];
    }

    /** Encrypt every listed key present in $row. */
    public static function encryptFields(array $row, array $fields): array
    {
        foreach ($fields as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = self::encrypt($row[$f]);
            }
        }
        return $row;
    }

    /** Decrypt every listed key present in $row. */
    public static function decryptFields(array $row, array $fields): array
    {
        foreach ($fields as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = self::decrypt($row[$f]);
            }
        }
        return $row;
    }
}
