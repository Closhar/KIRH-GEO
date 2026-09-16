import React from "react";
import { ScrollView, StyleSheet, Text, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { Button, Card, Title, ui } from "../ui/components";
import { useTheme } from "../ui/theme";
import { HomeDashboard } from "./HomeDashboard";

export function GuestHomeScreen({ onOpenAuth }: { onOpenAuth: (mode?: "login" | "register") => void }) {
  const { colors: c } = useTheme();
  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: c.background }}>
      <ScrollView contentContainerStyle={ui.content}>
        <HomeDashboard
          user={{ id: "", name: "Гость" }}
          workspaceId=""
          positions={[]}
          onRefresh={async () => {}}
        />
        <Card style={{ backgroundColor: c.greenSoft }}>
          <Title subtitle="Создайте группу, включите согласие на передачу и используйте SOS, Live-сессии и геозоны.">
            Зарегистрируйтесь, чтобы открыть все возможности
          </Title>
          <View style={{ gap: 10 }}>
            <Button onPress={() => onOpenAuth("register")}>Создать аккаунт</Button>
            <Button tone="secondary" onPress={() => onOpenAuth("login")}>У меня уже есть аккаунт</Button>
          </View>
        </Card>
      </ScrollView>
    </SafeAreaView>
  );
}
