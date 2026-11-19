<?php
// ====== Pengaturan koneksi database (XAMPP bawaan) ======
const DB_HOST = 'localhost';
const DB_NAME = 'sjdm';
const DB_USER = 'root';
const DB_PASS = '';   // XAMPP bawaan: kosong

session_start();

$dsn = getenv('SJDM_TEST_DSN') ?: 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
try {
    $pdo = new PDO($dsn, getenv('SJDM_TEST_DSN') ? null : DB_USER, getenv('SJDM_TEST_DSN') ? null : DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Gagal terhubung ke database "' . DB_NAME . '". Pastikan MySQL di XAMPP menyala dan sjdm.sql sudah diimport.']));
}

// Tambah kolom bookings.color otomatis kalau database lama belum punya (tidak perlu import ulang)
function ensure_color_column(PDO $pdo): void {
    try { $pdo->query('SELECT color FROM bookings LIMIT 1'); }
    catch (PDOException $e) { $pdo->exec("ALTER TABLE bookings ADD COLUMN color VARCHAR(30) DEFAULT ''"); }
}

// Kolom users.username (untuk login admin). Dibuat otomatis kalau database lama belum punya,
// dan setiap admin yang belum punya username diberi username dari bagian depan emailnya.
function ensure_username_column(PDO $pdo): void {
    try { $pdo->query('SELECT username FROM users LIMIT 1'); }
    catch (PDOException $e) {
        $pdo->exec('ALTER TABLE users ADD COLUMN username VARCHAR(50) NULL');
        try { $pdo->exec('CREATE UNIQUE INDEX idx_users_username ON users(username)'); } catch (PDOException $e2) {}
    }
    $rows = $pdo->query("SELECT id,email FROM users WHERE role='admin' AND (username IS NULL OR username='')")->fetchAll();
    foreach ($rows as $r) {
        $base = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string)strstr($r['email'], '@', true))) ?: 'admin';
        $q = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(username)=? AND id<>?'); $q->execute([$base, $r['id']]);
        $name = $q->fetch() ? $base . $r['id'] : $base;
        $pdo->prepare('UPDATE users SET username=? WHERE id=?')->execute([$name, $r['id']]);
    }
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): void { json_out(['error' => $msg], $code); }
function current_user() { return $_SESSION['user'] ?? null; }
function require_admin(): void {
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') fail('Akses khusus admin.', 403);
}
