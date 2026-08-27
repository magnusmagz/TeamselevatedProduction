<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The internal notification-health screen.
 *
 * Chat notifications bypass EmailSendService, which is where open and click
 * tracking live, so nothing about them appears in Email Reporting — and there is
 * no analytics on the site. Without this endpoint the only way to answer "are
 * notifications reaching anyone" is a database query.
 *
 * These are structural assertions on the gateway. The behaviour they protect is
 * WHO can reach it and that it cannot change anything: the numbers themselves are
 * plain aggregates, but a read-only platform-wide report that stopped being
 * super-admin-only would expose every club's activity to any signed-in user.
 */
class NotificationHealthTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../api/super-admin-gateway.php');
    }

    public function testTheActionExists(): void
    {
        $this->assertStringContainsString("case 'notification-health':", $this->src);
        $this->assertStringContainsString('function handleNotificationHealth', $this->src);
    }

    /**
     * The whole file is gated at the top, and that gate is what makes a
     * platform-wide report safe. If it ever moves or narrows, this report starts
     * leaking every club's activity to whoever is signed in.
     */
    public function testTheGatewayIsSuperAdminOnly(): void
    {
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$auth->isSuperAdmin\(\)\s*\)\s*\{/',
            $this->src,
            'super-admin-gateway must refuse anyone who is not a super admin.'
        );

        $gate = strpos($this->src, '$auth->isSuperAdmin()');
        $action = strpos($this->src, "case 'notification-health':");
        $this->assertNotFalse($gate);
        $this->assertNotFalse($action);
        $this->assertLessThan($action, $gate, 'The gate must run before any action dispatches.');
    }

    /**
     * Read-only. That is what makes it safe to hand to the whole internal team,
     * and it is one careless edit away from not being true.
     */
    public function testTheHandlerCannotChangeAnything(): void
    {
        $start = strpos($this->src, 'function handleNotificationHealth');
        $end = strpos($this->src, 'function handleGetStats');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $body = substr($this->src, $start, $end - $start);

        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'DROP ', 'ALTER '] as $write) {
            $this->assertStringNotContainsStringIgnoringCase(
                $write,
                $body,
                'The notification health report must stay read-only.'
            );
        }
    }

    /** The window is bounded, so a hand-edited URL cannot ask for an unbounded scan. */
    public function testTheDayRangeIsClamped(): void
    {
        $start = strpos($this->src, 'function handleNotificationHealth');
        $body = substr($this->src, $start, 1200);

        $this->assertStringContainsString('max(1, min(365', $body,
            'days must be clamped — an unbounded range is a free table scan from a query string.');
    }

    /**
     * Reach matters more than any rate. A low click rate on a channel nobody has
     * enabled says nothing, and without these numbers the screen invites exactly
     * that misreading.
     */
    public function testItReportsReachNotJustRates(): void
    {
        $start = strpos($this->src, 'function handleNotificationHealth');
        $end = strpos($this->src, 'function handleGetStats');
        $body = substr($this->src, $start, $end - $start);

        foreach (['push_devices', 'opted_out_email', 'muted_conversations'] as $key) {
            $this->assertStringContainsString($key, $body);
        }
    }

    /**
     * A worker that has gone quiet is indistinguishable from a quiet week unless
     * both timestamps are surfaced. That failure went unnoticed for weeks before
     * the reconnect fix.
     */
    public function testItSurfacesWhetherTheDispatcherIsAlive(): void
    {
        $start = strpos($this->src, 'function handleNotificationHealth');
        $end = strpos($this->src, 'function handleGetStats');
        $body = substr($this->src, $start, $end - $start);

        $this->assertStringContainsString('last_notification_at', $body);
        $this->assertStringContainsString('last_message_at', $body);
    }
}
