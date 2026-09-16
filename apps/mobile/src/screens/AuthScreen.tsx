import React, { useEffect, useState } from "react";
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
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
import { installationId, saveSession, type Session } from "../shared/session";
import Ionicons from "@expo/vector-icons/Ionicons";

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
  const { colors: themeColors, dark, toggle } = useTheme();
  const [mode, setMode] = useState<AuthMode>(initialMode);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [repeatPassword, setRepeatPassword] = useState("");
  const [code, setCode] = useState(initialCode);
  const [understood, setUnderstood] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [verificationStep, setVerificationStep] = useState<"register" | "code" | "reset">("register");
  const [pendingEmail, setPendingEmail] = useState("");
  const [pendingPassword, setPendingPassword] = useState("");
  const [pendingName, setPendingName] = useState("");
  const [codeDigits, setCodeDigits] = useState<string[]>(Array(6).fill(""));
  const [resetPassword, setResetPassword] = useState("");
  const [resendIn, setResendIn] = useState(0);
  async function forgotPassword() {
    setError(""); setNotice("");
    if (!email.includes("@")) {setError("Введите адрес почты."); return;}
    setBusy(true);
    try {
      await api("auth/forgot-password", "POST", {email: email.trim()});
      setPendingEmail(email.trim());
      setVerificationStep("reset");
      setCodeDigits(Array(6).fill(""));
      setResetPassword("");
      startResendTimer();
      setNotice("Код отправлен, если аккаунт существует.");
    }
    catch (e) {setError(e instanceof Error ? e.message : "Восстановление пока недоступно.");}
    finally {setBusy(false);}
  }
  function startResendTimer() {
    setResendIn(120);
  }
  useEffect(() => {
    if (resendIn <= 0) return;
    const timer = setTimeout(() => setResendIn((value) => Math.max(0, value - 1)), 1000);
    return () => clearTimeout(timer);
  }, [resendIn]);
  async function sendRegistrationCode(fields: { name: string; email: string; password: string }) {
    const result = await api<Session>("auth/register", "POST", {
      ...fields,
      installation_id: await installationId(),
      platform: Platform.OS === "ios" ? "ios" : "android",
    });
    await saveSession(result);
    await api("auth/email/verification", "POST");
    setPendingName(fields.name);
    setPendingEmail(fields.email);
    setPendingPassword(fields.password);
    setVerificationStep("code");
    startResendTimer();
  }
  function updateCodeDigit(index: number, value: string) {
    setCodeDigits((current) => {
      const next = [...current];
      next[index] = value.replace(/\D/g, "").slice(-1);
      return next;
    });
  }
  async function submitVerificationCode() {
    const token = codeDigits.join("");
    if (token.length !== 6) {
      setError("Введите код из 6 цифр.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      await api("auth/email/verify", "POST", { token });
      await onAuthenticate("login", { email: pendingEmail, password: pendingPassword });
    } catch (e) {
      setError(e instanceof Error ? e.message : "Не удалось подтвердить код.");
    } finally {
      setBusy(false);
    }
  }
  async function resendVerificationCode() {
    setBusy(true);
    setError("");
    try {
      await api("auth/email/verification", "POST");
      startResendTimer();
      setNotice("Новый код отправлен.");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Не удалось отправить код.");
    } finally {
      setBusy(false);
    }
  }
  async function submitResetCode() {
    const token = codeDigits.join("");
    if (token.length !== 6 || resetPassword.length < 12 || !/\d/.test(resetPassword) || !/[a-zа-я]/i.test(resetPassword)) {
      setError("Введите код и новый пароль от 12 символов с буквами и цифрами.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      await api("auth/reset-password", "POST", {
        token,
        password: resetPassword,
        password_confirmation: resetPassword,
      });
      setNotice("Пароль восстановлен. Войдите с новым паролем.");
      setVerificationStep("register");
      setCodeDigits(Array(6).fill(""));
      setResetPassword("");
      setMode("login");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Не удалось восстановить пароль.");
    } finally {
      setBusy(false);
    }
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
        !/[a-zа-я]/i.test(password) ||
        password !== repeatPassword)
    ) {
      setError("Нужны имя, одинаковые пароли от 12 символов с буквами и цифрами.");
      return;
    }
    setBusy(true);
    try {
      if (mode === "register") {
        await sendRegistrationCode({
          name: name.trim(),
          email: email.trim(),
          password,
        });
      } else {
        await onAuthenticate(
          mode,
          mode === "join"
            ? { name: name.trim(), code: code.replace(/\s/g, "").toUpperCase() }
            : {
                email: email.trim(),
                password,
              },
        );
      }
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
        <View style={{ flexDirection: "row", alignItems: "center", justifyContent: "space-between" }}>
          {onBack ? (
            <Button tone="ghost" onPress={onBack}>← Назад</Button>
          ) : <View />}
          <Pressable
            onPress={toggle}
            accessibilityRole="button"
            style={{
              width: 44,
              height: 44,
              borderRadius: 22,
              borderWidth: 1,
              borderColor: themeColors.line,
              alignItems: "center",
              justifyContent: "center",
            }}
          >
            <Ionicons name={dark ? "sunny-outline" : "moon-outline"} size={24} color={themeColors.ink} />
          </Pressable>
        </View>
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
            Регистрация
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
        <Card style={{ backgroundColor: themeColors.surface, borderColor: themeColors.line }}>
          <Text style={{ fontSize: 19, fontWeight: "700", color: themeColors.ink }}>
            {mode === "join"
              ? "Вас пригласили"
              : mode === "register"
                ? "Начнём с аккаунта"
                : "Рады видеть вас снова"}
          </Text>
          {verificationStep === "register" && (
          <>
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
              {mode === "register" && (
                <Field
                  label="Повторите пароль"
                  value={repeatPassword}
                  onChangeText={setRepeatPassword}
                  secureTextEntry
                  autoComplete="new-password"
                />
              )}
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
                ? "Регистрация"
                : "Войти"}
          </Button>
          {mode === "login" && <Button tone="ghost" disabled={busy} onPress={() => void forgotPassword()}>Забыли пароль?</Button>}
          </>
          )}
          {verificationStep === "code" && (
            <>
              <Text style={{ color: themeColors.muted, lineHeight: 21 }}>
                На адрес {pendingEmail} отправлен проверочный код.
              </Text>
              <View style={{ flexDirection: "row", gap: 8 }}>
                {codeDigits.map((digit, index) => (
                  <TextInput
                    key={index}
                    value={digit}
                    onChangeText={(value) => updateCodeDigit(index, value)}
                    keyboardType="number-pad"
                    maxLength={1}
                    style={{
                      flex: 1,
                      minHeight: 54,
                      borderWidth: 1,
                      borderColor: themeColors.line,
                      borderRadius: 14,
                      color: themeColors.ink,
                      backgroundColor: themeColors.background,
                      textAlign: "center",
                      fontSize: 22,
                      fontWeight: "800",
                    }}
                  />
                ))}
              </View>
              <Button onPress={submitVerificationCode} busy={busy}>
                Подтвердить код
              </Button>
              {resendIn > 0 ? (
                <Text style={{ color: themeColors.muted, textAlign: "center" }}>
                  Повторная отправка через {Math.floor(resendIn / 60)}:
                  {String(resendIn % 60).padStart(2, "0")}
                </Text>
              ) : (
                <Button tone="ghost" onPress={resendVerificationCode} busy={busy}>
                  Отправить код ещё раз
                </Button>
              )}
            </>
          )}
          {verificationStep === "reset" && (
            <>
              <Text style={{ color: themeColors.muted, lineHeight: 21 }}>
                Введите код с почты и новый пароль.
              </Text>
              <View style={{ flexDirection: "row", gap: 8 }}>
                {codeDigits.map((digit, index) => (
                  <TextInput
                    key={index}
                    value={digit}
                    onChangeText={(value) => updateCodeDigit(index, value)}
                    keyboardType="number-pad"
                    maxLength={1}
                    style={{
                      flex: 1,
                      minHeight: 54,
                      borderWidth: 1,
                      borderColor: themeColors.line,
                      borderRadius: 14,
                      color: themeColors.ink,
                      backgroundColor: themeColors.background,
                      textAlign: "center",
                      fontSize: 22,
                      fontWeight: "800",
                    }}
                  />
                ))}
              </View>
              <Field
                label="Новый пароль"
                value={resetPassword}
                onChangeText={setResetPassword}
                secureTextEntry
              />
              <Button onPress={submitResetCode} busy={busy}>
                Восстановить пароль
              </Button>
              {resendIn > 0 ? (
                <Text style={{ color: themeColors.muted, textAlign: "center" }}>
                  Повторная отправка через {Math.floor(resendIn / 60)}:
                  {String(resendIn % 60).padStart(2, "0")}
                </Text>
              ) : (
                <Button tone="ghost" onPress={forgotPassword} busy={busy}>
                  Отправить код ещё раз
                </Button>
              )}
            </>
          )}
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
