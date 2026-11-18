BEGIN;
SET LOCAL search_path TO poli, public;
SET LOCAL TIME ZONE 'Asia/Jakarta';
-- Demo only. Set passwords for demo accounts with scripts/demo-password.php.
INSERT INTO pengguna (nama,username,password_hash,peran) VALUES
('Admin Demo','admin.demo','DISABLED','admin'),
('Petugas Penyakit Dalam','petugas.dalam','DISABLED','petugas'),
('Petugas Anak','petugas.anak','DISABLED','petugas'),
('Petugas Mata','petugas.mata','DISABLED','petugas'),
('Petugas Jantung','petugas.jantung','DISABLED','petugas'),
('Petugas Kulit','petugas.kulit','DISABLED','petugas');
INSERT INTO poliklinik(nama,target_harian) VALUES
('Penyakit Dalam',20),('Anak',16),('Mata',14),('Jantung',12),('Kulit dan Kelamin',15);
INSERT INTO dokter(nama,spesialisasi) VALUES
('dr. Aruna','Penyakit Dalam'),('dr. Bagas','Anak'),('dr. Citra','Mata'),
('dr. Damar','Jantung'),('dr. Esti','Kulit dan Kelamin'),('dr. Farah','Penyakit Dalam');
INSERT INTO jadwal_praktik(dokter_id,poli_id,hari,shift,mulai,selesai,target)
SELECT d.id,p.id,
       n,CASE WHEN d.id IN (4,5) THEN 'Sore' ELSE 'Pagi' END,
       CASE WHEN d.id IN (4,5) THEN TIME '15:00' ELSE TIME '08:00' END,
       CASE WHEN d.id IN (4,5) THEN TIME '20:00' ELSE TIME '13:00' END,
       CASE WHEN d.id=6 THEN 8 ELSE p.target_harian END
FROM dokter d
JOIN poliklinik p ON p.id=CASE WHEN d.id=6 THEN 1 ELSE d.id END
CROSS JOIN generate_series(1,7) n;
INSERT INTO penugasan(petugas_id,poli_id,tanggal,shift)
SELECT p.id+1,p.id,CURRENT_DATE+x,CASE WHEN p.id IN (4,5) THEN 'Sore' ELSE 'Pagi' END
FROM poliklinik p CROSS JOIN generate_series(-2,0) x ORDER BY x,p.id;
INSERT INTO laporan(penugasan_id,catatan)
SELECT id,'Data simulasi praktik poliklinik' FROM penugasan
WHERE tanggal < CURRENT_DATE OR poli_id IN (2,3,4);
INSERT INTO detail_kinerja
    (penugasan_id,dokter_id,mulai_rencana,selesai_rencana,datang,pulang,target,pasien,status)
SELECT p.id,j.dokter_id,j.mulai,j.selesai,
       CASE WHEN p.poli_id<>5 THEN j.mulai + make_interval(mins=>((p.poli_id+extract(day from p.tanggal)::integer)%4)*5) END,
       CASE WHEN p.poli_id<>5 THEN j.selesai END,
       j.target,CASE WHEN p.poli_id=5 THEN 0 ELSE j.target-3 END,
       CASE WHEN p.poli_id=5 THEN 'Izin' ELSE 'Hadir' END
FROM laporan l JOIN penugasan p ON p.id=l.penugasan_id
JOIN jadwal_praktik j ON j.poli_id=p.poli_id AND j.shift=p.shift AND j.hari=extract(isodow FROM p.tanggal);
COMMIT;
