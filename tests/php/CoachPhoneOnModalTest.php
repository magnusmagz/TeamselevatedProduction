<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The coach modal shows and edits the coach's phone (Maggie, 2026-09-06).
 * Pins: the list SELECT returns `u.phone` so an existing number pre-fills the
 * form; the update writes `phone`; and it is written NORMALIZED through
 * te_normalize_sms_phone (the one implementation), so the SMS paths can trust
 * users.phone the way they trust guardians.mobile_phone.
 */
class CoachPhoneOnModalTest extends TestCase
{
    private function gateway(): string
    {
        return file_get_contents(__DIR__ . '/../../legacy/coaches-gateway.php');
    }

    public function testListReturnsPhoneSoTheModalCanPrefillIt(): void
    {
        $this->assertMatchesRegularExpression('/SELECT u\.id, u\.first_name, u\.last_name, u\.email, u\.phone,/', $this->gateway());
    }

    public function testUpdateWritesPhoneAndNormalizesIt(): void
    {
        $src = $this->gateway();
        $this->assertMatchesRegularExpression('/UPDATE users\s+SET first_name = \?,\s+last_name = \?,\s+email = \?,\s+phone = \?\s+WHERE id = \?/', $src);
        $upd = strpos($src, 'phone = ?');
        $norm = strrpos(substr($src, 0, $upd), 'te_normalize_sms_phone(');
        $this->assertNotFalse($norm, 'phone must pass through te_normalize_sms_phone before the UPDATE');
        $this->assertStringContainsString("'field' => 'phone'", $src, 'an unreadable number is a 422, not a stored string');
    }

    public function testTheNormalizerAcceptsTheFormatsAnAdminTypes(): void
    {
        require_once __DIR__ . '/../../lib/suppression.php';
        $this->assertSame('+13165550100', te_normalize_sms_phone('316-555-0100'));
        $this->assertSame('+13165550100', te_normalize_sms_phone('(316) 555-0100'));
        $this->assertSame('+13165550100', te_normalize_sms_phone('+1 316 555 0100'));
        $this->assertNull(te_normalize_sms_phone('call me'));
    }
}
