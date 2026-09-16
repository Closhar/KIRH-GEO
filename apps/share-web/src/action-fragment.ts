export type IdentityAction = {purpose: 'verify_email' | 'reset_password'; token: string};

export function takeIdentityAction(location: Pick<Location, 'hash' | 'pathname'>, history: Pick<History, 'replaceState'>): IdentityAction | null {
  const values = new URLSearchParams(location.hash.replace(/^#/, ''));
  const purpose = values.get('action');
  const token = values.get('token');
  if ((purpose !== 'verify_email' && purpose !== 'reset_password') || !/^[a-f0-9]{64}$/.test(token ?? '')) return null;
  history.replaceState(null, '', location.pathname);
  return {purpose, token: token!};
}
