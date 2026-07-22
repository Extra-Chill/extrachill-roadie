# Roadie Multisite Artist Journey

This product-owned package consumes the installed `wordpress-multisite-e2e`
Homeboy rig without modifying or wrapping it. The generic rig owns disposable
Playground startup and evidence capture. These files only declare Roadie's real
component mounts, seed data, product assertions, and browser scenarios.

## Inputs

Use `components.example.json` as the shape for a manifest outside the repository
and replace every path and version with the checkout that should be tested. The
manifest includes the Extra Chill theme as well as the plugin components because
the theme owns the product `artist` and `location` taxonomies. Each version must
be the full immutable Git commit at that checkout's `HEAD`; the runner rejects
branch names, abbreviated hashes, mismatches, and dirty checkouts. Verified clean
revisions are bound to a digest of every mounted byte outside `.git`, then
persisted in the effective WP Codebox recipe and a local provenance sidecar. The
theme is mounted readonly and activated on every site by the generic rig. The
Roadie manifest entry must be the checkout running this harness so fixture code
cannot drift from the attributed Roadie revision.
`extrachill-roadie` should point at the checkout under test.

The runner also requires explicit WordPress and PHP versions. Use PHP `8.4` for
the live-equivalent package run; both runtime selections are retained in
provenance.

The standalone `agents-api` mount is intentional. Queue ownership depends on the
canonical fix from Automattic/agents-api#451; mounting it before Data Machine
keeps the standalone runtime explicit. The same immutable checkout is also
declared through WP Codebox's Composer dependency overlay contract so Data
Machine cannot load an older bundled copy from its committed vendor directory.

The API and Network mounts are also intentional product composition: Extra Chill
Users declares both as required plugins, so the journey activates those real
dependencies rather than bypassing WordPress dependency enforcement.

## Validate Without Playground

```bash
node tests/e2e/roadie-multisite/validate.mjs
```

This creates disposable Git component fixtures and invokes
`homeboy rig check wordpress-multisite-e2e`. The installed generic rig generates
the exact recipe, runs WP Codebox recipe validation and dry-run validation, and
validates the embedded browser interaction schemas without starting Playground.

## Run Through The Installed Rig

One command runs the journey and retains Roadie's verified provenance and outer
Homeboy stdout/stderr. When the generic rig completes, it also retains its result
envelope and WP Codebox evidence bundle:

```bash
ROADIE_E2E_COMPONENTS_FILE=/absolute/path/to/components.json ROADIE_E2E_WORDPRESS_VERSION=nightly ROADIE_E2E_PHP_VERSION=8.4 ROADIE_E2E_ARTIFACT_ROOT="$PWD/artifacts/roadie-multisite" node tests/e2e/roadie-multisite/run.mjs
```

The command prints all retained paths. Browser scenarios use real network
frontends and the mounted Frontend Agent Chat widget; they retain steps, console
output, page errors, HTML, network activity, screenshots, and DOM snapshots for
anonymous mobile and authenticated desktop journeys.

## Coverage

- path-based main, Artist Platform, Events, and Community sites;
- shared network user identity and reciprocal artist membership;
- unrelated-user denial for artist objects, revisions, autosaves, sessions, and queues;
- real WordPress `artist_profile` REST CRUD, revisions, and autosaves;
- one network-workspace Roadie session created and continued by repeated calls
  to FAC's production `/chat` route, then listed, read, titled, and deleted
  through FAC lifecycle routes across different subsites;
- production-created run ownership plus FAC queue ownership using canonical
  Agents API workspace/owner binding;
- pending-action resolution through FAC at its stored Events origin, including
  forged-site, foreign-network, foreign-owner, and real artist-capability denial;
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

## Current Owning-Layer Blockers

The full acceptance run has not reached every Roadie assertion and this package
does not establish issue #87 as complete.

- Automattic/agents-api#459: the owning fix at `66d8ae1` clears the PHP-WASM
  `GLOB_BRACE` activation fatal when supplied as an immutable component input;
  the issue remains open upstream.
- Automattic/wp-codebox#1949: `wordpress.phpunit` can materialize an inconsistent
  Composer static autoloader before test discovery. Retesting directly against
  fix branch `50d2d220` still reproduces the missing generated class.
- Extra-Chill/homeboy#9579: local files and WP Codebox bundles cannot currently
  be registered against the enclosing Homeboy rig run on both success and
  failure. Roadie retains local paths but does not claim they are Homeboy
  artifact rows.

Earlier blocked evidence remains inspectable, but it predates the independent
review corrections and is not acceptance evidence:

```bash
homeboy runs artifacts 23ad7025-aebb-4652-b02d-44f7546ed978
homeboy runs evidence 23ad7025-aebb-4652-b02d-44f7546ed978
```

Current correction evidence:

```bash
homeboy runs evidence 05218356-ab5d-45ce-b40d-761adf369267
homeboy runs evidence 56b126f6-0322-4052-aa8a-73f64b8b2d3c
```

Run `05218356-ab5d-45ce-b40d-761adf369267` proves the fixed Agents API and the
then-declared plugin dependencies reached the former generic rig PHP-version
boundary; it predates the theme and PHP consumer inputs added here. Run
`56b126f6-0322-4052-aa8a-73f64b8b2d3c` records clean audit/lint plus the
remaining WP Codebox Composer bootstrap failure against its fix branch.
