import type { Point } from './types';

/** Gaps in GPS collection are unknown travel, so never draw a connecting path over them. */
export function routeSegments(points: Point[], maxGapMs = 15 * 60 * 1000): Point[][] {
  const segments: Point[][] = [];
  let segment: Point[] = [];
  for (const point of points) {
    const previous = segment[segment.length - 1];
    const timestamp = Date.parse(point.captured_at);
    const valid = Number.isFinite(timestamp) && Number.isFinite(Number(point.latitude)) && Number.isFinite(Number(point.longitude));
    const gap = previous ? timestamp - Date.parse(previous.captured_at) : 0;
    if (!valid || gap > maxGapMs || gap < 0) {
      if (segment.length > 1) segments.push(segment);
      segment = [];
    }
    if (valid) segment.push(point);
  }
  if (segment.length > 1) segments.push(segment);
  return segments;
}
