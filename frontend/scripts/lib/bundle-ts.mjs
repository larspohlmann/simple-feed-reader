import { build } from 'esbuild';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const FRONTEND = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

export async function importBundledTs({ contents, cacheName, external = [] }) {
  const outfile = join(FRONTEND, 'node_modules', '.cache', cacheName, 'bundle.mjs');
  await build({
    stdin: { contents, resolveDir: FRONTEND, loader: 'ts' },
    bundle: true,
    format: 'esm',
    platform: 'node',
    external,
    outfile,
    logLevel: 'warning',
  });
  return import(pathToFileURL(outfile).href);
}
