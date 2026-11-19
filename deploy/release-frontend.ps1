<#
Rilis frontend penuh ke Hostinger: build -> susun index.html -> backup -> upload -> verifikasi.

Contoh:
  .\deploy\release-frontend.ps1                                        # dry-run (default): build + rencana, server tidak diubah
  .\deploy\release-frontend.ps1 -Drop student-biodata-attendance-release-v5.js -Apply
  .\deploy\release-frontend.ps1 -Rollback /home/u273554645/asc-backups/fe-20261013-010000 -Apply
  .\deploy\release-frontend.ps1 -Drop a.js,b.js -Apply -Tag fe-rilis-20261013

Produksi berlapis: index.html produksi memuat skrip overlay (/*.js di root) yang menambal fitur lewat DOM.
Script ini MEMPERTAHANKAN semua tag overlay dari index.html produksi, kecuali yang disebut di -Drop
(fitur itu sudah ada di source; jika tidak dilepas akan tampil dobel). File overlay di server tidak dihapus.
Aset di assets/ bersifat tambahan (nama berhash), tidak ada yang ditimpa atau dihapus.
Yang diganti dan di-backup: index.html, sw-asc-v3.js, manifest.webmanifest, workbox-*.js, registerSW.js.
Urutan upload: assets -> workbox -> sw -> index.html (terakhir), sehingga situs tidak pernah menunjuk ke berkas yang belum ada.
#>
param(
    [string[]]$Drop = @(),
    [switch]$Apply,
    [switch]$SkipBuild,
    [string]$BuildDir,
    [string]$Rollback,
    [string]$Tag,
    [string]$Key = "$HOME\.ssh\id_ed25519_railway_asc",
    [string]$Server = 'u273554645@31.97.104.148',
    [int]$Port = 65002,
    [string]$Root = '/home/u273554645/domains/stainibandung.ac.id/public_html/asc',
    [string]$BackupRoot = '/home/u273554645/asc-backups',
    [string]$Site = 'https://nuris.byethost31.com'
)
$ErrorActionPreference = 'Stop'
$repo = (git rev-parse --show-toplevel).Trim()
Set-Location $repo

$ssh = @('-i', $Key, '-p', $Port, '-o', 'BatchMode=yes', $Server)
function Invoke-Remote([string]$cmd) {
    $out = & ssh @ssh $cmd
    if ($LASTEXITCODE) { throw "Perintah remote gagal: $cmd" }
    $out
}
function Send-Tar([string]$base, [string[]]$files) {
    $list = [IO.Path]::GetTempFileName(); $tar = [IO.Path]::GetTempFileName()
    try {
        $files | Set-Content $list -Encoding ascii
        tar -cf $tar -C $base -T $list
        if ($LASTEXITCODE) { throw 'Gagal membuat arsip.' }
        cmd /c "ssh -i `"$Key`" -p $Port -o BatchMode=yes $Server `"cd $Root && tar -xf -`" < `"$tar`""
        if ($LASTEXITCODE) { throw 'Upload gagal.' }
    } finally { Remove-Item $list, $tar -ErrorAction SilentlyContinue }
}
function Get-Md5Hex([string]$text) {
    $bytes = (New-Object Text.UTF8Encoding $false).GetBytes($text)
    $h = [Security.Cryptography.MD5]::Create().ComputeHash($bytes)
    ([BitConverter]::ToString($h) -replace '-', '').ToLower()
}
function Test-Url([string]$url) {
    try { (Invoke-WebRequest $url -UseBasicParsing -Method Head -TimeoutSec 20).StatusCode -eq 200 } catch { $false }
}
$mutable = @('index.html', 'sw-asc-v3.js', 'manifest.webmanifest', 'registerSW.js')

# ---------- Rollback ----------
if ($Rollback) {
    "Rollback dari: $Rollback"
    Invoke-Remote "ls -1 $Rollback"
    if (-not $Apply) { "`nDRY-RUN: tambahkan -Apply untuk memulihkan berkas di atas."; return }
    Invoke-Remote "cd $Rollback && cp -p index.html sw-asc-v3.js $Root/ && for f in manifest.webmanifest registerSW.js; do test -f `$f && cp -p `$f $Root/; done; echo restored"
    "Selesai. Pengguna perlu memuat ulang 1-2 kali."
    return
}

# ---------- Build ----------
if (git status --porcelain -- frontend) { throw 'Ada perubahan frontend yang belum di-commit. Commit dulu agar rilis dapat dilacak.' }
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$out = Join-Path $env:TEMP "fe-release-$stamp"
if ($SkipBuild) {
    if (-not $BuildDir) { throw '-SkipBuild butuh -BuildDir (folder hasil build yang sudah ada).' }
    Copy-Item $BuildDir $out -Recurse
} else {
    Push-Location (Join-Path $repo 'frontend')
    try {
        if (-not (Test-Path node_modules)) { npm ci; if ($LASTEXITCODE) { throw 'npm ci gagal.' } }
        # Cek tipe dilewati: yang dirilis adalah hasil vite build, sama seperti rilis sebelumnya.
        $ErrorActionPreference = 'Continue'   # vite menulis peringatan ke stderr
        npx vite build --outDir $out --emptyOutDir 2>&1 | Out-Host
        $code = $LASTEXITCODE
        $ErrorActionPreference = 'Stop'
        if ($code) { throw 'Build gagal.' }
    } finally { Pop-Location }
}
if (-not (Test-Path "$out\index.html")) { throw "Hasil build tidak ditemukan di $out" }
$api = Select-String -Path "$out\assets\*.js" -Pattern 'nuris.byethost31.com' -List
if (-not $api) { throw 'URL API produksi tidak ada di hasil build. Periksa frontend/.env.production.' }

# ---------- Susun index.html: build baru + overlay produksi ----------
$prodHtml = (Invoke-Remote "cat $Root/index.html") -join "`n"
$overlayTags = [regex]::Matches($prodHtml, '<script[^>]*\ssrc="/(?!assets/)([^"/]+\.js)"[^>]*></script>') |
    ForEach-Object { [pscustomobject]@{ File = $_.Groups[1].Value; Tag = $_.Value } }
$unknown = @($Drop | Where-Object { $_ -notin $overlayTags.File })
if ($unknown) { Write-Warning "Sudah tidak ada di index.html produksi (dilewati): $($unknown -join ', ')" }
$Drop = @($Drop | Where-Object { $_ -in $overlayTags.File })
$keep = @($overlayTags | Where-Object { $_.File -notin $Drop })

$html = [IO.File]::ReadAllText("$out\index.html")
$html = $html -replace '<script[^>]*\ssrc="/(?!assets/)[^"/]+\.js"[^>]*></script>\s*', ''   # buang overlay bawaan public/ repo
if ($html -notmatch '</head>') { throw 'index.html hasil build tidak punya </head>.' }
$inject = ($keep | ForEach-Object { '    ' + $_.Tag }) -join "`n"
$html = $html -replace '</head>', "$inject`n  </head>"
$html = $html -replace "`r`n", "`n"
[IO.File]::WriteAllText("$out\index.html", $html, (New-Object Text.UTF8Encoding $false))

$missingOv = @()
foreach ($o in $keep) { if (-not (Test-Url "$Site/$($o.File)")) { $missingOv += $o.File } }
if ($missingOv) { throw "Overlay yang dipertahankan tidak ada di server: $($missingOv -join ', ')" }

# sw: revisi index.html harus sesuai berkas yang benar-benar diunggah
$sw = [IO.File]::ReadAllText("$out\sw-asc-v3.js")
$newRev = Get-Md5Hex $html
$sw2 = [regex]::Replace($sw, '(\{url:"index\.html",revision:")[^"]*("\})', "`${1}$newRev`${2}")
if ($sw2 -eq $sw -and $sw -notmatch [regex]::Escape($newRev)) { throw 'Entri index.html tidak ditemukan di sw-asc-v3.js.' }
[IO.File]::WriteAllText("$out\sw-asc-v3.js", $sw2, (New-Object Text.UTF8Encoding $false))

# ---------- Rencana ----------
$remoteAssets = @{}
Invoke-Remote "ls -1 $Root/assets" | ForEach-Object { $remoteAssets[$_.Trim()] = $true }
$newAssets = @(Get-ChildItem "$out\assets" -File | Where-Object { -not $remoteAssets.ContainsKey($_.Name) } | ForEach-Object { "assets/$($_.Name)" })
$workbox = @(Get-ChildItem $out -File -Filter 'workbox-*.js' | ForEach-Object Name)
$replace = @($mutable | Where-Object { Test-Path "$out\$_" })

"Rilis frontend dari $(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD))"
"  Build            : $out"
"  Aset baru        : $($newAssets.Count) dari $((Get-ChildItem "$out\assets" -File).Count) (sisanya sudah ada di server)"
"  Diganti + backup : $($replace + $workbox -join ', ')"
"  Overlay dipertahankan: $($keep.Count)"
"  Overlay dilepas dari index.html: $($Drop.Count)"; $Drop | ForEach-Object { "    - $_" }
if (-not $Drop) { "  PERINGATAN: tanpa -Drop semua overlay tetap aktif. Fitur yang sudah ada di source akan tampil dobel." }

if (-not $Apply) { "`nDRY-RUN: server tidak diubah. Tambahkan -Apply untuk eksekusi."; return }

# ---------- Backup ----------
$bk = "$BackupRoot/fe-$stamp"
Invoke-Remote "mkdir -p $bk && cd $Root && for f in $($replace -join ' ') workbox-*.js; do test -f `$f && cp -p `$f $bk/; done; echo ok" | Out-Null
"Backup: $bk"

# ---------- Upload ----------
try {
    if ($newAssets) { Send-Tar $out $newAssets }
    $icons = @(Get-ChildItem "$out\icons" -File -ErrorAction SilentlyContinue | ForEach-Object { "icons/$($_.Name)" })
    if ($icons) { Send-Tar $out $icons }
    if ($workbox) { Send-Tar $out $workbox }
    Send-Tar $out @('sw-asc-v3.js')
    $rest = @($replace | Where-Object { $_ -notin 'sw-asc-v3.js', 'index.html' })
    if ($rest) { Send-Tar $out $rest }
    Send-Tar $out @('index.html')
} catch {
    "Upload terhenti: $_"
    "Pulihkan dengan: .\deploy\release-frontend.ps1 -Rollback $bk -Apply"
    throw
}
'Upload selesai.'

# ---------- Verifikasi ----------
$live = (Invoke-WebRequest "$Site/index.html?v=$stamp" -UseBasicParsing).Content
$refs = [regex]::Matches($live, '(?:src|href)="(/[^"]+\.(?:js|css))"') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique
$bad = @($refs | Where-Object { -not (Test-Url "$Site$_") })
if ($bad) {
    "Berkas tidak bisa diakses: $($bad -join ', ')"
    "Pulihkan dengan: .\deploy\release-frontend.ps1 -Rollback $bk -Apply"
    exit 2
}
"Verifikasi OK: $($refs.Count) berkas dimuat dengan status 200."

if ($Tag) { git tag $Tag HEAD; "Tag lokal dibuat: $Tag (git push origin $Tag untuk membagikan)" }
"Selesai. Backup: $bk. Pengguna perlu memuat ulang 1-2 kali. Uji di browser, lalu commit source bila ada perubahan."
