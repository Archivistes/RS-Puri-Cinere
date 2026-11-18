BEGIN;
CREATE SCHEMA poli;
SET LOCAL search_path TO poli, public;

CREATE TABLE pengguna (
    id integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    nama varchar(100) NOT NULL,
    username varchar(50) NOT NULL UNIQUE CHECK (username = lower(username)),
    password_hash varchar(255) NOT NULL,
    peran varchar(12) NOT NULL CHECK (peran IN ('admin','petugas')),
    aktif boolean NOT NULL DEFAULT true
);
CREATE TABLE poliklinik (
    id integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    nama varchar(100) NOT NULL UNIQUE,
    target_harian integer NOT NULL CHECK (target_harian >= 0)
);
CREATE TABLE dokter (
    id integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    nama varchar(100) NOT NULL,
    spesialisasi varchar(80) NOT NULL
);
CREATE TABLE jadwal_praktik (
    id integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    dokter_id integer NOT NULL REFERENCES dokter(id),
    poli_id integer NOT NULL REFERENCES poliklinik(id),
    hari smallint NOT NULL CHECK (hari BETWEEN 1 AND 7),
    shift varchar(4) NOT NULL CHECK (shift IN ('Pagi','Sore')),
    mulai time NOT NULL,
    selesai time NOT NULL CHECK (selesai > mulai),
    target integer NOT NULL CHECK (target > 0),
    UNIQUE (dokter_id, hari, shift)
);
CREATE TABLE penugasan (
    id integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    petugas_id integer NOT NULL REFERENCES pengguna(id),
    poli_id integer NOT NULL REFERENCES poliklinik(id),
    tanggal date NOT NULL,
    shift varchar(4) NOT NULL CHECK (shift IN ('Pagi','Sore')),
    UNIQUE (poli_id, tanggal, shift)
);
CREATE TABLE laporan (
    penugasan_id integer PRIMARY KEY REFERENCES penugasan(id),
    catatan text NOT NULL DEFAULT '' CHECK (char_length(catatan) <= 2000),
    dibuat timestamptz NOT NULL DEFAULT now(),
    diperbarui timestamptz NOT NULL DEFAULT now(),
    versi integer NOT NULL DEFAULT 1 CHECK (versi > 0)
);
CREATE TABLE detail_kinerja (
    penugasan_id integer NOT NULL REFERENCES laporan(penugasan_id) ON DELETE CASCADE,
    dokter_id integer NOT NULL REFERENCES dokter(id),
    mulai_rencana time NOT NULL,
    selesai_rencana time NOT NULL CHECK (selesai_rencana > mulai_rencana),
    datang time,
    pulang time,
    target integer NOT NULL CHECK (target > 0),
    pasien integer NOT NULL CHECK (pasien >= 0),
    status varchar(5) NOT NULL CHECK (status IN ('Hadir','Izin','Sakit','Alpa','Cuti')),
    kendala text NOT NULL DEFAULT '' CHECK (char_length(kendala) <= 500),
    terlambat integer GENERATED ALWAYS AS (
        CASE WHEN datang IS NOT NULL THEN GREATEST(0, FLOOR(EXTRACT(EPOCH FROM datang - mulai_rencana)/60)::integer) END
    ) STORED,
    pulang_cepat integer GENERATED ALWAYS AS (
        CASE WHEN pulang IS NOT NULL THEN GREATEST(0, FLOOR(EXTRACT(EPOCH FROM selesai_rencana - pulang)/60)::integer) END
    ) STORED,
    capaian numeric(5,2) GENERATED ALWAYS AS (
        LEAST(100, ROUND(100::numeric * pasien / NULLIF(target,0),2))
    ) STORED,
    PRIMARY KEY (penugasan_id, dokter_id),
    CHECK ((status = 'Hadir' AND datang IS NOT NULL AND pulang IS NOT NULL AND pulang >= datang)
        OR (status <> 'Hadir' AND datang IS NULL AND pulang IS NULL AND pasien = 0))
);

CREATE INDEX jadwal_poli_hari_shift_idx ON jadwal_praktik(poli_id,hari,shift);
CREATE INDEX penugasan_petugas_tanggal_idx ON penugasan(petugas_id,tanggal);
CREATE INDEX penugasan_tanggal_idx ON penugasan(tanggal,poli_id);
CREATE INDEX detail_dokter_idx ON detail_kinerja(dokter_id);

-- Enforce assignment identity and freeze it while a report exists.
CREATE FUNCTION jaga_penugasan() RETURNS trigger LANGUAGE plpgsql
SET search_path = poli, pg_temp AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pengguna WHERE id = NEW.petugas_id AND peran = 'petugas' AND aktif) THEN
        RAISE EXCEPTION 'Akun penugasan harus petugas aktif' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW IS DISTINCT FROM OLD
       AND EXISTS (SELECT 1 FROM laporan WHERE penugasan_id = OLD.id) THEN
        RAISE EXCEPTION 'Penugasan sudah memiliki laporan' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER cek_penugasan BEFORE INSERT OR UPDATE ON penugasan
FOR EACH ROW EXECUTE FUNCTION jaga_penugasan();

CREATE VIEW kinerja AS
SELECT p.tanggal,p.shift,p.poli_id,d.penugasan_id,d.dokter_id,
       d.pasien,d.target,d.status,d.terlambat,d.pulang_cepat,d.capaian
FROM detail_kinerja d JOIN penugasan p ON p.id=d.penugasan_id;
COMMIT;
