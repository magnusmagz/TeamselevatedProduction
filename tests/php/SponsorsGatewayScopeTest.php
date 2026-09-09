<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * api/sponsors.php had no authentication at all until 2026-09-09: GET returned
 * `SELECT *` (sponsor contact name / email / phone) to anyone who guessed a
 * club_id, and POST / PUT / DELETE were open to the world.
 *
 * Parse-based, like RegistrationWriteScopeTest: the handlers are inline `case`
 * blocks in one switch, and the property that matters is which gate each
 * block calls before its SQL.
 */
class SponsorsGatewayScopeTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../api/sponsors.php');
    }

    private function block(string $case): string
    {
        $start = strpos($this->src, "case '$case':");
        $this->assertNotFalse($start, "no case '$case'");
        $next = preg_match('/\n        case \'|\n        default:/', $this->src, $m, PREG_OFFSET_CAPTURE, $start + 10)
            ? $m[0][1] : strlen($this->src);
        return substr($this->src, $start, $next - $start);
    }

    public function testAnonymousGetIsThePublicProjectionNeverSelectStar(): void
    {
        $get = $this->block('GET');
        $this->assertStringNotContainsString('SELECT * FROM sponsors', $get);
        $this->assertStringContainsString('TE_SPONSOR_PUBLIC_COLUMNS', $get);
        $this->assertStringContainsString('te_is_club_admin($auth', $get, 'the full row needs a club admin of THAT club');
        $public = $this->publicColumns();
        foreach (['contact_name', 'contact_email', 'contact_phone'] as $col) {
            $this->assertStringNotContainsString($col, $public, "$col is not public");
        }
    }

    private function publicColumns(): string
    {
        preg_match("/const TE_SPONSOR_PUBLIC_COLUMNS = '([^;]+);/s", $this->src, $m);
        $this->assertNotEmpty($m, 'TE_SPONSOR_PUBLIC_COLUMNS is defined');
        return $m[1];
    }

    public function testIncludeInactiveIsAdminOnly(): void
    {
        $get = $this->block('GET');
        $this->assertMatchesRegularExpression('/\$includeInactive = \$full &&/', $get);
    }

    /** @dataProvider writeCases */
    public function testEveryWriteRequiresAClubAdminOfTheSponsorsClubBeforeItsSql(string $case, string $sqlMarker): void
    {
        $block = $this->block($case);
        $gate = strpos($block, 'te_sponsors_require_admin(');
        $sql = strpos($block, $sqlMarker);
        $this->assertNotFalse($gate, "$case does not call te_sponsors_require_admin");
        $this->assertNotFalse($sql, "$case has no $sqlMarker");
        $this->assertLessThan($sql, $gate, "$case must gate BEFORE $sqlMarker");
        $this->assertStringNotContainsString("\$data['created_by']", $block, 'attribution comes from the token, never the body');
        $this->assertStringNotContainsString("\$data['updated_by']", $block);
        $this->assertStringNotContainsString("\$data['deleted_by']", $block);
    }

    public static function writeCases(): array
    {
        return [
            'POST'   => ['POST', 'INSERT INTO sponsors'],
            'PUT'    => ['PUT', 'UPDATE sponsors'],
            'DELETE' => ['DELETE', 'UPDATE sponsors'],
        ];
    }

    public function testTheGateRefusesANullClubAndANullAuth(): void
    {
        $fn = substr($this->src, strpos($this->src, 'function te_sponsors_require_admin('));
        $fn = substr($fn, 0, strpos($fn, "\n}\n") + 3);
        $this->assertStringContainsString('$auth === null', $fn);
        $this->assertStringContainsString('$clubId === null || !te_is_club_admin($auth, $clubId)', $fn);
        $this->assertStringContainsString('exit()', $fn);
    }

    public function testReorderRefusesAMixedClubList(): void
    {
        $put = $this->block('PUT');
        $this->assertStringContainsString('count($clubs) !== 1', $put);
    }
}
