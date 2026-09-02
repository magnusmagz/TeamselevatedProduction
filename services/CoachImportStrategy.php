<?php
require_once __DIR__ . '/ImportStrategy.php';
require_once __DIR__ . '/../lib/role_cache.php';

/**
 * CoachImportStrategy — imports coaches as users with a club-level coach role.
 *
 * A "coach" in this system is a user with a `coach` role on a club via
 * the user_club_access table. This importer creates the user (if they
 * don't exist) and the access row in one shot. Team assignment is NOT
 * handled here — coaches can be assigned to specific teams afterwards
 * via the existing team management UI.
 *
 * Users created here have password_hash=NULL (login disabled until
 * they claim the account via the existing signup or password reset
 * flow). No invite email is sent — that's a future enhancement.
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

        $firstName = $this->field($row, $mapping, 'first_name');
        $lastName  = $this->field($row, $mapping, 'last_name');
        $email     = strtolower($this->field($row, $mapping, 'email'));
        $phone     = $this->field($row, $mapping, 'phone');

        if ($firstName === '' || $lastName === '' || $email === '') {
            throw new RuntimeException('Missing required field: first_name, last_name, and email are all required');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid email '{$email}'");
        }

        $pdo->beginTransaction();
        try {
            $userId = $this->findOrCreateUser($pdo, $firstName, $lastName, $email, $phone);
            $outcome = $this->findOrCreateClubAccess($pdo, $userId, $clubId, $grantedBy);
            $pdo->commit();
            return $outcome;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────

    private function findOrCreateUser(PDO $pdo, string $firstName, string $lastName, string $email, string $phone): int {
        // Email is unique on users. Existing users are reused as-is — we do
        // NOT overwrite their first/last name or phone, since they may have
        // updated their own profile since whatever the CSV says.
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
        $stmt->execute(['email' => $email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) return (int) $existing['id'];

        // password_hash stays NULL — login disabled until the account is claimed.
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

    private function findOrCreateClubAccess(PDO $pdo, int $userId, int $clubId, int $grantedBy): string {
        // UNIQUE constraint is (user_id, club_profile_id, role). If a row already
        // exists for this user with role='coach' on this club, skip — even if
        // it's currently inactive (revoked). Re-importing should not silently
        // re-grant a revoked access.
        $stmt = $pdo->prepare("
            SELECT id FROM user_club_access
            WHERE user_id = :user AND club_profile_id = :club AND role = 'coach'
            LIMIT 1
        ");
        $stmt->execute(['user' => $userId, 'club' => $clubId]);
        if ($stmt->fetch()) return 'skipped';

        $pdo->prepare("
            INSERT INTO user_club_access (user_id, club_profile_id, role, granted_by, active)
            VALUES (:user, :club, 'coach', :granted_by, true)
        ")->execute([
            'user'        => $userId,
            'club'        => $clubId,
            'granted_by'  => $grantedBy,
        ]);
        te_role_cache_invalidate($userId);
        return 'created';
    }
}
