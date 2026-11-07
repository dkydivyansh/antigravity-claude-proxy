<?php
/**
 * Antigravity Claude Proxy — Remote Account Storage API
 *
 * Stores account configuration (OAuth tokens, refresh tokens, rate-limit state)
 * in a local SQLite database so stateless Render deployments persist state across
 * redeploys and restarts.
 *
 * Endpoints:
 *   GET  /api.php?action=load          → returns full accounts JSON
 *   POST /api.php?action=save          → saves full accounts JSON (body = JSON)
 *   GET  /api.php?action=health        → liveness / diagnostic check
 */

// ─────────────────────────── CONFIGURATION ───────────────────────────────────

// Hardcoded secret key for your PHP server.
// Set REMOTE_STORAGE_SECRET on Render to match this value!
define('HARDCODED_SECRET', '54754736657657356767547654');

define('VERSION', '1.1.0');
define('DB_FILE', __DIR__ . '/storage.sqlite');

// Secret resolution: env var > hardcoded constant > fallback
$secret = getenv('STORAGE_SECRET') ?: HARDCODED_SECRET;

// ─────────────────────────── Helpers ─────────────────────────────────────────

function json_response(int $status, mixed $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_auth(string $secret): void {
    if (empty($secret)) {
        return; // open if no secret configured
    }

    // 1. Check X-Api-Secret header (sent by remote-storage.js)
    $provided = $_SERVER['HTTP_X_API_SECRET'] ?? '';

    // 2. Check Authorization: Bearer <secret>
    if (empty($provided) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $provided = trim($m[1]);
        }
    }

    // 3. Fallback: ?secret= query parameter (convenient for quick browser tests)
    if (empty($provided) && isset($_GET['secret'])) {
        $provided = $_GET['secret'];
    }

    if (!hash_equals($secret, (string)$provided)) {
        json_response(401, [
            'ok' => false,
            'error' => 'Unauthorized: Invalid secret key. Check X-Api-Secret header or REMOTE_STORAGE_SECRET.'
        ]);
    }
}

// ─────────────────────────── Database & Auto-Creation ────────────────────────

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    // Auto-create sqlite database file if it does not exist
    if (!file_exists(DB_FILE)) {
        @touch(DB_FILE);
        @chmod(DB_FILE, 0666);
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Optimize SQLite for concurrent web requests
    @$pdo->exec('PRAGMA journal_mode=WAL');
    @$pdo->exec('PRAGMA synchronous=NORMAL');

    // Auto-create table using standard SQLite CURRENT_TIMESTAMP
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS kv_store (
                key        TEXT PRIMARY KEY,
                value      TEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    } catch (Throwable $e) {
        // If table was left in an invalid state from previous syntax errors, drop and recreate
        $pdo->exec('DROP TABLE IF EXISTS kv_store');
        $pdo->exec('
            CREATE TABLE kv_store (
                key        TEXT PRIMARY KEY,
                value      TEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    return $pdo;
}

function kv_get(string $key): ?string {
    $stmt = get_db()->prepare('SELECT value FROM kv_store WHERE key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : null;
}

function kv_set(string $key, string $value): void {
    $stmt = get_db()->prepare('
        INSERT INTO kv_store (key, value, updated_at)
             VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(key) DO UPDATE
            SET value = excluded.value,
                updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$key, $value]);
}

// ─────────────────────────── Request Routing ─────────────────────────────────

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Secret, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';

// ── Health / Diagnostic Endpoint ─────────────────────────────────────────────
if ($action === 'health') {
    try {
        $db = get_db();
        $dbExists = file_exists(DB_FILE);
        $dbWritable = is_writable(DB_FILE) || is_writable(dirname(DB_FILE));

        json_response(200, [
            'ok' => true,
            'version' => VERSION,
            'db_file' => DB_FILE,
            'db_exists' => $dbExists,
            'db_writable' => $dbWritable,
            'time' => date('c')
        ]);
    } catch (Throwable $e) {
        json_response(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

// All data operations require authentication
require_auth($secret);

// ── Load Endpoint ─────────────────────────────────────────────────────────────
if ($action === 'load' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $raw = kv_get('accounts_config');
        if ($raw === null) {
            // First time run — return clean empty state expected by storage.js
            json_response(200, [
                'accounts'    => [],
                'settings'    => (object)[],
                'activeIndex' => 0,
            ]);
        }

        // Return the exact raw JSON from the database
        // Avoids lossy decode/encode cycle that converts empty objects {} to []
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo $raw;
        exit;
    } catch (Throwable $e) {
        json_response(500, ['error' => $e->getMessage()]);
    }
}

// ── Save Endpoint ─────────────────────────────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $body = file_get_contents('php://input');
        if (empty($body)) {
            json_response(400, ['error' => 'Empty request body']);
        }

        $parsed = json_decode($body, true);
        if ($parsed === null) {
            json_response(400, ['error' => 'Invalid JSON body']);
        }

        if (!isset($parsed['accounts']) || !is_array($parsed['accounts'])) {
            json_response(400, ['error' => 'Missing accounts array in payload']);
        }

        kv_set('accounts_config', $body);

        json_response(200, [
            'ok'      => true,
            'saved'   => count($parsed['accounts']),
            'updated' => date('c'),
        ]);
    } catch (Throwable $e) {
        json_response(500, ['error' => $e->getMessage()]);
    }
}

// ── Unknown Action ───────────────────────────────────────────────────────────
json_response(400, [
    'error' => "Unknown action '{$action}'. Supported: health, load, save"
]);
