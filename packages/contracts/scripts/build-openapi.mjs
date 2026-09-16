// Authoritative API design. Generated openapi.json is consumed by clients and validators.
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
const str = (extra = {}) => ({ type: 'string', ...extra });
const uuid = str({ format: 'uuid' });
const time = str({ format: 'date-time' });
const integer = (minimum = 0, maximum) => ({ type: 'integer', minimum, ...(maximum === undefined ? {} : { maximum }) });
const number = (minimum, maximum) => ({ type: 'number', ...(minimum === undefined ? {} : { minimum }), ...(maximum === undefined ? {} : { maximum }) });
const bool = { type: 'boolean' };
const ref = name => ({ $ref: `#/components/schemas/${name}` });
const arr = (items, maxItems = 500) => ({ type: 'array', items, maxItems });
const obj = (properties, required = Object.keys(properties)) => ({ type: 'object', additionalProperties: false, properties, required });
const mode = str({ enum: ['idle', 'normal', 'live', 'sport', 'sos'] });
const idempotency = { name: 'Idempotency-Key', in: 'header', required: true, schema: uuid, description: 'Retry with the same key and same body. Different body returns 409.' };
const schemas = {
  Error: obj({ error: obj({ code: str(), message: str(), details: { type: 'object', additionalProperties: true } }, ['code', 'message']) }),
  User: { ...obj({ id: uuid, name: str(), email: { type: ['string', 'null'], format: 'email' } }, ['id', 'name']), additionalProperties: true },
  Credentials: obj({ email: str({ format: 'email' }), password: str({ minLength: 1, maxLength: 1024 }), installation_id: uuid, platform: str({ enum: ['android', 'ios', 'web'] }) }),
  Registration: obj({ name: str({ minLength: 1, maxLength: 120 }), email: str({ format: 'email' }), password: str({ minLength: 12, maxLength: 128 }), installation_id: uuid, platform: str({ enum: ['android', 'ios', 'web'] }) }),
  Session: obj({ session_id: uuid, device_id: uuid, access_token: str(), refresh_token: str(), expires_in: integer(1, 900), token_type: str({ const: 'Bearer' }), user: ref('User') }, ['session_id', 'device_id', 'access_token', 'refresh_token', 'expires_in', 'token_type']),
  DeviceInput: obj({ installation_id: uuid, platform: str({ enum: ['android', 'ios'] }), app_version: str({ maxLength: 40 }), name: str({ maxLength: 100 }) }),
  Device: { ...obj({ id: uuid, platform: str({ enum: ['android', 'ios', 'web'] }), installation_id: uuid, revoked_at: { type: ['string', 'null'], format: 'date-time' } }), additionalProperties: true },
  WorkspaceInput: obj({ name: str({ minLength: 1, maxLength: 120 }) }),
  Workspace: { ...obj({ id: uuid, name: str(), type: str(), owner_user_id: uuid }, ['id', 'name']), additionalProperties: true },
  Member: obj({ id: uuid, name: str(), joined_at: time }),
  Group: { ...obj({ id: uuid, workspace_id: uuid, name: str() }), additionalProperties: true },
  Invitation: obj({ id: uuid, code: str(), expires_at: time }),
  VisibilityRule: obj({ group_id: uuid, subject_user_id: uuid, viewer_user_id: uuid, allowed: bool }),
  VisibilityInput: obj({ subject_user_id: uuid, viewer_user_id: uuid, allowed: bool }),
  ConsentInput: obj({ viewer_ids: arr(uuid, 100), current_allowed: bool, history_allowed: bool, policy_version: str({ maxLength: 50 }), expected_revision: integer() }),
  Consent: obj({ subject_id: uuid, revision: integer(), viewer_ids: arr(uuid, 100), current_allowed: bool, history_allowed: bool, paused: bool, granted_at: time }),
  LocationPoint: obj({ client_point_id: uuid, captured_at: time, latitude: number(-90, 90), longitude: number(-180, 180), accuracy_m: number(0, 10000), battery_pct: integer(0, 100), mode, consent_revision: integer(1), altitude_m: number(), speed_mps: number(0, 400), heading: number(0, 360) }, ['client_point_id', 'captured_at', 'latitude', 'longitude', 'accuracy_m', 'mode', 'consent_revision']),
  LocationBatch: obj({ device_id: uuid, client_batch_id: uuid, schema_version: { type: 'integer', const: 1 }, points: { ...arr(ref('LocationPoint')), minItems: 1 } }),
  BatchReceipt: obj({ client_batch_id: uuid, results: arr(obj({ client_point_id: uuid, status: str({ enum: ['accepted', 'duplicate', 'permanently_rejected'] }), code: str() }, ['client_point_id', 'status'])) }),
  SharingGrant: { ...obj({ id: uuid, user_id: uuid, viewer_user_ids: arr(uuid, 100), scope: str({ enum: ['current', 'history', 'both'] }), consent_version: integer(1), starts_at: time }, ['id', 'viewer_user_ids', 'consent_version']), additionalProperties: true },
  CurrentLocation: obj({ id: uuid, device_id: uuid, latitude: number(-90, 90), longitude: number(-180, 180), captured_at: time, received_at: time, accuracy_m: number(0), battery_pct: { type: ['integer', 'null'], minimum: 0, maximum: 100 }, mode }, ['latitude', 'longitude', 'captured_at', 'received_at', 'accuracy_m', 'battery_pct']),
  ParticipantLocation: obj({ user_id: uuid, name: str(), status: str({ enum: ['fresh', 'stale', 'no_location', 'unavailable'] }), location: { anyOf: [ref('CurrentLocation'), { type: 'null' }] } }),
  HistoryPage: obj({ points: arr(ref('CurrentLocation'), 1000), next_cursor: { type: ['string', 'null'] }, timezone: str(), date: str({ format: 'date' }) }),
  ModePolicy: obj({ capture_seconds: integer(1, 86400), upload_seconds: integer(1, 86400) }),
  LocationPolicy: obj({ version: integer(1), modes: obj(Object.fromEntries(['idle', 'normal', 'live', 'sport', 'sos'].map(m => [m, ref('ModePolicy')]))), history_retention_days: integer(0, 3650), offline_max_age_hours: integer(1, 168), batch_max_points: integer(1, 500), low_battery_threshold_pct: integer(1, 100) }),
  EffectiveLocationPolicy: obj({ modes: obj(Object.fromEntries(['idle', 'normal', 'live', 'sport', 'sos'].map(m => [m, ref('ModePolicy')]))), history_retention_days: integer(0, 3650) }),
  GeofenceInput: obj({ name: str({ minLength: 1, maxLength: 100 }), latitude: number(-90, 90), longitude: number(-180, 180), radius_m: integer(50, 100000), target_user_ids: { ...arr(uuid, 100), minItems: 1 }, hysteresis_m: integer(0, 1000), dwell_seconds: integer(0, 600) }, ['name', 'latitude', 'longitude', 'radius_m', 'target_user_ids']),
  Geofence: obj({ id: uuid, name: str(), latitude: number(-90, 90), longitude: number(-180, 180), radius_m: number(50), hysteresis_m: integer(), dwell_seconds: integer() }),
  GeofenceEvent: obj({ id: uuid, geofence_id: uuid, user_id: uuid, type: str({ enum: ['enter', 'exit'] }), occurred_at: time }),
  Sos: { ...obj({ id: uuid, user_id: uuid, status: str({ enum: ['active', 'ended'] }), started_at: time }, ['id', 'status']), additionalProperties: true },
  LiveSession: obj({ id: uuid, status: str({ enum: ['requested', 'active', 'ended', 'expired'] }), expires_at: time }, ['id', 'status']),
  TemporaryShare: obj({ id: uuid, expires_at: time, token: str({ description: 'Returned once; place in URL fragment. Never log or persist in analytics.' }) }),
  Entitlements: { type: 'object', additionalProperties: { oneOf: [bool, integer()] } },
  Usage: obj({ feature: str(), consumed: integer(), reserved: integer(), limit: integer(), period_end: time }),
  PlanPrice: obj({ id: uuid, interval: str({ enum: ['month', 'year'] }), currency: str({ const: 'RUB' }), amount_minor: integer() }),
  Plan: obj({ id: uuid, name: str(), features: ref('Entitlements'), prices: arr(ref('PlanPrice'), 20) }),
  Subscription: obj({ id: uuid, status: str({ enum: ['pending', 'trialing', 'active', 'past_due', 'grace', 'paused', 'canceled', 'expired'] }), period_end: time, cancel_at_period_end: bool, auto_renew: bool }),
  Checkout: obj({ checkout_id: uuid, url: str({ format: 'uri' }) }),
  PromoResult: obj({ redemption_id: uuid, type: str({ enum: ['free_access', 'discount', 'free_months'] }), effective_until: time }),
  PartnerSummary: obj({ partner_id: uuid, referred_count: integer(), pending_minor: integer(), payable_minor: integer(), paid_minor: integer(), currency: str({ const: 'RUB' }) }),
  Payout: obj({ id: uuid, amount_minor: integer(1), status: str({ enum: ['requested', 'reserved', 'paid', 'rejected'] }) }),
  Notification: obj({ id: uuid, type: str(), event_id: uuid, created_at: time, read_at: { type: ['string', 'null'], format: 'date-time' } }),
  RequestAccepted: obj({ id: uuid, status: str({ const: 'pending' }) }),
};
const paths = {};
function route(path, method, operationId, tag, output, options = {}) {
  const parameters = [...path.matchAll(/\{([^}]+)\}/g)].map(([, name]) => ({ name, in: 'path', required: true, schema: name === 'provider' ? str({ enum: ['sandbox', 'yookassa', 'apple', 'google'] }) : uuid }));
  if (options.idempotent) parameters.push(idempotency);
  parameters.push(...(options.query ?? []));
  const successSchema = output ? obj({ data: typeof output === 'string' ? ref(output) : output }) : null;
  const status = options.status ?? (output ? '200' : '204');
  const responses = { [status]: { description: 'Success', ...(successSchema ? { content: { 'application/json': { schema: successSchema } } } : {}) } };
  for (const code of ['400', '401', '403', '404', '409', '422', '429', '503']) responses[code] = { $ref: '#/components/responses/Error' };
  paths[path] ??= {};
  paths[path][method] = { operationId, tags: [tag], summary: options.summary ?? operationId, ...(options.description ? { description: options.description } : {}), security: options.public ? [] : [{ bearerAuth: [] }], parameters, ...(options.input ? { requestBody: { required: true, content: { 'application/json': { schema: typeof options.input === 'string' ? ref(options.input) : options.input } } } } : {}), responses };
}
const query = (name, schema, required = false) => ({ name, in: 'query', required, schema });
const list = name => arr(ref(name));
route('/auth/register', 'post', 'register', 'Identity', 'Session', { public: true, input: 'Registration', status: '201' });
route('/auth/login', 'post', 'login', 'Identity', 'Session', { public: true, input: 'Credentials' });
route('/auth/refresh', 'post', 'refreshSession', 'Identity', 'Session', { public: true, input: obj({ refresh_token: str() }) });
route('/auth/forgot-password', 'post', 'requestPasswordReset', 'Identity', obj({ accepted: bool, message: str() }), { public: true, input: obj({ email: str({ format: 'email', maxLength: 254 }) }), status: '202', description: 'Generic response for existing/missing identities. Requires enabled SMTP and durable queue configuration; otherwise 503 MAIL_ACTIONS_UNAVAILABLE.' });
route('/auth/reset-password', 'post', 'resetPassword', 'Identity', obj({ reset: bool, login_required: bool, new_consent_required: bool }), { public: true, input: obj({ token: str({ minLength: 64, maxLength: 64 }), password: str({ minLength: 12, maxLength: 1024 }), password_confirmation: str({ minLength: 12, maxLength: 1024 }) }), description: 'One-use action token; revokes all sessions and sharing. Requires enabled mail-action configuration.' });
route('/auth/email/verify', 'post', 'verifyEmail', 'Identity', obj({ verified: bool }), { public: true, input: obj({ token: str({ minLength: 64, maxLength: 64 }) }) });
route('/auth/email/verification', 'post', 'requestEmailVerification', 'Identity', obj({ accepted: bool }), { status: '202' });
route('/auth/logout', 'post', 'logout', 'Identity', obj({ revoked: bool }));
route('/auth/me', 'get', 'getMe', 'Identity', obj({ user: ref('User'), device_id: uuid }));
route('/devices', 'get', 'listDevices', 'Identity', list('Device'));
route('/devices', 'post', 'registerDevice', 'Identity', 'Device', { input: 'DeviceInput', status: '201' });
route('/devices/{device}', 'delete', 'revokeDevice', 'Identity', obj({ revoked: bool }));
route('/devices/{device}/push-token', 'put', 'registerPushToken', 'Identity', undefined, { input: obj({ provider: str({ enum: ['fcm', 'apns'] }), token: str({ minLength: 1, maxLength: 4096 }) }) });
route('/workspaces', 'get', 'listWorkspaces', 'Workspaces', list('Workspace'));
route('/workspaces', 'post', 'createWorkspace', 'Workspaces', 'Workspace', { input: 'WorkspaceInput', status: '201' });
route('/invitations/accept', 'post', 'acceptInvitation', 'Workspaces', obj({ workspace_id: uuid, group_id: uuid, consent_required: bool }), { input: obj({ code: str({ minLength: 10, maxLength: 10 }) }) });
route('/invitations/join', 'post', 'joinInvitation', 'Workspaces', obj({ ...schemas.Session.properties, workspace_id: uuid, group_id: uuid, consent_required: bool }, [...schemas.Session.required, 'workspace_id', 'group_id', 'consent_required']), { public: true, input: obj({ code: str({ minLength: 10, maxLength: 10 }), name: str({ minLength: 1, maxLength: 120 }), installation_id: uuid, platform: str({ enum: ['android', 'ios'] }), app_version: str({ maxLength: 40 }) }, ['code', 'name', 'installation_id', 'platform']) });
route('/location/pause', 'post', 'pauseLocation', 'Consent', obj({ sharing_paused: bool, new_consent_required: bool }));
const w = '/workspaces/{workspace}';
route(`${w}/members`, 'get', 'listMembers', 'Workspaces', list('Member'));
route(`${w}/members/{member}`, 'delete', 'removeMember', 'Workspaces');
route(`${w}/invitations`, 'post', 'createInvitation', 'Workspaces', 'Invitation', { input: obj({ group_id: uuid }), status: '201' });
route(`${w}/groups`, 'get', 'listGroups', 'Workspaces', list('Group'));
route(`${w}/groups`, 'post', 'createGroup', 'Workspaces', 'Group', { input: obj({ name: str({ minLength: 1, maxLength: 100 }) }), status: '201' });
route(`${w}/groups/{group}/members/{member}`, 'put', 'addGroupMember', 'Workspaces');
route(`${w}/groups/{group}/members/{member}`, 'delete', 'removeGroupMember', 'Workspaces');
route(`${w}/visibility`, 'get', 'listVisibilityRules', 'Consent', list('VisibilityRule'));
route(`${w}/visibility`, 'put', 'setVisibilityRule', 'Consent', 'VisibilityRule', { input: 'VisibilityInput', description: 'Administrative permission for directed subject → viewer access. Does not create subject consent. Administrator may be the subject.' });
route(`${w}/groups/{group}/visibility`, 'put', 'setGroupVisibility', 'Consent', obj({ updated: bool, consent_required: bool }), { input: 'VisibilityInput', description: 'Sets one directed edge in this group; both ends must be current members. Does not create consent.' });
route(`${w}/sharing-grants`, 'get', 'listOwnSharingGrants', 'Consent', list('SharingGrant'));
route(`${w}/sharing-grants`, 'post', 'createSharingGrant', 'Consent', 'SharingGrant', { input: obj({ viewer_user_ids: { ...arr(uuid, 100), minItems: 1 }, scope: str({ enum: ['current', 'history', 'both'] }), group_id: uuid, policy_version: str({ const: '1' }), confirmed: { type: 'boolean', const: true } }), status: '201' });
route(`${w}/sharing-grants/{grant}`, 'delete', 'revokeSharingGrant', 'Consent', obj({ revoked: bool }));
route(`${w}/consent`, 'get', 'getOwnConsent', 'Consent', 'Consent');
route(`${w}/consent`, 'put', 'setOwnConsent', 'Consent', 'Consent', { input: 'ConsentInput', description: 'Only authenticated subject can grant. Explicit viewer snapshot; joining a group never expands audience.' });
route(`${w}/consent`, 'delete', 'revokeOwnConsent', 'Consent');
route(`${w}/consent/pause`, 'put', 'pauseOwnConsent', 'Consent', 'Consent', { input: obj({ paused: bool, expected_revision: integer() }) });
route('/locations/batches', 'post', 'submitLocationBatch', 'Location', 'BatchReceipt', { input: 'LocationBatch', description: 'Maximum 500 points / 512 KiB; 72-hour offline window by default. Batch identity is (device_id,client_batch_id). Same identity and payload returns identical receipt; changed payload returns 409. Only accepted/duplicates are acknowledged. Client must discard permanent rejects. Server resolves audiences from consent; no client-selected audience is trusted.' });
route(`${w}/locations/current`, 'get', 'getCurrentLocations', 'Location', list('ParticipantLocation'));
route(`${w}/members/{member}/locations/history`, 'get', 'getLocationHistory', 'Location', 'HistoryPage', { query: [query('date', str({ format: 'date' }), true), query('timezone', str({ example: 'Europe/Moscow' }), true), query('cursor', str({ maxLength: 1024 })), query('limit', integer(1, 2000))] });
route(`${w}/location-policy`, 'get', 'getEffectiveLocationPolicy', 'Location', 'EffectiveLocationPolicy', { description: 'Interval=max(policy, entitlement minimum, subject/device battery setting). Retention=min(policy cap, entitlement cap, subject preference). No remote activation.' });
route(`${w}/geofences`, 'get', 'listGeofences', 'Geofencing', list('Geofence'));
route(`${w}/geofences`, 'post', 'createGeofence', 'Geofencing', obj({ id: uuid }), { input: 'GeofenceInput', status: '201' });
route(`${w}/geofences/{geofence}`, 'delete', 'deleteGeofence', 'Geofencing', obj({ deleted: bool }));
route(`${w}/geofence-events`, 'get', 'listGeofenceEvents', 'Geofencing', list('GeofenceEvent'));
route(`${w}/sos`, 'get', 'listSos', 'Safety', list('Sos'));
route(`${w}/sos`, 'post', 'startSos', 'Safety', 'Sos', { idempotent: true, status: '201' });
route(`${w}/sos/{sos}/acknowledge`, 'post', 'acknowledgeSos', 'Safety', obj({ acknowledged: bool }));
route(`${w}/sos/{sos}/end`, 'post', 'endSos', 'Safety', obj({ ended: bool }));
route(`${w}/live-sessions`, 'get', 'listLiveSessions', 'Sharing', arr({ ...schemas.LiveSession, additionalProperties: true }));
route(`${w}/temporary-shares`, 'get', 'listTemporaryShares', 'Sharing', arr(obj({ id: uuid, grant_id: uuid, scope: str(), expires_at: time, revoked_at: { type: ['string', 'null'], format: 'date-time' }, created_at: time })));
route(`${w}/live-sessions`, 'post', 'startLiveSession', 'Sharing', 'LiveSession', { input: obj({ subject_id: uuid, duration_minutes: integer(1, 60) }), status: '201', description: 'A request starts requested until the subject explicitly accepts.' });
route(`${w}/live-sessions/{session}/accept`, 'post', 'acceptLiveSession', 'Sharing', 'LiveSession', { input: obj({ confirmed: { type: 'boolean', const: true } }) });
route(`${w}/live-sessions/{session}`, 'delete', 'endLiveSession', 'Sharing', obj({ ended: bool }));
route(`${w}/temporary-shares`, 'post', 'createTemporaryShare', 'Sharing', 'TemporaryShare', { input: obj({ grant_id: uuid, confirmed: { type: 'boolean', const: true }, expires_in_minutes: integer(1, 1440), passcode: str({ minLength: 6, maxLength: 128 }) }, ['grant_id', 'confirmed', 'expires_in_minutes']), status: '201', description: 'Self-issued current-position access only in first implementation.' });
route(`${w}/temporary-shares/{share}`, 'delete', 'revokeTemporaryShare', 'Sharing', obj({ revoked: bool }));
route('/shares/exchange', 'post', 'exchangeShareToken', 'Sharing', obj({ access_token: str(), expires_in: integer(1, 900), token_type: str({ const: 'Bearer' }) }), { public: true, input: obj({ token: str({ minLength: 64, maxLength: 64 }), passcode: str({ maxLength: 64 }) }, ['token']) });
route('/shares/current', 'get', 'getSharedCurrentLocation', 'Sharing', { anyOf: [ref('CurrentLocation'), { type: 'null' }] }, { description: 'Bearer is a limited share session, never a general user session. Check underlying consent/revocation/expiry on every request. Cache-Control: no-store.' });
route(`${w}/effective-entitlements`, 'get', 'getEntitlements', 'Billing', 'Entitlements');
route(`${w}/usage`, 'get', 'getUsage', 'Billing', list('Usage'));
route('/billing/catalog', 'get', 'getBillingCatalog', 'Billing', list('Plan'));
route(`${w}/billing/subscription`, 'get', 'getSubscription', 'Billing', 'Subscription');
route(`${w}/billing/checkout`, 'post', 'createCheckout', 'Billing', 'Checkout', { input: obj({ plan_price_id: uuid, auto_renew: bool }, ['plan_price_id']), idempotent: true, status: '201', description: 'Redeem promotion first. Automatic renewal requires explicit opt-in and enabled provider capability.' });
route(`${w}/billing/cancel`, 'post', 'cancelSubscription', 'Billing', 'Subscription', { idempotent: true });
route(`${w}/billing/trial`, 'post', 'startTrial', 'Billing', 'Subscription', { idempotent: true });
route(`${w}/billing/promo`, 'post', 'redeemPromo', 'Billing', 'PromoResult', { input: obj({ code: str({ minLength: 1, maxLength: 64 }) }), idempotent: true });
route(`${w}/billing/restore`, 'post', 'restoreStorePurchase', 'Billing', 'Subscription', { input: obj({ provider: str({ enum: ['apple', 'google'] }), purchase_token: str({ maxLength: 16384 }) }), idempotent: true });
route('/webhooks/payments/{provider}', 'post', 'receivePaymentWebhook', 'Billing', undefined, { public: true, status: '200', input: { type: 'object', additionalProperties: true }, description: 'Provider-specific authenticity verification of raw body or server-to-server retrieval is mandatory before granting rights. Durable inbox dedupes provider event ID. This endpoint is not a trusted client API.' });
route('/partners/me', 'get', 'getPartnerSummary', 'Partners', 'PartnerSummary');
route(`${w}/partners/attribution`, 'post', 'attributePartner', 'Partners', undefined, { input: obj({ referral_code: str({ maxLength: 64 }) }) });
route('/partners/payouts', 'post', 'requestPartnerPayout', 'Partners', 'Payout', { input: obj({ amount_minor: integer(1) }), idempotent: true, status: '201' });
route('/notifications', 'get', 'listNotifications', 'Notifications', list('Notification'));
route('/notifications/{notification}/read', 'post', 'readNotification', 'Notifications', obj({ read: bool }));
route('/device-tokens', 'post', 'registerDeviceToken', 'Notifications', obj({ registered: bool }), { input: obj({ provider: str({ enum: ['fcm', 'apns'] }), token: str({ maxLength: 4096 }) }) });
route('/privacy/exports', 'post', 'requestDataExport', 'Privacy', 'RequestAccepted', { status: '202' });
route('/privacy/deletion', 'post', 'requestDataDeletion', 'Privacy', 'RequestAccepted', { input: obj({ confirmed: { type: 'boolean', const: true } }), status: '202' });
route('/privacy/exports/{export}', 'get', 'getDataExport', 'Privacy', obj({ id: uuid, status: str(), expires_at: { type: ['string', 'null'], format: 'date-time' } }));
route('/privacy/exports/{export}/download', 'get', 'downloadDataExport', 'Privacy', str({ format: 'binary' }));
paths['/privacy/exports/{export}/download'].get.responses['200'] = { description: 'Private NDJSON download', content: { 'application/x-ndjson': { schema: str({ format: 'binary' }) } } };
route(`${w}/leave`, 'post', 'leaveWorkspace', 'Workspaces', obj({ left: bool }));
route(`${w}/transfer-ownership`, 'post', 'transferOwnership', 'Workspaces', obj({ transferred: bool, billing_owner_changed: bool }), { input: obj({ new_owner_id: uuid, confirmed: { type: 'boolean', const: true } }) });
route('/health', 'get', 'getHealth', 'Operations', obj({ status: str() }), { public: true });
route('/broadcasting/auth', 'post', 'authorizeBroadcast', 'Realtime', obj({ auth: str() }), { input: obj({ socket_id: str(), channel_name: str() }) });
route('/admin/location-policy', 'get', 'getAdminLocationPolicy', 'Administration', 'LocationPolicy', { description: 'Platform administrator with MFA and settings.manage permission.' });
route('/admin/location-policy', 'put', 'updateAdminLocationPolicy', 'Administration', 'LocationPolicy', { input: obj({ policy: ref('LocationPolicy'), expected_version: integer(1), reason: str({ minLength: 5, maxLength: 500 }) }), description: 'Validated, optimistic version check, immutable audit. New revision immediately limits reads; purge asynchronously. Never activates sharing.' });
const document = { openapi: '3.1.0', info: { title: 'KIRH GEO API', version: '0.1.0', description: 'Contract target for staged implementation; presence here does not mean a route is deployed. All tenant resources require active membership, permission, subject consent for location, entitlement and atomic limits. No implicit administrator GPS access.' }, servers: [{ url: '/api/v1' }], paths, components: { securitySchemes: { bearerAuth: { type: 'http', scheme: 'bearer' } }, schemas, responses: { Error: { description: 'Stable error envelope. 404 conceals foreign resources. 409 is replay-body or revision conflict. 429 includes Retry-After when available.', content: { 'application/json': { schema: ref('Error') } } } } } };
const inventoryPath = new URL('../backend-routes.json', import.meta.url);
const inventory = existsSync(inventoryPath) ? JSON.parse(readFileSync(inventoryPath)) : [];
const normalize = path => path.replace(/\{[^}]+\}/g, '{}');
const registered = new Set(inventory.map(({ method, path }) => `${method} ${normalize(path)}`));
const activePaths = {};
for (const [path, methods] of Object.entries(paths)) {
  for (const [method, operation] of Object.entries(methods)) {
    const active = registered.has(`${method} ${normalize(path)}`);
    operation['x-implementation-status'] = active ? (operation.operationId === 'restoreStorePurchase' ? 'disabled-provider-gate' : 'route-registered') : 'planned';
    if (active) {
      activePaths[path] ??= {};
      activePaths[path][method] = operation;
    }
  }
}
writeFileSync(new URL('../openapi.json', import.meta.url), `${JSON.stringify(document, null, 2)}\n`);
writeFileSync(new URL('../openapi.active.json', import.meta.url), `${JSON.stringify({ ...document, info: { ...document.info, description: 'Only operations registered in the captured Laravel route inventory. Check x-implementation-status for disabled provider gates. Route registration is not a claim of production readiness.' }, paths: activePaths }, null, 2)}\n`);
