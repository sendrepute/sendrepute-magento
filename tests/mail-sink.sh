#!/bin/sh
set -eu
# PHP mail() passes its complete message to stdin; do not delegate to sendmail.
case "${MAGENTO_MAIL_SINK_DIR:-}" in
  /tmp/sendrepute-magento-installed.*/*) ;;
  *) echo "Refusing an unowned mail sink" >&2; exit 2 ;;
esac
test -d "$MAGENTO_MAIL_SINK_DIR"
file=$(mktemp "$MAGENTO_MAIL_SINK_DIR/message.XXXXXXXX")
cat > "$file"