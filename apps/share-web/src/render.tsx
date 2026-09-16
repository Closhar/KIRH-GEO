import { createRoot } from 'react-dom/client';
import { App } from './App';
import { ShareSession } from './share-session';

export function renderApp(token: string | null) {
  const base = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/$/, '');
  const api = new URL(base, window.location.origin);
  if (api.protocol !== 'https:' && !(api.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(api.hostname))) {
    document.getElementById('root')!.textContent = 'Для безопасного просмотра требуется HTTPS.';
    return;
  }
  const session = new ShareSession(token, api.toString().replace(/\/$/, ''));
  // BFCache must not restore previously visible coordinates after navigation.
  window.addEventListener('pagehide', () => session.close());
  createRoot(document.getElementById('root')!).render(<App session={session} />);
}
