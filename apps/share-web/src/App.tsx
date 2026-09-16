import { lazy, Suspense, useState, useSyncExternalStore } from 'react';
import type { FormEvent } from 'react';
import { ShareSession } from './share-session';

const LocationMap = lazy(() => import('./LocationMap'));
const displayTime = (value: string) => new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'medium' }).format(new Date(value));

export function App({ session }: { session: ShareSession }) {
  const state = useSyncExternalStore(session.subscribe, session.getState);
  const [passcode, setPasscode] = useState('');
  const styleUrl = import.meta.env.VITE_MAP_STYLE_URL as string | undefined;
  const active = state.status === 'active' || state.status === 'offline';
  const point = state.point;
  const stale = point && Date.now() - Date.parse(point.captured_at) > 180000;
  const open = (event: FormEvent) => {
    event.preventDefault();
    const value = passcode;
    setPasscode('');
    void session.open(value);
  };

  return <main className="page">
    <header className="header"><a className="brand" href="/" aria-label="KIRH GEO — начало"><img src="/brand/kt-geo-logo.png" alt="" /><span>KIRH<span className="brand-light"> GEO</span></span></a><span className="privacy-pill"><span aria-hidden="true">◈</span> По добровольному приглашению</span></header>
    <section className="intro"><div className="eyebrow">БЛИЖЕ, ДАЖЕ НА РАССТОЯНИИ</div><h1>На связи.<br /><span>На одной карте.</span></h1><p>Временный доступ к геопозиции человека,<br className="desktop-break" /> который решил поделиться ею с вами.</p></section>
    {(state.status === 'ready' || state.status === 'opening') && <section className="access-card" aria-labelledby="access-title"><div className="card-icon" aria-hidden="true">↗</div><h2 id="access-title">Вам открыли геопозицию</h2><p>Доступ действует ограниченное время. Владелец может завершить его в любой момент.</p><form onSubmit={open}><label htmlFor="passcode">Код доступа <span>если его прислали вместе со ссылкой</span></label><input id="passcode" type="password" autoComplete="off" maxLength={64} value={passcode} onChange={e => setPasscode(e.target.value)} placeholder="Необязательно" /><button disabled={state.status === 'opening'}>{state.status === 'opening' ? 'Открываем…' : 'Посмотреть геопозицию'} <span aria-hidden="true">→</span></button></form>{state.message && <p role="alert" className="error">{state.message}</p>}<div className="microcopy">Ваша геопозиция не запрашивается.</div></section>}
    {active && <section className="location-card" aria-label="Текущая геопозиция"><div className="location-heading"><div><span className={`status-dot ${stale || state.status === 'offline' ? 'stale' : ''}`} />{state.status === 'offline' ? 'Нет связи' : stale ? 'Последнее известное место' : 'Геопозиция доступна'}</div><button className="text-button" onClick={() => session.close()}>Закрыть доступ</button></div>{state.message && <p className="banner" role="status">{state.message}</p>}{point ? <><div className="map-container">{styleUrl ? <Suspense fallback={<div className="map-placeholder">Загружаем карту…</div>}><LocationMap point={point} styleUrl={styleUrl} /></Suspense> : <div className="coordinates-panel"><div className="coordinate-icon" aria-hidden="true">⌖</div><span>Координаты участника</span><strong>{point.latitude.toFixed(6)}<br />{point.longitude.toFixed(6)}</strong><p>Карта пока недоступна.<br />Координаты продолжают обновляться.</p></div>}</div><div className="metrics"><div><span>Последняя координата</span><strong>{displayTime(point.captured_at)}</strong></div><div><span>Точность GPS</span><strong>± {Math.round(point.accuracy_m)} м</strong></div><div><span>Заряд при обновлении</span><strong>{point.battery_pct === null ? 'Неизвестен' : `${point.battery_pct}%`}</strong></div></div><p className="location-note">Обновлено на сервере: {displayTime(point.received_at)}. {stale ? 'Данные устарели — человек мог переместиться.' : 'GPS может определять положение с погрешностью.'}</p></> : <div className="empty-position"><span aria-hidden="true">⌖</span><h2>Ждём первую координату</h2><p>Позиция появится, когда устройство отправит обновление.</p></div>}<div className="session-note">Просмотр до {state.expiresAt ? new Date(state.expiresAt).toLocaleTimeString('ru-RU') : '—'} · Обновление каждые 10 секунд</div></section>}
    {['invalid', 'unavailable', 'expired'].includes(state.status) && <section className="access-card closed"><div className="card-icon" aria-hidden="true">◷</div><h2>{state.status === 'expired' ? 'Время просмотра закончилось' : state.status === 'invalid' ? 'Нужна ссылка-приглашение' : 'Доступ закрыт'}</h2><p>{state.status === 'invalid' ? 'Откройте полную ссылку, которую вам отправили из приложения KIRH GEO.' : 'Ссылка могла истечь или быть отозвана. Проверьте код доступа и снова откройте исходную ссылку либо попросите новую.'}</p><div className="microcopy">Геопозиция больше не отображается.</div></section>}
    <footer><div className="footer-line" /><p>Ваши люди. Ваш выбор.</p><span>Геопозиция передаётся только с согласия её владельца.</span></footer>
  </main>;
}
