/**
 * Development server — serves the PHP application via @php-wasm without
 * requiring a system PHP binary or MySQL server.
 *
 *   npm run dev                 -> http://localhost:8000
 *   PORT=4000 npm run dev
 *
 * The server mounts the project directory into the WASM filesystem
 * (useHostFilesystem) so file changes are reflected without a restart.
 * Sessions are stored on the host filesystem and survive reloads.
 */

import http from 'node:http';
import { PHP, PHPRequestHandler } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = path.resolve(__dirname, '..');
const PORT = parseInt(process.env.PORT || '8000', 10);
const HOST = process.env.HOST || '0.0.0.0';

console.log('Starting PHP runtime (WASM) — this takes ~2s on first load...');

const runtime = await loadNodeRuntime('8.3', {
  emscriptenOptions: { processId: 42 },
});
const php = new PHP(runtime);
useHostFilesystem(php);
php.chdir(PROJECT_ROOT);

const handler = new PHPRequestHandler({
  php,
  documentRoot: path.join(PROJECT_ROOT, 'public'),
  absoluteUrl: `http://localhost:${PORT}`,
  cookieStore: false,
  getFileNotFoundAction: (relativePath) => {
    // Static assets should 404 if not found; everything else goes to the front controller
    if (relativePath.startsWith('/assets/') || relativePath.startsWith('/uploads/')) {
      return { type: '404' };
    }
    return { type: 'internal-redirect', uri: '/index.php' };
  },
});

const server = http.createServer(async (req, res) => {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  const body = Buffer.concat(chunks);

  // Build headers map for PHP
  const headers = {};
  for (const [k, v] of Object.entries(req.headers)) {
    headers[k] = Array.isArray(v) ? v.join(', ') : v ?? '';
  }

  // Host header must match absoluteUrl host for PHP $_SERVER
  if (!headers['host']) headers['host'] = `localhost:${PORT}`;

  try {
    const url = `http://localhost:${PORT}${req.url}`;
    const response = await handler.request({
      url,
      method: req.method,
      headers,
      ...(body.length ? { body } : {}),
    });

    // Forward Set-Cookie to browser
    const setCookies = response.headers['set-cookie'] || response.headers['Set-Cookie'] || [];
    for (const sc of setCookies) {
      res.appendHeader('Set-Cookie', sc);
    }

    res.statusCode = response.httpStatusCode ?? 200;
    for (const [k, vals] of Object.entries(response.headers)) {
      if (k.toLowerCase() === 'set-cookie') continue;
      for (const v of vals) res.appendHeader(k, v);
    }
    res.end(Buffer.from(response.bytes));
  } catch (e) {
    console.error('Request error:', e);
    res.statusCode = 500;
    res.setHeader('Content-Type', 'text/plain');
    res.end('Internal Server Error\n' + (e.stack || e.message));
  }
});

server.listen(PORT, HOST, async () => {
  const preview = process.env.E2B_SANDBOX_ID ? `https://${PORT}-${process.env.E2B_SANDBOX_ID}.e2b.app` : `http://localhost:${PORT}`;
  console.log(`\n  CodeAlpha dev server running`);
  console.log(`  Local:   http://localhost:${PORT}`);
  console.log(`  Network: http://${HOST}:${PORT}`);
  if (process.env.E2B_SANDBOX_ID) console.log(`  Preview: ${preview}`);
  console.log(`  Document root: ${path.join(PROJECT_ROOT, 'public')}`);
  try { const r = await php.run({ code: '<?php echo PHP_VERSION;' }); console.log(`  PHP: ${await r.text}`); } catch { console.log('  PHP: wasm'); }
  console.log(`\n  Demo accounts (after bin/install.php):`);
  console.log(`    super@codealpha.local / SuperAdmin123!  (Super Admin)`);
  console.log(`    admin@codealpha.local / Admin123!`);
  console.log(`    finance@codealpha.local / Finance123!`);
  console.log(`    support@codealpha.local / Support123!`);
  console.log(`    ada@example.com / Demo1234!  (member)`);
  console.log('');
});
