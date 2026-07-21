#!/usr/bin/env node
import { strict as assert } from 'node:assert';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { buildSettings, componentFiles, root } from './settings.mjs';

const components = Object.fromEntries(
  Object.keys(componentFiles).map((slug) => [slug, { path: `/components/${slug}`, version: 'test-revision' }]),
);
const settings = buildSettings(components, '6.9');

assert.equal(settings.wp_codebox_extra_plugins.length, Object.keys(componentFiles).length + 1);
assert.deepEqual(
  settings.roadie_e2e_component_versions,
  Object.fromEntries(Object.keys(componentFiles).map((slug) => [slug, 'test-revision'])),
);
assert.deepEqual(
  settings.wordpress_runtime_prepare_steps.map((step) => step.command),
  ['wordpress.run-php', 'wordpress.run-php'],
);
assert.equal(settings.wordpress_runtime_post_steps[0].command, 'wordpress.run-php');
assert.equal(settings.wp_codebox_scenario_manifests.length, 2);

for (const scenarioPath of settings.wp_codebox_scenario_manifests) {
  const scenario = JSON.parse(await readFile(scenarioPath, 'utf8'));
  assert.equal(typeof scenario.url, 'string');
  assert.ok(Array.isArray(scenario.steps));
  assert.ok(Array.isArray(scenario.assertions));
  assert.ok(scenario.captures.includes('console'));
  assert.ok(scenario.captures.includes('errors'));
  assert.ok(scenario.assertions.some((item) => item.type === 'noPageErrors'));
}

for (const file of ['activate.php', 'seed.php', 'assert.php', 'README.md']) {
  const source = await readFile(path.join(root, file), 'utf8');
  assert.doesNotMatch(source, /\/var\/(?:lib\/datamachine\/workspace|www)\//);
}

console.log('Roadie multisite E2E scenario and recipe inputs passed static validation.');
