import { access, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = path.dirname(fileURLToPath(import.meta.url));

export const componentFiles = {
  'agents-api': 'agents-api.php',
  'data-machine': 'data-machine.php',
  'frontend-agent-chat': 'frontend-agent-chat.php',
  'extrachill-users': 'extrachill-users.php',
  'extrachill-artist-platform': 'extrachill-artist-platform.php',
  'data-machine-events': 'data-machine-events.php',
  'extrachill-events': 'extrachill-events.php',
  'extrachill-roadie': 'extrachill-roadie.php',
};

export async function readComponents(file = process.env.ROADIE_E2E_COMPONENTS_FILE) {
  if (!file) {
    throw new Error('ROADIE_E2E_COMPONENTS_FILE must name a JSON component manifest.');
  }

  const manifest = JSON.parse(await readFile(path.resolve(file), 'utf8'));
  for (const [slug, pluginFile] of Object.entries(componentFiles)) {
    const component = manifest[slug];
    if (!component || typeof component.path !== 'string' || !path.isAbsolute(component.path)) {
      throw new Error(`${slug}.path must be an absolute component checkout path.`);
    }
    if (typeof component.version !== 'string' || component.version.trim() === '') {
      throw new Error(`${slug}.version must record the mounted component revision.`);
    }
    await access(path.join(component.path, pluginFile));
  }

  return manifest;
}

export function buildSettings(components, wordpressVersion) {
  if (typeof wordpressVersion !== 'string' || wordpressVersion.trim() === '') {
    throw new Error('ROADIE_E2E_WORDPRESS_VERSION must explicitly select WordPress.');
  }

  const fixture = path.join(root, 'fixture');
  return {
    wordpress_runtime_version: wordpressVersion,
    roadie_e2e_component_versions: Object.fromEntries(
      Object.keys(componentFiles).map((slug) => [slug, components[slug].version]),
    ),
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
      })),
    ],
    wordpress_runtime_prepare_steps: [
      phpStep('activate.php'),
      phpStep('seed.php'),
    ],
    wp_codebox_scenario_manifests: [
      path.join(root, 'browser-anonymous.json'),
      path.join(root, 'browser-authenticated.json'),
    ],
    wordpress_runtime_post_steps: [phpStep('assert.php')],
  };
}

function phpStep(file) {
  return {
    command: 'wordpress.run-php',
    args: [`code-file=${path.join(root, file)}`],
  };
}
