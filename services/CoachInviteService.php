<?php
require_once __DIR__ . '/../lib/coach_invite.php';

/**
 * CoachInviteService — the queue-side half of coach invites (GOTR G6).
 *
 * An import of 30,000 coaches must not send 30,000 emails from inside the
 * import loop. Each row enqueues ONE job on `email_queue`, which is the queue
 * the worker already rate-limits (TE_RATE_LIMIT_EMAIL_PER_MINUTE), so a national
 * onboarding cannot outrun SendGrid or drown a scheduled broadcast — and it can
 * be paused by pausing that queue.
 *
 * The worker hands `type: coach_invite` jobs here rather than to EmailSendService:
 * the bulk path writes a communication_log row per send (which would flood Email
 * Reporting) and applies the club's MARKETING suppressions (which must not stop a
 * sign-in link). This service uses lib/Email.php branded as the club.
 *
 * ⚠️ Construct it inside `$buildServices()` in workers/queue-worker.php and nowhere
 * else in the worker — a service built at boot keeps a dead DB handle after a
 * reconnect (CLAUDE.md, "rebuild services, don't just reconnect").
 *
 * The payload never carries the token. The worker reads the freshest unused
 * token at send time, so a job that sits in Redis for an hour still sends a
 * link that works, and nothing in Redis can sign in as anyone.
 */
class CoachInviteService {
    public const QUEUE = 'email_queue';
    public const TYPE  = 'coach_invite';

    private PDO $pdo;
    /** @var callable|null fn(string $to, string $name, string $link): bool */
    private $sender;

    public function __construct(PDO $pdo, ?callable $sender = null) {
        $this->pdo = $pdo;
        $this->sender = $sender;
    }

    /** The job an importer pushes. Deliberately small: ids only. */
    public static function jobPayload(int $userId, int $clubId, ?int $actorId = null): array {
        return [
            'id'           => sprintf('coach_invite_%d_%d_%s', $userId, $clubId, bin2hex(random_bytes(4))),
            'type'         => self::TYPE,
            'user_id'      => $userId,
            'club_id'      => $clubId,
            'actor_id'     => $actorId,
            'max_attempts' => 3,
        ];
    }

    /**
     * Send one invite.
     *
     * Throws ONLY for a transport failure, which is the one outcome a retry can
     * change. "Already active", "switched off" and a malformed payload are final
     * answers and are returned, not thrown — retrying them three times would
     * just burn the queue's rate allowance.
     *
     * @return array{sent: bool, reason: string}
     */
    public function processJob(array $payload): array {
        $userId = (int) ($payload['user_id'] ?? 0);
        $clubId = (int) ($payload['club_id'] ?? 0);
        if ($userId <= 0 || $clubId <= 0) {
            error_log('[CoachInviteService] bad payload: ' . json_encode($payload));
            return ['sent' => false, 'reason' => 'bad_payload'];
        }

        $result = te_coach_invite_send($this->pdo, $userId, $clubId, $this->sender);

        if (!$result['sent'] && ($result['reason'] ?? '') === 'transport_failed') {
            throw new RuntimeException("coach invite for user {$userId} could not be delivered to the mail transport");
        }
        return $result;
    }
}
