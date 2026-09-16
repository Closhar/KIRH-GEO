import React, { useEffect, useRef, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import MapView, { Marker, Polyline } from "react-native-maps";
import { api } from "../shared/api";
import { Button, Card, Field, Notice, ui } from "../ui/components";
import { colors as c } from "../ui/theme";
import { MemberPosition, Point } from "./types";
import { routeSegments } from './routeSegments';

function dateLocal() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}
function lastSeen(value: string) {
  const minutes = Math.max(
    0,
    Math.floor((Date.now() - new Date(value).getTime()) / 60000),
  );
  return minutes < 1
    ? "только что"
    : minutes < 60
      ? `${minutes} мин назад`
      : new Date(value).toLocaleString("ru-RU");
}
export function MapScreen({
  workspaceId,
  positions,
  onRefresh,
}: {
  workspaceId: string;
  positions: MemberPosition[];
  onRefresh: () => void;
}) {
  const map = useRef<MapView>(null);
  const generation = useRef(0);
  const [subject, setSubject] = useState("");
  const [history, setHistory] = useState<Point[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [day, setDay] = useState(dateLocal());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [historyLoaded, setHistoryLoaded] = useState(false);
  const selected = positions.find(p => p.user_id === subject);
  const historyAllowed = !!selected && selected.status !== 'unavailable';
  const currentVisibility = useRef(false);
  currentVisibility.current = historyAllowed;
  const visible = positions.filter((p) => p.location !== null && p.status !== 'unavailable');
  useEffect(() => {
    if (!historyAllowed) {
      generation.current++;
      setHistory([]);
      setCursor(null);
      setHistoryLoaded(false);
      setBusy(false);
    }
  }, [historyAllowed]);
  useEffect(() => () => { generation.current++; }, []);
  useEffect(() => {
    generation.current++;
    setHistory([]);
    setCursor(null);
    setSubject("");
    setError("");
    setHistoryLoaded(false);
  }, [workspaceId]);
  useEffect(() => {
    if (visible.length)
      map.current?.fitToCoordinates(
        visible.map((p) => ({
          latitude: Number(p.location!.latitude),
          longitude: Number(p.location!.longitude),
        })),
        {
          edgePadding: { top: 55, right: 55, bottom: 55, left: 55 },
          animated: true,
        },
      );
  }, [workspaceId, positions.map((p) => p.user_id).join(",")]);
  function choose(id: string) {
    generation.current++;
    setSubject(id);
    setHistory([]);
    setCursor(null);
    setHistoryLoaded(false);
    setError("");
    const p = positions.find((p) => p.user_id === id)?.location;
    if (p)
      map.current?.animateToRegion({
        latitude: Number(p.latitude),
        longitude: Number(p.longitude),
        latitudeDelta: 0.012,
        longitudeDelta: 0.012,
      });
  }
  async function loadHistory(more = false) {
    if (!subject || !historyAllowed || !/^\d{4}-\d{2}-\d{2}$/.test(day)) {
      setError("Выберите участника и дату в формате ГГГГ-ММ-ДД.");
      return;
    }
    const request = ++generation.current;
    setBusy(true);
    setError("");
    try {
      const timezone =
        Intl.DateTimeFormat().resolvedOptions().timeZone || "Europe/Moscow";
      const result = await api<{ points: Point[]; next_cursor: string | null }>(
        `workspaces/${workspaceId}/members/${subject}/locations/history?date=${day}&timezone=${encodeURIComponent(timezone)}${more && cursor ? `&cursor=${encodeURIComponent(cursor)}` : ""}`,
      );
      if (request !== generation.current || !currentVisibility.current) return;
      const next = more ? [...history, ...result.points] : result.points;
      setHistory(next);
      setCursor(result.next_cursor);
      setHistoryLoaded(true);
      if (next.length)
        map.current?.fitToCoordinates(
          next.map((p) => ({
            latitude: Number(p.latitude),
            longitude: Number(p.longitude),
          })),
          {
            edgePadding: { top: 45, bottom: 45, left: 45, right: 45 },
            animated: true,
          },
        );
    } catch (e) {
      if (request === generation.current) {
        setHistory([]);
        setCursor(null);
        setError(e instanceof Error ? e.message : "История недоступна");
      }
    } finally {
      if (request === generation.current) setBusy(false);
    }
  }
  return (
    <View style={{ gap: 17 }}>
      <View style={s.mapCard}>
        <MapView
          ref={map}
          style={s.map}
          initialRegion={{
            latitude: 55.751244,
            longitude: 37.618423,
            latitudeDelta: 0.05,
            longitudeDelta: 0.05,
          }}
          showsUserLocation={false}
          showsMyLocationButton={false}
        >
          {visible.map((p, i) => (
            <Marker
              key={p.user_id}
              coordinate={{
                latitude: Number(p.location!.latitude),
                longitude: Number(p.location!.longitude),
              }}
              title={p.name}
              description={lastSeen(p.location!.captured_at)}
              onPress={() => choose(p.user_id)}
            >
              <View
                style={[
                  s.marker,
                  { backgroundColor: i % 2 ? c.green : c.purple },
                ]}
              >
                <Text style={s.markerText}>
                  {p.name.slice(0, 1).toUpperCase()}
                </Text>
              </View>
            </Marker>
          ))}
          {historyAllowed && routeSegments(history).map((segment, index) => (
            <Polyline
              key={index}
              coordinates={segment.map((p) => ({
                latitude: Number(p.latitude),
                longitude: Number(p.longitude),
              }))}
              strokeWidth={5}
              strokeColor={c.purple}
            />
          ))}
        </MapView>
        <View pointerEvents="none" style={s.mapBadge}>
          <Text style={{ color: c.purple, fontWeight: "600" }}>
            ●{" "}
            {visible.length
              ? `На карте: ${visible.length}`
              : "Ожидаем координаты"}
          </Text>
        </View>
      </View>
      <View style={[ui.row, { justifyContent: "space-between" }]}>
        <Text style={ui.heading}>Ваши люди</Text>
        <Button small tone="ghost" onPress={onRefresh}>
          Обновить
        </Button>
      </View>
      {!positions.length && (
        <Card>
          <Text style={ui.text}>
            Пригласите близкого по коду. Его позиция появится после согласия на
            передачу.
          </Text>
        </Card>
      )}
      {positions.map((p, i) => (
        <Pressable
          key={p.user_id}
          onPress={() => choose(p.user_id)}
          accessibilityRole="button"
          style={[s.person, subject === p.user_id && { borderColor: c.purple }]}
        >
          <View
            style={[
              s.avatar,
              { backgroundColor: i % 2 ? c.greenSoft : c.purpleSoft },
            ]}
          >
            <Text
              style={{
                color: i % 2 ? c.green : c.purple,
                fontWeight: "700",
                fontSize: 20,
              }}
            >
              {p.name.slice(0, 1)}
            </Text>
          </View>
          <View style={{ flex: 1, gap: 4 }}>
            <Text style={{ color: c.ink, fontWeight: "700", fontSize: 16 }}>
              {p.name}
            </Text>
            <Text style={ui.muted}>
              {p.location
                ? lastSeen(p.location.captured_at)
                : p.status === "unavailable"
                  ? "Передача не разрешена или на паузе"
                  : "Координат пока нет"}
            </Text>
            {p.location && (
              <Text style={ui.muted}>
                Точность ±{Math.round(p.location.accuracy_m)} м · Заряд{" "}
                {p.location.battery_pct === null
                  ? "—"
                  : `${Math.round(p.location.battery_pct)}%`}
              </Text>
            )}
          </View>
          <View
            style={[
              s.dot,
              { backgroundColor: p.status === "fresh" ? c.green : "#B9B3C4" },
            ]}
          />
        </Pressable>
      ))}
      {subject && historyAllowed && (
        <Card>
          <Text style={ui.heading}>Маршрут за день</Text>
          <Field
            label="Дата"
            value={day}
            onChangeText={(value) => {
              generation.current++;
              setDay(value);
              setHistory([]);
              setCursor(null);
              setHistoryLoaded(false);
              setBusy(false);
            }}
            placeholder="ГГГГ-ММ-ДД"
            keyboardType="numbers-and-punctuation"
          />
          <Text style={ui.muted}>
            История доступна только за время действующего согласия. Часовой пояс
            — вашего телефона.
          </Text>
          <Button onPress={() => loadHistory()} busy={busy}>
            Показать маршрут
          </Button>
          {cursor && (
            <Button
              tone="secondary"
              onPress={() => loadHistory(true)}
              busy={busy}
            >
              Загрузить следующую часть
            </Button>
          )}
          {historyLoaded && (
            <Text style={ui.muted}>
              {history.length
                ? `Точек маршрута: ${history.length}`
                : "За этот день доступных точек нет."}
            </Text>
          )}
          {error && <Notice danger>{error}</Notice>}
        </Card>
      )}
    </View>
  );
}
const s = StyleSheet.create({
  mapCard: {
    height: 320,
    overflow: "hidden",
    borderRadius: 26,
    backgroundColor: "#E4E9E5",
  },
  map: { flex: 1 },
  mapBadge: {
    position: "absolute",
    top: 14,
    left: 14,
    borderRadius: 20,
    backgroundColor: "#FFFFFFEF",
    paddingHorizontal: 14,
    paddingVertical: 10,
  },
  marker: {
    width: 40,
    height: 40,
    borderRadius: 20,
    borderWidth: 3,
    borderColor: "#fff",
    alignItems: "center",
    justifyContent: "center",
  },
  markerText: { color: "#fff", fontWeight: "800", fontSize: 16 },
  person: {
    backgroundColor: c.surface,
    padding: 15,
    borderRadius: 20,
    borderWidth: 1,
    borderColor: c.line,
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
  },
  avatar: {
    width: 44,
    height: 44,
    borderRadius: 15,
    alignItems: "center",
    justifyContent: "center",
  },
  dot: { height: 8, width: 8, borderRadius: 4 },
});
