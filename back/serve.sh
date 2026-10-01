#!/usr/bin/env bash
# Demarre l'API TicketLab avec les limites PHP necessaires (voir php/ticketlab.ini).
#
# `php artisan serve` delegue a un `php -S` qui NE reprend pas l'option -c du
# parent : le worker relit alors /etc/php/8.2/cli/php.ini (post_max_size = 8M,
# upload_max_filesize = 2M) et tout upload au-dela de 2 Mo est tronque SANS
# reponse exploitable pour le navigateur (net::ERR_FAILED).
#
# On lance donc directement le serveur integre, depuis public/ (c'est le
# document root attendu par le router de Laravel) et avec -c.
#
# Usage : ./serve.sh [port]   (defaut : 8001)
set -euo pipefail

PORT="${1:-8001}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

if ss -ltn 2>/dev/null | grep -q ":${PORT} "; then
  echo "Le port ${PORT} est deja utilise." >&2
  exit 1
fi

# vendor/.../resources/server.php fait `$publicPath = getcwd()` puis
# require_once $publicPath.'/index.php' : le repertoire courant DOIT etre
# public/, comme le fait le chdir() de `artisan serve`.
cd "$ROOT/public"

# 4 workers : le serveur integre de PHP est mono-thread, une generation longue
# bloquerait sinon l'affichage des vignettes de templates.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

exec php -c "$ROOT/php/ticketlab.ini" \
  -S "127.0.0.1:${PORT}" \
  "$ROOT/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"