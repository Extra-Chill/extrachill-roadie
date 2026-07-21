# Roadie Multisite Artist Journey

This product-owned package consumes the installed `wordpress-multisite-e2e`
Homeboy rig without modifying or wrapping it. The generic rig owns disposable
Playground startup and evidence capture. These files only declare Roadie's real
component mounts, seed data, product assertions, and browser scenarios.

## Inputs

Copy `components.example.json` outside the repository and replace every path and
version with the checkout that should be tested. Paths and revisions are runtime
inputs so committed configuration does not depend on one host's workspace
layout. `extrachill-roadie` should point at the checkout under test.

The standalone `agents-api` mount is intentional. Queue ownership depends on the
canonical fix from Automattic/agents-api#451; mounting it before Data Machine
prevents an older bundled copy from silently weakening the journey.

## Validate Without Playground

```bash
node tests/e2e/roadie-multisite/validate.mjs
```

## Run Through The Installed Rig

One command runs the journey and retains the generic rig's result envelope and
WP Codebox evidence bundle:

```bash
ROADIE_E2E_COMPONENTS_FILE=/absolute/path/to/components.json ROADIE_E2E_WORDPRESS_VERSION=nightly ROADIE_E2E_ARTIFACT_ROOT="$PWD/artifacts/roadie-multisite" node tests/e2e/roadie-multisite/run.mjs
```

The command prints `artifactRoot` and `resultFile`. Browser scenarios retain
steps, console output, page errors, HTML, network activity, screenshots, and DOM
snapshots for anonymous mobile and authenticated desktop journeys.

## Coverage

- path-based main, Artist Platform, Events, and Community sites;
- shared network user identity and reciprocal artist membership;
- unrelated-user denial for artist objects, revisions, autosaves, sessions, and queues;
- real WordPress `artist_profile` REST CRUD, revisions, and autosaves;
- one network-workspace Roadie session created, listed, read, titled, continued,
  and deleted across different subsites;
- run and queue ownership using canonical Agents API workspace/owner binding;
- pending-action resolution at its stored Events origin, with forged site and
  foreign-network workspace claims denied;
- explicit canonical artist-term mapping to Events with intentionally different
  slugs, proving there is no request-time slug fallback;
- canonical venue and calendar adapters preserving identity, location, timing,
  taxonomy, occurrence, permalink, and ticket fields.

## Topology Boundary

The generic rig is a path-based network on one canonical `http://localhost`
origin. Its authenticated browser journey proves same-origin path multisite
cookie continuity only. It does not model Extra Chill's mapped domains, DNS,
TLS boundaries, or cross-domain cookie behavior and must not be presented as
production-domain parity.

## Current Upstream Blocker

The real integration currently stops while activating standalone Agents API at
`agents-api.php:82`: PHP-WASM does not define optional `GLOB_BRACE`. This is
tracked upstream as Automattic/agents-api#459. Roadie deliberately carries no
bootstrap shim for that substrate defect.

Blocked Homeboy run `23ad7025-aebb-4652-b02d-44f7546ed978` retains the fatal and
recipe evidence. Inspect it with:

```bash
homeboy runs artifacts 23ad7025-aebb-4652-b02d-44f7546ed978
homeboy runs evidence 23ad7025-aebb-4652-b02d-44f7546ed978
```
