#!/usr/bin/env bash
#
# Jalankan seluruh stack BimbelKu untuk pengembangan lokal:
#   frontend Vite + Laravel API + scheduler.
#
# Perintah ini idempoten: aman dijalankan berkali-kali, termasuk saat database
# Docker belum siap, migration belum lengkap, atau sebagian service sudah aktif.
#
# Pemakaian:
#   npm start
#   ./start.sh
#   bash scripts/run-local.sh --no-docker --no-scheduler
#
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="$ROOT_DIR/bimbelku-backend"
RUN_DIR="$ROOT_DIR/.run"
COMPOSE_FILE="$BACKEND_DIR/docker-compose.yml"

USE_DOCKER=1
USE_SCHEDULER=1
USE_FRONTEND=1

for arg in "$@"; do
  case "$arg" in
    --no-docker) USE_DOCKER=0 ;;
    --no-scheduler) USE_SCHEDULER=0 ;;
    --no-frontend) USE_FRONTEND=0 ;;
    --help|-h)
      awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "${BASH_SOURCE[0]}"
      exit 0
      ;;
    *)
      printf 'Opsi tidak dikenal: %s\n' "$arg" >&2
      exit 2
      ;;
  esac
done

log()  { printf '\n\033[1;36m[run]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[ok]\033[0m  %s\n' "$*"; }
warn() { printf '\033[1;33m[run]\033[0m %s\n' "$*"; }
fail() { printf '\n\033[1;31m[run]\033[0m %s\n' "$*" >&2; exit 1; }

mkdir -p "$RUN_DIR"
cd "$ROOT_DIR"

STARTED_PIDS=()
TAIL_PID=""
CLEANING_UP=0

cleanup() {
  local code=$?
  [ "$CLEANING_UP" = "1" ] && return
  CLEANING_UP=1
  trap - INT TERM EXIT
  if [ -n "$TAIL_PID" ] && kill -0 "$TAIL_PID" 2>/dev/null; then
    kill "$TAIL_PID" 2>/dev/null || true
  fi
  for pid in "${STARTED_PIDS[@]:-}"; do
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
      kill "$pid" 2>/dev/null || true
    fi
  done
  wait 2>/dev/null || true
  if [ "$code" -eq 130 ] || [ "$code" -eq 143 ]; then
    code=0
  fi
  printf '\n\033[1;36m[run]\033[0m Service yang dijalankan perintah ini sudah dihentikan.\n'
  exit "$code"
}
trap cleanup INT TERM EXIT

port_open() {
  # /dev/tcp bisa menggantung lama bila SYN di-drop; batasi 1 detik.
  timeout 1 bash -c "exec 3<>/dev/tcp/127.0.0.1/$1" >/dev/null 2>&1
}

# ---------------------------------------------------------------------------
# 1. Prasyarat
# ---------------------------------------------------------------------------
command -v node >/dev/null 2>&1 || fail "Node.js tidak ditemukan. Install Node.js 20+ dulu."
command -v npm  >/dev/null 2>&1 || fail "npm tidak ditemukan. Install npm 10+ dulu."
command -v composer >/dev/null 2>&1 || fail "Composer tidak ditemukan. Install Composer 2 dulu."

# Proyek ini menyertakan bin/php sebagai wrapper pdo_mysql lokal. Pakai wrapper
# bila ada supaya Laravel tidak gagal dengan error "could not find driver".
if [ -x "$BACKEND_DIR/bin/php" ]; then
  PHP_BIN="$BACKEND_DIR/bin/php"
else
  PHP_BIN="$(command -v php || true)"
  [ -n "$PHP_BIN" ] || fail "PHP tidak ditemukan. Install PHP 8.2+ dulu."
fi

if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^pdo_mysql$'; then
  warn "PHP CLI belum memuat pdo_mysql. Jika migration gagal, aktifkan ekstensi pdo_mysql"
  warn "atau pastikan bimbelku-backend/bin/php tersedia dan executable."
fi

# ---------------------------------------------------------------------------
# 2. File .env dan dependency
# ---------------------------------------------------------------------------
if [ ! -f "$ROOT_DIR/.env" ]; then
  cp "$ROOT_DIR/.env.example" "$ROOT_DIR/.env"
  ok "Membuat .env frontend dari .env.example"
fi

if [ ! -f "$BACKEND_DIR/.env" ]; then
  cp "$BACKEND_DIR/.env.example" "$BACKEND_DIR/.env"
  ok "Membuat .env backend dari .env.example"
fi

if ! grep -qE '^APP_KEY=.+' "$BACKEND_DIR/.env"; then
  log "Membuat APP_KEY backend"
  (cd "$BACKEND_DIR" && "$PHP_BIN" artisan key:generate --force --no-interaction)
fi

if [ ! -d "$ROOT_DIR/node_modules" ]; then
  log "Menginstall dependency frontend"
  if [ -f "$ROOT_DIR/package-lock.json" ]; then
    npm ci
  else
    npm install
  fi
  ok "Dependency frontend siap"
fi

if [ ! -d "$BACKEND_DIR/vendor" ]; then
  log "Menginstall dependency backend"
  (cd "$BACKEND_DIR" && composer install --no-interaction --prefer-dist)
  ok "Dependency backend siap"
fi

# ---------------------------------------------------------------------------
# 3. Database
# ---------------------------------------------------------------------------
if [ "$USE_DOCKER" = "1" ]; then
  if ! command -v docker >/dev/null 2>&1; then
    warn "Docker tidak ditemukan; melewati penyiapan database container."
  elif ! docker info >/dev/null 2>&1; then
    warn "Docker daemon tidak aktif; melewati penyiapan database container."
  elif [ ! -f "$COMPOSE_FILE" ]; then
    warn "docker-compose.yml backend tidak ditemukan; melewati penyiapan database."
  else
    if docker compose version >/dev/null 2>&1; then
      DC=(docker compose)
    elif command -v docker-compose >/dev/null 2>&1; then
      DC=(docker-compose)
    else
      DC=()
    fi

    if [ "${#DC[@]}" -gt 0 ]; then
      log "Menyiapkan database (${DC[*]} -f docker-compose.yml up -d db)"
      "${DC[@]}" -f "$COMPOSE_FILE" up -d db
      ok "Container database aktif"
    else
      warn "docker compose tidak tersedia; melewati penyiapan database container."
    fi
  fi
fi

db_ready() {
  (cd "$BACKEND_DIR" && "$PHP_BIN" artisan migrate:status --no-interaction >/dev/null 2>&1)
}

log "Menunggu database benar-benar siap"
DB_TRIES="${DB_WAIT_TRIES:-45}"
DB_READY=0
for ((i = 1; i <= DB_TRIES; i++)); do
  if db_ready; then
    DB_READY=1
    break
  fi
  sleep 2
done

if [ "$DB_READY" != "1" ]; then
  fail "Database belum bisa diakses setelah $((DB_TRIES * 2)) detik.
Periksa:
  - container: docker compose -f bimbelku-backend/docker-compose.yml ps
  - kredensial DB_* di bimbelku-backend/.env
  - ekstensi pdo_mysql pada PHP CLI (lihat bimbelku-backend/bin/php)"
fi
ok "Database dapat diakses"

# ---------------------------------------------------------------------------
# 4. Migration, seed, dan penyiapan Laravel
# ---------------------------------------------------------------------------
log "Menjalankan migration"
(cd "$BACKEND_DIR" && "$PHP_BIN" artisan migrate --force --no-interaction)
ok "Migration selesai"

log "Memeriksa kelengkapan data awal"
NEEDS_SEED="$(
  cd "$BACKEND_DIR" && "$PHP_BIN" artisan tinker --execute='
    echo (\App\Models\User::query()->exists()
      && \App\Models\CurriculumSubject::query()->exists()
      && \App\Models\PackagePlan::query()->exists()) ? "0" : "1";
  ' 2>/dev/null | tail -n 1 | tr -dc '01' | tail -c 1
)"

if [ "$NEEDS_SEED" = "1" ]; then
  log "Database baru atau data awal belum lengkap; menjalankan seeder"
  (cd "$BACKEND_DIR" && "$PHP_BIN" artisan db:seed --force --no-interaction)
  ok "Data awal selesai dimuat"
else
  ok "Data awal sudah lengkap; seeder dilewati"
fi

if [ ! -e "$BACKEND_DIR/public/storage" ]; then
  (cd "$BACKEND_DIR" && "$PHP_BIN" artisan storage:link --no-interaction >/dev/null 2>&1) \
    && ok "Storage link dibuat" \
    || warn "Storage link gagal dibuat; foto lama mungkin tidak tampil."
fi

(cd "$BACKEND_DIR" && "$PHP_BIN" artisan optimize:clear --no-interaction >/dev/null 2>&1) || \
  warn "optimize:clear gagal; lanjut dengan konfigurasi yang ada."

# ---------------------------------------------------------------------------
# 5. Jalankan service
# ---------------------------------------------------------------------------
STARTED_LABELS=()

start_service() {
  local label="$1" logfile="$2" workdir="$3"
  shift 3
  : > "$logfile"
  (cd "$workdir" && exec "$@") > "$logfile" 2>&1 &
  local pid=$!
  STARTED_PIDS+=("$pid")
  STARTED_LABELS+=("$label")
  ok "$label dimulai (PID $pid)"
}

if port_open 8000; then
  ok "Backend sudah aktif di port 8000; memakai proses yang ada"
else
  start_service "backend" "$RUN_DIR/backend.log" "$BACKEND_DIR" \
    "$PHP_BIN" artisan serve --host=127.0.0.1 --port=8000
fi

if [ "$USE_SCHEDULER" = "1" ]; then
  if pgrep -f "artisan schedule:work" >/dev/null 2>&1; then
    ok "Scheduler sudah aktif; tidak menjalankan proses kedua"
  else
    start_service "scheduler" "$RUN_DIR/scheduler.log" "$BACKEND_DIR" \
      "$PHP_BIN" artisan schedule:work
  fi
fi

if [ "$USE_FRONTEND" = "1" ]; then
  if port_open 8080; then
    ok "Frontend sudah aktif di port 8080; memakai proses yang ada"
  else
    VITE_BIN="$ROOT_DIR/node_modules/.bin/vite"
    [ -x "$VITE_BIN" ] || fail "Vite tidak ditemukan di node_modules. Jalankan npm install lalu coba lagi."
    start_service "frontend" "$RUN_DIR/frontend.log" "$ROOT_DIR" \
      "$VITE_BIN" --host 127.0.0.1 --port 8080
  fi
fi

printf '\n\033[1;32mBimbelKu siap dipakai:\033[0m\n'
printf '  Frontend : http://127.0.0.1:8080\n'
printf '  Backend  : http://127.0.0.1:8000\n'
printf '  Log      : %s/{backend,frontend,scheduler}.log\n' "$RUN_DIR"
printf '\nTekan Ctrl+C untuk menghentikan service yang dijalankan perintah ini.\n\n'

# Tampilkan log service yang baru dijalankan. Service yang sudah aktif
# sebelumnya tidak disentuh.
if [ "${#STARTED_PIDS[@]}" -gt 0 ]; then
  LOG_FILES=()
  for label in "${STARTED_LABELS[@]}"; do
    LOG_FILES+=("$RUN_DIR/$label.log")
  done
  tail -n +1 -F "${LOG_FILES[@]}" &
  TAIL_PID=$!
  wait "$TAIL_PID" 2>/dev/null || true
else
  # Semua service sudah berjalan; tetap tunggu sampai Ctrl+C.
  while true; do sleep 3600; done
fi
