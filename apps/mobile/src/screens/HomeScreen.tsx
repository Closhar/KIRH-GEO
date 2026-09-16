import React, { useCallback, useEffect, useRef, useState } from "react";
import {
  ActivityIndicator,
  Alert,
  Animated,
  AppState,
  Dimensions,
  Image,
  Linking,
  Modal,
  Platform,
  Pressable,
  RefreshControl,
  ScrollView,
  Share,
  StyleSheet,
  Text,
  View,
} from "react-native";
import * as Crypto from "expo-crypto";
import * as Notifications from "expo-notifications";
import { api, ApiFailure } from "../shared/api";
import { subscribeToChanges } from "../shared/realtime";
import {
  getTrackingStatus,
  pauseTracking,
  requestSOS,
  setMode,
  startTracking,
  syncPending,
} from "../features/location/engine";
import type { Policy } from "../features/location/policy";
import {
  Brand,
  Button,
  Card,
  Check,
  Chip,
  Field,
  Notice,
  Title,
  ui,
} from "../ui/components";
import { colors as c, useTheme } from "../ui/theme";
import {
  Grant,
  Group,
  LocationMode,
  Member,
  MemberPosition,
  User,
  Workspace,
} from "./types";
import { HomeDashboard } from "./HomeDashboard";
import { PrivacyPanel } from "../features/privacy/PrivacyPanel";
import { getBiometricEnabled, setBiometricEnabled } from "../shared/session";

type Tab = "home" | "sender" | "group" | "settings";
type TrackingStatus = {
  enabled: boolean;
  mode: LocationMode;
  queueSize: number;
  lastSyncAt?: number;
  error?: string;
  pendingSOS?: boolean;
  sosEventId?: string;
};
type Plan = {
  id: string;
  name: string;
  features: Record<string, number | boolean>;
  prices: {
    id: string;
    interval: string;
    amount_minor: number;
    currency: string;
  }[];
};
type Fence = { id: string; name: string; radius_m: number };
type LiveSession = { id: string; subject_id: string; initiator_id: string; status: string; expires_at: string };
type SosEvent = { id: string; user_id: string; status: string; started_at: string };
const tabs: { id: Tab; title: string; icon: string }[] = [
  { id: "home", title: "Главная", icon: "◎" },
  { id: "sender", title: "Передача", icon: "⇪" },
  { id: "group", title: "Группа", icon: "♧" },
  { id: "settings", title: "Настройки", icon: "⚙" },
];
const modeLabels: Record<LocationMode, string> = {
  idle: "Покой",
  normal: "Обычный",
  live: "Live",
  sport: "Спорт",
  sos: "SOS",
};

export function HomeScreen({
  user,
  onLogout,
  onDeleted,
}: {
  user: User;
  onLogout: () => Promise<void>;
  onDeleted: () => void;
}) {
  const { colors: themeColors, dark, toggle } = useTheme();
  const [tab, setTab] = useState<Tab>("home");
  const [workspaces, setWorkspaces] = useState<Workspace[]>([]);
  const [workspaceId, setWorkspaceId] = useState("");
  const [groups, setGroups] = useState<Group[]>([]);
  const [groupId, setGroupId] = useState("");
  const [members, setMembers] = useState<Member[]>([]);
  const [positions, setPositions] = useState<MemberPosition[]>([]);
  const [grants, setGrants] = useState<Grant[]>([]);
  const [policy, setPolicy] = useState<Policy | null>(null);
  const [status, setStatus] = useState<TrackingStatus>({
    enabled: false,
    mode: "normal",
    queueSize: 0,
  });
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [refreshing, setRefreshing] = useState(false);
  const [workspaceName, setWorkspaceName] = useState("Моя группа");
  const [groupName, setGroupName] = useState("Близкие");
  const [joinCode, setJoinCode] = useState("");
  const [inviteCode, setInviteCode] = useState("");
  const [viewers, setViewers] = useState<string[]>([]);
  const [confirmed, setConfirmed] = useState(false);
  const [mode, setSelectedMode] = useState<LocationMode>("normal");
  const [subjectId, setSubjectId] = useState("");
  const [viewerId, setViewerId] = useState("");
  const [plans, setPlans] = useState<Plan[]>([]);
  const [promoCode, setPromoCode] = useState("");
  const [sosId, setSosId] = useState("");
  const [shareId, setShareId] = useState("");
  const [fences, setFences] = useState<Fence[]>([]);
  const [fenceName, setFenceName] = useState("Дом");
  const [fenceRadius, setFenceRadius] = useState("150");
  const [fenceSubject, setFenceSubject] = useState("");
  const [notifications, setNotifications] = useState<
    { id: string; event_id: string; type: string; created_at: string }[]
  >([]);
  const [liveSubject, setLiveSubject] = useState("");
  const [liveId, setLiveId] = useState("");
  const [liveSessions, setLiveSessions] = useState<LiveSession[]>([]);
  const [sosEvents, setSosEvents] = useState<SosEvent[]>([]);
  const [entitlements, setEntitlements] = useState<Record<string, boolean | number> | null>(null);
  const [biometricEnabled, setBiometricEnabledState] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const menuAnim = useRef(new Animated.Value(0)).current;
  const activeWorkspace = useRef("");
  const keys = useRef<Record<string, string>>({});
  const actionRunning = useRef(false);
  const workspace = workspaces.find((w) => w.id === workspaceId);
  const isOwner = workspace?.owner_user_id === user.id;
  const others = members.filter((m) => m.id !== user.id);
  const userStatus = workspace ? (isOwner ? "Владелец" : "Участник") : "Без группы";
  const activeGrants = grants.filter(
    (g) =>
      !g.revoked_at &&
      (!g.ends_at || new Date(g.ends_at).getTime() > Date.now()),
  );

  async function act(name: string, action: () => Promise<void>) {
    if (actionRunning.current) return;
    actionRunning.current = true;
    setBusy(name);
    setError("");
    setNotice("");
    try {
      await action();
    } catch (e) {
      setError(
        e instanceof Error ? e.message : "Не удалось выполнить действие.",
      );
    } finally {
      actionRunning.current = false;
      setBusy("");
    }
  }
  async function mutation<T>(path: string, body: unknown, name: string) {
    const key =
      keys.current[name] ?? (keys.current[name] = Crypto.randomUUID());
    const result = await api<T>(path, "POST", body, key);
    delete keys.current[name];
    return result;
  }
  function openMenu() {
    setMenuOpen(true);
    Animated.spring(menuAnim, {
      toValue: 1,
      useNativeDriver: true,
      speed: 18,
      bounciness: 4,
    }).start();
  }
  function closeMenu() {
    Animated.timing(menuAnim, {
      toValue: 0,
      duration: 180,
      useNativeDriver: true,
    }).start(() => setMenuOpen(false));
  }
  async function loadWorkspaces(preferred?: string) {
    const all = await api<Workspace[]>("workspaces");
    setWorkspaces(all);
    setWorkspaceId(
      (current) =>
        preferred ??
        (all.some((w) => w.id === current) ? current : (all[0]?.id ?? "")),
    );
  }
  const loadWorkspace = useCallback(async (id: string) => {
    if (!id) return;
    const result = await Promise.all([
      api<Member[]>(`workspaces/${id}/members`),
      api<Group[]>(`workspaces/${id}/groups`),
      api<MemberPosition[]>(`workspaces/${id}/locations/current`).catch(error => {
        if (error instanceof ApiFailure && [403,404].includes(error.status)) return [];
        throw error;
      }),
      api<Grant[]>(`workspaces/${id}/sharing-grants`),
      api<Policy>(`workspaces/${id}/location-policy`),
      api<LiveSession[]>(`workspaces/${id}/live-sessions`),
      api<SosEvent[]>(`workspaces/${id}/sos`),
    ]);
    if (activeWorkspace.current !== id) return;
    setMembers(result[0]);
    setGroups(result[1]);
    setPositions(result[2]);
    setGrants(result[3]);
    setPolicy(result[4]);
    setLiveSessions(result[5].filter(session => ['requested','active'].includes(session.status) && new Date(session.expires_at).getTime() > Date.now()));
    setSosEvents(result[6].filter(event => event.status === 'active'));
    setSosId(result[6].find(event => event.user_id === user.id && event.status === 'active')?.id ?? '');
    setGroupId((previous) =>
      result[1].some((g) => g.id === previous)
        ? previous
        : (result[1][0]?.id ?? ""),
    );
  }, [user.id]);
  useEffect(() => {
    loadWorkspaces().catch((e) => setError(e.message));
    getTrackingStatus()
      .then(setStatus)
      .catch((e) => setError(e.message));
    getBiometricEnabled()
      .then(setBiometricEnabledState)
      .catch(() => {});
  }, []);
  useEffect(() => {
    const subscription = Notifications.addPushTokenListener((token) => {
      void Notifications.getPermissionsAsync()
        .then((permission) => {
          if (permission.granted && typeof token.data === "string")
            return api("device-tokens", "POST", {
              provider: Platform.OS === "ios" ? "apns" : "fcm",
              token: token.data,
            });
        })
        .catch(() => {});
    });
    return () => subscription.remove();
  }, []);
  useEffect(() => {
    activeWorkspace.current = workspaceId;
    setMembers([]);
    setGroups([]);
    setPositions([]);
    setGrants([]);
    setPolicy(null);
    setViewers([]);
    setConfirmed(false);
    setInviteCode("");
    setFences([]);
    setShareId("");
    setSosId("");
    setLiveSessions([]);
    setSosEvents([]);
    if (!workspaceId) return;
    let running = true;
    const refreshData = async () => {
      try {
        await loadWorkspace(workspaceId);
        const current = await getTrackingStatus();
        if (running) setStatus(current);
      } catch (e) {
        if (running) {
          setPositions([]);
          setError(
            e instanceof Error ? e.message : "Не удалось обновить данные.",
          );
        }
      }
    };
    void refreshData();
    let unsubscribe = AppState.currentState === 'active' ? subscribeToChanges(user.id, () => void refreshData()) : () => {};
    const timer = setInterval(() => {
      if (AppState.currentState === "active") void refreshData();
    }, 15000);
    const appState = AppState.addEventListener("change", (state) => {
      unsubscribe();
      if (state === "active") {
        unsubscribe = subscribeToChanges(user.id, () => void refreshData());
        void syncPending().catch(() => {});
        void refreshData();
      } else setPositions([]);
    });
    return () => {
      running = false;
      clearInterval(timer);
      appState.remove();
      unsubscribe();
    };
  }, [workspaceId, loadWorkspace, user.id]);
  async function refresh() {
    setRefreshing(true);
    try {
      await loadWorkspace(workspaceId);
      setStatus(await getTrackingStatus());
    } catch (e) {
      setPositions([]);
      setError(e instanceof Error ? e.message : "Нет соединения");
    } finally {
      setRefreshing(false);
    }
  }
  async function createFamily() {
    await act("create", async () => {
      const created = await api<{ id: string }>("workspaces", "POST", {
        name: workspaceName.trim(),
      });
      await api(`workspaces/${created.id}/groups`, "POST", { name: "Близкие" });
      await loadWorkspaces(created.id);
      setTab("group");
    });
  }
  async function start() {
    await act("start", async () => {
      if (!policy || !groupId || !viewers.length || !confirmed)
        throw new Error("Выберите группу и людей, затем подтвердите согласие.");
      if ((await getTrackingStatus()).enabled) {
        const paused = await pauseTracking();
        setStatus(await getTrackingStatus());
        if (paused.pendingRevocation)
          throw new Error(
            "Дождитесь подключения, чтобы отозвать прежнее согласие и включить новое.",
          );
      }
      const grant = await api<Grant>(
        `workspaces/${workspaceId}/sharing-grants`,
        "POST",
        {
          group_id: groupId,
          viewer_user_ids: viewers,
          scope: "both",
          policy_version: "1",
          confirmed: true,
        },
      );
      await startTracking({
        workspaceId,
        grantId: grant.id,
        revision: grant.consent_version,
        mode,
        policy,
      });
      setStatus(await getTrackingStatus());
      await loadWorkspace(workspaceId);
      setConfirmed(false);
      setNotice(
        "Передача включена. Вы можете поставить её на паузу в любой момент.",
      );
    });
  }
  async function pause() {
    await act("pause", async () => {
      const result = await pauseTracking();
      setStatus(await getTrackingStatus());
      setConfirmed(false);
      setPositions([]);
      setNotice(
        result.pendingRevocation
          ? "GPS остановлен на телефоне. Отзыв доступа на сервере ожидает сети: ранее отправленные данные могут оставаться доступны до синхронизации."
          : "Передача остановлена, доступ отозван. Для возобновления нужно новое согласие.",
      );
      if (!result.pendingRevocation) await loadWorkspace(workspaceId);
    });
  }
  function confirmSOS() {
    Alert.alert(
      "Отправить SOS близким?",
      "Мы уведомим разрешённых получателей и повысим частоту GPS. Это не вызов экстренных служб.",
      [
        { text: "Отмена", style: "cancel" },
        {
          text: "Отправить SOS",
          style: "destructive",
          onPress: () =>
            void act("sos", async () => {
              const result = await requestSOS(workspaceId);
              if (result.id) setSosId(result.id);
              setStatus(await getTrackingStatus());
              setNotice(
                result.pending
                  ? "GPS переведён в режим ожидания SOS. Сообщение ещё не подтверждено сервером — при опасности позвоните 112."
                  : "SOS доставлен разрешённым участникам. При опасности также позвоните 112.",
              );
            }),
        },
      ],
    );
  }
  async function temporaryShare() {
    await act("share", async () => {
      const grant = activeGrants[0];
      if (!grant) throw new Error("Сначала включите добровольную передачу.");
      const base = process.env.EXPO_PUBLIC_SHARE_URL;
      if (!base) throw new Error("Публичные ссылки пока недоступны.");
      const result = await api<{ id: string; token: string }>(
        `workspaces/${workspaceId}/temporary-shares`,
        "POST",
        { grant_id: grant.id, expires_in_minutes: 60, confirmed: true },
      );
      setShareId(result.id);
      await Share.share({
        message: `Моя геопозиция на 1 час: ${base.replace(/\/$/, "")}/#${result.token}`,
      });
    });
  }
  async function loadMore() {
    await act("more", async () => {
      setPlans(await api<Plan[]>("billing/catalog"));
      setNotifications(await api<typeof notifications>("notifications"));
      setEntitlements(await api<Record<string, boolean | number>>(`workspaces/${workspaceId}/effective-entitlements`));
      if (isOwner)
        setFences(await api<Fence[]>(`workspaces/${workspaceId}/geofences`));
    });
  }
  async function enablePush() {
    await act("push", async () => {
      if (Platform.OS === "android")
        await Notifications.setNotificationChannelAsync("safety", {
          name: "SOS и геозоны",
          importance: Notifications.AndroidImportance.HIGH,
        });
      const permission = await Notifications.requestPermissionsAsync();
      if (!permission.granted)
        throw new Error(
          "Уведомления не разрешены. Вы можете включить их позже в настройках телефона.",
        );
      const token = await Notifications.getDevicePushTokenAsync();
      if (typeof token.data !== "string")
        throw new Error("Push-уведомления доступны в приложении на телефоне.");
      await api("device-tokens", "POST", {
        provider: Platform.OS === "ios" ? "apns" : "fcm",
        token: token.data,
      });
      setNotice("Уведомления включены.");
    });
  }
  useEffect(() => {
    if (tab === "settings" && workspaceId) void loadMore();
  }, [tab, workspaceId]);

  return (
    <View style={[ui.page, { backgroundColor: themeColors.background }]}>
      <View style={[s.header, { backgroundColor: themeColors.surface, borderColor: themeColors.line }]}>
        <Brand compact />
        <View style={ui.row}>
          <View
            style={[
              s.statusPill,
              { backgroundColor: status.enabled ? c.greenSoft : c.purpleSoft },
            ]}
          >
            <Text
              style={{
                color: status.enabled ? "#008264" : c.purple,
                fontSize: 11,
                fontWeight: "700",
              }}
            >
              {status.enabled ? "● Передаю" : "○ На паузе"}
            </Text>
          </View>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Открыть меню"
            onPress={openMenu}
            style={s.menuButton}
          >
            <Text style={s.menuIcon}>☰</Text>
          </Pressable>
        </View>
      </View>
      <ScrollView
        contentContainerStyle={ui.content}
        keyboardShouldPersistTaps="handled"
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={refresh}
            tintColor={c.purple}
          />
        }
      >
        <Title
          subtitle={
            workspace
              ? workspace.name
              : "Создайте свою группу или присоединитесь по коду."
          }
        >
          {tab === "home"
            ? "Ваше местоположение"
            : tab === "sender"
              ? "Моя геопозиция"
              : tab === "group"
                ? "Свои люди"
                : "Настройки"}
        </Title>
        {workspaces.length > 1 && (
          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={ui.wrap}
          >
            {workspaces.map((w) => (
              <Chip
                key={w.id}
                active={w.id === workspaceId}
                onPress={() => {
                  activeWorkspace.current = w.id;
                  setPositions([]);
                  setWorkspaceId(w.id);
                }}
              >
                {w.name}
              </Chip>
            ))}
          </ScrollView>
        )}
        {error ? <Notice danger>{error}</Notice> : null}
        {notice ? <Notice>{notice}</Notice> : null}
        {busy ? (
          <View style={ui.row}>
            <ActivityIndicator color={c.purple} />
            <Text style={ui.muted}>Выполняем…</Text>
          </View>
        ) : null}
        {!workspaceId && tab !== "home" && tab !== "settings" && (
          <Card>
            <Text style={ui.text}>
              Создайте группу в настройках.
            </Text>
          </Card>
        )}
        {tab === "home" && (
          <HomeDashboard
            user={user}
            workspaceId={workspaceId}
            positions={positions}
            onRefresh={refresh}
          />
        )}
        {workspaceId && tab === "sender" && (
          <>
            <Card
              style={{
                backgroundColor: status.enabled ? c.greenSoft : c.surface,
              }}
            >
              <View style={[ui.row, { justifyContent: "space-between" }]}>
                <Text style={ui.heading}>
                  {status.enabled
                    ? "Вы делитесь геопозицией"
                    : "Передача остановлена"}
                </Text>
                <Text style={{ fontSize: 27, color: c.green }}>
                  {status.enabled ? "↗" : "Ⅱ"}
                </Text>
              </View>
              <Text style={ui.text}>
                {status.enabled
                  ? `Режим: ${modeLabels[status.mode]}. В очереди: ${status.queueSize} точек.`
                  : "Только вы решаете, когда включить GPS и кому показать своё место."}
              </Text>
              {status.lastSyncAt && (
                <Text style={ui.muted}>
                  Последняя выгрузка:{" "}
                  {new Date(status.lastSyncAt).toLocaleString("ru-RU")}
                </Text>
              )}
              {status.error && <Notice danger>{status.error}</Notice>}
              {status.enabled && (
                <Button tone="danger" onPress={pause}>
                  Остановить передачу и отозвать доступ
                </Button>
              )}
            </Card>
            <Card>
              <Text style={ui.heading}>Кто сможет видеть меня</Text>
              <Text style={ui.muted}>
                Текущая позиция и доступная история. Новые участники не получат
                доступ без вашего нового согласия.
              </Text>
              <View style={ui.wrap}>
                {groups.map((g) => (
                  <Chip
                    key={g.id}
                    active={groupId === g.id}
                    onPress={() => {
                      setGroupId(g.id);
                      setViewers([]);
                      setConfirmed(false);
                    }}
                  >
                    {g.name}
                  </Chip>
                ))}
              </View>
              {!others.length && (
                <Text style={ui.muted}>
                  В группе пока нет других участников. Пригласите человека на
                  вкладке «Группа».
                </Text>
              )}
              {others.map((m) => (
                <Check
                  key={m.id}
                  value={viewers.includes(m.id)}
                  onChange={(value) => {
                    setConfirmed(false);
                    setViewers((current) =>
                      value
                        ? [...current, m.id]
                        : current.filter((id) => id !== m.id),
                    );
                  }}
                >
                  {m.name}
                </Check>
              ))}
              <Text style={ui.heading}>Режим работы</Text>
              <View style={ui.wrap}>
                {(["normal", "idle", "sport"] as LocationMode[]).map((item) => (
                  <Chip
                    key={item}
                    active={mode === item}
                    onPress={() => setSelectedMode(item)}
                  >
                    {modeLabels[item]}
                  </Chip>
                ))}
              </View>
              <Text style={ui.muted}>
                {policy
                  ? `Целевой интервал: ${policy.modes[mode].capture_seconds} сек. История: до ${policy.history_retention_days} дней. ОС и экономия батареи могут снизить частоту.`
                  : "Загружаем настройки частоты…"}
              </Text>
              <Check value={confirmed} onChange={setConfirmed}>
                Разрешаю выбранным людям видеть мои координаты и историю. Затем
                приложение запросит разрешения телефона на GPS и работу в фоне.
              </Check>
              <Button
                disabled={!confirmed || !viewers.length || !!busy}
                onPress={start}
              >
                {status.enabled
                  ? "Обновить согласие и режим"
                  : "Разрешить и начать передачу"}
              </Button>
              <Button tone="ghost" onPress={() => Linking.openSettings()}>
                Разрешения телефона
              </Button>
            </Card>
            {!!activeGrants.length && (
              <Card>
                <Text style={ui.heading}>Действующие согласия</Text>
                {activeGrants.map((g) => (
                  <View key={g.id} style={{ gap: 9 }}>
                    <Text style={ui.text}>
                      {g.viewer_user_ids
                        .map(
                          (id) =>
                            members.find((m) => m.id === id)?.name ??
                            "Участник",
                        )
                        .join(", ")}
                    </Text>
                    <Button small tone="secondary" onPress={pause}>
                      Отозвать и остановить передачу
                    </Button>
                  </View>
                ))}
              </Card>
            )}
          </>
        )}
        {workspaceId && tab === "group" && (
          <>
            <Card>
              <Text style={ui.heading}>{workspace?.name}</Text>
              {members.map((m) => (
                <View key={m.id} style={ui.row}>
                  <View style={s.miniAvatar}>
                    <Text style={{ color: c.purple, fontWeight: "700" }}>
                      {m.name.slice(0, 1)}
                    </Text>
                  </View>
                  <Text style={ui.text}>
                    {m.name}
                    {m.id === user.id ? " · вы" : ""}
                    {m.id === workspace?.owner_user_id ? " · владелец" : ""}
                  </Text>
                </View>
              ))}
              <View style={ui.wrap}>
                {groups.map((g) => (
                  <Chip
                    key={g.id}
                    active={groupId === g.id}
                    onPress={() => setGroupId(g.id)}
                  >
                    {g.name}
                  </Chip>
                ))}
              </View>
            </Card>
            {isOwner && (
              <>
                <Card>
                  <Text style={ui.heading}>Пригласить близкого</Text>
                  <Text style={ui.muted}>
                    Один код — один участник. Срок действия — 15 минут. После
                    входа человек сам разрешит передачу.
                  </Text>
                  <Button
                    disabled={!groupId || !!busy}
                    onPress={() =>
                      act("invite", async () => {
                        const invite = await api<{ code: string }>(
                          `workspaces/${workspaceId}/invitations`,
                          "POST",
                          { group_id: groupId },
                        );
                        setInviteCode(invite.code);
                      })
                    }
                  >
                    Создать приглашение
                  </Button>
                  {inviteCode && (
                    <>
                      <Text selectable style={s.invite}>
                        {inviteCode}
                      </Text>
                      <Button
                        tone="secondary"
                        onPress={() =>
                          Share.share({
                            message: `Присоединяйся в KIRH GEO по ссылке: kirhgeo://join?code=${inviteCode}\n\nКод: ${inviteCode}. Действует 15 минут. Координаты будут доступны только после твоего согласия.`,
                          })
                        }
                      >
                        Поделиться кодом
                      </Button>
                    </>
                  )}
                </Card>
                <Card>
                  <Text style={ui.heading}>Взаимная видимость</Text>
                  <Text style={ui.muted}>
                    Вы разрешаете связь между участниками. Доступ появится
                    только после согласия отправителя. Для взаимного доступа
                    настройте оба направления.
                  </Text>
                  <Text style={ui.text}>Чья геопозиция</Text>
                  <View style={ui.wrap}>
                    {members.map((m) => (
                      <Chip
                        key={m.id}
                        active={subjectId === m.id}
                        onPress={() => setSubjectId(m.id)}
                      >
                        {m.name}
                      </Chip>
                    ))}
                  </View>
                  <Text style={ui.text}>Кто может смотреть</Text>
                  <View style={ui.wrap}>
                    {members
                      .filter((m) => m.id !== subjectId)
                      .map((m) => (
                        <Chip
                          key={m.id}
                          active={viewerId === m.id}
                          onPress={() => setViewerId(m.id)}
                        >
                          {m.name}
                        </Chip>
                      ))}
                  </View>
                  <View style={ui.row}>
                    {[true, false].map((allowed) => (
                      <View key={String(allowed)} style={{ flex: 1 }}>
                        <Button
                          tone={allowed ? "primary" : "secondary"}
                          disabled={
                            !subjectId ||
                            !viewerId ||
                            subjectId === viewerId ||
                            !groupId ||
                            !!busy
                          }
                          onPress={() =>
                            act("visibility", async () => {
                              await api(
                                `workspaces/${workspaceId}/groups/${groupId}/visibility`,
                                "PUT",
                                {
                                  subject_user_id: subjectId,
                                  viewer_user_id: viewerId,
                                  allowed,
                                },
                              );
                              setNotice(
                                allowed
                                  ? "Связь разрешена. Отправителю нужно подтвердить согласие в своём приложении."
                                  : "Видимость отключена.",
                              );
                              await loadWorkspace(workspaceId);
                            })
                          }
                        >
                          {allowed ? "Разрешить" : "Отключить"}
                        </Button>
                      </View>
                    ))}
                  </View>
                </Card>
                <Card>
                  <Text style={ui.heading}>Новая группа</Text>
                  <Field
                    label="Название"
                    value={groupName}
                    onChangeText={setGroupName}
                  />
                  <Button
                    tone="secondary"
                    onPress={() =>
                      act("group", async () => {
                        await api(`workspaces/${workspaceId}/groups`, "POST", {
                          name: groupName,
                        });
                        await loadWorkspace(workspaceId);
                      })
                    }
                  >
                    Создать группу
                  </Button>
                </Card>
              </>
            )}
            {!isOwner && (
              <Button
                tone="ghost"
                onPress={() =>
                  Alert.alert(
                    "Выйти из группы?",
                    "Ваши координаты станут недоступны участникам.",
                    [
                      { text: "Остаться", style: "cancel" },
                      {
                        text: "Выйти",
                        style: "destructive",
                        onPress: () =>
                          void act("leave", async () => {
                            await pauseTracking();
                            await api(
                              `workspaces/${workspaceId}/leave`,
                              "POST",
                            );
                            await loadWorkspaces();
                          }),
                      },
                    ],
                  )
                }
              >
                Выйти из пространства
              </Button>
            )}
          </>
        )}
        {tab === "settings" && (
          <Card>
            <Text style={ui.heading}>Профиль и оформление</Text>
            <Text style={ui.text}>
              {user.name}
              {user.email ? ` · ${user.email}` : ""}
            </Text>
            <View style={[ui.row, { justifyContent: "space-between" }]}>
              <Text style={ui.text}>Тёмная тема</Text>
              <Chip active={dark} onPress={toggle}>
                {dark ? "Включена" : "Выключена"}
              </Chip>
            </View>
            <View style={[ui.row, { justifyContent: "space-between" }]}>
              <Text style={ui.text}>Вход по отпечатку пальца</Text>
              <Chip
                active={biometricEnabled}
                onPress={() =>
                  act("biometric", async () => {
                    const next = !biometricEnabled;
                    await setBiometricEnabled(next);
                    setBiometricEnabledState(next);
                    setNotice(next ? "Вход по отпечатку включён." : "Вход по отпечатку выключен.");
                  })
                }
              >
                {biometricEnabled ? "Включён" : "Выключен"}
              </Chip>
            </View>
            <Text style={ui.muted}>
              Сейчас используется {dark ? "тёмная" : "светлая"} тема.
            </Text>
          </Card>
        )}
        {!workspaceId && tab === "settings" && (
          <>
            <Card>
              <Text style={ui.heading}>Создать группу</Text>
              <Field
                label="Название группы"
                value={workspaceName}
                onChangeText={setWorkspaceName}
              />
              <Button onPress={createFamily} busy={busy === "create"}>
                Создать группу
              </Button>
            </Card>
            <Card>
              <Text style={ui.heading}>Войти в группу близкого</Text>
              <Field
                label="Код приглашения"
                value={joinCode}
                onChangeText={setJoinCode}
                autoCapitalize="characters"
                maxLength={10}
              />
              <Button
                tone="secondary"
                onPress={() =>
                  act("join", async () => {
                    await api("invitations/accept", "POST", {
                      code: joinCode.trim().toUpperCase(),
                    });
                    await loadWorkspaces();
                    setTab("sender");
                  })
                }
              >
                Присоединиться
              </Button>
            </Card>
          </>
        )}
        {workspaceId && tab === "settings" && (
          <>
            <Card>
              <Text style={ui.heading}>Не пропустите важное</Text>
              <Text style={ui.muted}>
                Разрешите уведомления, чтобы получать SOS и события входа/выхода
                из геозон. Запрос телефона появится после нажатия кнопки.
              </Text>
              <Button tone="secondary" onPress={enablePush}>
                Включить уведомления
              </Button>
            </Card>
            <Card>
              <Text style={ui.heading}>Live на 15 минут</Text>
              <Text style={ui.muted}>
                Запросите более частые координаты участника. Режим включится
                после его подтверждения и автоматически завершится по времени.
              </Text>
              <View style={ui.wrap}>
                {others.map((m) => (
                  <Chip
                    key={m.id}
                    active={liveSubject === m.id}
                    onPress={() => setLiveSubject(m.id)}
                  >
                    {m.name}
                  </Chip>
                ))}
              </View>
              <Button
                tone="secondary"
                disabled={!liveSubject || !!busy}
                onPress={() =>
                  act("live", async () => {
                    const result = await api<{ id: string }>(
                      `workspaces/${workspaceId}/live-sessions`,
                      "POST",
                      { subject_id: liveSubject, duration_minutes: 15 },
                    );
                    setLiveId(result.id);
                    await loadWorkspace(workspaceId);
                    setNotice("Запрос Live отправлен участнику.");
                  })
                }
              >
                Запросить Live
              </Button>
              {liveSessions.map(session => <View key={session.id} style={{gap:10}}>
                <Text style={ui.text}>{members.find(m=>m.id===session.initiator_id)?.name ?? 'Участник'} → {members.find(m=>m.id===session.subject_id)?.name ?? 'Участник'} · {session.status === 'active' ? 'Live активен' : 'Ожидает согласия'}</Text>
                {session.subject_id === user.id && session.status === 'requested' && <Button tone="secondary" disabled={!status.enabled || !!busy} onPress={()=>Alert.alert('Разрешить Live?', 'Координаты будут отправляться чаще до указанного срока. Это увеличивает расход батареи.', [{text:'Отмена',style:'cancel'},{text:'Разрешить',onPress:()=>void act('live-accept',async()=>{await api(`workspaces/${workspaceId}/live-sessions/${session.id}/accept`,'POST',{confirmed:true});await setMode('live',Date.parse(session.expires_at));setStatus(await getTrackingStatus());await loadWorkspace(workspaceId);})}])}>Разрешить до {new Date(session.expires_at).toLocaleTimeString('ru-RU')}</Button>}
                <Button tone="ghost" onPress={()=>act('live-stop',async()=>{await api(`workspaces/${workspaceId}/live-sessions/${session.id}`,'DELETE');if(session.subject_id===user.id && status.mode==='live' && status.enabled) await setMode('normal');await loadWorkspace(workspaceId);})}>Завершить запрос</Button>
              </View>)}
              {liveId && (
                <Button
                  tone="ghost"
                  onPress={() =>
                    act("live-end", async () => {
                      await api(
                        `workspaces/${workspaceId}/live-sessions/${liveId}`,
                        "DELETE",
                      );
                      if (status.enabled && status.mode === "live")
                        await setMode("normal");
                      setLiveId("");
                      setNotice("Live завершён.");
                    })
                  }
                >
                  Завершить Live
                </Button>
              )}
            </Card>
            <Card style={{ backgroundColor: c.dangerSoft }}>
              <Text style={ui.heading}>Когда нужна помощь</Text>
              {sosEvents.filter(event=>event.user_id!==user.id).map(event=><View key={event.id} style={{gap:8}}><Text style={ui.text}>SOS от {members.find(member=>member.id===event.user_id)?.name ?? 'участника'}</Text><Button tone="danger" onPress={()=>act('sos-ack',async()=>{await api(`workspaces/${workspaceId}/sos/${event.id}/acknowledge`,'POST');setNotice('Вы подтвердили получение SOS.');})}>Я вижу сигнал · подтверждаю</Button></View>)}
              <Text style={ui.text}>
                SOS отправит сигнал вашим разрешённым получателям. При угрозе
                жизни звоните 112.
              </Text>
              {sosId ? (
                <Button
                  tone="secondary"
                  onPress={() =>
                    act("sos-end", async () => {
                      await api(
                        `workspaces/${workspaceId}/sos/${sosId}/end`,
                        "POST",
                      );
                      setSosId("");
                      if (status.enabled) await setMode("normal");
                      setNotice("SOS завершён.");
                    })
                  }
                >
                  Я в порядке · завершить SOS
                </Button>
              ) : (
                <Button tone="danger" onPress={confirmSOS}>
                  Отправить SOS
                </Button>
              )}
            </Card>
            <Card>
              <Text style={ui.heading}>Поделиться на время</Text>
              <Text style={ui.muted}>
                Любой, у кого есть ссылка, сможет видеть вашу текущую позицию в
                течение часа. Делитесь ей только с теми, кому доверяете.
              </Text>
              <Button
                tone="secondary"
                disabled={!activeGrants.length || !!busy}
                onPress={temporaryShare}
              >
                Поделиться на 1 час
              </Button>
              {shareId && (
                <Button
                  tone="ghost"
                  onPress={() =>
                    act("share-end", async () => {
                      await api(
                        `workspaces/${workspaceId}/temporary-shares/${shareId}`,
                        "DELETE",
                      );
                      setShareId("");
                      setNotice("Ссылка отозвана.");
                    })
                  }
                >
                  Отозвать ссылку
                </Button>
              )}
            </Card>
            {isOwner && (
              <Card>
                <Text style={ui.heading}>Геозоны</Text>
                <Text style={ui.muted}>
                  Создайте круговую зону вокруг последнего места участника.
                  Уведомления приходят при входе и выходе, пока действует
                  согласие.
                </Text>
                {fences.map((f) => (
                  <View key={f.id} style={ui.row}>
                    <Text style={[ui.text, { flex: 1 }]}>
                      {f.name} · {f.radius_m} м
                    </Text>
                    <Button
                      small
                      tone="ghost"
                      onPress={() =>
                        act("delete-zone", async () => {
                          await api(
                            `workspaces/${workspaceId}/geofences/${f.id}`,
                            "DELETE",
                          );
                          setFences(
                            await api<Fence[]>(
                              `workspaces/${workspaceId}/geofences`,
                            ),
                          );
                        })
                      }
                    >
                      Удалить
                    </Button>
                  </View>
                ))}
                <Field
                  label="Название зоны"
                  value={fenceName}
                  onChangeText={setFenceName}
                />
                <Field
                  label="Радиус, метров"
                  value={fenceRadius}
                  onChangeText={setFenceRadius}
                  keyboardType="number-pad"
                />
                <View style={ui.wrap}>
                  {positions
                    .filter((p) => p.location)
                    .map((p) => (
                      <Chip
                        key={p.user_id}
                        active={fenceSubject === p.user_id}
                        onPress={() => setFenceSubject(p.user_id)}
                      >
                        {p.name}
                      </Chip>
                    ))}
                </View>
                <Button
                  tone="secondary"
                  disabled={!fenceSubject || !!busy}
                  onPress={() =>
                    act("zone", async () => {
                      const point = positions.find(
                        (p) => p.user_id === fenceSubject,
                      )?.location;
                      if (!point)
                        throw new Error("Нет текущей позиции участника.");
                      await api(`workspaces/${workspaceId}/geofences`, "POST", {
                        name: fenceName,
                        group_id: groupId || null,
                        latitude: Number(point.latitude),
                        longitude: Number(point.longitude),
                        radius_m: Number(fenceRadius),
                        target_user_ids: [fenceSubject],
                      });
                      setFences(
                        await api<Fence[]>(
                          `workspaces/${workspaceId}/geofences`,
                        ),
                      );
                      setNotice("Геозона создана.");
                    })
                  }
                >
                  Создать зону в этом месте
                </Button>
              </Card>
            )}
            {entitlements && (
              <Card>
                <Text style={ui.heading}>Ваш доступ</Text>
                <Text style={ui.text}>
                  GPS:{" "}
                  {entitlements["location.enabled"]
                    ? "включена"
                    : "недоступна"}
                </Text>
                <Text style={ui.text}>
                  История: {String(entitlements["history.retention_days"] ?? "—")}{" "}
                  дней · Участников:{" "}
                  {String(entitlements["members.max"] ?? "—")}
                </Text>
                <Text style={ui.muted}>
                  Это может быть тестовый режим, тариф или пробный доступ.
                  Управление тестовым режимом находится в админке.
                </Text>
              </Card>
            )}
            {workspace?.billing_owner_user_id === user.id && (
              <Card>
              <Text style={ui.heading}>Подписка вашей группы</Text>
              <Text style={ui.muted}>Оплата выбранного периода без автоматического продления. Сохранение карты и регулярные списания не включаются.</Text>
                {plans.map((plan) => (
                  <View
                    key={plan.id}
                    style={{
                      gap: 10,
                      borderBottomWidth: 1,
                      borderColor: c.line,
                      paddingBottom: 12,
                    }}
                  >
                    <Text style={ui.text}>{plan.name}</Text>
                    <Text style={ui.muted}>
                      Участников: {String(plan.features["members.max"] ?? "—")}{" "}
                      · История:{" "}
                      {String(plan.features["history.retention_days"] ?? "—")}{" "}
                      дней
                    </Text>
                    {plan.prices.map((price) => (
                      <Button
                        key={price.id}
                        tone="secondary"
                        disabled={!!busy}
                        onPress={() =>
                          act("checkout", async () => {
                            const result = await mutation<{ url: string }>(
                              `workspaces/${workspaceId}/billing/checkout`,
                              { plan_price_id: price.id },
                              `checkout:${workspaceId}:${price.id}`,
                            );
                            if (!result.url.startsWith("https://"))
                              throw new Error("Оплата пока недоступна.");
                            await Linking.openURL(result.url);
                          })
                        }
                      >
                        {price.interval === "year" ? "Год" : "Месяц"} ·{" "}
                        {(price.amount_minor / 100).toLocaleString("ru-RU")} ₽
                      </Button>
                    ))}
                  </View>
                ))}
                <Field
                  label="Промокод"
                  value={promoCode}
                  onChangeText={setPromoCode}
                  autoCapitalize="characters"
                />
                <Button
                  tone="secondary"
                  onPress={() =>
                    act("promo", async () => {
                      await mutation(
                        `workspaces/${workspaceId}/billing/promo`,
                        { code: promoCode },
                        `promo:${workspaceId}:${promoCode}`,
                      );
                      setNotice("Промокод применён.");
                      await loadWorkspace(workspaceId);
                    })
                  }
                >
                  Применить промокод
                </Button>
                <Button
                  tone="ghost"
                  onPress={() =>
                    act("trial", async () => {
                      await mutation(
                        `workspaces/${workspaceId}/billing/trial`,
                        {},
                        `trial:${workspaceId}`,
                      );
                      setNotice("Пробный доступ активирован.");
                      await loadWorkspace(workspaceId);
                    })
                  }
                >
                  Активировать пробный доступ
                </Button>
                <Button
                  tone="ghost"
                  onPress={() =>
                    Alert.alert(
                      "Отключить продление?",
                      "Уже оплаченный период останется доступен.",
                      [
                        { text: "Назад", style: "cancel" },
                        {
                          text: "Отключить",
                          onPress: () =>
                            void act("cancel", async () => {
                              await mutation(
                                `workspaces/${workspaceId}/billing/cancel`,
                                {},
                                `cancel:${workspaceId}`,
                              );
                              setNotice("Продление отключено.");
                            }),
                        },
                      ],
                    )
                  }
                >
                  Отключить продление
                </Button>
              </Card>
            )}
            <Card>
              <Text style={ui.heading}>Уведомления</Text>
              {notifications.length ? (
                notifications.slice(0, 15).map((n) => (
                  <View key={n.id}>
                    <Text style={ui.text}>
                      {n.type === "sos.started"
                        ? "Сигнал SOS"
                        : n.type.includes("geofence")
                          ? "Событие геозоны"
                          : n.type.includes("live")
                            ? "Запрос Live"
                            : "Обновление группы"}
                    </Text>
                    <Text style={ui.muted}>
                      {new Date(n.created_at).toLocaleString("ru-RU")}
                    </Text>
                  </View>
                ))
              ) : (
                <Text style={ui.muted}>Пока нет уведомлений.</Text>
              )}
            </Card>
          </>
        )}
        {tab === "settings" && (
          <PrivacyPanel onDeleted={onDeleted}/>
        )}
        {tab === "settings" && (
          <Button tone="ghost" onPress={() => act("logout", onLogout)}>
            Выйти из аккаунта
          </Button>
        )}
      </ScrollView>
      <View style={[s.tabs, { backgroundColor: themeColors.surface, borderColor: themeColors.line }]}>
        {tabs.map((item) => (
          <Pressable
            accessibilityRole="tab"
            accessibilityState={{ selected: item.id === tab }}
            key={item.id}
            onPress={() => setTab(item.id)}
            style={s.tab}
          >
            <Text
              style={[
                s.tabIcon,
                { color: item.id === tab ? themeColors.purple : themeColors.muted },
              ]}
            >
              {item.icon}
            </Text>
            <Text
              style={{
                color: item.id === tab ? themeColors.purple : themeColors.muted,
                fontSize: 11,
                fontWeight: item.id === tab ? "700" : "500",
              }}
            >
              {item.title}
            </Text>
            {item.id === tab && (
              <View style={[s.tabDot, { backgroundColor: themeColors.green }]} />
            )}
          </Pressable>
        ))}
      </View>
      <Modal transparent visible={menuOpen} animationType="none" onRequestClose={closeMenu}>
        <Pressable style={{ flex: 1, backgroundColor: "rgba(20,17,30,0.42)" }} onPress={closeMenu} />
        <Animated.View
          style={[
            s.drawer,
            {
              backgroundColor: themeColors.surface + (dark ? "E6" : "F2"),
              transform: [
                {
                  translateX: menuAnim.interpolate({
                    inputRange: [0, 1],
                    outputRange: [Dimensions.get("window").width, 0],
                  }),
                },
              ],
            },
          ]}
        >
          <View style={[s.drawerOrbOne, { backgroundColor: themeColors.purpleSoft }]} />
          <View style={[s.drawerOrbTwo, { backgroundColor: themeColors.greenSoft }]} />
          <View pointerEvents="none" style={s.drawerPattern}>
            <Text style={[s.drawerPatternIcon, { color: themeColors.purple }]}>◎</Text>
            <View style={[s.drawerRing, { borderColor: themeColors.green }]} />
          </View>
          <View style={[ui.row, { marginTop: 18, justifyContent: "space-between" }]}>
            <View style={ui.row}>
              <Image
                source={require("../../assets/kt-geo-logo.png")}
                style={{ width: 42, height: 42 }}
                resizeMode="contain"
              />
              <Text style={[s.drawerBrand, { color: themeColors.purple }]}>KIRH GEO</Text>
            </View>
            <Pressable onPress={closeMenu} accessibilityRole="button">
              <Text style={[s.drawerClose, { color: themeColors.muted }]}>✕</Text>
            </Pressable>
          </View>
          <View style={[ui.row, { justifyContent: "space-between", marginTop: 8 }]}>
            <View style={{ flex: 1 }}>
              <Text style={[s.drawerUser, { color: themeColors.ink, fontSize: 16 }]}>{user.name}</Text>
              <Text style={{ color: themeColors.muted, fontSize: 12, marginTop: 3 }}>{userStatus}</Text>
            </View>
            <View style={ui.row}>
              <Pressable
                onPress={toggle}
                accessibilityRole="button"
                style={[s.drawerAction, { borderColor: themeColors.line }]}
              >
                <Text style={{ color: themeColors.ink, fontSize: 42, lineHeight: 42, textAlign: "center", includeFontPadding: false }}>{dark ? "◐" : "◑"}</Text>
              </Pressable>
              <Pressable
                onPress={() => {
                  closeMenu();
                  void act("logout", onLogout);
                }}
                accessibilityRole="button"
                style={[s.drawerAction, { borderColor: themeColors.line }]}
              >
                <Text style={{ color: themeColors.danger, fontSize: 42, lineHeight: 42, textAlign: "center", includeFontPadding: false }}>↩</Text>
              </Pressable>
            </View>
          </View>
          <View style={ui.divider} />
          {tabs.map((item) => (
            <Pressable
              key={item.id}
              onPress={() => {
                setTab(item.id);
                closeMenu();
              }}
              style={s.drawerItem}
            >
              <View style={{ flexDirection: "row", alignItems: "center", gap: 8 }}>
                <Text style={{ color: themeColors.purple, fontSize: 28, width: 30, textAlign: "center" }}>
                  {item.icon}
                </Text>
                <Text style={[s.drawerItemText, { color: themeColors.ink }]}>{item.title}</Text>
              </View>
            </Pressable>
          ))}
          <View style={ui.divider} />
        </Animated.View>
      </Modal>
    </View>
  );
}
const s = StyleSheet.create({
  header: {
    paddingHorizontal: 20,
    paddingVertical: 13,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    backgroundColor: c.surface,
    borderBottomWidth: 1,
    borderColor: c.line,
  },
  statusPill: { paddingHorizontal: 10, paddingVertical: 8, borderRadius: 16 },
  menuButton: {
    width: 42,
    height: 42,
    borderRadius: 21,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: c.purpleSoft,
  },
  menuIcon: { color: c.purple, fontSize: 24, fontWeight: "800" },
  tabs: {
    flexDirection: "row",
    backgroundColor: c.surface,
    borderTopWidth: 1,
    borderColor: c.line,
    paddingTop: 6,
    paddingBottom: 7,
  },
  tab: { flex: 1, alignItems: "center", gap: 3, paddingVertical: 4 },
  tabIcon: { fontSize: 25, height: 31 },
  tabDot: { height: 3, width: 18, borderRadius: 2, backgroundColor: c.green },
  drawer: {
    position: "absolute",
    top: 0,
    bottom: 0,
    right: 0,
    width: 290,
    backgroundColor: c.surface,
    padding: 22,
    gap: 4,
    borderTopLeftRadius: 28,
    borderBottomLeftRadius: 28,
  },
  drawerClose: { color: c.muted, fontSize: 22, fontWeight: "700" },
  drawerBrand: { fontSize: 19, fontWeight: "800", letterSpacing: 1.4 },
  drawerUser: { fontSize: 16, fontWeight: "800" },
  drawerAction: {
    width: 58,
    height: 58,
    borderRadius: 12,
    borderWidth: 1,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "transparent",
    padding: 0,
  },
  drawerOrbOne: {
    position: "absolute",
    width: 150,
    height: 150,
    borderRadius: 75,
    top: -60,
    right: -50,
    opacity: 0.25,
  },
  drawerOrbTwo: {
    position: "absolute",
    width: 110,
    height: 110,
    borderRadius: 55,
    bottom: 30,
    left: -35,
    opacity: 0.18,
  },
  drawerPattern: {
    position: "absolute",
    width: 220,
    height: 220,
    right: -90,
    top: -70,
    alignItems: "center",
    justifyContent: "center",
    opacity: 0.14,
  },
  drawerPatternIcon: { fontSize: 210, fontWeight: "900" },
  drawerRing: {
    position: "absolute",
    width: 160,
    height: 160,
    borderRadius: 80,
    borderWidth: 2,
  },
  drawerItem: {
    paddingVertical: 5,
    paddingHorizontal: 6,
    borderRadius: 14,
  },
  drawerItemText: { color: c.ink, fontSize: 17, fontWeight: "700" },
  miniAvatar: {
    width: 35,
    height: 35,
    borderRadius: 12,
    backgroundColor: c.purpleSoft,
    alignItems: "center",
    justifyContent: "center",
  },
  invite: {
    fontSize: 27,
    letterSpacing: 4,
    textAlign: "center",
    color: c.purple,
    fontWeight: "800",
    paddingVertical: 10,
  },
});
