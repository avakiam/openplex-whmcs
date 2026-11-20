#!/bin/bash
# Inside the OpenPlex test container: sign in as admin, open the API port to
# the WHMCS network, and write an admin API key to /root/whmcs-api-key.
set -euo pipefail
command -v jq >/dev/null || { apt-get update -qq >/dev/null; apt-get install -y -qq jq >/dev/null; }
API=http://127.0.0.1:7080/api/v1
pw=$(tr -d '\n' </var/lib/openplex/initial-admin-password)
token=$(jq -n --arg p "$pw" '{username:"admin",password:$p}' |
  curl -fsS -H 'Content-Type: application/json' -d @- "$API/auth/login" | jq -r .access_token)
auth=(-H "Authorization: Bearer $token" -H 'Content-Type: application/json')

curl -fsS "${auth[@]}" -d '{"enabled":true,"rules":[
  {"protocol":"tcp","ports":"22","source":"","comment":"SSH"},
  {"protocol":"tcp","ports":"7080","source":"'"$1"'","comment":"WHMCS"}]}' "$API/firewall/apply" >/dev/null
curl -fsS "${auth[@]}" -X POST "$API/firewall/confirm" >/dev/null
echo "firewall: 7080 open to $1"

curl -fsS "${auth[@]}" -d '{"name":"WHMCS test","expires_in_days":30}' "$API/api-keys" |
  jq -r '.key // .api_key // .token' >/root/whmcs-api-key
chmod 600 /root/whmcs-api-key
echo "api key prefix: $(cut -c1-8 /root/whmcs-api-key)"
