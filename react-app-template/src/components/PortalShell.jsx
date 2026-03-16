import React, { useEffect, useRef, useState } from "react";

import { t, tt } from "../i18n/t.js";
import { setParam } from "../lib/params.js";

const ICON_PROPS = {
  viewBox: "0 0 24 24",
  width: 18,
  height: 18,
  fill: "none",
};

function IconGlobe() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <circle cx={12} cy={12} r={9} fill="none" stroke="currentColor" strokeWidth={1.5} />
      <path d="M3 12h18" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M12 3c3 3 3 15 0 18" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M12 3c-3 3-3 15 0 18" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
    </svg>
  );
}

function IconMoon() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
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
    <svg {...ICON_PROPS} aria-hidden="true">
      <circle cx={12} cy={9} r={3.2} fill="none" stroke="currentColor" strokeWidth={1.5} />
      <path d="M6.2 20c1.6-3 4-4.5 5.8-4.5s4.2 1.5 5.8 4.5" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
    </svg>
  );
}

function IconShieldCheck() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
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

function IconLogout() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <path d="M10 7V6a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-6a2 2 0 0 1-2-2v-1" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M3 12h9" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M7 8l-4 4 4 4" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function initials(name) {
  const value = String(name || "").trim();
  if (!value) return "U";
  const parts = value.split(/\s+/).filter(Boolean);
  const first = parts[0]?.[0] || "U";
  const last = parts.length > 1 ? parts[parts.length - 1]?.[0] : "";
  return (first + last).toUpperCase();
}

function LanguageMenu({ locale, onLocale, disabled = false }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const current = locale || "es_ES";

  useEffect(() => {
    if (disabled && open) setOpen(false);
  }, [disabled, open]);

  useEffect(() => {
    function onDocClick(event) {
      if (!open) return;
      if (ref.current && !ref.current.contains(event.target)) setOpen(false);
    }
    document.addEventListener("mousedown", onDocClick);
    return () => document.removeEventListener("mousedown", onDocClick);
  }, [open]);

  const items = [
    { value: "es_ES", label: "ES", name: "Español" },
    { value: "en_US", label: "EN", name: "English" },
  ];
  const active = items.find((item) => item.value === current) || items[0];

  return (
    <div className="cp-lang" ref={ref}>
      <button type="button" className="cp-lang-btn" onClick={() => { if (!disabled) setOpen((value) => !value); }} aria-haspopup="menu" aria-expanded={open ? "true" : "false"} title={active.name} disabled={disabled}>
        <span className="cp-lang-ico" aria-hidden="true"><IconGlobe /></span>
        <span className="cp-lang-label">{active.label}</span>
      </button>
      {open && !disabled ? (
        <div className="cp-lang-menu" role="menu">
          {items.map((item) => (
            <button
              key={item.value}
              type="button"
              className={`cp-lang-item ${item.value === current ? "is-active" : ""}`}
              onClick={() => {
                setOpen(false);
                if (typeof onLocale === "function") onLocale(item.value);
              }}
              role="menuitem"
            >
              <span className="cp-lang-item-label">{item.name}</span>
              {item.value === current ? <span className="cp-lang-check" aria-hidden="true">✓</span> : null}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}

function UserMenu({ profile, onGo, onLogout, theme = "light", onToggleTheme }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    function onDocClick(event) {
      if (!open) return;
      if (ref.current && !ref.current.contains(event.target)) setOpen(false);
    }
    document.addEventListener("mousedown", onDocClick);
    return () => document.removeEventListener("mousedown", onDocClick);
  }, [open]);

  const name = profile?.user?.displayName || profile?.giav?.nombre || "";
  const email = profile?.user?.email || profile?.giav?.email || "";
  const avatarUrl = profile?.user?.avatarUrl || "";
  const isDark = theme === "dark";

  return (
    <div className="cp-user" ref={ref}>
      <button type="button" className="cp-user-btn" onClick={() => setOpen((value) => !value)} aria-haspopup="menu" aria-expanded={open ? "true" : "false"}>
        {avatarUrl ? (
          <img className="cp-user-avatar" src={avatarUrl} alt="" />
        ) : (
          <div className="cp-user-avatar is-fallback" aria-hidden="true">{initials(name)}</div>
        )}
      </button>

      {open ? (
        <div className="cp-user-menu" role="menu">
          <div className="cp-user-head">
            {avatarUrl ? (
              <img className="cp-user-avatar" src={avatarUrl} alt="" />
            ) : (
              <div className="cp-user-avatar is-fallback" aria-hidden="true">{initials(name)}</div>
            )}
            <div>
              <div className="cp-user-name">{name || t("account_label", "Tu cuenta")}</div>
              {email ? <div className="cp-user-email">{email}</div> : null}
            </div>
          </div>

          <button type="button" className="cp-user-item" onClick={() => { setOpen(false); onGo("profile"); }} role="menuitem">
            <span className="cp-user-item-ico" aria-hidden="true"><IconUser /></span>
            {t("menu_profile", "Mi perfil")}
          </button>
          <button type="button" className="cp-user-item" onClick={() => { setOpen(false); onGo("security"); }} role="menuitem">
            <span className="cp-user-item-ico" aria-hidden="true"><IconShieldCheck /></span>
            {t("menu_security", "Seguridad")}
          </button>

          <div className="cp-user-sep" />
          <button
            type="button"
            className="cp-user-item cp-user-item--toggle"
            onClick={() => { if (typeof onToggleTheme === "function") onToggleTheme(); }}
            role="menuitemcheckbox"
            aria-checked={isDark ? "true" : "false"}
          >
            <span className="cp-user-item-ico" aria-hidden="true"><IconMoon /></span>
            <span className="cp-user-item-copy">
              <span className="cp-user-item-title">{tt("Modo oscuro")}</span>
              <span className="cp-user-item-note">{isDark ? tt("Activado") : tt("Desactivado")}</span>
            </span>
            <span className={`cp-theme-switch ${isDark ? "is-on" : ""}`} aria-hidden="true">
              <span className="cp-theme-switch__thumb" />
            </span>
          </button>

          <div className="cp-user-sep" />
          <button type="button" className="cp-user-item is-danger" onClick={() => { setOpen(false); onLogout(); }} role="menuitem">
            <span className="cp-user-item-ico" aria-hidden="true"><IconLogout /></span>
            {t("menu_logout", "Cerrar sesión")}
          </button>
        </div>
      ) : null}
    </div>
  );
}

export function Sidebar({ view, unread = 0, items = [], theme = "light" }) {
  const agency = window.CasanovaPortal?.agency || {};
  const branding = window.CasanovaPortal?.branding || {};
  const agencyName = String(agency.nombre || "Casanova Golf").trim();
  const lightLogoUrl = String(branding.logoLightUrl || "").trim();
  const darkLogoUrl = String(branding.logoDarkUrl || "").trim();
  const logoUrl = theme === "dark" ? (darkLogoUrl || lightLogoUrl) : (lightLogoUrl || darkLogoUrl);

  return (
    <aside className="cp-sidebar">
      <div className="cp-brand" style={{ display: "flex", alignItems: "center", gap: 12 }}>
        {logoUrl ? (
          <img
            className="cp-logo cp-logo--image"
            src={logoUrl}
            alt={agencyName}
            loading="eager"
            decoding="async"
          />
        ) : (
          <div className="cp-logo cp-logo--placeholder" aria-hidden="true" />
        )}
        <div style={{ display: "flex", flexDirection: "column", lineHeight: 1.1 }}>
          <div className="cp-brand-title">{tt("Casanova Portal")}</div>
          <div className="cp-brand-sub">{tt("Gestión de Reservas")}</div>
        </div>
      </div>

      <nav className="cp-nav">
        {items.map((item) => {
          const IconComponent = item.icon;
          const active = item.isActive(view);
          return (
            <button
              key={item.key}
              type="button"
              className={`cp-nav-btn ${active ? "is-active" : ""}`}
              onClick={() => setParam("view", item.view)}
            >
              <span className="cp-nav-label">
                <span className="cp-nav-icon">
                  <IconComponent />
                </span>
                <span>{item.label}</span>
              </span>
              {item.key === "inbox" && view !== "inbox" && unread > 0 ? (
                <span className="cp-badge">{unread}</span>
              ) : null}
            </button>
          );
        })}
      </nav>

      <div style={{ marginTop: "auto" }} />
    </aside>
  );
}

export function Topbar({ title, chip, onRefresh, isRefreshing, profile, onGo, onLogout, onLocale, readOnly = false, theme, onToggleTheme }) {
  return (
    <div className="cp-topbar">
      <div className="cp-topbar-inner">
        <div className="cp-title">{title}</div>
        <div className="cp-actions">
          {chip ? <div className="cp-chip">{chip}</div> : null}
          {isRefreshing ? <div className="cp-chip">{tt("Actualizando…")}</div> : null}
          <button className="cp-btn" onClick={onRefresh}>
            {tt("Actualizar")}
          </button>
          <LanguageMenu locale={profile?.locale} onLocale={onLocale} disabled={readOnly} />
          <UserMenu profile={profile} onGo={onGo} onLogout={onLogout} theme={theme} onToggleTheme={onToggleTheme} />
        </div>
      </div>
    </div>
  );
}

export function PortalFooter() {
  const agency = window.CasanovaPortal?.agency || {};
  const tel = String(agency.tel || "").trim();
  const email = String(agency.email || "").trim();
  const nombre = String(agency.nombre || "Casanova Golf").trim();
  const direccion = String(agency.direccion || "").trim();
  const web = String(agency.web || "").trim();

  return (
    <footer className="cp-footer">
      <div className="cp-footer-inner">
        <div className="cp-footer-left">
          <div className="cp-footer-brand">{nombre}</div>
          {direccion ? <div className="cp-footer-muted">{direccion}</div> : null}
        </div>
        <div className="cp-footer-right">
          {tel ? (
            <div className="cp-footer-item">
              <span className="cp-footer-label">{tt("Tel.")}</span>
              <a href={`tel:${tel.replace(/\s+/g, "")}`} className="cp-footer-link">{tel}</a>
            </div>
          ) : null}
          {email ? (
            <div className="cp-footer-item">
              <span className="cp-footer-label">{tt("Email")}</span>
              <a href={`mailto:${email}`} className="cp-footer-link">{email}</a>
            </div>
          ) : null}
          {web ? (
            <div className="cp-footer-item">
              <span className="cp-footer-label">{tt("Web")}</span>
              <a href={web} className="cp-footer-link" target="_blank" rel="noreferrer">{web.replace(/^https?:\/\//, "")}</a>
            </div>
          ) : null}
        </div>
      </div>
    </footer>
  );
}
