#!/usr/bin/env bash
#
# Imagina Reports — CrowdSec push agent (CLAUDE.md §9, push model).
#
# Runs on the CLIENT VPS where CrowdSec is installed. It reads this month's alerts from
# the LOCAL CrowdSec engine (cscli) and POSTs them OUTBOUND over HTTPS to Imagina Reports.
# No inbound port is ever opened on this server — it only makes an outbound call, like any
# website. Authentication is the per-source push URL (which embeds a secret token); copy it
# from Imagina Reports → Fuentes → tu fuente de CrowdSec.
#
# Install (run once, as root, on the client VPS):
#   1) Save this file:        /usr/local/bin/imagina-crowdsec-push.sh
#   2) Make it executable:    chmod +x /usr/local/bin/imagina-crowdsec-push.sh
#   3) Set the URL below (or export IMAGINA_INGEST_URL in the cron line).
#   4) Add a cron (hourly):
#        echo '0 * * * * root IMAGINA_INGEST_URL="https://reports.imagina.cloud/api/v1/ingest/TU_TOKEN" /usr/local/bin/imagina-crowdsec-push.sh' \
#          > /etc/cron.d/imagina-crowdsec-push
#
# Requirements: crowdsec (cscli) installed and running locally, plus curl.

set -euo pipefail

# The push URL from Imagina Reports (Fuentes → CrowdSec → "Comando de instalación").
# Either edit this line or pass it via the IMAGINA_INGEST_URL environment variable.
INGEST_URL="${IMAGINA_INGEST_URL:-https://reports.imagina.cloud/api/v1/ingest/REEMPLAZA_CON_TU_TOKEN}"

if ! command -v cscli >/dev/null 2>&1; then
    echo "cscli not found — is CrowdSec installed on this server?" >&2
    exit 1
fi

# Whole hours elapsed since the 1st of this month, so we report the current calendar
# month (Imagina Reports stamps the snapshot to this month's window on its side).
month_start_epoch="$(date -d "$(date +%Y-%m-01) 00:00:00" +%s)"
hours_this_month="$(( ( $(date +%s) - month_start_epoch ) / 3600 + 1 ))"

# Already aggregated at the source (CLAUDE.md §3.3): cscli returns a compact JSON array
# of this month's alerts — never raw logs.
alerts_json="$(cscli alerts list --since "${hours_this_month}h" -o json 2>/dev/null || echo '[]')"

# Wrap as { "alerts": [...] }; the connector accepts either shape.
curl -fsS --max-time 30 \
    -H 'Content-Type: application/json' \
    -X POST "${INGEST_URL}" \
    --data "{\"alerts\": ${alerts_json}}" \
    >/dev/null

echo "Imagina Reports: CrowdSec alerts pushed."
