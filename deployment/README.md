# Deployment ASC ke Rumahweb melalui GitHub

Deployment menggunakan tiga lokasi production:

- Laravel: `/home/stad1276/asc-backend`
- public backend: `/home/stad1276/public_html/api-asc`
- frontend: `/home/stad1276/public_html/smartcampus`

## Menyiapkan release di komputer lokal

Jalankan dari PowerShell pada root repository:

```powershell
.\deployment\prepare-rumahweb-release.ps1
```

Skrip menjalankan `npm ci`, pemeriksaan tipe, build frontend, lalu menyalin hasilnya ke
`deployment/frontend-dist`. Commit hasil build tersebut bersama perubahan source agar
cPanel dapat memasangnya tanpa membutuhkan Node.js.

## Deployment melalui cPanel

Sebelum deployment pertama, jalankan pemeriksaan yang tidak mengubah production:

```bash
cd /home/stad1276/repositories/ASC
bash deployment/rumahweb-deploy.sh --check
```

1. Buat backup database jika release berisi perubahan struktur atau data.
2. Buka **Git Version Control**.
3. Jalankan **Update from Remote**.
4. Pastikan commit yang diterima benar.
5. Jalankan **Deploy HEAD Commit**.
6. Periksa log deployment sampai selesai tanpa error.
7. Jika release mempunyai migration, jalankan secara manual setelah backup:

   ```bash
   cd /home/stad1276/asc-backend
   php artisan migrate --force
   ```

8. Uji frontend, API, login, unggah berkas, dan fungsi yang berubah.

## Perlindungan data production

Skrip deployment tidak menimpa `backend/.env`, `backend/storage`, `backend/vendor`,
`public_html/api-asc/index.php`, atau storage publik. Migration database juga tidak
dijalankan otomatis. Skrip hanya menggunakan utilitas standar hosting (`tar`, `find`,
dan `cp`) sehingga tidak membutuhkan `rsync`.
Composer dipanggil melalui path cPanel `/home/stad1276/bin/composer` agar tetap tersedia
di lingkungan deployment non-interaktif.
