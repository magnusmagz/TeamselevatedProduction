<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Team.php';

class TeamController {
    private $db;
    private $teamModel;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->teamModel = new Team($this->db);
    }

    public function index() {
        $search = $_GET['search'] ?? null;
        $season_id = $_GET['season_id'] ?? null;
        $age_group = $_GET['age_group'] ?? null;
        $division = $_GET['division'] ?? null;
        $sort_by = $_GET['sort_by'] ?? 'name';
        $sort_order = $_GET['sort_order'] ?? 'asc';
        $page = $_GET['page'] ?? 1;

        $teams = $this->teamModel->getTeams([
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
        $data = json_decode(file_get_contents('php://input'), true);

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
        if ($this->teamModel->removeCoach($teamId, $userId)) {
            echo json_encode(['message' => 'Coach removed successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to remove coach']);
        }
    }

    public function assignVolunteer($teamId) {
        $data = json_decode(file_get_contents('php://input'), true);

        if ($this->teamModel->assignVolunteer($teamId, $data)) {
            echo json_encode(['message' => 'Volunteer assigned successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to assign volunteer']);
        }
    }

    public function roster($teamId) {
        $roster = $this->teamModel->getTeamRoster($teamId);
        echo json_encode($roster);
    }

    public function auditLog($teamId) {
        $log = $this->teamModel->getAuditLog($teamId);
        echo json_encode($log);
    }

    public function bulkAction() {
        $data = json_decode(file_get_contents('php://input'), true);

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
        $coaches = $this->teamModel->getAvailableCoaches($_GET['season_id'] ?? null);
        echo json_encode($coaches);
    }

    public function seasons() {
        $seasons = $this->teamModel->getActiveSeasons();
        echo json_encode($seasons);
    }

    public function fields() {
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