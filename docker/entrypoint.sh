#!/usr/bin/env sh
set -eu
# Chờ MySQL sẵn sàng, dựng schema, nạp dữ liệu mẫu (nếu CSDL còn trống) rồi chạy web.
i=0
until php bin/stockflow check >/dev/null 2>&1; do
  i=$((i + 1))
  [ "$i" -gt 60 ] && { echo "MySQL không sẵn sàng" >&2; exit 1; }
  sleep 1
done
php bin/stockflow migrate
[ "${SEED_DEMO:-1}" = "1" ] && php bin/stockflow seed
mkdir -p .local/sessions
exec php -d session.save_path=/app/.local/sessions -S 0.0.0.0:8080 -t public public/index.php
