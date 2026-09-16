export interface Receipt {client_batch_id: string; results: {client_point_id: string; status: string; code?: string}[]}
export function validateReceipt(expectedBatch: {client_batch_id: string; points: {client_point_id: string}[]}, receipt: Receipt): string[] {
  const expected = new Set(expectedBatch.points.map(point => point.client_point_id));
  if (receipt.client_batch_id !== expectedBatch.client_batch_id || !Array.isArray(receipt.results) || receipt.results.length !== expected.size ||
    new Set(receipt.results.map(r => r.client_point_id)).size !== expected.size ||
    receipt.results.some(r => !expected.has(r.client_point_id) || !['accepted', 'duplicate', 'permanently_rejected'].includes(r.status))) {
    throw new Error('Некорректное подтверждение сервера. Очередь сохранена.');
  }
  return receipt.results.map(result => result.client_point_id);
}
