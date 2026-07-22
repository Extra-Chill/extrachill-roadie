#!/usr/bin/env node
import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { buildProvenance, buildSettings, componentFiles, readComponents, root, themeFiles } from './settings.mjs';

const temporaryRoot = await mkdtemp(path.join(os.tmpdir(), 'roadie-multisite-validate-'));

try {
  const manifest = {};
  const checkoutFiles = { ...componentFiles, ...themeFiles };
  for (const [slug, checkoutFile] of Object.entries(checkoutFiles)) {
    const componentPath = path.join(temporaryRoot, slug);
    await mkdir(componentPath, { recursive: true });
    const fixture = Object.hasOwn(themeFiles, slug)
      ? `/*\nTheme Name: ${slug} validation fixture\n*/\n`
      : `<?php\n/* Plugin Name: ${slug} validation fixture */\n`;
    await writeFile(path.join(componentPath, checkoutFile), fixture);
    if (Object.hasOwn(themeFiles, slug)) {
      await writeFile(path.join(componentPath, 'index.php'), '<?php\n');
    }
    run('git', ['init', '--quiet', componentPath]);
    run('git', ['-C', componentPath, 'add', '.']);
    run('git', ['-C', componentPath, '-c', 'user.name=Roadie E2E', '-c', 'user.email=roadie-e2e@example.test', 'commit', '--quiet', '-m', 'test fixture']);
    const revision = run('git', ['-C', componentPath, 'rev-parse', 'HEAD']).stdout.trim();
    manifest[slug] = { path: componentPath, version: revision };
  }

  const manifestFile = path.join(temporaryRoot, 'components.json');
  await writeFile(manifestFile, `${JSON.stringify(manifest, null, 2)}\n`);
  const components = await readComponents(manifestFile, { enforceRoadieRoot: false });
  const settings = buildSettings(components, '7.0', '8.4');
  const provenance = buildProvenance(components, '7.0', '8.4');

  assert.throws(() => buildSettings(components, '7.0'), /ROADIE_E2E_PHP_VERSION must explicitly select PHP/);
  assert.equal(settings.wp_codebox_extra_plugins.length, Object.keys(componentFiles).length + 1);
  assert.equal(settings.wordpress_runtime_version, '7.0');
  assert.equal(settings.wordpress_runtime_php_version, '8.4');
  assert.equal(settings.wordpress_multisite_synthetic_fixture, false);
  assert.equal(settings.wordpress_runtime_prepare_steps[0].metadata.roadie_e2e_provenance.components.length, Object.keys(checkoutFiles).length);
  assert.equal(provenance.wordpress, '7.0');
  assert.equal(provenance.php, '8.4');
  assert.deepEqual(
    settings.wp_codebox_extra_plugins.slice(1).map((plugin) => plugin.metadata.provenance.revision),
    Object.keys(componentFiles).map((slug) => manifest[slug].version),
  );
  assert.deepEqual(settings.wp_codebox_extra_themes, [{
    source: manifest.extrachill.path,
    slug: 'extrachill',
    activate: true,
    metadata: {
      provenance: {
        schema: 'extrachill-roadie/component-checkout/v1',
        component: 'extrachill',
        kind: 'theme',
        revision: manifest.extrachill.version,
        content_sha256: components.extrachill.contentSha256,
        dirty: false,
      },
    },
  }]);
  assert.deepEqual(settings.wp_codebox_dependency_overlays, [{
    kind: 'composer-package',
    package: 'wordpress/agents-api',
    source: manifest['agents-api'].path,
    consumer: 'data-machine',
    metadata: {
      provenance: {
        schema: 'extrachill-roadie/component-checkout/v1',
        component: 'agents-api',
        revision: manifest['agents-api'].version,
        content_sha256: components['agents-api'].contentSha256,
        dirty: false,
      },
    },
  }]);
  assert.equal(provenance.components.find((component) => component.slug === 'extrachill')?.kind, 'theme');
  assert.deepEqual(
    settings.wordpress_runtime_prepare_steps.map((step) => step.command),
    ['wordpress.run-php', 'wordpress.run-php', 'wordpress.run-php'],
  );
  assert.deepEqual(settings.wordpress_runtime_post_steps, []);
  assert.equal(settings.wp_codebox_scenario_manifests.length, 2);

  const mismatch = structuredClone(manifest);
  mismatch['agents-api'].version = '0000000000000000000000000000000000000000';
  const mismatchFile = path.join(temporaryRoot, 'components-mismatch.json');
  await writeFile(mismatchFile, `${JSON.stringify(mismatch, null, 2)}\n`);
  await assert.rejects(readComponents(mismatchFile, { enforceRoadieRoot: false }), /does not match checkout HEAD/);

  await writeFile(path.join(manifest['agents-api'].path, 'untracked.php'), '<?php\n');
  await assert.rejects(readComponents(manifestFile, { enforceRoadieRoot: false }), /has uncommitted changes/);
  await rm(path.join(manifest['agents-api'].path, 'untracked.php'));

  await writeFile(path.join(manifest.extrachill.path, 'untracked.css'), '/* dirty theme */\n');
  await assert.rejects(readComponents(manifestFile, { enforceRoadieRoot: false }), /extrachill\.path has uncommitted changes/);
  await rm(path.join(manifest.extrachill.path, 'untracked.css'));

  for (const scenarioPath of settings.wp_codebox_scenario_manifests) {
    const scenario = JSON.parse(await readFile(scenarioPath, 'utf8'));
    assert.equal(typeof scenario.url, 'string');
    assert.ok(Array.isArray(scenario.steps));
    assert.ok(Array.isArray(scenario.assertions));
    assert.ok(scenario.captures.includes('console'));
    assert.ok(scenario.captures.includes('errors'));
    assert.ok(scenario.assertions.some((item) => item.type === 'noPageErrors'));
    assert.ok(scenario.steps.some((step) => step.kind === 'navigate' || step.kind === 'click'));
    assert.doesNotMatch(scenario.url, /roadie-e2e/);
    if (scenario.auth === 'wordpress-admin') {
      assert.equal(scenario.authUserId, 2);
    }
  }

  for (const file of ['activate.php', 'seed.php', 'assert.php', 'README.md']) {
    const source = await readFile(path.join(root, file), 'utf8');
    assert.doesNotMatch(source, /\/var\/(?:lib\/datamachine\/workspace|www)\//);
  }

  const rigCheck = run('homeboy', ['--placement', 'local', 'rig', 'check', 'wordpress-multisite-e2e'], {
    HOMEBOY_ARTIFACT_ROOT: path.join(temporaryRoot, 'artifacts'),
    HOMEBOY_NETWORK_E2E_RESULT_FILE: path.join(temporaryRoot, 'rig-result.json'),
    HOMEBOY_SETTINGS_JSON: JSON.stringify(settings),
  });
  process.stdout.write(rigCheck.stdout);
  process.stderr.write(rigCheck.stderr);

  console.log('Roadie multisite E2E provenance, generated recipe, and browser schemas passed static validation.');
} finally {
  await rm(temporaryRoot, { recursive: true, force: true });
}

function run(command, args, extraEnv = {}) {
  const result = spawnSync(command, args, {
    encoding: 'utf8',
    env: { ...process.env, ...extraEnv },
  });
  if (result.error) {
    throw result.error;
  }
  if (result.status !== 0) {
    throw new Error(`${command} ${args.join(' ')} exited with status ${result.status}:\n${result.stdout || ''}${result.stderr || ''}`);
  }
  return result;
}
