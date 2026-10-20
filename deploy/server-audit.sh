#!/usr/bin/env bash
# FAQAT O'QISH. Serverda hech narsani o'zgartirmaydi.
# Ishlatish: DEPLOY_HOST=<server> DEPLOY_USER=<login> ./deploy/server-audit.sh
set -euo pipefail
: "${DEPLOY_HOST:?DEPLOY_HOST kerak}"; : "${DEPLOY_USER:?DEPLOY_USER kerak}"
KEY="${DEPLOY_KEY:-$HOME/.ssh/tisu_deploy}"
ssh -i "$KEY" -o StrictHostKeyChecking=accept-new "$DEPLOY_USER@$DEPLOY_HOST" 'bash -s' <<'REMOTE'
echo "== user ==";        whoami; id; echo "$HOME"
echo "== os ==";          uname -a; cat /etc/os-release 2>/dev/null | head -3
echo "== disk ==";        df -h "$HOME" | tail -1; du -sh "$HOME" 2>/dev/null
echo "== home ==";        ls -la "$HOME"
echo "== node ==";        command -v node && node -v || echo "node yo'q"; ls -d /opt/alt/alt-nodejs* 2>/dev/null; ls "$HOME/nodevenv" 2>/dev/null
echo "== tools ==";       for c in git npm pnpm pm2 mysql mysqldump psql pg_dump cloudlinux-selector; do printf "%s: " $c; command -v $c || echo "-"; done
echo "== processes ==";   ps -u "$(whoami)" -o pid,etime,cmd --no-headers | head -40
echo "== ports ==";       (ss -ltnp 2>/dev/null || netstat -ltnp 2>/dev/null) | head -40
echo "== crontab ==";     crontab -l 2>/dev/null || echo "(bo'sh)"
echo "== domains ==";     ls "$HOME"/public_html "$HOME"/domains 2>/dev/null | head -40
REMOTE
