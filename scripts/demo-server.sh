#!/usr/bin/env bash
# A local DEMO copy of the ERP, for taking "What's new" pictures.
#
# Builds database/demo.sqlite (git-ignored, made-up data only: the seeded
# admin and whatever the seeders create) and serves it on http://127.0.0.1:8765.
# It never touches the live site or any real data.
#
#   scripts/demo-server.sh          start (builds the database the first time)
#   scripts/demo-server.sh fresh    wipe and rebuild the demo database, then start
#
# Sign-in: admin@example.com / password (arabic@example.com for the Arabic
# screen). Stop with Ctrl+C.
set -euo pipefail
cd "$(dirname "$0")/.."

export DB_CONNECTION=sqlite
export DB_DATABASE="$PWD/database/demo.sqlite"
export APP_URL="http://127.0.0.1:8765"
export APP_ENV=local
export MAIL_MAILER=log
PORT=8765
MODULES="contacts pos inventory accounting purchases rental limousine project"

if [[ "${1:-}" == "fresh" ]]; then rm -f "$DB_DATABASE"; fi

if [[ ! -f "$DB_DATABASE" ]]; then
  echo "Building the demo database…"
  touch "$DB_DATABASE"
  php artisan migrate:fresh --seed --force --no-interaction
  for m in $MODULES; do php artisan module:install "$m" --no-interaction || true; done
  for s in PartnerSeeder PosSeeder DemoFleetSeeder; do
    php artisan db:seed --class="Database\\Seeders\\$s" --force --no-interaction || true
  done
  # The demo admin is the owner, so owner-only pages can be photographed.
  php artisan tinker --execute='\App\Models\User::query()->where("email", "admin@example.com")->first()?->forceFill(["is_super_admin" => true])->save();' >/dev/null
  # A second demo admin who reads Arabic, for right-to-left pictures (--ar).
  php artisan tinker --execute='\App\Models\User::query()->updateOrCreate(["email" => "arabic@example.com"], ["name" => "مدير تجريبي", "password" => bcrypt("password"), "is_admin" => true, "language" => "ar"])->forceFill(["is_admin" => true, "is_super_admin" => true])->save();' >/dev/null
fi

[[ -f public/build/manifest.json ]] || npm run build
php artisan optimize:clear >/dev/null
echo "Demo copy on http://127.0.0.1:$PORT  (admin@example.com / password)"
# PHP's own server, not `artisan serve`: that command hands its worker only a
# fixed list of environment variables, so the demo database path would be lost
# and the worker would fall back to .env.
cd public  # the router script serves from the current folder
exec php -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
