/**
 * Lint all PHP files via php -l in WASM.
 */

import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import fs from 'node:fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');

const runtime = await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 100 } });
const php = new PHP(runtime);
useHostFilesystem(php);

function walk(dir, out=[]) {
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, ent.name);
    if (ent.isDirectory()) {
      if (['node_modules','.git','storage','vendor'].includes(ent.name)) continue;
      walk(full, out);
    } else if (ent.name.endsWith('.php')) out.push(full);
  }
  return out;
}

const files = walk(ROOT);
let errors = 0;
for (const file of files) {
  const rel = path.relative(ROOT, file).replaceAll(path.sep, '/');
  const abs = '/' + rel;
  // Ensure file is visible at / in wasm (useHostFilesystem mounts root)
  const result = await php.cli(['-l', path.join(ROOT, rel)]);
  const stdout = await result.text || '';
  if (result.exitCode !== 0 || stdout.includes('Parse error') || stdout.includes('Errors parsing')) {
    console.error(`✗ ${rel}: ${stdout.trim()} ${result.errors||''}`);
    errors++;
  }
}
if (errors === 0) console.log(`✓ All ${files.length} PHP files lint OK`);
else { console.log(`\n${errors} file(s) failed lint`); process.exit(1); }
