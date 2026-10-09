#!/bin/sh
# Disposable Magento Open Source host experiment; never accepts an existing installation.
set -eu
ROOT=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
VERSION=2.4.7-p6
COMMIT=b69acb3d4cb3720665c7829fd6390d3036a07b13
WORK=${MAGENTO_INSTALLED_WORK_ROOT:-}
EVIDENCE=${MAGENTO_INSTALLED_EVIDENCE:-"$ROOT/tests/installed-result.json"}
if [ -n "$WORK" ] && { [ -e "$WORK" ] || [ -L "$WORK" ]; }; then
  echo "Refusing existing MAGENTO_INSTALLED_WORK_ROOT" >&2
  exit 2
fi
if [ -z "$WORK" ]; then
  # nix-shell's TMPDIR is deleted when the shell exits; retain failure evidence.
  WORK=$(mktemp -d "/tmp/sendrepute-magento-installed.XXXXXX")
else
  mkdir -m 700 "$WORK"
fi
chmod 700 "$WORK"
STATUS=blocked
REASON=preflight
DB_PID=
WEB_PID=
SEARCH_PID=
record() {
  source_sha=
  lock_sha=
  deliveries=
  classifications=
  if [ -f "$WORK/source.zip" ]; then source_sha=$(sha256sum "$WORK/source.zip" | cut -d' ' -f1); fi
  if [ -f "$WORK/site/composer.lock" ]; then lock_sha=$(sha256sum "$WORK/site/composer.lock" | cut -d' ' -f1); fi
  if [ -d "$WORK/mail" ]; then deliveries=$(find "$WORK/mail" -type f -name 'message.*' | wc -l); fi
  if [ -f "$WORK/classify.jsonl" ]; then classifications=$(wc -l < "$WORK/classify.jsonl"); fi
  MAGENTO_RESULT_STATUS="$STATUS" MAGENTO_RESULT_REASON="$REASON" \
    MAGENTO_RESULT_WORK="$WORK" MAGENTO_RESULT_VERSION="$VERSION" \
    MAGENTO_RESULT_COMMIT="$COMMIT" MAGENTO_RESULT_PHP="$(php -r 'echo PHP_VERSION;')" \
    MAGENTO_RESULT_COMPOSER="$(composer --version --no-ansi 2>/dev/null || :)" \
    MAGENTO_RESULT_SOURCE_SHA="$source_sha" MAGENTO_RESULT_LOCK_SHA="$lock_sha" \
    MAGENTO_RESULT_MAILS="$deliveries" MAGENTO_RESULT_CLASSIFICATIONS="$classifications" \
    php -r '$ok = getenv("MAGENTO_RESULT_STATUS") === "passed"; $out = ["status" => getenv("MAGENTO_RESULT_STATUS"), "reason" => getenv("MAGENTO_RESULT_REASON"), "magento_tag" => getenv("MAGENTO_RESULT_VERSION"), "source_commit" => getenv("MAGENTO_RESULT_COMMIT"), "source_zip_sha256" => getenv("MAGENTO_RESULT_SOURCE_SHA") ?: null, "composer_lock_sha256" => getenv("MAGENTO_RESULT_LOCK_SHA") ?: null, "php" => getenv("MAGENTO_RESULT_PHP"), "composer" => getenv("MAGENTO_RESULT_COMPOSER"), "mariadb" => "10.11.13, owned socket, networking disabled", "search" => "Elasticsearch 7.17.27, disposable loopback 34705/34706", "ports" => [34700,34705,34706], "work_root" => getenv("MAGENTO_RESULT_WORK"), "full_install" => $ok, "coverage" => $ok ? ["native_admin_guest_redirect" => "passed", "native_admin_authenticated_config" => "passed", "native_admin_missing_form_key" => "rejected", "native_admin_restricted_role_acl" => "denied", "native_admin_stored_xss" => "escaped", "final_transport_synthetic_high_risk" => "blocked before delivery", "final_transport_synthetic_failure" => "blocked before delivery", "final_transport_allowed_and_unselected" => "delivered to owned sink", "consent_off" => "delivered without classification", "four_simultaneous_same_content_opted_in" => "four independent classifications and four sink deliveries; no adapter nonce/retry implementation", "nonce_and_retry" => "not applicable: adapter contains neither"] : null, "sink_deliveries" => getenv("MAGENTO_RESULT_MAILS") === "" ? null : (int)getenv("MAGENTO_RESULT_MAILS"), "synthetic_classifications" => getenv("MAGENTO_RESULT_CLASSIFICATIONS") === "" ? null : (int)getenv("MAGENTO_RESULT_CLASSIFICATIONS"), "external_mail_or_billing" => "none: owned sendmail sink; synthetic API client; real API token unset"]; file_put_contents($argv[1], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");' "$EVIDENCE"
}
cleanup() {
  [ -z "$WEB_PID" ] || kill "$WEB_PID" 2>/dev/null || :
  [ -z "$DB_PID" ] || kill "$DB_PID" 2>/dev/null || :
  [ -z "$SEARCH_PID" ] || kill "$SEARCH_PID" 2>/dev/null || :
  record
}
trap cleanup EXIT
export COMPOSER_HOME="$WORK/composer-home" COMPOSER_AUTH='{}'
mkdir -m 700 "$COMPOSER_HOME"
unset SENDREPUTE_API_TOKEN MAGENTO_PUBLIC_KEY MAGENTO_PRIVATE_KEY || :

# Magento's own pinned composer.json excludes 8.4; do not bypass the platform check.
if ! php -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'; then
  REASON="Magento $VERSION requires PHP 8.1-8.3; this host has $(php -r 'echo PHP_VERSION;'). No platform-requirement override is safe."
  echo "$REASON" >&2
  exit 2
fi
if ! command -v composer >/dev/null || ! command -v mariadbd >/dev/null || ! command -v mariadb-install-db >/dev/null; then
  REASON="Composer or isolated MariaDB tools unavailable"
  echo "$REASON" >&2
  exit 2
fi
if ! command -v elasticsearch >/dev/null; then
  REASON="Elasticsearch 7 unavailable; invoke using nix-shell -p php83 php83Packages.composer php83Extensions.xsl elasticsearch7 --run 'sh tests/run-installed.sh'."
  echo "$REASON" >&2
  exit 2
fi
if ! php -m | grep -qx xsl; then
  for package in ${buildInputs:-}; do
    case "$package" in
      *php-xsl*-dev)
        xsl=$(tr ' ' '\n' < "$package/nix-support/propagated-build-inputs" | grep -- '-php-xsl-' | head -1)
        mkdir -p "$WORK/php-ini"
        printf 'extension=%s/lib/php/extensions/xsl.so\n' "$xsl" > "$WORK/php-ini/xsl.ini"
        PHP_INI_SCAN_DIR="$(php --ini | sed -n 's/^Scan for additional .ini files in: *//p' | head -1):$WORK/php-ini"
        export PHP_INI_SCAN_DIR
        break
        ;;
    esac
  done
  if ! php -m | grep -qx xsl; then
    REASON="PHP 8.3 ext-xsl unavailable; enable php83Extensions.xsl in an isolated PHP_INI_SCAN_DIR."
    echo "$REASON" >&2
    exit 2
  fi
fi
for port in 34700 34705 34706; do
  php -r '$s=@stream_socket_server("tcp://127.0.0.1:".$argv[1],$e,$m); if (!$s) exit(2); fclose($s);' "$port" ||
    { REASON="Port $port already occupied"; echo "$REASON" >&2; exit 2; }
done
REASON="public source download or Composer install failed"
curl -fLSs --connect-timeout 8 --max-time 120 \
  "https://codeload.github.com/magento/magento2/zip/$COMMIT" -o "$WORK/source.zip"
unzip -q "$WORK/source.zip" -d "$WORK"
mv "$WORK"/magento2-* "$WORK/site"
(cd "$WORK/site" && composer install --no-dev --no-interaction --no-progress --prefer-dist) > "$WORK/composer.log" 2>&1 ||
  { tail -15 "$WORK/composer.log" >&2; exit 2; }
mkdir -p "$WORK/site/app/code/SendRepute"
cp -R "$ROOT" "$WORK/site/app/code/SendRepute/MailAdapter"
REASON="isolated search or database startup failed"
ES_HOME=$(dirname "$(dirname "$(command -v elasticsearch)")")
mkdir -p "$WORK/search/config" "$WORK/search/data" "$WORK/search/logs"
cp -R "$ES_HOME/config/." "$WORK/search/config/"
chmod -R u+w "$WORK/search/config"
sed -i "s@logs/gc.log@$WORK/search/logs/gc.log@g" "$WORK/search/config/jvm.options"
printf 'cluster.name: task600\nnode.name: disposable\npath.data: %s/search/data\npath.logs: %s/search/logs\nnetwork.host: 127.0.0.1\nhttp.port: 34705\ntransport.port: 34706\ndiscovery.type: single-node\nxpack.security.enabled: false\n' "$WORK" "$WORK" > "$WORK/search/config/elasticsearch.yml"
ES_HOME="$ES_HOME" ES_PATH_CONF="$WORK/search/config" ES_JAVA_OPTS='-Xms512m -Xmx512m' \
  elasticsearch > "$WORK/search.log" 2>&1 &
SEARCH_PID=$!
mariadb-install-db --no-defaults --datadir="$WORK/db" --auth-root-authentication-method=normal > "$WORK/db-init.log" 2>&1
mariadbd --no-defaults --datadir="$WORK/db" --socket="$WORK/db.sock" --pid-file="$WORK/db.pid" \
  --skip-networking --skip-name-resolve --log-error="$WORK/db-error.log" &
DB_PID=$!
ready=
for i in $(seq 1 35); do
  if mariadb-admin --protocol=socket --socket="$WORK/db.sock" -uroot ping >/dev/null 2>&1 &&
    curl -fsS --max-time 2 http://127.0.0.1:34705/ -o /dev/null 2>/dev/null; then
    ready=yes
    break
  fi
  sleep 1
done
if [ "$ready" != yes ]; then echo "$REASON" >&2; exit 2; fi
mariadb --protocol=socket --socket="$WORK/db.sock" -uroot \
  -e 'CREATE DATABASE magento_task600 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
REASON="Magento setup:install did not finish"
cd "$WORK/site"
timeout 200 php -d memory_limit=1024M -d "pdo_mysql.default_socket=$WORK/db.sock" \
  -d "mysqli.default_socket=$WORK/db.sock" -d sendmail_path=/bin/false bin/magento setup:install \
  --base-url=http://127.0.0.1:34700/ --db-host=localhost --db-name=magento_task600 \
  --db-user=root --admin-firstname=Local --admin-lastname=Tester --admin-email=local@example.invalid \
  --admin-user=task600_admin --admin-password='SyntheticLocalTask600!47' --language=en_US \
  --currency=USD --timezone=UTC --use-rewrites=0 --search-engine=elasticsearch7 \
  --elasticsearch-host=127.0.0.1 --elasticsearch-port=34705 --cleanup-database > "$WORK/setup.log" 2>&1 ||
  { tail -50 "$WORK/setup.log" >&2; exit 2; }
REASON="Installed final-hook mail check failed"
mkdir -m 700 "$WORK/mail"
export MAGENTO_MAIL_SINK_DIR="$WORK/mail" MAGENTO_CLASSIFY_LOG="$WORK/classify.jsonl"
: > "$MAGENTO_CLASSIFY_LOG"
export MAGENTO_PHP_BIN="$(command -v php)"
MAIL_SINK="sh $ROOT/tests/mail-sink.sh"
php -d memory_limit=1024M -d "pdo_mysql.default_socket=$WORK/db.sock" \
  -d "mysqli.default_socket=$WORK/db.sock" -d "sendmail_path=$MAIL_SINK" \
  "$ROOT/tests/installed-host.php" "$WORK/site" initial > "$WORK/host.log" 2>&1 ||
  { cat "$WORK/host.log" >&2; exit 2; }
REASON="Concurrent consent and same-content checks failed"
run_host() {
  php -d memory_limit=1024M -d "pdo_mysql.default_socket=$WORK/db.sock" \
    -d "mysqli.default_socket=$WORK/db.sock" -d "sendmail_path=$MAIL_SINK" \
    "$ROOT/tests/installed-host.php" "$WORK/site" "$1"
}
mail_count() { find "$WORK/mail" -name 'message.*' -type f | wc -l; }
classify_count() { wc -l < "$MAGENTO_CLASSIFY_LOG"; }
test "$(mail_count)" -eq 3 && test "$(classify_count)" -eq 2 ||
  { echo "$REASON: initial counts" >&2; exit 2; }
run_workers() {
  pids=
  for n in 1 2 3 4; do
    run_host worker > "$WORK/worker-$1-$n.log" 2>&1 &
    pids="$pids $!"
  done
  failed=
  for pid in $pids; do wait "$pid" || failed=yes; done
  if [ -n "$failed" ]; then
    for n in 1 2 3 4; do cat "$WORK/worker-$1-$n.log" >&2; done
    return 1
  fi
}
run_workers consent-off
test "$(mail_count)" -eq 7 && test "$(classify_count)" -eq 2 ||
  { echo "$REASON: consent-off counts" >&2; exit 2; }
run_host prepare > "$WORK/prepare.log"
if ! run_workers consent-on; then run_host finish >/dev/null; exit 2; fi
run_host finish > "$WORK/finish.log"
test "$(mail_count)" -eq 11 && test "$(classify_count)" -eq 6 ||
  { echo "$REASON: consent-on counts" >&2; exit 2; }
REASON="Native admin restricted-role setup failed"
php -d memory_limit=1024M -d "pdo_mysql.default_socket=$WORK/db.sock" \
  -d "mysqli.default_socket=$WORK/db.sock" \
  "$ROOT/tests/installed-acl-setup.php" "$WORK/site" > "$WORK/acl-setup.log" 2>&1 ||
  { cat "$WORK/acl-setup.log" >&2; exit 2; }
REASON="Native admin HTTP security check failed"
php -d memory_limit=1024M -d "pdo_mysql.default_socket=$WORK/db.sock" \
  -d "mysqli.default_socket=$WORK/db.sock" -d sendmail_path=/bin/false \
  -S 127.0.0.1:34700 -t pub phpserver/router.php > "$WORK/http.log" 2>&1 &
WEB_PID=$!
for i in $(seq 1 15); do curl -s --max-time 1 -o /dev/null http://127.0.0.1:34700/ && break; sleep 1; done
front=$(php -r '$c=require $argv[1];echo $c["backend"]["frontName"];' "$WORK/site/app/etc/env.php")
python3 "$ROOT/tests/installed-admin.py" http://127.0.0.1:34700 \
  "$front" \
  task600_admin 'SyntheticLocalTask600!47' "$WORK/site" full > "$WORK/admin.log" 2>&1 ||
  { cat "$WORK/admin.log" >&2; exit 2; }
python3 "$ROOT/tests/installed-admin.py" http://127.0.0.1:34700 \
  "$front" \
  task600_limited 'SyntheticLocalTask600!47' "$WORK/site" restricted > "$WORK/acl-http.log" 2>&1 ||
  { cat "$WORK/acl-http.log" >&2; exit 2; }
STATUS=passed
REASON="Installed Magento: native ACL/CSRF/XSS, high-risk cancellation, final transport, local sink, consent-off and four-way same-content concurrency passed; no adapter nonce/retry feature."
echo "$REASON"