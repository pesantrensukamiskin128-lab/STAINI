<#
Rilis backend ke Hostinger: diff -> cek drift -> backup -> upload -> lint -> migrate.

Contoh:
  .\deploy\release-backend.ps1 -Since rilis-20261012            # dry-run (default), hanya menampilkan rencana
  .\deploy\release-backend.ps1 -Since rilis-20261012 -Apply     # eksekusi
  .\deploy\release-backend.ps1 -Since rilis-20261012 -Apply -Tag rilis-20261020

-Since : commit/tag rilis terakhir (titik awal diff terhadap HEAD).
Drift  : file di server yang berbeda dari versi -Since (mis. perbaikan darurat) TIDAK ditimpa
         kecuali memakai -Force.
Hapus  : file yang dihapus di git hanya dilaporkan, tidak dihapus di server.
#>
param(
    [Parameter(Mandatory)][string]$Since,
    [switch]$Apply,
    [switch]$Force,
    [string]$Tag,
    [string]$Key = "$HOME\.ssh\id_ed25519_railway_asc",
    [string]$Server = 'u273554645@31.97.104.148',
    [int]$Port = 65002,
    [string]$Root = '/home/u273554645/asc-backend',
    [string]$BackupRoot = '/home/u273554645/asc-backups'
)
$ErrorActionPreference = 'Stop'
$repo = (git rev-parse --show-toplevel).Trim()
Set-Location $repo

if (git status --porcelain -- backend) { throw 'Ada perubahan backend yang belum di-commit. Commit dulu agar rilis dapat dilacak.' }
git rev-parse --verify "$Since^{commit}" *> $null
if ($LASTEXITCODE) { throw "Ref '$Since' tidak ditemukan." }

$ssh = @('-i', $Key, '-p', $Port, '-o', 'BatchMode=yes', $Server)
function Invoke-Remote([string]$cmd, [string[]]$lines) {
    if ($lines) { ($lines -join "`n") + "`n" | & ssh @ssh $cmd } else { & ssh @ssh $cmd }
}
# Hash apa adanya dan hash versi LF; server bisa menyimpan CRLF atau LF.
function Get-Md5Set([byte[]]$bytes) {
    $md5 = [Security.Cryptography.MD5]::Create()
    $t = [Text.Encoding]::UTF8.GetString($bytes) -replace "`r`n", "`n"
    @($md5.ComputeHash($bytes), $md5.ComputeHash([Text.Encoding]::UTF8.GetBytes($t))) |
        ForEach-Object { ([BitConverter]::ToString($_) -replace '-', '').ToLower() }
}
function Get-GitBytes([string]$ref, [string]$path) {
    $tmp = [IO.Path]::GetTempFileName()
    try {
        cmd /c "git show ${ref}:backend/$path > `"$tmp`" 2>nul"
        if ($LASTEXITCODE) { return $null }
        return , [IO.File]::ReadAllBytes($tmp)
    } finally { Remove-Item $tmp -ErrorAction SilentlyContinue }
}

# Hanya kode aplikasi; tests, vendor, storage, dan .env tidak ikut.
$scope = 'app/', 'routes/', 'database/migrations/', 'database/seeders/', 'config/', 'resources/', 'bootstrap/app.php', 'composer.json', 'composer.lock'
$status = git diff --name-status --no-renames "$Since..HEAD" -- backend
$changed = @(); $deleted = @()
foreach ($l in $status) {
    $s, $p = $l -split "`t", 2
    $p = $p -replace '^backend/', ''
    if (-not ($scope | Where-Object { $p -like "$_*" })) { continue }
    if ($s -eq 'D') { $deleted += $p } else { $changed += $p }
}
if (-not $changed -and -not $deleted) { 'Tidak ada perubahan backend sejak ' + $Since; return }

# Hash file di server
$srv = @{}
Invoke-Remote "cd $Root && tr -d '\r' | sed '/^$/d' | xargs -d '\n' md5sum 2>/dev/null" $changed | ForEach-Object {
    $p = $_ -split '\s+', 2; if ($p.Count -eq 2) { $srv[$p[1].Trim()] = $p[0] }
}

$upload = @(); $same = @(); $drift = @()
foreach ($f in $changed) {
    $new = Get-Md5Set ([IO.File]::ReadAllBytes((Join-Path $repo "backend\$($f -replace '/','\')")))
    if (-not $srv.ContainsKey($f)) { $upload += $f; continue }   # file baru di server
    if ($new -contains $srv[$f]) { $same += $f; continue }              # sudah sama dengan server
    $old = Get-GitBytes $Since $f
    if ($old -and ((Get-Md5Set $old) -contains $srv[$f])) { $upload += $f } else { $drift += $f }
}
$migrations = $upload | Where-Object { $_ -like 'database/migrations/*' }

"Rilis backend: $Since -> $(git rev-parse --short HEAD)"
"  Diunggah          : $($upload.Count)"; $upload | ForEach-Object { "    + $_" }
"  Sudah sama server : $($same.Count)"
"  DRIFT (server != $Since): $($drift.Count)"; $drift | ForEach-Object { "    ! $_" }
"  Dihapus di git (tidak dihapus di server): $($deleted.Count)"; $deleted | ForEach-Object { "    - $_" }
"  Migration baru    : $($migrations.Count)"

if ($drift -and -not $Force) {
    "`nBerhenti: file DRIFT di atas diubah langsung di server. Periksa dulu (bandingkan server vs git), lalu gabungkan atau ulangi dengan -Force untuk menimpa."
    if ($Apply) { exit 2 } else { "(dry-run)" }
}
if ($Force) { $upload += $drift }
if (-not $Apply) { "`nDRY-RUN: tidak ada yang diubah. Tambahkan -Apply untuk eksekusi."; return }
if (-not $upload) { 'Tidak ada yang perlu diunggah.'; return }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$bk = "$BackupRoot/release-$stamp"
Invoke-Remote "mkdir -p $bk && cd $Root && tr -d '\r' | sed '/^$/d' | xargs -d '\n' -I{} sh -c 'test -f {} && cp --parents {} $bk/ || true'" $upload
"Backup: $bk"

$tar = Join-Path $env:TEMP "release-backend-$stamp.tar"
try {
    $list = Join-Path $env:TEMP "release-backend-$stamp.txt"
    $upload | Set-Content $list -Encoding ascii
    tar -cf $tar -C (Join-Path $repo 'backend') -T $list
    if ($LASTEXITCODE) { throw 'Gagal membuat arsip.' }
    cmd /c "ssh -i `"$Key`" -p $Port -o BatchMode=yes $Server `"cd $Root && tar -xf -`" < `"$tar`""
    if ($LASTEXITCODE) { throw 'Upload gagal. Pulihkan dari ' + $bk }
} finally { Remove-Item $tar, $list -ErrorAction SilentlyContinue }
"Upload selesai."

$php = $upload | Where-Object { $_ -like '*.php' }
$lint = Invoke-Remote "cd $Root && tr -d '\r' | sed '/^$/d' | xargs -d '\n' -n1 php -l 2>&1 | grep -v '^No syntax errors'" $php
if ($lint) { $lint; throw "Syntax error di server. Pulihkan dari $bk (cp -r $bk/* $Root/)." }
'Lint PHP OK.'

if ($migrations) {
    Invoke-Remote "cd $Root && php artisan migrate --force"
    if ($LASTEXITCODE) { throw 'Migrate gagal.' }
}
Invoke-Remote "cd $Root && php artisan optimize:clear > /dev/null && echo optimize:clear OK"

if ($Tag) { git tag $Tag HEAD; "Tag lokal dibuat: $Tag (git push origin $Tag untuk membagikan)" }
"Selesai. Backup: $bk"
