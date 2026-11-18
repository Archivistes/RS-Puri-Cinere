SET search_path TO poli, public;
SET TIME ZONE 'Asia/Jakarta';

-- 1. Rekap harian per shift. Poli tanpa laporan tidak dihitung.
SELECT p.shift, COUNT(DISTINCT p.poli_id) AS poli_melapor,
       COUNT(DISTINCT k.dokter_id) AS dokter, COALESCE(SUM(k.pasien),0) AS pasien,
       ROUND(AVG(k.terlambat),2) AS menit_terlambat
FROM penugasan p JOIN laporan l ON l.penugasan_id=p.id
LEFT JOIN kinerja k ON k.penugasan_id=p.id
WHERE p.tanggal=CURRENT_DATE GROUP BY p.shift ORDER BY p.shift;

-- 2. Bandingkan kinerja shift selama bulan berjalan.
SELECT shift, COUNT(*) AS sesi, SUM(pasien) AS pasien,
       ROUND(AVG(terlambat),2) AS menit_terlambat, ROUND(AVG(capaian),2) AS capaian_persen
FROM kinerja WHERE tanggal>=date_trunc('month',CURRENT_DATE)
AND tanggal<date_trunc('month',CURRENT_DATE)+interval '1 month'
GROUP BY shift ORDER BY shift;

-- 3. Lima dokter dengan akumulasi keterlambatan tertinggi.
SELECT d.nama, COUNT(k.terlambat) AS sesi_hadir, SUM(k.terlambat) AS total_menit
FROM kinerja k JOIN dokter d ON d.id=k.dokter_id
WHERE k.terlambat IS NOT NULL GROUP BY d.id
ORDER BY total_menit DESC,d.id LIMIT 5;

-- 4. Contoh riwayat individual untuk dokter pertama pada dataset demo.
SELECT k.tanggal,k.shift,p.nama AS poli,k.status,k.terlambat,k.pulang_cepat,k.pasien,k.capaian
FROM kinerja k JOIN poliklinik p ON p.id=k.poli_id
WHERE k.dokter_id=(SELECT MIN(id) FROM dokter) ORDER BY k.tanggal DESC,k.shift;

-- 5. Jadwal pada hari ini untuk shift Pagi.
SELECT p.nama AS poli,d.nama AS dokter,j.mulai,j.selesai,j.target
FROM jadwal_praktik j JOIN dokter d ON d.id=j.dokter_id JOIN poliklinik p ON p.id=j.poli_id
WHERE j.hari=extract(isodow FROM CURRENT_DATE) AND j.shift='Pagi'
ORDER BY p.nama,d.nama;

-- Jumlah baris untuk memastikan data contoh memenuhi syarat LKP.
SELECT 'pengguna' AS tabel,count(*) AS baris FROM pengguna
UNION ALL SELECT 'poliklinik',count(*) FROM poliklinik
UNION ALL SELECT 'dokter',count(*) FROM dokter
UNION ALL SELECT 'jadwal_praktik',count(*) FROM jadwal_praktik
UNION ALL SELECT 'penugasan',count(*) FROM penugasan
UNION ALL SELECT 'laporan',count(*) FROM laporan
UNION ALL SELECT 'detail_kinerja',count(*) FROM detail_kinerja;
