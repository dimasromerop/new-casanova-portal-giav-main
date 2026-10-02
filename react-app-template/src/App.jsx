/*
  App.portal.viajes-detalle-tabs.jsx
  - Viajes: listado con tabla rica (ancho ampliado + fechas ES)
  - Viaje: vista detalle con breadcrumb + header + tabs (Resumen/Pagos/Facturas/Bonos/Mensajes)
  - Mensajes: timeline por expediente (usa /messages?expediente=ID)
*/

import React, { Suspense, lazy, startTransition, useEffect, useMemo, useRef, useState } from "react";

import Icon from "./components/Icon.jsx";
import DashboardView from "./components/dashboard/DashboardView.jsx";
import { MobileTabBar, PortalFooter, Sidebar, Topbar } from "./components/PortalShell.jsx";
import { Notice, Skeleton, TableSkeleton } from "./components/ui.jsx";
import { t, tt } from "./i18n/t.js";
import { api } from "./lib/api.js";
import { readParams, setParam, setParams } from "./lib/params.js";
import { LS_KEYS, lsGet, lsSet } from "./lib/storage.js";
import { pickTripHeroImage } from "./lib/tripServices.js";

// El Inicio va en el bundle principal; el resto de vistas se descarga al abrirlas
// (y en segundo plano cuando el navegador está libre, ver preloadViews).
const loadTripsList = () => import("./components/TripsList.jsx");
const loadTripDetail = () => import("./components/trip/TripDetailView.jsx");
const loadInbox = () => import("./components/InboxView.jsx");
const loadMulligans = () => import("./components/MulligansView.jsx");
const loadProfile = () => import("./components/ProfileView.jsx");
const loadSecurity = () => import("./components/SecurityView.jsx");
const loadProposals = () => import("./components/ProposalsView.jsx");

const TripsList = lazy(loadTripsList);
const TripDetailView = lazy(loadTripDetail);
const InboxView = lazy(loadInbox);
const MulligansView = lazy(loadMulligans);
const ProfileView = lazy(loadProfile);
const SecurityView = lazy(loadSecurity);
const ProposalsView = lazy(loadProposals);

function preloadViews() {
  const run = () => [loadTripsList, loadTripDetail, loadInbox, loadMulligans, loadProposals].forEach((load) => load().catch(() => {}));
  if ("requestIdleCallback" in window) window.requestIdleCallback(run, { timeout: 4000 });
  else window.setTimeout(run, 2500);
}

function ViewFallback() {
  return (
    <div className="cp-content" aria-busy="true">
      <div className="cp-card"><Skeleton lines={6} /></div>
    </div>
  );
}

/* ===== Local state =====
   El portal ya escribe mensajes propios; GIAV sigue entrando como fuente adicional.
*/

// Iconos del menú (Tabler, como el resto del portal).
const NavIconHome = () => <Icon name="home" size={20} />;
const NavIconTrips = () => <Icon name="map-pin" size={20} />;
const NavIconProposals = () => <Icon name="file" size={20} />;
const NavIconMessages = () => <Icon name="message" size={20} />;
const NavIconMulligans = () => <Icon name="star" size={20} />;

const NAV_ITEMS = [
  {
    key: "dashboard",
    labelKey: "nav_dashboard",
    label: "Inicio",
    view: "dashboard",
    icon: NavIconHome,
    isActive: (view) => view === "dashboard",
  },
  {
    key: "trips",
    labelKey: "nav_trips",
    label: "Viajes",
    view: "trips",
    icon: NavIconTrips,
    isActive: (view) => view === "trips" || view === "trip",
  },
  {
    key: "proposals",
    labelKey: "nav_proposals",
    label: "Propuestas",
    view: "proposals",
    icon: NavIconProposals,
    isActive: (view) => view === "proposals",
  },
  {
    key: "inbox",
    labelKey: "nav_messages",
    label: "Mensajes",
    view: "inbox",
    icon: NavIconMessages,
    isActive: (view) => view === "inbox",
  },
  {
    key: "mulligans",
    labelKey: "nav_mulligans",
    label: "Mulligans",
    view: "mulligans",
    icon: NavIconMulligans,
    isActive: (view) => view === "mulligans",
  },
];

function getNavItems({ mulligansEnabled = true, proposalsEnabled = false } = {}) {
  return NAV_ITEMS.filter((item) => (mulligansEnabled || item.key !== "mulligans") && (proposalsEnabled || item.key !== "proposals"));
}

/* ===== App ===== */

function pickHeroImageFromTripDetail(detail) {
  return pickTripHeroImage(detail);
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

  const [proposals, setProposals] = useState(null);
  const [loadingProposals, setLoadingProposals] = useState(false);
  const [proposalsErr, setProposalsErr] = useState(null);
  const proposalsRequestIdRef = useRef(0);
  const [loadingInbox, setLoadingInbox] = useState(false);
  const [inboxErr, setInboxErr] = useState(null);

  const dashboardRequestIdRef = useRef(0);
  const inboxRequestIdRef = useRef(0);
  const profileRequestRef = useRef(null);
  const refreshEffectReadyRef = useRef(false);

  const [profile, setProfile] = useState(null);
  const [profileErr, setProfileErr] = useState(null);
  const [toast, setToast] = useState(null);
  const impersonation = window.CasanovaPortal?.impersonation || {};
  const isReadOnly = Boolean(impersonation.readOnly);
  const readOnlyMessage = String(impersonation.message || tt("Modo de vista cliente activo. Solo lectura."));
  const isMulligansEnabled = window.CasanovaPortal?.features?.mulligansEnabled !== false;
  const isProposalsEnabled = window.CasanovaPortal?.features?.proposalsEnabled === true;
  const visibleNavItems = useMemo(
    () => getNavItems({ mulligansEnabled: isMulligansEnabled, proposalsEnabled: isProposalsEnabled }),
    [isMulligansEnabled, isProposalsEnabled],
  );
  const activeView = (!isMulligansEnabled && route.view === "mulligans") || (!isProposalsEnabled && route.view === "proposals")
    ? "dashboard"
    : route.view;

  useEffect(() => {
    const onPop = () => setRoute(readParams());
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);

  useEffect(() => {
    preloadViews();
  }, []);

  useEffect(() => {
    if (isMulligansEnabled || route.view !== "mulligans") return;
    setParam("view", "dashboard");
  }, [isMulligansEnabled, route.view]);

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
                  ? tt("La transferencia ya se ha iniciado. Actualizaremos tus pagos cuando recibamos la confirmación del banco.")
                  : method === "aplazame"
                    ? tt("La solicitud con Aplazame ha quedado pendiente. Actualizaremos tus pagos en cuanto recibamos la confirmación final.")
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
      const failedMessage = method === "aplazame"
        ? tt("La solicitud con Aplazame no se completó. Si el estado cambia más tarde, lo verás reflejado aquí.")
        : tt("La transferencia no se completó. Si el banco la confirma más tarde, lo verás reflejado aquí.");
      notify(failedMessage, "warn");
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

  async function loadProposalsFeed({ background = false } = {}) {
    if (!isProposalsEnabled) return null;
    const requestId = ++proposalsRequestIdRef.current;
    try {
      if (!background) setLoadingProposals(true);
      const res = await api(`/proposals${route.mock ? "?mock=1" : ""}`);
      if (requestId !== proposalsRequestIdRef.current) return res;
      startTransition(() => {
        setProposals(res);
        setProposalsErr(null);
      });
      return res;
    } catch (e) {
      if (requestId === proposalsRequestIdRef.current) setProposalsErr(e);
      return null;
    } finally {
      if (!background && requestId === proposalsRequestIdRef.current) setLoadingProposals(false);
    }
  }

  // Al abrir una propuesta deja de ser «Nueva» (en la vista como cliente no se marca nada).
  function markProposalSeen(proposal) {
    if (!proposal?.is_new || isReadOnly) return;
    const id = Number(proposal.id);
    setProposals((current) => {
      if (!current || typeof current !== "object") return current;
      const groups = (current.groups || []).map((group) => ({
        ...group,
        proposals: (group.proposals || []).map((item) => (Number(item.id) === id ? { ...item, is_new: false } : item)),
      }));
      const counts = { ...(current.counts || {}), new: Math.max(0, Number(current.counts?.new || 0) - 1) };
      return { ...current, groups, counts };
    });
    if (!route.mock) {
      void api("/proposals/seen", { method: "POST", body: { ids: [id] }, keepalive: true }).catch(() => {});
    }
  }

  // Las propuestas se piden cuando el navegador está libre: el menú y el Inicio
  // muestran el aviso de propuestas nuevas sin retrasar la primera pintura.
  useEffect(() => {
    if (!isProposalsEnabled) return undefined;
    setProposals(null);
    setProposalsErr(null);
    if (activeView === "proposals") {
      void loadProposalsFeed();
      return undefined;
    }
    const run = () => { void loadProposalsFeed({ background: true }); };
    if (typeof window.requestIdleCallback === "function") {
      const idleId = window.requestIdleCallback(run, { timeout: 2000 });
      return () => window.cancelIdleCallback?.(idleId);
    }
    const timer = window.setTimeout(run, 1200);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.mock, isProposalsEnabled]);

  // Al entrar en la sección se refresca en segundo plano (puede haber llegado algo nuevo).
  useEffect(() => {
    if (activeView !== "proposals") return;
    if (proposals) void loadProposalsFeed({ background: true });
    else if (proposalsErr) void loadProposalsFeed();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeView]);

  function refreshMessagesState() {
    void (async () => {
      const inboxRes = await loadInbox({ refresh: true, background: true });
      if (!inboxRes || typeof inboxRes.unread !== "number") return;

      setDashboard((current) => {
        if (!current || typeof current !== "object") return current;
        return {
          ...current,
          messages: {
            ...(current.messages || {}),
            unread: inboxRes.unread,
          },
        };
      });
    })();
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

  async function setLocale(selection) {
    if (isReadOnly) {
      notify(readOnlyMessage, "warn");
      return;
    }
    try {
      const locale = typeof selection === "string"
        ? selection
        : String(selection?.locale || selection?.value || "");
      const lang = String(locale || "").slice(0, 2).toLowerCase();
      const resolvedLang = typeof selection === "string"
        ? lang
        : String(selection?.lang || lang || "").toLowerCase();
      const res = await api('/profile/locale', { method: 'POST', body: { locale, lang: resolvedLang } });
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
    void loadProposalsFeed({ background: !!proposals });

    if (activeView === "profile" || activeView === "security") {
      void loadProfile(true).catch(() => {
        // Silent fail: profile view already renders a dedicated notice.
      });
    }
  }

  const unreadInbox = inbox?.unread;
  const unreadDash = dashboard?.messages?.unread;
  const unreadCount = typeof unreadInbox === "number" ? unreadInbox : (typeof unreadDash === "number" ? unreadDash : 0);
  const navCounts = { inbox: unreadCount, proposals: Number(proposals?.counts?.new || 0) };

  const chipItems = [];
  if (route.mock) chipItems.push(t('mock_mode', 'Modo prueba'));
  if (isReadOnly) chipItems.push(tt("Vista cliente"));
  const chip = chipItems.length ? chipItems.join(" · ") : null;

  const topbarInfo = useMemo(() => {
    if (activeView === "dashboard") return { title: null, subtitle: null };
    if (activeView === "viajes" || activeView === "trips") return {
      title: t('nav_trips', 'Viajes'),
      subtitle: t('trips_subtitle', 'Consulta fechas, pagos y estado de cada expediente.'),
    };
    if (activeView === "proposals") return {
      title: t('nav_proposals', 'Propuestas'),
      subtitle: tt("Las propuestas que hemos preparado para ti y tus solicitudes en curso."),
    };
    // La ficha pinta su propio <h1> (el nombre del viaje) sobre la foto.
    if (activeView === "trip") return { title: null, subtitle: null };
    if (activeView === "inbox") return {
      title: t('nav_messages', 'Mensajes'),
      subtitle: t('messages_subtitle', 'Conversaciones con el equipo de Casanova Golf, organizadas por viaje.'),
    };
    if (activeView === "mulligans") return {
      title: t('nav_mulligans', 'Mulligans'),
      subtitle: t('mulligans_subtitle', 'Programa de fidelización y beneficios exclusivos.'),
    };
    if (activeView === "profile") return {
      title: t('menu_profile', 'Mi perfil'),
      subtitle: null,
    };
    if (activeView === "security") return {
      title: t('menu_security', 'Seguridad'),
      subtitle: null,
    };
    return { title: t('nav_portal', 'Portal'), subtitle: null };
  }, [activeView]);

  return (
    <div className="cp-app">
      <a className="cp-skip" href="#cp-main-content">{tt("Saltar al contenido")}</a>
      <Sidebar view={activeView} counts={navCounts} items={visibleNavItems} />
      <div className="cp-main">
        <Topbar
          title={topbarInfo.title}
          subtitle={topbarInfo.subtitle}
          chip={chip}
          onRefresh={handleRefresh}
          isRefreshing={isRefreshing}
          profile={profile}
          onGo={go}
          onLogout={logout}
          onLocale={setLocale}
          readOnly={isReadOnly}
        />
        <main className="cp-view" id="cp-main-content" tabIndex={-1}>
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
                closeLabel={t("close", "Cerrar")}
              >
                {paymentBanner.body}
              </Notice>
            </div>
          ) : null}

          <Suspense fallback={<ViewFallback />}>
          {loadingDash && !dashboard ? (
            <div className="cp-content">
              <div className="cp-card">
                <div className="cp-card-title">{(activeView === "viajes" || activeView === "trips") ? tt("Tus viajes") : tt("Cargando")}</div>
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
              onOpen={(id, tab = "summary") => setParams({ view: "trip", expediente: String(id), tab })}
            />
          ) : activeView === "trip" && route.expediente ? (
            <TripDetailView
              mock={route.mock}
              expediente={route.expediente}
              dashboard={dashboard}
              readOnly={isReadOnly}
              readOnlyMessage={readOnlyMessage}
              onSeen={refreshMessagesState}
              mulligansEnabled={isMulligansEnabled}
            />
          ) : activeView === "proposals" ? (
            <ProposalsView
              feed={proposals}
              loading={!proposals && !proposalsErr}
              error={proposals ? null : proposalsErr}
              onOpenProposal={markProposalSeen}
              onRefresh={() => { void loadProposalsFeed({ background: true }); }}
              mock={route.mock}
              profile={profile}
              readOnly={isReadOnly}
              readOnlyMessage={readOnlyMessage}
            />
          ) : activeView === "inbox" ? (
            <InboxView
              mock={route.mock}
              inbox={inbox}
              loading={loadingInbox && !inbox}
              error={inbox ? null : inboxErr}
              onSeen={refreshMessagesState}
              readOnly={isReadOnly}
              readOnlyMessage={readOnlyMessage}
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
              proposals={isProposalsEnabled ? proposals : null}
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
                    {profileErr?.message || tt("Inténtalo de nuevo más tarde.")}
                  </Notice>
                ) : (
                  <div className="cp-card"><Skeleton lines={6} /></div>
                )}
              </div>
            )
          ) : activeView === "security" ? (
            <SecurityView onChangePassword={changePassword} readOnly={isReadOnly} readOnlyMessage={readOnlyMessage} username={profile?.user?.email || ""} />
          ) : (
            <div className="cp-content">
              <div className="cp-notice">{tt("Vista en construcción.")}</div>
            </div>
          )}
          </Suspense>
        </main>
        <PortalFooter />
      </div>
      <MobileTabBar view={activeView} counts={navCounts} items={visibleNavItems} />
    </div>
  );
}

export default App;
