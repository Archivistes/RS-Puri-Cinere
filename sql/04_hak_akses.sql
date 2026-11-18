-- Run as schema owner after provisioning the separate LOGIN role dashboard_app.
GRANT USAGE ON SCHEMA poli TO dashboard_app;
GRANT SELECT ON ALL TABLES IN SCHEMA poli TO dashboard_app;
GRANT INSERT, UPDATE, DELETE ON poli.penugasan, poli.laporan, poli.detail_kinerja TO dashboard_app;
GRANT USAGE ON SEQUENCE poli.penugasan_id_seq TO dashboard_app;
