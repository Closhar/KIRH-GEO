import React, { PropsWithChildren } from "react";
import {
  ActivityIndicator,
  Image,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  TextInputProps,
  View,
  ViewStyle,
} from "react-native";
import { colors as c, useTheme } from "./theme";
import Ionicons from "@expo/vector-icons/Ionicons";

export function Brand({ compact = false }: { compact?: boolean }) {
  const { colors: c } = useTheme();
  return (
    <View style={s.brand}>
      <Image
        source={require("../../assets/kt-geo-logo.png")}
        style={compact ? s.logoSmall : s.logo}
        resizeMode="contain"
        accessibilityLabel="KIRH GEO"
      />
      <View>
        <Text style={{ color: c.purple, fontWeight: "800", fontSize: 19, letterSpacing: 1.5 }}>
          KIRH GEO
        </Text>
        <Text style={{ color: c.muted, fontSize: 10, marginTop: 3 }}>
          Ближе, где бы вы ни были
        </Text>
      </View>
    </View>
  );
}
export function Button({
  children,
  onPress,
  tone = "primary",
  disabled,
  busy,
  small,
}: PropsWithChildren<{
  onPress: () => void;
  tone?: "primary" | "secondary" | "danger" | "ghost";
  disabled?: boolean;
  busy?: boolean;
  small?: boolean;
}>) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: disabled || busy }}
      disabled={disabled || busy}
      onPress={onPress}
      style={({ pressed }) => [
        s.button,
        small && s.small,
        tone === "secondary" && s.secondary,
        tone === "danger" && s.danger,
        tone === "ghost" && s.ghost,
        (disabled || busy) && { opacity: 0.45 },
        pressed && { opacity: 0.75 },
      ]}
    >
      {busy ? (
        <ActivityIndicator
          color={tone === "secondary" || tone === "ghost" ? c.purple : "#fff"}
        />
      ) : (
        <Text
          style={[
            s.buttonText,
            (tone === "secondary" || tone === "ghost") && { color: c.purple },
          ]}
        >
          {children}
        </Text>
      )}
    </Pressable>
  );
}
export function Field({
  label,
  hint,
  secureTextEntry,
  ...props
}: TextInputProps & { label: string; hint?: string }) {
  const { colors: c } = useTheme();
  const [hidden, setHidden] = React.useState(true);
  if (secureTextEntry) {
    return (
      <View style={{ gap: 7 }}>
        <Text style={s.label}>{label}</Text>
        <View style={[s.input, { flexDirection: "row", alignItems: "center", paddingVertical: 0, backgroundColor: c.background, borderColor: c.line }]}>
          <TextInput
            placeholderTextColor="#A09AAD"
            accessibilityLabel={label}
            style={{ flex: 1, color: c.ink, fontSize: 16, paddingVertical: 13 }}
            secureTextEntry={hidden}
            {...props}
          />
          <Pressable onPress={() => setHidden(!hidden)} accessibilityRole="button" style={{ paddingHorizontal: 12 }}>
            <Ionicons name={hidden ? "eye-outline" : "eye-off-outline"} size={22} color={c.muted} />
          </Pressable>
        </View>
        {hint && <Text style={s.hint}>{hint}</Text>}
      </View>
    );
  }
  return (
    <View style={{ gap: 7 }}>
      <Text style={s.label}>{label}</Text>
      <TextInput
        placeholderTextColor="#A09AAD"
        accessibilityLabel={label}
        style={s.input}
        {...props}
      />
      {hint && <Text style={s.hint}>{hint}</Text>}
    </View>
  );
}
export function Card({
  children,
  style,
}: PropsWithChildren<{ style?: ViewStyle }>) {
  return <View style={[s.card, style]}>{children}</View>;
}
export function Title({
  children,
  subtitle,
}: PropsWithChildren<{ subtitle?: string }>) {
  const { colors: c } = useTheme();
  return (
    <View style={{ gap: 6 }}>
      <Text style={{ fontSize: 30, lineHeight: 37, letterSpacing: -0.8, fontWeight: "800", color: c.ink }}>
        {children}
      </Text>
      {subtitle && (
        <Text style={{ fontSize: 15, lineHeight: 23, color: c.muted }}>{subtitle}</Text>
      )}
    </View>
  );
}
export function Check({
  value,
  onChange,
  children,
}: PropsWithChildren<{ value: boolean; onChange: (value: boolean) => void }>) {
  return (
    <Pressable
      accessibilityRole="checkbox"
      accessibilityState={{ checked: value }}
      onPress={() => onChange(!value)}
      style={s.checkRow}
    >
      <View
        style={[
          s.check,
          value && { backgroundColor: c.purple, borderColor: c.purple },
        ]}
      >
        <Text style={{ color: "#fff", fontWeight: "800" }}>
          {value ? "✓" : ""}
        </Text>
      </View>
      <Text style={s.checkLabel}>{children}</Text>
    </Pressable>
  );
}
export function Chip({
  children,
  active,
  onPress,
}: PropsWithChildren<{ active?: boolean; onPress: () => void }>) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected: active }}
      onPress={onPress}
      style={[s.chip, active && s.activeChip]}
    >
      <Text style={{ color: active ? c.purple : c.muted, fontWeight: "600" }}>
        {children}
      </Text>
    </Pressable>
  );
}
export function Notice({
  children,
  danger,
}: PropsWithChildren<{ danger?: boolean }>) {
  return (
    <View
      accessibilityRole="alert"
      style={[s.notice, danger && { backgroundColor: c.dangerSoft }]}
    >
      <Text style={{ color: danger ? c.danger : c.purple, lineHeight: 21 }}>
        {children}
      </Text>
    </View>
  );
}
export const ui = StyleSheet.create({
  body: { fontSize: 15, lineHeight: 23, color: c.ink },
  page: { flex: 1, backgroundColor: c.background },
  content: { padding: 20, paddingBottom: 35, gap: 18 },
  row: { flexDirection: "row", alignItems: "center", gap: 12 },
  wrap: { flexDirection: "row", flexWrap: "wrap", gap: 8 },
  heading: { fontSize: 19, fontWeight: "700", color: c.ink },
  text: { fontSize: 15, lineHeight: 23, color: c.ink },
  muted: { fontSize: 13, lineHeight: 20, color: c.muted },
  divider: { height: 1, backgroundColor: c.line },
});
const s = StyleSheet.create({
  brand: { flexDirection: "row", alignItems: "center", gap: 10 },
  logo: { width: 60, height: 60 },
  logoSmall: { width: 40, height: 40 },
  brandName: {
    color: c.purple,
    fontWeight: "800",
    fontSize: 19,
    letterSpacing: 1.5,
  },
  brandCaption: { color: c.muted, fontSize: 10, marginTop: 3 },
  button: {
    minHeight: 52,
    borderRadius: 16,
    paddingHorizontal: 19,
    paddingVertical: 14,
    backgroundColor: c.purple,
    alignItems: "center",
    justifyContent: "center",
  },
  small: { minHeight: 39, paddingVertical: 9, borderRadius: 12 },
  secondary: { backgroundColor: c.purpleSoft },
  danger: { backgroundColor: c.danger },
  ghost: { backgroundColor: "transparent" },
  buttonText: { color: "#fff", fontWeight: "700", fontSize: 15 },
  input: {
    backgroundColor: "#FAF9FC",
    borderWidth: 1,
    borderColor: c.line,
    borderRadius: 14,
    color: c.ink,
    paddingHorizontal: 15,
    paddingVertical: 13,
    minHeight: 49,
    fontSize: 16,
  },
  label: { color: c.ink, fontSize: 13, fontWeight: "600" },
  hint: { fontSize: 12, color: c.muted },
  card: {
    backgroundColor: c.surface,
    borderRadius: 24,
    borderWidth: 1,
    borderColor: "#EEEDF4",
    padding: 20,
    gap: 16,
  },
  title: {
    fontSize: 30,
    lineHeight: 37,
    letterSpacing: -0.8,
    fontWeight: "800",
    color: c.ink,
  },
  subtitle: { fontSize: 15, lineHeight: 23, color: c.muted },
  checkRow: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 11,
    paddingVertical: 6,
  },
  check: {
    width: 23,
    height: 23,
    borderWidth: 1.5,
    borderColor: c.line,
    borderRadius: 7,
    alignItems: "center",
    justifyContent: "center",
  },
  checkLabel: { flex: 1, color: c.ink, fontSize: 14, lineHeight: 22 },
  chip: {
    paddingHorizontal: 15,
    paddingVertical: 10,
    borderRadius: 20,
    backgroundColor: c.surface,
    borderWidth: 1,
    borderColor: c.line,
  },
  activeChip: { backgroundColor: c.purpleSoft, borderColor: "#CFC0E5" },
  notice: { padding: 14, borderRadius: 14, backgroundColor: c.purpleSoft },
});
