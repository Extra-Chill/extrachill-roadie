#!/usr/bin/env node
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { buildProvenance, buildSettings, readComponents } from './settings.mjs';

const components = await readComponents();
const wordpressVersion = process.env.ROADIE_E2E_WORDPRESS_VERSION;
const artifactRoot = path.resolve(process.env.ROADIE_E2E_ARTIFACT_ROOT || 'artifacts/roadie-multisite');
const resultFile = path.join(artifactRoot, 'rig-result.json');
const provenanceFile = path.join(artifactRoot, 'roadie-component-provenance.json');
const stdoutFile = path.join(artifactRoot, 'homeboy-rig.stdout.log');
const stderrFile = path.join(artifactRoot, 'homeboy-rig.stderr.log');
const settings = buildSettings(components, wordpressVersion);

await mkdir(artifactRoot, { recursive: true });
await writeFile(provenanceFile, `${JSON.stringify(buildProvenance(components, wordpressVersion), null, 2)}\n`);

const result = spawnSync('homeboy', ['rig', 'up', 'wordpress-multisite-e2e'], {
  encoding: 'utf8',
  env: {
    ...process.env,
    HOMEBOY_ARTIFACT_ROOT: artifactRoot,
    HOMEBOY_NETWORK_E2E_RESULT_FILE: resultFile,
    HOMEBOY_SETTINGS_JSON: JSON.stringify(settings),
  },
  stdio: 'pipe',
});

const stdout = result.stdout || '';
const stderr = result.stderr || '';
await Promise.all([writeFile(stdoutFile, stdout), writeFile(stderrFile, stderr)]);
process.stdout.write(stdout);
process.stderr.write(stderr);

const retained = { artifactRoot, provenanceFile, resultFile, stdoutFile, stderrFile };
console.log(JSON.stringify(retained, null, 2));

if (result.error) {
  throw result.error;
}
if (result.status !== 0) {
  throw new Error(`Homeboy multisite rig exited with status ${result.status}.`);
}
