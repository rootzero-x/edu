#!/usr/bin/env bash
# Laravel backend'ni myxvest shared hostingiga deploy qiladi.
# Faqat $REMOTE_DIR va ~/tisu-backups ga yozadi. Boshqa saytlar, bazalar, crontab'ga tegmaydi.
#
#   ./deploy/deploy-backend.sh            # deploy
#   ./deploy/deploy-backend.sh --check    # faqat o'qish: server holatini ko'rsatadi
set -euo pipefail

HOST="${DEPLOY_HOST:-176.9.111.172}"
USER_="${DEPLOY_USER:-694fc8f1e180d}"
KEY="${DEPLOY_KEY:-$HOME/.ssh/tisu_deploy}"
DOMAIN="${DEPLOY_DOMAIN:-api.tisu.uzstudents.uz}"
REMOTE_DIR="${REMOTE_DIR:-www/$DOMAIN}"  # uy katalogiga nisbatan
PHP_BIN="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"

# Bitta umumiy ulanish: hosting ketma-ket ko'p SSH ulanishlarni uzib qo'yadi.
SSH=(ssh -i "$KEY" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20 -o ServerAliveInterval=15
  -o ControlMaster=auto -o "ControlPath=$HOME/.ssh/cm-tisu-%C" -o ControlPersist=120 "$USER_@$HOST")
ROOT=$(cd "$(dirname "$0")/.." && pwd)
SRC="$ROOT/apps/backend"

if [[ "${1:-}" == "--check" ]]; then
  "${SSH[@]}" "bash -s" <<REMOTE
echo "== whoami: \$(whoami)  home: \$HOME"
echo "== php:"; ls -d /opt/alt/php8*/usr/bin/php 2>/dev/null; $PHP_BIN -v 2>/dev/null | head -1 || echo "$PHP_BIN yo'q"
$PHP_BIN -m 2>/dev/null | grep -iE 'mbstring|pdo_mysql|dom|openssl|fileinfo|tokenizer' | tr '\n' ' '; echo
echo "== tools:"; for c in rsync mysqldump composer git; do printf "%s: " \$c; command -v \$c || echo "-"; done
echo "== /www:"; ls -la /www 2>/dev/null
echo "== $REMOTE_DIR:"; ls -la $REMOTE_DIR 2>/dev/null || echo "(yo'q)"
REMOTE
  exit 0
fi

BUILD=$(mktemp -d)
trap 'rm -rf "$BUILD" "$BUILD.tgz"' EXIT
echo "→ build (composer --no-dev)"
tar -C "$SRC" --exclude=./vendor --exclude=./.env --exclude='./storage/logs/*' --exclude='./storage/framework/*/*' \
  --exclude='./bootstrap/cache/*.php' --exclude=./tests -cf - . | tar -C "$BUILD" -xf -
php "${COMPOSER_PHAR:-$HOME/.local/share/composer-bin/composer.phar}" install -d "$BUILD" \
  --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-req='ext-*' -q
tar -C "$BUILD" -czf "$BUILD.tgz" .

REL=$(date -u +%Y%m%d%H%M%S)
# Umumiy ulanishni ochish: hosting (CloudLinux LVE, jarayonlar limiti) ba'zan ulanishni darhol yopadi — qayta urinamiz.
for i in 1 2 3 4 5 6; do
  "${SSH[@]}" true 2>/dev/null && break
  [ "$i" = 6 ] && { echo "XATO: serverga SSH ulanib bo'lmadi."; exit 1; }
  echo "  SSH ulanmadi, ${i}-qayta urinish 20s dan keyin…"; sleep 20
done
echo "→ upload $REL"
"${SSH[@]}" "test -d $REMOTE_DIR && test -f $REMOTE_DIR/.env" \
  || { echo "XATO: $REMOTE_DIR yoki undagi .env yo'q (docs/DEPLOY.md)."; exit 1; }
"${SSH[@]}" "grep -qE '^DB_PASSWORD=.+' $REMOTE_DIR/.env" \
  || { echo "XATO: $REMOTE_DIR/.env da DB_PASSWORD bo'sh. Avval uni to'ldiring."; exit 1; }
"${SSH[@]}" "cat > ~/tisu-release-$REL.tgz" < "$BUILD.tgz"

"${SSH[@]}" "bash -s" <<REMOTE
set -euo pipefail
cd $REMOTE_DIR
PHP=$PHP_BIN
# Zaxiralar maxfiy (700/600). umask faqat shu katalog uchun — kod fayllariga ta'sir qilmasin.
mkdir -p ~/tisu-backups && chmod 700 ~/tisu-backups
# 1) Zaxira: kod va baza
(umask 077; tar --exclude=./storage/logs -czf ~/tisu-backups/code-$REL.tgz . 2>/dev/null) || true
DB_HOST=\$(grep -E '^DB_HOST=' .env | cut -d= -f2-); DB_DATABASE=\$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)
DB_USERNAME=\$(grep -E '^DB_USERNAME=' .env | cut -d= -f2-); DB_PASSWORD=\$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)
case "\$DB_PASSWORD" in \'*\') DB_PASSWORD=\${DB_PASSWORD:1:-1};; \"*\") DB_PASSWORD=\${DB_PASSWORD:1:-1};; esac
if command -v mysqldump >/dev/null; then
  MYSQL_PWD="\$DB_PASSWORD" mysqldump --single-transaction --no-tablespaces -h"\${DB_HOST:-localhost}" -u"\$DB_USERNAME" "\$DB_DATABASE" 2>/dev/null \
    | (umask 077; gzip > ~/tisu-backups/db-$REL.sql.gz) || echo "ogohlantirish: mysqldump muvaffaqiyatsiz"
fi
# 2) Yangi kod (.env va storage saqlanadi)
[ -f artisan ] && \$PHP artisan down --retry=30 >/dev/null 2>&1 || true
rm -rf app bootstrap/cache/*.php config database public resources routes vendor
tar -xzf ~/tisu-release-$REL.tgz && rm ~/tisu-release-$REL.tgz
mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
# Veb-server o'qishi uchun: kataloglar 755, fayllar 644; .env faqat egasi uchun.
chmod 755 .
find . -path ./storage -prune -o -type d -exec chmod 755 {} +
find . -path ./storage -prune -o -type f -exec chmod 644 {} +
chmod 755 artisan; chmod 600 .env
chmod -R u+rwX,go+rX storage bootstrap/cache
# 3) Laravel
\$PHP artisan package:discover >/dev/null
\$PHP artisan migrate --force --no-ansi
\$PHP artisan config:cache >/dev/null && \$PHP artisan route:cache >/dev/null
\$PHP artisan up >/dev/null
ls -1t ~/tisu-backups/code-*.tgz 2>/dev/null | tail -n +6 | xargs -r rm -f
echo "OK $REL"
REMOTE

echo "→ health"
curl -fsS "https://$DOMAIN/health" && echo
