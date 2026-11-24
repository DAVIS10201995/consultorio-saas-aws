#!/bin/sh
# Lo ejecuta el contenedor de PostgreSQL solo la primera vez (volumen vacío).
set -e
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
  -v mig_pw="$MIGRADOR_PW" -v app_pw="$APP_PW" -f /db/roles.sql
