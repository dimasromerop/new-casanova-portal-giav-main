import React, { useEffect, useId, useRef, useState } from "react";

import { getLanguages, t, tt } from "../i18n/t.js";
import Icon from "./Icon.jsx";

/* ===== Navegación ===== */

function viewHref(view) {
  const current = new URLSearchParams(window.location.search);
  const next = new URLSearchParams();
  next.set("view", view);
  if (current.get("mock") === "1") next.set("mock", "1");
  return `${window.location.pathname}?${next.toString()}`;
}

function isModifiedClick(event) {
  return event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0;
}

// Enlaces reales (se pueden abrir en otra pestaña) que navegan dentro de la SPA.
function navigate(event, href) {
  if (isModifiedClick(event)) return;
  event.preventDefault();
  if (`${window.location.pathname}${window.location.search}` !== href) {
    window.history.pushState({}, "", href);
    window.dispatchEvent(new Event("popstate"));
  }
  window.scrollTo({ top: 0 });
}

function navLabel(item) {
  return item.labelKey ? t(item.labelKey, item.label || item.fallback || "") : (item.label || item.fallback || "");
}

function brandInfo() {
  const agency = window.CasanovaPortal?.agency || {};
  const branding = window.CasanovaPortal?.branding || {};
  return {
    name: String(agency.nombre || "Casanova Golf").trim(),
    logoUrl: String(branding.logoBrandUrl || branding.logoLightUrl || branding.logoDarkUrl || "").trim(),
    tel: String(agency.tel || "").trim(),
    email: String(agency.email || "").trim(),
    web: String(agency.web || "").trim(),
    address: String(agency.direccion || "").trim(),
  };
}

function BrandLink({ className }) {
  const brand = brandInfo();
  const href = viewHref("dashboard");
  return (
    <a className={className} href={href} onClick={(event) => navigate(event, href)}>
      {brand.logoUrl ? (
        <img src={brand.logoUrl} alt={brand.name} decoding="async" />
      ) : (
        <span className="cp-brand-text">{brand.name}</span>
      )}
    </a>
  );
}

/* ===== Menús desplegables accesibles ===== */

function useMenu() {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);
  const triggerRef = useRef(null);
  const menuRef = useRef(null);
  const menuId = useId();

  useEffect(() => {
    if (!open) return undefined;

    const items = () => Array.from(menuRef.current?.querySelectorAll('[role^="menuitem"]') || []);
    const first = items().find((el) => el.getAttribute("aria-checked") === "true") || items()[0];
    first?.focus();

    function onPointer(event) {
      if (rootRef.current && !rootRef.current.contains(event.target)) setOpen(false);
    }
    function onKey(event) {
      if (event.key === "Escape") {
        setOpen(false);
        triggerRef.current?.focus();
        return;
      }
      if (!menuRef.current?.contains(document.activeElement)) return;
      const list = items();
      const index = list.indexOf(document.activeElement);
      let next = null;
      if (event.key === "ArrowDown") next = list[(index + 1) % list.length];
      if (event.key === "ArrowUp") next = list[(index - 1 + list.length) % list.length];
      if (event.key === "Home") next = list[0];
      if (event.key === "End") next = list[list.length - 1];
      if (event.key === "Tab") setOpen(false);
      if (next) {
        event.preventDefault();
        next.focus();
      }
    }

    document.addEventListener("pointerdown", onPointer);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("pointerdown", onPointer);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const close = (restoreFocus = false) => {
    setOpen(false);
    if (restoreFocus) triggerRef.current?.focus();
  };

  return { open, setOpen, close, rootRef, triggerRef, menuRef, menuId };
}

function fallbackLanguages() {
  return [
    { value: "es_ES", locale: "es_ES", lang: "es", label: "ES", name: tt("Español") },
    { value: "en_US", locale: "en_US", lang: "en", label: "EN", name: tt("English") },
  ];
}

function availableLanguages() {
  const items = getLanguages();
  return items.length ? items : fallbackLanguages();
}

function normalizeLanguageSelection(item) {
  const locale = item?.locale || item?.value || "";
  const lang = item?.lang || String(locale || "").slice(0, 2).toLowerCase();
  return { locale, lang };
}

function currentLocaleValue(locale, items) {
  const current = String(locale || "");
  if (current) return current;
  const runtimeLocale = String(window.CASANOVA_I18N_META?.localeRaw || "");
  if (runtimeLocale) return runtimeLocale;
  return items[0]?.value || "es_ES";
}

function LanguageMenu({ locale, onLocale, disabled = false }) {
  const menu = useMenu();
  const items = availableLanguages();
  const current = currentLocaleValue(locale, items);
  const isCurrent = (item) => item.value === current || item.locale === current;
  const active = items.find(isCurrent) || items[0];

  useEffect(() => {
    if (disabled && menu.open) menu.setOpen(false);
  }, [disabled, menu.open]);

  return (
    <div className="cp-menu" ref={menu.rootRef}>
      <button
        ref={menu.triggerRef}
        type="button"
        className="cp-iconbtn cp-iconbtn--text"
        onClick={() => { if (!disabled) menu.setOpen((value) => !value); }}
        aria-haspopup="menu"
        aria-expanded={menu.open ? "true" : "false"}
        aria-controls={menu.menuId}
        aria-label={`${tt("Idioma")}: ${active?.name || ""}`}
        disabled={disabled}
      >
        <Icon name="globe" size={18} />
        <span>{active?.label || "ES"}</span>
      </button>
      {menu.open && !disabled ? (
        <div className="cp-menu__panel cp-menu__panel--narrow" role="menu" id={menu.menuId} ref={menu.menuRef}>
          {items.map((item) => (
            <button
              key={item.value || item.locale}
              type="button"
              className="cp-menu__item"
              role="menuitemradio"
              aria-checked={isCurrent(item) ? "true" : "false"}
              onClick={() => {
                menu.close(true);
                if (typeof onLocale === "function") onLocale(normalizeLanguageSelection(item));
              }}
            >
              <span className="cp-menu__label">{item.name}</span>
              {isCurrent(item) ? <Icon name="check" size={18} className="cp-menu__check" /> : null}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}

function initials(name) {
  const parts = String(name || "").trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return "U";
  const first = parts[0][0] || "";
  const last = parts.length > 1 ? parts[parts.length - 1][0] : "";
  return (first + last).toUpperCase();
}

// Las iniciales quedan debajo de la foto: si el cliente no tiene foto, el avatar
// llega transparente (default "blank") y se ven las iniciales.
function Avatar({ url, name }) {
  return (
    <span className="cp-avatar" aria-hidden="true">
      <span className="cp-avatar__initials">{initials(name)}</span>
      {url ? <img className="cp-avatar__img" src={url} alt="" /> : null}
    </span>
  );
}

function UserMenu({ profile, onGo, onLogout }) {
  const menu = useMenu();
  const name = profile?.user?.displayName || profile?.giav?.nombre || "";
  const email = profile?.user?.email || profile?.giav?.email || "";
  const avatarUrl = profile?.user?.avatarUrl || "";
  const accountLabel = t("account_label", "Tu cuenta");

  return (
    <div className="cp-menu" ref={menu.rootRef}>
      <button
        ref={menu.triggerRef}
        type="button"
        className="cp-avatarbtn"
        onClick={() => menu.setOpen((value) => !value)}
        aria-haspopup="menu"
        aria-expanded={menu.open ? "true" : "false"}
        aria-controls={menu.menuId}
        aria-label={accountLabel}
      >
        <Avatar url={avatarUrl} name={name} />
      </button>

      {menu.open ? (
        <div className="cp-menu__panel" role="menu" id={menu.menuId} ref={menu.menuRef} aria-label={accountLabel}>
          <div className="cp-menu__head">
            <Avatar url={avatarUrl} name={name} />
            <div className="cp-menu__who">
              <div className="cp-menu__name">{name || accountLabel}</div>
              {email ? <div className="cp-menu__email">{email}</div> : null}
            </div>
          </div>

          <button type="button" className="cp-menu__item" role="menuitem" onClick={() => { menu.close(); onGo("profile"); }}>
            <Icon name="user" size={18} />
            <span className="cp-menu__label">{t("menu_profile", "Mi perfil")}</span>
          </button>
          <button type="button" className="cp-menu__item" role="menuitem" onClick={() => { menu.close(); onGo("security"); }}>
            <Icon name="shield" size={18} />
            <span className="cp-menu__label">{t("menu_security", "Seguridad")}</span>
          </button>

          <div className="cp-menu__sep" role="separator" />
          <button type="button" className="cp-menu__item is-danger" role="menuitem" onClick={() => { menu.close(); onLogout(); }}>
            <Icon name="logout" size={18} />
            <span className="cp-menu__label">{t("menu_logout", "Cerrar sesión")}</span>
          </button>
        </div>
      ) : null}
    </div>
  );
}

/* ===== Estructura ===== */

export function Sidebar({ view, unread = 0, items = [] }) {
  const brand = brandInfo();

  return (
    <aside className="cp-sidebar">
      <BrandLink className="cp-sidebar__brand" />

      <nav className="cp-nav" aria-label={tt("Menú principal")}>
        {items.map((item) => {
          const IconComponent = item.icon;
          const active = item.isActive(view);
          const href = viewHref(item.view);
          const showCount = item.key === "inbox" && view !== "inbox" && unread > 0;

          return (
            <a
              key={item.key}
              href={href}
              className={`cp-nav__item ${active ? "is-active" : ""}`}
              aria-current={active ? "page" : undefined}
              onClick={(event) => navigate(event, href)}
            >
              <span className="cp-nav__icon"><IconComponent /></span>
              <span className="cp-nav__label">{navLabel(item)}</span>
              {showCount ? <span className="cp-count">{unread}</span> : null}
            </a>
          );
        })}
      </nav>

      {brand.tel || brand.email ? (
        <div className="cp-sidebar__help">
          <p className="cp-sidebar__help-title">{tt("Tu equipo Casanova")}</p>
          {brand.tel ? (
            <a className="cp-sidebar__help-link" href={`tel:${brand.tel.replace(/\s+/g, "")}`}>
              <Icon name="phone" size={18} />
              <span>{brand.tel}</span>
            </a>
          ) : null}
          {brand.email ? (
            <a className="cp-sidebar__help-link" href={`mailto:${brand.email}`}>
              <Icon name="mail" size={18} />
              <span>{brand.email}</span>
            </a>
          ) : null}
        </div>
      ) : null}
    </aside>
  );
}

export function MobileTabBar({ view, unread = 0, items = [] }) {
  return (
    <nav className="cp-tabbar" aria-label={tt("Menú principal")}>
      {items.map((item) => {
        const IconComponent = item.icon;
        const active = item.isActive(view);
        const href = viewHref(item.view);
        const showCount = item.key === "inbox" && view !== "inbox" && unread > 0;

        return (
          <a
            key={item.key}
            href={href}
            className={`cp-tabbar__item ${active ? "is-active" : ""}`}
            aria-current={active ? "page" : undefined}
            onClick={(event) => navigate(event, href)}
          >
            <span className="cp-tabbar__icon">
              <IconComponent />
              {showCount ? <span className="cp-tabbar__count">{unread > 9 ? "9+" : unread}</span> : null}
            </span>
            <span className="cp-tabbar__label">{navLabel(item)}</span>
          </a>
        );
      })}
    </nav>
  );
}

export function Topbar({ title, subtitle, chip, onRefresh, isRefreshing, profile, onGo, onLogout, onLocale, readOnly = false }) {
  const refreshLabel = tt("Actualizar");

  return (
    <>
      <header className="cp-topbar">
        <div className="cp-topbar__inner">
          <BrandLink className="cp-topbar__brand" />

          <div className="cp-topbar__heading">
            {title ? <h1 className="cp-topbar__title">{title}</h1> : null}
            {subtitle ? <p className="cp-topbar__subtitle">{subtitle}</p> : null}
          </div>

          <div className="cp-topbar__actions">
            {chip ? <span className="cp-topbar__chip">{chip}</span> : null}
            <button
              type="button"
              className="cp-iconbtn"
              onClick={onRefresh}
              disabled={isRefreshing}
              aria-label={refreshLabel}
              title={refreshLabel}
            >
              <Icon name="refresh" size={18} className={isRefreshing ? "is-spinning" : ""} />
            </button>
            <span className="cp-sr-only" aria-live="polite">{isRefreshing ? tt("Actualizando…") : ""}</span>
            <LanguageMenu locale={profile?.locale} onLocale={onLocale} disabled={readOnly} />
            <UserMenu profile={profile} onGo={onGo} onLogout={onLogout} />
          </div>
        </div>
      </header>

      {title ? (
        <div className="cp-pagehead">
          <h1 className="cp-pagehead__title">{title}</h1>
          {subtitle ? <p className="cp-pagehead__subtitle">{subtitle}</p> : null}
        </div>
      ) : null}
    </>
  );
}

export function PortalFooter() {
  const brand = brandInfo();

  return (
    <footer className="cp-footer">
      <div className="cp-footer__inner">
        <div className="cp-footer__brand">
          <div className="cp-footer__name">{brand.name}</div>
          {brand.address ? <div className="cp-footer__muted">{brand.address}</div> : null}
        </div>
        <div className="cp-footer__links">
          {brand.tel ? (
            <a className="cp-footer__link is-contact" href={`tel:${brand.tel.replace(/\s+/g, "")}`}>
              <Icon name="phone" size={16} />
              {brand.tel}
            </a>
          ) : null}
          {brand.email ? (
            <a className="cp-footer__link is-contact" href={`mailto:${brand.email}`}>
              <Icon name="mail" size={16} />
              {brand.email}
            </a>
          ) : null}
          {brand.web ? (
            <a className="cp-footer__link" href={brand.web} target="_blank" rel="noreferrer">
              {brand.web.replace(/^https?:\/\//, "").replace(/\/$/, "")}
              <Icon name="external" size={16} />
            </a>
          ) : null}
        </div>
      </div>
    </footer>
  );
}
