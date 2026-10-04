#!/usr/bin/env node
/**
 * Builds one deployable folder for shared hosting:
 *
 *   dist/kaneas/
 *     index.html, *.js, *.css, i18n/   Angular production build
 *     .htaccess                        SPA routing (RewriteBase set by the installer)
 *     api/                             PHP API (no config.php)
 *     install/                         web installer
 *
 * Upload the contents of dist/kaneas to any folder on the host, then open <url>/install/.
 *
 *   node scripts/build.mjs          build the folder
 *   node scripts/build.mjs --zip    also create dist/kaneas-<version>.zip
 */
import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { basename, dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const frontend = join(root, 'frontend');
const backend = join(root, 'backend');
const outDir = join(root, 'dist', 'kaneas');
const zip = process.argv.includes('--zip');

const version = /KANEAS_VERSION = '([^']+)'/.exec(readFileSync(join(backend, 'api/src/bootstrap.php'), 'utf8'))?.[1] ?? 'dev';

function step(message) {
  console.log(`\n\x1b[36m▸ ${message}\x1b[0m`);
}

function run(command, args, cwd) {
  // npm/npx are .cmd shims on Windows and need a shell; args here are fixed, never user input.
  const result =
    process.platform === 'win32'
      ? spawnSync([command, ...args.map((a) => (a.includes(' ') ? `"${a}"` : a))].join(' '), { cwd, stdio: 'inherit', shell: true })
      : spawnSync(command, args, { cwd, stdio: 'inherit' });
  if (result.status !== 0) {
    console.error(`\x1b[31m✖ ${command} ${args.join(' ')} failed\x1b[0m`);
    process.exit(result.status ?? 1);
  }
}

/** Files that must never end up in a release. */
function shouldCopy(source) {
  const rel = relative(backend, source).replaceAll('\\', '/');
  const name = basename(source);
  return !(
    rel === 'api/config/config.php' ||
    rel === 'install/.htaccess' || // lock file written after a (dev) install
    name.endsWith('.tmp') ||
    name === '.DS_Store'
  );
}

step(`Kaneas ${version} — cleaning ${relative(root, outDir)}`);
rmSync(outDir, { recursive: true, force: true });
mkdirSync(outDir, { recursive: true });

step('Building Angular frontend (production)');
if (!existsSync(join(frontend, 'node_modules'))) {
  run('npm', ['ci'], frontend);
}
run('npx', ['ng', 'build', '--configuration', 'production'], frontend);

step('Copying frontend');
cpSync(join(frontend, 'dist', 'frontend', 'browser'), outDir, { recursive: true });
cpSync(join(root, 'deploy', 'htaccess'), join(outDir, '.htaccess'));

step('Copying PHP API and installer');
cpSync(join(backend, 'api'), join(outDir, 'api'), { recursive: true, filter: shouldCopy });
cpSync(join(backend, 'install'), join(outDir, 'install'), { recursive: true, filter: shouldCopy });
writeFileSync(join(outDir, 'VERSION'), `${version}\n`);

if (existsSync(join(outDir, 'api', 'config', 'config.php'))) {
  console.error('\x1b[31m✖ config.php leaked into the build\x1b[0m');
  process.exit(1);
}

if (zip) {
  const zipFile = join(root, 'dist', `kaneas-${version}.zip`);
  step(`Creating ${relative(root, zipFile)}`);
  rmSync(zipFile, { force: true });
  if (process.platform === 'win32') {
    // bsdtar ships with Windows 10+ and writes zip files with -a.
    run('tar', ['-a', '-c', '-f', zipFile, '-C', outDir, '.'], root);
  } else {
    run('zip', ['-qr', zipFile, '.'], outDir);
  }
}

console.log(`\n\x1b[32m✔ Ready: ${relative(root, outDir)}\x1b[0m`);
console.log('  Upload its contents to your hosting (any folder) and open <your-url>/install/');
