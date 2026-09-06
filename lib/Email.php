<?php
/**
 * Email Utility Class
 *
 * Handles sending emails for magic links, invitations, etc.
 * Supports both SendGrid and PHP mail() function.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/CalendarInvite.php';
require_once __DIR__ . '/email_sender.php';

class Email {
    private $provider;
    private $fromEmail;
    private $fromName;
    private $apiKey;

    public function __construct() {
        $this->provider = Env::get('EMAIL_PROVIDER', 'mail'); // 'sendgrid' or 'mail'
        // One address for every path — see lib/email_sender.php. The old default
        // here was noreply@teamselevated.com while production ran on
        // maggie@eyeinteams.com, so neither the code nor the config said what
        // families actually saw.
        $this->fromEmail = te_email_from_address();
        $this->fromName = te_email_from_name();
        $this->apiKey = Env::get('SENDGRID_API_KEY', '');
    }

    /**
     * Send subsequent mail as the club rather than as the platform.
     *
     * A parent should recognise "Central Kansas United" in their inbox. Call this
     * wherever a club is known — invites, consent, team invitations, receipts.
     * Where it genuinely is not (password reset or magic-link sign-in from the
     * login page, where the person may span several clubs) leave it alone and the
     * mail goes out as "Teams Elevated", which is the honest answer.
     *
     * @param PDO|null $pdo
     * @param int|null $clubId
     * @return $this  chainable: (new Email())->forClub($db, $clubId)->sendParentInvite(...)
     */
    public function forClub($pdo, $clubId) {
        $this->fromName = te_email_from_name($pdo, $clubId !== null ? (int) $clubId : null);
        return $this;
    }

    /**
     * Send magic link email
     *
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $magicLink The magic link URL
     * @return bool Success status
     */
    public function sendMagicLink($to, $name, $magicLink) {
        $subject = 'Your Teams Elevated Login Link';

        $htmlBody = $this->getMagicLinkTemplate($name, $magicLink);
        $textBody = "Hi $name,\n\n" .
                    "Click the link below to sign in to Teams Elevated:\n\n" .
                    "$magicLink\n\n" .
                    "This link expires in 15 minutes.\n\n" .
                    "If you didn't request this link, you can safely ignore this email.";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send password reset email
     *
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $resetLink The password reset URL
     * @return bool Success status
     */
    public function sendPasswordReset($to, $name, $resetLink) {
        $subject = 'Reset Your Teams Elevated Password';

        $htmlBody = $this->getPasswordResetTemplate($name, $resetLink);
        $textBody = "Hi $name,\n\n" .
                    "We received a request to reset your password for Teams Elevated.\n\n" .
                    "Click the link below to reset your password:\n\n" .
                    "$resetLink\n\n" .
                    "This link expires in 1 hour.\n\n" .
                    "If you didn't request this password reset, you can safely ignore this email. Your password will remain unchanged.";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send parent-portal invite email ("set your password" link)
     *
     * Mirrors sendPasswordReset: a branded email with a button linking to the
     * one-time set-password page. The link expires in 7 days.
     *
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $inviteLink The set-parent-password URL
     * @param string|null $athleteName Optional athlete name for context
     * @return bool Success status
     */
    public function sendParentInvite($to, $name, $inviteLink, $athleteName = null) {
        $subject = 'Set up your Teams Elevated parent account';

        $htmlBody = $this->getParentInviteTemplate($name, $inviteLink, $athleteName);

        $athleteLine = $athleteName
            ? "Your athlete $athleteName has been registered.\n\n"
            : '';
        $textBody = "Hi $name,\n\n" .
                    $athleteLine .
                    "You've been invited to set up your Teams Elevated parent account.\n\n" .
                    "Click the link below to set your password and access the parent portal:\n\n" .
                    "$inviteLink\n\n" .
                    "This link expires in 7 days and can only be used once. Once you've set your\n" .
                    "password, sign in with it instead of clicking this link again.\n\n" .
                    "If you weren't expecting this invitation, you can safely ignore this email.";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send the parental consent confirmation email.
     *
     * COPPA-COMPLIANCE.md specifies a double-opt-in on consent: the guardian
     * records consent in the app, then confirms via a link mailed to them, which
     * is what makes the consent verifiable rather than merely asserted. This
     * method was documented as deployed and was absent from the tree.
     *
     * @param string $to           Guardian email.
     * @param string $name         Guardian name.
     * @param string $athleteName  Athlete the consent covers.
     * @param string $confirmLink  Tokenised confirmation URL (48-hour expiry).
     * @return bool
     */
    public function sendConsentConfirmation($to, $name, $athleteName, $confirmLink) {
        $subject = 'Please confirm your consent — Teams Elevated';

        $safeName = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
        $safeAthlete = htmlspecialchars((string) $athleteName, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars((string) $confirmLink, ENT_QUOTES, 'UTF-8');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background-color:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;">
<tr><td align="center" style="padding:20px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">
    <tr><td style="background-color:#12443E;padding:18px 24px;">
      <div style="color:#ffffff;font-weight:800;font-size:16px;">Teams Elevated</div>
      <div style="color:#c3cdd6;font-size:12px;text-transform:uppercase;letter-spacing:.08em;margin-top:3px;">Confirm your consent</div>
    </td></tr>
    <tr><td style="padding:30px;">
      <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#333333;">Hi {$safeName},</p>
      <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#333333;">
        Thank you for providing consent for <strong>{$safeAthlete}</strong>. To complete it, please
        confirm using the button below. We ask for this second step so your consent is verifiable,
        as required for collecting information about a minor.
      </p>
      <p style="text-align:center;margin:26px 0;">
        <a href="{$safeLink}" style="background-color:#12443E;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:bold;display:inline-block;">Confirm consent</a>
      </p>
      <p style="margin:0 0 16px 0;font-size:14px;line-height:1.6;color:#555555;">
        This link expires in 48 hours. You can withdraw your consent at any time from the parent portal.
      </p>
      <p style="margin:0;font-size:13px;line-height:1.6;color:#777777;">
        If you did not provide this consent, you can safely ignore this email and nothing will be confirmed.
      </p>
    </td></tr>
    <tr><td style="background-color:#12443E;color:#ffffff;padding:20px;text-align:center;font-size:11px;">
      Powered by Teams Elevated
    </td></tr>
  </table>
</td></tr></table>
</body></html>
HTML;

        $textBody = "Hi $name,\n\n" .
                    "Thank you for providing consent for $athleteName. To complete it, please confirm " .
                    "using the link below. We ask for this second step so your consent is verifiable, " .
                    "as required for collecting information about a minor.\n\n" .
                    "$confirmLink\n\n" .
                    "This link expires in 48 hours. You can withdraw your consent at any time from the " .
                    "parent portal.\n\n" .
                    "If you did not provide this consent, you can safely ignore this email.";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Tell someone they have unread chat messages.
     *
     * ⚠️ **This email deliberately carries NO message text.** Sender names and a
     * count only, decided with Maggie 2026-08-25.
     *
     * Chat has archive and deliberately no delete: the only removal path is admin
     * moderation, which tombstones the message and writes audit_log. An email
     * cannot be recalled, so a digest containing the text would leave a moderated
     * message sitting in every recipient's inbox permanently — outside the
     * retention rules in lib/retention_plans.php and outside moderation's reach.
     * In a product carrying minors' communications that is the wrong trade for a
     * bit of convenience. The digest exists to get the person back into the app,
     * where all of that still applies.
     *
     * @param string   $to               Recipient email
     * @param string   $recipientName    Who we are writing to
     * @param string   $conversationLabel e.g. "U12 Blue" — the team or group name
     * @param string[] $senderNames      Distinct senders, already de-duplicated
     * @param int      $messageCount     How many unread messages this covers
     * @param string   $link             Where to open the conversation
     * @return bool Success status
     */
    public function sendChatDigest($to, $recipientName, $conversationLabel, array $senderNames, $messageCount, $link) {
        $count = max(1, (int) $messageCount);
        $plural = $count === 1 ? 'message' : 'messages';

        $safeName = htmlspecialchars((string) $recipientName, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars((string) $conversationLabel, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars((string) $link, ENT_QUOTES, 'UTF-8');

        $fromWhom = $this->describeSenders($senderNames);
        $safeFromWhom = htmlspecialchars($fromWhom, ENT_QUOTES, 'UTF-8');

        $subject = $count === 1
            ? "New message in {$conversationLabel}"
            : "{$count} new messages in {$conversationLabel}";

        $line = $fromWhom === ''
            ? "You have {$count} new {$plural}."
            : "You have {$count} new {$plural} from {$fromWhom}.";
        $safeLine = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background-color:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;">
<tr><td align="center" style="padding:20px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">
    <tr><td style="background-color:#12443E;padding:18px 24px;">
      <div style="color:#ffffff;font-weight:800;font-size:16px;">{$safeLabel}</div>
      <div style="color:#c3cdd6;font-size:12px;text-transform:uppercase;letter-spacing:.08em;margin-top:3px;">New messages</div>
    </td></tr>
    <tr><td style="padding:30px;">
      <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#333333;">Hi {$safeName},</p>
      <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#333333;">{$safeLine}</p>
      <p style="text-align:center;margin:26px 0;">
        <a href="{$safeLink}" style="background-color:#12443E;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:bold;display:inline-block;"><span style="color:#ffffff;">Open chat</span></a>
      </p>
      <p style="margin:0;font-size:13px;line-height:1.6;color:#777777;">
        You are getting this because you are on this team. You can mute this conversation,
        or turn these emails off, from the app at any time.
      </p>
    </td></tr>
    <tr><td style="background-color:#12443E;color:#ffffff;padding:20px;text-align:center;font-size:11px;">
      Powered by Teams Elevated
    </td></tr>
  </table>
</td></tr></table>
</body></html>
HTML;

        $textBody = "Hi {$recipientName},\n\n" .
                    "{$line}\n\n" .
                    "Open chat: {$link}\n\n" .
                    "You are getting this because you are on this team. You can mute this " .
                    "conversation, or turn these emails off, from the app at any time.";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * "Cora Coach", "Cora Coach and Pat Parent", "Cora Coach and 3 others".
     *
     * Listing every sender of a busy team chat would put a dozen names in one
     * sentence, so it caps at two and counts the rest.
     */
    private function describeSenders(array $names) {
        $clean = [];
        foreach ($names as $n) {
            $n = trim((string) $n);
            if ($n !== '' && !in_array($n, $clean, true)) {
                $clean[] = $n;
            }
        }

        $total = count($clean);
        if ($total === 0) {
            return '';
        }
        if ($total === 1) {
            return $clean[0];
        }
        if ($total === 2) {
            return $clean[0] . ' and ' . $clean[1];
        }
        return $clean[0] . ' and ' . ($total - 1) . ' others';
    }

    /**
     * Tell a club admin that chat needs their attention.
     *
     * Two kinds, per docs/chat-moderation-plan.md:328 — an individual alert for
     * a high-severity flag, and a routine digest of what is still open.
     *
     * ⚠️ **Carries no message text and no names**, for a stronger version of the
     * reason sendChatDigest() does not: this is content that has been FLAGGED,
     * possibly for hate speech or an attempt to move a child off-platform.
     * Copying it into several admins' inboxes spreads the material, survives the
     * moderation removal that may follow, and puts it outside the retention
     * rules and the access log that make admin review accountable. The alert's
     * job is to get an admin to the review screen, where reading it is gated and
     * recorded.
     *
     * @param string $to            Recipient email
     * @param string $recipientName Who we are writing to
     * @param string $kind          'high_severity' | 'digest'
     * @param array  $detail        high_severity: rule, source. digest: open_total, open_high
     * @param string $link          The Reported Messages screen
     * @return bool Success status
     */
    public function sendModerationAlert($to, $recipientName, $kind, array $detail, $link) {
        $safeName = htmlspecialchars((string) $recipientName, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars((string) $link, ENT_QUOTES, 'UTF-8');

        if ($kind === 'high_severity') {
            $subject = 'A chat message needs review';
            $heading = 'Flagged for review';
            $reason = $this->describeFlagRule($detail['rule'] ?? '');
            $bySystem = (($detail['source'] ?? '') === 'auto');

            $lead = $bySystem
                ? "A message in your club's chat was automatically flagged ({$reason}) and is waiting for review."
                : "A member of your club reported a chat message ({$reason}). It is waiting for review.";

            $tail = 'The message is not shown here on purpose. Opening it in the app keeps the review '
                  . 'gated and recorded, which is what makes it defensible later.';
        } else {
            $openTotal = max(0, (int) ($detail['open_total'] ?? 0));
            $openHigh = max(0, (int) ($detail['open_high'] ?? 0));
            $plural = $openTotal === 1 ? 'report is' : 'reports are';

            $subject = $openTotal === 1
                ? '1 chat report is waiting for review'
                : "{$openTotal} chat reports are waiting for review";
            $heading = 'Still waiting for review';

            $lead = "{$openTotal} chat {$plural} still open in your club.";
            if ($openHigh > 0) {
                $lead .= " {$openHigh} of them "
                       . ($openHigh === 1 ? 'is' : 'are')
                       . ' high severity.';
            }

            $tail = 'You are getting this weekly because reports stay open until someone reviews them.';
        }

        $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $safeLead = htmlspecialchars($lead, ENT_QUOTES, 'UTF-8');
        $safeTail = htmlspecialchars($tail, ENT_QUOTES, 'UTF-8');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background-color:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;">
<tr><td align="center" style="padding:20px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">
    <tr><td style="background-color:#12443E;padding:18px 24px;">
      <div style="color:#ffffff;font-weight:800;font-size:16px;">Teams Elevated</div>
      <div style="color:#c3cdd6;font-size:12px;text-transform:uppercase;letter-spacing:.08em;margin-top:3px;">{$safeHeading}</div>
    </td></tr>
    <tr><td style="padding:30px;">
      <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#333333;">Hi {$safeName},</p>
      <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#333333;">{$safeLead}</p>
      <p style="text-align:center;margin:26px 0;">
        <a href="{$safeLink}" style="background-color:#12443E;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:bold;display:inline-block;"><span style="color:#ffffff;">Review reported messages</span></a>
      </p>
      <p style="margin:0;font-size:13px;line-height:1.6;color:#777777;">{$safeTail}</p>
    </td></tr>
    <tr><td style="background-color:#12443E;color:#ffffff;padding:20px;text-align:center;font-size:11px;">
      Powered by Teams Elevated
    </td></tr>
  </table>
</td></tr></table>
</body></html>
HTML;

        $textBody = "Hi {$recipientName},\n\n{$lead}\n\nReview reported messages: {$link}\n\n{$tail}";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Plain-English name for an auto-flag rule.
     *
     * Deliberately vague on the specifics — an admin needs to know it is worth
     * opening, not to be able to reconstruct the message from the subject line.
     */
    private function describeFlagRule($rule) {
        $map = [
            'hate_speech'          => 'hateful language',
            'secrecy'              => 'asking a member to keep something secret',
            'off_platform_contact' => 'moving the conversation off the platform',
            'profanity'            => 'strong language',
            'external_app'         => 'pointing to an outside app',
        ];
        $key = (string) $rule;
        return $map[$key] ?? 'flagged content';
    }

    /**
     * Send team invitation email
     *
     * @param string $to Recipient email
     * @param string $teamName Team name
     * @param string $invitedBy Name of person who invited
     * @param string $invitationLink The invitation link
     * @param string $personalMessage Optional personal message
     * @return bool Success status
     */
    public function sendTeamInvitation($to, $teamName, $invitedBy, $invitationLink, $personalMessage = '') {
        $subject = "You're invited to join $teamName";

        $htmlBody = $this->getTeamInvitationTemplate($teamName, $invitedBy, $invitationLink, $personalMessage);
        $textBody = "Hi,\n\n" .
                    "$invitedBy has invited you to join $teamName on Teams Elevated.\n\n" .
                    ($personalMessage ? "$personalMessage\n\n" : '') .
                    "Click the link below to accept your invitation:\n\n" .
                    "$invitationLink\n\n" .
                    "This invitation expires in 90 days.";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send calendar invitation
     *
     * @param array $event Event details with keys:
     *   - summary: Event title
     *   - startDateTime: Start date/time
     *   - endDateTime: End date/time
     *   - location: Event location (optional)
     *   - description: Event description (optional)
     *   - status: 'scheduled', 'cancelled', 'postponed'
     *   - organizerName: Name of organizer
     *   - organizerEmail: Email of organizer
     *   - attendees: Array of attendee objects with 'name' and 'email'
     * @param string $action 'invite' | 'update' | 'cancel'
     * @return bool Success status
     */
    public function sendCalendarInvite($event, $action = 'invite') {
        // Generate the iCalendar content
        if ($action === 'cancel') {
            $icsContent = CalendarInvite::generateCancellation($event);
        } elseif ($action === 'update') {
            $icsContent = CalendarInvite::generateUpdate($event);
        } else {
            $icsContent = CalendarInvite::generate($event);
        }

        // Build email subject
        $subject = $event['summary'];
        if ($action === 'cancel') {
            $subject = 'CANCELLED: ' . $subject;
        } elseif ($action === 'update') {
            $subject = 'UPDATED: ' . $subject;
        }

        // Send to each attendee with personalized RSVP links
        $allSent = true;
        if (!empty($event['attendees']) && is_array($event['attendees'])) {
            foreach ($event['attendees'] as $attendee) {
                if (!empty($attendee['email'])) {
                    // Build email body with RSVP token (personalized for each attendee)
                    $rsvpToken = $attendee['rsvp_token'] ?? null;
                    $htmlBody = $this->getCalendarInviteTemplate($event, $action, $rsvpToken);
                    $textBody = $this->getCalendarInviteText($event, $action, $rsvpToken);

                    $sent = $this->sendWithCalendar(
                        $attendee['email'],
                        $subject,
                        $htmlBody,
                        $textBody,
                        $icsContent
                    );
                    $allSent = $allSent && $sent;
                }
            }
        }

        return $allSent;
    }

    /**
     * Send email (main method)
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $htmlBody HTML body
     * @param string $textBody Plain text body
     * @return bool Success status
     */
    private function send($to, $subject, $htmlBody, $textBody) {
        if ($this->provider === 'sendgrid' && !empty($this->apiKey)) {
            return $this->sendViaSendGrid($to, $subject, $htmlBody, $textBody);
        } else {
            return $this->sendViaPHPMail($to, $subject, $htmlBody, $textBody);
        }
    }

    /**
     * Send email via SendGrid API
     */
    private function sendViaSendGrid($to, $subject, $htmlBody, $textBody) {
        $payload = [
            'personalizations' => [
                [
                    'to' => [['email' => $to]],
                    'subject' => $subject
                ]
            ],
            'from' => [
                'email' => $this->fromEmail,
                'name' => $this->fromName
            ],
            'content' => [
                ['type' => 'text/plain', 'value' => $textBody],
                ['type' => 'text/html', 'value' => $htmlBody]
            ]
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("Email sent successfully to $to via SendGrid");
            return true;
        } else {
            error_log("SendGrid API error ($httpCode): $response");
            return false;
        }
    }

    /**
     * Send email via PHP mail() function
     */
    private function sendViaPHPMail($to, $subject, $htmlBody, $textBody) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . $this->fromEmail,
            'X-Mailer: PHP/' . phpversion()
        ];

        $sent = mail($to, $subject, $htmlBody, implode("\r\n", $headers));

        if ($sent) {
            error_log("Email sent successfully to $to via PHP mail()");
        } else {
            error_log("Failed to send email to $to via PHP mail()");
        }

        return $sent;
    }

    /**
     * Magic link email template
     */
    private function getMagicLinkTemplate($name, $magicLink) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #12443E 0%, #12443E 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .button span { color: #ffffff !important; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Teams Elevated</h1>
        </div>
        <div class="content">
            <h2>Hi {$name},</h2>
            <p>Click the button below to sign in to Teams Elevated:</p>
            <p style="text-align: center;">
                <a href="{$magicLink}" class="button" style="display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0;"><span style="color: #ffffff !important; text-decoration: none;">Sign In to Teams Elevated</span></a>
            </p>
            <p style="color: #666; font-size: 14px;">
                This link expires in 15 minutes. If you didn't request this link, you can safely ignore this email.
            </p>
            <p style="color: #999; font-size: 12px; word-break: break-all;">
                Or copy and paste this link: {$magicLink}
            </p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Password reset email template
     */
    private function getPasswordResetTemplate($name, $resetLink) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #12443E 0%, #12443E 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .button span { color: #ffffff !important; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Teams Elevated</h1>
        </div>
        <div class="content">
            <h2>Reset Your Password</h2>
            <p>Hi {$name},</p>
            <p>We received a request to reset your password for your Teams Elevated account.</p>
            <p style="text-align: center;">
                <a href="{$resetLink}" class="button" style="display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0;"><span style="color: #ffffff !important; text-decoration: none;">Reset Password</span></a>
            </p>
            <div class="warning">
                <strong>This link expires in 1 hour.</strong> If you didn't request this password reset, you can safely ignore this email.
            </div>
            <p style="color: #999; font-size: 12px; word-break: break-all;">
                Or copy and paste this link: {$resetLink}
            </p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Parent-portal invite email template
     */
    private function getParentInviteTemplate($name, $inviteLink, $athleteName = null) {
        $athleteHtml = $athleteName
            ? "<p>Your athlete <strong>{$athleteName}</strong> has been registered.</p>"
            : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #12443E 0%, #12443E 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .button span { color: #ffffff !important; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Teams Elevated</h1>
        </div>
        <div class="content">
            <h2>Set Up Your Parent Account</h2>
            <p>Hi {$name},</p>
            {$athleteHtml}
            <p>You've been invited to set up your Teams Elevated parent account. Click the button below to choose a password and access the parent portal.</p>
            <p style="text-align: center;">
                <a href="{$inviteLink}" class="button" style="display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0;"><span style="color: #ffffff !important; text-decoration: none;">Set Up My Account</span></a>
            </p>
            <div class="warning">
                <strong>This link expires in 7 days and can only be used once.</strong> Once you've set your password, sign in with it instead of clicking this link again. If you weren't expecting this invitation, you can safely ignore this email.
            </div>
            <p style="color: #999; font-size: 12px; word-break: break-all;">
                Or copy and paste this link: {$inviteLink}
            </p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Team invitation email template
     */
    private function getTeamInvitationTemplate($teamName, $invitedBy, $invitationLink, $personalMessage) {
        $messageHtml = $personalMessage ? "<p style='background: #fff; padding: 15px; border-left: 4px solid #12443E; margin: 20px 0;'><em>\"$personalMessage\"</em></p>" : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #12443E 0%, #12443E 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .button span { color: #ffffff !important; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>You're Invited!</h1>
        </div>
        <div class="content">
            <h2>Join {$teamName}</h2>
            <p><strong>{$invitedBy}</strong> has invited you to join <strong>{$teamName}</strong> on Teams Elevated.</p>
            {$messageHtml}
            <p style="text-align: center;">
                <a href="{$invitationLink}" class="button" style="display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0;"><span style="color: #ffffff !important; text-decoration: none;">Accept Invitation</span></a>
            </p>
            <p style="color: #666; font-size: 14px;">
                This invitation is valid for 90 days.
            </p>
            <p style="color: #999; font-size: 12px; word-break: break-all;">
                Or copy and paste this link: {$invitationLink}
            </p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Send email with calendar attachment
     */
    private function sendWithCalendar($to, $subject, $htmlBody, $textBody, $icsContent) {
        if ($this->provider === 'sendgrid' && !empty($this->apiKey)) {
            return $this->sendCalendarViaSendGrid($to, $subject, $htmlBody, $textBody, $icsContent);
        } else {
            return $this->sendCalendarViaPHPMail($to, $subject, $htmlBody, $textBody, $icsContent);
        }
    }

    /**
     * Send calendar invite via SendGrid
     */
    private function sendCalendarViaSendGrid($to, $subject, $htmlBody, $textBody, $icsContent) {
        // Encode the .ics content as base64 for attachment
        $icsBase64 = base64_encode($icsContent);

        $payload = [
            'personalizations' => [
                [
                    'to' => [['email' => $to]],
                    'subject' => $subject
                ]
            ],
            'from' => [
                'email' => $this->fromEmail,
                'name' => $this->fromName
            ],
            'content' => [
                ['type' => 'text/plain', 'value' => $textBody],
                ['type' => 'text/html', 'value' => $htmlBody],
                ['type' => 'text/calendar; method=REQUEST', 'value' => $icsContent]
            ],
            'attachments' => [
                [
                    'content' => $icsBase64,
                    'type' => 'text/calendar; method=REQUEST',
                    'filename' => 'invite.ics',
                    'disposition' => 'attachment'
                ]
            ]
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("Calendar invite sent successfully to $to via SendGrid");
            return true;
        } else {
            error_log("SendGrid calendar invite error ($httpCode): $response");
            return false;
        }
    }

    /**
     * Send calendar invite via PHP mail()
     */
    private function sendCalendarViaPHPMail($to, $subject, $htmlBody, $textBody, $icsContent) {
        $boundary = md5(uniqid(time()));

        $headers = [
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . $this->fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'X-Mailer: PHP/' . phpversion()
        ];

        $message = "--$boundary\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $message .= $textBody . "\r\n\r\n";

        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";

        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/calendar; method=REQUEST; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $message .= $icsContent . "\r\n\r\n";

        $message .= "--$boundary--\r\n";

        $sent = mail($to, $subject, $message, implode("\r\n", $headers));

        if ($sent) {
            error_log("Calendar invite sent successfully to $to via PHP mail()");
        } else {
            error_log("Failed to send calendar invite to $to via PHP mail()");
        }

        return $sent;
    }

    /**
     * Calendar invite email template
     */
    private function getCalendarInviteTemplate($event, $action, $rsvpToken = null) {
        $title = $event['summary'];
        $location = $event['location'] ?? 'TBD';
        $description = $event['description'] ?? '';
        $startDateTime = new DateTime($event['startDateTime']);
        $endDateTime = new DateTime($event['endDateTime']);

        $dateFormatted = $startDateTime->format('l, F j, Y');
        $timeFormatted = $startDateTime->format('g:i A') . ' - ' . $endDateTime->format('g:i A');

        $actionMessage = '';
        if ($action === 'cancel') {
            $actionMessage = '<div style="background: #fee; border-left: 4px solid #c00; padding: 15px; margin: 20px 0;"><strong>This event has been cancelled.</strong></div>';
        } elseif ($action === 'update') {
            $actionMessage = '<div style="background: #fef9e7; border-left: 4px solid #f39c12; padding: 15px; margin: 20px 0;"><strong>This event has been updated. Please check the details below.</strong></div>';
        }

        $descriptionHtml = '';
        if ($description) {
            $descriptionHtml = "<div class='detail-row'><span class='detail-label'>📝 Details:</span><br>{$description}</div>";
        }

        // Build RSVP buttons if token is provided and action is not cancel
        $rsvpButtons = '';
        if ($rsvpToken && $action !== 'cancel') {
            $apiUrl = getenv('API_URL') ?: 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
            $acceptUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=accepted';
            $declineUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=declined';
            $tentativeUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=tentative';

            $rsvpButtons = <<<RSVP
            <div style="text-align: center; margin: 30px 0;">
                <p style="font-weight: bold; margin-bottom: 15px;">Will you attend?</p>
                <a href="{$acceptUrl}" style="display: inline-block; background: #28a745; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 5px;">✓ Yes</a>
                <a href="{$tentativeUrl}" style="display: inline-block; background: #ffc107; color: #333; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 5px;">? Maybe</a>
                <a href="{$declineUrl}" style="display: inline-block; background: #dc3545; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 5px;">✗ No</a>
            </div>
RSVP;
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #12443E 0%, #12443E 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .event-details { background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #12443E; }
        .event-details h3 { margin-top: 0; color: #12443E; }
        .detail-row { padding: 10px 0; border-bottom: 1px solid #eee; }
        .detail-label { font-weight: bold; color: #666; }
        .button { display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        .button span { color: #ffffff !important; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Calendar Invitation</h1>
        </div>
        <div class="content">
            {$actionMessage}
            <div class="event-details">
                <h3>{$title}</h3>
                <div class="detail-row">
                    <span class="detail-label">📅 Date:</span> {$dateFormatted}
                </div>
                <div class="detail-row">
                    <span class="detail-label">🕐 Time:</span> {$timeFormatted}
                </div>
                <div class="detail-row">
                    <span class="detail-label">📍 Location:</span> {$location}
                </div>
                {$descriptionHtml}
            </div>
            {$rsvpButtons}
            <p style="text-align: center; margin-top: 30px; color: #666;">
                <em>This event has been added to your calendar. You can also respond directly in your calendar application.</em>
            </p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Calendar invite plain text
     */
    private function getCalendarInviteText($event, $action, $rsvpToken = null) {
        $title = $event['summary'];
        $location = $event['location'] ?? 'TBD';
        $description = $event['description'] ?? '';
        $startDateTime = new DateTime($event['startDateTime']);
        $endDateTime = new DateTime($event['endDateTime']);

        $dateFormatted = $startDateTime->format('l, F j, Y');
        $timeFormatted = $startDateTime->format('g:i A') . ' - ' . $endDateTime->format('g:i A');

        $actionMessage = '';
        if ($action === 'cancel') {
            $actionMessage = "*** THIS EVENT HAS BEEN CANCELLED ***\n\n";
        } elseif ($action === 'update') {
            $actionMessage = "*** THIS EVENT HAS BEEN UPDATED ***\n\n";
        }

        $text = "CALENDAR INVITATION\n\n";
        $text .= $actionMessage;
        $text .= "Event: {$title}\n";
        $text .= "Date: {$dateFormatted}\n";
        $text .= "Time: {$timeFormatted}\n";
        $text .= "Location: {$location}\n";
        if ($description) {
            $text .= "Details: {$description}\n";
        }

        // Add RSVP links if token is provided and action is not cancel
        if ($rsvpToken && $action !== 'cancel') {
            $apiUrl = getenv('API_URL') ?: 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
            $acceptUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=accepted';
            $declineUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=declined';
            $tentativeUrl = $apiUrl . '/api/rsvp-webhook.php?action=respond&token=' . $rsvpToken . '&response=tentative';

            $text .= "\n---\nWILL YOU ATTEND?\n\n";
            $text .= "Yes, I'll be there: {$acceptUrl}\n";
            $text .= "Maybe: {$tentativeUrl}\n";
            $text .= "No, I can't attend: {$declineUrl}\n";
            $text .= "---\n";
        }

        $text .= "\nThis event has been added to your calendar.\n";
        $text .= "You can also respond directly in your calendar application.\n\n";
        $text .= "Teams Elevated\n";

        return $text;
    }

    /**
     * Send donation receipt email
     *
     * @param string $to Recipient email
     * @param string $donorName Donor's name
     * @param float $amount Donation amount
     * @param string $campaignTitle Campaign title
     * @param string $clubName Club name
     * @param int $donationId Donation ID for reference
     * @param string $transactionId Payment transaction ID
     * @return bool Success status
     */
    public function sendDonationReceipt($to, $donorName, $amount, $campaignTitle, $clubName, $donationId, $transactionId) {
        $subject = "Thank you for your donation to $campaignTitle";

        $amountFormatted = '$' . number_format($amount, 2);
        $date = date('F j, Y');

        $htmlBody = $this->getDonationReceiptTemplate($donorName, $amountFormatted, $campaignTitle, $clubName, $donationId, $transactionId, $date);
        $textBody = "Thank you for your donation!\n\n" .
                    "Dear $donorName,\n\n" .
                    "Thank you for your generous donation of $amountFormatted to $campaignTitle.\n\n" .
                    "Donation Details:\n" .
                    "- Amount: $amountFormatted\n" .
                    "- Campaign: $campaignTitle\n" .
                    "- Organization: $clubName\n" .
                    "- Date: $date\n" .
                    "- Reference #: DON-$donationId\n" .
                    "- Transaction ID: $transactionId\n\n" .
                    "Your support makes a real difference. Thank you for being part of our community!\n\n" .
                    "Best regards,\n" .
                    "$clubName\n" .
                    "via Teams Elevated";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Receipt for an online invoice payment (Stripe checkout path).
     * $invoiceNumbers is an array like ['INV-202607-00018'].
     */
    public function sendPaymentReceipt($to, $payerName, $amount, array $invoiceNumbers, $clubName, $transactionRef) {
        $amountFormatted = '$' . number_format($amount, 2);
        $invoiceList = implode(', ', $invoiceNumbers);
        $subject = "Payment received — $amountFormatted to $clubName";
        $date = date('F j, Y');

        $textBody = "Payment received — thank you!\n\n" .
                    "Dear $payerName,\n\n" .
                    "We received your payment of $amountFormatted to $clubName.\n\n" .
                    "Payment Details:\n" .
                    "- Amount: $amountFormatted\n" .
                    "- Applied to: $invoiceList\n" .
                    "- Date: $date\n" .
                    "- Reference: $transactionRef\n\n" .
                    "You can view your balance anytime in the parent portal under Payments.\n\n" .
                    "Best regards,\n$clubName\nvia Teams Elevated";

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #12443E; color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .receipt-box { background: white; border: 2px solid #12443E; border-radius: 8px; padding: 25px; margin: 20px 0; }
        .amount-display { font-size: 36px; color: #12443E; font-weight: bold; text-align: center; margin: 20px 0; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .detail-label { color: #666; }
        .detail-value { font-weight: 500; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Payment Received</h1>
            <p style="margin: 0; opacity: 0.9;">Thank you, {$payerName}</p>
        </div>
        <div class="content">
            <div class="receipt-box">
                <div class="amount-display">{$amountFormatted}</div>
                <div class="detail-row"><span class="detail-label">Applied to</span><span class="detail-value">{$invoiceList}</span></div>
                <div class="detail-row"><span class="detail-label">Paid to</span><span class="detail-value">{$clubName}</span></div>
                <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value">{$date}</span></div>
                <div class="detail-row"><span class="detail-label">Reference</span><span class="detail-value">{$transactionRef}</span></div>
            </div>
            <p>You can view your balance anytime in the parent portal under Payments.</p>
        </div>
        <div class="footer">{$clubName} · via Teams Elevated</div>
    </div>
</body>
</html>
HTML;

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Donation receipt email template
     */
    private function getDonationReceiptTemplate($donorName, $amount, $campaignTitle, $clubName, $donationId, $transactionId, $date) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #12443E 0%, #12443E 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .receipt-box { background: white; border: 2px solid #12443E; border-radius: 8px; padding: 25px; margin: 20px 0; }
        .receipt-header { text-align: center; border-bottom: 2px dashed #ddd; padding-bottom: 15px; margin-bottom: 15px; }
        .receipt-header h2 { color: #12443E; margin: 0; }
        .amount-display { font-size: 36px; color: #12443E; font-weight: bold; text-align: center; margin: 20px 0; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .detail-label { color: #666; }
        .detail-value { font-weight: 500; }
        .thank-you { background: #e8f5e9; border-left: 4px solid #4caf50; padding: 15px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Thank You!</h1>
            <p style="margin: 0; opacity: 0.9;">Your donation has been received</p>
        </div>
        <div class="content">
            <p>Dear {$donorName},</p>
            <p>Thank you for your generous donation to <strong>{$campaignTitle}</strong>. Your support means the world to us!</p>

            <div class="receipt-box">
                <div class="receipt-header">
                    <h2>Donation Receipt</h2>
                    <p style="color: #666; margin: 5px 0 0 0;">Keep this for your records</p>
                </div>

                <div class="amount-display">{$amount}</div>

                <div style="margin-top: 20px;">
                    <div class="detail-row">
                        <span class="detail-label">Campaign</span>
                        <span class="detail-value">{$campaignTitle}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Organization</span>
                        <span class="detail-value">{$clubName}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Date</span>
                        <span class="detail-value">{$date}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Reference #</span>
                        <span class="detail-value">DON-{$donationId}</span>
                    </div>
                    <div class="detail-row" style="border-bottom: none;">
                        <span class="detail-label">Transaction ID</span>
                        <span class="detail-value" style="font-family: monospace; font-size: 12px;">{$transactionId}</span>
                    </div>
                </div>
            </div>

            <div class="thank-you">
                <strong>Your support makes a real difference!</strong><br>
                Thank you for being part of our community and helping us reach our goals.
            </div>

            <p style="color: #666; font-size: 14px;">
                If you have any questions about your donation, please contact {$clubName} directly.
            </p>
        </div>
        <div class="footer">
            <p>This receipt was sent via Teams Elevated</p>
            <p>&copy; 2025 Teams Elevated. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Receipt for a recorded payment_transactions row (registration / program fees).
     *
     * NOTE the name. sendPaymentReceipt() above is the Stripe checkout path and takes
     * invoice numbers; this one receipts a transaction against an athlete's program
     * fee, so it names the athlete. A family with three children gets three receipts
     * and has to be able to tell them apart.
     *
     * Called by api/payment-receipt.php?action=email. Until 2026-09-02 that endpoint
     * only ever wrote "DEMO: Would send receipt email" to the error log and answered
     * success, so the button in PaymentReceipt.tsx did nothing at all.
     *
     * @return bool true only if the provider accepted the message.
     */
    public function sendPaymentTransactionReceipt(
        $to,
        $guardianName,
        $athleteName,
        $itemName,
        $programName,
        $amount,
        $transactionRef,
        $paidOn,
        $clubName
    ) {
        // No money in the subject: a receipt subject line renders on a lock screen
        // and in every notification preview, and what a family paid is nobody
        // else's business.
        $subject = 'Payment receipt for ' . $athleteName;

        $amountFormatted = '$' . number_format((float) $amount, 2);
        $date = $paidOn ? date('F j, Y', strtotime($paidOn)) : date('F j, Y');

        $rows = [
            'Athlete'   => $athleteName,
            'Item'      => $itemName,
            'Program'   => $programName,
            'Amount'    => $amountFormatted,
            'Date'      => $date,
            'Reference' => $transactionRef,
        ];

        $htmlBody = $this->getPaymentReceiptTemplate($guardianName, $rows, $clubName);

        $textBody = "Payment receipt\n\n" .
                    "Hi $guardianName,\n\n" .
                    "We received your payment. Keep this receipt for your records.\n\n" .
                    $this->paymentDetailsText($rows) . "\n" .
                    "You can see your balance any time in the parent portal under Payments.\n\n" .
                    "Thank you,\n$clubName\nvia Teams Elevated";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Reminder that a program fee is due, or is already overdue.
     *
     * Called by api/payment-reminders.php (single send and batch). The endpoint
     * writes payment_reminder_log only after this returns true — a log row is a
     * record that a family was contacted, and writing one for mail that never left
     * is how "we reminded you three times" becomes untrue.
     *
     * @return bool
     */
    public function sendPaymentReminder(
        $to,
        $guardianName,
        $athleteName,
        $itemName,
        $programName,
        $amountDue,
        $dueDate,
        $isOverdue,
        $clubName,
        $paymentLink
    ) {
        $subject = $isOverdue
            ? 'Payment overdue for ' . $athleteName
            : 'Payment reminder for ' . $athleteName;

        $amountFormatted = '$' . number_format((float) $amountDue, 2);
        $dueText = $dueDate ? date('F j, Y', strtotime($dueDate)) : 'Not set';

        $rows = [
            'Athlete'    => $athleteName,
            'Program'    => $programName,
            'Item'       => $itemName,
            'Amount due' => $amountFormatted,
            'Due date'   => $dueText,
        ];

        $intro = $isOverdue
            ? 'This payment is past its due date.'
            : 'This is a reminder about a payment coming due.';

        $htmlBody = $this->getPaymentReminderTemplate(
            $guardianName,
            $intro,
            $rows,
            $isOverdue,
            $clubName,
            $paymentLink
        );

        $textBody = ($isOverdue ? "Payment overdue\n\n" : "Payment reminder\n\n") .
                    "Hi $guardianName,\n\n" .
                    "$intro\n\n" .
                    $this->paymentDetailsText($rows) . "\n" .
                    "To pay, sign in to the parent portal:\n$paymentLink\n\n" .
                    "If you have already paid, or if something here looks wrong, reply to this\n" .
                    "email or contact $clubName and we will sort it out.\n\n" .
                    "Thank you,\n$clubName\nvia Teams Elevated";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Tell a family (or a club admin) that a payment attempt failed.
     *
     * $forAdmin switches the copy from "your payment did not go through" to an
     * internal alert. The admin copy is deliberately the same method: the two
     * messages describe one event, and a club that gets a differently-shaped alert
     * from the one the parent received cannot help them.
     *
     * @return bool
     */
    public function sendPaymentFailureNotice(
        $to,
        $recipientName,
        $athleteName,
        $itemName,
        $programName,
        $amount,
        $failureReason,
        $clubName,
        $paymentLink,
        $forAdmin = false
    ) {
        // The amount stays out of the subject on both copies.
        $subject = $forAdmin
            ? 'Payment failure — ' . $athleteName
            : 'We could not process a payment for ' . $athleteName;

        $amountFormatted = '$' . number_format((float) $amount, 2);
        $reason = trim((string) $failureReason) !== '' ? $failureReason : 'No reason reported by the card processor';

        $rows = [
            'Athlete' => $athleteName,
            'Program' => $programName,
            'Item'    => $itemName,
            'Amount'  => $amountFormatted,
            'Reason'  => $reason,
        ];

        $htmlBody = $this->getPaymentFailureTemplate(
            $recipientName,
            $rows,
            $forAdmin,
            $clubName,
            $paymentLink
        );

        if ($forAdmin) {
            $textBody = "Payment failure\n\n" .
                        "A payment attempt failed and the family has been notified.\n\n" .
                        $this->paymentDetailsText($rows) . "\n" .
                        "Follow up from Payments in the club dashboard if it is not resolved.\n\n" .
                        "$clubName\nvia Teams Elevated";
        } else {
            $textBody = "We could not process your payment\n\n" .
                        "Hi $recipientName,\n\n" .
                        "Your payment did not go through. Nothing has been charged.\n\n" .
                        $this->paymentDetailsText($rows) . "\n" .
                        "To try again with the same or a different card, sign in to the parent portal:\n" .
                        "$paymentLink\n\n" .
                        "If you have any questions, contact $clubName.\n\n" .
                        "Thank you,\n$clubName\nvia Teams Elevated";
        }

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /** Plain-text rendering of the same detail rows the HTML table shows. */
    private function paymentDetailsText(array $rows) {
        $out = "Payment details:\n";
        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $out .= "- $label: $value\n";
        }
        return $out;
    }

    private function getPaymentReceiptTemplate($guardianName, array $rows, $clubName) {
        return $this->renderPaymentEmail(
            'Payment Received',
            'Thank you',
            $guardianName,
            'We received your payment. Keep this receipt for your records.',
            $rows,
            null,
            null,
            'You can see your balance any time in the parent portal under Payments.',
            $clubName
        );
    }

    private function getPaymentReminderTemplate($guardianName, $intro, array $rows, $isOverdue, $clubName, $paymentLink) {
        return $this->renderPaymentEmail(
            $isOverdue ? 'Payment Overdue' : 'Payment Reminder',
            $isOverdue ? 'This payment is past due' : 'A payment is coming due',
            $guardianName,
            $intro,
            $rows,
            'Make a Payment',
            $paymentLink,
            'If you have already paid, or something here looks wrong, contact ' . $clubName . ' and we will sort it out.',
            $clubName
        );
    }

    private function getPaymentFailureTemplate($recipientName, array $rows, $forAdmin, $clubName, $paymentLink) {
        if ($forAdmin) {
            return $this->renderPaymentEmail(
                'Payment Failure',
                'A payment attempt did not go through',
                $recipientName,
                'A payment attempt failed and the family has been notified.',
                $rows,
                'Open Payments',
                $paymentLink,
                'Follow up from Payments in the club dashboard if it is not resolved.',
                $clubName
            );
        }

        return $this->renderPaymentEmail(
            'Payment Not Completed',
            'Nothing has been charged',
            $recipientName,
            'Your payment did not go through. Nothing has been charged, so please try again when you can.',
            $rows,
            'Try Again',
            $paymentLink,
            'If you have any questions, contact ' . $clubName . '.',
            $clubName
        );
    }

    /**
     * Tell somebody their compliance requirements need attention (GOTR G4).
     *
     * ⚠️ **This email deliberately carries NO detail beyond requirement NAMES and
     * dates.** No rejection reasons, no notes, no document names, nothing about
     * anybody else. A compliance record is health-and-safety paperwork about one
     * person; mail forwards, sits in shared family inboxes and cannot be recalled,
     * and the person can see the full state on the page this links to. Same rule,
     * and the same reasoning, as sendChatDigest carrying no message text.
     *
     * The copy is built by te_compliance_reminder_copy() in
     * lib/compliance_reminders.php so the wording lives with the sweep that
     * decides who gets it — one place to read when somebody asks what we said.
     *
     * Called through (new Email())->forClub($pdo, $clubId), so the From name is
     * the coach's own council and they recognise it. Never EmailSendService:
     * that would log a communication_log row per reminder and apply the club's
     * MARKETING suppression list, silently cutting off anyone who unsubscribed
     * from broadcasts.
     *
     * @param string $to            Recipient email
     * @param string $recipientName Their first name
     * @param array  $copy          From te_compliance_reminder_copy():
     *                              subject, heading, intro, lines[], cta
     * @param string $link          Where to see the requirements. Defaults to the
     *                              coach page under APP_URL.
     * @return bool Success status
     */
    public function sendComplianceReminder($to, $recipientName, array $copy, $link = null) {
        $subject = (string) ($copy['subject'] ?? 'Your requirements need attention');
        $heading = (string) ($copy['heading'] ?? 'Requirements');
        $intro   = (string) ($copy['intro'] ?? '');
        $cta     = (string) ($copy['cta'] ?? 'Sign in to see your requirements');
        $lines   = array_values(array_filter((array) ($copy['lines'] ?? [])));

        if ($link === null || $link === '') {
            $link = rtrim(Env::get('APP_URL', 'https://teams-elevated.netlify.app'), '/')
                . '/compliance/mine';
        }

        // The club, not the platform — forClub() has already put the council's
        // name here, and "Teams Elevated" is the honest answer when it has not.
        $clubName = $this->fromName;

        // renderPaymentEmail is the file's ONE shared renderer: header, detail
        // table, CTA button and footer. Its name is historical (it was written
        // for the three payment emails) but nothing in it is payment-specific,
        // and copying it would reintroduce exactly the drift
        // EmailButtonContrastTest exists to catch — the CTA's white label has to
        // be inline on the anchor AND on a nested span or mail clients render it
        // blue on dark green.
        $rows = [];
        foreach ((array) ($copy['rows'] ?? []) as $name => $detail) {
            // A council and its division may both define a rule called
            // "Background check". Two identical array keys would silently
            // collapse into one row, so a duplicate gets a zero-width space —
            // unique as a key, invisible on screen.
            $key = (string) $name;
            while (array_key_exists($key, $rows)) {
                $key .= "\u{200B}";
            }
            // Never blank: renderPaymentEmail skips a row whose value is '' or
            // null, so a requirement with no expiry date would disappear from
            // the list it is the whole subject of.
            $rows[$key] = trim((string) $detail) === '' ? '—' : (string) $detail;
        }

        $htmlBody = $this->renderPaymentEmail(
            $heading,
            $clubName,
            $recipientName,
            $intro,
            $rows,
            $cta,
            $link,
            'You are getting this because you hold a staff or volunteer role with ' . $clubName . '.',
            $clubName
        );

        $textBody = "{$heading}\n\n"
            . "Hi {$recipientName},\n\n"
            . "{$intro}\n\n"
            . implode("\n", array_map(static fn ($l): string => '- ' . $l, $lines)) . "\n\n"
            . "{$cta}:\n{$link}\n\n"
            . "You are getting this because you hold a staff or volunteer role with {$clubName}.\n\n"
            . "{$clubName}\nvia Teams Elevated";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send one step of an admin-authored reminder stream (GOTR G7).
     *
     * The subject and body are the ADMIN'S OWN COPY with the merge tags already
     * filled by lib/compliance_reminders.php — nothing is added to the body
     * beyond the club shell, the renewal button and the standard "why you got
     * this" line. The body is plain text from a textarea: escaped, paragraphs
     * on blank lines, line breaks kept. It is never emitted as HTML, for the
     * same reason the plain-text signature path had to stop being (an admin
     * textarea is not a place to type markup that 300 coaches will render).
     *
     * Call on an instance that has had forClub() applied. The CTA carries the
     * inline white label EmailButtonContrastTest enforces.
     *
     * @param string $to
     * @param string $recipientName
     * @param string $subject   already resolved
     * @param string $bodyText  already resolved, plain text
     * @param string $link      the renewal URL the button points at
     * @return bool
     */
    public function sendComplianceStreamStep($to, $recipientName, $subject, $bodyText, $link) {
        $clubName = $this->fromName;
        $subject = trim((string) $subject) !== '' ? (string) $subject : 'Your requirements need attention';

        $paragraphs = preg_split('/\R\s*\R/', trim((string) $bodyText)) ?: [];
        $bodyHtml = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $bodyHtml .= '<p>' . nl2br($this->escapeForEmail($paragraph)) . '</p>';
        }

        $safeLink = $this->escapeForEmail($link);
        $safeClub = $this->escapeForEmail($clubName);
        $greeting = trim((string) $recipientName) !== ''
            ? '<p>Hi ' . $this->escapeForEmail($recipientName) . ',</p>'
            : '';
        $ctaHtml = '<p style="text-align: center;">'
            . '<a href="' . $safeLink . '" class="button" style="display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0;">'
            . '<span style="color: #ffffff !important; text-decoration: none;">Renew now</span>'
            . '</a></p>'
            . '<p style="color: #999; font-size: 12px; word-break: break-all;">Or copy and paste this link: ' . $safeLink . '</p>';
        $note = 'You are getting this because you hold a staff or volunteer role with ' . $safeClub . '.';

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #12443E; color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .button { display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .button span { color: #ffffff !important; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0;">{$safeClub}</h1>
        </div>
        <div class="content">
            {$greeting}
            {$bodyHtml}
            {$ctaHtml}
            <p style="color: #666; font-size: 14px;">{$note}</p>
        </div>
        <div class="footer">
            <p>{$safeClub} &middot; via Teams Elevated</p>
        </div>
    </div>
</body>
</html>
HTML;

        $textBody = (trim((string) $recipientName) !== '' ? "Hi {$recipientName},\n\n" : '')
            . trim((string) $bodyText) . "\n\n"
            . "Renew now:\n{$link}\n\n"
            . "You are getting this because you hold a staff or volunteer role with {$clubName}.\n\n"
            . "{$clubName}\nvia Teams Elevated";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send a coach their single-use "set your password" link (GOTR G6).
     *
     * Call on an instance that has had forClub() applied — the heading names
     * the club, and "Teams Elevated" is the honest fallback when it has not.
     * Uses the shared renderer so the CTA carries the inline white label that
     * EmailButtonContrastTest exists to enforce.
     *
     * @param string $to         Coach email
     * @param string $name       Coach name
     * @param string $inviteLink Tokenised /accept-coach-invite URL
     * @return bool
     */
    public function sendCoachInvite($to, $name, $inviteLink) {
        $clubName = $this->fromName;
        $subject  = "Set up your {$clubName} coach account";
        $heading  = "You're invited to coach with {$clubName}";
        $intro    = "{$clubName} has set up a Teams Elevated coach account for you. "
                  . "Choose a password to finish setting it up. This link works once and expires in 7 days; "
                  . "after you have set your password, sign in with it instead of clicking the link again.";

        $htmlBody = $this->renderPaymentEmail(
            $heading,
            $clubName,
            $name,
            $intro,
            [],
            'Set my password',
            $inviteLink,
            "If you weren't expecting this invitation, you can safely ignore this email.",
            $clubName
        );

        $textBody = "Hi {$name},\n\n"
            . "{$intro}\n\n"
            . "Set my password:\n{$inviteLink}\n\n"
            . "If you weren't expecting this invitation, you can safely ignore this email.\n\n"
            . "{$clubName}\nvia Teams Elevated";

        return $this->send($to, $subject, $htmlBody, $textBody);
    }

    /**
     * One renderer for all three payment emails.
     *
     * The four older templates in this file are near-identical copies and have
     * drifted before — EmailButtonContrastTest exists because the CTA colour was
     * fixed in some of them and not others. These three share a renderer so there
     * is one button, one table and one footer to get right.
     *
     * Every interpolated value is escaped: names, item names and the processor's
     * failure_reason all come from data.
     */
    private function renderPaymentEmail(
        $heading,
        $subHeading,
        $greetingName,
        $intro,
        array $rows,
        $ctaLabel,
        $ctaLink,
        $note,
        $clubName
    ) {
        $heading    = $this->escapeForEmail($heading);
        $subHeading = $this->escapeForEmail($subHeading);
        $intro      = $this->escapeForEmail($intro);
        $note       = $this->escapeForEmail($note);
        $clubName   = $this->escapeForEmail($clubName);

        $greeting = trim((string) $greetingName) !== ''
            ? '<p>Hi ' . $this->escapeForEmail($greetingName) . ',</p>'
            : '';

        $rowsHtml = '';
        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $rowsHtml .= '<tr>'
                . '<td style="padding: 10px 0; border-bottom: 1px solid #eee; color: #666;">'
                . $this->escapeForEmail($label) . '</td>'
                . '<td style="padding: 10px 0; border-bottom: 1px solid #eee; font-weight: 500; text-align: right;">'
                . $this->escapeForEmail($value) . '</td>'
                . '</tr>';
        }

        // The button label must carry white inline on the anchor AND on a nested
        // span. Mail clients override anchor colour with their own link styling,
        // which turns a dark green button into an unreadable blue-on-green one —
        // the failure EmailButtonContrastTest was written for.
        $ctaHtml = '';
        if ($ctaLabel && $ctaLink) {
            $safeLink  = $this->escapeForEmail($ctaLink);
            $safeLabel = $this->escapeForEmail($ctaLabel);
            $ctaHtml = '<p style="text-align: center;">'
                . '<a href="' . $safeLink . '" class="button" style="display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0;">'
                . '<span style="color: #ffffff !important; text-decoration: none;">' . $safeLabel . '</span>'
                . '</a></p>'
                . '<p style="color: #999; font-size: 12px; word-break: break-all;">Or copy and paste this link: ' . $safeLink . '</p>';
        }

        $noteHtml = $note !== ''
            ? '<p style="color: #666; font-size: 14px;">' . $note . '</p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #12443E; color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .detail-box { background: white; border: 2px solid #12443E; border-radius: 8px; padding: 25px; margin: 20px 0; }
        .button { display: inline-block; background: #12443E; color: #ffffff !important; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .button span { color: #ffffff !important; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0;">{$heading}</h1>
            <p style="margin: 5px 0 0 0; opacity: 0.9;">{$subHeading}</p>
        </div>
        <div class="content">
            {$greeting}
            <p>{$intro}</p>
            <div class="detail-box">
                <table style="width: 100%; border-collapse: collapse;">
                    {$rowsHtml}
                </table>
            </div>
            {$ctaHtml}
            {$noteHtml}
        </div>
        <div class="footer">
            <p>{$clubName} &middot; via Teams Elevated</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /** Names, item names and processor failure_reason strings all come from data. */
    private function escapeForEmail($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
