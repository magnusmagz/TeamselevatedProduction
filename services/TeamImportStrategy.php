<?php
require_once __DIR__ . '/ImportStrategy.php';

/**
 * TeamImportStrategy — imports teams into a club.
 *
 * Identity: a team is (name, age_group) within a club. A club can
 * legitimately have "Tigers U12" and "Tigers U14" as distinct teams,
 * so the match key is (LOWER(name), age_group, club_id).
 *
 * Required: name, age_group. Everything else is optional.
 *
 * Foreign keys (season, primary coach, home field, program) are
 * resolved by name/email at import time. If a value is provided but
 * the target record can't be found, the row errors with a clear
 * message — the importer never auto-creates seasons or coaches.
 */

class TeamImportStrategy extends ImportStrategy {
    // From TeamController::validateTeamData — canonical age group list
    private static $ALLOWED_AGE_GROUPS = ['U6', 'U8', 'U10', 'U12', 'U14', 'U16', 'U18', 'Adult'];

    // From the teams CHECK constraints
    private static $ALLOWED_SKILL_LEVELS = ['Beginner', 'Intermediate', 'Advanced', 'Elite'];
    private static $ALLOWED_GENDERS      = ['Male', 'Female', 'Mixed'];
    private static $ALLOWED_STATUSES     = ['forming', 'active', 'completed', 'disbanded'];

    public function getEntityType(): string {
        return 'teams';
    }

    public function getRequiredFields(): array {
        return [
            'name',
            'age_group',
        ];
    }

    public function getOptionalFields(): array {
        return [
            'division',
            'skill_level',
            'gender',
            'status',
            'max_players',
            'team_color',
            'season_name',
            'program_name',
            'primary_coach_email',
            'home_venue_name',
            'home_field_name',
        ];
    }

    public function getFieldLabels(): array {
        return [
            'name'                => 'Team Name (e.g. Tigers)',
            'age_group'           => 'Age Group (U6/U8/U10/U12/U14/U16/U18/Adult)',
            'division'            => 'Division (e.g. Recreational, Competitive)',
            'skill_level'         => 'Skill Level (Beginner/Intermediate/Advanced/Elite)',
            'gender'              => 'Gender (Male/Female/Mixed)',
            'status'              => 'Status (forming/active/completed/disbanded)',
            'max_players'         => 'Max Players',
            'team_color'          => 'Team Color (#hex)',
            'season_name'         => 'Season Name (must already exist)',
            'program_name'        => 'Program Name (must already exist)',
            'primary_coach_email' => 'Primary Coach Email (must already exist as a user)',
            'home_venue_name'     => 'Home Venue Name (must already exist)',
            'home_field_name'     => 'Home Field Name (within the venue)',
        ];
    }

    public function getSynonyms(): array {
        return [
            'name'                => ['name', 'teamname', 'team'],
            'age_group'           => ['agegroup', 'age', 'division', 'agedivision'],
            'division'            => ['division', 'level', 'competitivelevel'],
            'skill_level'         => ['skilllevel', 'skill', 'tier'],
            'gender'              => ['gender', 'sex'],
            'status'              => ['status'],
            'max_players'         => ['maxplayers', 'rosterlimit', 'capacity'],
            'team_color'          => ['teamcolor', 'color', 'colour'],
            'season_name'         => ['seasonname', 'season'],
            'program_name'        => ['programname', 'program'],
            'primary_coach_email' => ['primarycoachemail', 'headcoachemail', 'coachemail'],
            'home_venue_name'     => ['homevenuename', 'venue', 'venuename', 'homevenue'],
            'home_field_name'     => ['homefieldname', 'field', 'fieldname', 'homefield'],
        ];
    }

    public function processRow(array $row, array $mapping, array $context): string {
        /** @var PDO $pdo */
        $pdo    = $context['pdo'];
        $clubId = (int) $context['club_id'];

        $name     = $this->field($row, $mapping, 'name');
        $ageGroup = $this->field($row, $mapping, 'age_group');

        if ($name === '' || $ageGroup === '') {
            throw new RuntimeException('Missing required field: name and age_group are both required');
        }

        if (!in_array($ageGroup, self::$ALLOWED_AGE_GROUPS, true)) {
            throw new RuntimeException("Invalid age_group '{$ageGroup}' — must be one of: " . implode(', ', self::$ALLOWED_AGE_GROUPS));
        }

        // Validate optional enums before opening a transaction.
        $skillLevel = $this->field($row, $mapping, 'skill_level');
        if ($skillLevel !== '' && !in_array($skillLevel, self::$ALLOWED_SKILL_LEVELS, true)) {
            throw new RuntimeException("Invalid skill_level '{$skillLevel}' — must be one of: " . implode(', ', self::$ALLOWED_SKILL_LEVELS));
        }

        $gender = $this->field($row, $mapping, 'gender');
        if ($gender !== '' && !in_array($gender, self::$ALLOWED_GENDERS, true)) {
            throw new RuntimeException("Invalid gender '{$gender}' — must be one of: " . implode(', ', self::$ALLOWED_GENDERS));
        }

        $status = $this->field($row, $mapping, 'status');
        if ($status !== '' && !in_array($status, self::$ALLOWED_STATUSES, true)) {
            throw new RuntimeException("Invalid status '{$status}' — must be one of: " . implode(', ', self::$ALLOWED_STATUSES));
        }

        $pdo->beginTransaction();
        try {
            // Idempotency: (LOWER(name), age_group, club_id) defines team identity.
            $existing = $pdo->prepare('
                SELECT id FROM teams
                WHERE LOWER(name) = LOWER(:name) AND age_group = :age AND club_id = :club AND deleted_at IS NULL
                LIMIT 1
            ');
            $existing->execute(['name' => $name, 'age' => $ageGroup, 'club' => $clubId]);
            if ($existing->fetch()) {
                $pdo->commit();
                return 'skipped';
            }

            // Resolve optional FK lookups. Each one is strict: provided but not found → error the row.
            $seasonId       = $this->lookupSeasonId($pdo, $this->field($row, $mapping, 'season_name'));
            $programId      = $this->lookupProgramId($pdo, $this->field($row, $mapping, 'program_name'), $clubId);
            $primaryCoachId = $this->lookupCoachId($pdo, $this->field($row, $mapping, 'primary_coach_email'));
            $homeFieldId    = $this->lookupFieldId(
                $pdo,
                $this->field($row, $mapping, 'home_venue_name'),
                $this->field($row, $mapping, 'home_field_name')
            );

            // Insert — everything optional is nullable or has a DB default.
            $insert = $pdo->prepare('
                INSERT INTO teams
                    (name, age_group, division, skill_level, gender, status,
                     max_players, team_color, season_id, program_id, primary_coach_id, home_field_id, club_id)
                VALUES
                    (:name, :age, :division, :skill, :gender, :status,
                     :max_players, :color, :season, :program, :coach, :home_field, :club)
            ');
            $insert->execute([
                'name'       => $name,
                'age'        => $ageGroup,
                'division'   => $this->strOrNull($this->field($row, $mapping, 'division')),
                'skill'      => $skillLevel !== '' ? $skillLevel : 'Beginner',
                'gender'     => $gender !== '' ? $gender : 'Mixed',
                'status'     => $status !== '' ? $status : 'forming',
                'max_players' => $this->intOrNull($this->field($row, $mapping, 'max_players')),
                'color'      => $this->strOrNull($this->field($row, $mapping, 'team_color')),
                'season'     => $seasonId,
                'program'    => $programId,
                'coach'      => $primaryCoachId,
                'home_field' => $homeFieldId,
                'club'       => $clubId,
            ]);

            $pdo->commit();
            return 'created';
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────

    private function lookupSeasonId(PDO $pdo, string $seasonName): ?int {
        if ($seasonName === '') return null;
        $stmt = $pdo->prepare('SELECT id FROM seasons WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $stmt->execute(['name' => $seasonName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("Season '{$seasonName}' not found");
        return (int) $row['id'];
    }

    private function lookupProgramId(PDO $pdo, string $programName, int $clubId): ?int {
        if ($programName === '') return null;
        // Programs may or may not be club-scoped depending on schema. Try club-scoped first.
        $stmt = $pdo->prepare('SELECT id FROM programs WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $stmt->execute(['name' => $programName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("Program '{$programName}' not found");
        return (int) $row['id'];
    }

    private function lookupCoachId(PDO $pdo, string $coachEmail): ?int {
        if ($coachEmail === '') return null;
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
        $stmt->execute(['email' => $coachEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("Primary coach user with email '{$coachEmail}' not found — import coaches first, or create the user account");
        return (int) $row['id'];
    }

    private function lookupFieldId(PDO $pdo, string $venueName, string $fieldName): ?int {
        // Both must be provided together or both omitted. Partial → error.
        if ($venueName === '' && $fieldName === '') return null;
        if ($venueName === '' || $fieldName === '') {
            throw new RuntimeException('home_venue_name and home_field_name must be provided together');
        }

        $stmt = $pdo->prepare('
            SELECT f.id
            FROM fields f
            JOIN venues v ON v.id = f.venue_id
            WHERE LOWER(v.name) = LOWER(:venue) AND LOWER(f.name) = LOWER(:field)
            LIMIT 1
        ');
        $stmt->execute(['venue' => $venueName, 'field' => $fieldName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("Field '{$fieldName}' at venue '{$venueName}' not found — import facilities first");
        return (int) $row['id'];
    }
}
