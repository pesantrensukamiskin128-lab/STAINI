[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$frontendRoot = Join-Path $repositoryRoot 'frontend'
$frontendDist = Join-Path $frontendRoot 'dist'
$releaseRoot = Join-Path $PSScriptRoot 'hostinger-frontend-dist'
$hostingerApiUrl = 'https://nuris.byethost31.com/api'

if (-not (Test-Path -LiteralPath (Join-Path $frontendRoot 'package.json'))) {
    throw 'Folder frontend tidak valid.'
}

$previousApiUrl = [Environment]::GetEnvironmentVariable('VITE_API_URL', 'Process')
$previousAppName = [Environment]::GetEnvironmentVariable('VITE_APP_NAME', 'Process')

Push-Location $frontendRoot
try {
    [Environment]::SetEnvironmentVariable('VITE_API_URL', $hostingerApiUrl, 'Process')
    [Environment]::SetEnvironmentVariable('VITE_APP_NAME', 'Al-Jawami Smart Campus', 'Process')

    Write-Host 'Memasang dependency frontend...' -ForegroundColor Cyan
    npm ci
    if ($LASTEXITCODE -ne 0) { throw "npm ci gagal dengan exit code $LASTEXITCODE." }

    Write-Host "Membangun frontend untuk $hostingerApiUrl ..." -ForegroundColor Cyan
    npm run build
    if ($LASTEXITCODE -ne 0) { throw "Build frontend gagal dengan exit code $LASTEXITCODE." }
}
finally {
    [Environment]::SetEnvironmentVariable('VITE_API_URL', $previousApiUrl, 'Process')
    [Environment]::SetEnvironmentVariable('VITE_APP_NAME', $previousAppName, 'Process')
    Pop-Location
}

if (-not (Test-Path -LiteralPath (Join-Path $frontendDist 'index.html'))) {
    throw 'Build tidak menghasilkan frontend/dist/index.html.'
}

$resolvedDeployment = [System.IO.Path]::GetFullPath($PSScriptRoot)
$resolvedRelease = [System.IO.Path]::GetFullPath($releaseRoot)
if (-not $resolvedRelease.StartsWith($resolvedDeployment, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Lokasi release berada di luar folder deployment.'
}

if (Test-Path -LiteralPath $releaseRoot) {
    Remove-Item -LiteralPath $releaseRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $releaseRoot | Out-Null

Get-ChildItem -LiteralPath $frontendDist -Force |
    Where-Object { $_.Name -ne 'Thumbs.db' } |
    ForEach-Object {
        Copy-Item -LiteralPath $_.FullName -Destination $releaseRoot -Recurse -Force
    }

Get-ChildItem -LiteralPath $releaseRoot -Filter 'Thumbs.db' -Recurse -Force |
    Remove-Item -Force

$releaseFiles = Get-ChildItem -LiteralPath $releaseRoot -Recurse -File
$hostingerReferences = Select-String -Path $releaseFiles.FullName -Pattern $hostingerApiUrl -SimpleMatch -List -ErrorAction SilentlyContinue
if (-not $hostingerReferences) {
    throw 'URL API Hostinger tidak ditemukan dalam release frontend.'
}

$rumahwebReferences = Select-String -Path $releaseFiles.FullName -Pattern 'https://api-asc.stainibandung.ac.id/api' -SimpleMatch -List -ErrorAction SilentlyContinue
if ($rumahwebReferences) {
    throw 'URL API Rumahweb masih ditemukan dalam release Hostinger.'
}

Write-Host 'Release Hostinger siap:' -ForegroundColor Green
Write-Host $releaseRoot
