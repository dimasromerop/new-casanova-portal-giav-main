export const LS_KEYS = {
  dashboardSnapshot: "casanovaPortal_dashboardSnapshot",
  inboxLatestTs: "casanovaPortal_inboxLatestTs",
  messagesLastSeenTs: "casanovaPortal_messagesLastSeenTs",
  theme: "casanovaPortal_theme",
};

export function lsGetInt(key, fallback = 0) {
  try {
    const value = window.localStorage.getItem(key);
    const parsed = parseInt(value || "", 10);
    return Number.isFinite(parsed) ? parsed : fallback;
  } catch {
    return fallback;
  }
}

export function lsSetInt(key, value) {
  try {
    window.localStorage.setItem(key, String(value));
  } catch {
  }
}

export function lsGet(key, fallback = "") {
  try {
    const value = window.localStorage.getItem(key);
    return typeof value === "string" && value !== "" ? value : fallback;
  } catch {
    return fallback;
  }
}

export function lsSet(key, value) {
  try {
    if (value === null || value === undefined || value === "") {
      window.localStorage.removeItem(key);
      return;
    }
    window.localStorage.setItem(key, String(value));
  } catch {
  }
}

export function resolveInitialTheme() {
  const stored = String(lsGet(LS_KEYS.theme, "")).toLowerCase();
  if (stored === "dark" || stored === "light") return stored;
  try {
    if (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
      return "dark";
    }
  } catch {
  }
  return "light";
}
