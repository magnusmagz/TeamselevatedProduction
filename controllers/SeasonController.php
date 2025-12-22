<?php
require_once __DIR__ . '/../config/database.php';

class SeasonController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getSeasons() {
        $sql = "SELECT * FROM seasons ORDER BY start_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $seasons = $stmt->fetchAll();

        echo json_encode($seasons);
    }

    public function createSeason() {
        $data = json_decode(file_get_contents('php://input'), true);

        // Validate
        if (empty($data['name']) || empty($data['start_date']) || empty($data['end_date'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Name, start date, and end date are required']);
            return;
        }

        if (strtotime($data['start_date']) >= strtotime($data['end_date'])) {
            http_response_code(400);
            echo json_encode(['error' => 'End date must be after start date']);
            return;
        }

        try {
            $sql = "INSERT INTO seasons (name, start_date, end_date, created_by)
                    VALUES (:name, :start_date, :end_date, :created_by)";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':name' => $data['name'],
                ':start_date' => $data['start_date'],
                ':end_date' => $data['end_date'],
                ':created_by' => $_SESSION['user_id'] ?? 1
            ]);

            if ($result) {
                http_response_code(201);
                echo json_encode([
                    'id' => $this->db->lastInsertId(),
                    'message' => 'Season created successfully'
                ]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create season']);
        }
    }

    public function updateSeason($id) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (strtotime($data['start_date']) >= strtotime($data['end_date'])) {
            http_response_code(400);
            echo json_encode(['error' => 'End date must be after start date']);
            return;
        }

        try {
            $sql = "UPDATE seasons
                    SET name = :name, start_date = :start_date, end_date = :end_date
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':id' => $id,
                ':name' => $data['name'],
                ':start_date' => $data['start_date'],
                ':end_date' => $data['end_date']
            ]);

            if ($result) {
                echo json_encode(['message' => 'Season updated successfully']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update season']);
        }
    }

    public function deleteSeason($id) {
        try {
            // Check if season has teams
            $sql = "SELECT COUNT(*) as count FROM teams WHERE season_id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch();

            if ($result['count'] > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete season with active teams']);
                return;
            }

            // Soft delete
            $sql = "UPDATE seasons SET is_active = FALSE WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([':id' => $id]);

            if ($result) {
                echo json_encode(['message' => 'Season deleted successfully']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete season']);
        }
    }

    public function getCurrentSeason() {
        $sql = "SELECT * FROM seasons
                WHERE is_active = TRUE
                AND start_date <= CURDATE()
                AND end_date >= CURDATE()
                ORDER BY start_date DESC
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $season = $stmt->fetch();

        if ($season) {
            echo json_encode($season);
        } else {
            echo json_encode(['message' => 'No current season']);
        }
    }
}