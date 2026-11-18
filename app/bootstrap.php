<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

function openDatabase(): PDO
{
    $dsn = getenv('POLI_DSN');
    $user = getenv('POLI_DB_USER');
    $password = getenv('POLI_DB_PASSWORD');
    if (defined('POLI_CLOUD') && (!$dsn || !$user || !$password)) {
        throw new RuntimeException('Cloud database settings are missing.');
    }
    return new PDO($dsn ?: 'pgsql:host=127.0.0.1;port=5432;dbname=dashboard_poli',
        $user ?: 'dashboard_app', $password ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
}

function db(): PDO
{
    static $connection;
    if (!$connection) {
        $connection = openDatabase();
        $connection->exec("SET search_path TO poli, pg_temp");
        $connection->exec("SET TIME ZONE 'Asia/Jakarta'");
    }
    return $connection;
}

function query(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function today(): string { return date('Y-m-d'); }

function validDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function redirect(string $url = '/'): never
{
    header('Location: '.$url, true, 303);
    exit;
}

function positiveId(mixed $value): int
{
    return filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>2147483647]]) ?: 0;
}

function textField(mixed $value, int $max): string
{
    if (!is_string($value) || preg_match('//u',$value) !== 1 ||
        preg_match_all('/./us', $value) > $max || str_contains($value, "\0")) {
        throw new DomainException("Isi teks tidak valid atau melebihi $max karakter.");
    }
    return trim($value);
}

function lockedAssignment(int $id): array
{
    $row = query('SELECT * FROM penugasan WHERE id=? FOR UPDATE', [$id])->fetch();
    if (!$row) {
        throw new DomainException('Penugasan tidak ditemukan.');
    }
    return $row;
}

function scheduledDoctors(int $poli, string $date, string $shift): array
{
    return query("SELECT j.dokter_id,d.nama,d.spesialisasi,j.mulai AS mulai_rencana,
        j.selesai AS selesai_rencana,j.target,NULL AS datang,NULL AS pulang,0 AS pasien,
        'Hadir' AS status,'' AS kendala
        FROM jadwal_praktik j JOIN dokter d ON d.id=j.dokter_id
        WHERE j.poli_id=? AND j.hari=extract(isodow FROM CAST(? AS date))
        AND j.shift=? ORDER BY d.nama,d.id",[$poli,$date,$shift])->fetchAll();
}

function savedDoctors(int $id): array
{
    return query('SELECT d.*,k.nama,k.spesialisasi FROM detail_kinerja d
        JOIN dokter k ON k.id=d.dokter_id WHERE d.penugasan_id=? ORDER BY k.nama,k.id',[$id])->fetchAll();
}

function inputDetail(array $data): array
{
    $status = $data['status'] ?? '';
    if (!in_array($status,['Hadir','Izin','Sakit','Alpa','Cuti'],true)) {
        throw new DomainException('Pilih status kehadiran yang valid.');
    }
    $datang = $data['datang'] ?? '';
    $pulang = $data['pulang'] ?? '';
    $pasien = filter_var($data['pasien'] ?? null,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>0,'max_range'=>100000]]);
    if ($pasien === false) {
        throw new DomainException('Jumlah pasien harus bilangan bulat antara 0 dan 100.000.');
    }
    if ($status === 'Hadir') {
        $pattern = '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D';
        if (!is_string($datang) || !is_string($pulang) || !preg_match($pattern,$datang)
            || !preg_match($pattern,$pulang) || $pulang < $datang) {
            throw new DomainException('Isi jam datang dan pulang untuk dokter yang hadir. Jam pulang harus setelah atau sama dengan jam datang.');
        }
    } else {
        if ($pasien !== 0 || $datang !== '' || $pulang !== '') {
            throw new DomainException('Kosongkan jam aktual dan isi 0 pasien jika dokter tidak hadir.');
        }
        $datang = $pulang = null;
    }
    return [$datang,$pulang,$pasien,$status,textField($data['kendala'] ?? '',500)];
}
