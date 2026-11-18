# Dashboard Kinerja Dokter Poliklinik RS Puri Cinere

Prototype Fase 3 dan dasar aplikasi CRUD Fase 4. Petugas memakai satu akun bersama, memilih poli dan shift sesuai jadwal, lalu mengisi, membaca, mengubah, atau menghapus laporan hari ini. Admin dapat membaca semua laporan dan menelusuri riwayat menurut tanggal, poli, dan shift. Semua data bawaan adalah simulasi.

## Mulai demo lokal di Windows

Jalankan Mulai-Demo.ps1 dengan PowerShell. Tentukan lokasi php.exe dan folder bin PostgreSQL melalui parameter bila program belum ada di PATH. Skrip menyiapkan klaster terpisah di .demo pada 127.0.0.1:55437 dan membuka server PHP pada http://127.0.0.1:8789 selama sesi PowerShell berjalan. PHP memerlukan ekstensi pdo_pgsql. Jika memakai distribusi PHP portabel Windows, skrip memakai DLL pdo_pgsql dari folder ext bila ekstensi belum aktif.

Skrip menampilkan kata sandi khusus demo. Akun yang bisa dicoba adalah admin.demo dan petugas.poli. Pilihan hari ini mengikuti jadwal praktik yang tersimpan, termasuk saat demo lama dibuka kembali setelah tanggal berganti.

## Setup manual PostgreSQL dan PHP

1. Pada koneksi PostgreSQL yang mempunyai hak CREATE DATABASE, jalankan sql/00_database.sql dari database postgres, di luar transaksi. Sambungkan ke database dashboard_poli. Jalankan sql/01_skema.sql sebagai pemilik skema, diikuti sql/02_data_demo.sql untuk data contoh.
2. Buat role LOGIN dashboard_app tanpa hak superuser, CREATEDB, atau CREATEROLE. Tetapkan kata sandi dari pengelola rahasia atau perintah psql \password, lalu jalankan sql/04_hak_akses.sql sebagai pemilik skema. Jalankan scripts/demo-password.php melalui PHP CLI dengan POLI_DEMO_PASSWORD dan koneksi milik pemilik skema untuk mengaktifkan akun data simulasi.
3. Atur variabel POLI_DSN menjadi pgsql:host=127.0.0.1;port=5432;dbname=dashboard_poli, POLI_DB_USER menjadi dashboard_app, dan POLI_DB_PASSWORD sesuai akun aplikasi. Jalankan php -S 127.0.0.1:8789 -t public untuk demo. Untuk server institusi, arahkan document root PHP ke public, gunakan server web produksi dengan HTTPS, set POLI_HTTPS=1, dan batasi sambungan PostgreSQL ke host aplikasi.
4. Buka sql/03_mission_objectives.sql di DBeaver atau psql pada database dashboard_poli. Query menggunakan zona waktu Asia/Jakarta. Dataset contoh relatif terhadap hari pembuatan data.

## Skenario presentasi

- Masuk sebagai petugas.poli. Pilih Penyakit Dalam dan shift Pagi, terapkan filter, lalu buka formulir melalui Isi laporan. Isi jam kedatangan dan kepulangan dua dokter, jumlah pasien, dan catatan shift. Simpan dan muat ulang, lalu ubah salah satu jumlah pasien. Ulangi dengan poli yang terjadwal pada shift Sore untuk memperlihatkan penggunaan akun yang sama.
- Muat ulang Ringkasan Admin dan buka rincian Penyakit Dalam. Cocokkan jumlah pasien atau keterlambatan dengan query SQL, kemudian kembali sebagai petugas dan hapus laporan uji melalui konfirmasi.
- Jelaskan bahwa tabel penugasan mengikat satu kombinasi poli, tanggal, dan shift ke laporan. Sistem mengisi baris tersebut otomatis saat laporan pertama kali disimpan. Jelaskan juga PK gabungan detail, transaksi penyimpanan induk dan detail, dan pembatasan perubahan laporan hari ini melalui backend. Perubahan jadwal master tidak mengubah waktu dan target yang sudah disalin ke detail. Nilai keterlambatan sesi tanpa kedatangan adalah NULL. Capaian target dibatasi 100 persen sesuai rancangan kelompok.
- Untuk memperbarui database demo lama, jalankan sql/06_akun_petugas_bersama.sql sebagai pemilik skema. Skrip memakai kembali ID dan kata sandi petugas.dalam dengan nama pengguna petugas.poli, menonaktifkan akun petugas lama lainnya, dan mempertahankan hubungan ke laporan terdahulu.

## Antarmuka dan demo daring

Antarmuka menggunakan layout vertikal, kartu, tabel, dan kontrol formulir [Tabler](https://preview.tabler.io/layout-vertical.html). CSS resmi Tabler 1.7.5 dan lisensi MIT tersedia di public/vendor/tabler. Referensi kebutuhan berasal dari Dashboard Harian Yanmedkep. Prototype dapat dijalankan tanpa CDN.

Untuk demo di Vercel, gunakan PostgreSQL yang hanya berisi data simulasi. Jalankan skrip setup manual dan sql/05_sesi_vercel.sql pada database demo. Skrip terakhir membuat tabel sesi pada skema teknis poli_runtime. Fungsi PHP menggunakan tabel itu untuk mempertahankan login di beberapa instance, sementara demo Windows menggunakan sesi PHP lokal.

Impor repo ke Vercel. Konfigurasi vercel.json menggunakan runtime komunitas PHP dan menerbitkan CSS dari folder dist. Atur POLI_DSN, POLI_DB_USER, POLI_DB_PASSWORD, serta POLI_HTTPS=1 di lingkungan Production. Gunakan host PostgreSQL direct karena aplikasi mengatur search_path dan zona waktu per koneksi. Berikan kredensial akun demo lewat jalur privat. Lihat [runtime Vercel](https://vercel.com/docs/functions/runtimes) dan [integrasi PostgreSQL](https://vercel.com/docs/postgres).
