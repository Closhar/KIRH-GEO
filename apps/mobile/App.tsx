import React, { useEffect, useState } from "react";
import { ActivityIndicator, Alert, Platform, View } from "react-native";
import { SafeAreaProvider, SafeAreaView } from "react-native-safe-area-context";
import { StatusBar } from "expo-status-bar";
import { api } from "./src/shared/api";
import {
  getSession,
  installationId,
  saveSession,
  Session,
} from "./src/shared/session";
import { pauseTracking } from "./src/features/location/engine";
import { AuthScreen } from "./src/screens/AuthScreen";
import { HomeScreen } from "./src/screens/HomeScreen";
import type { AuthMode, User } from "./src/screens/types";
import { colors } from "./src/ui/theme";

export default function App() {
  const [session, setSession] = useState<Session | null>(null);
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    getSession()
      .then(async (current) => {
        if (current && !current.user) {
          const me = await api<{ user: User }>("auth/me");
          current = { ...current, user: me.user };
          await saveSession(current);
        }
        setSession(current);
      })
      .catch(() => {
        Alert.alert(
          "Не удалось открыть сессию",
          "Проверьте подключение и войдите заново.",
        );
      })
      .finally(() => setLoading(false));
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
    setSession(result);
  }
  async function logout() {
    const paused = await pauseTracking();
    if (paused.pendingRevocation)
      throw new Error(
        "GPS остановлен. Дождитесь сети и отзыва доступа на сервере перед выходом из аккаунта.",
      );
    await api("auth/logout", "POST");
    await saveSession(null);
    setSession(null);
  }
  return (
    <SafeAreaProvider>
      <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }}>
        <StatusBar style="dark" />
        {loading ? (
          <View style={{ flex: 1, justifyContent: "center" }}>
            <ActivityIndicator size="large" color={colors.purple} />
          </View>
        ) : session?.user ? (
          <HomeScreen user={session.user} onLogout={logout} onDeleted={() => setSession(null)} />
        ) : (
          <AuthScreen onAuthenticate={authenticate} />
        )}
      </SafeAreaView>
    </SafeAreaProvider>
  );
}
