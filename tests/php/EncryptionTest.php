<?php

use PHPUnit\Framework\TestCase;

/**
 * Encryption (AES-256-GCM) for athlete health fields.
 *
 * The contract that matters: PHI round-trips, tampering is detected rather than
 * returned as garbage, legacy plaintext still reads, and a missing key fails the
 * WRITE loudly instead of silently persisting plaintext.
 */
class EncryptionTest extends TestCase
{
    /** A deterministic 32-byte key for tests. */
    private const TEST_KEY_RAW = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        $this->setKey(base64_encode(self::TEST_KEY_RAW));
    }

    protected function tearDown(): void
    {
        $this->setKey('');
    }

    /** Set the env var and clear Encryption's cached key. */
    private function setKey(string $b64): void
    {
        putenv('MEDICAL_ENCRYPTION_KEY=' . $b64);
        $_ENV['MEDICAL_ENCRYPTION_KEY'] = $b64;
        $ref = new ReflectionProperty('Encryption', 'key');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    public function testRoundTripsAValue(): void
    {
        $plain = 'Peanuts — life threatening. EpiPen in red pouch.';
        $cipher = Encryption::encrypt($plain);

        $this->assertNotSame($plain, $cipher);
        $this->assertStringNotContainsString('Peanuts', $cipher);
        $this->assertSame($plain, Encryption::decrypt($cipher));
    }

    public function testCiphertextIsMarkedAndDetectable(): void
    {
        $cipher = Encryption::encrypt('Amoxicillin');
        $this->assertStringStartsWith('enc:v1:', $cipher);
        $this->assertTrue(Encryption::isEncrypted($cipher));
        $this->assertFalse(Encryption::isEncrypted('Amoxicillin'));
    }

    public function testSameInputProducesDifferentCiphertext(): void
    {
        // Random IV per write: identical values must not be correlatable across rows.
        $a = Encryption::encrypt('O+');
        $b = Encryption::encrypt('O+');
        $this->assertNotSame($a, $b);
        $this->assertSame('O+', Encryption::decrypt($a));
        $this->assertSame('O+', Encryption::decrypt($b));
    }

    public function testTamperedCiphertextReturnsNullNotGarbage(): void
    {
        $cipher = Encryption::encrypt('Asthma; inhaler in front pocket');
        $blob = base64_decode(substr($cipher, strlen('enc:v1:')), true);
        $blob[strlen($blob) - 1] = chr(ord($blob[strlen($blob) - 1]) ^ 0xFF);
        $tampered = 'enc:v1:' . base64_encode($blob);

        $this->assertNull(Encryption::decrypt($tampered));
    }

    public function testWrongKeyReturnsNull(): void
    {
        $cipher = Encryption::encrypt('Type 1 diabetes');
        $this->setKey(base64_encode('ffffffffffffffffffffffffffffffff'));
        $this->assertNull(Encryption::decrypt($cipher));
    }

    public function testLegacyPlaintextReadsThrough(): void
    {
        // Rows written before encryption existed must keep working.
        $this->assertSame('legacy plaintext', Encryption::decrypt('legacy plaintext'));
        $this->assertNull(Encryption::decrypt(null));
    }

    public function testNullAndEmptyPassThroughUnchanged(): void
    {
        $this->assertNull(Encryption::encrypt(null));
        $this->assertSame('', Encryption::encrypt(''));
    }

    public function testDoesNotDoubleEncrypt(): void
    {
        $once = Encryption::encrypt('Penicillin');
        $twice = Encryption::encrypt($once);
        $this->assertSame($once, $twice);
        $this->assertSame('Penicillin', Encryption::decrypt($twice));
    }

    public function testMissingKeyThrowsOnWrite(): void
    {
        $this->setKey('');
        $this->expectException(RuntimeException::class);
        Encryption::encrypt('should never be stored in the clear');
    }

    public function testMalformedKeyThrowsOnWrite(): void
    {
        // Right shape, wrong length — must be treated as absent, not truncated.
        $this->setKey(base64_encode('too-short'));
        $this->assertFalse(Encryption::isAvailable());
        $this->expectException(RuntimeException::class);
        Encryption::encrypt('sensitive');
    }

    public function testFieldHelpersOnlyTouchListedKeys(): void
    {
        $row = [
            'athlete_id' => 42,
            'blood_type' => 'AB-',
            'allergy_severity' => 'severe',   // deliberately NOT encrypted
            'has_epipen' => true,
        ];
        $enc = Encryption::encryptFields($row, Encryption::athleteMedicalFields());

        $this->assertSame(42, $enc['athlete_id']);
        $this->assertSame('severe', $enc['allergy_severity'], 'logic fields must stay readable');
        $this->assertTrue($enc['has_epipen']);
        $this->assertTrue(Encryption::isEncrypted($enc['blood_type']));

        $dec = Encryption::decryptFields($enc, Encryption::athleteMedicalFields());
        $this->assertSame('AB-', $dec['blood_type']);
    }

    public function testAlertDrivingFieldsAreNotEncrypted(): void
    {
        // The GET builds alerts from these; encrypting them would break alerting
        // or leak ciphertext into an alert message.
        $fields = Encryption::athleteMedicalFields();
        foreach (['allergy_severity', 'has_epipen', 'has_asthma', 'physical_expiry_date', 'return_to_play_date'] as $f) {
            $this->assertNotContains($f, $fields, "$f must remain plaintext");
        }
    }
}
