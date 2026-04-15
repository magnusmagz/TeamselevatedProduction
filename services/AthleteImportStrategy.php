<?php
require_once __DIR__ . '/ImportStrategy.php';

/**
 * AthleteImportStrategy — imports athletes + guardians from a family-row CSV.
 *
 * One row = one athlete + up to two guardians. Athletes match on
 * (first_name + last_name + date_of_birth + club_id). Guardians match on
 * (email + first_name + last_name) to support families sharing a household
 * email. Existing athletes are skipped on re-import (idempotent).
 */

class AthleteImportStrategy extends ImportStrategy {
    private static $ALLOWED_GENDERS = ['Male', 'Female', 'Non-binary', 'Prefer not to say'];
    private static $ALLOWED_RELATIONSHIPS = ['Parent', 'Guardian', 'Emergency Contact', 'Other'];

    public function getEntityType(): string {
        return 'athletes';
    }

    public function requiresUploadTeamId(): bool {
        // Athletes can be optionally added to a single team's roster at upload time
        // via the wizard's team picker. Coaches are required to pick one so the
        // gateway's coach-team enforcement fires for this strategy.
        return true;
    }

    public function getRequiredFields(): array {
        return [
            'athlete_first_name',
            'athlete_last_name',
            'athlete_dob',
            'athlete_gender',
            'guardian1_first_name',
            'guardian1_last_name',
            'guardian1_email',
            'guardian1_mobile',
        ];
    }

    public function getOptionalFields(): array {
        return [
            'athlete_grade_level',
            'athlete_school',
            'guardian1_relationship',
            'guardian1_is_primary',
            'guardian2_first_name',
            'guardian2_last_name',
            'guardian2_email',
            'guardian2_mobile',
            'guardian2_relationship',
        ];
    }

    public function getFieldLabels(): array {
        return [
            'athlete_first_name'     => 'Athlete First Name',
            'athlete_last_name'      => 'Athlete Last Name',
            'athlete_dob'            => 'Athlete Date of Birth (YYYY-MM-DD)',
            'athlete_gender'         => 'Athlete Gender (Male/Female/Non-binary/Prefer not to say)',
            'athlete_grade_level'    => 'Grade Level',
            'athlete_school'         => 'School',
            'guardian1_first_name'   => 'Guardian 1 First Name',
            'guardian1_last_name'    => 'Guardian 1 Last Name',
            'guardian1_email'        => 'Guardian 1 Email',
            'guardian1_mobile'       => 'Guardian 1 Mobile',
            'guardian1_relationship' => 'Guardian 1 Relationship',
            'guardian1_is_primary'   => 'Guardian 1 Is Primary',
            'guardian2_first_name'   => 'Guardian 2 First Name',
            'guardian2_last_name'    => 'Guardian 2 Last Name',
            'guardian2_email'        => 'Guardian 2 Email',
            'guardian2_mobile'       => 'Guardian 2 Mobile',
            'guardian2_relationship' => 'Guardian 2 Relationship',
        ];
    }

    public function getSynonyms(): array {
        return [
            'athlete_first_name'     => ['athletefirstname', 'playerfirstname', 'childfirstname', 'firstname', 'first', 'givenname'],
            'athlete_last_name'      => ['athletelastname', 'playerlastname', 'childlastname', 'lastname', 'last', 'surname', 'familyname'],
            'athlete_dob'            => ['athletedob', 'dob', 'dateofbirth', 'birthdate', 'birthday'],
            'athlete_gender'         => ['athletegender', 'gender', 'sex'],
            'athlete_grade_level'    => ['athletegradelevel', 'gradelevel', 'grade'],
            'athlete_school'         => ['athleteschool', 'schoolname', 'school'],
            'guardian1_first_name'   => ['guardian1firstname', 'parent1firstname', 'parentfirstname', 'guardianfirstname', 'primaryparentfirstname'],
            'guardian1_last_name'    => ['guardian1lastname', 'parent1lastname', 'parentlastname', 'guardianlastname', 'primaryparentlastname'],
            'guardian1_email'        => ['guardian1email', 'parent1email', 'parentemail', 'guardianemail', 'primaryparentemail', 'email'],
            'guardian1_mobile'       => ['guardian1mobile', 'parent1mobile', 'parent1phone', 'parentmobile', 'parentphone', 'guardianmobile', 'guardianphone', 'mobile', 'phone', 'cell'],
            'guardian1_relationship' => ['guardian1relationship', 'parent1relationship', 'relationship'],
            'guardian1_is_primary'   => ['guardian1isprimary', 'guardian1primary', 'isprimary', 'primarycontact'],
            'guardian2_first_name'   => ['guardian2firstname', 'parent2firstname', 'secondaryparentfirstname'],
            'guardian2_last_name'    => ['guardian2lastname', 'parent2lastname', 'secondaryparentlastname'],
            'guardian2_email'        => ['guardian2email', 'parent2email', 'secondaryparentemail'],
            'guardian2_mobile'       => ['guardian2mobile', 'parent2mobile', 'parent2phone', 'secondaryparentmobile'],
            'guardian2_relationship' => ['guardian2relationship', 'parent2relationship'],
        ];
    }

    public function processRow(array $row, array $mapping, array $context): string {
        /** @var PDO $pdo */
        $pdo       = $context['pdo'];
        $clubId    = (int) $context['club_id'];
        $teamId    = isset($context['team_id']) && $context['team_id'] !== null ? (int) $context['team_id'] : null;
        $createdBy = (int) $context['user_id'];

        $athleteFirst  = $this->field($row, $mapping, 'athlete_first_name');
        $athleteLast   = $this->field($row, $mapping, 'athlete_last_name');
        $athleteDob    = $this->field($row, $mapping, 'athlete_dob');
        $athleteGender = $this->field($row, $mapping, 'athlete_gender');

        if ($athleteFirst === '' || $athleteLast === '' || $athleteDob === '') {
            throw new RuntimeException('Missing athlete first_name, last_name, or dob');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $athleteDob)) {
            throw new RuntimeException("Invalid athlete_dob '{$athleteDob}' — must be YYYY-MM-DD");
        }
        if (!in_array($athleteGender, self::$ALLOWED_GENDERS, true)) {
            throw new RuntimeException("Invalid athlete_gender '{$athleteGender}' — must be one of: " . implode(', ', self::$ALLOWED_GENDERS));
        }

        $pdo->beginTransaction();
        try {
            $athleteResult = $this->upsertAthlete(
                $pdo,
                $athleteFirst,
                $athleteLast,
                $athleteDob,
                $athleteGender,
                $this->intOrNull($this->field($row, $mapping, 'athlete_grade_level')),
                $this->strOrNull($this->field($row, $mapping, 'athlete_school')),
                $clubId,
                $createdBy
            );
            $athleteId  = $athleteResult['id'];
            $rowOutcome = $athleteResult['outcome'];

            for ($n = 1; $n <= 2; $n++) {
                $gFirst = $this->field($row, $mapping, "guardian{$n}_first_name");
                $gLast  = $this->field($row, $mapping, "guardian{$n}_last_name");
                $gEmail = $this->field($row, $mapping, "guardian{$n}_email");
                if ($gFirst === '' && $gLast === '' && $gEmail === '') continue;
                if ($gFirst === '' || $gLast === '' || $gEmail === '') {
                    throw new RuntimeException("guardian{$n} requires first_name, last_name, and email");
                }

                $guardianId = $this->upsertGuardian($pdo, [
                    'first_name'   => $gFirst,
                    'last_name'    => $gLast,
                    'email'        => $gEmail,
                    'mobile_phone' => $this->field($row, $mapping, "guardian{$n}_mobile"),
                ]);

                $relationship = $this->field($row, $mapping, "guardian{$n}_relationship");
                if ($relationship === '' || !in_array($relationship, self::$ALLOWED_RELATIONSHIPS, true)) {
                    $relationship = 'Guardian';
                }
                $primaryRaw = $this->field($row, $mapping, "guardian{$n}_is_primary");
                $isPrimary  = $primaryRaw !== '' ? $this->parseBool($primaryRaw) : ($n === 1);

                $this->upsertAthleteGuardianLink($pdo, $athleteId, $guardianId, $relationship, $isPrimary);
            }

            if ($teamId !== null) {
                $this->ensureTeamMembership($pdo, $athleteId, $teamId);
            }

            $pdo->commit();
            return $rowOutcome;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────

    private function upsertAthlete(
        PDO $pdo,
        string $firstName,
        string $lastName,
        string $dob,
        string $gender,
        ?int $gradeLevel,
        ?string $school,
        int $clubId,
        int $createdBy
    ): array {
        $stmt = $pdo->prepare('
            SELECT id FROM athletes
            WHERE first_name = :first AND last_name = :last AND date_of_birth = :dob AND club_id = :club
        ');
        $stmt->execute([
            'first' => $firstName,
            'last'  => $lastName,
            'dob'   => $dob,
            'club'  => $clubId,
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            return ['id' => (int) $existing['id'], 'outcome' => 'skipped'];
        }

        $insert = $pdo->prepare('
            INSERT INTO athletes
                (first_name, last_name, date_of_birth, gender, grade_level, school_name, club_id, created_by, active_status)
            VALUES
                (:first, :last, :dob, :gender, :grade, :school, :club, :created_by, true)
            RETURNING id
        ');
        $insert->execute([
            'first'      => $firstName,
            'last'       => $lastName,
            'dob'        => $dob,
            'gender'     => $gender,
            'grade'      => $gradeLevel,
            'school'     => $school,
            'club'       => $clubId,
            'created_by' => $createdBy,
        ]);
        return ['id' => (int) $insert->fetchColumn(), 'outcome' => 'created'];
    }

    private function upsertGuardian(PDO $pdo, array $g): int {
        $stmt = $pdo->prepare('
            SELECT id FROM guardians
            WHERE email = :email AND first_name = :first AND last_name = :last
            LIMIT 1
        ');
        $stmt->execute([
            'email' => $g['email'],
            'first' => $g['first_name'],
            'last'  => $g['last_name'],
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) return (int) $existing['id'];

        $mobile = $g['mobile_phone'] !== '' ? $g['mobile_phone'] : 'unknown';
        $insert = $pdo->prepare('
            INSERT INTO guardians (first_name, last_name, email, mobile_phone)
            VALUES (:first, :last, :email, :mobile)
            RETURNING id
        ');
        $insert->execute([
            'first'  => $g['first_name'],
            'last'   => $g['last_name'],
            'email'  => $g['email'],
            'mobile' => $mobile,
        ]);
        return (int) $insert->fetchColumn();
    }

    private function upsertAthleteGuardianLink(PDO $pdo, int $athleteId, int $guardianId, string $relationship, bool $isPrimary): void {
        $stmt = $pdo->prepare('
            SELECT id FROM athlete_guardians WHERE athlete_id = :a AND guardian_id = :g LIMIT 1
        ');
        $stmt->execute(['a' => $athleteId, 'g' => $guardianId]);
        if ($stmt->fetch()) return;

        $pdo->prepare('
            INSERT INTO athlete_guardians (athlete_id, guardian_id, relationship, is_primary)
            VALUES (:a, :g, :rel, :primary)
        ')->execute([
            'a'       => $athleteId,
            'g'       => $guardianId,
            'rel'     => $relationship,
            'primary' => $isPrimary ? 'true' : 'false',
        ]);
    }

    private function ensureTeamMembership(PDO $pdo, int $athleteId, int $teamId): void {
        $stmt = $pdo->prepare('
            SELECT id FROM team_members WHERE team_id = :t AND athlete_id = :a LIMIT 1
        ');
        $stmt->execute(['t' => $teamId, 'a' => $athleteId]);
        if ($stmt->fetch()) return;

        $pdo->prepare("
            INSERT INTO team_members (team_id, athlete_id, role, status, join_date)
            VALUES (:t, :a, 'player', 'active', CURRENT_DATE)
        ")->execute(['t' => $teamId, 'a' => $athleteId]);
    }
}
