<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/** Decision 10 (2026-09-02): a tryout evaluation is attributed to the signed-in coach, never a body field. */
class EvaluatorFromTokenTest extends TestCase
{
    public function testEvaluateNeverReadsEvaluatorIdFromTheBody(): void
    {
        $src = file_get_contents(__DIR__ . '/../../registration/tryouts-api.php');
        $start = strpos($src, "case 'evaluate':");
        $end = strpos($src, "case '", $start + 10);
        $body = substr($src, $start, $end - $start);
        $this->assertStringNotContainsString("\$data['evaluator_id']", $body);
        $this->assertStringContainsString('$auth->getUserId()', $body);
    }

    public function testVenuesPhpPutIsRefused(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/venues.php');
        $start = strpos($src, "case 'PUT':");
        $end = strpos($src, "case 'DELETE':", $start);
        $body = substr($src, $start, $end - $start);
        $this->assertStringContainsString('http_response_code(405)', $body);
        $this->assertStringNotContainsString('UPDATE venues', $body);
    }
}
