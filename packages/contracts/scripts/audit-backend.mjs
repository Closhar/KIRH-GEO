import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const output = execFileSync(process.env.PHP_BINARY || 'php', ['-d', 'extension=intl', 'artisan', 'route:list', '--path=api/v1', '--json'], {
  cwd: fileURLToPath(new URL('../../../apps/api/', import.meta.url)), encoding: 'utf8', windowsHide: true,
});
const routes = JSON.parse(output.slice(output.indexOf('['))).flatMap(route => route.method.split('|').filter(method => method !== 'HEAD').map(method => ({ method: method.toLowerCase(), path: `/${route.uri.replace(/^api\/v1\//, '')}` })));
writeFileSync(new URL('../backend-routes.json', import.meta.url), `${JSON.stringify(routes, null, 2)}\n`);
const normalize = path => path.replace(/\{[^}]+\}/g, '{}');
const spec = JSON.parse(readFileSync(new URL('../openapi.json', import.meta.url)));
const described = new Set(Object.entries(spec.paths).flatMap(([path, methods]) => Object.keys(methods).map(method => `${method} ${normalize(path)}`)));
const missing = routes.filter(route => !described.has(`${route.method} ${normalize(route.path)}`));
if (missing.length) {
  process.stderr.write(`Backend routes missing from contract: ${JSON.stringify(missing)}\n`);
  process.exitCode = 1;
} else {
  process.stdout.write(`All ${routes.length} registered backend operations are described. Registration does not prove functional acceptance.\n`);
}
