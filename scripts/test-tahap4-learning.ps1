$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $PSScriptRoot
$BackendRoot = Join-Path $ProjectRoot "bimbelku-backend"

Write-Host "Memeriksa konfigurasi tanpa mengubah .env..." -ForegroundColor Cyan
if (-not (Test-Path (Join-Path $ProjectRoot ".env"))) {
    throw "Frontend .env tidak ditemukan. Salin .env lama ke folder proyek."
}
if (-not (Test-Path (Join-Path $BackendRoot ".env"))) {
    throw "Backend .env tidak ditemukan. Salin bimbelku-backend\.env lama."
}

Write-Host "Memasang dan memeriksa frontend..." -ForegroundColor Cyan
Set-Location $ProjectRoot
npm ci
npm run check

Write-Host "Memasang backend..." -ForegroundColor Cyan
Set-Location $BackendRoot
composer install --no-interaction

Write-Host "Menjalankan migration bertahap..." -ForegroundColor Cyan
php artisan migrate
php artisan optimize:clear

try {
    php artisan storage:link
} catch {
    Write-Host "Storage link sudah tersedia atau tidak perlu dibuat ulang." -ForegroundColor Yellow
}

Write-Host "Menjalankan tes Tahap 4..." -ForegroundColor Cyan
php artisan test --filter="SessionPresenceFlowV2Test|FreeTextLearningFocusTest|StageFourAccountFlowTest"
php artisan route:list --path=api

Write-Host "Tahap 4 lulus pada Laragon." -ForegroundColor Green
Write-Host "Jangan menjalankan php artisan migrate:fresh." -ForegroundColor Yellow
