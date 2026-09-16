import React, { useEffect, useMemo, useState } from "react";
import { ActivityIndicator, Animated, StyleSheet, Text, View } from "react-native";
import * as Location from "expo-location";
import { Button, Card, Title, ui } from "../ui/components";
import { useTheme } from "../ui/theme";
import { MapScreen } from "./MapScreen";
import type { MemberPosition, User } from "./types";

type DeviceLocation = {
  latitude: number;
  longitude: number;
  accuracy: number | null;
};

function haversineMeters(a: DeviceLocation, b: { latitude: number; longitude: number }) {
  const R = 6371000;
  const toRad = (value: number) => (value * Math.PI) / 180;
  const dLat = toRad(b.latitude - a.latitude);
  const dLon = toRad(b.longitude - a.longitude);
  const lat1 = toRad(a.latitude);
  const lat2 = toRad(b.latitude);
  const h =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

function formatDistance(meters: number) {
  return meters < 1000
    ? `${Math.round(meters)} м`
    : `${(meters / 1000).toFixed(1)} км`;
}

export function HomeDashboard({
  user,
  workspaceId,
  positions,
  onRefresh,
  onSendCoordinates,
}: {
  user: User;
  workspaceId: string;
  positions: MemberPosition[];
  onRefresh: () => Promise<void>;
  onSendCoordinates: () => void;
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
      const point = await Location.getCurrentPositionAsync({
        accuracy: Location.Accuracy.Balanced,
      });
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
      const next = event.trueHeading ?? event.magHeading;
      setHeading(next || 0);
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

  return (
    <View style={{ gap: 18 }}>
      <Card style={{ backgroundColor: c.purpleSoft, overflow: "hidden" }}>
        <View style={hero(c).orbOne} />
        <View style={hero(c).orbTwo} />
        <View style={{ gap: 10 }}>
          <Text style={{ color: c.green, fontWeight: "800", fontSize: 12, letterSpacing: 1.1 }}>
            ВЫ НА СВЯЗИ
          </Text>
          <Title subtitle="Только вы решаете, кто видит вашу геопозицию.">
            {user.name}
          </Title>
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
                Точность:{" "}
                {deviceLocation.accuracy === null
                  ? "неизвестна"
                  : `±${Math.round(deviceLocation.accuracy)} м`}
              </Text>
            </View>
          ) : null}
          <Button onPress={onSendCoordinates}>Отправить свои координаты</Button>
        </View>
      </Card>

      <View style={[ui.row, { gap: 14 }]}>
        <Card style={{ flex: 1, alignItems: "center", backgroundColor: c.surface }}>
          <Text style={ui.heading}>Компас</Text>
          <View style={compass(c).outer}>
            <Text style={compass(c).north}>N</Text>
            <Animated.View style={[compass(c).needleWrap, { transform: [{ rotate: compassRotation.interpolate({ inputRange: [-360, 360], outputRange: ["-360deg", "360deg"] }) }] }]}>
              <View style={compass(c).needleNorth} />
              <View style={compass(c).needleSouth} />
            </Animated.View>
          </View>
          <Text style={ui.muted}>{Math.round(heading)}°</Text>
        </Card>
        <Card style={{ flex: 1, backgroundColor: c.surface }}>
          <Text style={ui.heading}>Рядом</Text>
          {visible.length ? (
            <Text style={ui.text}>
              {visible.length} человек{visible.length === 1 ? "" : "а"} на карте
            </Text>
          ) : (
            <Text style={ui.muted}>Координат пока нет.</Text>
          )}
          {distances.slice(0, 3).map((item) => (
            <Text key={item.id} style={ui.muted}>
              {item.name} · {formatDistance(item.meters)}
            </Text>
          ))}
        </Card>
      </View>

      <MapScreen
        workspaceId={workspaceId}
        positions={positions}
        onRefresh={onRefresh}
      />
    </View>
  );
}

const hero = (c: ReturnType<typeof useTheme>["colors"]) =>
  StyleSheet.create({
    orbOne: {
      position: "absolute",
      width: 150,
      height: 150,
      borderRadius: 75,
      right: -60,
      top: -45,
      backgroundColor: c.greenSoft,
      opacity: 0.7,
    },
    orbTwo: {
      position: "absolute",
      width: 110,
      height: 110,
      borderRadius: 55,
      right: -20,
      bottom: -35,
      backgroundColor: c.purple,
      opacity: 0.12,
    },
  });

const compass = (c: ReturnType<typeof useTheme>["colors"]) =>
  StyleSheet.create({
    outer: {
      width: 120,
      height: 120,
      borderRadius: 60,
      borderWidth: 3,
      borderColor: c.line,
      alignItems: "center",
      justifyContent: "center",
      backgroundColor: c.background,
    },
    north: {
      position: "absolute",
      top: 8,
      color: c.danger,
      fontWeight: "900",
      fontSize: 16,
    },
    needleWrap: {
      width: 4,
      height: 86,
      alignItems: "center",
      justifyContent: "space-between",
    },
    needleNorth: {
      width: 0,
      height: 0,
      borderLeftWidth: 10,
      borderRightWidth: 10,
      borderBottomWidth: 40,
      borderLeftColor: "transparent",
      borderRightColor: "transparent",
      borderBottomColor: c.danger,
    },
    needleSouth: {
      width: 0,
      height: 0,
      borderLeftWidth: 10,
      borderRightWidth: 10,
      borderTopWidth: 40,
      borderLeftColor: "transparent",
      borderRightColor: "transparent",
      borderTopColor: c.muted,
    },
  });
