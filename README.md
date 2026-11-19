# S JDM — Website Showroom Mobil JDM

Fitur: halaman utama, login & daftar, panel admin (kelola mobil, booking, pengguna), database SQLite.

## Cara menjalankan
1. Install Node.js 18+ dari https://nodejs.org
2. Buka terminal di folder ini lalu jalankan:

    npm install
    npm start

3. Buka http://localhost:3000

## Akun admin bawaan
- Email    : admin@sjdm.com
- Password : admin123
(Segera ganti: login > Admin > Pengguna, atau ubah di db.js sebelum pertama kali dijalankan.)

## Struktur
- server.js  : server Express + semua API
- db.js      : skema database SQLite + data awal (sjdm.db dibuat otomatis)
- public/    : halaman publik, login, register, CSS, JS, folder uploads/
- views/     : admin.html (hanya bisa diakses admin lewat /admin)

## Database (SQLite, file sjdm.db)
- users    : id, name, email, password (bcrypt), role (user/admin)
- cars     : id, brand, model, variant, engine, power, torque, drivetrain, transmission, price, colors, image, status
- bookings : id, user_id, car_id, name, phone, visit_date, note, status

## Catatan
- Untuk produksi, set variabel SESSION_SECRET: SESSION_SECRET=rahasia-anda npm start
- Mata uang ditampilkan dengan $ (seperti contoh). Ubah CURRENCY di public/js/common.js menjadi 'Rp ' jika ingin Rupiah.

## File database untuk MySQL/MariaDB (XAMPP / phpMyAdmin)
Import `database/sjdm.sql` lewat phpMyAdmin > Import. Database `sjdm` dan semua tabel (users, cars, bookings) + data awal dibuat otomatis.
