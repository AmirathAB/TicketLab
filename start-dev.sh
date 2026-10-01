#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACK="$ROOT/back"
FRONT="$ROOT/front"

fail() {
  echo "ERREUR: $*" >&2
  exit 1
}

command -v php >/dev/null 2>&1 || fail "PHP est introuvable. Installez PHP 8.2+ et relancez la commande."
command -v composer >/dev/null 2>&1 || fail "Composer est introuvable. Installez Composer puis relancez la commande."
command -v npm >/dev/null 2>&1 || fail "Node.js/npm est introuvable. Installez Node.js puis relancez la commande."

cd "$BACK"

if [[ ! -d vendor ]]; then
  echo "Installation des dépendances Laravel..."
  composer install --no-interaction
fi

if [[ ! -f .env ]]; then
  echo "Création de back/.env depuis .env.example..."
  cp .env.example .env
fi

if grep -q '^DB_CONNECTION=sqlite' .env && [[ ! -f "$BACK/database/database.sqlite" ]]; then
  touch "$BACK/database/database.sqlite"
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  echo "Génération de la clé Laravel..."
  php artisan key:generate --force
fi

# Rejoue le seeder à chaque démarrage : les presets Parking/Lavage sont ainsi
# présents même si l’archive a été copiée après une première initialisation.
echo "Mise à jour de la base et des templates..."
php artisan migrate --seed --force

php artisan config:clear >/dev/null 2>&1 || true
(
  cd "$BACK"
  ./serve.sh 8001
) &
BACK_PID=$!

cleanup() {
  kill "$BACK_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

if [[ ! -d "$FRONT/node_modules" ]]; then
  echo "Installation des dépendances frontend..."
  (cd "$FRONT" && npm ci)
fi

cd "$FRONT"
echo "TicketLab est disponible sur http://localhost:5173"
npm run dev -- --host 127.0.0.1
