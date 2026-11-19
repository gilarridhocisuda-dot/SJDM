const Database = require('better-sqlite3');
const bcrypt = require('bcryptjs');
const path = require('path');

const db = new Database(path.join(__dirname, 'sjdm.db'));
db.pragma('journal_mode = WAL');

db.exec(`
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'user',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS cars (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  brand TEXT NOT NULL,
  model TEXT NOT NULL,
  variant TEXT DEFAULT '',
  engine TEXT DEFAULT '',
  power TEXT DEFAULT '',
  torque TEXT DEFAULT '',
  drivetrain TEXT DEFAULT '',
  transmission TEXT DEFAULT '',
  price INTEGER NOT NULL DEFAULT 0,
  colors TEXT DEFAULT '#111111',
  image TEXT DEFAULT '',
  status TEXT NOT NULL DEFAULT 'available',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS bookings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER,
  car_id INTEGER,
  name TEXT NOT NULL,
  phone TEXT NOT NULL,
  visit_date TEXT NOT NULL,
  note TEXT DEFAULT '',
  status TEXT NOT NULL DEFAULT 'pending',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE SET NULL
);
`);

// Seed admin
if (!db.prepare('SELECT 1 FROM users WHERE role = ?').get('admin')) {
  db.prepare('INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)')
    .run('Administrator', 'admin@sjdm.com', bcrypt.hashSync('admin123', 10), 'admin');
}

// Seed mobil contoh
if (db.prepare('SELECT COUNT(*) c FROM cars').get().c === 0) {
  const ins = db.prepare(`INSERT INTO cars
    (brand,model,variant,engine,power,torque,drivetrain,transmission,price,colors)
    VALUES (?,?,?,?,?,?,?,?,?,?)`);
  [
    ['Nissan', 'Skyline', 'GT-R R34', '2.6L RB26DETT', '280 HP', '392 Nm', 'AWD', '6-Speed Manual', 154999, '#1a1a1a,#c9a96e,#1b2f6b,#6a5acd'],
    ['Toyota', 'Supra', 'MK4', '3.0L 2JZ-GTE', '320 HP', '427 Nm', 'RWD', '6-Speed Manual', 129999, '#f2f2f2,#c8102e,#555555,#111111'],
    ['Mazda', 'RX-7', 'FD3S', '1.3L 13B-REW', '276 HP', '314 Nm', 'RWD', '5-Speed Manual', 89999, '#c8102e,#2f5fb3,#f2f2f2,#111111'],
    ['Honda', 'NSX', 'NA1', '3.0L C30A V6', '270 HP', '280 Nm', 'RWD', '5-Speed Manual', 109999, '#c8102e,#111111,#f2f2f2,#6b4f3a'],
    ['Mitsubishi', 'Lancer', 'Evolution VI', '2.0L 4G63T', '276 HP', '353 Nm', 'AWD', '5-Speed Manual', 79999, '#777777,#bbbbbb,#c8102e,#1b2f6b'],
    ['Nissan', 'Silvia', 'S15', '2.0L SR20DET', '250 HP', '275 Nm', 'RWD', '6-Speed Manual', 64999, '#1f4fbf,#f2f2f2,#111111,#bbbbbb'],
  ].forEach(r => ins.run(...r));
}

module.exports = db;
