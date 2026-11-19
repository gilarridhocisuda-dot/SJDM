S JDM - Website Showroom Mobil JDM (PHP + MySQL)
================================================

CARA PASANG (XAMPP) - 3 langkah
1. IMPORT DATABASE
   - Nyalakan Apache + MySQL di XAMPP Control Panel
   - Buka http://localhost/phpmyadmin > tab "Import"
   - Pilih file  database/sjdm.sql  > klik "Import"
   (database "sjdm" + semua tabel + data contoh dibuat otomatis)

2. TARUH FOLDER
   - Salin seluruh folder ini ke  C:\xampp\htdocs\sjdm
     (nama folder boleh diganti, bebas)

3. BUKA WEBSITE
   - http://localhost/sjdm/index.html

LOGIN (DIPISAH)
   Pengguna : http://localhost/sjdm/login.html        -> pakai EMAIL + password (akun hasil Daftar)
   Admin    : http://localhost/sjdm/admin-login.html  -> pakai USERNAME + password
   Admin bawaan -> Username: admin   Password: admin123
   Ganti username/password lewat panel admin: menu "Akun Saya".
   Akun admin tidak bisa login di halaman pengguna, dan sebaliknya.

Jika tampil "Gagal terhubung ke database": pastikan MySQL menyala dan
sjdm.sql sudah diimport. Pengaturan koneksi ada di config.php
(bawaan XAMPP: user root, password kosong).

ISI FOLDER
  index.html / login.html / register.html   halaman publik
  admin-login.html                          login khusus admin
  admin.php + admin_view.html               panel admin (hanya untuk admin)
  api.php, config.php                       backend PHP + koneksi database
  css/, js/, uploads/                       tampilan, skrip, foto mobil yang diunggah
  database/sjdm.sql                         database untuk diimport

Fitur: katalog mobil + filter/urut, booking kunjungan, login & daftar,
panel admin (dashboard, CRUD mobil + upload foto, kelola booking, kelola pengguna).
