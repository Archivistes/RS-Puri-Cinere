<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);
$password = getenv('POLI_DEMO_PASSWORD');
if (!$password || strlen($password) < 12 || strlen($password) > 72) {
    fwrite(STDERR,"Set POLI_DEMO_PASSWORD to a demo password of 12 to 72 bytes.\n");
    exit(1);
}
foreach (['admin.demo','petugas.poli'] as $username) {
    query('UPDATE pengguna SET password_hash=? WHERE username=?',[password_hash($password,PASSWORD_DEFAULT),$username]);
}
echo "Akun demo siap.\n";
