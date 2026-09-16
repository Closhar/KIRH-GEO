import {api} from './api';

/** Reverb/Pusher protocol 7. Receives invalidations only; GPS always comes from the authorized API. */
export function subscribeToChanges(userId: string, invalidate: () => void): () => void {
  const origin = process.env.EXPO_PUBLIC_REVERB_URL;
  const key = process.env.EXPO_PUBLIC_REVERB_APP_KEY;
  if (!origin || !key || (!__DEV__ && !origin.startsWith('wss://'))) return () => {};
  let stopped = false;
  let socket: WebSocket | undefined;
  let retry: ReturnType<typeof setTimeout> | undefined;
  let heartbeat: ReturnType<typeof setInterval> | undefined;
  let attempts = 0;
  let lastMessage = Date.now();
  const channel = `private-users.${userId}`;
  function connect() {
    if (stopped) return;
    const ws = new WebSocket(`${origin!.replace(/\/$/, '')}/app/${encodeURIComponent(key!)}?protocol=7&client=kirh-geo&version=1.0`);
    socket = ws;
    let activitySeconds = 120;
    lastMessage = Date.now();
    heartbeat = setInterval(() => {
      if (ws.readyState !== WebSocket.OPEN) return;
      if (Date.now() - lastMessage > (activitySeconds + 30) * 1000) ws.close();
      else if (Date.now() - lastMessage > activitySeconds * 1000) ws.send(JSON.stringify({event: 'pusher:ping', data: {}}));
    }, 15000);
    ws.onmessage = event => {
      lastMessage = Date.now();
      void (async () => {
        const message = JSON.parse(String(event.data));
        const data = typeof message.data === 'string' ? JSON.parse(message.data) : message.data;
        if (message.event === 'pusher:connection_established') {
          if (!/^\d+\.\d+$/.test(data.socket_id)) throw new Error('Invalid socket');
          activitySeconds = Math.max(15, Math.min(120, Number(data.activity_timeout) || 120));
          const auth = await api<{auth: string}>('broadcasting/auth', 'POST', {socket_id: data.socket_id, channel_name: channel});
          if (stopped || socket !== ws || ws.readyState !== WebSocket.OPEN) return;
          ws.send(JSON.stringify({event: 'pusher:subscribe', data: {channel, auth: auth.auth}}));
        } else if (message.event === 'pusher:ping') {
          ws.send(JSON.stringify({event: 'pusher:pong', data: {}}));
        } else if (message.event === 'pusher_internal:subscription_succeeded') {
          attempts = 0;
          invalidate();
        } else if (message.event === 'geo.event' && message.channel === channel) {
          invalidate();
        } else if (message.event === 'pusher:error') ws.close();
      })().catch(() => ws.close());
    };
    ws.onerror = () => ws.close();
    ws.onclose = () => {
      if (heartbeat) clearInterval(heartbeat);
      if (!stopped) retry = setTimeout(connect, Math.min(60000, 1000 * 2 ** Math.min(attempts++, 6)));
    };
  }
  connect();
  return () => { stopped = true; if (retry) clearTimeout(retry); if (heartbeat) clearInterval(heartbeat); socket?.close(); };
}
