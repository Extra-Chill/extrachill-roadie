import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { access, lstat, readFile, readdir, readlink, realpath } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const immutableRevision = /^(?:[0-9a-f]{40}|[0-9a-f]{64})$/i;

export const root = path.dirname(fileURLToPath(import.meta.url));
export const repositoryRoot = path.resolve(root, '../../..');

export const componentFiles = {
  'agents-api': 'agents-api.php',
  'data-machine': 'data-machine.php',
  'frontend-agent-chat': 'frontend-agent-chat.php',
  'extrachill-network': 'extrachill-network.php',
  'extrachill-api': 'extrachill-api.php',
  'extrachill-users': 'extrachill-users.php',
  'extrachill-artist-platform': 'extrachill-artist-platform.php',
  'data-machine-events': 'data-machine-events.php',
  'extrachill-events': 'extrachill-events.php',
  'extrachill-roadie': 'extrachill-roadie.php',
};

export async function readComponents(file = process.env.ROADIE_E2E_COMPONENTS_FILE, { enforceRoadieRoot = true } = {}) {
  if (!file) {
    throw new Error('ROADIE_E2E_COMPONENTS_FILE must name a JSON component manifest.');
  }

  const manifest = JSON.parse(await readFile(path.resolve(file), 'utf8'));
  if (enforceRoadieRoot) {
    const declaredRoadieRoot = await realpath(manifest['extrachill-roadie']?.path || '').catch(() => '');
    const actualRoadieRoot = await realpath(repositoryRoot);
    if (declaredRoadieRoot !== actualRoadieRoot) {
      throw new Error(`extrachill-roadie.path must be the checkout running this harness: ${actualRoadieRoot}.`);
    }
  }
  for (const [slug, pluginFile] of Object.entries(componentFiles)) {
    const component = manifest[slug];
    if (!component || typeof component.path !== 'string' || !path.isAbsolute(component.path)) {
      throw new Error(`${slug}.path must be an absolute component checkout path.`);
    }
    if (typeof component.version !== 'string' || !immutableRevision.test(component.version.trim())) {
      throw new Error(`${slug}.version must be a full immutable Git revision.`);
    }
    await access(path.join(component.path, pluginFile));

    let head;
    let dirty;
    try {
      head = (await execFileAsync('git', ['-C', component.path, 'rev-parse', '--verify', 'HEAD^{commit}'])).stdout.trim();
      dirty = (await execFileAsync('git', ['-C', component.path, 'status', '--porcelain=v1'])).stdout.trim() !== '';
    } catch (error) {
      throw new Error(`${slug}.path must be a Git checkout with a resolvable HEAD.`, { cause: error });
    }
    if (component.version.toLowerCase() !== head.toLowerCase()) {
      throw new Error(`${slug}.version ${component.version} does not match checkout HEAD ${head}.`);
    }
    if (dirty) {
      throw new Error(`${slug}.path has uncommitted changes; mounted content would not match immutable revision ${head}.`);
    }

    manifest[slug] = {
      ...component,
      revision: head.toLowerCase(),
      dirty: false,
      contentSha256: await digestDirectory(component.path),
    };
  }

  return manifest;
}

export function buildSettings(components, wordpressVersion) {
  if (typeof wordpressVersion !== 'string' || wordpressVersion.trim() === '') {
    throw new Error('ROADIE_E2E_WORDPRESS_VERSION must explicitly select WordPress.');
  }

  const fixture = path.join(root, 'fixture');
  const provenance = buildProvenance(components, wordpressVersion);
  return {
    wordpress_runtime_version: wordpressVersion,
    wp_codebox_extra_plugins: [
      {
        source: fixture,
        slug: '00-roadie-multisite-fixture',
        pluginFile: '00-roadie-multisite-fixture/roadie-multisite-fixture.php',
        activate: false,
      },
      ...Object.entries(componentFiles).map(([slug, pluginFile]) => ({
        source: components[slug].path,
        slug,
        pluginFile: `${slug}/${pluginFile}`,
        activate: false,
        metadata: {
          provenance: {
            schema: 'extrachill-roadie/component-checkout/v1',
            component: slug,
            revision: components[slug].revision,
            content_sha256: components[slug].contentSha256,
            dirty: components[slug].dirty === true,
          },
        },
      })),
    ],
    wordpress_runtime_prepare_steps: [
      phpStep('activate.php', { roadie_e2e_provenance: provenance }),
      phpStep('seed.php'),
    ],
    wp_codebox_scenario_manifests: [
      path.join(root, 'browser-anonymous.json'),
      path.join(root, 'browser-authenticated.json'),
    ],
    wordpress_runtime_post_steps: [phpStep('assert.php')],
  };
}

export function buildProvenance(components, wordpressVersion) {
  return {
    schema: 'extrachill-roadie/multisite-component-provenance/v1',
    wordpress: wordpressVersion,
    components: Object.keys(componentFiles).map((slug) => ({
      slug,
      revision: components[slug].revision,
      content_sha256: components[slug].contentSha256,
      dirty: components[slug].dirty === true,
    })),
  };
}

async function digestDirectory(directory) {
  const hash = createHash('sha256');
  await digestEntries(directory, '', hash);
  return hash.digest('hex');
}

async function digestEntries(directory, relative, hash) {
  const entries = await readdir(path.join(directory, relative), { withFileTypes: true });
  entries.sort((left, right) => left.name.localeCompare(right.name));
  for (const entry of entries) {
    if (entry.name === '.git' && relative === '') {
      continue;
    }
    const childRelative = path.join(relative, entry.name);
    const childPath = path.join(directory, childRelative);
    const stat = await lstat(childPath);
    hash.update(`${childRelative.split(path.sep).join('/')}\0${stat.mode.toString(8)}\0`);
    if (stat.isDirectory()) {
      await digestEntries(directory, childRelative, hash);
    } else if (stat.isSymbolicLink()) {
      hash.update(`link\0${await readlink(childPath)}\0`);
    } else if (stat.isFile()) {
      hash.update(await readFile(childPath));
      hash.update('\0');
    }
  }
}

function phpStep(file, metadata) {
  const step = {
    command: 'wordpress.run-php',
    args: [`code-file=${path.join(root, file)}`],
  };
  if (metadata) {
    step.metadata = metadata;
  }
  return step;
}
