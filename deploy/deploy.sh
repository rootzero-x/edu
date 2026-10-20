#!/usr/bin/env bash
# API'ni serverga izolyatsiyalangan katalogga deploy qiladi: ~/apps/tisu-api
#   releases/<vaqt>/  — har bir deploy alohida
#   shared/.env       — sirlar (qo'lda yaratiladi, chmod 600)
#   shared/backups/   — migratsiyadan oldingi baza zaxirasi
#   current -> releases/<oxirgi>
# Boshqa loyihalar, crontab, web-server konfiguratsiyasiga tegmaydi.
# Ishlatish: DEPLOY_HOST=... DEPLOY_USER=... ./deploy/deploy.sh
set -euo pipefail
: "${DEPLOY_HOST:?}"; : "${DEPLOY_USER:?}"
KEY="${DEPLOY_KEY:-$HOME/.ssh/tisu_deploy}"
SSH=(ssh -i "$KEY" "$DEPLOY_USER@$DEPLOY_HOST")
BASE='~/apps/tisu-api'
REL=$(date -u +%Y%m%d%H%M%S)
ROOT=$(cd "$(dirname "$0")/.." && pwd)

echo "→ paketlash"
TMP=$(mktemp -d)
tar -C "$ROOT" --exclude=node_modules --exclude=.next --exclude=.git -czf "$TMP/rel.tgz" \
  package.json pnpm-workspace.yaml pnpm-lock.yaml tsconfig.base.json packages/core apps/api

echo "→ yuklash ($REL)"
"${SSH[@]}" "mkdir -p $BASE/releases/$REL $BASE/shared/backups && test -f $BASE/shared/.env" \
  || { echo "XATO: $BASE/shared/.env yo'q. Avval uni yarating (.env.example ga qarang)."; exit 1; }
scp -i "$KEY" "$TMP/rel.tgz" "$DEPLOY_USER@$DEPLOY_HOST:apps/tisu-api/releases/$REL/rel.tgz"

"${SSH[@]}" "bash -s" <<REMOTE
set -euo pipefail
cd $BASE/releases/$REL
tar -xzf rel.tgz && rm rel.tgz
ln -sf $BASE/shared/.env apps/api/.env
export COREPACK_ENABLE_DOWNLOAD_PROMPT=0
corepack pnpm@9.15.9 install --frozen-lockfile --prod=false --filter @tisu/api...
set -a; . $BASE/shared/.env; set +a
if command -v mysqldump >/dev/null && [ -n "\${DB_NAME:-}" ]; then
  mysqldump --single-transaction --no-tablespaces -u"\$DB_USER" -p"\$DB_PASSWORD" "\$DB_NAME" | gzip > $BASE/shared/backups/pre-$REL.sql.gz
fi
PN="corepack pnpm@9.15.9"
(cd apps/api && \$PN exec prisma generate && \$PN exec prisma migrate deploy)
PREV=\$(readlink $BASE/current || true)
ln -sfn $BASE/releases/$REL $BASE/current
(cd $BASE/current/apps/api && \$PN exec pm2 startOrReload ecosystem.config.cjs --update-env)
sleep 3
if ! curl -fsS "http://127.0.0.1:\${PORT:-3100}/health" >/dev/null; then
  echo "health-check XATO — rollback"; [ -n "\$PREV" ] && ln -sfn "\$PREV" $BASE/current
  (cd $BASE/current/apps/api && \$PN exec pm2 startOrReload ecosystem.config.cjs --update-env); exit 1
fi
ls -1dt $BASE/releases/* | tail -n +6 | xargs -r rm -rf   # oxirgi 5 ta release saqlanadi
echo "OK: $REL"
REMOTE
rm -rf "$TMP"
