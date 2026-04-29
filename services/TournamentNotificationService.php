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
     * Render the kind's template with $ctx merge fields and queue email to
     * $recipients. Empty recipient list is a silent no-op (legitimate case).
     */
    private function dispatchEmail($kind, array $ctx, array $recipients, $actorUserId) {
        if (empty($recipients)) return;

        $tpl = $this->loadTemplate($kind, $ctx['club_profile_id'] ?? null);
        if (!$tpl) {
            error_log("TournamentNotificationService [$kind]: no active template found");
            return;
        }

        $mergeCtx = array_merge($ctx, ['user_id' => $actorUserId]);
        $subject = $this->mergeFieldService->resolveVariables($tpl['subject'] ?? '', $mergeCtx);
        $html    = $this->mergeFieldService->resolveVariables($tpl['html_output'] ?? '', $mergeCtx);
        $body    = $this->mergeFieldService->resolveVariables($tpl['body_text'] ?? strip_tags($html), $mergeCtx);

        $this->emailService->queueEmail([
            'user_id'         => $actorUserId,
            'club_profile_id' => $ctx['club_profile_id'] ?? null,
            'recipients'      => $recipients,
            'subject'         => $subject,
            'html_body'       => $html,
            'body'            => $body,
            'template_id'     => $tpl['id'],
        ]);
    }

    /**
     * Render the SMS body (uses body_text from the same template) and queue
     * SMS to $recipients. Recipients without phones are filtered upstream
     * by withPhones().
     */
    private function dispatchSms($kind, array $ctx, array $recipients, $actorUserId) {
        if (empty($recipients)) return;

        $tpl = $this->loadTemplate($kind, $ctx['club_profile_id'] ?? null);
        if (!$tpl || empty($tpl['body_text'])) return;

        $mergeCtx = array_merge($ctx, ['user_id' => $actorUserId]);
        $body = $this->mergeFieldService->resolveVariables($tpl['body_text'], $mergeCtx);

        $this->smsService->queueSms([
            'user_id'         => $actorUserId,
            'club_profile_id' => $ctx['club_profile_id'] ?? null,
            'recipients'      => $recipients,
            'body'            => $body,
        ]);
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
}
