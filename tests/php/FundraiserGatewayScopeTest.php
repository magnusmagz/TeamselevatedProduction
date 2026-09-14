<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * api/fundraiser-campaigns.php had no authentication at all until 2026-09-14:
 * anyone could create, edit, end or delete any club's campaign, and list /
 * stats returned a club's donor feed to anyone with a club_id. Donor PII in
 * campaign-donations.php was behind canAccessClub, which a parent satisfies.
 *
 * Parse-based, like SponsorsGatewayScopeTest: which gate each `case` block
 * calls before its SQL is the property. Also pins the webhook branch and the
 * removal of the raw-card demo path.
 */
class FundraiserGatewayScopeTest extends TestCase
{
    private string $campaigns;
    private string $donations;

    protected function setUp(): void
    {
        $this->campaigns = file_get_contents(__DIR__ . '/../../api/fundraiser-campaigns.php');
        $this->donations = file_get_contents(__DIR__ . '/../../api/campaign-donations.php');
    }

    private function block(string $src, string $case): string
    {
        $start = strpos($src, "case '$case':");
        $this->assertNotFalse($start, "no case '$case'");
        $next = preg_match('/\n        case \'|\n        default:/', $src, $m, PREG_OFFSET_CAPTURE, $start + 10)
            ? $m[0][1] : strlen($src);
        return substr($src, $start, $next - $start);
    }

    /** The gate must run BEFORE the first statement that reads or writes campaign data. */
    private function assertGatedBeforeSql(string $block, string $gate, string $sqlMarker, string $case): void
    {
        $gateAt = strpos($block, $gate);
        $sqlAt = strpos($block, $sqlMarker);
        $this->assertNotFalse($gateAt, "'$case' does not call $gate");
        $this->assertNotFalse($sqlAt, "'$case' has no '$sqlMarker'");
        $this->assertLessThan($sqlAt, $gateAt, "'$case' runs SQL before its gate");
    }

    public static function campaignCases(): array
    {
        return [
            ['list', 'fundraiser_requireFinancialAdmin((int) $clubId)', 'FROM fundraiser_campaigns'],
            ['stats', 'fundraiser_requireFinancialAdmin((int) $clubId)', 'FROM fundraiser_campaigns'],
            ['create', "fundraiser_requireFinancialAdmin((int) \$data['club_id'])", 'INSERT INTO fundraiser_campaigns'],
            ['update', 'fundraiser_requireFinancialAdmin(fundraiser_clubOf($db', 'UPDATE fundraiser_campaigns'],
            ['end', 'fundraiser_requireFinancialAdmin(fundraiser_clubOf($db', 'UPDATE fundraiser_campaigns'],
            ['delete', 'fundraiser_requireFinancialAdmin(fundraiser_clubOf($db', 'UPDATE fundraiser_campaigns'],
            ['post-update', 'fundraiser_requireFinancialAdmin(fundraiser_clubOf($db', 'INSERT INTO campaign_updates'],
        ];
    }

    /** @dataProvider campaignCases */
    public function testEveryStaffActionIsGatedOnFinancialAdminOfTheCampaignsClub(string $case, string $gate, string $sql): void
    {
        $this->assertGatedBeforeSql($this->block($this->campaigns, $case), $gate, $sql, $case);
    }

    public function testGetStaysPublicItRendersTheDonationPage(): void
    {
        $get = $this->block($this->campaigns, 'get');
        $this->assertStringNotContainsString('requireAuth', $get);
        $this->assertStringNotContainsString('fundraiser_requireFinancialAdmin', $get);
    }

    public function testThePredicateIsFinancialAdminNeverClubMembership(): void
    {
        foreach (['fundraiser-campaigns.php' => $this->campaigns, 'campaign-donations.php' => $this->donations] as $file => $src) {
            $this->assertStringNotContainsString('canAccessClub', $src, "$file: canAccessClub admits parents");
            $this->assertStringContainsString('te_is_financial_admin(', $src, "$file");
        }
    }

    public function testAttributionComesFromTheTokenNeverTheBody(): void
    {
        $this->assertStringNotContainsString("\$data['created_by']", $this->campaigns);
        $this->assertStringNotContainsString("\$data['deleted_by']", $this->campaigns);
        $this->assertStringContainsString('$auth->getUserId()', $this->campaigns);
    }

    public static function donationStaffCases(): array
    {
        return [
            ['list', 'campaignDonations_requireFinancialAdmin($db', 'donor_email'],
            ['export', 'campaignDonations_requireFinancialAdmin($db', 'FROM campaign_donations'],
            ['resend-receipt', 'campaignDonations_requireFinancialAdmin($db', 'sendDonationReceipt'],
        ];
    }

    /** @dataProvider donationStaffCases */
    public function testDonorPiiIsFinancialAdminOnly(string $case, string $gate, string $sql): void
    {
        $this->assertGatedBeforeSql($this->block($this->donations, $case), $gate, $sql, $case);
    }

    public function testTheBrowserNeverSendsACardNumberAgain(): void
    {
        $this->assertStringNotContainsString('PaymentProcessorFactory', $this->donations);
        $this->assertStringNotContainsString('card_number', $this->donations);
        $create = $this->block($this->donations, 'create');
        $this->assertStringContainsString('410', $create, 'the old demo path answers 410, it does not fake a receipt');
        $this->assertStringNotContainsString('INSERT INTO campaign_donations', $create);

        $checkout = $this->block($this->donations, 'checkout');
        $this->assertStringContainsString('CampaignDonationService', $checkout);
        $this->assertStringContainsString('createCheckout(', $checkout);
        $this->assertStringNotContainsString('INSERT INTO campaign_donations', $checkout, 'the row is written by the webhook, never the browser');

        $form = file_get_contents(__DIR__ . '/../../frontend/src/components/DonationForm.tsx');
        $this->assertStringNotContainsString('card_number', $form);
        $this->assertStringNotContainsString('cvv', strtolower($form));
        $this->assertStringContainsString('action=checkout', $form);
    }

    public function testTheWebhookRecordsDonationsBeforeTheInvoiceBranchAndMailsAfterCommit(): void
    {
        $hook = file_get_contents(__DIR__ . '/../../api/webhooks/stripe-connect.php');
        $branch = strpos($hook, 'CampaignDonationService::isCampaignSession($meta)');
        $invoice = strpos($hook, "explode(',', \$meta['invoice_ids']");
        $commit = strpos($hook, '$pdo->commit();');
        $mail = strpos($hook, 'sendDonationReceipt(');
        $this->assertNotFalse($branch);
        $this->assertLessThan($invoice, $branch, 'a donation session must not fall into the "no invoice_ids" log-and-drop');
        $this->assertLessThan($mail, $commit, 'the receipt is sent AFTER commit');
        $this->assertStringContainsString('$donationReceipt = null;', $hook);
    }
}
