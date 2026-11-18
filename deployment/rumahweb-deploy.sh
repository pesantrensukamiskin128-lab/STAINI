#!/usr/bin/env bash

set -Eeuo pipefail

readonly ACCOUNT_ROOT="/home/stad1276"
readonly BACKEND_TARGET="${ACCOUNT_ROOT}/asc-backend"
readonly API_PUBLIC_TARGET="${ACCOUNT_ROOT}/public_html/api-asc"
readonly FRONTEND_TARGET="${ACCOUNT_ROOT}/public_html/smartcampus"
readonly REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly BACKEND_SOURCE="${REPOSITORY_ROOT}/backend"
readonly FRONTEND_RELEASE="${REPOSITORY_ROOT}/deployment/frontend-dist"
readonly DEPLOY_MODE="${1:-deploy}"
readonly COMPOSER_BIN="${ACCOUNT_ROOT}/bin/composer"

maintenance_enabled=0

case "${DEPLOY_MODE}" in
    deploy|--check) ;;
    *)
        printf '[ASC Deploy] ERROR: mode tidak dikenal: %s\n' "${DEPLOY_MODE}" >&2
        printf 'Penggunaan: %s [--check]\n' "$0" >&2
        exit 1
        ;;
esac

log() {
    printf '[ASC Deploy] %s\n' "$1"
}

restore_application() {
    if [[ "${maintenance_enabled}" -eq 1 ]]; then
        log "Mengaktifkan kembali aplikasi."
        (cd "${BACKEND_TARGET}" && php artisan up) || true
    fi
}

trap restore_application EXIT

for command_name in php tar find cp rm; do
    if ! command -v "${command_name}" >/dev/null 2>&1; then
        printf '[ASC Deploy] ERROR: perintah %s tidak tersedia.\n' "${command_name}" >&2
        exit 1
    fi
done

if [[ ! -x "${COMPOSER_BIN}" ]]; then
    printf '[ASC Deploy] ERROR: Composer tidak tersedia atau tidak executable: %s\n' "${COMPOSER_BIN}" >&2
    exit 1
fi

clear_target_contents() {
    local target_path="$1"

    case "${target_path}" in
        "${BACKEND_TARGET}"|"${API_PUBLIC_TARGET}"|"${FRONTEND_TARGET}") ;;
        *)
            printf '[ASC Deploy] ERROR: menolak membersihkan target tidak dikenal: %s\n' "${target_path}" >&2
            exit 1
            ;;
    esac

    if [[ ! -d "${target_path}" ]]; then
        printf '[ASC Deploy] ERROR: target tidak ditemukan: %s\n' "${target_path}" >&2
        exit 1
    fi

    case "${target_path}" in
        "${BACKEND_TARGET}")
            find "${target_path}" -mindepth 1 -maxdepth 1 \
                ! -name '.env' \
                ! -name 'storage' \
                ! -name 'vendor' \
                -exec rm -rf -- {} +
            ;;
        "${API_PUBLIC_TARGET}")
            find "${target_path}" -mindepth 1 -maxdepth 1 \
                ! -name 'index.php' \
                ! -name 'storage' \
                -exec rm -rf -- {} +
            ;;
        "${FRONTEND_TARGET}")
            find "${target_path}" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
            ;;
    esac
}

if [[ ! -d "${BACKEND_SOURCE}" || ! -f "${BACKEND_SOURCE}/artisan" ]]; then
    printf '[ASC Deploy] ERROR: source backend tidak lengkap.\n' >&2
    exit 1
fi

if [[ ! -f "${FRONTEND_RELEASE}/index.html" ]]; then
    printf '[ASC Deploy] ERROR: frontend release belum tersedia. Jalankan deployment/prepare-rumahweb-release.ps1, lalu commit hasilnya.\n' >&2
    exit 1
fi

if [[ ! -f "${BACKEND_TARGET}/.env" ]]; then
    printf '[ASC Deploy] ERROR: %s/.env tidak ditemukan; deployment dibatalkan.\n' "${BACKEND_TARGET}" >&2
    exit 1
fi

if [[ ! -d "${BACKEND_TARGET}/storage" ]]; then
    printf '[ASC Deploy] ERROR: storage production tidak ditemukan; deployment dibatalkan.\n' >&2
    exit 1
fi

if [[ ! -f "${API_PUBLIC_TARGET}/index.php" ]]; then
    printf '[ASC Deploy] ERROR: index.php production backend tidak ditemukan; deployment dibatalkan.\n' >&2
    exit 1
fi

for target_path in "${BACKEND_TARGET}" "${API_PUBLIC_TARGET}" "${FRONTEND_TARGET}"; do
    case "${target_path}" in
        "${ACCOUNT_ROOT}"/*) ;;
        *)
            printf '[ASC Deploy] ERROR: target di luar akun hosting: %s\n' "${target_path}" >&2
            exit 1
            ;;
    esac
done

if [[ "${DEPLOY_MODE}" == '--check' ]]; then
    log "Pemeriksaan berhasil; source, release frontend, perintah, dan target production tersedia."
    log "Tidak ada berkas production yang diubah."
    exit 0
fi

log "Mengaktifkan mode pemeliharaan."
(cd "${BACKEND_TARGET}" && php artisan down --retry=30) || true
maintenance_enabled=1

log "Menyinkronkan source Laravel tanpa menimpa .env, storage, dan vendor."
clear_target_contents "${BACKEND_TARGET}"
(
    cd "${BACKEND_SOURCE}"
    tar \
        --exclude='./.env' \
        --exclude='./.env.*' \
        --exclude='./storage' \
        --exclude='./vendor' \
        --exclude='./.git' \
        -cf - .
) | (
    cd "${BACKEND_TARGET}"
    tar -xf -
)

log "Memasang dependency production."
(
    cd "${BACKEND_TARGET}"
    COMPOSER_MEMORY_LIMIT=-1 "${COMPOSER_BIN}" install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-scripts
    php artisan package:discover --ansi
)

log "Menyinkronkan public backend tanpa mengganti index.php production dan storage."
clear_target_contents "${API_PUBLIC_TARGET}"
(
    cd "${BACKEND_SOURCE}/public"
    tar \
        --exclude='./index.php' \
        --exclude='./storage' \
        -cf - .
) | (
    cd "${API_PUBLIC_TARGET}"
    tar -xf -
)

log "Menyinkronkan frontend production."
clear_target_contents "${FRONTEND_TARGET}"
cp -a "${FRONTEND_RELEASE}/." "${FRONTEND_TARGET}/"

log "Membersihkan dan membangun ulang cache Laravel."
(
    cd "${BACKEND_TARGET}"
    chmod -R ug+rwX storage bootstrap/cache
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
    php artisan cache:clear
    php artisan config:cache
    php artisan view:cache
)

log "Deployment selesai. Migration database tidak dijalankan otomatis."
log "Commit: $(git -C "${REPOSITORY_ROOT}" rev-parse --short HEAD 2>/dev/null || printf 'tidak diketahui')"
