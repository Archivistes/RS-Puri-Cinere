-- Upgrade the course demo as the schema owner. Preserve reports and foreign keys.
BEGIN;
UPDATE poli.pengguna
SET username='petugas.poli', nama='Petugas Poli'
WHERE username='petugas.dalam' AND peran='petugas'
  AND NOT EXISTS (SELECT 1 FROM poli.pengguna WHERE username='petugas.poli');

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM poli.pengguna
        WHERE username='petugas.poli' AND peran='petugas' AND aktif
    ) THEN
        RAISE EXCEPTION 'The active shared staff account is required';
    END IF;
END
$$;

UPDATE poli.pengguna
SET aktif=false, password_hash='DISABLED'
WHERE peran='petugas' AND username<>'petugas.poli' AND (aktif OR password_hash<>'DISABLED');
COMMIT;
