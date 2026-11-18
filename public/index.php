<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

ini_set('session.use_strict_mode','1');
session_set_cookie_params([
    'httponly'=>true, 'samesite'=>'Lax', 'path'=>'/',
    'secure'=>getenv('POLI_HTTPS') === '1' || getenv('VERCEL') === '1'
]);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
try {
    if (defined('POLI_CLOUD')) {
        require __DIR__.'/../app/PostgresSession.php';
        ini_set('session.gc_probability','1');
        ini_set('session.gc_divisor','100');
        session_set_save_handler(new PostgresSession(),true);
    }
    if (!session_start()) {
        throw new RuntimeException('Unable to start session.');
    }
} catch (Throwable $exception) {
    $reason = match (true) {
        $exception->getMessage() === 'Cloud database settings are missing.' => 'missing_db_settings',
        str_contains($exception->getMessage(), 'could not find driver') => 'missing_pdo_pgsql',
        stripos($exception->getMessage(), 'endpoint ID') !== false => 'neon_endpoint_required',
        stripos($exception->getMessage(), 'password authentication failed') !== false => 'database_auth_failed',
        stripos($exception->getMessage(), 'invalid connection option') !== false => 'invalid_connection_option',
        stripos($exception->getMessage(), 'SSL') !== false => 'database_tls_error',
        default => 'session_error'
    };
    $sqlState = $exception instanceof PDOException && preg_match('/^[A-Z0-9]{5}$/D',(string)$exception->getCode())
        ? (string)$exception->getCode() : 'n/a';
    error_log("Session initialization failed ($reason, state=$sqlState).");
    http_response_code(503);
    exit('Layanan sesi belum tersedia.');
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$user = null;
$error = '';
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);
$route = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'home';
$today = today();
$date = $_GET['tanggal'] ?? $today;
if (!is_string($date) || !validDate($date)) $date = $today;
$poliFilter = positiveId($_GET['poli'] ?? null);
$id = positiveId($_GET['id'] ?? null);
$shiftChoice = $_GET['shift'] ?? '';
$shiftChoice = in_array($shiftChoice,['Pagi','Sore'],true) ? $shiftChoice : '';
$forbidden = static function (): never {
    http_response_code(403);
    exit('Akses ditolak.');
};
try {
    if (isset($_SESSION['user'])) {
        $user = query('SELECT id,nama,peran FROM pengguna WHERE id=? AND aktif',[$_SESSION['user']])->fetch() ?: null;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'],$token)) $forbidden();
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            $username = strtolower(trim((string) ($_POST['username'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $account = query('SELECT id,password_hash FROM pengguna WHERE username=? AND aktif',[$username])->fetch();
            if (!$account || !password_verify($password,$account['password_hash'])) {
                throw new DomainException('Nama pengguna atau kata sandi tidak sesuai.');
            }
            session_regenerate_id(true);
            $_SESSION = ['user'=>$account['id'],'csrf'=>bin2hex(random_bytes(32))];
            redirect();
        }
        if (!$user) $forbidden();
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            redirect();
        }
        if (in_array($action,['assignment-save','assignment-delete'],true)) $forbidden();
        if (in_array($action,['report-save','report-delete'],true)) {
            if ($user['peran'] !== 'petugas' || $route !== 'report') $forbidden();
            db()->beginTransaction();
            $reportId = $id;
            if (!$reportId) {
                if ($action !== 'report-save' || !$poliFilter || !$shiftChoice
                    || !scheduledDoctors($poliFilter,$today,$shiftChoice)) {
                    throw new DomainException('Pilih poli dan shift yang memiliki jadwal hari ini.');
                }
                query('INSERT INTO penugasan(petugas_id,poli_id,tanggal,shift) VALUES (?,?,?,?)
                    ON CONFLICT (poli_id,tanggal,shift) DO NOTHING',
                    [$user['id'],$poliFilter,$today,$shiftChoice]);
                $reportId = (int)query('SELECT id FROM penugasan WHERE poli_id=? AND tanggal=? AND shift=?',
                    [$poliFilter,$today,$shiftChoice])->fetchColumn();
            }
            $assignment = lockedAssignment($reportId);
            if ($assignment['tanggal'] !== $today) {
                db()->rollBack();
                $forbidden();
            }
            $version = query('SELECT versi FROM laporan WHERE penugasan_id=?',[$reportId])->fetchColumn();
            if ((int) $version !== (int) ($_POST['versi'] ?? -1)) {
                db()->rollBack();
                $_SESSION['notice'] = 'Laporan sudah berubah. Periksa data terbaru sebelum menyimpan atau menghapus.';
                redirect('/?view=report&id='.$reportId);
            }
            if ($action === 'report-delete') {
                if (!$version) throw new DomainException('Laporan sudah dihapus.');
                query('DELETE FROM laporan WHERE penugasan_id=?',[$reportId]);
            } else {
                $doctorRows = $version ? savedDoctors($reportId)
                    : scheduledDoctors((int)$assignment['poli_id'],$today,$assignment['shift']);
                if (!$doctorRows) throw new DomainException('Jadwal dokter belum tersedia.');
                $notes = textField($_POST['catatan'] ?? '',2000);
                if ($version) {
                    query('UPDATE laporan SET catatan=?,diperbarui=now(),versi=versi+1 WHERE penugasan_id=?',[$notes,$reportId]);
                } else {
                    query('INSERT INTO laporan(penugasan_id,catatan) VALUES (?,?)',[$reportId,$notes]);
                }
                foreach ($doctorRows as $row) {
                    $doctor = $row['dokter_id'];
                    $inputs = $_POST['detail'][$doctor] ?? null;
                    if (!is_array($inputs)) throw new DomainException('Lengkapi data seluruh dokter pada formulir.');
                    $values = inputDetail($inputs);
                    if ($version) {
                        query('UPDATE detail_kinerja SET datang=?,pulang=?,pasien=?,status=?,kendala=?
                            WHERE penugasan_id=? AND dokter_id=?',[...$values,$reportId,$doctor]);
                    } else {
                        query('INSERT INTO detail_kinerja
                            (datang,pulang,pasien,status,kendala,penugasan_id,dokter_id,mulai_rencana,selesai_rencana,target)
                            VALUES (?,?,?,?,?,?,?,?,?,?)',[...$values,$reportId,$doctor,$row['mulai_rencana'],
                                $row['selesai_rencana'],$row['target']]);
                    }
                }
            }
            db()->commit();
            $_SESSION['notice'] = $action === 'report-delete' ? 'Laporan dihapus.' : 'Laporan tersimpan.';
            redirect($action === 'report-delete' ? '/' : '/?view=report&id='.$reportId);
        }
        http_response_code(400);
        exit('Tindakan tidak tersedia.');
    }
} catch (DomainException $exception) {
    if (db()->inTransaction()) db()->rollBack();
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    if (in_array($exception->getCode(),['23505','23503','23514'],true)) {
        if (db()->inTransaction()) db()->rollBack();
        $error = match($exception->getCode()) {
            '23505'=>'Penugasan atau laporan untuk poli, tanggal, dan shift tersebut sudah ada.',
            '23503'=>'Data terkait tidak tersedia atau masih dipakai oleh laporan.',
            default=>'Data melanggar aturan penugasan atau laporan.'
        };
    } else {
        error_log($exception->getMessage());
        http_response_code(503);
        exit('Layanan database belum tersedia.');
    }
}
require __DIR__.'/../app/views.php';
