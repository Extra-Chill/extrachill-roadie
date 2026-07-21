#!/usr/bin/env node
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { buildSettings, readComponents } from './settings.mjs';

const components = await readComponents();
const wordpressVersion = process.env.ROADIE_E2E_WORDPRESS_VERSION;
const artifactRoot = path.resolve(process.env.ROADIE_E2E_ARTIFACT_ROOT || 'artifacts/roadie-multisite');
const resultFile = path.join(artifactRoot, 'rig-result.json');
const settings = buildSettings(components, wordpressVersion);

await mkdir(artifactRoot, { recursive: true });

const result = spawnSync('homeboy', ['rig', 'up', 'wordpress-multisite-e2e'], {
  encoding: 'utf8',
  env: {
    ...process.env,
    HOMEBOY_ARTIFACT_ROOT: artifactRoot,
    HOMEBOY_NETWORK_E2E_RESULT_FILE: resultFile,
    HOMEBOY_SETTINGS_JSON: JSON.stringify(settings),
  },
  stdio: 'inherit',
});

if (result.error) {
  throw result.error;
}
if (result.status !== 0) {
  throw new Error(`Homeboy multisite rig exited with status ${result.status}.`);
}

console.log(JSON.stringify({ artifactRoot, resultFile }, null, 2));
