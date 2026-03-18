/*
  App.portal.viajes-detalle-tabs.jsx
  - Viajes: listado con tabla rica (ancho ampliado + fechas ES)
  - Viaje: vista detalle con breadcrumb + header + tabs (Resumen/Pagos/Facturas/Bonos/Mensajes)
  - Mensajes: timeline por expediente (usa /messages?expediente=ID)
*/

import React, { startTransition, useEffect, useMemo, useRef, useState } from "react";

import InboxView from "./components/InboxView.jsx";
import KpiCard from "./components/KpiCard.jsx";
import DashboardView from "./components/dashboard/DashboardView.jsx";
import TripDetailView from "./components/trip/TripDetailView.jsx";
import ProfileView from "./components/ProfileView.jsx";
import { PortalFooter, Sidebar, Topbar } from "./components/PortalShell.jsx";
import SecurityView from "./components/SecurityView.jsx";
import TripsList from "./components/TripsList.jsx";
import { EmptyState, Notice, Skeleton, TableSkeleton } from "./components/ui.jsx";
import { t, tt } from "./i18n/t.js";
import { api } from "./lib/api.js";
import { formatMsgDate } from "./lib/formatters.js";
import { readParams, setParam } from "./lib/params.js";
import { getBonusesVariant, getPaymentVariant, getStatusVariant } from "./lib/statusBadges.js";
import { LS_KEYS, lsGet, lsGetInt, lsSet, lsSetInt, resolveInitialTheme } from "./lib/storage.js";

/* ===== Local state (frontend-only) =====
   GIAV is read-only from this portal for now. We track "seen" client-side to avoid
   zombie badges and keep UX sane while we wait for API write-back.
*/

const ICON_PROPS = {
  width: 20,
  height: 20,
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.5,
  strokeLinecap: "round",
  strokeLinejoin: "round",
  focusable: "false",
};

const KPI_ICON_PROPS = {
  width: 20,
  height: 20,
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.5,
  strokeLinecap: "round",
  strokeLinejoin: "round",
  focusable: "false",
};

function IconGrid() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <rect x={3} y={3} width={7.5} height={7.5} rx={1.5} />
      <rect x={13.5} y={3} width={7.5} height={7.5} rx={1.5} />
      <rect x={3} y={13.5} width={7.5} height={7.5} rx={1.5} />
      <rect x={13.5} y={13.5} width={7.5} height={7.5} rx={1.5} />
    </svg>
  );
}

function IconMapPin() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <path d="M12 3c-3.866 0-7 3.134-7 7 0 4.25 7 11 7 11s7-6.75 7-11c0-3.866-3.134-7-7-7z" />
      <circle cx={12} cy={10} r={2.2} />
    </svg>
  );
}

function IconChatBubble() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <path d="M5 6h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H8l-4 4V8a2 2 0 0 1 2-2z" />
    </svg>
  );
}

function IconStarBadge() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <path d="M12 4l1.91 3.86 4.27.62-3.09 3.01.73 4.25-3.82-2.01-3.82 2.01.73-4.25-3.09-3.01 4.27-.62z" />
    </svg>
  );
}

function IconClipboardList() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <path d="M8 5h8a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z" />
      <path d="M9 9h6" />
      <path d="M9 13l2 2 4-4" />
    </svg>
  );
}

function IconWallet() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <path d="M5 8h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8z" />
      <path d="M16 12h2" />
      <circle cx={17} cy={11.5} r={1} fill="currentColor" stroke="none" />
    </svg>
  );
}

function IconBed() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path d="M4 12V9.5A2.5 2.5 0 0 1 6.5 7H9a2 2 0 0 1 2 2v3" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M11 12V8.8A1.8 1.8 0 0 1 12.8 7H16a4 4 0 0 1 4 4v1" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M4 12h16v4H4z" fill="none" stroke="currentColor" strokeWidth={1.5} />
      <path d="M6 16v2M18 16v2" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
    </svg>
  );
}

function IconGolfFlag() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path d="M8 20V4" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M8 5c1.2 0 2.2-.8 3.8-.8 1.7 0 2.3.8 3.8.8 1.2 0 1.9-.4 2.4-.7v6.4c-.5.3-1.2.7-2.4.7-1.5 0-2.1-.8-3.8-.8-1.6 0-2.6.8-3.8.8" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinejoin="round" />
      <path d="M6 20h4" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
    </svg>
  );
}

function IconCar() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path d="M6.5 16.5h11a1.5 1.5 0 0 0 1.5-1.5v-3l-1.7-3.6A2.2 2.2 0 0 0 15.3 7H8.7a2.2 2.2 0 0 0-2 1.4L5 12v3a1.5 1.5 0 0 0 1.5 1.5z" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinejoin="round" />
      <path d="M7 12h10" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <circle cx={8.5} cy={16.5} r={1.4} fill="none" stroke="currentColor" strokeWidth={1.5} />
      <circle cx={15.5} cy={16.5} r={1.4} fill="none" stroke="currentColor" strokeWidth={1.5} />
    </svg>
  );
}

function IconBriefcase() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <rect x={5} y={9} width={14} height={12} rx={2} stroke="currentColor" strokeWidth={1.5} fill="none" />
      <path d="M8 9V7h8v2" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M6 13h12" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
    </svg>
  );
}

function IconShieldCheck() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path d="M12 3l6 3v6c0 4-3 7-6 8-3-1-6-4-6-8V6z" fill="none" stroke="currentColor" strokeWidth={1.5} />
      <polyline
        points="9.5 12 11.5 14.5 15.5 10.5"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.5}
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconClockArrow() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <circle cx={12} cy={12} r={8} fill="none" stroke="currentColor" strokeWidth={1.5} />
      <path d="M12 8v4l3 2" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function IconSparkle() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path d="M12 4l2 4 4 .5-3 2.9 1 4.7-4-2-4 2 1-4.7-3-2.9 4-.5z" fill="currentColor" />
    </svg>
  );
}

function IconChartBar() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <rect x={5} y={11} width={3} height={8} fill="currentColor" />
      <rect x={10.5} y={7} width={3} height={12} fill="currentColor" />
      <rect x={16} y={4} width={3} height={15} fill="currentColor" />
    </svg>
  );
}

function IconCalendar() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <rect x={4} y={5} width={16} height={15} rx={3} fill="none" stroke="currentColor" strokeWidth={1.5} />
      <line x1={4} y1={9} x2={20} y2={9} stroke="currentColor" strokeWidth={1.5} />
      <line x1={7} y1={2} x2={7} y2={7} stroke="currentColor" strokeWidth={1.5} />
      <line x1={17} y1={2} x2={17} y2={7} stroke="currentColor" strokeWidth={1.5} />
    </svg>
  );
}

function IconStar() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path d="M12 3l2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 17l-5.2 2.5 1-5.8-4.2-4.1 5.8-.8z" fill="currentColor" />
    </svg>
  );
}

function IconGlobe() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <circle cx={12} cy={12} r={9} fill="none" stroke="currentColor" strokeWidth={1.5} />
      <path d="M3 12h18" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M12 3c3 3 3 15 0 18" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M12 3c-3 3-3 15 0 18" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
    </svg>
  );
}

function IconMoon() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path
        d="M16.7 14.1A6.8 6.8 0 0 1 9.9 7.3c0-.9.2-1.8.5-2.6A8 8 0 1 0 19.3 15c-.8.3-1.7.5-2.6.5z"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.5}
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconUser() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <circle cx={12} cy={9} r={3.2} fill="none" stroke="currentColor" strokeWidth={1.5} />
      <path d="M6.2 20c1.6-3 4-4.5 5.8-4.5s4.2 1.5 5.8 4.5" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
    </svg>
  );
}

function IconLogout() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path d="M10 7V6a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-6a2 2 0 0 1-2-2v-1" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M3 12h9" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M7 8l-4 4 4 4" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

const NAV_ITEMS = [
  {
    key: "dashboard",
    label: "Dashboard",
    view: "dashboard",
    icon: IconGrid,
    isActive: (view) => view === "dashboard",
  },
  {
    key: "trips",
    label: "Viajes",
    view: "trips",
    icon: IconMapPin,
    isActive: (view) => view === "trips" || view === "trip",
  },
  {
    key: "inbox",
    label: "Mensajes",
    view: "inbox",
    icon: IconChatBubble,
    isActive: (view) => view === "inbox",
  },
  {
    key: "mulligans",
    label: "Mulligans",
    view: "mulligans",
    icon: IconStarBadge,
    isActive: (view) => view === "mulligans",
  },
];

function getNavItems({ mulligansEnabled = true } = {}) {
  return NAV_ITEMS.filter((item) => mulligansEnabled || item.key !== "mulligans");
}

/* ===== App ===== */
function MulligansView({ data }) {
  const m = data?.mulligans || {};
  const points = Number(m.points || 0);
  const tier = String(m.tier || "birdie").toLowerCase();
  const spend = Number(m.spend || 0);
  const earned = Number(m.earned || 0);
  const bonus = Number(m.bonus || 0);
  const used = Number(m.used || 0);
  const ledger = Array.isArray(m.ledger) ? m.ledger : [];
  const tierSlug = tier
    .trim()
    .toLowerCase()
    .replace(/\s+/g, "_")
    .replace(/\+/g, "_plus")
    .replace(/-/g, "_");

  const tierLabel = (t) => {
    if (t === "albatross") return "Albatross";
    if (t === "eagle") return "Eagle";
    if (t === "birdie") return "Birdie";
    return t ? t.charAt(0).toUpperCase() + t.slice(1) : "Birdie";
  };

  const fmtMoney = (v) =>
    new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(v || 0);

  const fmtDate = (ts) => {
    const n = Number(ts || 0);
    if (!n) return "—";
    const d = new Date(n * 1000);
    return d.toLocaleDateString("es-ES", { day: "2-digit", month: "2-digit", year: "numeric" });
  };

  const mulliganKpiItems = [
    { key: "balance", label: "Balance", value: points.toLocaleString("es-ES"), icon: <IconSparkle />, colorClass: "is-salmon" },
    { key: "spend", label: "Gasto histórico", value: fmtMoney(spend), icon: <IconChartBar />, colorClass: "is-blue" },
    { key: "sync", label: "Última sincronización", value: fmtDate(m.last_sync), icon: <IconCalendar />, colorClass: "is-lilac" },
    { key: "earned", label: "Ganados", value: earned.toLocaleString("es-ES"), icon: <IconShieldCheck />, colorClass: "is-green" },
    { key: "bonus", label: "Bonus", value: bonus.toLocaleString("es-ES"), icon: <IconStar />, colorClass: "is-salmon" },
    { key: "used", label: "Usados", value: used.toLocaleString("es-ES"), icon: <IconClockArrow />, colorClass: "is-lilac" },
  ];

  return (
    <div className="cp-content">
      <div className="cp-card">
        <div className="cp-card-header">
          <div>
            <div className="cp-card-title">{tt("Tu programa Mulligans")}</div>
            <div className="cp-card-sub">{tt("Puntos y nivel se actualizan automáticamente con tus reservas.")}</div>
          </div>
          <div className={`cp-pill ${tierSlug ? `is-${tierSlug}` : ""}`}>{tierLabel(tier)}</div>
        </div>
        <div className="cp-kpi-card-grid cp-mulligans-kpi-grid">
          {mulliganKpiItems.map((item) => (
            <KpiCard
              key={item.key}
              icon={item.icon}
              label={item.label}
              value={item.value}
              colorClass={item.colorClass}
            />
          ))}
        </div>
        <div className="cp-mt-14">
          <Notice variant="info" title={tt("Cómo funciona")}>
            Los beneficios se activan con una reserva real. Si un año no viajas, mantienes tu nivel, pero no se “dispara” el beneficio.
          </Notice>
        </div>
      </div>

      <div className="cp-card cp-mt-14">
        <div className="cp-card-title">{tt("Histórico")}</div>
        <div className="cp-card-sub">{tt("Movimientos recientes (ganados, bonus y canjes).")}</div>

        {ledger.length === 0 ? (
          <EmptyState title={tt("Aún no hay movimientos")} icon="🧾">
            {tt("Cuando se registren pagos o se aplique un bonus, aparecerán aquí.")}
          </EmptyState>
        ) : (
          <div className="cp-ledger">
            {ledger.map((it) => {
              const pts = Number(it.points || 0);
              const sign = pts >= 0 ? "+" : "";
              const when = it.ts ? fmtDate(it.ts) : "—";
              const type = String(it.type || "");
              const label = type === "bonus" ? "Bonus" : type === "earn" ? "Ganado" : type === "redeem" ? "Canje" : "Movimiento";
              return (
                <div key={it.id || `${it.ts}-${Math.random()}`} className="cp-ledger-row">
                  <div className="cp-ledger-main">
                    <div className="cp-ledger-title">{label}</div>
                    <div className="cp-ledger-sub">{it.note || it.source || "—"}</div>
                  </div>
                  <div className="cp-ledger-right">
                    <div className={`cp-ledger-points ${pts >= 0 ? "is-pos" : "is-neg"}`}>{sign}{pts}</div>
                    <div className="cp-ledger-date">{when}</div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}



function pickHeroImageFromTripDetail(detail) {
  if (!detail || typeof detail !== "object") return "";
  const pkgServices = Array.isArray(detail?.package?.services) ? detail.package.services : [];
  const extras = Array.isArray(detail?.extras) ? detail.extras : [];
  const pool = [...pkgServices, ...extras];
  for (const s of pool) {
    const url = s?.media?.image_url;
    if (typeof url === "string" && url.trim() !== "") return url.trim();
  }
  // fallback: sometimes trip may include a hero image directly
  const tripImg = detail?.trip?.media?.image_url || detail?.trip?.hero_image_url || "";
  return typeof tripImg === "string" ? tripImg.trim() : "";
}

function dashboardSnapshotStorageKey(mock = false) {
  const nonce = String(window.CasanovaPortal?.nonce || "").trim();
  const fingerprint = nonce ? nonce.slice(-12) : "anon";
  return `${LS_KEYS.dashboardSnapshot}_${mock ? "mock" : "live"}_${fingerprint}`;
}

function readDashboardSnapshot(mock = false) {
  const raw = lsGet(dashboardSnapshotStorageKey(mock), "");
  if (!raw) return null;

  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === "object" && parsed.data && typeof parsed.data === "object"
      ? parsed
      : null;
  } catch {
    return null;
  }
}

function persistDashboardSnapshot(mock = false, data = null) {
  if (!data || typeof data !== "object") return;
  lsSet(dashboardSnapshotStorageKey(mock), JSON.stringify({
    savedAt: Date.now(),
    data,
  }));
}

function App() {
  const [route, setRoute] = useState(readParams());
  const [dashboard, setDashboard] = useState(() => readDashboardSnapshot(readParams().mock)?.data ?? null);
  const [dashboardTripDetail, setDashboardTripDetail] = useState(null);
  const [dashboardTripDetailLoading, setDashboardTripDetailLoading] = useState(false);
  const [heroImageUrl, setHeroImageUrl] = useState("");
  const [heroMap, setHeroMap] = useState(null);
  const [loadingDash, setLoadingDash] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [dashErr, setDashErr] = useState(null);
  const [paymentBanner, setPaymentBanner] = useState(null);
  const bannerTimerRef = useRef(null);
  const paymentDismissKey = "casanova_payment_banner_dismissed";

  const [inbox, setInbox] = useState(null);
  const [loadingInbox, setLoadingInbox] = useState(false);
  const [inboxErr, setInboxErr] = useState(null);

  const [inboxLatestTs, setInboxLatestTs] = useState(() => lsGetInt(LS_KEYS.inboxLatestTs, 0));
  const [messagesLastSeenTs, setMessagesLastSeenTs] = useState(() => lsGetInt(LS_KEYS.messagesLastSeenTs, 0));

  const dashboardRequestIdRef = useRef(0);
  const inboxRequestIdRef = useRef(0);
  const profileRequestRef = useRef(null);
  const refreshEffectReadyRef = useRef(false);

  const [profile, setProfile] = useState(null);
  const [profileErr, setProfileErr] = useState(null);
  const [toast, setToast] = useState(null);
  const [theme, setTheme] = useState(() => resolveInitialTheme());
  const impersonation = window.CasanovaPortal?.impersonation || {};
  const isReadOnly = Boolean(impersonation.readOnly);
  const readOnlyMessage = String(impersonation.message || tt("Modo de vista cliente activo. Solo lectura."));
  const isMulligansEnabled = window.CasanovaPortal?.features?.mulligansEnabled !== false;
  const visibleNavItems = useMemo(() => getNavItems({ mulligansEnabled: isMulligansEnabled }), [isMulligansEnabled]);
  const activeView = !isMulligansEnabled && route.view === "mulligans" ? "dashboard" : route.view;

  useEffect(() => {
    const onPop = () => setRoute(readParams());
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);

  useEffect(() => {
    if (isMulligansEnabled || route.view !== "mulligans") return;
    setParam("view", "dashboard");
  }, [isMulligansEnabled, route.view]);

  useEffect(() => {
    lsSet(LS_KEYS.theme, theme);
  }, [theme]);

  // Persist language preference (WPML):
  // - Backend stores the choice in user_meta via /profile/locale.
  // - On load, if the current WPML language differs, redirect once to the preferred language URL.
  useEffect(() => {
    try {
      const current = String(window.CasanovaPortal?.currentLang || "").toLowerCase();
      const preferred = String(window.CasanovaPortal?.preferredLang || "").toLowerCase();
      const redirectUrl = String(window.CasanovaPortal?.preferredRedirectUrl || "");
      if (!current || !preferred || current === preferred) return;
      if (!redirectUrl) return;

      const u = new URL(redirectUrl, window.location.origin);
      // keep current SPA params
      if (window.location.search) u.search = window.location.search;
      if (u.toString() !== window.location.href) {
        window.location.replace(u.toString());
      }
    } catch {
      // ignore
    }
  }, []);

  async function loadProfile(force = false) {
    if (!force && profile) return profile;
    if (profileRequestRef.current) return profileRequestRef.current;

    setProfileErr(null);

    const request = (async () => {
      try {
        const data = await api('/profile');
        startTransition(() => {
          setProfile(data);
          setProfileErr(null);
        });
        return data;
      } catch (e) {
        setProfileErr(e);
        throw e;
      } finally {
        profileRequestRef.current = null;
      }
    })();

    profileRequestRef.current = request;
    return request;
  }

  useEffect(() => {
    const needsProfileNow = activeView === "profile" || activeView === "security";
    if (needsProfileNow) {
      void loadProfile();
      return;
    }

    const loadProfileWhenIdle = () => {
      void loadProfile().catch(() => {
        // Silent fail: profile can retry later when the user opens that area.
      });
    };

    if (typeof window.requestIdleCallback === "function") {
      const idleId = window.requestIdleCallback(loadProfileWhenIdle, { timeout: 1200 });
      return () => {
        if (typeof window.cancelIdleCallback === "function") {
          window.cancelIdleCallback(idleId);
        }
      };
    }

    const timer = window.setTimeout(loadProfileWhenIdle, 1200);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeView]);

  function notify(message, variant = 'info') {
    setToast({ message, variant });
    window.setTimeout(() => setToast(null), 4_000);
  }

  function clearDashboardPresentation() {
    setDashboard(null);
    setDashboardTripDetail(null);
    setDashboardTripDetailLoading(false);
    setHeroImageUrl("");
    setHeroMap(null);
  }

  function applyDashboardPayload(dashRes, { persist = true, mock = route.mock } = {}) {
    if (!dashRes || typeof dashRes !== "object") {
      clearDashboardPresentation();
      return;
    }

    setDashboard(dashRes);

    const nextTripSummary = dashRes?.next_trip_summary && typeof dashRes.next_trip_summary === "object"
      ? dashRes.next_trip_summary
      : null;
    setDashboardTripDetail(nextTripSummary);
    setDashboardTripDetailLoading(false);

    if (nextTripSummary?.map && typeof nextTripSummary.map.url === "string") {
      setHeroMap({
        type: nextTripSummary.map.type || "single",
        url: nextTripSummary.map.url,
        hotels: Array.isArray(nextTripSummary.map.hotels) ? nextTripSummary.map.hotels : [],
      });
    } else {
      setHeroMap(null);
    }

    setHeroImageUrl(pickHeroImageFromTripDetail(nextTripSummary) || "");

    if (persist) {
      persistDashboardSnapshot(mock, dashRes);
    }
  }

  function hydrateDashboardSnapshot(mock = route.mock) {
    const snapshot = readDashboardSnapshot(mock);
    if (!snapshot?.data || typeof snapshot.data !== "object") {
      return false;
    }

    setDashErr(null);
    applyDashboardPayload(snapshot.data, { persist: false, mock });
    setLoadingDash(false);
    return true;
  }

  useEffect(() => {
    const payment = String(route.payment || "").toLowerCase();
    const method = String(route.method || "").toLowerCase();
    const payStatus = String(route.payStatus || "").toLowerCase();

    if (payment === "success") {
      const isPendingConfirmation = payStatus === "checking" || payStatus === "";

      // Informative toast for bank transfers (async confirmation).
      if (method === "bank_transfer" && isPendingConfirmation) {
        notify(tt("Transferencia iniciada. En cuanto el banco la confirme actualizaremos tus pagos."), "info");
      }

      // Evitar que se quede "plantificado" si el usuario recarga o navega.
      const dismissed = window.sessionStorage ? window.sessionStorage.getItem(paymentDismissKey) === "1" : false;
      if (!dismissed) {
        const bannerConfig = isPendingConfirmation
          ? {
              variant: "info",
              title: tt("Pago pendiente de confirmación"),
              body:
                method === "bank_transfer"
                  ? tt("La transferencia ya se ha iniciado. Actualizaremos tus pagos cuando recibamos la confimación del banco.")
                  : tt("Hemos recibido el pago. Gracias, procesamos el cobro y actualizamos tus datos."),
            }
          : {
              variant: "success",
              title: t('payment_registered_title', 'Pago registrado'),
              body: tt("Gracias, procesamos el cobro y actualizamos tus datos."),
            };

        setPaymentBanner(bannerConfig);

        // Auto-hide como red de seguridad (si el usuario no lo cierra).
        if (bannerTimerRef.current) {
          window.clearTimeout(bannerTimerRef.current);
        }
        bannerTimerRef.current = window.setTimeout(() => {
          setPaymentBanner(null);
        }, 8_000);
      }

      setParam("payment", "");
      setParam("pay_status", "");
      setParam("method", "");
    }

    if (payment === "failed") {
      notify(tt("La transferencia no se completó. Si el banco la confirma más tarde, lo verás reflejado aquí."), "warn");
      setParam("payment", "");
      setParam("pay_status", "");
      setParam("method", "");
    }

    return () => {
      if (bannerTimerRef.current) {
        window.clearTimeout(bannerTimerRef.current);
        bannerTimerRef.current = null;
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.payment, route.method, route.payStatus]);

  function dismissPaymentBanner() {
    setPaymentBanner(null);
    try {
      if (window.sessionStorage) window.sessionStorage.setItem(paymentDismissKey, "1");
    } catch {
      // ignore
    }
  }

  async function loadInbox({ refresh = false, background = false } = {}) {
    const requestId = ++inboxRequestIdRef.current;
    const shouldShowLoading = !background || !inbox;

    try {
      if (shouldShowLoading) setLoadingInbox(true);
      setInboxErr(null);

      const qs = route.mock ? "?mock=1" : (refresh ? "?refresh=1" : "");
      const inboxRes = await api(`/inbox${qs}`);
      if (requestId !== inboxRequestIdRef.current) return inboxRes;

      startTransition(() => {
        setInbox(inboxRes);
        setInboxErr(null);
      });
      return inboxRes;
    } catch (e) {
      if (requestId !== inboxRequestIdRef.current) return null;
      if (!inbox) setInboxErr(e);
      return null;
    } finally {
      if (shouldShowLoading && requestId === inboxRequestIdRef.current) {
        setLoadingInbox(false);
      }
    }
  }

  async function loadDashboard(refresh = false, { waitForInbox = false, hasVisibleData = !!dashboard } = {}) {
    const requestId = ++dashboardRequestIdRef.current;
    const hadData = hasVisibleData;
    try {
      if (hadData) setIsRefreshing(true);
      else setLoadingDash(true);

      setDashErr(null);
      const qs = route.mock ? "?mock=1" : (refresh ? "?refresh=1" : "");
      const dashRes = await api(`/dashboard${qs}`);
      if (requestId !== dashboardRequestIdRef.current) return dashRes;

      applyDashboardPayload(dashRes, { persist: true, mock: route.mock });

      if (waitForInbox) {
        await loadInbox({ refresh, background: false });
      } else {
        void loadInbox({ refresh, background: true });
      }
      return dashRes;
    } catch (e) {
      if (requestId !== dashboardRequestIdRef.current) return null;
      if (!dashboard) setDashErr(e);
    } finally {
      if (requestId === dashboardRequestIdRef.current) {
        setIsRefreshing(false);
        setLoadingDash(false);
      }
    }
    return null;
  }

  useEffect(() => {
    const shouldRefreshOnBoot = route.payStatus === "checking" || route.payment === "success" || route.refresh;
    setDashErr(null);
    const hydrated = hydrateDashboardSnapshot(route.mock);
    if (!hydrated) {
      clearDashboardPresentation();
    }
    setInbox(null);
    setInboxErr(null);
    setLoadingInbox(false);
    loadDashboard(shouldRefreshOnBoot, {
      waitForInbox: activeView === "inbox",
      hasVisibleData: hydrated,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.mock]);

  useEffect(() => {
    if (!refreshEffectReadyRef.current) {
      refreshEffectReadyRef.current = true;
      return;
    }
    if (route.payStatus === "checking" || route.payment === "success" || route.refresh) {
      const hydrated = !dashboard ? hydrateDashboardSnapshot(route.mock) : false;
      loadDashboard(true, {
        waitForInbox: activeView === "inbox",
        hasVisibleData: hydrated || !!dashboard,
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.payStatus, route.payment, route.refresh, activeView]);

  useEffect(() => {
    if (activeView !== "inbox") return;
    if (inbox || loadingInbox || inboxErr) return;
    void loadInbox();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeView]);

  useEffect(() => {
    const items = Array.isArray(inbox?.items) ? inbox.items : [];
    if (!items.length) return;
    const latest = items.reduce((max, it) => {
      const d = it?.last_message_at || it?.date;
      const t = d ? new Date(d).getTime() : 0;
      return t > max ? t : max;
    }, 0);
    if (latest) handleLatestTs(latest);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [inbox]);

  function handleLatestTs(ts) {
    if (!ts || !Number.isFinite(ts)) return;
    setInboxLatestTs(ts);
    lsSetInt(LS_KEYS.inboxLatestTs, ts);
  }

  function markMessagesSeen() {
    const now = Date.now();
    setMessagesLastSeenTs(now);
    lsSetInt(LS_KEYS.messagesLastSeenTs, now);
  }

  async function saveProfile(data) {
    if (isReadOnly) {
      notify(readOnlyMessage, "warn");
      return;
    }
    try {
      const res = await api('/profile', { method: 'POST', body: data });
      setProfile(res);
      notify(tt('Perfil actualizado.'), 'success');
    } catch (e) {
      notify(e?.message || tt('No se pudo guardar el perfil.'), 'warn');
    }
  }

  async function changePassword(data) {
    if (isReadOnly) {
      notify(readOnlyMessage, "warn");
      return;
    }
    try {
      await api('/profile/password', { method: 'POST', body: data });
      notify(tt('Contraseña actualizada.'), 'success');
    } catch (e) {
      notify(e?.message || tt('No se pudo actualizar la contraseña.'), 'warn');
    }
  }

  async function setLocale(locale) {
    if (isReadOnly) {
      notify(readOnlyMessage, "warn");
      return;
    }
    try {
      const lang = String(locale || "").slice(0, 2).toLowerCase();
      const res = await api('/profile/locale', { method: 'POST', body: { locale, lang } });
      setProfile((p) => (p ? { ...p, locale: res.locale || locale } : p));

      // Si WPML está activo, el backend nos devuelve la URL del portal en el idioma correcto.
      if (res && typeof res.redirectUrl === "string" && res.redirectUrl.trim() !== "") {
        const u = new URL(res.redirectUrl, window.location.origin);
        // Mantener la vista actual (query params) al cambiar de idioma.
        if (window.location.search) u.search = window.location.search;
        window.location.href = u.toString();
        return;
      }

      notify(t('language_updated', 'Idioma actualizado.'), 'success');
    } catch (e) {
      notify(e?.message || t('language_update_failed', 'No se pudo actualizar el idioma.'), 'warn');
    }
  }

  function go(view) {
    if (view) setParam('view', view);
  }

  function logout() {
    const url = profile?.logoutUrl;
    if (url) window.location.href = url;
    else window.location.href = '/';
  }

  function handleRefresh() {
    void loadDashboard(true, { waitForInbox: activeView === "inbox" });

    if (activeView === "profile" || activeView === "security") {
      void loadProfile(true).catch(() => {
        // Silent fail: profile view already renders a dedicated notice.
      });
    }
  }

  const unreadInbox = inbox?.unread;
  const unreadDash = dashboard?.messages?.unread;
  const unreadFromServer = typeof unreadInbox === "number" ? unreadInbox : (typeof unreadDash === "number" ? unreadDash : 0);

  const unreadCount =
    inboxLatestTs > 0 && messagesLastSeenTs >= inboxLatestTs ? 0 : unreadFromServer;

  const title = useMemo(() => {
    if ((activeView === "viajes" || activeView === "trips")) return t('nav_trips', 'Viajes');
    if (activeView === "trip") return t('nav_trip_detail', 'Detalle del viaje');
    if (activeView === "inbox") return t('nav_messages', 'Mensajes');
    if (activeView === "dashboard") return t('nav_dashboard', 'Dashboard');
    if (activeView === "mulligans") return t('nav_mulligans', 'Mulligans');
    if (activeView === "profile") return t('menu_profile', 'Mi perfil');
    if (activeView === "security") return t('menu_security', 'Seguridad');
    return t('nav_portal', 'Portal');
  }, [activeView]);

  const chipItems = [];
  if (route.mock) chipItems.push(t('mock_mode', 'Modo prueba'));
  if (isReadOnly) chipItems.push(tt("Vista cliente"));
  const chip = chipItems.length ? chipItems.join(" · ") : null;

  return (
    <div className="cp-app" data-theme={theme}>
      <Sidebar view={activeView} unread={unreadCount} items={visibleNavItems} theme={theme} />
      <main className="cp-main">
        <Topbar
          title={title}
          chip={chip}
          onRefresh={handleRefresh}
          isRefreshing={isRefreshing}
          profile={profile}
          onGo={go}
          onLogout={logout}
          onLocale={setLocale}
          readOnly={isReadOnly}
          theme={theme}
          onToggleTheme={() => setTheme((current) => (current === "dark" ? "light" : "dark"))}
        />
        {toast ? (
          <div className={`cp-toast is-${toast.variant || 'info'}`}>{toast.message}</div>
        ) : null}
        {paymentBanner ? (
          <div className="cp-content">
            <Notice
              variant={paymentBanner.variant || "info"}
              title={paymentBanner.title}
              className="casanova-notice casanova-notice--payment"
              onClose={dismissPaymentBanner}
              closeLabel="Cerrar"
            >
              {paymentBanner.body}
            </Notice>
          </div>
        ) : null}

        {loadingDash && !dashboard ? (
          <div className="cp-content">
            <div className="cp-card">
              <div className="cp-card-title">{(activeView === "viajes" || activeView === "trips") ? "Tus viajes" : "Cargando"}</div>
              {(activeView === "viajes" || activeView === "trips") ? (
                <div className="cp-table-wrap cp-mt-14">
                  <TableSkeleton rows={7} cols={8} />
                </div>
              ) : (
                <Skeleton lines={8} />
              )}
            </div>
          </div>
        ) : dashErr && !dashboard ? (
          <div className="cp-content">
            <div className="cp-notice is-warn">
              {tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")}
            </div>
          </div>
        ) : (activeView === "viajes" || activeView === "trips") ? (
          <TripsList
            mock={route.mock}
            dashboard={dashboard}
            onOpen={(id, tab = "summary") => {
              setParam("view", "trip");
              setParam("expediente", String(id));
              setParam("tab", tab);
            }}
          />
        ) : activeView === "trip" && route.expediente ? (
          <TripDetailView
            mock={route.mock}
            expediente={route.expediente}
            dashboard={dashboard}
            readOnly={isReadOnly}
            readOnlyMessage={readOnlyMessage}
            onLatestTs={handleLatestTs}
            onSeen={markMessagesSeen}
            mulligansEnabled={isMulligansEnabled}
            KpiCard={KpiCard}
            paymentIcons={{ IconBriefcase, IconShieldCheck, IconClockArrow, IconSparkle }}
          />
        ) : activeView === "inbox" ? (
          <InboxView
            mock={route.mock}
            inbox={inbox}
            loading={loadingInbox && !inbox}
            error={inbox ? null : inboxErr}
            onLatestTs={handleLatestTs}
            onSeen={markMessagesSeen}
          />
        ) : activeView === "dashboard" ? (
          <DashboardView
            data={dashboard}
            heroImageUrl={heroImageUrl}
            heroMap={heroMap}
            tripDetail={dashboardTripDetail}
            tripDetailLoading={dashboardTripDetailLoading}
            mulligansEnabled={isMulligansEnabled}
            profile={profile}
            icons={{
              IconBed,
              IconCalendar,
              IconCar,
              IconChatBubble,
              IconClockArrow,
              IconGolfFlag,
              IconMapPin,
            }}
          />
        ) : activeView === "mulligans" ? (
          <MulligansView data={dashboard} />
        ) : activeView === "profile" ? (
          profile ? (
            <ProfileView profile={profile} onSave={saveProfile} onLocale={setLocale} readOnly={isReadOnly} readOnlyMessage={readOnlyMessage} />
          ) : (
            <div className="cp-content">
              {profileErr ? (
                <Notice variant="warn" title={tt("No podemos cargar tu perfil")}>
                  {profileErr?.message || 'Inténtalo de nuevo más tarde.'}
                </Notice>
              ) : (
                <div className="cp-card"><Skeleton lines={6} /></div>
              )}
            </div>
          )
        ) : activeView === "security" ? (
          <SecurityView onChangePassword={changePassword} readOnly={isReadOnly} readOnlyMessage={readOnlyMessage} />
        ) : (
          <div className="cp-content">
            <div className="cp-notice">{tt("Vista en construcción.")}</div>
          </div>
        )}

        <PortalFooter />
      </main>
    </div>
  );
}

export default App;
