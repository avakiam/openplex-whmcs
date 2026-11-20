#!/bin/bash
# Inside the WHMCS test container: non-interactive install. The admin
# password is generated here and kept in /root/whmcs-admin-password.
set -euo pipefail
cd /var/www/html
license=$1
pw=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 20)A1
printf '%s\n' "$pw" >/root/whmcs-admin-password
chmod 600 /root/whmcs-admin-password
hash=$(openssl rand -hex 32)
cat >/root/whmcs-install.json <<EOF
{
  "admin": {"username": "admin", "password": "$pw"},
  "configuration": {
    "license": "$license",
    "db_host": "db",
    "db_username": "whmcs",
    "db_password": "whmcs-test-db-pw1",
    "db_name": "whmcs",
    "cc_encryption_hash": "$hash",
    "mysql_charset": "utf8"
  }
}
EOF
chmod 600 /root/whmcs-install.json
rm -f configuration.php
# The installer wants the JSON on a single line.
tr -d '\n' </root/whmcs-install.json | php -f install/bin/installer.php -- -i -n -c 2>&1 | grep -v -i password
rm -f /root/whmcs-install.json
chown -R www-data:www-data /var/www/html
