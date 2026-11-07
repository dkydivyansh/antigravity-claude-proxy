<?php
/**
 * Antigravity Claude Proxy — Remote Account Storage API
 *
 * Stores account config (OAuth tokens, refresh tokens, rate-limit state)
 * in a local SQLite database so the stateless Render deployment can
 * persist state across redeploys/restarts.
 *
 * Endpoints:
 *   GET  /api.php?action=load          → returns full accounts JSON
 *   POST /api.php?action=save          → saves full accounts JSON (body = JSON)
 *   GET  /api.php?action=health        → liveness check
 *
 * Authentication:
 *   Every request must include the header:
 *     X-Api-Secret: <your-secret-from-.env>
 *
 * Setup:
 *   1. Copy this file and .env.example to your PHP server
 *   2. Rename .env.example → .env and set STORAGE_SECRET
 *   3. Make sure the directory is writable (chmod 755 / chown www-data)
 *   4. Set REMOTE_STORAGE_URL + REMOTE_STORAGE_SECRET on Render
 */

// ─────────────────────────── Bootstrap ───────────────────────────────────────

define('VERSION', '1.0.0');
define('DB_FILE', __DIR__ . '/storage.sqlite');

// Load .env file if present (simple key=value parser, no library needed)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
        putenv(trim($key) . '=' . trim($val));
    }
}

$secret = $_ENV['STORAGE_SECRET'] ?? getenv('STORAGE_SECRET') ?? '';

// ─────────────────────────── Helpers ─────────────────────────────────────────

function json_response(int $status, mixed $data): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_auth(string $secret): void {
    if (empty($secret)) {
        // No secret configured → open (useful for local dev only)
        return;
    }
    $provided = $_SERVER['HTTP_X_API_SECRET'] ?? '';
    if (!hash_equals($secret, $provided)) {
        json_response(401, ['error' => 'Unauthorized']);
    }
}

// ─────────────────────────── Database ────────────────────────────────────────

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // WAL mode: faster writes, allows concurrent reads
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');

    // Single table: key → json_value + timestamps
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS kv_store (
            key        TEXT PRIMARY KEY,
            value      TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (datetime("now"))
        )
    ');

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
             VALUES (?, ?, datetime("now"))
        ON CONFLICT(key) DO UPDATE
            SET value = excluded.value,
                updated_at = excluded.updated_at
    ');
    $stmt->execute([$key, $value]);
}

// ─────────────────────────── Request routing ─────────────────────────────────

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Secret');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Pre-flight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';

// ── Health ────────────────────────────────────────────────────────────────────
if ($action === 'health') {
    // Health check: no auth required so Render/UptimeRobot can ping freely
    try {
        get_db(); // ensure DB opens
        json_response(200, ['ok' => true, 'version' => VERSION]);
    } catch (Throwable $e) {
        json_response(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

// All other routes require authentication
require_auth($secret);

// ── Load ──────────────────────────────────────────────────────────────────────
if ($action === 'load' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $raw = kv_get('accounts_config');
        if ($raw === null) {
            // No data yet — return empty structure identical to what storage.js expects
            json_response(200, [
                'accounts'    => [],
                'settings'    => (object)[],
                'activeIndex' => 0,
            ]);
        }
        $data = json_decode($raw, true);
        if ($data === null) {
            json_response(500, ['error' => 'Corrupted data in database']);
        }
        json_response(200, $data);
    } catch (Throwable $e) {
        json_response(500, ['error' => $e->getMessage()]);
    }
}

// ── Save ──────────────────────────────────────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $body = file_get_contents('php://input');
        if (empty($body)) {
            json_response(400, ['error' => 'Empty body']);
        }

        // Validate it's parseable JSON before storing
        $parsed = json_decode($body, true);
        if ($parsed === null) {
            json_response(400, ['error' => 'Invalid JSON body']);
        }

        // Basic schema check — must have accounts array
        if (!isset($parsed['accounts']) || !is_array($parsed['accounts'])) {
            json_response(400, ['error' => 'Missing accounts array']);
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

// ── Fallback ──────────────────────────────────────────────────────────────────
json_response(400, ['error' => "Unknown action: $action"]);

