<?php
// Halaman admin: hanya bisa dibuka oleh akun dengan role admin
require __DIR__ . '/config.php';
$u = current_user();
if (!$u || $u['role'] !== 'admin') { header('Location: admin-login.html'); exit; }
readfile(__DIR__ . '/admin_view.html');
