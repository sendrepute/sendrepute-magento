#!/bin/sh
# Recheck an already installed, owned disposable Magento host without downloads.
set -eu
ROOT=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
SITE=${1:-}
case "$SITE" in
  /tmp/sendrepute-magento-installed.*/site) ;;
  *) echo "Refusing non-disposable Magento site" >&2; exit 2 ;;
esac
test -f "$SITE/app/etc/env.php" && test -d "$SITE/../db" ||
  { echo "Missing installed host or owned MariaDB data" >&2; exit 2; }
WORK=$(dirname "$SITE")
test "$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')" = 8.3
php -m | grep -qx xsl
test -z "${SENDREPUTE_API_TOKEN:-}" || { echo "Refusing an API token" >&2; exit 2; }
unset MAGENTO_PUBLIC_KEY MAGENTO_PRIVATE_KEY
php -r '$s=@stream_socket_server("tcp://127.0.0.1:34700",$e,$m); if (!$s) exit(2); fclose($s);' ||
  { echo "Refusing occupied 34700" >&2; exit 2; }
mkdir -p "$WORK/mail"
rm -f "$WORK/mail"/message.*
: > "$WORK/classify.jsonl"
export MAGENTO_MAIL_SINK_DIR="$WORK/mail" MAGENTO_CLASSIFY_LOG="$WORK/classify.jsonl"
export MAGENTO_PHP_BIN="$(command -v php)"
mariadbd --no-defaults --datadir="$WORK/db" --socket="$WORK/db.sock" \
  --pid-file="$WORK/db.pid" --skip-networking --skip-name-resolve \
  --log-error="$WORK/db-focused.log" &
DB_PID=$!
WEB_PID=
cleanup() {
  [ -z "$WEB_PID" ] || kill "$WEB_PID" 2>/dev/null || :
  kill "$DB_PID" 2>/dev/null || :
}
trap cleanup EXIT
ready=
for i in $(seq 1 20); do
  if mariadb-admin --protocol=socket --socket="$WORK/db.sock" -uroot ping >/dev/null 2>&1; then
    ready=yes
    break
  fi
  sleep 1
done
test "$ready" = yes || { echo "Owned MariaDB did not start" >&2; exit 2; }
send() {
  php -d memory_limit=1024M -d "pdo_mysql.default_socket=$WORK/db.sock" \
    -d "mysqli.default_socket=$WORK/db.sock" \
    -d "sendmail_path=sh $ROOT/tests/mail-sink.sh" \
    "$ROOT/tests/installed-host.php" "$SITE" "$1"
}
count_mail() { find "$WORK/mail" -name 'message.*' -type f | wc -l; }
count_classify() { wc -l < "$WORK/classify.jsonl"; }
send initial
test "$(count_mail)" -eq 3 && test "$(count_classify)" -eq 2
workers() {
  pids=
  for n in 1 2 3 4; do
    send worker > "$WORK/focused-worker-$1-$n.log" 2>&1 &
    pids="$pids $!"
  done
  failed=
  for pid in $pids; do wait "$pid" || failed=yes; done
  if [ -n "$failed" ]; then
    for n in 1 2 3 4; do cat "$WORK/focused-worker-$1-$n.log" >&2; done
    return 1
  fi
}
workers consent-off
test "$(count_mail)" -eq 7 && test "$(count_classify)" -eq 2
send prepare
if ! workers consent-on; then send finish; exit 2; fi
send finish
test "$(count_mail)" -eq 11 && test "$(count_classify)" -eq 6
php -d memory_limit=1024M -d "pdo_mysql.default_socket=$WORK/db.sock" \
  -d "mysqli.default_socket=$WORK/db.sock" \
  "$ROOT/tests/installed-acl-setup.php" "$SITE"
front=$(php -r '$c=require $argv[1];echo $c["backend"]["frontName"];' "$SITE/app/etc/env.php")
(cd "$SITE" && php -d memory_limit=1024M \
  -d "pdo_mysql.default_socket=$WORK/db.sock" -d "mysqli.default_socket=$WORK/db.sock" \
  -d sendmail_path=/bin/false -S 127.0.0.1:34700 -t pub phpserver/router.php) \
  > "$WORK/http-focused.log" 2>&1 &
WEB_PID=$!
ready=
for i in $(seq 1 18); do
  if curl -s --max-time 2 -o /dev/null http://127.0.0.1:34700/; then
    ready=yes
    break
  fi
  sleep 1
done
test "$ready" = yes
python3 "$ROOT/tests/installed-admin.py" http://127.0.0.1:34700 "$front" \
  task600_admin 'SyntheticLocalTask600!47' "$SITE" full
python3 "$ROOT/tests/installed-admin.py" http://127.0.0.1:34700 "$front" \
  task600_limited 'SyntheticLocalTask600!47' "$SITE" restricted
echo "Focused installed-host suite passed: 11 sink deliveries, 6 synthetic classifications."