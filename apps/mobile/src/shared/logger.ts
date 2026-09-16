type Level = 'debug' | 'info' | 'warn' | 'error';
const rank: Record<Level, number> = {debug: 10, info: 20, warn: 30, error: 40};
const configured = (process.env.EXPO_PUBLIC_LOG_LEVEL ?? (__DEV__ ? 'debug' : 'warn')) as Level;

/** Never pass coordinates, tokens, invitation/share secrets, email or payment data. */
export function log(level: Level, event: string, context: Record<string, string | number | boolean | null | undefined> = {}): void {
  if (rank[level] < (rank[configured] ?? rank.warn)) return;
  const safe = {event, ...context};
  if (level === 'error') console.error(safe);
  else if (level === 'warn') console.warn(safe);
  else if (__DEV__) console.log(safe);
}
