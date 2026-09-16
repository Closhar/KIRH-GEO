import React, { useEffect, useState } from "react";
import { ActivityIndicator, StyleSheet, Text, View } from "react-native";
import * as Location from "expo-location";
import { Button, Card, Title } from "../ui/components";
import { useTheme } from "../ui/theme";
import { MapScreen } from "./MapScreen";
import type { MemberPosition, User } from "./types";

type DeviceLocation = {
  latitude: number;
  longitude: number;
  accuracy: number | null;
};

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
  }, []);

  return (
    <View style={{ gap: 18 }}>
      <Card style={{ backgroundColor: c.purpleSoft }}>
        <Title subtitle="Ваше устройство может показывать позицию только с вашего согласия.">
          Здравствуйте, {user.name}
        </Title>
        {loadingLocation ? (
          <View style={styles(c).locationRow}>
            <ActivityIndicator color={c.purple} />
            <Text style={{ color: c.muted }}>Определяем местоположение…</Text>
          </View>
        ) : locationError ? (
          <Text style={{ color: c.danger }}>{locationError}</Text>
        ) : deviceLocation ? (
          <View style={{ gap: 6 }}>
            <Text style={styles(c).coordinate}>
              {deviceLocation.latitude.toFixed(6)}, {deviceLocation.longitude.toFixed(6)}
            </Text>
            <Text style={{ color: c.muted }}>
              Точность:{" "}
              {deviceLocation.accuracy === null
                ? "неизвестна"
                : `±${Math.round(deviceLocation.accuracy)} м`}
            </Text>
          </View>
        ) : null}
        <Button onPress={onSendCoordinates}>Отправить свои координаты</Button>
      </Card>
      <MapScreen
        workspaceId={workspaceId}
        positions={positions}
        onRefresh={onRefresh}
      />
    </View>
  );
}

const styles = (c: ReturnType<typeof useTheme>["colors"]) =>
  StyleSheet.create({
    locationRow: { flexDirection: "row", alignItems: "center", gap: 10 },
    coordinate: { fontSize: 18, fontWeight: "800", color: c.ink },
  });
