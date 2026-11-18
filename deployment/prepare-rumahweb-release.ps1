[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$frontendRoot = Join-Path $repositoryRoot 'frontend'
$frontendDist = Join-Path $frontendRoot 'dist'
$releaseRoot = Join-Path $PSScriptRoot 'frontend-dist'

if (-not (Test-Path -LiteralPath (Join-Path $frontendRoot 'package.json'))) {
    throw 'Folder frontend tidak valid.'
}

Write-Host 'Memasang dependency frontend secara konsisten...' -ForegroundColor Cyan
Push-Location $frontendRoot
try {
    npm ci
    if ($LASTEXITCODE -ne 0) { throw "npm ci gagal dengan exit code $LASTEXITCODE." }

    npm run build
    if ($LASTEXITCODE -ne 0) { throw "Build frontend gagal dengan exit code $LASTEXITCODE." }
}
finally {
    Pop-Location
}

if (-not (Test-Path -LiteralPath (Join-Path $frontendDist 'index.html'))) {
    throw 'Build tidak menghasilkan frontend/dist/index.html.'
}

$resolvedDeployment = [System.IO.Path]::GetFullPath($PSScriptRoot)
$resolvedRelease = [System.IO.Path]::GetFullPath($releaseRoot)
if (-not $resolvedRelease.StartsWith($resolvedDeployment, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Lokasi frontend release berada di luar folder deployment.'
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

Write-Host ''
Write-Host 'Frontend release siap:' -ForegroundColor Green
Write-Host $releaseRoot
Write-Host ''
Write-Host 'Periksa aplikasi, lalu commit folder deployment/frontend-dist bersama perubahan source.' -ForegroundColor Yellow
