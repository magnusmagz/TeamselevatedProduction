<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Team.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/club_standing.php';
require_once __DIR__ . '/../lib/background_check.php';

/**
 * index.php performs NO authentication, and until 2026-09-02 neither did this
 * controller: 15 methods, zero auth references. Verified with no token against prod
 * on 2026-08-31 — GET /api/teams returned 20 teams across three clubs, and
 * POST /api/teams/{id}/volunteers took `background_check_status` from the body.
 *
 * No frontend code calls these routes (the UI uses legacy/teams-gateway.php and
 * legacy/coaches-gateway.php), which is exactly why it survived. The absence of a UI
 * is not an access control, so every method now authenticates in the constructor and
 * authorizes against the TEAM's club: staff (admin or coach) to read, club admin to
 * write. Pinned by tests/php/TeamControllerScopeTest.php.
 */
class TeamController {
    private $db;
    private $teamModel;
    /** @var AuthMiddleware */
    private $auth;

    public function __construct() {
        // Exits 401 on a missing or unverifiable token. Nothing upstream does this.
        $this->auth = AuthMiddleware::requireAuth();
        $this->db = Database::getInstance()->getConnection();
        $this->teamModel = new Team($this->db);
    }

    /** teams.club_id for a team, or null when the team does not exist. */
    private function teamClubId($teamId): ?int {
        $stmt = $this->db->prepare("SELECT club_id FROM teams WHERE id = :id");
        $stmt->execute([':id' => $teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $row['club_id'] === null ? 0 : (int)$row['club_id'];
    }

    /** Read gate: club admin or coach of the team's club. 404 if no such team. */
    private function requireTeamStaff($teamId): int {
        $clubId = $this->teamClubId($teamId);
        if ($clubId === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Team not found']);
            exit;
        }
        if (!te_is_club_staff($this->auth, $clubId)) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have access to this team']);
            exit;
        }
        return $clubId;
    }

    /** Write gate: club admin of the team's club. 404 if no such team. */
    private function requireTeamAdmin($teamId): int {
        $clubId = $this->teamClubId($teamId);
        if ($clubId === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Team not found']);
            exit;
        }
        $this->requireClubAdmin($clubId);
        return $clubId;
    }

    private function requireClubAdmin(int $clubId): void {
        if (!te_is_club_admin($this->auth, $clubId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Only a club administrator can do this']);
            exit;
        }
    }

    /** Lookups with no club in the request: any staff standing anywhere. */
    private function requireAnyStaff(): void {
        if ($this->auth->isSuperAdmin()
            || $this->auth->hasRole('club_admin')
            || $this->auth->hasRole('coach')) {
            return;
        }
        http_response_code(403);
        echo json_encode(['error' => 'Staff access required']);
        exit;
    }

    public function index() {
        $search = $_GET['search'] ?? null;
        $season_id = $_GET['season_id'] ?? null;
        $age_group = $_GET['age_group'] ?? null;
        $division = $_GET['division'] ?? null;
        $sort_by = $_GET['sort_by'] ?? 'name';
        $sort_order = $_GET['sort_order'] ?? 'asc';
        $page = $_GET['page'] ?? 1;

        // null = super admin, every club; [] = no club standing, no teams.
        $clubIds = $this->auth->getAccessibleClubIds();

        $teams = $this->teamModel->getTeams([
            'club_ids' => $clubIds,
            'search' => $search,
            'season_id' => $season_id,
            'age_group' => $age_group,
            'division' => $division,
            'sort_by' => $sort_by,
            'sort_order' => $sort_order,
            'page' => $page
        ]);

        echo json_encode($teams);
    }

    public function show($id) {
        $this->requireTeamStaff($id);
        $team = $this->teamModel->getTeamById($id);
        if ($team) {
            echo json_encode($team);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Team not found']);
        }
    }

    public function create() {
        $data = json_decode(file_get_contents('php://input'), true);

        // The club comes from the body, so standing is checked against THAT club —
        // and createTeam now writes it, where before club_id was left NULL and the
        // team was invisible to every club-scoped screen.
        if (empty($data['club_id'])) {
            http_response_code(400);
            echo json_encode(['errors' => ['club_id' => 'club_id is required']]);
            return;
        }
        $this->requireClubAdmin((int)$data['club_id']);

        $validationErrors = $this->validateTeamData($data);
        if (!empty($validationErrors)) {
            http_response_code(400);
            echo json_encode(['errors' => $validationErrors]);
            return;
        }

        $teamId = $this->teamModel->createTeam($data);
        if ($teamId) {
            http_response_code(201);
            echo json_encode(['id' => $teamId, 'message' => 'Team created successfully']);

            if (!empty($data['primary_coach_id'])) {
                $this->sendCoachNotification($data['primary_coach_id'], $teamId);
            }
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create team']);
        }
    }

    public function update($id) {
        $this->requireTeamAdmin($id);
        $data = json_decode(file_get_contents('php://input'), true);
        unset($data['club_id']); // a team does not move clubs through this route

        $validationErrors = $this->validateTeamData($data, $id);
        if (!empty($validationErrors)) {
            http_response_code(400);
            echo json_encode(['errors' => $validationErrors]);
            return;
        }

        $oldTeam = $this->teamModel->getTeamById($id);

        if ($this->teamModel->updateTeam($id, $data)) {
            $this->logTeamChanges($id, $oldTeam, $data);

            echo json_encode(['message' => 'Team updated successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update team']);
        }
    }

    public function delete($id) {
        $this->requireTeamAdmin($id);
        $deletion = $this->teamModel->canDeleteTeam($id);

        if ($deletion['active_members'] > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot delete team with active players']);
            return;
        }

        if ($deletion['future_events'] > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot delete team with scheduled future games']);
            return;
        }

        $reason = $_POST['reason'] ?? 'Manual deletion';

        if ($this->teamModel->deleteTeam($id, $reason)) {
            echo json_encode(['message' => 'Team archived successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete team']);
        }
    }

    public function assignCoach($teamId) {
        $this->requireTeamAdmin($teamId);
        $data = json_decode(file_get_contents('php://input'), true);

        $existingCommitments = $this->teamModel->checkCoachAvailability(
            $data['coach_id'],
            $teamId,
            $data['role']
        );

        if ($existingCommitments && $data['role'] === 'primary') {
            http_response_code(400);
            echo json_encode(['error' => 'Coach already assigned to another team this season']);
            return;
        }

        if ($this->teamModel->assignCoach($teamId, $data['coach_id'], $data['role'])) {
            $this->sendCoachNotification($data['coach_id'], $teamId);
            echo json_encode(['message' => 'Coach assigned successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to assign coach']);
        }
    }

    public function removeCoach($teamId, $userId) {
        $this->requireTeamAdmin($teamId);
        if ($this->teamModel->removeCoach($teamId, $userId)) {
            echo json_encode(['message' => 'Coach removed successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to remove coach']);
        }
    }

    public function assignVolunteer($teamId) {
        $this->requireTeamAdmin($teamId);
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['user_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id is required']);
            return;
        }

        // The same gate as volunteer-gateway.php's direct assignment. The status is
        // READ from the volunteer's record, never taken from the request body — the
        // old code accepted `background_check_status` from the caller and defaulted
        // it to 'pending', which let a non-cleared volunteer onto a team.
        $bgStatus = te_background_check_status($this->db, (int)$data['user_id']);
        if ($bgStatus !== 'cleared') {
            http_response_code(403);
            echo json_encode([
                'error' => 'Cannot assign: volunteer background check not cleared',
                'background_check_status' => $bgStatus
            ]);
            return;
        }

        if ($this->teamModel->assignVolunteer($teamId, $data, $bgStatus, (int)$this->auth->getUserId())) {
            echo json_encode(['message' => 'Volunteer assigned successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to assign volunteer']);
        }
    }

    public function roster($teamId) {
        $this->requireTeamStaff($teamId);
        $roster = $this->teamModel->getTeamRoster($teamId);
        echo json_encode($roster);
    }

    public function auditLog($teamId) {
        $this->requireTeamStaff($teamId);
        $log = $this->teamModel->getAuditLog($teamId);
        echo json_encode($log);
    }

    public function bulkAction() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['team_ids']) || !is_array($data['team_ids'])) {
            http_response_code(400);
            echo json_encode(['error' => 'team_ids is required']);
            return;
        }
        // Admin of EVERY team's club, checked before anything is touched.
        foreach ($data['team_ids'] as $teamId) {
            $this->requireTeamAdmin($teamId);
        }

        $result = $this->teamModel->performBulkAction(
            $data['team_ids'],
            $data['action'],
            $data['params'] ?? []
        );

        if ($result) {
            echo json_encode(['message' => count($data['team_ids']) . ' teams updated successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Bulk operation failed']);
        }
    }

    public function availableCoaches() {
        $this->requireAnyStaff();
        $coaches = $this->teamModel->getAvailableCoaches($_GET['season_id'] ?? null);
        echo json_encode($coaches);
    }

    public function seasons() {
        $this->requireAnyStaff();
        $seasons = $this->teamModel->getActiveSeasons();
        echo json_encode($seasons);
    }

    public function fields() {
        $this->requireAnyStaff();
        $fields = $this->teamModel->getActiveFields();
        echo json_encode($fields);
    }

    private function validateTeamData($data, $teamId = null) {
        $errors = [];

        if (empty($data['name']) || strlen($data['name']) > 100) {
            $errors['name'] = 'Team name is required and must be less than 100 characters';
        }

        if (!$this->teamModel->isTeamNameUnique($data['name'], $data['season_id'], $teamId)) {
            $errors['name'] = 'Team name already exists in this season';
        }

        $validAgeGroups = ['U6', 'U8', 'U10', 'U12', 'U14', 'U16', 'U18', 'Adult'];
        if (!in_array($data['age_group'], $validAgeGroups)) {
            $errors['age_group'] = 'Invalid age group';
        }

        $validDivisions = ['Recreational', 'Competitive', 'Elite'];
        if (!in_array($data['division'], $validDivisions)) {
            $errors['division'] = 'Invalid division';
        }

        return $errors;
    }

    private function logTeamChanges($teamId, $oldData, $newData) {
        foreach ($newData as $field => $newValue) {
            if (isset($oldData[$field]) && $oldData[$field] != $newValue) {
                $this->teamModel->logChange($teamId, $field, $oldData[$field], $newValue);
            }
        }
    }

    private function sendCoachNotification($coachId, $teamId) {
        // Implement email notification logic here
    }
}