<?php
/**
 * Help Portal API Gateway
 *
 * Actions: categories, category-articles, article, search, release-notes, release-note,
 *          feedback, create-category, update-category, delete-category,
 *          create-article, update-article, delete-article,
 *          create-release-note, update-release-note, delete-release-note
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';

$auth = AuthMiddleware::requireAuth();
$userId = $auth->getUserId();
$isAdmin = $auth->isSuperAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {

        // ============================================
        // LIST CATEGORIES
        // ============================================
        case 'categories':
            if ($method !== 'GET') { methodNotAllowed(); }

            $stmt = $db->prepare("
                SELECT c.id, c.name, c.slug, c.description, c.icon, c.sort_order, c.role_tag,
                       COUNT(a.id) FILTER (WHERE a.is_published = true) as article_count
                FROM help_categories c
                LEFT JOIN help_articles a ON a.category_id = c.id
                WHERE c.is_active = true
                GROUP BY c.id
                ORDER BY c.sort_order ASC
            ");
            $stmt->execute();
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($categories as &$cat) {
                $cat['article_count'] = (int)$cat['article_count'];
            }

            echo json_encode(['success' => true, 'categories' => $categories]);
            break;

        // ============================================
        // LIST ARTICLES IN CATEGORY
        // ============================================
        case 'category-articles':
            if ($method !== 'GET') { methodNotAllowed(); }

            $categorySlug = $_GET['category_slug'] ?? '';
            if (!$categorySlug) { badRequest('category_slug is required'); }

            $stmt = $db->prepare("
                SELECT a.id, a.title, a.slug, a.summary, a.role_tags, a.sort_order, a.updated_at
                FROM help_articles a
                JOIN help_categories c ON c.id = a.category_id
                WHERE c.slug = :slug AND a.is_published = true
                ORDER BY a.sort_order ASC, a.title ASC
            ");
            $stmt->execute([':slug' => $categorySlug]);
            $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($articles as &$article) {
                $article['role_tags'] = parsePgArray($article['role_tags']);
            }

            echo json_encode(['success' => true, 'articles' => $articles]);
            break;

        // ============================================
        // GET SINGLE ARTICLE
        // ============================================
        case 'article':
            if ($method !== 'GET') { methodNotAllowed(); }

            $categorySlug = $_GET['category_slug'] ?? '';
            $articleSlug = $_GET['slug'] ?? '';
            if (!$categorySlug || !$articleSlug) { badRequest('category_slug and slug are required'); }

            $stmt = $db->prepare("
                SELECT a.*, c.name as category_name, c.slug as category_slug
                FROM help_articles a
                JOIN help_categories c ON c.id = a.category_id
                WHERE c.slug = :cat_slug AND a.slug = :art_slug AND a.is_published = true
            ");
            $stmt->execute([':cat_slug' => $categorySlug, ':art_slug' => $articleSlug]);
            $article = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$article) { notFound('Article not found'); }

            $article['role_tags'] = parsePgArray($article['role_tags']);
            $article['search_keywords'] = parsePgArray($article['search_keywords']);

            // Get user's existing feedback
            $fbStmt = $db->prepare("SELECT is_helpful FROM help_article_feedback WHERE article_id = :aid AND user_id = :uid");
            $fbStmt->execute([':aid' => $article['id'], ':uid' => $userId]);
            $fb = $fbStmt->fetch(PDO::FETCH_ASSOC);
            $article['user_feedback'] = $fb ? (bool)$fb['is_helpful'] : null;

            echo json_encode(['success' => true, 'article' => $article]);
            break;

        // ============================================
        // SEARCH ARTICLES
        // ============================================
        case 'search':
            if ($method !== 'GET') { methodNotAllowed(); }

            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) { badRequest('Search query must be at least 2 characters'); }

            // Full-text search with fallback to ILIKE
            $tsQuery = implode(' & ', array_filter(explode(' ', preg_replace('/[^\w\s]/', '', $q))));

            $stmt = $db->prepare("
                SELECT a.id, a.title, a.slug, a.summary, a.role_tags,
                       c.name as category_name, c.slug as category_slug,
                       ts_rank(to_tsvector('english', a.title || ' ' || COALESCE(a.summary, '') || ' ' || a.body_markdown), to_tsquery('english', :ts)) as rank
                FROM help_articles a
                JOIN help_categories c ON c.id = a.category_id
                WHERE a.is_published = true AND c.is_active = true
                  AND (
                    to_tsvector('english', a.title || ' ' || COALESCE(a.summary, '') || ' ' || a.body_markdown) @@ to_tsquery('english', :ts2)
                    OR a.title ILIKE :like
                    OR a.summary ILIKE :like2
                  )
                ORDER BY rank DESC
                LIMIT 20
            ");
            $likeQ = '%' . $q . '%';
            $stmt->execute([':ts' => $tsQuery, ':ts2' => $tsQuery, ':like' => $likeQ, ':like2' => $likeQ]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as &$r) {
                $r['role_tags'] = parsePgArray($r['role_tags']);
            }

            echo json_encode(['success' => true, 'results' => $results]);
            break;

        // ============================================
        // LIST RELEASE NOTES
        // ============================================
        case 'release-notes':
            if ($method !== 'GET') { methodNotAllowed(); }

            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $offset = ($page - 1) * $perPage;

            $stmt = $db->prepare("
                SELECT id, version, title, slug, body_markdown, release_date, tags, is_published, created_at
                FROM help_release_notes
                WHERE is_published = true
                ORDER BY release_date DESC, created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($notes as &$note) {
                $note['tags'] = parsePgArray($note['tags']);
            }

            // Total count
            $countStmt = $db->query("SELECT COUNT(*) FROM help_release_notes WHERE is_published = true");
            $total = (int)$countStmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'release_notes' => $notes,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage
            ]);
            break;

        // ============================================
        // GET SINGLE RELEASE NOTE
        // ============================================
        case 'release-note':
            if ($method !== 'GET') { methodNotAllowed(); }

            $slug = $_GET['slug'] ?? '';
            if (!$slug) { badRequest('slug is required'); }

            $stmt = $db->prepare("
                SELECT * FROM help_release_notes WHERE slug = :slug AND is_published = true
            ");
            $stmt->execute([':slug' => $slug]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$note) { notFound('Release note not found'); }

            $note['tags'] = parsePgArray($note['tags']);

            echo json_encode(['success' => true, 'release_note' => $note]);
            break;

        // ============================================
        // SUBMIT FEEDBACK
        // ============================================
        case 'feedback':
            if ($method !== 'POST') { methodNotAllowed(); }

            $data = json_decode(file_get_contents('php://input'), true);
            $articleId = (int)($data['article_id'] ?? 0);
            $isHelpful = isset($data['is_helpful']) ? (bool)$data['is_helpful'] : null;

            if (!$articleId || $isHelpful === null) { badRequest('article_id and is_helpful are required'); }

            $stmt = $db->prepare("
                INSERT INTO help_article_feedback (article_id, user_id, is_helpful, comment)
                VALUES (:aid, :uid, :helpful, :comment)
                ON CONFLICT (article_id, user_id)
                DO UPDATE SET is_helpful = :helpful2, comment = :comment2, created_at = NOW()
            ");
            $stmt->execute([
                ':aid' => $articleId,
                ':uid' => $userId,
                ':helpful' => $isHelpful ? 'true' : 'false',
                ':comment' => $data['comment'] ?? null,
                ':helpful2' => $isHelpful ? 'true' : 'false',
                ':comment2' => $data['comment'] ?? null
            ]);

            echo json_encode(['success' => true, 'message' => 'Feedback submitted']);
            break;

        // ============================================
        // ADMIN: CREATE CATEGORY
        // ============================================
        case 'create-category':
            if ($method !== 'POST') { methodNotAllowed(); }
            if (!$isAdmin) { forbidden(); }

            $data = json_decode(file_get_contents('php://input'), true);
            $name = trim($data['name'] ?? '');
            if (!$name) { badRequest('name is required'); }

            $slug = generateSlug($name);

            // Check unique slug
            $check = $db->prepare("SELECT id FROM help_categories WHERE slug = :slug");
            $check->execute([':slug' => $slug]);
            if ($check->fetch()) {
                $slug .= '-' . time();
            }

            $stmt = $db->prepare("
                INSERT INTO help_categories (name, slug, description, icon, sort_order, role_tag)
                VALUES (:name, :slug, :desc, :icon, :sort, :role)
                RETURNING id
            ");
            $stmt->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':desc' => $data['description'] ?? null,
                ':icon' => $data['icon'] ?? null,
                ':sort' => (int)($data['sort_order'] ?? 0),
                ':role' => $data['role_tag'] ?? null
            ]);
            $id = $stmt->fetchColumn();

            echo json_encode(['success' => true, 'id' => (int)$id, 'slug' => $slug]);
            break;

        // ============================================
        // ADMIN: UPDATE CATEGORY
        // ============================================
        case 'update-category':
            if ($method !== 'PUT') { methodNotAllowed(); }
            if (!$isAdmin) { forbidden(); }

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { badRequest('id is required'); }

            $data = json_decode(file_get_contents('php://input'), true);

            $stmt = $db->prepare("
                UPDATE help_categories
                SET name = :name, description = :desc, icon = :icon,
                    sort_order = :sort, role_tag = :role, is_active = :active,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => trim($data['name'] ?? ''),
                ':desc' => $data['description'] ?? null,
                ':icon' => $data['icon'] ?? null,
                ':sort' => (int)($data['sort_order'] ?? 0),
                ':role' => $data['role_tag'] ?? null,
                ':active' => ($data['is_active'] ?? true) ? 'true' : 'false',
                ':id' => $id
            ]);

            echo json_encode(['success' => true]);
            break;

        // ============================================
        // ADMIN: DELETE CATEGORY
        // ============================================
        case 'delete-category':
            if ($method !== 'DELETE') { methodNotAllowed(); }
            if (!$isAdmin) { forbidden(); }

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { badRequest('id is required'); }

            // Check for articles
            $check = $db->prepare("SELECT COUNT(*) FROM help_articles WHERE category_id = :id");
            $check->execute([':id' => $id]);
            if ((int)$check->fetchColumn() > 0) {
                badRequest('Cannot delete category with existing articles. Move or delete articles first.');
            }

            $stmt = $db->prepare("DELETE FROM help_categories WHERE id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode(['success' => true]);
            break;

        // ============================================
        // ADMIN: CREATE ARTICLE
        // ============================================
        case 'create-article':
            if ($method !== 'POST') { methodNotAllowed(); }
            if (!$isAdmin) { forbidden(); }

            $data = json_decode(file_get_contents('php://input'), true);
            $title = trim($data['title'] ?? '');
            $categoryId = (int)($data['category_id'] ?? 0);
            $bodyMarkdown = $data['body_markdown'] ?? '';

            if (!$title || !$categoryId || !$bodyMarkdown) {
                badRequest('title, category_id, and body_markdown are required');
            }

            $slug = generateSlug($title);

            // Ensure unique within category
            $check = $db->prepare("SELECT id FROM help_articles WHERE category_id = :cid AND slug = :slug");
            $check->execute([':cid' => $categoryId, ':slug' => $slug]);
            $i = 2;
            $baseSlug = $slug;
            while ($check->fetch()) {
                $slug = $baseSlug . '-' . $i++;
                $check->execute([':cid' => $categoryId, ':slug' => $slug]);
            }

            $roleTags = $data['role_tags'] ?? [];
            $searchKeywords = $data['search_keywords'] ?? [];

            $stmt = $db->prepare("
                INSERT INTO help_articles (category_id, title, slug, summary, body_markdown,
                    role_tags, related_feature, search_keywords, sort_order, is_published, author_user_id)
                VALUES (:cid, :title, :slug, :summary, :body,
                    :roles, :feature, :keywords, :sort, :published, :author)
                RETURNING id
            ");
            $stmt->execute([
                ':cid' => $categoryId,
                ':title' => $title,
                ':slug' => $slug,
                ':summary' => $data['summary'] ?? null,
                ':body' => $bodyMarkdown,
                ':roles' => toPgArray($roleTags),
                ':feature' => $data['related_feature'] ?? null,
                ':keywords' => toPgArray($searchKeywords),
                ':sort' => (int)($data['sort_order'] ?? 0),
                ':published' => ($data['is_published'] ?? true) ? 'true' : 'false',
                ':author' => $userId
            ]);
            $id = $stmt->fetchColumn();

            echo json_encode(['success' => true, 'id' => (int)$id, 'slug' => $slug]);
            break;

        // ============================================
        // ADMIN: UPDATE ARTICLE
        // ============================================
        case 'update-article':
            if ($method !== 'PUT') { methodNotAllowed(); }
            if (!$isAdmin) { forbidden(); }

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { badRequest('id is required'); }

            $data = json_decode(file_get_contents('php://input'), true);
            $roleTags = $data['role_tags'] ?? [];
            $searchKeywords = $data['search_keywords'] ?? [];

            $stmt = $db->prepare("
                UPDATE help_articles
                SET title = :title, summary = :summary, body_markdown = :body,
                    role_tags = :roles, related_feature = :feature, search_keywords = :keywords,
                    sort_order = :sort, is_published = :published, category_id = :cid,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':title' => trim($data['title'] ?? ''),
                ':summary' => $data['summary'] ?? null,
                ':body' => $data['body_markdown'] ?? '',
                ':roles' => toPgArray($roleTags),
                ':feature' => $data['related_feature'] ?? null,
                ':keywords' => toPgArray($searchKeywords),
                ':sort' => (int)($data['sort_order'] ?? 0),
                ':published' => ($data['is_published'] ?? true) ? 'true' : 'false',
                ':cid' => (int)($data['category_id'] ?? 0),
                ':id' => $id
            ]);

            echo json_encode(['success' => true]);
            break;

        // ============================================
        // ADMIN: DELETE ARTICLE
        // ============================================
        case 'delete-article':
            if ($method !== 'DELETE') { methodNotAllowed(); }
            if (!$isAdmin) { forbidden(); }

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { badRequest('id is required'); }

            $stmt = $db->prepare("DELETE FROM help_articles WHERE id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode(['success' => true]);
            break;

        // ============================================
        // ADMIN: CREATE RELEASE NOTE
        // ============================================
        case 'create-release-note':
            if ($method !== 'POST') { methodNotAllowed(); }
            if (!$isAdmin) { forbidden(); }

            $data = json_decode(file_get_contents('php://input'), true);
            $title = trim($data['title'] ?? '');
            $bodyMarkdown = $data['body_markdown'] ?? '';

            if (!$title || !$bodyMarkdown) {
                badRequest('title and body_markdown are required');
            }

            $slug = generateSlug($title);
            $check = $db->prepare("SELECT id FROM help_release_notes WHERE slug = :slug");
            $check->execute([':slug' => $slug]);
            if ($check->fetch()) {
                $slug .= '-' . time();
            }

            $tags = $data['tags'] ?? [];

            $stmt = $db->prepare("
                INSERT INTO help_release_notes (version, title, slug, body_markdown, release_date, tags, is_published, author_user_id)
                VALUES (:version, :title, :slug, :body, :date, :tags, :published, :author)
                RETURNING id
            ");
            $stmt->execute([
                ':version' => $data['version'] ?? null,
                ':title' => $title,
                ':slug' => $slug,
                ':body' => $bodyMarkdown,
                ':date' => $data['release_date'] ?? date('Y-m-d'),
                ':tags' => toPgArray($tags),
                ':published' => ($data['is_published'] ?? true) ? 'true' : 'false',
                ':author' => $userId
            ]);
            $id = $stmt->fetchColumn();

            echo json_encode(['success' => true, 'id' => (int)$id, 'slug' => $slug]);
            break;

        // ============================================
        // ADMIN: UPDATE RELEASE NOTE
        // ============================================
        case 'update-release-note':
            if ($method !== 'PUT') { methodNotAllowed(); }
            if (!$isAdmin) { forbidden(); }

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { badRequest('id is required'); }

            $data = json_decode(file_get_contents('php://input'), true);
            $tags = $data['tags'] ?? [];

            $stmt = $db->prepare("
                UPDATE help_release_notes
                SET version = :version, title = :title, body_markdown = :body,
                    release_date = :date, tags = :tags, is_published = :published,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':version' => $data['version'] ?? null,
                ':title' => trim($data['title'] ?? ''),
                ':body' => $data['body_markdown'] ?? '',
                ':date' => $data['release_date'] ?? date('Y-m-d'),
                ':tags' => toPgArray($tags),
                ':published' => ($data['is_published'] ?? true) ? 'true' : 'false',
                ':id' => $id
            ]);

            echo json_encode(['success' => true]);
            break;

        // ============================================
        // ADMIN: DELETE RELEASE NOTE
        // ============================================
        case 'delete-release-note':
            if ($method !== 'DELETE') { methodNotAllowed(); }
            if (!$isAdmin) { forbidden(); }

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { badRequest('id is required'); }

            $stmt = $db->prepare("DELETE FROM help_release_notes WHERE id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode(['success' => true]);
            break;

        // ============================================
        // ADMIN: LIST ALL (including unpublished)
        // ============================================
        case 'admin-list':
            if ($method !== 'GET') { methodNotAllowed(); }
            if (!$isAdmin) { forbidden(); }

            $stmt = $db->prepare("
                SELECT a.*, c.name as category_name, c.slug as category_slug
                FROM help_articles a
                JOIN help_categories c ON c.id = a.category_id
                ORDER BY c.sort_order ASC, a.sort_order ASC, a.title ASC
            ");
            $stmt->execute();
            $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($articles as &$a) {
                $a['role_tags'] = parsePgArray($a['role_tags']);
                $a['is_published'] = (bool)$a['is_published'];
            }

            // Release notes too
            $rnStmt = $db->query("SELECT * FROM help_release_notes ORDER BY release_date DESC");
            $releaseNotes = $rnStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($releaseNotes as &$rn) {
                $rn['tags'] = parsePgArray($rn['tags']);
                $rn['is_published'] = (bool)$rn['is_published'];
            }

            // Categories
            $catStmt = $db->query("SELECT * FROM help_categories ORDER BY sort_order ASC");
            $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'articles' => $articles,
                'release_notes' => $releaseNotes,
                'categories' => $categories
            ]);
            break;

        default:
            notFound('Unknown action');
    }

} catch (PDOException $e) {
    error_log("Help Gateway PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
} catch (Exception $e) {
    error_log("Help Gateway Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function methodNotAllowed() {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

function badRequest($msg = 'Bad request') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

function notFound($msg = 'Not found') {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

function forbidden() {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit();
}

function generateSlug($text) {
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    return trim($slug, '-');
}

function parsePgArray($str) {
    if (!$str || $str === '{}') return [];
    $str = trim($str, '{}');
    if (!$str) return [];
    return array_map(function($v) {
        return trim($v, '"');
    }, str_getcsv($str));
}

function toPgArray($arr) {
    if (empty($arr)) return '{}';
    $escaped = array_map(function($v) {
        return '"' . str_replace('"', '\\"', $v) . '"';
    }, $arr);
    return '{' . implode(',', $escaped) . '}';
}
