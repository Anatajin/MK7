#!/usr/bin/env bash
set -euo pipefail

BLOCKED_JSON="/var/www/html/.monitoring/blocked_ips.json"
NFT_DIR="/etc/nftables.d"
NFT_FILE="${NFT_DIR}/sport-blocked.nft"
MAIN_CONF="/etc/nftables.conf"

mkdir -p "${NFT_DIR}"

if [ ! -f "${BLOCKED_JSON}" ]; then
  printf '[]\n' > "${BLOCKED_JSON}"
fi

TMP_FILE="$(mktemp)"

python3 - "${BLOCKED_JSON}" "${TMP_FILE}" <<'PY'
import ipaddress
import json
import sys

source_path, output_path = sys.argv[1], sys.argv[2]

try:
    with open(source_path, 'r', encoding='utf-8') as handle:
        rows = json.load(handle)
except Exception:
    rows = []

ipv4 = []
ipv6 = []

for item in rows if isinstance(rows, list) else []:
    if not isinstance(item, dict):
        continue
    ip = str(item.get('ip', '')).strip()
    if not ip:
        continue

    try:
        parsed = ipaddress.ip_address(ip)
    except ValueError:
        continue

    if parsed.version == 4:
        ipv4.append(ip)
    else:
        ipv6.append(ip)

ipv4 = sorted(set(ipv4), key=lambda value: tuple(int(part) for part in value.split('.')))
ipv6 = sorted(set(ipv6))

with open(output_path, 'w', encoding='utf-8') as handle:
    handle.write('# Managed by SPORT monitor firewall sync\n')
    handle.write(f'# Source: {source_path}\n')
    handle.write('table inet sport_monitor {\n')
    handle.write('    set blocked_ipv4 {\n')
    handle.write('        type ipv4_addr\n')
    if ipv4:
        handle.write('        elements = { ' + ', '.join(ipv4) + ' }\n')
    handle.write('    }\n')
    handle.write('\n')
    handle.write('    set blocked_ipv6 {\n')
    handle.write('        type ipv6_addr\n')
    if ipv6:
        handle.write('        elements = { ' + ', '.join(ipv6) + ' }\n')
    handle.write('    }\n')
    handle.write('\n')
    handle.write('    chain input {\n')
    handle.write('        type filter hook input priority -150; policy accept;\n')
    if ipv4:
        handle.write('        ip saddr @blocked_ipv4 drop\n')
    if ipv6:
        handle.write('        ip6 saddr @blocked_ipv6 drop\n')
    handle.write('    }\n')
    handle.write('}\n')
PY

chmod 0644 "${TMP_FILE}"
mv "${TMP_FILE}" "${NFT_FILE}"

if [ ! -f "${MAIN_CONF}" ]; then
  cat > "${MAIN_CONF}" <<'EOF'
#!/usr/sbin/nft -f

flush ruleset

table inet filter {
    chain input {
        type filter hook input priority filter;
        policy accept;
    }
    chain forward {
        type filter hook forward priority filter;
        policy accept;
    }
    chain output {
        type filter hook output priority filter;
        policy accept;
    }
}

include "/etc/nftables.d/*.nft"
EOF
fi

if ! grep -Fq 'include "/etc/nftables.d/*.nft"' "${MAIN_CONF}"; then
  printf '\ninclude "/etc/nftables.d/*.nft"\n' >> "${MAIN_CONF}"
fi

nft -c -f "${MAIN_CONF}" >/dev/null
systemctl enable nftables >/dev/null 2>&1 || true
systemctl restart nftables
systemctl is-active --quiet nftables

echo "SPORT firewall sync applied"
