#!/usr/bin/env bash
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V.
#
# Provision Stackiq's OpenRegister registers + schemas on a freshly
# installed Nextcloud, for the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/stackiq/tests/e2e/ci-seed.sh'
#
# WHY THIS IS NEEDED
# ------------------
# `occ app:enable stackiq` runs the `InitializeSettings` post-migration
# repair step, which is supposed to import `lib/Settings/softwarecatalogus_register.json`
# into OpenRegister. Two things make that unreliable as the sole fresh-install
# path, and BOTH fail silently:
#
#   1. An IRepairStep runs with NO user session. OpenRegister's RBAC evaluates
#      the acting user, so the import can be denied outright — and
#      `InitializeSettings::run()` catches `\Exception` and downgrades it to a
#      warning. `occ app:enable` still exits 0.
#   2. The non-forced import path is version-guarded: it can advance the
#      recorded configuration version WITHOUT applying the register, so a
#      second run then sees "already current" and does nothing either.
#
# Either way the app enables cleanly, the SPA boots, and the `voorzieningen`
# register simply is not there. The e2e suite's failure mode in that state is a
# wall of `voorzieningen/config returned 500` / `voorzieningen register not
# configured` — messages that point at the fixtures, not at the missing import.
#
# So this script does the import EXPLICITLY through the admin HTTP API (which
# has a real session and passes RBAC), with `force: true` to defeat the version
# guard, and then VERIFIES the registers, the schemas, AND the app-level
# register/schema mapping the fixtures actually resolve by. A failed provision
# becomes one loud step failure here instead of ~20 misleading spec failures.
#
# It is idempotent: the import is idempotent server-side, and re-running only
# re-verifies.

set -euo pipefail

# ── Target resolution ────────────────────────────────────────────────────────
# The shared workflow's "Seed test data" step exports BASE_URL / NEXTCLOUD_URL /
# ADMIN_USER / ADMIN_PASSWORD, but accept the runner's own `php -S 0.0.0.0:8080`
# as a fallback in case a caller wires this differently.
#
# That fallback is gated on actually being in CI. On a developer box
# `localhost:8080` is the SHARED dev container, and this script performs ADMIN
# WRITES with `force: true` — it must never silently reimport a register into
# somebody else's environment. Off CI, an unset target is a hard error.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[ci-seed] target: ${BASE}"

# ── 1. Import the Stackiq configuration ──────────────────────────────
# `settings#manualImport` is admin-only (no @NoAdminRequired) and
# @NoCSRFRequired, so basic auth is sufficient. `force` is compared with
# `=== true` in SettingsController::manualImport(), so it must arrive as a JSON
# boolean — a form-encoded "true" is the *string* "true" and would be ignored,
# silently giving us the version-guarded path this script exists to bypass.
IMPORT_URL="${BASE}/index.php/apps/stackiq/api/settings/import"
echo "[ci-seed] POST ${IMPORT_URL} (force: true)"

IMPORT_BODY="$(mktemp)"
IMPORT_CODE="$(
	curl -sS -o "$IMPORT_BODY" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data '{"force":true}' \
		"$IMPORT_URL" || echo 000
)"

echo "[ci-seed] import HTTP ${IMPORT_CODE}"
head -c 3000 "$IMPORT_BODY"; echo

if [ "$IMPORT_CODE" != "200" ]; then
	echo "::error::Stackiq configuration import failed (HTTP ${IMPORT_CODE}). The e2e suite cannot resolve the voorzieningen register without it."
	exit 1
fi

# ── 2. Verify the registers and schemas are actually there ───────────────────
# The import reporting success is not the same as the register existing —
# verify against OpenRegister directly, using the slugs declared in
# lib/Settings/softwarecatalogus_register.json and consumed by the e2e
# fixtures (tests/e2e/workflows/_fixtures.ts).
#
# ⚠️ Before believing a zero, prove the query can match: the check below prints
# every slug it DID see, so "missing" can never be confused with "the endpoint
# returned nothing / did not return JSON at all" — the two are distinguished
# explicitly and reported differently.
verify() {
	python3 - "$1" "$2" <<'PY'
import json, sys
path, kind = sys.argv[1], sys.argv[2]
required = {
    # `vng-gemma` (title "AMEF") is the SECOND register this app declares. It
    # carries element / model / property-definition / relation / view, and the
    # Standaarden + StandaardDetail manifest pages read `element` from it via the
    # `@resolve:amef_register` sentinel. It was previously unchecked here, so an
    # import that produced only the catalog register reported a clean seed.
    #
    # `stackiq` is the catalog register's slug (renamed from `voorzieningen`;
    # lib/Repair/MigrateRegisterSlug.php moves the row). `vng-gemma` deliberately
    # keeps its name — it holds VNG reference data, not this app's own store.
    'registers': ['stackiq', 'vng-gemma'],
    # The schemas the e2e fixtures create/read through, per _fixtures.ts
    # (organization, contactPerson, module, contract, moduleVersion) plus the
    # ones the spec-coverage index pages render.
    #
    # These are SLUGS, so they move with a slug rename. This list is the only
    # place that names them outside the register JSON, and it is checked after
    # the import — which is what caught the rename here rather than in a spec.
    'schemas': [
        'organization', 'contactPerson', 'module', 'catalogContract',
        'moduleVersion', 'vulnerability', 'compliancy', 'suite',
    ],
}[kind]
with open(path) as fh:
    raw = fh.read()
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::{kind} endpoint did not return JSON. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
items = body if isinstance(body, list) else body.get('results', [])
slugs = {i.get('slug') for i in items if isinstance(i, dict)}
if not slugs:
    print(f'::error::{kind} endpoint returned ZERO items — the query itself may be wrong.')
    print(raw[:500])
    sys.exit(1)

# A PAGE IS NOT THE POPULATION. `total` is what the instance holds; `items` is
# what this request returned. On a shared instance carrying several apps those
# differ wildly — 1,997 schemas against a 1,000-row page — and a slug that
# simply fell off the page reads exactly like a slug the import never created.
# Refuse to judge rather than report a false absence.
total = None
if isinstance(body, dict):
    for key in ('total', 'count'):
        if isinstance(body.get(key), int):
            total = body[key]
            break
if total is not None and total > len(items):
    print(f'::error::{kind} listing is TRUNCATED: {len(items)} of {total} returned.')
    print('::error::Raise the _limit on this request. A missing slug cannot be '
          'distinguished from one that fell off the page, so this check is '
          'refusing to report either.')
    sys.exit(1)

missing = [s for s in required if s not in slugs]
print(f'[ci-seed] {kind} present ({len(slugs)} of {total if total is not None else len(slugs)})')
if missing:
    print(f'::error::Stackiq {kind} missing after import: {missing}')
    sys.exit(1)
print(f'[ci-seed] {kind} OK ({len(required)} required slugs present)')
PY
}

REG_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=2000" -o "$REG_BODY"
verify "$REG_BODY" registers

SCH_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/schemas?_limit=10000" -o "$SCH_BODY"
verify "$SCH_BODY" schemas

# ── 3. Verify the APP-LEVEL mapping, not just OpenRegister ───────────────────
# The register existing in OpenRegister and stackiq KNOWING about it are
# two different facts. `tests/e2e/workflows/_fixtures.ts` resolves the register
# id and every schema id from `GET /api/voorzieningen/config`, which reads the
# `voorzieningen_config` app-config value written by the auto-configure pass of
# the import. If that pass silently no-ops, OpenRegister looks perfectly seeded
# and every fixture still dies on "voorzieningen register not configured".
CFG_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/stackiq/api/voorzieningen/config" -o "$CFG_BODY"

python3 - "$CFG_BODY" <<'PY'
import json, sys
with open(sys.argv[1]) as fh:
    raw = fh.read()
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print('::error::voorzieningen/config did not return JSON. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
config = (body or {}).get('config') or {}
print(f'[ci-seed] voorzieningen config: {json.dumps(config)[:800]}')
missing = [k for k in ('register', 'organisatie_schema', 'contactpersoon_schema',
                       'module_schema', 'contract_schema') if not config.get(k)]
if missing:
    print(f'::error::stackiq has no register/schema mapping for: {missing}')
    print('::error::OpenRegister may be seeded, but the app cannot resolve it — '
          'every e2e fixture would fail with "voorzieningen register not configured".')
    sys.exit(1)
print('[ci-seed] app-level voorzieningen mapping OK.')
PY

# ── 3b. The AMEF register: resolve it, PROBE IT, and give it rows ────────────
# The Standaarden / StandaardDetail manifest pages read `schema: element`, which
# lib/Settings/softwarecatalogus_register.json attaches to the `vng-gemma` (AMEF)
# register and NOT to the catalog register `stackiq`. They resolve it through the
# `@resolve:amef_register` sentinel that lib/AppInfo/Application.php::boot()
# provisions from the `amef_config` blob.
#
# ⚠️ THE CHECK ABOVE CANNOT CATCH THE FAILURE THIS ONE EXISTS FOR. Verifying that
# a schema slug is PRESENT in /api/schemas is a different question from whether it
# is ATTACHED to the register you are about to address, and only the second one
# decides whether /api/objects/{register}/{schema} answers. OpenRegister used to
# fall back to a global slug lookup on a scoped miss; since 2026-08-16 it throws,
# so an unattached slug returns `404 {"message":"Schema not found: 'element'"}`.
# The only honest probe is the request the page itself makes — so we make it.
#
# And a 200 is still not enough. An empty list renders as a legitimate "No items
# found", i.e. a page that is broken and quiet looks exactly like a page that is
# healthy and unpopulated. GEMMA elements normally arrive via the ArchiMate import
# of a multi-megabyte GEMMA_release.xml, which no CI job runs, so we seed two
# `element` fixtures here — one `gemmaType: standaard` (which the page MUST list)
# and one `gemmaType: referentiecomponent` (which it must NOT). The pair makes the
# page's own `filter.gemmaType` falsifiable in both directions.
AMEF_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/stackiq/api/amef/config" -o "$AMEF_BODY"

AMEF_REGISTER="$(
	python3 - "$AMEF_BODY" <<'PY'
import json, sys
with open(sys.argv[1]) as fh:
    raw = fh.read()
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print('::error::amef/config did not return JSON. First 500 bytes:', file=sys.stderr)
    print(raw[:500], file=sys.stderr)
    sys.exit(1)
config = (body or {}).get('config') or {}
print(f'[ci-seed] amef config: {json.dumps(config)[:400]}', file=sys.stderr)
register = str(config.get('register') or config.get('register_id') or '')
if not register:
    print('::error::stackiq has no AMEF register mapping — the '
          '@resolve:amef_register sentinel would resolve to null and the '
          'Standards pages would fetch /api/objects/@resolve:amef_register/element.',
          file=sys.stderr)
    sys.exit(1)
if not config.get('element_schema'):
    print('::error::the AMEF config names a register but no element schema.', file=sys.stderr)
    sys.exit(1)
print(register)
PY
)"
echo "[ci-seed] amef register id: ${AMEF_REGISTER}"

# The page's own request, verbatim. A non-200 here is the whole defect this
# section guards, so report the status AND the body — "Schema not found" and
# "Register not found" are different faults with different fixes.
ELEM_BODY="$(mktemp)"
ELEM_CODE="$(
	curl -sS -o "$ELEM_BODY" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
		"${BASE}/index.php/apps/openregister/api/objects/${AMEF_REGISTER}/element?_limit=1" || echo 000
)"
echo "[ci-seed] GET /api/objects/${AMEF_REGISTER}/element -> ${ELEM_CODE}"
if [ "$ELEM_CODE" != "200" ]; then
	head -c 500 "$ELEM_BODY"; echo
	echo "::error::The AMEF register does not carry the 'element' schema (HTTP ${ELEM_CODE})."
	echo "::error::Declaring a schema does not attach it — check components.registers.vng-gemma.schemas in lib/Settings/softwarecatalogus_register.json."
	exit 1
fi

# Seed the two fixtures, idempotently. `identifier` is a declared property, so it
# is addressable as a bare query filter (the HTTP list API takes bare property
# names; `filters[identifier]=…` matches nothing). Verified with a negative
# control: an unknown identifier returns total 0, so a hit really is a hit.
python3 - "$BASE" "$USER_NAME" "$USER_PASS" "$AMEF_REGISTER" <<'PY'
import base64, json, sys, urllib.error, urllib.parse, urllib.request

base, user, password, register = sys.argv[1:5]
auth = base64.b64encode(f'{user}:{password}'.encode()).decode()
collection = f'{base}/index.php/apps/openregister/api/objects/{register}/element'

# `identifier`, `type` and `properties` are the element schema's required fields
# and it runs with hardValidation on, so all three must be present or the create
# is refused.
FIXTURES = [
    {
        'identifier': 'e2e-gemma-standaard-digikoppeling',
        'type': 'Standard',
        'properties': [],
        'name': 'Digikoppeling',
        'gemmaType': 'standaard',
        'gemmaThema': 'Gegevensuitwisseling',
        'gemmaStatus': 'In gebruik',
        'url': 'https://gemmaonline.nl/index.php/Digikoppeling',
    },
    {
        # The discriminator control. Same register, same schema, different
        # gemmaType — so it is listed by an unfiltered page and hidden by a
        # correctly filtered one. Without it, `filter.gemmaType` could be dropped
        # entirely and every assertion would still pass.
        'identifier': 'e2e-gemma-referentiecomponent-zaakregistratie',
        'type': 'ApplicationComponent',
        'properties': [],
        'name': 'Zaakregistratiecomponent',
        'gemmaType': 'referentiecomponent',
        'gemmaThema': 'Zaakgericht werken',
    },
]


def request(url, method='GET', payload=None):
    data = None
    headers = {'Authorization': f'Basic {auth}', 'OCS-APIRequest': 'true'}
    if payload is not None:
        data = json.dumps(payload).encode()
        headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    with urllib.request.urlopen(req) as resp:
        return resp.status, json.loads(resp.read().decode() or '{}')


def count(query):
    url = f'{collection}?{urllib.parse.urlencode(query)}'
    _, body = request(url)
    return body.get('total', 0), body


for fixture in FIXTURES:
    existing, _ = count({'_limit': 1, 'identifier': fixture['identifier']})
    if existing:
        print(f"[ci-seed] element fixture already present: {fixture['identifier']}")
        continue
    try:
        status, _ = request(collection, method='POST', payload=fixture)
    except urllib.error.HTTPError as exc:
        print(f"::error::Creating element fixture {fixture['identifier']} failed "
              f'(HTTP {exc.code}): {exc.read()[:300]!r}')
        sys.exit(1)
    print(f"[ci-seed] created element fixture {fixture['identifier']} (HTTP {status})")

# Verify with a FRESH read, never with the create response — OpenRegister echoes
# back properties it discarded, so a save response cannot tell you what was
# stored. Three assertions, of which the middle one is the point: the filtered
# query must be strictly smaller than the unfiltered one.
total_all, _ = count({'_limit': 50})
total_std, listed = count({'_limit': 50, 'gemmaType': 'standaard'})
total_none, _ = count({'_limit': 50, 'gemmaType': 'NO-SUCH-GEMMA-TYPE'})

names = sorted(str(r.get('name') or '') for r in listed.get('results', []))
print(f'[ci-seed] element objects: {total_all} total, {total_std} with '
      f'gemmaType=standaard, {total_none} with a nonsense gemmaType')
print(f'[ci-seed] standards the index page will list: {names}')

if total_std < 1:
    print('::error::No element object carries gemmaType=standaard, so the '
          'Standards index would render its empty state. A quiet empty page is '
          'the failure this seed exists to prevent.')
    sys.exit(1)
if total_none != 0:
    print('::error::A nonsense gemmaType still matched rows — the bare-property '
          'filter is not filtering, so "the page lists standards" would be '
          'satisfied by any element at all.')
    sys.exit(1)
if total_all <= total_std:
    print('::error::Every element carries gemmaType=standaard, so the page\'s '
          'filter has nothing to exclude and cannot be shown to work.')
    sys.exit(1)
print('[ci-seed] AMEF element fixtures verified (positive + negative control).')
PY

echo "[ci-seed] Stackiq registers + schemas provisioned."

# ── 4. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# The shared workflow serves Nextcloud with `php -S 0.0.0.0:8080`. It now sets
# PHP_CLI_SERVER_WORKERS: 8, but the first hit still pays a cold opcache and the
# first parse of a multi-megabyte webpack bundle, and the effect lands entirely
# on whichever spec happens to run first.
#
# Warm it here, in the environment-preparation step where it belongs. The
# alternative — raising the first spec's timeout — would hide the cold start
# inside an assertion and keep drifting upward. Failures are ignored on
# purpose: this is a warm-up, not a gate. The real checks are above and below.
for path in \
	"/index.php/apps/stackiq/" \
	"/index.php/apps/stackiq/api/settings" \
	"/index.php/apps/stackiq/api/voorzieningen/config" \
	"/index.php/apps/openregister/api/registers?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# Pull the main webpack bundle once so it is in the page cache.
#
# Do NOT hardcode the URL. Nextcloud serves an app's assets from whichever apps
# directory it was installed into — `/apps/<app>/js/...` on the CI runner,
# `/custom_apps/<app>/js/...` in the docker dev images — and asking for the
# wrong one does not 404. It returns **HTTP 200 with `text/html`**: the NC error
# page, served through index.php. A status-code check therefore reports success
# while fetching a 40 KB HTML page instead of a multi-MB bundle, so the warm-up
# silently warms nothing.
#
# Read the real src out of the rendered app page instead, and verify the
# response is actually JavaScript.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/stackiq/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that aborts the script right here — so the case the gate
# below exists to explain (no bundle) would die with a bare non-zero exit and
# none of the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*stackiq-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app — and the environment hides it well: when the
# bundle is absent, Nextcloud does not 404. It serves its HTML error page with
# **HTTP 200 and Content-Type text/html**, so `npm run build` producing nothing
# looks, to every status-code check in the pipeline, exactly like success.
#
# The specs are the honest signal (the `smoke` project asserts the app actually
# MOUNTS); this check just makes the cause loud and immediate instead of
# arriving as a wall of selector timeouts.
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle verified as JavaScript."
			;;
		*)
			echo "::error::The Stackiq frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac
fi

echo "[ci-seed] done."

# ── Settle the demo-data decision ────────────────────────────────────────────
# 🔴 OR THE SETUP WIZARD MASKS EVERY CLICK. ADR-111 added an OPTIONAL
# `demo-data` step, and CnAppRoot opens the non-gating wizard as a full modal
# mask while ANY optional step that is not info/summary is reported not-done —
# in every fresh browser context, so once per spec.
#
# Measured on this app's development at the ADR-111 merge: clicks failed with
# "locator resolved to <button ...> - attempting click action", the call log
# naming <ol class="cn-wizard-dialog__progress"> as the interceptor. The element
# was found; the click never landed.
#
# SKIPPED, not installed: recording the decision is what closes the wizard.
# Installing would push the app's whole demo dataset into every list the suite
# asserts on. `demo-data-setup-step.spec.ts` exercises the install deliberately.
#
# Uses the workflow's own exported credentials rather than this script's, so it
# does not depend on where in the file it sits.
#
# Tolerant on purpose: an app whose wizard has no demo-data step answers 400
# here, and that is not a seeding failure.
DEMO_BASE="${BASE_URL:-${NEXTCLOUD_URL:-http://localhost:8080}}"
DEMO_CODE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 300 \
	-u "${ADMIN_USER:-admin}:${ADMIN_PASSWORD:-admin}" -X POST \
	-H 'Content-Type: application/json' -H 'OCS-APIRequest: true' --data '{}' \
	"${DEMO_BASE}/index.php/apps/stackiq/api/setup/action/skip-demo-data" || echo 000)"
echo "[ci-seed] POST setup/action/skip-demo-data -> HTTP ${DEMO_CODE}"
