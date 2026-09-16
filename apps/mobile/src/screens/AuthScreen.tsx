import React, { useState } from "react";
import {
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from "react-native";
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
import { AuthMode } from "./types";
import { api } from "../shared/api";

export function AuthScreen({
  onAuthenticate,
  initialMode = "login",
  initialCode = "",
  onBack,
}: {
  onAuthenticate: (
    mode: AuthMode,
    fields: Record<string, string>,
  ) => Promise<void>;
  initialMode?: AuthMode;
  initialCode?: string;
  onBack?: () => void;
}) {
  const { colors: themeColors } = useTheme();
  const [mode, setMode] = useState<AuthMode>(initialMode);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [code, setCode] = useState(initialCode);
  const [understood, setUnderstood] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  async function forgotPassword() {
    setError(""); setNotice("");
    if (!email.includes("@")) {setError("Введите адрес почты."); return;}
    setBusy(true);
    try {await api("auth/forgot-password", "POST", {email: email.trim()}); setNotice("Если аккаунт существует, письмо со ссылкой отправлено.");}
    catch (e) {setError(e instanceof Error ? e.message : "Восстановление пока недоступно.");}
    finally {setBusy(false);}
  }
  async function submit() {
    setError("");
    if (
      mode === "join" &&
      (!name.trim() || code.replace(/\s/g, "").length !== 10)
    ) {
      setError("Укажите имя и 10 символов кода приглашения.");
      return;
    }
    if (mode !== "join" && (!email.includes("@") || !password)) {
      setError("Введите адрес почты и пароль.");
      return;
    }
    if (
      mode === "register" &&
      (!name.trim() ||
        password.length < 12 ||
        !/\d/.test(password) ||
        !/[a-zа-я]/i.test(password))
    ) {
      setError("Нужны имя и пароль от 12 символов с буквами и цифрами.");
      return;
    }
    setBusy(true);
    try {
      await onAuthenticate(
        mode,
        mode === "join"
          ? { name: name.trim(), code: code.replace(/\s/g, "").toUpperCase() }
          : {
              email: email.trim(),
              password,
              ...(mode === "register" ? { name: name.trim() } : {}),
            },
      );
    } catch (e) {
      setError(
        e instanceof Error
          ? e.message
          : "Не удалось войти. Попробуйте ещё раз.",
      );
    } finally {
      setBusy(false);
    }
  }
  return (
    <KeyboardAvoidingView
      style={[ui.page, { backgroundColor: themeColors.background }]}
      behavior={Platform.OS === "ios" ? "padding" : undefined}
    >
      <ScrollView
        contentContainerStyle={[ui.content, { paddingTop: 25 }]}
        keyboardShouldPersistTaps="handled"
      >
        {onBack && (
          <Button tone="ghost" onPress={onBack}>← Назад</Button>
        )}
        <Brand />
        <View style={s.hero}>
          <View style={s.orbitOne} />
          <View style={s.orbitTwo} />
          <View style={s.badge}>
            <Text style={{ color: c.green, fontWeight: "700" }}>
              ● С вашего разрешения
            </Text>
          </View>
          <Title subtitle="Знайте, что близкие добрались. Делитесь своим местом только с теми, кого выбрали.">
            Свои люди.{"\n"}На одной карте.
          </Title>
        </View>
        <View style={ui.wrap}>
          <Chip
            active={mode === "login"}
            onPress={() => {
              setMode("login");
              setError("");
            }}
          >
            Войти
          </Chip>
          <Chip
            active={mode === "register"}
            onPress={() => {
              setMode("register");
              setError("");
            }}
          >
            Создать группу
          </Chip>
          <Chip
            active={mode === "join"}
            onPress={() => {
              setMode("join");
              setError("");
            }}
          >
            Есть код
          </Chip>
        </View>
        <Card>
          <Text style={ui.heading}>
            {mode === "join"
              ? "Вас пригласили"
              : mode === "register"
                ? "Начнём с аккаунта"
                : "Рады видеть вас снова"}
          </Text>
          {mode !== "login" && (
            <Field
              label="Как вас зовут"
              value={name}
              onChangeText={setName}
              placeholder="Ваше имя"
              autoComplete="name"
            />
          )}
          {mode === "join" ? (
            <>
              <Field
                label="Код приглашения"
                value={code}
                onChangeText={setCode}
                placeholder="10 символов"
                maxLength={12}
                autoCapitalize="characters"
                autoCorrect={false}
              />
              <Text style={ui.muted}>
                Вход по коду создаст отдельный аккаунт на этом телефоне. Код
                действует 15 минут. После входа вы увидите группу и отдельно
                выберете получателей координат.
              </Text>
            </>
          ) : (
            <>
              <Field
                label="Электронная почта"
                value={email}
                onChangeText={setEmail}
                keyboardType="email-address"
                autoCapitalize="none"
                autoComplete="email"
                placeholder="you@example.ru"
              />
              <Field
                label="Пароль"
                value={password}
                onChangeText={setPassword}
                secureTextEntry
                autoComplete={
                  mode === "register" ? "new-password" : "current-password"
                }
                hint={
                  mode === "register"
                    ? "От 12 символов, буквы и цифры"
                    : undefined
                }
              />
            </>
          )}
          {mode !== "login" && (
            <Check value={understood} onChange={setUnderstood}>
              Я понимаю: присоединение к группе не включает GPS. Передача
              начнётся только после моего отдельного согласия.
            </Check>
          )}
          {error ? <Notice danger>{error}</Notice> : null}
          {notice ? <Notice>{notice}</Notice> : null}
          <Button
            onPress={submit}
            busy={busy}
            disabled={mode !== "login" && !understood}
          >
            {mode === "join"
              ? "Присоединиться по коду"
              : mode === "register"
                ? "Создать аккаунт"
                : "Войти"}
          </Button>
          {mode === "login" && <Button tone="ghost" disabled={busy} onPress={() => void forgotPassword()}>Забыли пароль?</Button>}
        </Card>
        <View style={s.footer}>
          <Text style={s.shield}>◇</Text>
          <Text style={[ui.muted, { flex: 1 }]}>
            У вас всегда есть кнопка паузы. Ни владелец группы, ни администратор
            не могут включить передачу за вас.
          </Text>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
const s = StyleSheet.create({
  hero: { paddingTop: 18, paddingBottom: 14, overflow: "hidden", gap: 15 },
  orbitOne: {
    position: "absolute",
    width: 130,
    height: 130,
    borderRadius: 65,
    right: -70,
    top: 0,
    backgroundColor: c.greenSoft,
  },
  orbitTwo: {
    position: "absolute",
    width: 90,
    height: 90,
    borderRadius: 45,
    right: -50,
    top: 80,
    backgroundColor: c.purpleSoft,
  },
  badge: {
    alignSelf: "flex-start",
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 18,
    backgroundColor: c.greenSoft,
  },
  footer: {
    flexDirection: "row",
    gap: 12,
    alignItems: "center",
    paddingHorizontal: 10,
  },
  shield: { fontSize: 35, color: c.green },
});
