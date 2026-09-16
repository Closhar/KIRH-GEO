# Temporary share viewer

React/TypeScript/Vite viewer consumes the actual public Laravel routes. Link format: `https://share.example.org/#<64-hex-token>`. The fragment and query are removed synchronously before app/map loading. Original secret is used only in the HTTPS POST exchange body; the returned limited bearer stays in memory. No analytics, service worker, localStorage, sessionStorage or external default tile provider.

```powershell
npm ci
npm test
npm run build
npm run dev
```

Set VITE_API_BASE_URL (default `/api/v1`) and optionally VITE_MAP_STYLE_URL pointing to an approved MapLibre style. Only public map credentials restricted by origin belong in frontend environment variables; never provider server secrets. With no style configured the app displays actual coordinates, captured/received timestamps, accuracy and battery and explains that the map is unavailable. It never substitutes a decorative map for geographic data.

The viewer polls every 10 seconds. Expired/revoked/forbidden access clears coordinates, session and pending requests. Transient network failure labels the last known position; local capability expiry still clears it. Capability lasts at most 15 minutes: reopen the original link to continue while its underlying share remains valid. Wrong passcode and unavailable share deliberately look the same; reopen the original link to retry.

Production reverse proxy must send `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`, `X-Content-Type-Options: nosniff`, `frame-ancestors 'none'` in CSP, and allow only the configured API/map origins in connect-src. HTML meta policies and dev/preview headers are supplied; production HTTP headers are the host's responsibility. Avoid logging POST bodies and Authorization. Referer must never be forwarded to the map provider. Map tiles themselves reveal viewed geographic areas; choose a provider consistent with the product privacy policy.

Tests cover stripping URL secrets, constrained bearer transport, revocation, local expiry during network loss, null position and stale in-flight response suppression. Map rendering requires configured provider and WebGL; that integration remains a deployment acceptance check.
