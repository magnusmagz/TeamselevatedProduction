<?php
class Coach {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getCoachTeams($coachId) {
        $sql = "SELECT t.*, s.name as season_name, f.name as home_field_name,
                (SELECT COUNT(*) FROM team_members tm
                 WHERE tm.team_id = t.id AND tm.role = 'player'
                 AND tm.leave_date IS NULL AND tm.team_priority IN ('primary', 'secondary')) as player_count,
                (SELECT COUNT(*) FROM team_members tm
                 WHERE tm.team_id = t.id AND tm.role = 'player'
                 AND tm.leave_date IS NULL AND tm.team_priority = 'guest') as guest_count,
                CASE WHEN t.primary_coach_id = :coach_id THEN 'Head Coach' ELSE 'Assistant Coach' END as coach_role,
                (SELECT MIN(ce.event_date::timestamp + COALESCE(ce.start_time, '00:00:00'::time))
                 FROM calendar_events ce
                 JOIN calendar_event_teams cet ON cet.event_id = ce.id
                 WHERE cet.team_id = t.id
                   AND ce.event_date >= CURRENT_DATE
                   AND (ce.status IS NULL OR ce.status != 'cancelled')) as next_event
                FROM teams t
                LEFT JOIN seasons s ON t.season_id = s.id
                LEFT JOIN fields f ON t.home_field_id = f.id
                WHERE (t.primary_coach_id = :coach_id2
                    OR EXISTS (SELECT 1 FROM team_members tm2
                               WHERE tm2.team_id = t.id AND tm2.user_id = :coach_id3
                               AND tm2.role = 'assistant_coach'))
                AND t.deleted_at IS NULL
                ORDER BY s.start_date DESC, t.name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':coach_id' => $coachId,
            ':coach_id2' => $coachId,
            ':coach_id3' => $coachId
        ]);
        return $stmt->fetchAll();
    }

    public function getTeamRoster($teamId) {
        // Rosters are athlete-based: join athletes on tm.athlete_id, NOT users.
        // The roster UI reads id (= team_members.id), athlete_id, first_name,
        // last_name, date_of_birth, gender, grade_level, primary_position,
        // positions (JSON array) and jersey_number.
        $sql = "SELECT tm.id, tm.athlete_id, tm.team_id, tm.role,
                       tm.positions, tm.primary_position, tm.jersey_number,
                       tm.jersey_number_alt, tm.team_priority, tm.status,
                       tm.join_date, tm.leave_date,
                       a.first_name, a.last_name, a.email, a.phone,
                       a.date_of_birth, a.gender, a.grade_level
                FROM team_members tm
                JOIN athletes a ON tm.athlete_id = a.id
                WHERE tm.team_id = :team_id
                AND tm.role = 'player'
                AND tm.leave_date IS NULL
                AND a.active_status = true
                ORDER BY tm.jersey_number, a.last_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':team_id' => $teamId]);

        $roster = $stmt->fetchAll();

        foreach ($roster as &$player) {
            // positions is jsonb; PDO returns it as a string — decode to array.
            $player['positions'] = json_decode($player['positions'] ?? '', true) ?? [];
            $player['name'] = $player['first_name'] . ' ' . $player['last_name'];

            $otherTeams = $this->getPlayerOtherTeams($player['athlete_id'], $teamId);
            $player['other_teams'] = $otherTeams;
        }

        return $roster;
    }

    public function addPlayerToTeam($teamId, $data) {
        $this->db->beginTransaction();

        try {
            // Players are athletes, not users. Accept athlete_id (UI sends
            // player_id as an alias).
            $athleteId = $data['athlete_id'] ?? $data['player_id'] ?? null;
            if (empty($athleteId)) {
                throw new Exception('athlete_id is required');
            }

            $existingCheck = "SELECT id FROM team_members
                            WHERE team_id = :team_id AND athlete_id = :athlete_id
                            AND leave_date IS NULL";
            $stmt = $this->db->prepare($existingCheck);
            $stmt->execute([':team_id' => $teamId, ':athlete_id' => $athleteId]);

            if ($stmt->fetch()) {
                throw new Exception('Player is already on this team');
            }

            // Postgres needs RETURNING to get the new id; team_members has no
            // guest_player_agreement_id column, so it is not inserted here.
            $sql = "INSERT INTO team_members
                    (team_id, athlete_id, role, jersey_number, jersey_number_alt, positions,
                     primary_position, team_priority, status, join_date)
                    VALUES (:team_id, :athlete_id, 'player', :jersey, :jersey_alt, :positions,
                            :primary_pos, :priority, :status, CURRENT_DATE)
                    RETURNING id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':team_id' => $teamId,
                ':athlete_id' => $athleteId,
                ':jersey' => $data['jersey_number'] ?? null,
                ':jersey_alt' => $data['jersey_number_alt'] ?? null,
                ':positions' => json_encode($data['positions'] ?? []),
                ':primary_pos' => $data['primary_position'] ?? null,
                ':priority' => $data['team_priority'] ?? 'primary',
                ':status' => $data['status'] ?? 'active'
            ]);

            $teamMemberId = $stmt->fetchColumn();

            $this->db->commit();
            return $teamMemberId;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('Coach::addPlayerToTeam failed: ' . $e->getMessage());
            return false;
        }
    }

    public function updatePlayerPositions($teamId, $playerId, $data) {
        // Decision: the roster route passes the roster identifier the UI holds.
        // getTeamRoster returns athlete_id (and id = team_members.id), so we
        // match the row by athlete_id to stay athlete-based and consistent.
        // (player_position_assignments / roster_change_log tables do not exist
        // in the Postgres schema, so those side writes are dropped.)
        $sql = "UPDATE team_members
                SET positions = :positions, primary_position = :primary_pos,
                    jersey_number = :jersey, jersey_number_alt = :jersey_alt
                WHERE team_id = :team_id AND athlete_id = :athlete_id
                AND leave_date IS NULL";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':positions' => json_encode($data['positions'] ?? []),
            ':primary_pos' => $data['primary_position'] ?? null,
            ':jersey' => $data['jersey_number'] ?? null,
            ':jersey_alt' => $data['jersey_number_alt'] ?? null,
            ':team_id' => $teamId,
            ':athlete_id' => $playerId
        ]);
    }

    public function removePlayerFromTeam($teamId, $playerId, $reason) {
        // Athlete-based: match on athlete_id (the roster identifier the UI holds),
        // consistent with getTeamRoster/updatePlayerPositions. team_members has no
        // removed_by column in the Postgres schema, so it is not written.
        $sql = "UPDATE team_members
                SET leave_date = CURRENT_DATE, leave_reason = :reason
                WHERE team_id = :team_id AND athlete_id = :athlete_id
                AND leave_date IS NULL";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':reason' => $reason,
            ':team_id' => $teamId,
            ':athlete_id' => $playerId
        ]);
    }

    public function getPositionReport($teamId) {
        $sql = "SELECT tm.id, tm.athlete_id, a.first_name, a.last_name,
                tm.positions, tm.primary_position, tm.team_priority, tm.status
                FROM team_members tm
                JOIN athletes a ON tm.athlete_id = a.id
                WHERE tm.team_id = :team_id
                AND tm.role = 'player'
                AND tm.leave_date IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':team_id' => $teamId]);

        $players = $stmt->fetchAll();
        $positionMap = [];

        foreach ($players as $player) {
            $positions = json_decode($player['positions'] ?? '', true) ?? [];
            $playerName = $player['first_name'] . ' ' . $player['last_name'];

            foreach ($positions as $position) {
                if (!isset($positionMap[$position])) {
                    $positionMap[$position] = [
                        'position' => $position,
                        'primary_players' => [],
                        'secondary_players' => [],
                        'guest_players' => []
                    ];
                }

                $playerInfo = [
                    'id' => $player['athlete_id'],
                    'name' => $playerName,
                    'is_primary' => $player['primary_position'] === $position,
                    'status' => $player['status']
                ];

                if ($player['team_priority'] === 'guest') {
                    $positionMap[$position]['guest_players'][] = $playerInfo;
                } elseif ($player['primary_position'] === $position) {
                    $positionMap[$position]['primary_players'][] = $playerInfo;
                } else {
                    $positionMap[$position]['secondary_players'][] = $playerInfo;
                }
            }
        }

        $minimumPerPosition = 2;
        $positionsNeedingCoverage = [];
        foreach ($positionMap as $position => $data) {
            $total = count($data['primary_players']) + count($data['secondary_players']);
            if ($total < $minimumPerPosition) {
                $positionsNeedingCoverage[] = [
                    'position' => $position,
                    'current' => $total,
                    'needed' => $minimumPerPosition - $total
                ];
            }
        }

        return [
            'position_map' => $positionMap,
            'positions_needing_coverage' => $positionsNeedingCoverage
        ];
    }

    public function getJerseyReport($teamId) {
        // player_position_assignments does not exist in the Postgres schema;
        // derive jersey/position from team_members + athletes instead.
        $sql = "SELECT tm.jersey_number, tm.primary_position AS position,
                a.first_name, a.last_name, tm.team_priority, tm.join_date, tm.leave_date
                FROM team_members tm
                JOIN athletes a ON tm.athlete_id = a.id
                WHERE tm.team_id = :team_id
                AND tm.role = 'player'
                AND tm.leave_date IS NULL
                AND tm.jersey_number IS NOT NULL
                ORDER BY tm.jersey_number, tm.primary_position";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':team_id' => $teamId]);

        $assignments = $stmt->fetchAll();
        $jerseyMap = [];
        $conflicts = [];

        foreach ($assignments as $assignment) {
            $number = $assignment['jersey_number'];
            if (!isset($jerseyMap[$number])) {
                $jerseyMap[$number] = [];
            }

            $jerseyMap[$number][] = [
                'player' => $assignment['first_name'] . ' ' . $assignment['last_name'],
                'position' => $assignment['position'],
                'priority' => $assignment['team_priority']
            ];
        }

        foreach ($jerseyMap as $number => $players) {
            $positionGroups = [];
            foreach ($players as $player) {
                $position = $player['position'];
                if (!isset($positionGroups[$position])) {
                    $positionGroups[$position] = [];
                }
                $positionGroups[$position][] = $player;
            }

            foreach ($positionGroups as $position => $positionPlayers) {
                if (count($positionPlayers) > 1) {
                    $conflicts[] = [
                        'number' => $number,
                        'position' => $position,
                        'players' => $positionPlayers
                    ];
                }
            }
        }

        $allNumbers = range(0, 99);
        $usedNumbers = array_keys($jerseyMap);
        $availableNumbers = array_values(array_diff($allNumbers, $usedNumbers));

        return [
            'jersey_map' => $jerseyMap,
            'available_numbers' => $availableNumbers,
            'conflicts' => $conflicts
        ];
    }

    public function addGuestPlayer($teamId, $data) {
        $this->db->beginTransaction();

        try {
            $data['team_priority'] = 'guest';
            $data['leave_date'] = $data['valid_until'] ?? null;

            $teamMemberId = $this->addPlayerToTeam($teamId, $data);

            if (!empty($data['specific_games'])) {
                foreach ($data['specific_games'] as $gameId) {
                    $sql = "INSERT INTO guest_player_games (team_member_id, game_id)
                            VALUES (:tm_id, :game_id)";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([':tm_id' => $teamMemberId, ':game_id' => $gameId]);
                }
            }

            $this->db->commit();
            return $teamMemberId;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function getPlayerTeamComparison($playerId) {
        $sql = "SELECT tm.*, t.name as team_name, t.division, t.age_group,
                CONCAT(u.first_name, ' ', u.last_name) as coach_name, u.email as coach_email
                FROM team_members tm
                JOIN teams t ON tm.team_id = t.id
                LEFT JOIN users u ON t.primary_coach_id = u.id
                WHERE tm.user_id = :player_id
                AND tm.leave_date IS NULL
                ORDER BY tm.team_priority, t.name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':player_id' => $playerId]);

        $teams = $stmt->fetchAll();
        $comparison = [];

        foreach ($teams as $team) {
            $comparison[] = [
                'team' => $team['team_name'],
                'team_id' => $team['team_id'],
                'division' => $team['division'],
                'age_group' => $team['age_group'],
                'priority' => $team['team_priority'],
                'positions' => json_decode($team['positions'], true) ?? [],
                'primary_position' => $team['primary_position'],
                'jersey_numbers' => [
                    'primary' => $team['jersey_number'],
                    'alternate' => $team['jersey_number_alt']
                ],
                'coach' => $team['coach_name'],
                'coach_contact' => $team['coach_email'],
                'status' => $team['status']
            ];
        }

        return $comparison;
    }

    public function recordAttendance($eventId, $data) {
        // Attendance lives in event_attendance, keyed on (event_id, athlete_id).
        // The UI sends team_member_id per record, so resolve it to athlete_id
        // first. Records may also carry athlete_id directly. Postgres upsert via
        // ON CONFLICT on the (event_id, athlete_id) unique constraint.
        $allowedStatuses = ['present', 'absent', 'late', 'excused'];

        $resolveStmt = $this->db->prepare(
            "SELECT athlete_id FROM team_members WHERE id = :tm_id"
        );

        $upsert = "INSERT INTO event_attendance
                    (event_id, athlete_id, status, notes, marked_by, marked_at)
                   VALUES (:event_id, :athlete_id, :status, :notes, :marked_by, CURRENT_TIMESTAMP)
                   ON CONFLICT (event_id, athlete_id) DO UPDATE
                   SET status = EXCLUDED.status,
                       notes = EXCLUDED.notes,
                       marked_by = EXCLUDED.marked_by,
                       marked_at = CURRENT_TIMESTAMP";
        $stmt = $this->db->prepare($upsert);

        $markedBy = $_SESSION['user_id'] ?? null;

        foreach ($data['attendance'] as $record) {
            $athleteId = $record['athlete_id'] ?? null;

            if (empty($athleteId) && !empty($record['team_member_id'])) {
                $resolveStmt->execute([':tm_id' => $record['team_member_id']]);
                $athleteId = $resolveStmt->fetchColumn();
            }

            if (empty($athleteId)) {
                // Can't resolve an athlete for this row; skip it.
                continue;
            }

            $status = in_array($record['status'] ?? '', $allowedStatuses, true)
                ? $record['status']
                : 'present';

            $stmt->execute([
                ':event_id' => $eventId,
                ':athlete_id' => $athleteId,
                ':status' => $status,
                ':notes' => $record['notes'] ?? null,
                ':marked_by' => $markedBy
            ]);
        }

        return true;
    }

    public function getAttendance($teamId, $eventId = null) {
        if ($eventId) {
            // Per-event roster status. event_attendance is keyed by athlete_id;
            // join athletes for names and scope to athletes on this team.
            $sql = "SELECT ea.id, ea.event_id, ea.athlete_id, ea.status, ea.notes,
                           ea.marked_by, ea.marked_at,
                           a.first_name, a.last_name
                    FROM event_attendance ea
                    JOIN athletes a ON ea.athlete_id = a.id
                    JOIN team_members tm ON tm.athlete_id = ea.athlete_id
                    WHERE ea.event_id = :event_id
                    AND tm.team_id = :team_id
                    AND tm.role = 'player'
                    AND tm.leave_date IS NULL";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':event_id' => $eventId, ':team_id' => $teamId]);
            return $stmt->fetchAll();
        } else {
            // Recent events for the team (via calendar_event_teams) with
            // present/absent/late counts from event_attendance.
            $sql = "SELECT ce.id, ce.name AS title, ce.event_date, ce.start_time,
                    COUNT(CASE WHEN ea.status = 'present' THEN 1 END) as present_count,
                    COUNT(CASE WHEN ea.status = 'absent' THEN 1 END) as absent_count,
                    COUNT(CASE WHEN ea.status = 'late' THEN 1 END) as late_count,
                    COUNT(CASE WHEN ea.status = 'excused' THEN 1 END) as excused_count
                    FROM calendar_events ce
                    JOIN calendar_event_teams cet ON cet.event_id = ce.id
                    LEFT JOIN event_attendance ea ON ea.event_id = ce.id
                    WHERE cet.team_id = :team_id
                    GROUP BY ce.id, ce.name, ce.event_date, ce.start_time
                    ORDER BY ce.event_date DESC
                    LIMIT 10";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':team_id' => $teamId]);
            return $stmt->fetchAll();
        }
    }

    public function searchAvailablePlayers($search, $excludeTeamId = null) {
        // Rosters are athlete-based: search the athletes table (Postgres ILIKE),
        // returning athletes not already on $excludeTeamId.
        $sql = "SELECT a.id, a.first_name, a.last_name, a.date_of_birth
                FROM athletes a
                WHERE a.active_status = true
                AND (a.first_name ILIKE :search OR a.last_name ILIKE :search2)";

        if ($excludeTeamId) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM team_members tm2
                                     WHERE tm2.athlete_id = a.id
                                     AND tm2.team_id = :exclude_team
                                     AND tm2.leave_date IS NULL)";
        }

        $sql .= " ORDER BY a.last_name, a.first_name LIMIT 20";

        $stmt = $this->db->prepare($sql);
        $params = [
            ':search' => '%' . $search . '%',
            ':search2' => '%' . $search . '%'
        ];
        if ($excludeTeamId) {
            $params[':exclude_team'] = $excludeTeamId;
        }
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function checkJerseyConflicts($teamId, $positions) {
        // player_position_assignments does not exist in the Postgres schema;
        // check active team_members jersey_number on this team instead.
        $conflicts = [];

        foreach ($positions as $position) {
            // $positions entries may be plain position strings or arrays with a
            // jersey_number. Only arrays carrying a jersey can conflict.
            if (is_array($position) && isset($position['jersey_number'])) {
                $sql = "SELECT COUNT(*) FROM team_members tm
                        WHERE tm.team_id = :team_id
                        AND tm.jersey_number = :jersey
                        AND tm.role = 'player'
                        AND tm.leave_date IS NULL";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':team_id' => $teamId,
                    ':jersey' => $position['jersey_number']
                ]);

                if ($stmt->fetchColumn() > 0) {
                    $conflicts[] = "Jersey #{$position['jersey_number']} is already in use";
                }
            }
        }

        return $conflicts;
    }

    public function isCoachForTeam($coachId, $teamId) {
        $sql = "SELECT 1 FROM teams t
                WHERE t.id = :team_id
                AND (t.primary_coach_id = :coach_id
                    OR EXISTS (SELECT 1 FROM team_members tm
                               WHERE tm.team_id = t.id
                               AND tm.user_id = :coach_id2
                               AND tm.role = 'assistant_coach'))";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':team_id' => $teamId,
            ':coach_id' => $coachId,
            ':coach_id2' => $coachId
        ]);

        return $stmt->fetch() !== false;
    }

    private function getPlayerOtherTeams($athleteId, $excludeTeamId) {
        $sql = "SELECT t.id, t.name FROM team_members tm
                JOIN teams t ON tm.team_id = t.id
                WHERE tm.athlete_id = :athlete_id
                AND tm.team_id != :exclude_team
                AND tm.leave_date IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':athlete_id' => $athleteId, ':exclude_team' => $excludeTeamId]);
        return $stmt->fetchAll();
    }

    private function logRosterChange($teamMemberId, $fieldName, $oldValue, $newValue) {
        $sql = "INSERT INTO roster_change_log
                (team_member_id, changed_by, field_name, old_value, new_value)
                VALUES (:tm_id, :changed_by, :field, :old_val, :new_val)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':tm_id' => $teamMemberId,
            ':changed_by' => $_SESSION['user_id'] ?? 2,
            ':field' => $fieldName,
            ':old_val' => $oldValue,
            ':new_val' => $newValue
        ]);
    }
}