import React, { useEffect, useMemo, useState } from "react";
import { ActivityIndicator, Animated, Share, StyleSheet, Text, View } from "react-native";
import * as Location from "expo-location";
import { Camera, Map, ViewAnnotation } from "@maplibre/maplibre-react-native";
import { Button, Card, Title, ui } from "../ui/components";
import { useTheme } from "../ui/theme";
import { MapScreen } from "./MapScreen";
import type { MemberPosition, User } from "./types";

type DeviceLocation = {
  latitude: number;
  longitude: number;
  accuracy: number | null;
};

const OSM_STYLE = {
  version: 8,
  sources: {
    osm: {
      type: "raster" as const,
      tiles: ["https://tile.openstreetmap.org/{z}/{x}/{y}.png"],
      tileSize: 256,
      attribution: "© OpenStreetMap contributors",
    },
  },
  layers: [
    { id: "osm", type: "raster" as const, source: "osm", minzoom: 0, maxzoom: 19 },
  ],
};

function haversineMeters(a: DeviceLocation, b: { latitude: number; longitude: number }) {
  const R = 6371000;
  const toRad = (value: number) => (value * Math.PI) / 180;
  const dLat = toRad(b.latitude - a.latitude);
  const dLon = toRad(b.longitude - a.longitude);
  const lat1 = toRad(a.latitude);
  const lat2 = toRad(b.latitude);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

function formatDistance(meters: number) {
  return meters < 1000 ? `${Math.round(meters)} м` : `${(meters / 1000).toFixed(1)} км`;
}

function yandexNavigatorUrl(location: DeviceLocation) {
  return `https://yandex.ru/navi/?whatshere%5Bpoint%5D=${location.latitude},${location.longitude}&whatshere%5Bzoom%5D=16`;
}

export function HomeDashboard({
  user,
  workspaceId,
  positions,
  onRefresh,
}: {
  user: User;
  workspaceId: string;
  positions: MemberPosition[];
  onRefresh: () => Promise<void>;
}) {
  const { colors: c } = useTheme();
  const [deviceLocation, setDeviceLocation] = useState<DeviceLocation | null>(null);
  const [locationError, setLocationError] = useState("");
  const [loadingLocation, setLoadingLocation] = useState(true);
  const [heading, setHeading] = useState(0);
  const compassRotation = useMemo(() => new Animated.Value(0), []);

  async function loadDeviceLocation() {
    setLoadingLocation(true);
    setLocationError("");
    try {
      const permission = await Location.requestForegroundPermissionsAsync();
      if (permission.status !== "granted") {
        setLocationError("Разрешите геопозицию, чтобы увидеть своё местоположение.");
        return;
      }
      const point = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      setDeviceLocation({
        latitude: point.coords.latitude,
        longitude: point.coords.longitude,
        accuracy: point.coords.accuracy,
      });
    } catch {
      setLocationError("Не удалось определить местоположение.");
    } finally {
      setLoadingLocation(false);
    }
  }

  useEffect(() => {
    void loadDeviceLocation();
    let subscription: Location.LocationSubscription | undefined;
    void Location.watchHeadingAsync((event) => {
      setHeading(event.trueHeading ?? event.magHeading ?? 0);
    }).then((value) => {
      subscription = value;
    });
    return () => subscription?.remove();
  }, []);

  useEffect(() => {
    Animated.timing(compassRotation, {
      toValue: -heading,
      duration: 220,
      useNativeDriver: true,
    }).start();
  }, [heading, compassRotation]);

  const distances = useMemo(() => {
    if (!deviceLocation) return [];
    return positions
      .filter((p) => p.location)
      .map((p) => ({
        id: p.user_id,
        name: p.name,
        meters: haversineMeters(deviceLocation, {
          latitude: Number(p.location!.latitude),
          longitude: Number(p.location!.longitude),
        }),
      }))
      .sort((a, b) => a.meters - b.meters);
  }, [deviceLocation, positions]);

  const visible = positions.filter((p) => p.location && p.status !== "unavailable");

  async function shareCoordinates() {
    if (!deviceLocation) {
      setLocationError("Сначала определите местоположение.");
      return;
    }
    const url = yandexNavigatorUrl(deviceLocation);
    await Share.share({
      message: `Моя геопозиция: ${url}`,
      title: "Моя геопозиция",
    });
  }

  return (
    <View style={{ gap: 18 }}>
      <Card style={{ backgroundColor: c.purpleSoft, overflow: "hidden" }}>
        <View style={hero(c).orbOne} />
        <View style={hero(c).orbTwo} />
        <View style={{ gap: 10 }}>
          <Text style={{ color: c.green, fontWeight: "800", fontSize: 12, letterSpacing: 1.1 }}>
            ВЫ НА СВЯЗИ
          </Text>
          <Title subtitle="Только вы решаете, кто видит вашу геопозицию.">{user.name}</Title>
          {loadingLocation ? (
            <View style={{ flexDirection: "row", alignItems: "center", gap: 10 }}>
              <ActivityIndicator color={c.purple} />
              <Text style={ui.muted}>Определяем местоположение…</Text>
            </View>
          ) : locationError ? (
            <Text style={{ color: c.danger }}>{locationError}</Text>
          ) : deviceLocation ? (
            <View style={{ gap: 6 }}>
              <Text style={[ui.text, { fontSize: 22, fontWeight: "800", color: c.ink }]}>
                {deviceLocation.latitude.toFixed(6)}, {deviceLocation.longitude.toFixed(6)}
              </Text>
              <Text style={ui.muted}>
                Точность: {deviceLocation.accuracy === null ? "неизвестна" : `±${Math.round(deviceLocation.accuracy)} м`}
              </Text>
            </View>
          ) : null}
          <Button onPress={shareCoordinates}>Поделиться своими координатами</Button>
        </View>
      </Card>

      {deviceLocation && (
        <Card style={{ padding: 0, overflow: "hidden", height: 220 }}>
          <Map style={{ flex: 1 }} mapStyle={OSM_STYLE as any}>
            <Camera center={[deviceLocation.longitude, deviceLocation.latitude]} zoom={15} />
            <ViewAnnotation
              draggable
              lngLat={[deviceLocation.longitude, deviceLocation.latitude]}
              onDragEnd={(event) => {
                const [longitude, latitude] = event.nativeEvent.lngLat;
                setDeviceLocation((current) => current ? { ...current, latitude, longitude } : current);
              }}
            >
              <View style={[deviceMarker(c).marker, { backgroundColor: c.purple }]}>
                <Text style={{ color: "#fff", fontWeight: "900" }}>●</Text>
              </View>
            </ViewAnnotation>
          </Map>
        </Card>
      )}

      <Card style={{ alignItems: "center", backgroundColor: c.surface }}>
        <Text style={ui.heading}>Компас</Text>
        <View style={compass(c).outer}>
          {compassLabels.map((item) => (
            <Text key={item.label} style={[compass(c).direction, item.position]}>
              {item.label}
            </Text>
          ))}
          <Animated.View
            style={[
              compass(c).needleWrap,
              { transform: [{ rotate: compassRotation.interpolate({ inputRange: [-360, 360], outputRange: ["-360deg", "360deg"] }) }] },
            ]}
          >
            <View style={compass(c).needleNorth} />
            <View style={compass(c).needleSouth} />
          </Animated.View>
        </View>
        <Text style={ui.muted}>{Math.round(heading)}°</Text>
      </Card>

      {workspaceId ? (
        <MapScreen workspaceId={workspaceId} positions={positions} onRefresh={onRefresh} />
      ) : null}
    </View>
  );
}

const compassLabels = [
  { label: "N", position: { top: 8, left: 65 } },
  { label: "NE", position: { top: 22, right: 18 } },
  { label: "E", position: { top: 65, right: 8 } },
  { label: "SE", position: { bottom: 18, right: 18 } },
  { label: "S", position: { bottom: 8, left: 65 } },
  { label: "SW", position: { bottom: 18, left: 18 } },
  { label: "W", position: { top: 65, left: 8 } },
  { label: "NW", position: { top: 22, left: 18 } },
];

const hero = (c: ReturnType<typeof useTheme>["colors"]) =>
  StyleSheet.create({
    orbOne: { position: "absolute", width: 150, height: 150, borderRadius: 75, right: -60, top: -45, backgroundColor: c.greenSoft, opacity: 0.7 },
    orbTwo: { position: "absolute", width: 110, height: 110, borderRadius: 55, right: -20, bottom: -35, backgroundColor: c.purple, opacity: 0.12 },
  });

const deviceMarker = (c: ReturnType<typeof useTheme>["colors"]) =>
  StyleSheet.create({
    marker: {
      width: 30,
      height: 30,
      borderRadius: 15,
      borderWidth: 3,
      borderColor: "#fff",
      alignItems: "center",
      justifyContent: "center",
    },
  });

const compass = (c: ReturnType<typeof useTheme>["colors"]) =>
  StyleSheet.create({
    outer: {
      width: 150,
      height: 150,
      borderRadius: 75,
      borderWidth: 3,
      borderColor: c.line,
      alignItems: "center",
      justifyContent: "center",
      backgroundColor: c.background,
    },
    direction: {
      position: "absolute",
      color: c.muted,
      fontWeight: "800",
      fontSize: 13,
    },
    needleWrap: {
      width: 4,
      height: 96,
      alignItems: "center",
      justifyContent: "space-between",
    },
    needleNorth: {
      width: 0,
      height: 0,
      borderLeftWidth: 11,
      borderRightWidth: 11,
      borderBottomWidth: 46,
      borderLeftColor: "transparent",
      borderRightColor: "transparent",
      borderBottomColor: c.danger,
    },
    needleSouth: {
      width: 0,
      height: 0,
      borderLeftWidth: 11,
      borderRightWidth: 11,
      borderTopWidth: 46,
      borderLeftColor: "transparent",
      borderRightColor: "transparent",
      borderTopColor: c.muted,
    },
  });
