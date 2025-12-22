<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Coach.php';

class CoachController {
    private $db;
    private $coachModel;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->coachModel = new Coach($this->db);
    }

    public function myTeams() {
        $coachId = $_SESSION['user_id'] ?? 2;
        $teams = $this->coachModel->getCoachTeams($coachId);
        echo json_encode($teams);
    }

    public function roster($teamId) {
        if (!$this->authorizeCoachAccess($teamId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $roster = $this->coachModel->getTeamRoster($teamId);
        echo json_encode($roster);
    }

    public function addPlayer($teamId) {
        if (!$this->authorizeCoachAccess($teamId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $validationErrors = $this->validatePlayerData($data);
        if (!empty($validationErrors)) {
            http_response_code(400);
            echo json_encode(['errors' => $validationErrors]);
            return;
        }

        $jerseyConflicts = $this->coachModel->checkJerseyConflicts($teamId, $data['positions']);
        if (!empty($jerseyConflicts)) {
            http_response_code(400);
            echo json_encode(['errors' => $jerseyConflicts]);
            return;
        }

        $playerId = $this->coachModel->addPlayerToTeam($teamId, $data);
        if ($playerId) {
            http_response_code(201);
            echo json_encode(['id' => $playerId, 'message' => 'Player added successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add player']);
        }
    }

    public function updatePlayerPositions($teamId, $playerId) {
        if (!$this->authorizeCoachAccess($teamId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if ($this->coachModel->updatePlayerPositions($teamId, $playerId, $data)) {
            echo json_encode(['message' => 'Player positions updated successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update positions']);
        }
    }

    public function removePlayer($teamId, $playerId) {
        if (!$this->authorizeCoachAccess($teamId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $reason = $data['reason'] ?? 'Coach decision';

        if ($this->coachModel->removePlayerFromTeam($teamId, $playerId, $reason)) {
            echo json_encode(['message' => 'Player removed from team']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to remove player']);
        }
    }

    public function positionReport($teamId) {
        if (!$this->authorizeCoachAccess($teamId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $report = $this->coachModel->getPositionReport($teamId);
        echo json_encode($report);
    }

    public function jerseyReport($teamId) {
        if (!$this->authorizeCoachAccess($teamId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $report = $this->coachModel->getJerseyReport($teamId);
        echo json_encode($report);
    }

    public function addGuestPlayer($teamId) {
        if (!$this->authorizeCoachAccess($teamId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $guestId = $this->coachModel->addGuestPlayer($teamId, $data);
        if ($guestId) {
            echo json_encode(['id' => $guestId, 'message' => 'Guest player added']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add guest player']);
        }
    }

    public function multiTeamComparison($teamId, $playerId) {
        if (!$this->authorizeCoachAccess($teamId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $comparison = $this->coachModel->getPlayerTeamComparison($playerId);
        echo json_encode($comparison);
    }

    public function attendance($teamId) {
        if (!$this->authorizeCoachAccess($teamId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $eventId = $_GET['event_id'] ?? null;

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $attendance = $this->coachModel->getAttendance($teamId, $eventId);
            echo json_encode($attendance);
        } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            if ($this->coachModel->recordAttendance($eventId, $data)) {
                echo json_encode(['message' => 'Attendance recorded']);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to record attendance']);
            }
        }
    }

    public function searchPlayers() {
        $search = $_GET['search'] ?? '';
        $excludeTeamId = $_GET['exclude_team'] ?? null;

        if (strlen($search) < 2) {
            echo json_encode([]);
            return;
        }

        $players = $this->coachModel->searchAvailablePlayers($search, $excludeTeamId);
        echo json_encode($players);
    }

    public function exportRoster($teamId) {
        if (!$this->authorizeCoachAccess($teamId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $format = $_GET['format'] ?? 'json';
        $roster = $this->coachModel->getTeamRoster($teamId);

        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="roster.csv"');

            $output = fopen('php://output', 'w');
            fputcsv($output, ['Name', 'Jersey', 'Positions', 'Primary Position', 'Status', 'Email', 'Phone']);

            foreach ($roster as $player) {
                fputcsv($output, [
                    $player['name'],
                    $player['jersey_number'],
                    implode(', ', $player['positions']),
                    $player['primary_position'],
                    $player['status'],
                    $player['email'],
                    $player['phone']
                ]);
            }

            fclose($output);
        } else {
            echo json_encode($roster);
        }
    }

    private function authorizeCoachAccess($teamId) {
        $coachId = $_SESSION['user_id'] ?? 2;
        return $this->coachModel->isCoachForTeam($coachId, $teamId);
    }

    private function validatePlayerData($data) {
        $errors = [];

        if (empty($data['user_id'])) {
            $errors['user_id'] = 'Player selection is required';
        }

        if (empty($data['positions']) || !is_array($data['positions'])) {
            $errors['positions'] = 'At least one position is required';
        }

        if (empty($data['primary_position'])) {
            $errors['primary_position'] = 'Primary position is required';
        }

        if (!empty($data['jersey_number']) && ($data['jersey_number'] < 0 || $data['jersey_number'] > 99)) {
            $errors['jersey_number'] = 'Jersey number must be between 0 and 99';
        }

        return $errors;
    }
}