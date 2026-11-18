-- Run once as owner in the demo database after creating dashboard_app.
-- This schema holds web sessions separately from the seven business tables.
BEGIN;
CREATE SCHEMA poli_runtime;
CREATE TABLE poli_runtime.web_session (
    id_hash char(64) PRIMARY KEY,
    data text NOT NULL,
    expires_at timestamptz NOT NULL
);
CREATE INDEX web_session_expires_idx ON poli_runtime.web_session(expires_at);
GRANT USAGE ON SCHEMA poli_runtime TO dashboard_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON poli_runtime.web_session TO dashboard_app;
COMMIT;
