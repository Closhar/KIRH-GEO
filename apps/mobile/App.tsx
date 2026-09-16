import React, { useEffect, useState } from "react";
import { ActivityIndicator, Alert, Linking, Platform, StyleSheet, Text, View } from "react-native";
import { SafeAreaProvider, SafeAreaView } from "react-native-safe-area-context";
import { StatusBar } from "expo-status-bar";
import * as LocalAuthentication from "expo-local-authentication";
import { api } from "./src/shared/api";
import {
  getBiometricEnabled,
  getSession,
  installationId,
  saveSession,
  type Session,
} from "./src/shared/session";
import { pauseTracking } from "./src/features/location/engine";
import { AuthScreen } from "./src/screens/AuthScreen";
import { GuestHomeScreen } from "./src/screens/GuestHomeScreen";
import { HomeScreen } from "./src/screens/HomeScreen";
import type { AuthMode, User } from "./src/screens/types";
import { ThemeProvider, useTheme } from "./src/ui/theme";
import { Button } from "./src/ui/components";

function parseInviteCode(url: string): string | null {
  const match = url.match(/[?&]code=([A-Za-z0-9]{10})/);
  return match ? match[1].toUpperCase() : null;
}

function Root() {
  const { colors, dark } = useTheme();
  const [session, setSession] = useState<Session | null>(null);
  const [loading, setLoading] = useState(true);
  const [locked, setLocked] = useState(false);
  const [showAuth, setShowAuth] = useState(false);
  const [authMode, setAuthMode] = useState<AuthMode>("login");
  const [joinCode, setJoinCode] = useState("");

  async function loadSession() {
    setLoading(true);
    try {
      if (await getBiometricEnabled()) {
        const supported =
          (await LocalAuthentication.hasHardwareAsync()) &&
          (await LocalAuthentication.isEnrolledAsync());
        if (supported) {
          const result = await LocalAuthentication.authenticateAsync({
            promptMessage: "Войдите по отпечатку пальца",
            cancelLabel: "Отмена",
          });
          if (!result.success) {
            setLocked(true);
            return;
          }
        }
      }
      let current = await getSession();
      if (current && !current.user) {
        const me = await api<{ user: User }>("auth/me");
        current = { ...current, user: me.user };
        await saveSession(current);
      }
      setSession(current);
    } catch {
      Alert.alert("Не удалось открыть сессию", "Проверьте подключение и войдите заново.");
    } finally {
      setLoading(false);
    }
  }

  async function unlock() {
    try {
      const result = await LocalAuthentication.authenticateAsync({
        promptMessage: "Войдите по отпечатку пальца",
        cancelLabel: "Отмена",
      });
      if (!result.success) return;
      setLocked(false);
      await loadSession();
    } catch {
      Alert.alert("Не удалось проверить отпечаток", "Попробуйте ещё раз.");
    }
  }

  useEffect(() => {
    const handleUrl = (url: string) => {
      const code = parseInviteCode(url);
      if (code) {
        setJoinCode(code);
        setAuthMode("join");
        setShowAuth(true);
      }
    };
    void Linking.getInitialURL().then((url) => {
      if (url) handleUrl(url);
    }).catch(() => {});
    const subscription = Linking.addEventListener("url", ({ url }) => handleUrl(url));
    return () => subscription.remove();
  }, []);

  useEffect(() => {
    void loadSession();
  }, []);

  async function authenticate(mode: AuthMode, fields: Record<string, string>) {
    const result = await api<Session>(
      mode === "join" ? "invitations/join" : `auth/${mode}`,
      "POST",
      {
        ...fields,
        installation_id: await installationId(),
        platform: Platform.OS === "ios" ? "ios" : "android",
      },
    );
    await saveSession(result);
    setJoinCode("");
    setShowAuth(false);
    setSession(result);
  }

  async function logout() {
    let paused = { pendingRevocation: false };
    try {
      paused = await pauseTracking();
    } catch {
      paused = { pendingRevocation: false };
    }
    if (paused.pendingRevocation) {
      throw new Error(
        "GPS остановлен. Дождитесь сети и отзыва доступа на сервере перед выходом из аккаунта.",
      );
    }
    try {
      await api("location/pause", "POST");
    } catch {
      // Best effort server-side pause.
    }
    await api("auth/logout", "POST");
    await saveSession(null);
    setSession(null);
  }

  return (
    <SafeAreaProvider>
      <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }}>
        <StatusBar style={dark ? "light" : "dark"} />
        {loading ? (
          <View style={{ flex: 1, justifyContent: "center" }}>
            <ActivityIndicator size="large" color={colors.purple} />
          </View>
        ) : locked ? (
          <View style={lockStyles(colors.background).container}>
            <Text style={lockStyles(colors.ink).lockTitle}>Приложение заблокировано</Text>
            <Text style={{ color: colors.muted, textAlign: "center", lineHeight: 22 }}>
              Вход по отпечатку пальца включён. Подтвердите свою личность.
            </Text>
            <Button onPress={() => void unlock()}>Войти по отпечатку пальца</Button>
          </View>
        ) : session?.user ? (
          <HomeScreen
            user={session.user}
            onLogout={logout}
            onDeleted={() => setSession(null)}
          />
        ) : showAuth ? (
          <AuthScreen
            onAuthenticate={authenticate}
            initialMode={authMode}
            initialCode={joinCode}
            onBack={() => {
              setShowAuth(false);
              setJoinCode("");
            }}
          />
        ) : (
          <GuestHomeScreen
            onOpenAuth={(mode) => {
              setAuthMode(mode ?? "register");
              setShowAuth(true);
            }}
          />
        )}
      </SafeAreaView>
    </SafeAreaProvider>
  );
}

const lockStyles = (color: string) =>
  StyleSheet.create({
    container: {
      flex: 1,
      justifyContent: "center",
      alignItems: "center",
      padding: 28,
      gap: 18,
    },
    lockTitle: { color, fontSize: 24, fontWeight: "800", textAlign: "center" },
  });

export default function App() {
  return (
    <ThemeProvider>
      <Root />
    </ThemeProvider>
  );
}
