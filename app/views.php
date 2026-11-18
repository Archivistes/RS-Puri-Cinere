<?php
declare(strict_types=1);
function csrf(): void { ?>
<input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
<?php }
function options(array $rows, mixed $selected): void {
    foreach ($rows as $row): ?>
    <option value="<?= escape($row['id']) ?>" <?= (string)$row['id'] === (string)$selected ? 'selected' : '' ?>><?= escape($row['nama']) ?></option>
    <?php endforeach;
}
function reportList(string $date, int $poli, string $shift): array
{
    return query("WITH selected_day AS (
            SELECT CAST(? AS date) AS tanggal
        ), shifts AS (
            SELECT DISTINCT j.poli_id,j.shift FROM jadwal_praktik j CROSS JOIN selected_day day
            WHERE j.hari=extract(isodow FROM day.tanggal)
            UNION
            SELECT p.poli_id,p.shift FROM penugasan p JOIN selected_day day USING (tanggal)
        )
        SELECT p.id,day.tanggal,s.poli_id,s.shift,po.nama AS poli,l.versi,k.pasien,k.terlambat
        FROM shifts s CROSS JOIN selected_day day JOIN poliklinik po ON po.id=s.poli_id
        LEFT JOIN penugasan p ON p.poli_id=s.poli_id AND p.shift=s.shift AND p.tanggal=day.tanggal
        LEFT JOIN laporan l ON l.penugasan_id=p.id
        LEFT JOIN LATERAL (SELECT sum(pasien) pasien,round(avg(terlambat),1) terlambat
                           FROM detail_kinerja WHERE penugasan_id=p.id) k ON true
        WHERE (?=0 OR s.poli_id=?) AND (?='' OR s.shift=?)
        ORDER BY s.shift,po.nama",[$date,$poli,$poli,$shift,$shift])->fetchAll();
}
$admin = $user && $user['peran'] === 'admin';
$reports = [];
$clinicOptions = [];
$record = null;
$rows = [];
if ($user) {
    if ($route === 'report') {
        if ($id) {
            $record = query('SELECT p.*,po.nama AS poli,l.versi,l.catatan
                FROM penugasan p JOIN poliklinik po ON po.id=p.poli_id
                LEFT JOIN laporan l ON l.penugasan_id=p.id WHERE p.id=?',[$id])->fetch();
        } elseif (!$admin && $poliFilter && $shiftChoice) {
            $name = query('SELECT nama FROM poliklinik WHERE id=?',[$poliFilter])->fetchColumn();
            if ($name !== false) {
                $record = ['poli_id'=>$poliFilter,'poli'=>$name,'shift'=>$shiftChoice,
                    'tanggal'=>$today,'versi'=>null,'catatan'=>''];
            }
        }
        if (!$record || (!$record['versi'] && ($admin || $record['tanggal'] !== $today))) {
            $forbidden();
        }
        $rows = $record['versi'] ? savedDoctors($id)
            : scheduledDoctors((int)$record['poli_id'],$today,$record['shift']);
    } elseif ($route === 'home') {
        $clinicOptions = query('SELECT id,nama FROM poliklinik ORDER BY nama')->fetchAll();
        $reports = reportList($date,$poliFilter,$shiftChoice);
    } else {
        http_response_code(404);
        exit('Halaman tidak ditemukan.');
    }
}
$heading = !$user ? 'Masuk ke dashboard' : match($route) {
    'report'=>$record['versi'] ? ($admin || $record['tanggal'] !== $today ? 'Detail laporan' : 'Ubah laporan') : 'Isi laporan praktik',
    default=>$admin ? 'Ringkasan rawat jalan' : 'Laporan poliklinik'
};
$initials = '';
if ($user) {
    foreach (array_slice(preg_split('/\s+/u', trim($user['nama'])),0,2) as $word) {
        if (preg_match('/^./u',$word,$letter)) $initials .= $letter[0];
    }
}
$dateLabel = static function (string $value): string {
    $day = new DateTimeImmutable($value);
    $months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    return $day->format('j').' '.$months[(int)$day->format('n') - 1].' '.$day->format('Y');
};
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= escape($heading) ?> | RS Puri Cinere</title>
<link rel="stylesheet" href="/vendor/tabler/tabler.min.css">
<link rel="stylesheet" href="/style.css">
</head>
<body>
<div class="page <?= $user ? 'workspace' : 'sign-in-page' ?>">
<?php if ($user): ?>
<aside class="navbar navbar-vertical navbar-expand-lg clinic-nav" data-bs-theme="dark">
<div class="container-fluid">
<a class="navbar-brand hospital-brand" href="/"><span class="brand-symbol" aria-hidden="true"></span>
<span>RS Puri Cinere<small>Kinerja Poliklinik</small></span></a>
<nav class="navbar-nav" aria-label="Navigasi utama">
<a class="nav-link active" <?= $route==='home' ? 'aria-current="page"' : '' ?> href="/">
<span class="nav-symbol grid-symbol" aria-hidden="true"></span><span><?= $admin ? 'Ringkasan' : 'Laporan poli' ?></span></a>
</nav>
</div>
</aside>
<div class="page-wrapper">
<header class="navbar workspace-header">
<div class="container-xl">
<span class="workspace-label"><?= $admin ? 'Administrasi Rawat Jalan' : 'Pelaporan Rawat Jalan' ?></span>
<form method="post" action="/" class="identity"><?php csrf() ?><input type="hidden" name="action" value="logout">
<span class="avatar avatar-sm bg-blue-lt" aria-hidden="true"><?= escape($initials) ?></span>
<span class="identity-name"><?= escape($user['nama']) ?><small><?= $admin ? 'Admin' : 'Petugas Poli' ?></small></span>
<button class="btn btn-outline-secondary btn-sm">Keluar</button></form>
</div>
</header>
<?php else: ?>
<a class="hospital-brand sign-in-brand" href="/"><span class="brand-symbol" aria-hidden="true"></span>
<span>RS Puri Cinere<small>Kinerja Poliklinik</small></span></a>
<?php endif ?>
<main class="container-xl <?= !$user ? 'login' : 'workspace-main' ?>">
<?php if ($route === 'report' && $user): ?>
<a class="back" href="/?tanggal=<?= escape($record['tanggal']) ?>"><span aria-hidden="true">←</span> Kembali ke laporan</a>
<?php endif ?>
<div class="page-heading">
<div><h1 class="page-title"><?= escape($heading) ?></h1>
<?php if ($user && $route !== 'report'): ?><p class="text-secondary mt-1 mb-0"><?= escape($dateLabel($date)) ?></p><?php endif ?></div>
</div>
<?php if ($error): ?><p class="alert alert-danger" role="alert"><?= escape($error) ?></p><?php endif ?>
<?php if ($notice): ?><p class="alert alert-success" role="status"><?= escape($notice) ?></p><?php endif ?>

<?php if (!$user): ?>
<form method="post" action="/" class="card card-body stack">
<?php csrf() ?><input type="hidden" name="action" value="login">
<label>Nama pengguna<input class="form-control" name="username" autocomplete="username" maxlength="50" required autofocus></label>
<label>Kata sandi<input class="form-control" type="password" name="password" autocomplete="current-password" required></label>
<button class="btn btn-primary w-100">Masuk</button></form>

<?php elseif ($route === 'report'):
$editable = !$admin && $record['tanggal'] === $today;
$version = (int)$record['versi']; ?>
<div class="card report-context mb-3"><div class="card-body">
<div><span class="text-secondary">Poliklinik</span><strong><?= escape($record['poli']) ?></strong></div>
<div><span class="text-secondary">Tanggal dan shift</span><strong><?= escape($dateLabel($record['tanggal'])) ?> · <?= escape($record['shift']) ?></strong></div>
</div></div>
<?php if ($editable): ?>
<form method="post" class="stack">
<?php csrf() ?>
<input type="hidden" name="action" value="report-save">
<input type="hidden" name="versi" value="<?= $version ?>">
<?php foreach ($rows as $row):
$key = 'detail['.(int)$row['dokter_id'].']';
$attempt = $_POST['detail'][$row['dokter_id']] ?? [];
$entry = is_array($attempt) ? array_intersect_key($attempt,array_flip(['status','datang','pulang','pasien','kendala'])) + $row : $row;
?>
<fieldset class="card card-body doctor-form">
<legend><?= escape($row['nama']) ?></legend>
<p class="muted">Rencana <?= escape(substr($row['mulai_rencana'],0,5)) ?> sampai <?= escape(substr($row['selesai_rencana'],0,5)) ?> · Target <?= (int)$row['target'] ?> pasien</p>
<div class="fields">
<label>Kehadiran<select class="form-select" name="<?= $key ?>[status]"><?php foreach (['Hadir','Izin','Sakit','Alpa','Cuti'] as $status): ?>
<option <?= $entry['status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach ?></select></label>
<label>Jam datang<input class="form-control" type="time" name="<?= $key ?>[datang]" value="<?= escape(substr((string)$entry['datang'],0,5)) ?>"></label>
<label>Jam pulang<input class="form-control" type="time" name="<?= $key ?>[pulang]" value="<?= escape(substr((string)$entry['pulang'],0,5)) ?>"></label>
<label>Jumlah pasien<input class="form-control" type="number" name="<?= $key ?>[pasien]" value="<?= escape($entry['pasien']) ?>" min="0" max="100000" required></label>
</div>
<label>Kendala<textarea class="form-control" name="<?= $key ?>[kendala]" maxlength="500" rows="2"><?= escape($entry['kendala']) ?></textarea></label>
<?php if ($version): ?><p class="muted">Terlambat <?= escape($row['terlambat'] ?? 'n/a') ?> menit · Capaian <?= escape($row['capaian']) ?>%</p><?php endif ?>
</fieldset>
<?php endforeach ?>
<?php if ($rows): ?>
<div class="card card-body"><label>Catatan shift<textarea class="form-control" name="catatan" maxlength="2000" rows="2"><?= escape($_POST['catatan'] ?? $record['catatan'] ?? '') ?></textarea></label></div>
<button class="btn btn-primary">Simpan laporan</button>
<?php else: ?><p class="empty">Tidak ada dokter terjadwal untuk shift ini.</p><?php endif ?>
</form>
<?php if ($version): ?>
<details class="danger-zone"><summary>Hapus laporan</summary><form method="post" class="stack">
<?php csrf() ?><input type="hidden" name="action" value="report-delete"><input type="hidden" name="versi" value="<?= $version ?>">
<p>Laporan dan seluruh detail dokter pada poli dan shift ini akan dihapus.</p><button class="btn btn-danger">Ya, hapus laporan</button></form></details>
<?php endif ?>
<?php else: ?>
<div class="card"><div class="table-responsive"><table class="table table-vcenter card-table"><thead><tr><th>Dokter</th><th>Status</th><th>Pasien</th><th>Terlambat</th><th>Pulang cepat</th><th>Capaian</th><th>Kendala</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
<td><?= escape($row['nama']) ?></td><td><?= escape($row['status']) ?></td>
<td><?= (int)$row['pasien'] ?> / <?= (int)$row['target'] ?></td>
<td><?= $row['terlambat'] === null ? 'n/a' : escape($row['terlambat']).' mnt' ?></td>
<td><?= $row['pulang_cepat'] === null ? 'n/a' : escape($row['pulang_cepat']).' mnt' ?></td>
<td><?= escape($row['capaian']) ?>%</td><td><?= escape($row['kendala'] ?: '-') ?></td></tr><?php endforeach ?>
</tbody></table></div></div>
<p class="card card-body mt-3"><strong>Catatan shift</strong><?= escape($record['catatan'] ?: 'Tidak ada catatan.') ?></p>
<?php endif ?>

<?php else: ?>
<form method="get" class="card filter-card"><div class="card-body filters">
<input type="hidden" name="view" value="<?= escape($route) ?>">
<label>Tanggal laporan<input class="form-control" type="date" name="tanggal" value="<?= escape($date) ?>" required></label>
<label>Poliklinik<select class="form-select" name="poli"><option value="0">Semua poli</option><?php options($clinicOptions,$poliFilter) ?></select></label>
<label>Shift<select class="form-select" name="shift">
<option value="">Semua shift</option>
<?php foreach (['Pagi','Sore'] as $shift): ?><option <?= $shift === $shiftChoice ? 'selected' : '' ?>><?= $shift ?></option><?php endforeach ?>
</select></label>
<button class="btn btn-outline-primary">Terapkan</button></div></form>

<?php if ($admin && $route === 'home'):
$total = count($reports);
$reported = count(array_filter($reports,fn($r)=>$r['versi'] !== null));
$patients = (int)array_sum(array_column($reports,'pasien'));
$completion = $total ? (int)round(100*$reported/$total) : 0;
$metrics = [
    ['Shift terjadwal',$total,'primary','Kombinasi poli dan shift sesuai filter'],
    ['Laporan masuk',$reported,'green','Sudah dikirim oleh petugas'],
    ['Belum melapor',$total-$reported,'orange','Menunggu pengisian'],
    ['Pasien terlayani',$patients,'azure','Dari laporan yang masuk'],
];
$byShift = ['Pagi'=>0, 'Sore'=>0];
foreach ($reports as $assignment) {
    $byShift[$assignment['shift']] += (int)$assignment['pasien'];
}
?>
<div class="row row-deck row-cards metric-grid">
<?php foreach ($metrics as [$label,$count,$tone,$description]): ?>
<div class="col-6 col-xl-3"><div class="card metric-card">
<div class="card-status-top bg-<?= $tone ?>"></div>
<div class="card-body"><div class="metric-label"><?= escape($label) ?></div>
<div class="metric-value"><?= number_format($count,0,',','.') ?></div>
<span class="text-secondary metric-note"><?= escape($description) ?></span></div>
</div></div>
<?php endforeach ?>
</div>
<div class="row row-deck row-cards overview-grid">
<div class="col-md-5"><section class="card"><div class="card-body">
<h2 class="card-title mb-2">Progres laporan</h2>
<div class="d-flex justify-content-between align-items-baseline mb-2">
<span class="text-secondary"><?= $reported ?> dari <?= $total ?> shift terjadwal</span><strong class="progress-value"><?= $completion ?>%</strong></div>
<progress class="report-progress" aria-label="Persentase shift yang sudah dilaporkan" max="100" value="<?= $completion ?>"></progress>
</div></section></div>
<div class="col-md-7"><section class="card"><div class="card-body">
<h2 class="card-title mb-2">Pasien per shift</h2>
<?php foreach ($byShift as $shiftLabel=>$shiftCount): ?>
<div class="shift-row"><span><?= $shiftLabel ?></span>
<progress class="report-progress <?= $shiftLabel==='Sore' ? 'evening' : '' ?>" aria-label="Pasien shift <?= $shiftLabel ?>" max="<?= max(1,max($byShift)) ?>" value="<?= $shiftCount ?>"></progress>
<strong><?= number_format($shiftCount,0,',','.') ?></strong></div>
<?php endforeach ?>
</div></section></div>
</div>
<?php endif ?>

<section class="card report-list">
<div class="card-header"><h2 class="card-title">Laporan per poli dan shift</h2>
<span class="badge bg-secondary-lt ms-auto"><?= count($reports) ?> shift</span></div>
<?php if (!$reports): ?><div class="card-body text-secondary">Tidak ada jadwal atau laporan untuk pilihan ini.</div><?php else: ?>
<div class="table-responsive"><table class="table table-vcenter card-table table-hover"><thead><tr><th>Poliklinik</th><th>Shift</th><th>Status</th><th>Pasien</th><?php if ($admin && $route==='home'): ?><th>Rerata terlambat</th><?php endif ?><th>Tindakan</th></tr></thead><tbody>
<?php foreach ($reports as $a): ?><tr>
<td class="clinic-cell"><strong><?= escape($a['poli']) ?></strong></td><td data-label="Shift"><?= escape($a['shift']) ?></td>
<td><span class="badge status-badge <?= $a['versi'] ? 'bg-green-lt' : 'bg-orange-lt' ?>"><span class="status-dot" aria-hidden="true"></span><?= $a['versi'] ? 'Sudah dilaporkan' : 'Belum dilaporkan' ?></span></td>
<td data-label="Pasien"><?= escape($a['pasien'] ?? '-') ?></td>
<?php if ($admin && $route==='home'): ?><td data-label="Rerata terlambat"><?= $a['terlambat'] === null ? '-' : escape($a['terlambat']).' mnt' ?></td><?php endif ?>
<td class="action-cell">
<?php if ($a['versi'] || (!$admin && $date === $today)): ?>
<?php $reportHref = $a['id'] ? '?view=report&id='.(int)$a['id']
    : '?view=report&poli='.(int)$a['poli_id'].'&shift='.rawurlencode($a['shift']); ?>
<a href="/<?= escape($reportHref) ?>"><?= $a['versi'] ? ($admin || $date !== $today ? 'Lihat laporan' : 'Ubah laporan') : 'Isi laporan' ?></a>
<?php else: ?><span class="empty-action">-</span><?php endif ?>
</td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?>
</section>
<?php endif ?>
</main>
<?php if ($user): ?></div><?php endif ?>
</div>
</body>
</html>
