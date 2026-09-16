import { createContext, createElement, useContext, useMemo, useState, type ReactNode } from "react";

export const lightColors = {
  background: "#F5F5FA",
  surface: "#FFFFFF",
  ink: "#252139",
  muted: "#797588",
  purple: "#56318F",
  purpleSoft: "#EEE7FA",
  green: "#00B58B",
  greenSoft: "#E1F8EF",
  line: "#E5E3ED",
  danger: "#CF3B58",
  dangerSoft: "#FCE9EE",
  amber: "#976518",
};

export const darkColors: typeof lightColors = {
  background: "#14131C",
  surface: "#1F1D2B",
  ink: "#F4F1FA",
  muted: "#AAA4B8",
  purple: "#B79AE8",
  purpleSoft: "#312A4A",
  green: "#36D7B0",
  greenSoft: "#173C32",
  line: "#383347",
  danger: "#FF6B84",
  dangerSoft: "#47202A",
  amber: "#F2C879",
};

type ThemeColors = typeof lightColors;
type ThemeContextValue = {
  dark: boolean;
  toggle: () => void;
  colors: ThemeColors;
};

const ThemeContext = createContext<ThemeContextValue>({
  dark: false,
  toggle: () => {},
  colors: lightColors,
});

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [dark, setDark] = useState(false);
  const value = useMemo<ThemeContextValue>(() => ({
    dark,
    toggle: () => setDark((current) => !current),
    colors: dark ? darkColors : lightColors,
  }), [dark]);

  return createElement(ThemeContext.Provider, { value }, children);
}

export function useTheme(): ThemeContextValue {
  return useContext(ThemeContext);
}

// Kept for the few modules that only need light-mode constants.
export const colors = lightColors;
