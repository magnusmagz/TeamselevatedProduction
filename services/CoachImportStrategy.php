<?php
require_once __DIR__ . '/ImportStrategy.php';
require_once __DIR__ . '/CoachInviteService.php';
require_once __DIR__ . '/../lib/coach_invite.php';

/**
 * CoachImportStrategy — imports coaches as users with a club-level coach role.
 *
 * A "coach" in this system is a user with a `coach` role on a club via
 * user_club_access. This importer creates the user (if they don't exist), the
 * access row, and — since GOTR G6 — a single-use invite that reaches them
 * through the queue. Team assignment is NOT handled here.
 *
 * Every identity decision is lib/coach_invite.php's, shared with the Coaches
 * page, so an import and a hand-typed coach cannot drift:
 *   - users.email is UNIQUE → an existing account is ATTACHED, never duplicated;
 *   - an account with a password is `already_active` and gets no invite;
 *   - a revoked access is not re-granted by a re-import;
 *   - no password is ever written here.
 *
 * The invite EMAIL is not sent from inside the import loop. The row enqueues
 * one job (CoachInviteService::jobPayload) through `$context['enqueue_invite']`
 * — a callable the worker wires to the rate-limited email queue. Without one
 * (a preview, a test) the account and token still exist; only the mail waits.
 */

class CoachImportStrategy extends ImportStrategy {
    public function getEntityType(): string {
        return 'coaches';
    }

    public function getRequiredFields(): array {
        return [
            'first_name',
            'last_name',
            'email',
        ];
    }

    public function getOptionalFields(): array {
        return [
            'phone',
        ];
    }

    public function getFieldLabels(): array {
        return [
            'first_name' => 'First Name',
            'last_name'  => 'Last Name',
            'email'      => 'Email',
            'phone'      => 'Phone',
        ];
    }

    public function getSynonyms(): array {
        return [
            'first_name' => ['firstname', 'first', 'givenname', 'coachfirstname'],
            'last_name'  => ['lastname', 'last', 'surname', 'familyname', 'coachlastname'],
            'email'      => ['email', 'emailaddress', 'coachemail'],
            'phone'      => ['phone', 'mobile', 'cell', 'phonenumber', 'coachphone'],
        ];
    }

    public function processRow(array $row, array $mapping, array $context): string {
        /** @var PDO $pdo */
        $pdo       = $context['pdo'];
        $clubId    = (int) $context['club_id'];
        $grantedBy = (int) $context['user_id'];

        $person = [
            'first_name' => $this->field($row, $mapping, 'first_name'),
            'last_name'  => $this->field($row, $mapping, 'last_name'),
            'email'      => strtolower($this->field($row, $mapping, 'email')),
            'phone'      => $this->field($row, $mapping, 'phone'),
        ];

        if ($person['first_name'] === '' || $person['last_name'] === '' || $person['email'] === '') {
            throw new RuntimeException('Missing required field: first_name, last_name, and email are all required');
        }

        $result = te_coach_invite_ensure_user_and_token($pdo, $person, $clubId, $grantedBy ?: null, 'import');

        switch ($result['status']) {
            case 'error':
                throw new RuntimeException($result['message']);

            case 'access_revoked':
                // The council removed this person. A roster re-upload is not a decision to restore them.
                return 'skipped';

            case 'already_active':
                return $result['access'] === 'granted' ? 'updated' : 'skipped';

            case 'invited':
                $enqueue = $context['enqueue_invite'] ?? null;
                if (is_callable($enqueue)) {
                    // After the commit inside ensure(): a job for a row that rolled back would mail a dead link.
                    $enqueue(CoachInviteService::jobPayload((int) $result['user_id'], $clubId, $grantedBy ?: null));
                }
                return !empty($result['created']) ? 'created' : 'updated';
        }

        throw new RuntimeException('Unexpected invite outcome: ' . json_encode($result));
    }
}
