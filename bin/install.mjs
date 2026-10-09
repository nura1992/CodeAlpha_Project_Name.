/**
 * Node harness for bin/install.php — runs the PHP installer via WASM
 * so no system PHP binary is required.
 *
 *   node bin/install.mjs
 *   node bin/install.mjs --refresh
 */

import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = path.resolve(__dirname, '..');
const args = process.argv.slice(2).join(' ');

const runtime = await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 99 } });
const php = new PHP(runtime);
useHostFilesystem(php);
php.chdir(PROJECT_ROOT);

// Pass through CLI args as $argv
const code = `<?php
$argv = array_merge(['bin/install.php'], explode(' ', trim('${args.replace(/'/g, "\\'")}')));
$argc = count($argv);
include 'bin/install.php';
`;

const result = await php.run({ code, relativeUri: '/bin/install.php' });
const text = await result.text;
process.stdout.write(text);
if (result.errors) process.stderr.write(result.errors);
process.exit(result.exitCode ?? 0);
