<?php
class Team {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getTeams($filters) {
        $page = $filters['page'] ?? 1;
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        // The WHERE fragment is built once and used by BOTH the page query and the
        // count query. It used to be derived with
        //     str_replace('SELECT t.*', 'SELECT COUNT(*)', $sql)
        // which swapped only the first line of the select list and left
        // CONCAT(u.first_name, …), s.name and f.name sitting beside an aggregate —
        // SQLSTATE 42803, a hard 500 on every call. MySQL accepted it; Postgres does
        // not, so this endpoint has been dead since the database moved.
        $where = " WHERE t.deleted_at IS NULL";

        $params = [];

        if (!empty($filters['search'])) {
            // ILIKE, not LIKE: MySQL's LIKE is case-insensitive by default and
            // Postgres' is not, so searching "Thunder" for a team named "thunder"
            // silently returned nothing after the move.
            $where .= " AND t.name ILIKE :search";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['season_id'])) {
            $where .= " AND t.season_id = :season_id";
            $params[':season_id'] = $filters['season_id'];
        }

        if (!empty($filters['age_group'])) {
            $where .= " AND t.age_group = :age_group";
            $params[':age_group'] = $filters['age_group'];
        }

        if (!empty($filters['division'])) {
            $where .= " AND t.division = :division";
            $params[':division'] = $filters['division'];
        }

        // ORDER BY cannot be a bound parameter, so it is interpolated — which means
        // it MUST come from a fixed list. It previously took $_GET['sort_by'] and
        // $_GET['sort_order'] and pasted them straight into the statement.
        $sortable = ['name', 'division', 'age_group', 'status', 'created_at', 'updated_at', 'id'];
        $sortBy = in_array($filters['sort_by'] ?? '', $sortable, true) ? $filters['sort_by'] : 'name';
        $sortOrder = strtolower($filters['sort_order'] ?? '') === 'desc' ? 'DESC' : 'ASC';

        // Counted off `teams` alone: every filter above is on `t`, and the three
        // joins below are LEFT, so they cannot change the number of rows.
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM teams t" . $where);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetchColumn();

        $sql = "SELECT t.*,
                CONCAT(u.first_name, ' ', u.last_name) as coach_name,
                s.name as season_name,
                f.name as home_field_name,
                (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id AND tm.leave_date IS NULL) as player_count
                FROM teams t
                LEFT JOIN users u ON t.primary_coach_id = u.id
                LEFT JOIN seasons s ON t.season_id = s.id
                LEFT JOIN fields f ON t.home_field_id = f.id"
            . $where
            . " ORDER BY t.{$sortBy} {$sortOrder}"
            . " LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'teams' => $stmt->fetchAll(),
            'pagination' => [
                'total' => $totalCount,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => ceil($totalCount / $perPage)
            ]
        ];
    }

    public function getTeamById($id) {
        $sql = "SELECT t.*,
                CONCAT(u.first_name, ' ', u.last_name) as coach_name,
                s.name as season_name,
                f.name as home_field_name
                FROM teams t
                LEFT JOIN users u ON t.primary_coach_id = u.id
                LEFT JOIN seasons s ON t.season_id = s.id
                LEFT JOIN fields f ON t.home_field_id = f.id
                WHERE t.id = :id AND t.deleted_at IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function createTeam($data) {
        $sql = "INSERT INTO teams (name, logo_url, age_group, division, season_id, primary_coach_id, home_field_id, max_players)
                VALUES (:name, :logo_url, :age_group, :division, :season_id, :primary_coach_id, :home_field_id, :max_players)";

        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([
            ':name' => $data['name'],
            ':logo_url' => $data['logo_url'] ?? null,
            ':age_group' => $data['age_group'],
            ':division' => $data['division'],
            ':season_id' => !empty($data['season_id']) ? $data['season_id'] : null,
            ':primary_coach_id' => !empty($data['primary_coach_id']) ? $data['primary_coach_id'] : null,
            ':home_field_id' => !empty($data['home_field_id']) ? $data['home_field_id'] : null,
            ':max_players' => $data['max_players'] ?? 20
        ]);

        return $result ? $this->db->lastInsertId() : false;
    }

    public function updateTeam($id, $data) {
        $sql = "UPDATE teams SET
                name = :name,
                logo_url = :logo_url,
                age_group = :age_group,
                division = :division,
                season_id = :season_id,
                primary_coach_id = :primary_coach_id,
                home_field_id = :home_field_id,
                max_players = :max_players,
                updated_at = NOW()
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':id' => $id,
            ':name' => $data['name'],
            ':logo_url' => $data['logo_url'] ?? null,
            ':age_group' => $data['age_group'],
            ':division' => $data['division'],
            ':season_id' => !empty($data['season_id']) ? $data['season_id'] : null,
            ':primary_coach_id' => !empty($data['primary_coach_id']) ? $data['primary_coach_id'] : null,
            ':home_field_id' => !empty($data['home_field_id']) ? $data['home_field_id'] : null,
            ':max_players' => $data['max_players'] ?? 20,
        ]);
    }

    public function deleteTeam($id, $reason) {
        $sql = "UPDATE teams SET
                deleted_at = NOW(),
                deletion_reason = :reason,
                deleted_by = :deleted_by
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':id' => $id,
            ':reason' => $reason,
            ':deleted_by' => $_SESSION['user_id'] ?? 1
        ]);
    }

    public function canDeleteTeam($id) {
        $sql = "CALL check_team_deletion(:team_id)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':team_id' => $id]);
        return $stmt->fetch();
    }

    public function isTeamNameUnique($name, $seasonId, $excludeId = null) {
        $sql = "SELECT COUNT(*) FROM teams
                WHERE name = :name
                AND deleted_at IS NULL";

        $params = [':name' => $name];

        // Only check season_id if it's provided
        if (!empty($seasonId)) {
            $sql .= " AND season_id = :season_id";
            $params[':season_id'] = $seasonId;
        } else {
            // If no season provided, check for teams with NULL season_id
            $sql .= " AND season_id IS NULL";
        }

        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() == 0;
    }

    public function checkCoachAvailability($coachId, $teamId, $role) {
        if ($role !== 'primary') {
            return false;
        }

        $sql = "SELECT COUNT(*) FROM teams t1
                JOIN teams t2 ON t1.season_id = t2.season_id
                WHERE t1.primary_coach_id = :coach_id
                AND t2.id = :team_id
                AND t1.id != :team_id
                AND t1.deleted_at IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':coach_id' => $coachId,
            ':team_id' => $teamId
        ]);

        return $stmt->fetchColumn() > 0;
    }

    public function assignCoach($teamId, $coachId, $role) {
        if ($role === 'primary') {
            $sql = "UPDATE teams SET primary_coach_id = :coach_id WHERE id = :team_id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':coach_id' => $coachId, ':team_id' => $teamId]);
        } else {
            $sql = "INSERT INTO team_members (team_id, user_id, role, join_date)
                    VALUES (:team_id, :user_id, 'assistant_coach', CURRENT_DATE)";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':team_id' => $teamId, ':user_id' => $coachId]);
        }
    }

    public function removeCoach($teamId, $userId) {
        $sql = "UPDATE team_members SET leave_date = CURRENT_DATE
                WHERE team_id = :team_id AND user_id = :user_id AND role = 'assistant_coach'";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':team_id' => $teamId, ':user_id' => $userId]);
    }

    public function assignVolunteer($teamId, $data) {
        $sql = "INSERT INTO team_volunteers
                (team_id, user_id, volunteer_role, start_date, end_date, background_check_status, notes, assigned_by)
                VALUES (:team_id, :user_id, :role, :start_date, :end_date, :bg_status, :notes, :assigned_by)";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':team_id' => $teamId,
            ':user_id' => $data['user_id'],
            ':role' => $data['volunteer_role'],
            ':start_date' => $data['start_date'],
            ':end_date' => $data['end_date'] ?? null,
            ':bg_status' => $data['background_check_status'] ?? 'pending',
            ':notes' => $data['notes'] ?? null,
            ':assigned_by' => $_SESSION['user_id'] ?? 1
        ]);
    }

    public function getTeamRoster($teamId) {
        $sql = "SELECT tm.*, u.first_name, u.last_name, u.email, u.phone
                FROM team_members tm
                JOIN users u ON tm.user_id = u.id
                WHERE tm.team_id = :team_id AND tm.leave_date IS NULL
                ORDER BY tm.role, u.last_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':team_id' => $teamId]);
        return $stmt->fetchAll();
    }

    public function getAuditLog($teamId) {
        $sql = "SELECT tal.*, CONCAT(u.first_name, ' ', u.last_name) as changed_by_name
                FROM team_audit_log tal
                JOIN users u ON tal.changed_by = u.id
                WHERE tal.team_id = :team_id
                ORDER BY tal.changed_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':team_id' => $teamId]);
        return $stmt->fetchAll();
    }

    public function logChange($teamId, $fieldName, $oldValue, $newValue) {
        // team_audit_log is the generic audit table (user_id, action, table_name,
        // record_id, old_values, new_values) — not the per-field shape this used
        // to assume, so every write threw 42703 and no team change was ever logged.
        $sql = "INSERT INTO team_audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
                VALUES (:user_id, :action, 'teams', :record_id, :old_values, :new_values, :ip, :ua, NOW())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'] ?? null,
            ':action' => 'update_' . $fieldName,
            ':record_id' => $teamId,
            ':old_values' => json_encode([$fieldName => $oldValue]),
            ':new_values' => json_encode([$fieldName => $newValue]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public function performBulkAction($teamIds, $action, $params) {
        $this->db->beginTransaction();

        try {
            switch ($action) {
                case 'clone_to_season':
                    foreach ($teamIds as $teamId) {
                        $team = $this->getTeamById($teamId);
                        $team['season_id'] = $params['new_season_id'];
                        unset($team['id']);
                        $this->createTeam($team);
                    }
                    break;

                case 'bulk_assign_coach':
                    $sql = "UPDATE teams SET primary_coach_id = :coach_id WHERE id IN (" . implode(',', $teamIds) . ")";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([':coach_id' => $params['coach_id']]);
                    break;

                case 'archive':
                    $sql = "UPDATE teams SET deleted_at = NOW(), deletion_reason = 'Bulk archive', deleted_by = :user_id
                            WHERE id IN (" . implode(',', $teamIds) . ")";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([':user_id' => $_SESSION['user_id'] ?? 1]);
                    break;

                case 'update_division':
                    $sql = "UPDATE teams SET division = :division WHERE id IN (" . implode(',', $teamIds) . ")";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([':division' => $params['division']]);
                    break;
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function getAvailableCoaches($seasonId = null) {
        $sql = "SELECT u.id, u.first_name, u.last_name, u.email,
                (SELECT COUNT(*) FROM teams t WHERE t.primary_coach_id = u.id AND t.deleted_at IS NULL) as team_count
                FROM users u
                WHERE u.role = 'coach' AND u.is_active = 1
                ORDER BY u.last_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getActiveSeasons() {
        $sql = "SELECT * FROM seasons WHERE is_active = 1 ORDER BY start_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getActiveFields() {
        $sql = "SELECT * FROM fields WHERE is_active = 1 ORDER BY name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}