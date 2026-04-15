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
import { formatCurrency, formatDate, formatNumber, t, tt } from "./i18n/t.js";
import { api } from "./lib/api.js";
import { readParams, setParam } from "./lib/params.js";
import { getBonusesVariant, getPaymentVariant, getStatusVariant } from "./lib/statusBadges.js";
import { LS_KEYS, lsGet, lsSet, resolveInitialTheme } from "./lib/storage.js";
import { pickTripHeroImage } from "./lib/tripServices.js";

/* ===== Local state =====
   El portal ya escribe mensajes propios; GIAV sigue entrando como fuente adicional.
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
    labelKey: "nav_dashboard",
    label: "Dashboard",
    view: "dashboard",
    icon: IconGrid,
    isActive: (view) => view === "dashboard",
  },
  {
    key: "trips",
    labelKey: "nav_trips",
    label: "Viajes",
    view: "trips",
    icon: IconMapPin,
    isActive: (view) => view === "trips" || view === "trip",
  },
  {
    key: "inbox",
    labelKey: "nav_messages",
    label: "Mensajes",
    view: "inbox",
    icon: IconChatBubble,
    isActive: (view) => view === "inbox",
  },
  {
    key: "mulligans",
    labelKey: "nav_mulligans",
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
  const [historyFilter, setHistoryFilter] = useState("all");
  const m = data?.mulligans || {};
  const points = Number(m.points || 0);
  const tier = String(m.tier || "birdie").toLowerCase().replace(/\s+/g, "_").replace(/\+/g, "_plus").replace(/-/g, "_");
  const spend = Number(m.spend || 0);
  const earned = Number(m.earned || 0);
  const bonus = Number(m.bonus || 0);
  const used = Number(m.used || 0);
  const ledger = Array.isArray(m.ledger) ? m.ledger : [];
  const redeemedCount = ledger.filter((item) => String(item?.type || "") === "redeem").length;

  const tierLabel = (t) => {
    const map = { birdie: "Birdie", eagle: "Eagle", eagle_plus: "Eagle+", albatross: "Albatross" };
    return map[t] || (t ? t.charAt(0).toUpperCase() + t.slice(1) : "Birdie");
  };

  const fmtMoney = (v) => formatCurrency(v || 0, "EUR", { maximumFractionDigits: 0 });
  const fmtDate = (ts) => {
    const n = Number(ts || 0);
    if (!n) return "\u2014";
    const d = new Date(n * 1000);
    return formatDate(d, { day: "2-digit", month: "2-digit", year: "numeric" });
  };
  const fmtPts = (v) => formatNumber(v);

  /* Tier definitions — based on cumulative spend (euros) */
  const TIERS = [
    { slug: "birdie",     name: "Birdie",     mult: "x1.00", range: tt("Hasta 4.999 \u20ac"),            min: 0,     max: 4999 },
    { slug: "eagle",      name: "Eagle",      mult: "x1.20", range: "5.000 \u20ac \u2014 14.999 \u20ac", min: 5000,  max: 14999 },
    { slug: "eagle_plus", name: "Eagle+",     mult: "x1.35", range: "15.000 \u20ac \u2014 29.999 \u20ac",min: 15000, max: 29999, featured: true },
    { slug: "albatross",  name: "Albatross",  mult: "x1.50", range: tt("Mas de 30.000 \u20ac"),          min: 30000, max: Infinity },
  ];

  const currentTierIdx = Math.max(0, TIERS.findIndex((t) => t.slug === tier));
  const currentTier = TIERS[currentTierIdx];
  const nextTier = TIERS[currentTierIdx + 1] || null;
  const progressPct = nextTier
    ? Math.min(100, Math.max(0, ((spend - currentTier.min) / (nextTier.min - currentTier.min)) * 100))
    : 100;

  /* Filter ledger */
  const filteredLedger = historyFilter === "all"
    ? ledger
    : ledger.filter((it) => {
        const type = String(it.type || "");
        if (historyFilter === "earned") return type === "earn";
        if (historyFilter === "bonus") return type === "bonus";
        if (historyFilter === "redeemed") return type === "redeem";
        return true;
      });

  const typeLabel = (type) => {
    if (type === "bonus") return tt("Bonus");
    if (type === "earn") return tt("Ganado");
    if (type === "redeem") return tt("Canje");
    return tt("Movimiento");
  };

  const typeDotClass = (type) => {
    if (type === "bonus") return "is-bonus";
    if (type === "redeem") return "is-redeemed";
    return "is-earned";
  };

  const pointsClass = (type, pts) => {
    if (type === "bonus") return "is-bonus";
    if (pts < 0) return "is-negative";
    return "is-positive";
  };

  /* Benefits per tier — real program data */
  const allBenefits = [
    /* Birdie */
    { name: tt("Acceso al portal privado"),  desc: tt("Consulta tus viajes, pagos y puntos Mulligans desde tu portal personal."), tier: "birdie",
      iconBg: "var(--gold-light)", iconStroke: "var(--gold)", iconPath: "M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M12 3v12" },
    { name: tt("Historial de viajes"),       desc: tt("Registro completo de todos tus viajes organizados por Casanova Golf."), tier: "birdie",
      iconBg: "var(--gold-light)", iconStroke: "var(--gold)", iconPath: "M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" },
    { name: tt("Welcome Pack Digital"),      desc: tt("Pack de bienvenida digital al unirte al programa Mulligans."), tier: "birdie",
      iconBg: "var(--gold-light)", iconStroke: "var(--gold)", iconPath: "M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" },
    { name: tt("Bonus de bienvenida"),       desc: tt("Mulligans iniciales al crear tu cuenta en el programa."), tier: "birdie",
      iconBg: "var(--gold-light)", iconStroke: "var(--gold)", iconPath: "M12 2l2.09 6.26L21 9.27l-5 3.88L17.18 22 12 18.27 6.82 22 8 13.15 3 9.27l6.91-1.01z" },
    /* Eagle */
    { name: tt("Detalle Mulligans anual"),   desc: tt("Cortesia anual: elige tu regalo Mulligans con cada reserva."), tier: "eagle",
      iconBg: "var(--accent-light, #e8f0e6)", iconStroke: "var(--accent)", iconPath: "M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z" },
    { name: tt("Revision activa de reservas"), desc: tt("Seguimiento proactivo de tu reserva para optimizar tu experiencia."), tier: "eagle",
      iconBg: "var(--accent-light, #e8f0e6)", iconStroke: "var(--accent)", iconPath: "M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" },
    { name: tt("Acceso a canjes basicos"),   desc: tt("Canjea tu saldo Mulligans por mejoras preferentes y experiencias sencillas."), tier: "eagle",
      iconBg: "var(--accent-light, #e8f0e6)", iconStroke: "var(--accent)", iconPath: "M12 1v22M17 5H9.5a3.5 3.5 0 1 0 0 7h5a3.5 3.5 0 1 1 0 7H6" },
    /* Eagle+ */
    { name: tt("Atencion preferente"),       desc: tt("Linea directa de atencion prioritaria para tu viaje."), tier: "eagle_plus",
      iconBg: "var(--blue-light, #e6f0f7)", iconStroke: "var(--blue, #1a5276)", iconPath: "M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.11 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72" },
    { name: tt("Mulligan Operativo"),        desc: tt("Perdon de 1 penalizacion al ano en cambios o ajustes de reserva."), tier: "eagle_plus",
      iconBg: "var(--blue-light, #e6f0f7)", iconStroke: "var(--blue, #1a5276)", iconPath: "M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10zM9 12l2 2 4-4" },
    { name: tt("Acceso anticipado a ofertas"), desc: tt("Conoce antes que nadie las ofertas y destinos exclusivos."), tier: "eagle_plus",
      iconBg: "var(--blue-light, #e6f0f7)", iconStroke: "var(--blue, #1a5276)", iconPath: "M13 2L3 14h9l-1 8 10-12h-9l1-8z" },
    { name: tt("Experiencia Mulligans especial"), desc: tt("Canjes de experiencias gastronomicas, culturales y mejoras avanzadas."), tier: "eagle_plus",
      iconBg: "var(--blue-light, #e6f0f7)", iconStroke: "var(--blue, #1a5276)", iconPath: "M12 2l2.09 6.26L21 9.27l-5 3.88L17.18 22 12 18.27 6.82 22 8 13.15 3 9.27l6.91-1.01z" },
    /* Albatross */
    { name: tt("Maxima prioridad"),          desc: tt("Nivel de atencion y seguimiento mas alto del programa."), tier: "albatross",
      iconBg: "var(--purple-light, #f3e8ff)", iconStroke: "var(--purple, #7c3aed)", iconPath: "M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" },
    { name: tt("Traslado privado aeropuerto"), desc: tt("Transfer privado de salida incluido una vez al ano."), tier: "albatross",
      iconBg: "var(--purple-light, #f3e8ff)", iconStroke: "var(--purple, #7c3aed)", iconPath: "M1 16h16M5.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM18.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM1 3h15v13H1zM16 8h4l3 3v5h-3" },
    { name: tt("Experiencia Mulligans incluida"), desc: tt("Una experiencia al ano definida por Casanova Golf, sin coste de saldo."), tier: "albatross",
      iconBg: "var(--purple-light, #f3e8ff)", iconStroke: "var(--purple, #7c3aed)", iconPath: "M12 2l2.09 6.26L21 9.27l-5 3.88L17.18 22 12 18.27 6.82 22 8 13.15 3 9.27l6.91-1.01z" },
    { name: tt("Canje experiencia premium"),  desc: tt("Acceso a canjes premium: cenas privadas, logistica, noches adicionales."), tier: "albatross",
      iconBg: "var(--purple-light, #f3e8ff)", iconStroke: "var(--purple, #7c3aed)", iconPath: "M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z" },
  ];

  /* Which benefits are active/locked for the user */
  const tierOrder = ["birdie", "eagle", "eagle_plus", "albatross"];
  const userTierOrder = tierOrder.indexOf(tier);
  const benefits = allBenefits.map((b) => {
    const bTierOrder = tierOrder.indexOf(b.tier);
    const locked = bTierOrder > userTierOrder;
    return { ...b, locked, lockLevel: locked ? tierLabel(b.tier) : null };
  });

  return (
    <div className="cp-content">
      {/* Level Hero */}
      <div className="cp-level-hero">
        <div className="cp-level-hero__content">
          <div className="cp-level-hero__badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 2l2.09 6.26L21 9.27l-5 3.88L17.18 22 12 18.27 6.82 22 8 13.15 3 9.27l6.91-1.01z"/></svg>
            {tt("Nivel actual")}: {tierLabel(tier)}
          </div>
          <div className="cp-level-hero__points">{fmtPts(points)}</div>
          <p className="cp-level-hero__label">{tt("puntos Mulligans acumulados")}</p>
          {nextTier ? (
            <div className="cp-level-hero__progress">
              <div className="cp-level-hero__bar">
                <div className="cp-level-hero__bar-fill" style={{ width: `${progressPct}%` }} />
              </div>
              <div className="cp-level-hero__bar-labels">
                <span>{currentTier.name} &middot; <strong>{fmtMoney(currentTier.min)}</strong></span>
                <span>{nextTier.name} &middot; <strong>{fmtMoney(nextTier.min)}</strong></span>
              </div>
            </div>
          ) : (
            <div className="cp-level-hero__progress">
              <div className="cp-level-hero__bar">
                <div className="cp-level-hero__bar-fill" style={{ width: "100%" }} />
              </div>
              <div className="cp-level-hero__bar-labels">
                <span>{currentTier.name} &middot; <strong>{tt("Nivel maximo")}</strong></span>
              </div>
            </div>
          )}
        </div>
        <div className="cp-level-hero__tiers">
          {TIERS.map((t, i) => {
            const isCurrent = t.slug === tier;
            const isPast = i < currentTierIdx;
            let cls = "cp-tier";
            if (isCurrent) cls += " is-current";
            if (isPast) cls += " is-past";
            return (
              <React.Fragment key={t.slug}>
                {i > 0 && <div className="cp-tier-connector" />}
                <div className={cls}>
                  <div className="cp-tier__icon">{isCurrent ? "\u2605" : isPast ? "\u2713" : "\u2606"}</div>
                  <div className="cp-tier__text">
                    <span className="cp-tier__name">{t.name}</span>
                    <span className="cp-tier__mult">{t.mult}</span>
                    <span className="cp-tier__range">{t.range}</span>
                  </div>
                  {isCurrent && <span className="cp-tier__tag">{tt("Actual")}</span>}
                </div>
              </React.Fragment>
            );
          })}
        </div>
      </div>

      {/* Stats Row */}
      <div className="cp-mul-stats">
        <div className="cp-mul-stat">
          <div className="cp-mul-stat__icon is-gold"><IconStar /></div>
          <p className="cp-mul-stat__label">{tt("Balance")}</p>
          <p className="cp-mul-stat__value">{fmtPts(points)}</p>
        </div>
        <div className="cp-mul-stat">
          <div className="cp-mul-stat__icon is-blue"><IconChartBar /></div>
          <p className="cp-mul-stat__label">{tt("Gasto historico")}</p>
          <p className="cp-mul-stat__value">{fmtMoney(spend)}</p>
        </div>
        <div className="cp-mul-stat">
          <div className="cp-mul-stat__icon is-green"><IconCalendar /></div>
          <p className="cp-mul-stat__label">{tt("Multiplicador")}</p>
          <p className="cp-mul-stat__value">{currentTier.mult}</p>
        </div>
        <div className="cp-mul-stat">
          <div className="cp-mul-stat__icon is-green"><IconShieldCheck /></div>
          <p className="cp-mul-stat__label">{tt("Ganados")}</p>
          <p className="cp-mul-stat__value" style={{ color: "var(--accent)" }}>+{fmtPts(earned)}</p>
          <p className="cp-mul-stat__sub">{ledger.length} {tt("movimientos")}</p>
        </div>
        <div className="cp-mul-stat">
          <div className="cp-mul-stat__icon is-purple"><IconSparkle /></div>
          <p className="cp-mul-stat__label">{tt("Usados")}</p>
          <p className="cp-mul-stat__value">{fmtPts(used)}</p>
          <p className="cp-mul-stat__sub">{fmtPts(redeemedCount)} {tt("canjes")}</p>
        </div>
      </div>

      {/* How it works */}
      <div className="cp-mul-info">
        <div className="cp-mul-info__icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
        </div>
        <div className="cp-mul-info__text">
          <h4>{tt("Como funciona")}</h4>
          <p>{tt("Tu nivel se determina por tu gasto historico acumulado y es vitalicio. Las cortesias anuales se activan al confirmar una reserva. Tu saldo Mulligans no caduca y se puede canjear en futuros viajes segun tu nivel.")}</p>
        </div>
      </div>

      {/* Benefits */}
      <div className="cp-mul-benefits">
        <h3 className="cp-mul-benefits__title">
          {tt("Beneficios de tu nivel")} <span className="cp-mul-benefits__tag">{tierLabel(tier)}</span>
        </h3>
        <div className="cp-mul-ben-grid">
          {benefits.map((ben, i) => (
            <div key={i} className={`cp-mul-ben ${ben.locked ? "is-locked" : ""}`}>
              <div className="cp-mul-ben__icon" style={{ background: ben.locked ? "var(--bg)" : ben.iconBg }}>
                <svg viewBox="0 0 24 24" fill="none" stroke={ben.locked ? "var(--muted)" : ben.iconStroke} strokeWidth="1.8"><path d={ben.iconPath}/></svg>
              </div>
              <p className="cp-mul-ben__name">{ben.name}{ben.locked && ben.lockLevel ? <span className="cp-mul-ben__lock"> ({ben.lockLevel})</span> : null}</p>
              <p className="cp-mul-ben__desc">{ben.desc}</p>
            </div>
          ))}
        </div>
      </div>

      {/* History */}
      <div className="cp-mul-history">
        <div className="cp-mul-history__header">
          <h3>
            {tt("Historico de movimientos")} <span className="cp-mul-history__count">{ledger.length} {tt("movimientos")}</span>
          </h3>
          <div className="cp-mul-history__filters">
            {[
              { key: "all", label: tt("Todos") },
              { key: "earned", label: tt("Ganados") },
              { key: "bonus", label: tt("Bonus") },
              { key: "redeemed", label: tt("Canjeados") },
            ].map((f) => (
              <button
                key={f.key}
                type="button"
                className={`cp-mul-history__filter ${historyFilter === f.key ? "is-active" : ""}`}
                onClick={() => setHistoryFilter(f.key)}
              >
                {f.label}
              </button>
            ))}
          </div>
        </div>

        {filteredLedger.length === 0 ? (
          <div style={{ padding: 24 }}>
            <EmptyState title={tt("Aun no hay movimientos")} icon={"\uD83E\uDDFE"}>
              {tt("Cuando se registren pagos o se aplique un bonus, apareceran aqui.")}
            </EmptyState>
          </div>
        ) : (
          <table className="cp-mul-table">
            <thead>
              <tr>
                <th>{tt("Tipo")}</th>
                <th>{tt("Descripcion")}</th>
                <th>{tt("Fecha")}</th>
                <th>{tt("Puntos")}</th>
              </tr>
            </thead>
            <tbody>
              {filteredLedger.map((it, i) => {
                const pts = Number(it.points || 0);
                const sign = pts >= 0 ? "+" : "";
                const type = String(it.type || "");
                return (
                  <tr key={it.id || `${it.ts}-${i}`}>
                    <td>
                      <span className="cp-mul-type">
                        <span className={`cp-mul-dot ${typeDotClass(type)}`} />
                        {typeLabel(type)}
                      </span>
                    </td>
                    <td className="cp-mul-desc">{it.note || it.source || "\u2014"}</td>
                    <td className="cp-mul-date">{fmtDate(it.ts)}</td>
                    <td className={`cp-mul-points ${pointsClass(type, pts)}`}>{sign}{fmtPts(Math.abs(pts))}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}



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
  const [loadingInbox, setLoadingInbox] = useState(false);
  const [inboxErr, setInboxErr] = useState(null);

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

    if (activeView === "profile" || activeView === "security") {
      void loadProfile(true).catch(() => {
        // Silent fail: profile view already renders a dedicated notice.
      });
    }
  }

  const unreadInbox = inbox?.unread;
  const unreadDash = dashboard?.messages?.unread;
  const unreadCount = typeof unreadInbox === "number" ? unreadInbox : (typeof unreadDash === "number" ? unreadDash : 0);

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
    if (activeView === "trip") return {
      title: t('nav_trip_detail', 'Detalle del viaje'),
      subtitle: null,
    };
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
    <div className="cp-app" data-theme={theme}>
      <Sidebar view={activeView} unread={unreadCount} items={visibleNavItems} theme={theme} />
      <main className="cp-main">
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
              closeLabel={t("close", "Cerrar")}
            >
              {paymentBanner.body}
            </Notice>
          </div>
        ) : null}

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
            onSeen={refreshMessagesState}
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
                  {profileErr?.message || tt("Inténtalo de nuevo más tarde.")}
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
