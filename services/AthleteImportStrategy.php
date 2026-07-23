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
            'guardian1_first_name',
            'guardian1_last_name',
            'guardian1_email',
            'guardian1_mobile',
        ];
    }

    public function getOptionalFields(): array {
        return [
            'athlete_gender',
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
            'athlete_dob'            => 'Athlete Date of Birth (YYYY-MM-DD, MM/DD/YYYY, or "May 25, 2015")',
            'athlete_gender'         => 'Athlete Gender (optional — Male/Female/Non-binary/Prefer not to say)',
            'athlete_grade_level'    => 'Grade Level (number, "6th", "Kindergarten", or "Pre-K")',
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
        $athleteDobRaw = $this->field($row, $mapping, 'athlete_dob');

        // Fully-blank rows are a common trailing artifact of registration-platform
        // (e.g. GotSport) CSV exports — skip silently instead of erroring on each.
        if ($athleteFirst === '' && $athleteLast === '' && $athleteDobRaw === '') {
            return 'skipped';
        }
        if ($athleteFirst === '' || $athleteLast === '' || $athleteDobRaw === '') {
            throw new RuntimeException('Missing athlete first_name, last_name, or dob');
        }

        // DOB: accept common human formats (GotSport exports "May 25, 2015").
        $athleteDob = $this->normalizeDob($athleteDobRaw);
        if ($athleteDob === null) {
            throw new RuntimeException("Invalid athlete_dob '{$athleteDobRaw}' — use YYYY-MM-DD, MM/DD/YYYY, or a date like 'May 25, 2015'");
        }
        // Gender is optional (blank -> "Prefer not to say"); common variants normalized.
        $athleteGender = $this->normalizeGender($this->field($row, $mapping, 'athlete_gender'));

        $pdo->beginTransaction();
        try {
            $athleteResult = $this->upsertAthlete(
                $pdo,
                $athleteFirst,
                $athleteLast,
                $athleteDob,
                $athleteGender,
                $this->normalizeGrade($this->field($row, $mapping, 'athlete_grade_level')),
                $this->strOrNull($this->field($row, $mapping, 'athlete_school')),
                $clubId,
                $createdBy
            );
            $athleteId  = $athleteResult['id'];
            $rowOutcome = $athleteResult['outcome'];

            for ($n = 1; $n <= 2; $n++) {
                $gFirst  = $this->field($row, $mapping, "guardian{$n}_first_name");
                $gLast   = $this->field($row, $mapping, "guardian{$n}_last_name");
                $gEmail  = $this->field($row, $mapping, "guardian{$n}_email");
                $gMobile = $this->field($row, $mapping, "guardian{$n}_mobile");

                // Absent guardian block — nothing at all in it.
                if ($gFirst === '' && $gLast === '' && $gEmail === '' && $gMobile === '') continue;

                // A name is required (first/last are NOT NULL) but EMAIL IS OPTIONAL:
                // GotSport lists co-parents with a phone and no email. Keep them —
                // stored with an empty email, deduped by (email, first, last).
                if ($gFirst === '' || $gLast === '') {
                    throw new RuntimeException("guardian{$n} needs a first and last name");
                }

                $guardianId = $this->upsertGuardian($pdo, [
                    'first_name'   => $gFirst,
                    'last_name'    => $gLast,
                    'email'        => $gEmail,
                    'mobile_phone' => $gMobile,
                ]);

                $relationship = $this->normalizeRelationship($this->field($row, $mapping, "guardian{$n}_relationship"));
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

    // ─────────────────────────────────────────────────────────────
    // Normalizers — tolerant of real-world (GotSport) export formats.

    /**
     * Parse a date of birth in any common human format and return it as
     * YYYY-MM-DD, or null if it can't be understood. Slash formats are read
     * US-style (month first). Rejects overflow dates (e.g. 13/40/2020).
     */
    private function normalizeDob(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;
        $formats = [
            'Y-m-d', 'Y/m/d',
            'n/j/Y', 'm/d/Y', 'n-j-Y', 'm-d-Y',
            'M j, Y', 'F j, Y', 'M j Y', 'F j Y',
            'j M Y', 'j F Y', 'j-M-Y', 'j-F-Y',
        ];
        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat('!' . $fmt, $raw);
            if ($dt === false) continue;
            $errors = DateTime::getLastErrors();
            $clean = ($errors === false)
                || ((($errors['warning_count'] ?? 0) === 0) && (($errors['error_count'] ?? 0) === 0));
            if (!$clean) continue;
            $year = (int) $dt->format('Y');
            if ($year >= 1900 && $year <= 2100) {
                return $dt->format('Y-m-d');
            }
        }
        return null;
    }

    /**
     * Map a free-text gender to one of the four allowed values. Blank or
     * unrecognized -> "Prefer not to say" (gender is optional; never fail a row).
     */
    private function normalizeGender(string $raw): string {
        $s = strtolower(trim($raw));
        if ($s === '') return 'Prefer not to say';
        $map = [
            'm' => 'Male', 'male' => 'Male', 'boy' => 'Male', 'b' => 'Male',
            'f' => 'Female', 'female' => 'Female', 'girl' => 'Female', 'g' => 'Female',
            'nb' => 'Non-binary', 'non-binary' => 'Non-binary', 'nonbinary' => 'Non-binary',
            'other' => 'Non-binary', 'x' => 'Non-binary',
            'prefer not to say' => 'Prefer not to say', 'prefernottosay' => 'Prefer not to say',
            'na' => 'Prefer not to say', 'n/a' => 'Prefer not to say',
        ];
        if (isset($map[$s])) return $map[$s];
        foreach (self::$ALLOWED_GENDERS as $g) {
            if (strtolower($g) === $s) return $g;
        }
        return 'Prefer not to say';
    }

    /**
     * Convert a grade level (number, ordinal like "6th", or text like
     * "Kindergarten"/"Pre-K") to the integer grade_level column.
     * Pre-K = -1, Kindergarten = 0, grades 1-12 = 1..12, unknown = null.
     */
    private function normalizeGrade(string $raw): ?int {
        $s = strtolower(trim($raw));
        if ($s === '') return null;
        if (in_array($s, ['prek', 'pre-k', 'pre k', 'pk', 'preschool'], true)) return -1;
        if (in_array($s, ['k', 'kg', 'kinder', 'kindergarten'], true)) return 0;
        if (preg_match('/(\d{1,2})/', $s, $m)) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= 12) return $n;
        }
        return null;
    }

    /**
     * Map a free-text relationship to one of the allowed values. Unknown or
     * blank -> "Guardian" (the safe default).
     */
    private function normalizeRelationship(string $raw): string {
        $s = strtolower(trim($raw));
        if ($s === '') return 'Guardian';
        $map = [
            'parent' => 'Parent', 'mother' => 'Parent', 'father' => 'Parent',
            'mom' => 'Parent', 'dad' => 'Parent', 'mum' => 'Parent',
            'guardian' => 'Guardian', 'legal guardian' => 'Guardian',
            'grandparent' => 'Guardian', 'grandmother' => 'Guardian', 'grandfather' => 'Guardian',
            'grandma' => 'Guardian', 'grandpa' => 'Guardian', 'aunt' => 'Guardian', 'uncle' => 'Guardian',
            'emergency contact' => 'Emergency Contact', 'emergency' => 'Emergency Contact',
            'other' => 'Other',
        ];
        if (isset($map[$s])) return $map[$s];
        foreach (self::$ALLOWED_RELATIONSHIPS as $r) {
            if (strtolower($r) === $s) return $r;
        }
        return 'Guardian';
    }
}
