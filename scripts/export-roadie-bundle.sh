#!/usr/bin/env bash
#
# Re-export the roadie agent as a share-profile bundle into bundles/roadie/.
# Run from the repo root on a host with WP-CLI + Data Machine and the roadie agent.
#
# Roadie ships as a committed Data Machine agent bundle — the same pattern
# extrachill-event-bundles uses for events-bot. The committed bundle is the
# canonical source of Roadie's identity (memory/agent/SOUL.md); DM materializes
# it into the live agent store via `datamachine agent install` / `agent upgrade`,
# so there is no hand-rolled sync code. Edit the SOUL in the bundle, install/
# upgrade, done.
#
# The share profile strips secrets (any API keys/tokens become symbolic
# provider:account refs). NEVER change this to --profile=backup for a committed
# bundle — backup carries live credentials and must stay out of git. (Roadie
# currently has no credentialed handlers, but the guard stays so a future
# change can't quietly leak.)

set -euo pipefail

WP="${WP_CLI:-wp}"
WP_PATH="${WP_PATH:-/var/www/extrachill.com}"
WP_URL="${WP_URL:-extrachill.com}"
AGENT="${AGENT:-roadie}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${REPO_ROOT}/bundles/${AGENT}"
TMP="$(mktemp -d)"

echo "Exporting ${AGENT} (share profile) ..."
"${WP}" --allow-root --path="${WP_PATH}" --url="${WP_URL}" \
  datamachine agent export "${AGENT}" \
  --profile=share --format=directory --destination="${TMP}/${AGENT}"

# Safety: refuse to publish if any secret-like value slipped through.
if grep -rilE '"(access_token|refresh_token|api_key|apikey|client_secret|password|secret)"[[:space:]]*:[[:space:]]*"[^"]+' "${TMP}/${AGENT}" >/dev/null 2>&1; then
  echo "ERROR: secret-like values found in export — refusing to write. Inspect ${TMP}/${AGENT}" >&2
  exit 1
fi

# Overwrite in place so git diff reflects exactly what changed.
rm -rf "${DEST}"
mkdir -p "${DEST}"
cp -r "${TMP}/${AGENT}/." "${DEST}/"
rm -rf "${TMP}"

echo "Done. Review with: git -C '${REPO_ROOT}' diff --stat"
