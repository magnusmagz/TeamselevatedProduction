<?php
require_once __DIR__ . '/ImportStrategy.php';

/**
 * VolunteerImportStrategy — imports team volunteers.
 *
 * A "volunteer" in this system is a (team, user) pair stored in
 * team_volunteers. There's no standalone `volunteers` table. This
 * strategy find-or-creates the user (by email, which is unique),
 * looks up the team by name scoped to the import's club, and
 * inserts a team_volunteers row if one doesn't already exist.
 *
 * Users created here have password_hash=NULL (login disabled until
 * they claim the account via the existing signup/password reset
 * flow). No invite email is sent — that's a future enhancement.
 *
 * The CSV can span multiple teams in one file via the team_name
 * column — no upload-time team picker is used.
 */

class VolunteerImportStrategy extends ImportStrategy {
    private static $ALLOWED_BACKGROUND_STATUSES = ['pending', 'cleared', 'expired', 'none', 'never_checked'];

    public function getEntityType(): string {
        return 'volunteers';
    }

    public function getRequiredFields(): array {
        return [
            'team_name',
            'first_name',
            'last_name',
            'email',
        ];
    }

    public function getOptionalFields(): array {
        return [
            'phone',
            'volunteer_role',
            'background_check_status',
            'background_check_date',
            'start_date',
            'notes',
        ];
    }

    public function getFieldLabels(): array {
        return [
            'team_name'               => 'Team Name (must already exist in your club)',
            'first_name'              => 'First Name',
            'last_name'               => 'Last Name',
            'email'                   => 'Email',
            'phone'                   => 'Phone',
            'volunteer_role'          => 'Role (e.g., Team Manager, Team Parent, Assistant Coach)',
            'background_check_status' => 'Background Check Status (pending/cleared/expired/none/never_checked)',
            'background_check_date'   => 'Background Check Date (YYYY-MM-DD)',
            'start_date'              => 'Start Date (YYYY-MM-DD)',
            'notes'                   => 'Notes',
        ];
    }

    public function getSynonyms(): array {
        return [
            'team_name'               => ['teamname', 'team'],
            'first_name'              => ['firstname', 'first', 'givenname', 'volunteerfirstname'],
            'last_name'               => ['lastname', 'last', 'surname', 'familyname', 'volunteerlastname'],
            'email'                   => ['email', 'emailaddress', 'volunteeremail'],
            'phone'                   => ['phone', 'mobile', 'cell', 'phonenumber', 'volunteerphone'],
            'volunteer_role'          => ['volunteerrole', 'role', 'position', 'title'],
            'background_check_status' => ['backgroundcheckstatus', 'bgcheckstatus', 'backgroundcheck', 'bgcheck'],
            'background_check_date'   => ['backgroundcheckdate', 'bgcheckdate'],
            'start_date'              => ['startdate', 'beginsat', 'startedat'],
            'notes'                   => ['notes', 'comments'],
        ];
    }

    public function processRow(array $row, array $mapping, array $context): string {
        /** @var PDO $pdo */
        $pdo       = $context['pdo'];
        $clubId    = (int) $context['club_id'];
        $assignedBy = (int) $context['user_id'];

        $teamName  = $this->field($row, $mapping, 'team_name');
        $firstName = $this->field($row, $mapping, 'first_name');
        $lastName  = $this->field($row, $mapping, 'last_name');
        $email     = strtolower($this->field($row, $mapping, 'email'));

        if ($teamName === '' || $firstName === '' || $lastName === '' || $email === '') {
            throw new RuntimeException('Missing required field: team_name, first_name, last_name, and email are all required');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid email '{$email}'");
        }

        // Validate optional date/enum fields before starting a transaction.
        $bgStatus = $this->field($row, $mapping, 'background_check_status');
        if ($bgStatus !== '' && !in_array($bgStatus, self::$ALLOWED_BACKGROUND_STATUSES, true)) {
            throw new RuntimeException("Invalid background_check_status '{$bgStatus}' — must be one of: " . implode(', ', self::$ALLOWED_BACKGROUND_STATUSES));
        }

        $startDate = $this->field($row, $mapping, 'start_date');
        if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            throw new RuntimeException("Invalid start_date '{$startDate}' — must be YYYY-MM-DD");
        }

        $bgDate = $this->field($row, $mapping, 'background_check_date');
        if ($bgDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bgDate)) {
            throw new RuntimeException("Invalid background_check_date '{$bgDate}' — must be YYYY-MM-DD");
        }

        $pdo->beginTransaction();
        try {
            $teamId = $this->findTeamIdByName($pdo, $teamName, $clubId);
            if ($teamId === null) {
                throw new RuntimeException("Team '{$teamName}' not found in this club");
            }

            $userId = $this->findOrCreateUser($pdo, $firstName, $lastName, $email, $this->field($row, $mapping, 'phone'));

            $outcome = $this->findOrCreateTeamVolunteer(
                $pdo,
                $teamId,
                $userId,
                $this->strOrNull($this->field($row, $mapping, 'volunteer_role')) ?? 'volunteer',
                $bgStatus !== '' ? $bgStatus : 'pending',
                $this->strOrNull($bgDate),
                $this->strOrNull($startDate),
                $this->strOrNull($this->field($row, $mapping, 'notes')),
                $assignedBy
            );

            $pdo->commit();
            return $outcome;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────

    private function findTeamIdByName(PDO $pdo, string $teamName, int $clubId): ?int {
        $stmt = $pdo->prepare('
            SELECT id FROM teams
            WHERE LOWER(name) = LOWER(:name) AND club_id = :club AND deleted_at IS NULL
            LIMIT 1
        ');
        $stmt->execute(['name' => $teamName, 'club' => $clubId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['id'] : null;
    }

    private function findOrCreateUser(PDO $pdo, string $firstName, string $lastName, string $email, string $phone): int {
        // Email is the unique key on users. If a user with this email already exists,
        // reuse them — do NOT overwrite their first/last name or phone, since they
        // may have edited it themselves since whatever the importing CSV says.
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
        $stmt->execute(['email' => $email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) return (int) $existing['id'];

        // New user — password_hash stays NULL so login is disabled until they
        // claim the account via the existing signup or password reset flow.
        $insert = $pdo->prepare('
            INSERT INTO users (first_name, last_name, email, phone)
            VALUES (:first, :last, :email, :phone)
            RETURNING id
        ');
        $insert->execute([
            'first' => $firstName,
            'last'  => $lastName,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
        ]);
        return (int) $insert->fetchColumn();
    }

    private function findOrCreateTeamVolunteer(
        PDO $pdo,
        int $teamId,
        int $userId,
        string $volunteerRole,
        string $bgStatus,
        ?string $bgDate,
        ?string $startDate,
        ?string $notes,
        int $assignedBy
    ): string {
        $stmt = $pdo->prepare('SELECT id FROM team_volunteers WHERE team_id = :team AND user_id = :user LIMIT 1');
        $stmt->execute(['team' => $teamId, 'user' => $userId]);
        if ($stmt->fetch()) return 'skipped';

        $pdo->prepare("
            INSERT INTO team_volunteers
                (team_id, user_id, volunteer_role, background_check_status, background_check_date,
                 start_date, notes, assigned_by, status, self_signup)
            VALUES
                (:team, :user, :role, :bg_status, :bg_date, :start_date, :notes, :assigned_by, 'active', false)
        ")->execute([
            'team'        => $teamId,
            'user'        => $userId,
            'role'        => $volunteerRole,
            'bg_status'   => $bgStatus,
            'bg_date'     => $bgDate,
            'start_date'  => $startDate,
            'notes'       => $notes,
            'assigned_by' => $assignedBy,
        ]);
        return 'created';
    }
}
