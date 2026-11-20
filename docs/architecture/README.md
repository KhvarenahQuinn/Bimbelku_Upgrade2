# Arsitektur

Aplikasi memakai React dan TypeScript untuk antarmuka web serta Laravel untuk API dan proses bisnis. Frontend berada di `src/`; backend berada di `bimbelku-backend/`.

Data bisnis disimpan melalui model dan layanan Laravel. Otorisasi memakai middleware dan kebijakan akses berbasis peran (`role-based access control`). File publik disimpan pada disk publik dan dilayani melalui route media; dokumen sensitif berada di disk privat dan dilayani setelah pemeriksaan izin.

Konfigurasi runtime dibaca dari `environment variables`. Jangan menaruh kredensial atau data sensitif di dokumentasi, log, maupun source control.
