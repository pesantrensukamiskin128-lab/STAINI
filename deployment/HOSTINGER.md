# Persiapan ASC untuk Hostinger

Frontend: `https://nuris.byethost31.com`  
Backend: `https://nuris.byethost31.com`

Repository private di-clone ke `/home/u273554645/repositories/ASC`, **bukan** ke
`public_html`. Release frontend Hostinger terpisah dari release Rumahweb.

Build dari PowerShell lokal:

```powershell
.\deployment\prepare-hostinger-release.ps1
```

Hasil build ada di `deployment/hostinger-frontend-dist`. Skrip menetapkan
`VITE_API_URL=https://nuris.byethost31.com/api` hanya selama proses build; file
`frontend/.env.production` Rumahweb tetap tidak berubah.

Contoh backend di `backend/.env.hostinger.example` harus diisi menjadi `.env` hanya
di server Hostinger. Jangan commit `.env`, password database, APP_KEY, atau VAPID
private key. Salin APP_KEY dan VAPID dari server aktif agar verifikasi dokumen dan
identitas yang bergantung pada key tetap konsisten.

Source Laravel berada di `/home/u273554645/asc-backend`. Document root API
`/home/u273554645/domains/stainibandung.ac.id/public_html/api-asc` hanya boleh berisi
berkas dari `backend/public`; salin `deployment/hostinger-api-index.php` sebagai
`index.php` dan `deployment/hostinger-api-htaccess` sebagai `.htaccess` di sana
agar bootstrap Laravel mengarah ke source di luar folder publik dan index API
dipilih secara eksplisit. Jangan salin `.env`, `vendor`, atau `storage` ke
document root.

Jangan memasang release ke subdomain sebelum database, storage, dan SSL Hostinger
siap. Setelah pemeriksaan awal, lakukan final sync database/storage dari Rumahweb
ketika penulisan data lama dihentikan agar tidak kehilangan perubahan terakhir.
