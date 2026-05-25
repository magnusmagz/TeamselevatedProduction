<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use JWT;

/**
 * Unit tests for COACH-37: coach profile edit + image upload.
 *
 * Two procedural gateways are involved (api/upload.php, api/coach-profile.php);
 * both emit headers / exit() on include, so they can't be required in a unit
 * test. These tests exercise the two pieces of logic the fix depends on:
 *
 *   1. JWT payload-shape handling — the root cause. JWT::verify()/decode()
 *      return a stdClass payload, so the user_id must be read with object
 *      syntax. The old code used array access ($payload['user_id']) which
 *      yielded null against the object and broke the upload. readUserId()
 *      mirrors the fixed accessor (object OR array) used in both gateways.
 *   2. Profile update field mapping — replicates the coach-profile.php PUT
 *      field building + UPDATE against SQLite, asserting name / phone /
 *      coaching_background / profile_image_url all persist.
 */
class CoachProfileUploadTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                first_name TEXT,
                last_name TEXT,
                email TEXT,
                phone TEXT,
                profile_image_url TEXT,
                coaching_background TEXT
            );
        ");
        $this->pdo->exec("INSERT INTO users (id, first_name, last_name, email, phone, profile_image_url, coaching_background)
            VALUES (50, 'Casey', 'Coach', 'casey@club.test', NULL, NULL, NULL)");
    }

    // ---- 1. JWT payload-shape handling (root cause) ----

    /**
     * Mirrors the fixed accessor in upload.php / coach-profile.php: read user_id
     * from either an object (verify()/decode() shape) or an array.
     */
    private function readUserId($payload)
    {
        return is_object($payload)
            ? ($payload->user_id ?? null)
            : ($payload['user_id'] ?? null);
    }

    public function testJwtDecodeReturnsObjectNotArray(): void
    {
        // Document the shape the gateways must handle: json_decode without
        // assoc => stdClass. The original bug read it as an array.
        putenv('JWT_ALGORITHM=HS256');
        putenv('JWT_SECRET=test-secret-for-unit-tests');
        $token = JWT::generate('50', 'casey@club.test', 'Casey Coach');

        $decoded = JWT::decode($token);
        $this->assertIsObject($decoded);
        $this->assertObjectHasProperty('user_id', $decoded);

        // The OLD code path read $payload['user_id'] — array access on a
        // stdClass throws a fatal Error in PHP 8+. That is the COACH-37 bug.
        $threw = false;
        try {
            $ignored = $decoded['user_id'];
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'array access on the stdClass payload must fail (the original bug)');

        // The FIXED accessor recovers the real user id.
        $this->assertSame('50', $this->readUserId($decoded));
    }

    public function testVerifyRoundTripYieldsUsableUserId(): void
    {
        putenv('JWT_ALGORITHM=HS256');
        putenv('JWT_SECRET=test-secret-for-unit-tests');
        $token = JWT::generate('50', 'casey@club.test', 'Casey Coach');

        $payload = JWT::verify($token);
        $this->assertNotFalse($payload, 'a freshly signed token must verify');
        $this->assertSame('50', $this->readUserId($payload));
    }

    public function testTamperedTokenFailsVerification(): void
    {
        putenv('JWT_ALGORITHM=HS256');
        putenv('JWT_SECRET=test-secret-for-unit-tests');
        $token = JWT::generate('50', 'casey@club.test', 'Casey Coach');

        // Flip the signature so verification fails — decode() would have
        // happily accepted this; verify() must reject it.
        $tampered = $token . 'x';
        $this->assertFalse(JWT::verify($tampered));
    }

    public function testReadUserIdHandlesArrayShapeToo(): void
    {
        $this->assertSame(50, $this->readUserId(['user_id' => 50]));
    }

    // ---- 2. Profile update field mapping (mirrors coach-profile.php PUT) ----

    private function applyProfileUpdate(int $coachId, array $data): int
    {
        $updateFields = [];
        $params = ['coach_id' => $coachId];

        if (isset($data['first_name'])) {
            $updateFields[] = "first_name = :first_name";
            $params['first_name'] = trim($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $updateFields[] = "last_name = :last_name";
            $params['last_name'] = trim($data['last_name']);
        }
        if (isset($data['email'])) {
            $updateFields[] = "email = :email";
            $params['email'] = trim($data['email']);
        }
        if (isset($data['phone'])) {
            $updateFields[] = "phone = :phone";
            $params['phone'] = $data['phone'] ? trim($data['phone']) : null;
        }
        if (isset($data['profile_image_url'])) {
            $updateFields[] = "profile_image_url = :profile_image_url";
            $params['profile_image_url'] = $data['profile_image_url'] ? trim($data['profile_image_url']) : null;
        }
        if (isset($data['coaching_background'])) {
            $updateFields[] = "coaching_background = :coaching_background";
            $params['coaching_background'] = $data['coaching_background'] ? trim($data['coaching_background']) : null;
        }

        if (empty($updateFields)) {
            return 0;
        }

        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :coach_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private function fetchUser(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testProfileFieldsAndImageAllPersist(): void
    {
        $affected = $this->applyProfileUpdate(50, [
            'first_name' => 'Casey',
            'last_name' => 'Coachson',
            'phone' => '(555) 123-4567',
            'coaching_background' => '10 years coaching U12 soccer.',
            'profile_image_url' => 'https://cdn.example.com/uploads/coach_profile/c.png',
        ]);
        $this->assertSame(1, $affected);

        $row = $this->fetchUser(50);
        $this->assertSame('Coachson', $row['last_name']);
        $this->assertSame('(555) 123-4567', $row['phone']);
        $this->assertSame('10 years coaching U12 soccer.', $row['coaching_background']);
        $this->assertSame('https://cdn.example.com/uploads/coach_profile/c.png', $row['profile_image_url']);
    }

    public function testImageUploadUrlPersistsIndependently(): void
    {
        // Simulates the two-step flow: upload returns a URL, profile save stores it.
        $url = 'https://cdn.example.com/uploads/coach_profile/upload_123.jpg';
        $this->applyProfileUpdate(50, ['profile_image_url' => $url]);
        $this->assertSame($url, $this->fetchUser(50)['profile_image_url']);
    }

    public function testBlankImageUrlClearsToNull(): void
    {
        $this->applyProfileUpdate(50, ['profile_image_url' => 'https://x/y.png']);
        $this->assertNotNull($this->fetchUser(50)['profile_image_url']);

        $this->applyProfileUpdate(50, ['profile_image_url' => '']);
        $this->assertNull($this->fetchUser(50)['profile_image_url']);
    }
}
