const express = require('express');
const session = require('express-session');
const bcrypt = require('bcryptjs');
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const db = require('./db');

const app = express();
const PORT = process.env.PORT || 3000;
const UPLOAD_DIR = path.join(__dirname, 'public', 'uploads');
fs.mkdirSync(UPLOAD_DIR, { recursive: true });

app.use(express.json());
app.use(session({
  secret: process.env.SESSION_SECRET || 'ganti-secret-ini-di-produksi',
  resave: false,
  saveUninitialized: false,
  cookie: { httpOnly: true, sameSite: 'lax', maxAge: 1000 * 60 * 60 * 8 },
}));
app.use(express.static(path.join(__dirname, 'public')));

const upload = multer({
  storage: multer.diskStorage({
    destination: UPLOAD_DIR,
    filename: (req, file, cb) => cb(null, Date.now() + '-' + Math.round(Math.random() * 1e6) + path.extname(file.originalname).toLowerCase()),
  }),
  limits: { fileSize: 5 * 1024 * 1024 },
  fileFilter: (req, file, cb) => cb(null, /^image\/(jpeg|png|webp|gif)$/.test(file.mimetype)),
});

const requireLogin = (req, res, next) =>
  req.session.user ? next() : res.status(401).json({ error: 'Silakan login terlebih dahulu.' });
const requireAdmin = (req, res, next) =>
  req.session.user && req.session.user.role === 'admin' ? next() : res.status(403).json({ error: 'Akses khusus admin.' });

/* ---------- AUTH ---------- */
app.post('/api/register', (req, res) => {
  const { name, email, password } = req.body;
  if (!name || !email || !password) return res.status(400).json({ error: 'Semua kolom wajib diisi.' });
  if (password.length < 6) return res.status(400).json({ error: 'Password minimal 6 karakter.' });
  if (!/^\S+@\S+\.\S+$/.test(email)) return res.status(400).json({ error: 'Format email tidak valid.' });
  if (db.prepare('SELECT 1 FROM users WHERE email = ?').get(email.toLowerCase()))
    return res.status(409).json({ error: 'Email sudah terdaftar.' });
  const info = db.prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)')
    .run(name.trim(), email.toLowerCase(), bcrypt.hashSync(password, 10));
  req.session.user = { id: info.lastInsertRowid, name: name.trim(), email: email.toLowerCase(), role: 'user' };
  res.json({ user: req.session.user });
});

app.post('/api/login', (req, res) => {
  const { email, password } = req.body;
  const u = db.prepare('SELECT * FROM users WHERE email = ?').get((email || '').toLowerCase());
  if (!u || !bcrypt.compareSync(password || '', u.password))
    return res.status(401).json({ error: 'Email atau password salah.' });
  req.session.user = { id: u.id, name: u.name, email: u.email, role: u.role };
  res.json({ user: req.session.user });
});

app.post('/api/logout', (req, res) => req.session.destroy(() => res.json({ ok: true })));
app.get('/api/me', (req, res) => res.json({ user: req.session.user || null }));

/* ---------- CARS (publik) ---------- */
app.get('/api/cars', (req, res) => {
  const { brand, sort } = req.query;
  let sql = 'SELECT * FROM cars';
  const args = [];
  if (brand && brand !== 'all') { sql += ' WHERE brand = ?'; args.push(brand); }
  const orders = { price_asc: 'price ASC', price_desc: 'price DESC', newest: 'id DESC' };
  sql += ' ORDER BY ' + (orders[sort] || 'id ASC');
  res.json(db.prepare(sql).all(...args));
});
app.get('/api/brands', (req, res) =>
  res.json(db.prepare('SELECT DISTINCT brand FROM cars ORDER BY brand').all().map(r => r.brand)));

/* ---------- BOOKING ---------- */
app.post('/api/bookings', (req, res) => {
  const { car_id, name, phone, visit_date, note } = req.body;
  if (!name || !phone || !visit_date) return res.status(400).json({ error: 'Nama, telepon, dan tanggal wajib diisi.' });
  db.prepare('INSERT INTO bookings (user_id,car_id,name,phone,visit_date,note) VALUES (?,?,?,?,?,?)')
    .run(req.session.user ? req.session.user.id : null, car_id || null, name, phone, visit_date, note || '');
  res.json({ ok: true });
});

/* ---------- ADMIN ---------- */
app.get('/api/admin/stats', requireAdmin, (req, res) => {
  const one = (q) => db.prepare(q).get().c;
  res.json({
    cars: one('SELECT COUNT(*) c FROM cars'),
    available: one("SELECT COUNT(*) c FROM cars WHERE status='available'"),
    sold: one("SELECT COUNT(*) c FROM cars WHERE status='sold'"),
    users: one("SELECT COUNT(*) c FROM users WHERE role='user'"),
    bookings: one('SELECT COUNT(*) c FROM bookings'),
    pending: one("SELECT COUNT(*) c FROM bookings WHERE status='pending'"),
    value: one("SELECT COALESCE(SUM(price),0) c FROM cars WHERE status='available'"),
  });
});

const carFields = ['brand', 'model', 'variant', 'engine', 'power', 'torque', 'drivetrain', 'transmission', 'price', 'colors', 'status'];

app.post('/api/admin/cars', requireAdmin, upload.single('image'), (req, res) => {
  const b = req.body;
  if (!b.brand || !b.model || !b.price) return res.status(400).json({ error: 'Merek, model, dan harga wajib diisi.' });
  const image = req.file ? '/uploads/' + req.file.filename : '';
  db.prepare(`INSERT INTO cars (brand,model,variant,engine,power,torque,drivetrain,transmission,price,colors,image,status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).run(
    b.brand, b.model, b.variant || '', b.engine || '', b.power || '', b.torque || '',
    b.drivetrain || '', b.transmission || '', parseInt(b.price) || 0, b.colors || '#111111', image, b.status || 'available');
  res.json({ ok: true });
});

app.put('/api/admin/cars/:id', requireAdmin, upload.single('image'), (req, res) => {
  const car = db.prepare('SELECT * FROM cars WHERE id = ?').get(req.params.id);
  if (!car) return res.status(404).json({ error: 'Mobil tidak ditemukan.' });
  const b = req.body;
  const vals = carFields.map(f => (b[f] !== undefined ? (f === 'price' ? parseInt(b[f]) || 0 : b[f]) : car[f]));
  let image = car.image;
  if (req.file) {
    if (car.image && car.image.startsWith('/uploads/')) fs.unlink(path.join(__dirname, 'public', car.image), () => {});
    image = '/uploads/' + req.file.filename;
  }
  db.prepare(`UPDATE cars SET ${carFields.map(f => f + '=?').join(',')}, image=? WHERE id=?`).run(...vals, image, car.id);
  res.json({ ok: true });
});

app.delete('/api/admin/cars/:id', requireAdmin, (req, res) => {
  const car = db.prepare('SELECT * FROM cars WHERE id = ?').get(req.params.id);
  if (car && car.image && car.image.startsWith('/uploads/')) fs.unlink(path.join(__dirname, 'public', car.image), () => {});
  db.prepare('DELETE FROM cars WHERE id = ?').run(req.params.id);
  res.json({ ok: true });
});

app.get('/api/admin/bookings', requireAdmin, (req, res) =>
  res.json(db.prepare(`SELECT b.*, c.brand||' '||c.model||' '||c.variant AS car_name
    FROM bookings b LEFT JOIN cars c ON c.id=b.car_id ORDER BY b.id DESC`).all()));

app.patch('/api/admin/bookings/:id', requireAdmin, (req, res) => {
  if (!['pending', 'confirmed', 'done', 'cancelled'].includes(req.body.status))
    return res.status(400).json({ error: 'Status tidak valid.' });
  db.prepare('UPDATE bookings SET status=? WHERE id=?').run(req.body.status, req.params.id);
  res.json({ ok: true });
});
app.delete('/api/admin/bookings/:id', requireAdmin, (req, res) => {
  db.prepare('DELETE FROM bookings WHERE id=?').run(req.params.id);
  res.json({ ok: true });
});

app.get('/api/admin/users', requireAdmin, (req, res) =>
  res.json(db.prepare('SELECT id,name,email,role,created_at FROM users ORDER BY id DESC').all()));

app.patch('/api/admin/users/:id', requireAdmin, (req, res) => {
  if (!['user', 'admin'].includes(req.body.role)) return res.status(400).json({ error: 'Role tidak valid.' });
  if (+req.params.id === req.session.user.id) return res.status(400).json({ error: 'Tidak bisa mengubah role akun sendiri.' });
  db.prepare('UPDATE users SET role=? WHERE id=?').run(req.body.role, req.params.id);
  res.json({ ok: true });
});
app.delete('/api/admin/users/:id', requireAdmin, (req, res) => {
  if (+req.params.id === req.session.user.id) return res.status(400).json({ error: 'Tidak bisa menghapus akun sendiri.' });
  db.prepare('DELETE FROM users WHERE id=?').run(req.params.id);
  res.json({ ok: true });
});

// Halaman admin hanya untuk admin
app.get('/admin', (req, res) => {
  if (!req.session.user || req.session.user.role !== 'admin') return res.redirect('/login.html?next=/admin');
  res.sendFile(path.join(__dirname, 'views', 'admin.html'));
});

app.use((err, req, res, next) => {
  console.error(err);
  res.status(500).json({ error: 'Terjadi kesalahan pada server.' });
});

app.listen(PORT, () => console.log(`S JDM berjalan di http://localhost:${PORT}`));
