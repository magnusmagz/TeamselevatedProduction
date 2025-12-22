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
                (SELECT MIN(start_datetime) FROM events e
                 WHERE e.team_id = t.id AND e.start_datetime > NOW() AND e.cancelled = 0) as next_event
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
        $sql = "SELECT tm.*, u.first_name, u.last_name, u.email, u.phone, u.date_of_birth,
                tm.positions, tm.primary_position, tm.jersey_number, tm.jersey_number_alt,
                tm.team_priority, tm.status,
                GROUP_CONCAT(DISTINCT CONCAT(ppa.position, ':', COALESCE(ppa.jersey_number, ''))
                    ORDER BY ppa.position SEPARATOR '|') as position_jerseys
                FROM team_members tm
                JOIN users u ON tm.user_id = u.id
                LEFT JOIN player_position_assignments ppa ON tm.id = ppa.team_member_id AND ppa.is_active = 1
                WHERE tm.team_id = :team_id
                AND tm.role = 'player'
                AND tm.leave_date IS NULL
                GROUP BY tm.id
                ORDER BY tm.jersey_number, u.last_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':team_id' => $teamId]);

        $roster = $stmt->fetchAll();

        foreach ($roster as &$player) {
            $player['positions'] = json_decode($player['positions'], true) ?? [];
            $player['name'] = $player['first_name'] . ' ' . $player['last_name'];

            $positionJerseys = [];
            if (!empty($player['position_jerseys'])) {
                $pairs = explode('|', $player['position_jerseys']);
                foreach ($pairs as $pair) {
                    list($position, $jersey) = explode(':', $pair);
                    $positionJerseys[] = [
                        'position' => $position,
                        'jersey' => $jersey ?: null
                    ];
                }
            }
            $player['position_jerseys'] = $positionJerseys;

            $otherTeams = $this->getPlayerOtherTeams($player['user_id'], $teamId);
            $player['other_teams'] = $otherTeams;
        }

        return $roster;
    }

    public function addPlayerToTeam($teamId, $data) {
        $this->db->beginTransaction();

        try {
            $existingCheck = "SELECT id FROM team_members
                            WHERE team_id = :team_id AND user_id = :user_id
                            AND leave_date IS NULL";
            $stmt = $this->db->prepare($existingCheck);
            $stmt->execute([':team_id' => $teamId, ':user_id' => $data['user_id']]);

            if ($stmt->fetch()) {
                throw new Exception('Player is already on this team');
            }

            $sql = "INSERT INTO team_members
                    (team_id, user_id, role, jersey_number, jersey_number_alt, positions,
                     primary_position, team_priority, status, join_date, guest_player_agreement_id)
                    VALUES (:team_id, :user_id, 'player', :jersey, :jersey_alt, :positions,
                            :primary_pos, :priority, :status, CURDATE(), :guest_id)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':team_id' => $teamId,
                ':user_id' => $data['user_id'],
                ':jersey' => $data['jersey_number'] ?? null,
                ':jersey_alt' => $data['jersey_number_alt'] ?? null,
                ':positions' => json_encode($data['positions']),
                ':primary_pos' => $data['primary_position'],
                ':priority' => $data['team_priority'] ?? 'primary',
                ':status' => $data['status'] ?? 'active',
                ':guest_id' => $data['guest_player_agreement_id'] ?? null
            ]);

            $teamMemberId = $this->db->lastInsertId();

            if (!empty($data['position_assignments'])) {
                foreach ($data['position_assignments'] as $pa) {
                    $sql = "INSERT INTO player_position_assignments
                            (team_member_id, position, jersey_number, is_active, assigned_date)
                            VALUES (:tm_id, :position, :jersey, 1, CURDATE())";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([
                        ':tm_id' => $teamMemberId,
                        ':position' => $pa['position'],
                        ':jersey' => $pa['jersey_number'] ?? null
                    ]);
                }
            }

            $this->db->commit();
            return $teamMemberId;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function updatePlayerPositions($teamId, $playerId, $data) {
        $this->db->beginTransaction();

        try {
            $sql = "UPDATE team_members
                    SET positions = :positions, primary_position = :primary_pos,
                        jersey_number = :jersey, jersey_number_alt = :jersey_alt
                    WHERE team_id = :team_id AND user_id = :player_id
                    AND leave_date IS NULL";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':positions' => json_encode($data['positions']),
                ':primary_pos' => $data['primary_position'],
                ':jersey' => $data['jersey_number'] ?? null,
                ':jersey_alt' => $data['jersey_number_alt'] ?? null,
                ':team_id' => $teamId,
                ':player_id' => $playerId
            ]);

            $tmSql = "SELECT id FROM team_members
                     WHERE team_id = :team_id AND user_id = :player_id
                     AND leave_date IS NULL";
            $tmStmt = $this->db->prepare($tmSql);
            $tmStmt->execute([':team_id' => $teamId, ':player_id' => $playerId]);
            $teamMemberId = $tmStmt->fetchColumn();

            $sql = "UPDATE player_position_assignments
                    SET is_active = 0
                    WHERE team_member_id = :tm_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':tm_id' => $teamMemberId]);

            if (!empty($data['position_assignments'])) {
                foreach ($data['position_assignments'] as $pa) {
                    $sql = "INSERT INTO player_position_assignments
                            (team_member_id, position, jersey_number, is_active, assigned_date)
                            VALUES (:tm_id, :position, :jersey, 1, CURDATE())";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([
                        ':tm_id' => $teamMemberId,
                        ':position' => $pa['position'],
                        ':jersey' => $pa['jersey_number'] ?? null
                    ]);
                }
            }

            $this->logRosterChange($teamMemberId, 'positions',
                                 json_encode($data['old_positions'] ?? []),
                                 json_encode($data['positions']));

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function removePlayerFromTeam($teamId, $playerId, $reason) {
        $sql = "UPDATE team_members
                SET leave_date = CURDATE(), leave_reason = :reason, removed_by = :removed_by
                WHERE team_id = :team_id AND user_id = :player_id
                AND leave_date IS NULL";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':reason' => $reason,
            ':removed_by' => $_SESSION['user_id'] ?? 2,
            ':team_id' => $teamId,
            ':player_id' => $playerId
        ]);
    }

    public function getPositionReport($teamId) {
        $sql = "SELECT tm.*, u.first_name, u.last_name,
                tm.positions, tm.primary_position, tm.team_priority, tm.status
                FROM team_members tm
                JOIN users u ON tm.user_id = u.id
                WHERE tm.team_id = :team_id
                AND tm.role = 'player'
                AND tm.leave_date IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':team_id' => $teamId]);

        $players = $stmt->fetchAll();
        $positionMap = [];

        foreach ($players as $player) {
            $positions = json_decode($player['positions'], true) ?? [];
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
                    'id' => $player['user_id'],
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
        $sql = "SELECT ppa.jersey_number, ppa.position,
                u.first_name, u.last_name, tm.team_priority, tm.join_date, tm.leave_date
                FROM player_position_assignments ppa
                JOIN team_members tm ON ppa.team_member_id = tm.id
                JOIN users u ON tm.user_id = u.id
                WHERE tm.team_id = :team_id
                AND tm.leave_date IS NULL
                AND ppa.is_active = 1
                ORDER BY ppa.jersey_number, ppa.position";

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
        $sql = "INSERT INTO attendance_records
                (event_id, team_member_id, status, notes, recorded_by)
                VALUES (:event_id, :tm_id, :status, :notes, :recorded_by)
                ON DUPLICATE KEY UPDATE
                status = VALUES(status), notes = VALUES(notes)";

        $stmt = $this->db->prepare($sql);

        foreach ($data['attendance'] as $record) {
            $stmt->execute([
                ':event_id' => $eventId,
                ':tm_id' => $record['team_member_id'],
                ':status' => $record['status'],
                ':notes' => $record['notes'] ?? null,
                ':recorded_by' => $_SESSION['user_id'] ?? 2
            ]);
        }

        return true;
    }

    public function getAttendance($teamId, $eventId = null) {
        if ($eventId) {
            $sql = "SELECT ar.*, tm.user_id, u.first_name, u.last_name
                    FROM attendance_records ar
                    JOIN team_members tm ON ar.team_member_id = tm.id
                    JOIN users u ON tm.user_id = u.id
                    WHERE ar.event_id = :event_id
                    AND tm.team_id = :team_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':event_id' => $eventId, ':team_id' => $teamId]);
            return $stmt->fetchAll();
        } else {
            $sql = "SELECT e.id, e.title, e.start_datetime,
                    COUNT(CASE WHEN ar.status = 'present' THEN 1 END) as present_count,
                    COUNT(CASE WHEN ar.status = 'absent' THEN 1 END) as absent_count,
                    COUNT(CASE WHEN ar.status = 'late' THEN 1 END) as late_count
                    FROM events e
                    LEFT JOIN attendance_records ar ON e.id = ar.event_id
                    WHERE e.team_id = :team_id
                    GROUP BY e.id
                    ORDER BY e.start_datetime DESC
                    LIMIT 10";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':team_id' => $teamId]);
            return $stmt->fetchAll();
        }
    }

    public function searchAvailablePlayers($search, $excludeTeamId = null) {
        $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.date_of_birth,
                GROUP_CONCAT(DISTINCT t.name) as current_teams
                FROM users u
                LEFT JOIN team_members tm ON u.id = tm.user_id AND tm.leave_date IS NULL
                LEFT JOIN teams t ON tm.team_id = t.id
                WHERE u.role = 'player'
                AND (u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search2)";

        if ($excludeTeamId) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM team_members tm2
                                     WHERE tm2.user_id = u.id
                                     AND tm2.team_id = :exclude_team
                                     AND tm2.leave_date IS NULL)";
        }

        $sql .= " GROUP BY u.id LIMIT 20";

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
        $conflicts = [];

        foreach ($positions as $position) {
            if (isset($position['jersey_number'])) {
                $sql = "SELECT COUNT(*) FROM player_position_assignments ppa
                        JOIN team_members tm ON ppa.team_member_id = tm.id
                        WHERE tm.team_id = :team_id
                        AND ppa.position = :position
                        AND ppa.jersey_number = :jersey
                        AND ppa.is_active = 1
                        AND tm.leave_date IS NULL";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':team_id' => $teamId,
                    ':position' => $position['position'],
                    ':jersey' => $position['jersey_number']
                ]);

                if ($stmt->fetchColumn() > 0) {
                    $conflicts[] = "Jersey #{$position['jersey_number']} is already in use for position {$position['position']}";
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

    private function getPlayerOtherTeams($userId, $excludeTeamId) {
        $sql = "SELECT t.id, t.name FROM team_members tm
                JOIN teams t ON tm.team_id = t.id
                WHERE tm.user_id = :user_id
                AND tm.team_id != :exclude_team
                AND tm.leave_date IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':exclude_team' => $excludeTeamId]);
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