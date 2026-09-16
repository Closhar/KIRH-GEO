export type User = { id: string; name: string; email?: string };
export type Workspace = {
  id: string;
  name: string;
  owner_user_id: string;
  billing_owner_user_id: string;
};
export type Group = { id: string; name: string };
export type Member = { id: string; name: string };
export type Point = {
  id: string;
  latitude: number;
  longitude: number;
  captured_at: string;
  accuracy_m: number;
  battery_pct: number | null;
  mode?: string;
};
export type MemberPosition = {
  user_id: string;
  name: string;
  status: "fresh" | "stale" | "no_location" | "unavailable";
  location: Point | null;
};
export type Grant = {
  id: string;
  group_id: string;
  consent_version: number;
  viewer_user_ids: string[];
  revoked_at: string | null;
  ends_at: string | null;
};
export type AuthMode = "login" | "register" | "join";
export type LocationMode = "idle" | "normal" | "live" | "sport" | "sos";
