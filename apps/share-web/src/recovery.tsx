import {FormEvent, useState} from 'react';
import {createRoot} from 'react-dom/client';

const apiBase = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.replace(/\/$/, '') ?? '/api/v1';

function Recovery({purpose, token}: {purpose: 'verify_email' | 'reset_password'; token: string}) {
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [status, setStatus] = useState<'ready' | 'busy' | 'done' | 'error'>('ready');
  const [message, setMessage] = useState('');
  async function submit(event: FormEvent) {
    event.preventDefault();
    if (purpose === 'reset_password' && (password.length < 12 || !/[a-zа-я]/i.test(password) || !/\d/.test(password) || password !== confirmation)) {
      setMessage('Пароли должны совпадать и содержать не менее 12 символов, буквы и цифры.'); setStatus('error'); return;
    }
    setStatus('busy'); setMessage('');
    try {
      const response = await fetch(`${apiBase}/auth/${purpose === 'verify_email' ? 'email/verify' : 'reset-password'}`, {
        method: 'POST', headers: {Accept: 'application/json', 'Content-Type': 'application/json'}, cache: 'no-store', credentials: 'omit', referrerPolicy: 'no-referrer',
        body: JSON.stringify(purpose === 'verify_email' ? {token} : {token, password, password_confirmation: confirmation}),
      });
      const result = await response.json().catch(() => null) as {error?: {message?: string}} | null;
      if (!response.ok) throw new Error(result?.error?.message ?? 'Ссылка недействительна или уже использована.');
      setStatus('done'); setPassword(''); setConfirmation('');
    } catch (error) {setStatus('error'); setMessage(error instanceof Error ? error.message : 'Не удалось выполнить действие.');}
  }
  const verification = purpose === 'verify_email';
  return <main className="page"><header className="header"><a className="brand" href="/" aria-label="KIRH GEO"><img src="/brand/kt-geo-logo.png" alt=""/><span>KIRH<span className="brand-light"> GEO</span></span></a><span className="privacy-pill">Защищённое действие</span></header>
    <section className="intro"><div className="eyebrow">БЕЗОПАСНОСТЬ АККАУНТА</div><h1>{verification ? 'Подтвердите' : 'Задайте новый'}<br/><span>{verification ? 'адрес почты' : 'пароль'}</span></h1></section>
    <section className="access-card" aria-live="polite"><div className="card-icon" aria-hidden="true">◇</div>
      {status === 'done' ? <><h2>Готово</h2><p>{verification ? 'Адрес подтверждён. Вернитесь в приложение.' : 'Пароль изменён, все устройства и разрешения передачи отозваны. Войдите заново и дайте новое согласие при необходимости.'}</p></> : <form onSubmit={submit}><h2>{verification ? 'Подтверждение почты' : 'Восстановление доступа'}</h2>
        {!verification && <><label htmlFor="password">Новый пароль</label><input id="password" type="password" autoComplete="new-password" value={password} onChange={event => setPassword(event.target.value)} maxLength={1024}/><label htmlFor="confirmation">Повторите пароль</label><input id="confirmation" type="password" autoComplete="new-password" value={confirmation} onChange={event => setConfirmation(event.target.value)} maxLength={1024}/></>}
        <button disabled={status === 'busy'}>{status === 'busy' ? 'Проверяем…' : verification ? 'Подтвердить адрес' : 'Сменить пароль'}</button>{message && <p role="alert" className="error">{message}</p>}</form>}
      <div className="microcopy">Одноразовый код удалён из адресной строки и не передаётся сторонним сервисам.</div>
    </section><footer><div className="footer-line"/><p>Ваши люди. Ваш выбор.</p></footer></main>;
}

export function renderRecovery(purpose: 'verify_email' | 'reset_password', token: string): void {
  createRoot(document.getElementById('root')!).render(<Recovery purpose={purpose} token={token}/>);
}
