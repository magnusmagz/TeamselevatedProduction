<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/EmailSendService.php';
require_once __DIR__ . '/SmsSendService.php';
require_once __DIR__ . '/MergeFieldService.php';
require_once __DIR__ . '/RecipientService.php';

/**
 * Tournament Notification Service
 *
 * Translates tournament domain events (registration accepted, match rescheduled,
 * score posted, etc.) into queued email/SMS via the existing comms pipeline.
 *
 * Design:
 *   - Procedural, one public method per trigger kind. Matches the codebase style
 *     (no event bus, no observer pattern).
 *   - Every public method is wrapped in try/catch and NEVER throws upward.
 *     Comms failures must not break the originating tournament action.
 *   - Every trigger is gated by both a master flag (TOURNAMENT_TRIGGERS_ENABLED)
 *     and a per-kind flag (TOURNAMENT_TRIGGER_<KIND>). Both default to false.
 *     This lets us deploy the wiring with everything off, then flip flags
 *     one at a time in production while smoke-testing.
 *   - Template lookup cascades: club-scoped override → platform default. Clubs
 *     can clone the platform template and edit; if no club row exists the
 *     platform default is used.
 */
class TournamentNotificationService {
    private $pdo;
    private $emailService;
    private $smsService;
    private $mergeFieldService;
    private $recipientService;

    public function __construct(
        $pdo,
        ?EmailSendService $emailService = null,
        ?SmsSendService $smsService = null,
        ?MergeFieldService $mergeFieldService = null,
        ?RecipientService $recipientService = null
    ) {
        $this->pdo = $pdo;
        $this->emailService = $emailService ?? new EmailSendService($pdo);
        $this->smsService = $smsService ?? new SmsSendService($pdo);
        $this->mergeFieldService = $mergeFieldService ?? new MergeFieldService($pdo);
        $this->recipientService = $recipientService ?? new RecipientService($pdo);
    }

    // ============================================
    // Public trigger methods — one per kind
    // ============================================

    public function notifyRegistrationAccepted($registrationId, $actorUserId) {
        $kind = 'tournament.registration_accepted';
        if (!$this->kindEnabled($kind)) return;
        try {
            $ctx = $this->loadRegistrationContext($registrationId);
            if (!$ctx) return;
            $recipients = $this->recipientService->getRegistrationRecipients($registrationId);
            $this->dispatchEmail($kind, $ctx, $recipients, $actorUserId);
        } catch (\Throwable $e) {
            $this->logFailure($kind, $registrationId, $e);
        }
    }

    public function notifyRegistrationDeclined($registrationId, $actorUserId) {
        $kind = 'tournament.registration_declined';
        if (!$this->kindEnabled($kind)) return;
        try {
            $ctx = $this->loadRegistrationContext($registrationId);
            if (!$ctx) return;
            $recipients = $this->recipientService->getRegistrationSubmitter($registrationId);
            $this->dispatchEmail($kind, $ctx, $recipients, $actorUserId);
        } catch (\Throwable $e) {
            $this->logFailure($kind, $registrationId, $e);
        }
    }

    public function notifyRegistrationWaitlisted($registrationId, $actorUserId) {
        $kind = 'tournament.registration_waitlisted';
        if (!$this->kindEnabled($kind)) return;
        try {
            $ctx = $this->loadRegistrationContext($registrationId);
            if (!$ctx) return;
            $recipients = $this->recipientService->getRegistrationSubmitter($registrationId);
            $this->dispatchEmail($kind, $ctx, $recipients, $actorUserId);
        } catch (\Throwable $e) {
            $this->logFailure($kind, $registrationId, $e);
        }
    }

    /**
     * Spot opened up — offer the next-in-line waitlisted team.
     *
     * Caller (WaitlistService::promoteNextWaitlist) has already marked the
     * registration as 'offered' and generated a token. We just need to
     * email the team registrar with the accept/decline links.
     *
     * Recipients: the original submitter only. CC'ing the tournament
     * contact is intentionally *not* done — the offer is a per-team
     * decision and the contact will see the accept/decline outcome.
     */
    public function notifyWaitlistOffer($registrationId, $actorUserId) {
        $kind = 'tournament.waitlist_offer';
        if (!$this->kindEnabled($kind)) return;
        try {
            require_once __DIR__ . '/WaitlistService.php';
            $waitlist = new WaitlistService($this->db);
            $appUrl = (defined('APP_URL') ? APP_URL : (getenv('APP_URL') ?: 'https://teams-elevated.netlify.app'));
            $ctx = $waitlist->getOfferContext($registrationId, $appUrl);
            if (!$ctx) return;
            $recipients = $this->recipientService->getRegistrationSubmitter($registrationId);
            $this->dispatchEmail($kind, $ctx, $recipients, $actorUserId);
        } catch (\Throwable $e) {
            $this->logFailure($kind, $registrationId, $e);
        }
    }

    /**
     * Team accepted their waitlist spot — send a confirmation. Reuses the
     * existing registration_accepted template path conceptually but with
     * its own template so we can frame the message as "you're in" rather
     * than the standard registration acceptance copy.
     */
    public function notifyWaitlistAccepted($registrationId, $actorUserId) {
        $kind = 'tournament.waitlist_accepted';
        if (!$this->kindEnabled($kind)) return;
        try {
            require_once __DIR__ . '/WaitlistService.php';
            $waitlist = new WaitlistService($this->db);
            $appUrl = (defined('APP_URL') ? APP_URL : (getenv('APP_URL') ?: 'https://teams-elevated.netlify.app'));
            $ctx = $waitlist->getOfferContext($registrationId, $appUrl);
            if (!$ctx) {
                // Token may already be cleared by the time we send the
                // confirmation — fall back to the generic registration ctx.
                $ctx = $this->loadRegistrationContext($registrationId);
            }
            if (!$ctx) return;
            $recipients = $this->recipientService->getRegistrationSubmitter($registrationId);
            $this->dispatchEmail($kind, $ctx, $recipients, $actorUserId);
        } catch (\Throwable $e) {
            $this->logFailure($kind, $registrationId, $e);
        }
    }

    /**
     * Courtesy "your offer expired" notice. Team stays on the waitlist —
     * if another spot opens they'll get re-offered.
     */
    public function notifyWaitlistOfferExpired($registrationId) {
        $kind = 'tournament.waitlist_offer_expired';
        if (!$this->kindEnabled($kind)) return;
        try {
            $ctx = $this->loadRegistrationContext($registrationId);
            if (!$ctx) return;
            $recipients = $this->recipientService->getRegistrationSubmitter($registrationId);
            // Actor is the system (cron), so pass null user_id.
            $this->dispatchEmail($kind, $ctx, $recipients, null);
        } catch (\Throwable $e) {
            $this->logFailure($kind, $registrationId, $e);
        }
    }

    public function notifyPaymentReceived($registrationId, $actorUserId) {
        $kind = 'tournament.payment_received';
        if (!$this->kindEnabled($kind)) return;
        try {
            $ctx = $this->loadRegistrationContext($registrationId);
            if (!$ctx) return;
            $recipients = $this->recipientService->getRegistrationSubmitter($registrationId);
            $this->dispatchEmail($kind, $ctx, $recipients, $actorUserId);
        } catch (\Throwable $e) {
            $this->logFailure($kind, $registrationId, $e);
        }
    }

    public function notifySchedulePublished($tournamentId, $actorUserId) {
        $kind = 'tournament.schedule_published';
        if (!$this->kindEnabled($kind)) return;
        try {
            $ctx = $this->loadTournamentContext($tournamentId);
            if (!$ctx) return;
            $recipients = $this->recipientService->getTournamentRecipients($tournamentId, ['accepted']);
            $this->dispatchEmail($kind, $ctx, $recipients, $actorUserId);
        } catch (\Throwable $e) {
            $this->logFailure($kind, $tournamentId, $e);
        }
    }

    public function notifyMatchRescheduled($matchId, $actorUserId) {
        $kind = 'tournament.match_rescheduled';
        if (!$this->kindEnabled($kind)) return;
        try {
            $ctx = $this->loadMatchContext($matchId);
            if (!$ctx) return;
            $recipients = $this->recipientService->getMatchRecipients($matchId);
            $this->dispatchEmail($kind, $ctx, $recipients, $actorUserId);
        } catch (\Throwable $e) {
            $this->logFailure($kind, $matchId, $e);
        }
    }

    public function notifyScorePosted($matchId, $actorUserId) {
        $kind = 'tournament.score_posted';
        if (!$this->kindEnabled($kind)) return;
        try {
            $ctx = $this->loadMatchContext($matchId);
            if (!$ctx) return;
            $recipients = $this->recipientService->getMatchRecipients($matchId);
            $this->dispatchEmail($kind, $ctx, $recipients, $actorUserId);
            // Score posts also fan SMS to parents who have phones — per-recipient
            // suppression and opt-out is handled inside SmsSendService.
            $this->dispatchSms($kind, $ctx, $this->withPhones($recipients), $actorUserId);
        } catch (\Throwable $e) {
            $this->logFailure($kind, $matchId, $e);
        }
    }

    public function notifyWeatherDelay($tournamentId, $actorUserId) {
        $kind = 'tournament.weather_delay';
        if (!$this->kindEnabled($kind)) return;
        try {
            $ctx = $this->loadTournamentContext($tournamentId);
            if (!$ctx) return;
            // Weather delay is a true broadcast — pending and accepted both included.
            $recipients = $this->recipientService->getTournamentRecipients(
                $tournamentId,
                ['accepted', 'pending', 'waitlisted']
            );
            $this->dispatchEmail($kind, $ctx, $recipients, $actorUserId);
            $this->dispatchSms($kind, $ctx, $this->withPhones($recipients), $actorUserId);
        } catch (\Throwable $e) {
            $this->logFailure($kind, $tournamentId, $e);
        }
    }

    // ============================================
    // Feature flags
    // ============================================

    private function kindEnabled($kind) {
        if (!$this->boolEnv('TOURNAMENT_TRIGGERS_ENABLED', false)) return false;

        // Convert "tournament.registration_accepted" -> "TOURNAMENT_TRIGGER_REGISTRATION_ACCEPTED"
        $suffix = strtoupper(str_replace('.', '_', preg_replace('/^tournament\./', '', $kind)));
        $flag = 'TOURNAMENT_TRIGGER_' . $suffix;
        return $this->boolEnv($flag, false);
    }

    private function boolEnv($name, $default) {
        $val = Env::get($name, $default ? '1' : '0');
        if (is_bool($val)) return $val;
        $val = strtolower((string)$val);
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }

    // ============================================
    // Context loaders — gather IDs needed by MergeFieldService and dispatch
    // ============================================

    private function loadRegistrationContext($registrationId) {
        $stmt = $this->pdo->prepare("
            SELECT
                r.id              AS registration_id,
                r.tournament_id   AS tournament_id,
                r.division_id     AS division_id,
                r.team_id         AS team_id,
                t.club_id         AS club_profile_id
            FROM tournament_registrations r
            JOIN tournaments t ON r.tournament_id = t.id
            WHERE r.id = ?
        ");
        $stmt->execute([$registrationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function loadTournamentContext($tournamentId) {
        $stmt = $this->pdo->prepare("
            SELECT id AS tournament_id, club_id AS club_profile_id
            FROM tournaments
            WHERE id = ?
        ");
        $stmt->execute([$tournamentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function loadMatchContext($matchId) {
        $stmt = $this->pdo->prepare("
            SELECT
                m.id           AS match_id,
                d.id           AS division_id,
                d.tournament_id AS tournament_id,
                t.club_id      AS club_profile_id
            FROM tournament_matches m
            JOIN tournament_divisions d ON m.division_id = d.id
            JOIN tournaments t ON d.tournament_id = t.id
            WHERE m.id = ?
        ");
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ============================================
    // Template lookup — club override → platform default
    // ============================================

    private function loadTemplate($kind, $clubProfileId) {
        // 1. Try club-scoped override
        if ($clubProfileId) {
            $stmt = $this->pdo->prepare("
                SELECT id, subject, html_output, body_text
                FROM email_templates
                WHERE tournament_event_kind = ?
                    AND club_profile_id = ?
                    AND is_active = true
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$kind, $clubProfileId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }

        // 2. Fall back to platform default
        $stmt = $this->pdo->prepare("
            SELECT id, subject, html_output, body_text
            FROM email_templates
            WHERE tournament_event_kind = ?
                AND club_profile_id IS NULL
                AND scope = 'platform'
                AND is_active = true
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$kind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ============================================
    // Dispatch helpers
    // ============================================

    /**
     * Render the kind's template per-recipient and queue. Per-recipient render
     * is required because templates may include {{guardian_first_name}} or
     * {{athlete_first_name}} which only resolve when guardian_id / athlete_id
     * are passed in the merge context. We load the template once, then loop
     * recipients, building a recipient-specific merge ctx each time.
     *
     * Recipients with a tournament-scope unsubscribe (email_suppressions row
     * with scope='tournament') are filtered out here — that lets parents opt
     * out of *just* tournament alerts without losing their team emails. Any
     * broader suppression (scope='club' / 'team') is still caught by
     * EmailSendService::queueEmail's own isSuppressed check downstream.
     */
    private function dispatchEmail($kind, array $ctx, array $recipients, $actorUserId) {
        if (empty($recipients)) return;

        $tpl = $this->loadTemplate($kind, $ctx['club_profile_id'] ?? null);
        if (!$tpl) {
            error_log("TournamentNotificationService [$kind]: no active template found");
            return;
        }

        $clubProfileId = $ctx['club_profile_id'] ?? null;
        $recipients = $this->filterTournamentSuppressed($recipients, $clubProfileId);
        if (empty($recipients)) return;

        foreach ($recipients as $recipient) {
            $perRecipientCtx = $this->buildPerRecipientCtx($ctx, $recipient, $actorUserId);

            $subject = $this->mergeFieldService->resolveVariables($tpl['subject'] ?? '', $perRecipientCtx);
            $html    = $this->mergeFieldService->resolveVariables($tpl['html_output'] ?? '', $perRecipientCtx);
            $body    = $this->mergeFieldService->resolveVariables(
                $tpl['body_text'] ?? strip_tags($html),
                $perRecipientCtx
            );

            $this->emailService->queueEmail([
                'user_id'         => $actorUserId,
                'club_profile_id' => $ctx['club_profile_id'] ?? null,
                'recipients'      => [$recipient],
                'subject'         => $subject,
                'html_body'       => $html,
                'body'            => $body,
                'template_id'     => $tpl['id'],
            ]);
        }
    }

    /**
     * Render the SMS body per-recipient (so {{guardian_first_name}} works in
     * SMS templates too) and queue. Recipients without phones are filtered
     * upstream by withPhones(); SmsSendService applies its own opt-out checks.
     * Tournament-scope unsubscribed recipients are also filtered here.
     */
    private function dispatchSms($kind, array $ctx, array $recipients, $actorUserId) {
        if (empty($recipients)) return;

        $tpl = $this->loadTemplate($kind, $ctx['club_profile_id'] ?? null);
        if (!$tpl || empty($tpl['body_text'])) return;

        $clubProfileId = $ctx['club_profile_id'] ?? null;
        $recipients = $this->filterTournamentSuppressed($recipients, $clubProfileId);
        if (empty($recipients)) return;

        foreach ($recipients as $recipient) {
            $perRecipientCtx = $this->buildPerRecipientCtx($ctx, $recipient, $actorUserId);
            $body = $this->mergeFieldService->resolveVariables($tpl['body_text'], $perRecipientCtx);

            $this->smsService->queueSms([
                'user_id'         => $actorUserId,
                'club_profile_id' => $ctx['club_profile_id'] ?? null,
                'recipients'      => [$recipient],
                'body'            => $body,
            ]);
        }
    }

    /**
     * Merge the trigger context with per-recipient identifiers so that
     * MergeFieldService can resolve {{guardian_*}}, {{athlete_*}}, etc.
     * Recipient rows come from RecipientService and carry `type` plus a
     * domain id we can map to the merge service's expected keys.
     */
    private function buildPerRecipientCtx(array $ctx, array $recipient, $actorUserId) {
        $out = array_merge($ctx, ['user_id' => $actorUserId]);

        $type = $recipient['type'] ?? null;
        $rid  = $recipient['id'] ?? null;

        if ($type === 'guardian' && $rid) {
            $out['guardian_id'] = (int)$rid;
            if (!empty($recipient['athlete_id'])) {
                $out['athlete_id'] = (int)$recipient['athlete_id'];
            }
        } elseif ($type === 'athlete' && $rid) {
            $out['athlete_id'] = (int)$rid;
        }
        // 'coach' / 'user' types currently don't expose a merge-field group;
        // sender_* uses user_id which is the action-taker, not the recipient.
        return $out;
    }

    /**
     * Filter a recipient list down to entries that have a usable phone number,
     * prefer guardian.mobile_phone for guardian-typed rows. SmsSendService does
     * the actual normalization + opt-out check; this is just a first-pass filter
     * so we don't queue SMS jobs for recipients we already know have no phone.
     */
    private function withPhones(array $recipients) {
        if (empty($recipients)) return [];

        // Bulk-fetch phones for all guardian recipients in one query.
        $guardianIds = [];
        foreach ($recipients as $r) {
            if (($r['type'] ?? '') === 'guardian' && !empty($r['id'])) {
                $guardianIds[] = (int)$r['id'];
            }
        }

        $phoneById = [];
        if (!empty($guardianIds)) {
            $placeholders = str_repeat('?,', count($guardianIds) - 1) . '?';
            $stmt = $this->pdo->prepare("
                SELECT id, mobile_phone
                FROM guardians
                WHERE id IN ($placeholders)
                    AND mobile_phone IS NOT NULL
                    AND mobile_phone <> ''
            ");
            $stmt->execute($guardianIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $phoneById[(int)$row['id']] = $row['mobile_phone'];
            }
        }

        $out = [];
        foreach ($recipients as $r) {
            if (($r['type'] ?? '') === 'guardian' && isset($phoneById[(int)$r['id']])) {
                $r['phone'] = $phoneById[(int)$r['id']];
                $out[] = $r;
            }
            // Other recipient types (athlete, coach, user) don't have a standardized
            // phone column on this query — extend later if SMS-to-coach is needed.
        }
        return $out;
    }

    private function logFailure($kind, $entityId, \Throwable $e) {
        error_log(sprintf(
            'TournamentNotificationService [%s] failed for id=%s: %s',
            $kind,
            $entityId,
            $e->getMessage()
        ));
    }

    /**
     * Filter out recipients whose email has a tournament-scope suppression
     * (email_suppressions.scope = 'tournament') for the given club. Single
     * bulk query, no N+1 lookup.
     */
    private function filterTournamentSuppressed(array $recipients, $clubProfileId): array {
        if (empty($recipients) || !$clubProfileId) return $recipients;

        $emails = array_values(array_unique(array_filter(array_map(
            fn($r) => isset($r['email']) ? strtolower($r['email']) : null,
            $recipients
        ))));
        if (empty($emails)) return $recipients;

        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $stmt = $this->pdo->prepare("
            SELECT LOWER(email) AS email
            FROM email_suppressions
            WHERE club_profile_id = ?
              AND channel = 'email'
              AND scope = 'tournament'
              AND email IN ($placeholders)
        ");
        $stmt->execute(array_merge([(int)$clubProfileId], $emails));
        $suppressed = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'email');
        if (empty($suppressed)) return $recipients;

        $suppressedSet = array_flip($suppressed);
        return array_values(array_filter(
            $recipients,
            fn($r) => !isset($suppressedSet[strtolower($r['email'] ?? '')])
        ));
    }
}
