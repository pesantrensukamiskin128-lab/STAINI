# Deploy ASC ke Rumahweb Unlimited L

Target produksi:

- Frontend: `https://smartcampus.stai-aljawami.ac.id`
- Backend API: `https://api-asc.stai-aljawami.ac.id`
- Platform: Shared/Cloud Hosting cPanel Unlimited L

## 1. Persiapan cPanel

1. Buat kedua subdomain melalui menu **Domains**.
2. Atur document root:
   - `smartcampus.stai-aljawami.ac.id` → `public_html/smartcampus`
   - `api-asc.stai-aljawami.ac.id` → `public_html/api-asc`
3. Aktifkan AutoSSL/Let's Encrypt untuk kedua subdomain dan paksa HTTPS.
4. Pilih PHP 8.3 atau 8.4 untuk subdomain API melalui **MultiPHP Manager** atau **Select PHP Version**.
5. Aktifkan ekstensi PHP: `bcmath`, `ctype`, `curl`, `dom`, `exif`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, dan `zip`.
6. Atur sekurangnya `memory_limit=512M`, `upload_max_filesize=32M`, `post_max_size=40M`, dan `max_execution_time=300` jika paket mengizinkan.

Document root di hosting cPanel Rumahweb berada di bawah `public_html`. Karena itu, inti Laravel ditempatkan di luar `public_html`, sementara hanya isi folder `public` yang dipasang pada subdomain API.

## 2. Pertahankan identitas instalasi lama

Sebelum memindahkan aplikasi, salin dan simpan secara aman dari server lama:

- `APP_KEY`
- kredensial dan backup database
- isi `storage/app/public`
- `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, dan `VAPID_SUBJECT`
- konfigurasi SMTP yang masih dipakai

Jangan membuat `APP_KEY` baru. Mengganti kunci dapat merusak pembacaan data terenkripsi dan validasi data yang bergantung pada kunci aplikasi.

## 3. Siapkan frontend di komputer lokal

Konfigurasi `frontend/.env.production` sudah disiapkan. Pastikan isinya:

```env
VITE_API_URL=https://api-asc.stai-aljawami.ac.id/api
VITE_APP_NAME="Al-Jawami Smart Campus"
```

Build frontend:

```powershell
Set-Location frontend
npm ci
npm run build
```

Unggah **isi** `frontend/dist/` ke:

```text
/home/CPANEL_USER/public_html/smartcampus/
```

File `.htaccess` untuk Vue Router sudah disertakan di `frontend/public` dan otomatis masuk ke hasil build. Aktifkan **Show Hidden Files** pada cPanel File Manager agar `.htaccess` ikut terunggah.

## 4. Siapkan backend

Salin `backend/.env.production.example` menjadi `.env`, lalu isi:

- `APP_KEY` dari server lama
- nama, username, dan password database cPanel
- akun SMTP Rumahweb
- VAPID key dari server lama

Jangan commit atau mengunggah `.env` ke lokasi yang dapat diakses publik.

Unggah folder backend, selain `public`, ke:

```text
/home/CPANEL_USER/asc-backend/
```

Unggah **isi** `backend/public/` ke:

```text
/home/CPANEL_USER/public_html/api-asc/
```

Pastikan file tersembunyi `backend/public/.htaccess` juga ikut terunggah.

Edit file `/home/CPANEL_USER/public_html/api-asc/index.php` di server menjadi pola berikut:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appPath = dirname(__DIR__, 2).'/asc-backend';

if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appPath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appPath.'/bootstrap/app.php';
$app->handleRequest(Request::capture());
```

Jangan memindahkan `.env`, `vendor`, `storage`, atau source Laravel lain ke `public_html/api-asc`.

## 5. Instal dependency backend

Melalui cPanel Terminal/SSH:

```bash
cd /home/CPANEL_USER/asc-backend
composer install --no-dev --optimize-autoloader --no-interaction
chmod -R 775 storage bootstrap/cache
php artisan optimize:clear
```

Jika perintah `composer` tidak tersedia, jalankan `composer install --no-dev --optimize-autoloader` di komputer dengan PHP 8.3/8.4, kemudian unggah folder `vendor` bersama backend.

## 6. Pindahkan database

Buat database dan user melalui **MySQL Databases**, lalu berikan seluruh privilege ke database ASC.

Karena database aplikasi cukup besar, gunakan Terminal/SSH, bukan impor browser, jika phpMyAdmin mencapai batas unggahan:

```bash
gunzip -c /home/CPANEL_USER/asc-database.sql.gz | mysql -u DB_USERNAME -p DB_DATABASE
```

Setelah database lama berhasil diimpor:

```bash
cd /home/CPANEL_USER/asc-backend
php artisan migrate --force
php artisan config:cache
php artisan view:cache
```

Jangan menjalankan `migrate:fresh`. Jangan menjalankan `db:seed` pada database hasil pemindahan tanpa memeriksa seedernya terlebih dahulu.

## 7. Pindahkan berkas pengguna

Salin isi `storage/app/public` dari server lama ke:

```text
/home/CPANEL_USER/asc-backend/storage/app/public/
```

Aplikasi memiliki route `/storage/{path}` sehingga file tetap dapat dilayani tanpa symlink. Jika SSH mengizinkan, symlink dapat dipakai sebagai optimasi tambahan:

```bash
ln -s /home/CPANEL_USER/asc-backend/storage/app/public /home/CPANEL_USER/public_html/api-asc/storage
```

## 8. Cron Job

Tambahkan melalui menu **Cron Jobs**. Sesuaikan binary PHP berdasarkan hasil `which php` di Terminal.

Scheduler, setiap menit:

```cron
* * * * * cd /home/CPANEL_USER/asc-backend && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Queue database, setiap lima menit:

```cron
*/5 * * * * cd /home/CPANEL_USER/asc-backend && /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=240 --tries=3 >> /dev/null 2>&1
```

Shared hosting umumnya tidak cocok untuk worker permanen. Pola `--stop-when-empty` membuat worker selesai sebelum pemanggilan cron berikutnya.

## 9. Pemeriksaan akhir

Jalankan:

```bash
cd /home/CPANEL_USER/asc-backend
php artisan about
php artisan migrate:status
php artisan config:show app
php artisan queue:failed
```

Periksa melalui browser:

1. `https://api-asc.stai-aljawami.ac.id/api/institution/public` menghasilkan JSON.
2. `https://smartcampus.stai-aljawami.ac.id/login` menampilkan halaman login.
3. Login admin, dosen, dan mahasiswa berhasil.
4. Unggah foto/dokumen berhasil dan dapat dibuka kembali.
5. Cetak KHS serta transkrip berhasil.
6. QR dokumen mengarah ke domain baru dan terverifikasi.
7. Email, push notification, scheduler, dan queue berjalan.
8. URL `https://api-asc.stai-aljawami.ac.id/api/migration/status` menghasilkan `404` karena endpoint migrasi harus nonaktif.

## 10. Update aplikasi berikutnya

Backend:

```bash
cd /home/CPANEL_USER/asc-backend
php artisan down
git pull origin main
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
php artisan up
```

Frontend dibangun kembali di komputer lokal, lalu isi terbaru `frontend/dist` diunggah ke `public_html/smartcampus`. Jika isi `backend/public` berubah, salin juga perubahan tersebut ke `public_html/api-asc` tanpa mengganti `index.php` yang sudah menunjuk ke `asc-backend`.

## Referensi Rumahweb

- [Upload Laravel pada subdomain cPanel](https://www.rumahweb.com/journal/cara-upload-laravel-di-subdomain/)
- [Mengatur versi PHP di cPanel](https://www.rumahweb.com/journal/cara-mengganti-versi-php-di-cpanel/)
- [Mengganti document root subdomain](https://www.rumahweb.com/journal/cara-mengganti-document-root-addon-dan-subdomain-di-cpanel/)
- [Membuat Cron Job dengan versi PHP tertentu](https://www.rumahweb.com/journal/membuat-cron-job-di-cpanel-menggunakan-berbagai-versi-php/)
