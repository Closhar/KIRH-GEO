import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import SwaggerParser from '@apidevtools/swagger-parser';

const spec = JSON.parse(readFileSync(new URL('../openapi.json', import.meta.url)));
test('OpenAPI document validates and all references resolve', async () => {
  await SwaggerParser.validate(structuredClone(spec));
  await SwaggerParser.validate(JSON.parse(readFileSync(new URL('../openapi.active.json', import.meta.url))));
});
test('generated active contract excludes planned routes', () => {
  const active = JSON.parse(readFileSync(new URL('../openapi.active.json', import.meta.url)));
  assert.equal(active.paths['/admin/location-policy'], undefined);
  for (const operations of Object.values(active.paths)) {
    for (const operation of Object.values(operations)) assert.notEqual(operation['x-implementation-status'], 'planned');
  }
  assert.equal(active.paths['/workspaces/{workspace}/billing/restore'].post['x-implementation-status'], 'disabled-provider-gate');
});
test('all operations have unique IDs, error envelope and explicit auth decision', () => {
  const ids = new Set();
  for (const operations of Object.values(spec.paths)) {
    for (const operation of Object.values(operations)) {
      assert.equal(ids.has(operation.operationId), false);
      ids.add(operation.operationId);
      assert.ok(Array.isArray(operation.security));
      assert.equal(operation.responses['403'].$ref, '#/components/responses/Error');
    }
  }
  assert.ok(ids.size >= 55);
});
test('GPS batches and mutual visibility have privacy bounds', () => {
  assert.equal(spec.components.schemas.LocationBatch.properties.points.maxItems, 500);
  assert.equal(spec.components.schemas.LocationBatch.additionalProperties, false);
  assert.deepEqual(spec.components.schemas.BatchReceipt.properties.results.items.properties.status.enum, ['accepted', 'duplicate', 'permanently_rejected']);
  assert.ok(spec.paths['/workspaces/{workspace}/groups/{group}/visibility'].put.description.includes('Does not create consent'));
  assert.ok(spec.paths['/workspaces/{workspace}/sharing-grants'].post.requestBody.required);
});
