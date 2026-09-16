import { describe, expect, it } from 'vitest';
import { routeSegments } from './routeSegments';
import type { Point } from './types';

const point = (minutes: number): Point => ({id: String(minutes), latitude: 55.7, longitude: 37.6,
  captured_at: new Date(Date.UTC(2026, 8, 15, 12, minutes)).toISOString(), accuracy_m: 10, battery_pct: 50});

describe('history route continuity', () => {
  it('draws separate observed segments before and after a period with no GPS', () => {
    const segments = routeSegments([point(0), point(1), point(45), point(46)]);
    expect(segments.map(segment => segment.map(p => p.id))).toEqual([['0', '1'], ['45', '46']]);
  });
  it('never draws through an invalid point or a backwards clock jump', () => {
    const invalid = {...point(2), captured_at: 'invalid'};
    expect(routeSegments([point(0), point(1), invalid, point(4), point(5)]).map(segment => segment.length)).toEqual([2,2]);
    expect(routeSegments([point(10), point(11), point(3)])).toHaveLength(1);
  });
});
