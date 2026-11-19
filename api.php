<?php
require __DIR__ . '/config.php';

$method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_SERVER['REQUEST_METHOD']);
$path   = trim($_GET['path'] ?? '', '/');
$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$in     = $in + $_POST;
$UPLOAD_DIR = __DIR__ . '/uploads/';

function body(string $k, $d = '') { global $in; return isset($in[$k]) ? trim((string)$in[$k]) : $d; }

function save_upload(): ?string {
    global $UPLOAD_DIR;
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) return null;
    $f = $_FILES['image'];
    if ($f['size'] > 5 * 1024 * 1024) fail('Ukuran foto maksimal 5MB.');
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][mime_content_type($f['tmp_name'])] ?? null;
    if (!$ext) fail('Format foto harus JPG, PNG, WEBP, atau GIF.');
    $name = time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);
    move_uploaded_file($f['tmp_name'], $UPLOAD_DIR . $name);
    return 'uploads/' . $name;
}
function delete_upload(?string $img): void {
    if ($img && strpos($img, 'uploads/') === 0 && is_file(__DIR__ . '/' . $img)) @unlink(__DIR__ . '/' . $img);
}

/* ---------- AUTH ---------- */
if ($path === 'register' && $method === 'POST') {
    $name = body('name'); $email = strtolower(body('email')); $pass = (string)($in['password'] ?? '');
    if (!$name || !$email || !$pass) fail('Semua kolom wajib diisi.');
    if (strlen($pass) < 6) fail('Password minimal 6 karakter.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Format email tidak valid.');
    $q = $pdo->prepare('SELECT 1 FROM users WHERE email = ?'); $q->execute([$email]);
    if ($q->fetch()) fail('Email sudah terdaftar.', 409);
    $pdo->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)')
        ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'user'];
    json_out(['user' => $_SESSION['user']]);
}
if ($path === 'login' && $method === 'POST') {
    $as = body('as') === 'admin' ? 'admin' : 'user';
    $pass = (string)($in['password'] ?? '');
    if ($as === 'admin') {
        // Login admin: USERNAME + password (khusus role admin)
        ensure_username_column($pdo);
        $q = $pdo->prepare("SELECT * FROM users WHERE LOWER(username) = ? AND role = 'admin'"); $q->execute([strtolower(body('username'))]);
        $u = $q->fetch();
        if (!$u || !password_verify($pass, $u['password'])) fail('Username atau password salah.', 401);
    } else {
        // Login pengguna: EMAIL + password
        $q = $pdo->prepare('SELECT * FROM users WHERE email = ?'); $q->execute([strtolower(body('email'))]);
        $u = $q->fetch();
        if (!$u || !password_verify($pass, $u['password'])) fail('Email atau password salah.', 401);
        if ($u['role'] === 'admin') fail('Akun admin tidak bisa login di sini. Gunakan halaman Login Admin.', 403);
    }
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
    json_out(['user' => $_SESSION['user']]);
}
if ($path === 'logout' && $method === 'POST') { session_destroy(); json_out(['ok' => true]); }
if ($path === 'me') json_out(['user' => current_user()]);

/* ---------- MOBIL (publik) ---------- */
if ($path === 'cars' && $method === 'GET') {
    $brand = $_GET['brand'] ?? 'all'; $sort = $_GET['sort'] ?? '';
    $orders = ['price_asc' => 'price ASC', 'price_desc' => 'price DESC', 'newest' => 'id DESC'];
    $sql = 'SELECT * FROM cars'; $args = [];
    if ($brand !== 'all' && $brand !== '') { $sql .= ' WHERE brand = ?'; $args[] = $brand; }
    $sql .= ' ORDER BY ' . ($orders[$sort] ?? 'id ASC');
    $q = $pdo->prepare($sql); $q->execute($args);
    json_out($q->fetchAll());
}
if ($path === 'brands' && $method === 'GET') {
    json_out($pdo->query('SELECT DISTINCT brand FROM cars ORDER BY brand')->fetchAll(PDO::FETCH_COLUMN));
}

/* ---------- BOOKING ---------- */
if ($path === 'bookings' && $method === 'POST') {
    $name = body('name'); $phone = body('phone'); $date = body('visit_date');
    if (!$name || !$phone || !$date) fail('Nama, telepon, dan tanggal wajib diisi.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Format tanggal tidak valid.');
    $u = current_user();
    $car = $in['car_id'] ?? null;
    $color = body('color');
    if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) fail('Warna tidak valid.');
    ensure_color_column($pdo);
    $pdo->prepare('INSERT INTO bookings (user_id,car_id,name,phone,visit_date,note,color) VALUES (?,?,?,?,?,?,?)')
        ->execute([$u['id'] ?? null, $car ? (int)$car : null, $name, $phone, $date, body('note'), $color]);
    json_out(['ok' => true]);
}

/* ---------- PESANAN SAYA (user yang login) ---------- */
if (($path === 'my-bookings' || $path === 'bookings') && $method === 'GET') {
    $u = current_user();
    if (!$u) fail('Silakan login terlebih dahulu.', 401);
    ensure_color_column($pdo);
    $q = $pdo->prepare('SELECT b.*, c.brand AS c_brand, c.model AS c_model, c.variant AS c_variant, c.image AS c_image, c.price AS c_price
                        FROM bookings b LEFT JOIN cars c ON c.id = b.car_id
                        WHERE b.user_id = ? ORDER BY b.id DESC');
    $q->execute([$u['id']]);
    $rows = $q->fetchAll();
    foreach ($rows as &$r) {
        $r['car_name'] = $r['c_brand'] ? trim("{$r['c_brand']} {$r['c_model']} {$r['c_variant']}") : null;
        $r['car_image'] = $r['c_image']; $r['car_price'] = $r['c_price'];
        unset($r['c_brand'], $r['c_model'], $r['c_variant'], $r['c_image'], $r['c_price']);
    }
    json_out($rows);
}
if (preg_match('#^my-bookings/(\d+)$#', $path, $m) && $method === 'PATCH') {   // user membatalkan booking miliknya
    $u = current_user();
    if (!$u) fail('Silakan login terlebih dahulu.', 401);
    $q = $pdo->prepare('SELECT status FROM bookings WHERE id = ? AND user_id = ?'); $q->execute([(int)$m[1], $u['id']]);
    $st = $q->fetchColumn();
    if ($st === false) fail('Booking tidak ditemukan.', 404);
    if ($st !== 'pending') fail('Hanya booking berstatus menunggu yang bisa dibatalkan.');
    $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id = ?")->execute([(int)$m[1]]);
    json_out(['ok' => true]);
}

/* ---------- ADMIN ---------- */
if (strpos($path, 'admin/') === 0) {
    require_admin();
    $seg = explode('/', $path); // admin/<resource>/<id>
    $res = $seg[1] ?? ''; $id = isset($seg[2]) ? (int)$seg[2] : 0;
    $one = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

    if ($res === 'stats' && $method === 'GET') {
        json_out([
            'cars' => $one('SELECT COUNT(*) FROM cars'),
            'available' => $one("SELECT COUNT(*) FROM cars WHERE status='available'"),
            'sold' => $one("SELECT COUNT(*) FROM cars WHERE status='sold'"),
            'users' => $one("SELECT COUNT(*) FROM users WHERE role='user'"),
            'bookings' => $one('SELECT COUNT(*) FROM bookings'),
            'pending' => $one("SELECT COUNT(*) FROM bookings WHERE status='pending'"),
            'value' => $one("SELECT COALESCE(SUM(price),0) FROM cars WHERE status='available'"),
        ]);
    }

    if ($res === 'cars') {
        $fields = ['brand','model','variant','engine','power','torque','drivetrain','transmission','price','colors','status'];
        if ($method === 'POST' && !$id) {
            if (!body('brand') || !body('model') || body('price') === '') fail('Merek, model, dan harga wajib diisi.');
            $status = body('status', 'available') === 'sold' ? 'sold' : 'available';
            $img = save_upload() ?? '';
            $pdo->prepare('INSERT INTO cars (brand,model,variant,engine,power,torque,drivetrain,transmission,price,colors,image,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([body('brand'), body('model'), body('variant'), body('engine'), body('power'), body('torque'),
                           body('drivetrain'), body('transmission'), (int)body('price', 0), body('colors', '#111111') ?: '#111111', $img, $status]);
            json_out(['ok' => true]);
        }
        if ($method === 'PUT' && $id) {
            $q = $pdo->prepare('SELECT * FROM cars WHERE id = ?'); $q->execute([$id]); $car = $q->fetch();
            if (!$car) fail('Mobil tidak ditemukan.', 404);
            $vals = [];
            foreach ($fields as $f) {
                $v = array_key_exists($f, $in) ? trim((string)$in[$f]) : $car[$f];
                if ($f === 'price') $v = (int)$v;
                if ($f === 'status') $v = $v === 'sold' ? 'sold' : 'available';
                $vals[] = $v;
            }
            $img = $car['image'];
            if ($new = save_upload()) { delete_upload($img); $img = $new; }
            $pdo->prepare('UPDATE cars SET ' . implode('=?,', $fields) . '=?, image=? WHERE id=?')->execute([...$vals, $img, $id]);
            json_out(['ok' => true]);
        }
        if ($method === 'DELETE' && $id) {
            $q = $pdo->prepare('SELECT image FROM cars WHERE id = ?'); $q->execute([$id]);
            delete_upload($q->fetchColumn() ?: null);
            $pdo->prepare('DELETE FROM cars WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }
    }

    if ($res === 'bookings') {
        if ($method === 'GET') {
            ensure_color_column($pdo);
            $rows = $pdo->query('SELECT b.*, c.brand AS c_brand, c.model AS c_model, c.variant AS c_variant
                                 FROM bookings b LEFT JOIN cars c ON c.id = b.car_id ORDER BY b.id DESC')->fetchAll();
            foreach ($rows as &$r) {
                $r['car_name'] = $r['c_brand'] ? trim("{$r['c_brand']} {$r['c_model']} {$r['c_variant']}") : null;
                unset($r['c_brand'], $r['c_model'], $r['c_variant']);
            }
            json_out($rows);
        }
        if ($method === 'PATCH' && $id) {
            $st = body('status');
            if (!in_array($st, ['pending','confirmed','done','cancelled'], true)) fail('Status tidak valid.');
            $pdo->prepare('UPDATE bookings SET status=? WHERE id=?')->execute([$st, $id]);
            json_out(['ok' => true]);
        }
        if ($method === 'DELETE' && $id) { $pdo->prepare('DELETE FROM bookings WHERE id=?')->execute([$id]); json_out(['ok' => true]); }
    }

    if ($res === 'account') {   // Akun Saya: ganti username / password admin yang sedang login
        ensure_username_column($pdo);
        $me = current_user();
        $q = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $q->execute([$me['id']]); $row = $q->fetch();
        if ($method === 'GET') json_out(['name' => $row['name'], 'email' => $row['email'], 'username' => $row['username']]);
        if ($method === 'PATCH') {
            if (!password_verify((string)($in['current_password'] ?? ''), $row['password'])) fail('Password saat ini salah.', 403);
            $username = strtolower(body('username', $row['username']));
            if (!preg_match('/^[a-z0-9_.-]{3,30}$/', $username)) fail('Username 3-30 karakter: huruf, angka, titik, strip, atau garis bawah.');
            $q = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(username)=? AND id<>?'); $q->execute([$username, $me['id']]);
            if ($q->fetch()) fail('Username sudah dipakai.', 409);
            $newpw = (string)($in['new_password'] ?? '');
            if ($newpw !== '' && strlen($newpw) < 6) fail('Password baru minimal 6 karakter.');
            $pdo->prepare('UPDATE users SET username=?, password=? WHERE id=?')
                ->execute([$username, $newpw !== '' ? password_hash($newpw, PASSWORD_DEFAULT) : $row['password'], $me['id']]);
            json_out(['ok' => true]);
        }
    }

    if ($res === 'users') {
        $me = current_user();
        ensure_username_column($pdo);
        if ($method === 'GET') json_out($pdo->query('SELECT id,name,email,username,role,created_at FROM users ORDER BY id DESC')->fetchAll());
        if ($method === 'PATCH' && $id) {
            if (!in_array(body('role'), ['user','admin'], true)) fail('Role tidak valid.');
            if ($id === $me['id']) fail('Tidak bisa mengubah role akun sendiri.');
            $pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute([body('role'), $id]);
            ensure_username_column($pdo);   // admin baru otomatis dapat username
            json_out(['ok' => true]);
        }
        if ($method === 'DELETE' && $id) {
            if ($id === $me['id']) fail('Tidak bisa menghapus akun sendiri.');
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            json_out(['ok' => true]);
        }
    }
}

fail('Endpoint tidak ditemukan.', 404);
