import React from "react";
import { Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { Brand, Button, Card, Title, ui } from "../ui/components";
import { useTheme } from "../ui/theme";
import { HomeDashboard } from "./HomeDashboard";
import Ionicons from "@expo/vector-icons/Ionicons";

export function GuestHomeScreen({ onOpenAuth }: { onOpenAuth: (mode?: "login" | "register") => void }) {
  const { colors: c, dark, toggle } = useTheme();
  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: c.background }}>
      <ScrollView contentContainerStyle={ui.content}>
        <View style={{ flexDirection: "row", alignItems: "center", justifyContent: "space-between" }}>
          <Brand compact />
          <Pressable
            onPress={toggle}
            accessibilityRole="button"
            style={{
              width: 44,
              height: 44,
              borderRadius: 22,
              borderWidth: 1,
              borderColor: c.line,
              alignItems: "center",
              justifyContent: "center",
            }}
          >
            <Ionicons name={dark ? "sunny-outline" : "moon-outline"} size={24} color={c.ink} />
          </Pressable>
        </View>
        <Card style={{ backgroundColor: c.greenSoft }}>
          <Title subtitle="Создайте группу, включите согласие на передачу и используйте SOS, Live-сессии и геозоны.">
            Зарегистрируйтесь, чтобы открыть все возможности
          </Title>
          <View style={{ gap: 10 }}>
            <Button onPress={() => onOpenAuth("register")}>Создать аккаунт</Button>
            <Button tone="secondary" onPress={() => onOpenAuth("login")}>У меня уже есть аккаунт</Button>
          </View>
        </Card>
        <HomeDashboard
          user={{ id: "", name: "Гость" }}
          workspaceId=""
          positions={[]}
          onRefresh={async () => {}}
        />
      </ScrollView>
    </SafeAreaView>
  );
}
