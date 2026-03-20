import React, { useEffect, useState } from "react";

import Field from "./Field.jsx";
import { Notice } from "./ui.jsx";
import { getLanguages, t, tt } from "../i18n/t.js";

function fallbackLanguages() {
  return [
    { value: "es_ES", locale: "es_ES", lang: "es", name: tt("Español") },
    { value: "en_US", locale: "en_US", lang: "en", name: tt("English") },
  ];
}

function availableLanguages() {
  const items = getLanguages();
  return items.length ? items : fallbackLanguages();
}

function currentLocaleValue(locale, items) {
  const current = String(locale || "");
  if (current) return current;

  if (typeof window !== "undefined") {
    const runtimeLocale = String(window.CASANOVA_I18N_META?.localeRaw || "");
    if (runtimeLocale) return runtimeLocale;
  }

  return items[0]?.value || "es_ES";
}

export default function ProfileView({ profile, onSave, onLocale, readOnly = false, readOnlyMessage = "" }) {
  const giav = profile?.giav || {};
  const languageItems = availableLanguages();
  const [form, setForm] = useState(() => ({
    telefono: giav.telefono || "",
    movil: giav.movil || "",
    direccion: giav.direccion || "",
    codPostal: giav.codPostal || "",
    poblacion: giav.poblacion || "",
    provincia: giav.provincia || "",
    pais: giav.pais || "",
  }));

  useEffect(() => {
    setForm({
      telefono: giav.telefono || "",
      movil: giav.movil || "",
      direccion: giav.direccion || "",
      codPostal: giav.codPostal || "",
      poblacion: giav.poblacion || "",
      provincia: giav.provincia || "",
      pais: giav.pais || "",
    });
  }, [giav.codPostal, giav.direccion, giav.movil, giav.pais, giav.poblacion, giav.provincia, giav.telefono]);

  const locale = currentLocaleValue(profile?.locale, languageItems);
  const lockedMessage = readOnlyMessage || tt("Modo de vista cliente activo. Solo lectura.");
  const fullName = `${giav.nombre || ""} ${giav.apellidos || ""}`.trim() || "—";
  const email = giav.email || profile?.user?.email || "—";

  return (
    <div className="cp-content">
      <div className="cp-card">
        <div className="cp-card-title">{tt("Información personal")}</div>
        {readOnly ? (
          <div className="cp-mt-14">
            <Notice variant="warn" title={tt("Edición desactivada")}>
              {lockedMessage} {tt("Puedes consultar los datos del cliente, pero no modificarlos desde esta vista.")}
            </Notice>
          </div>
        ) : null}

        <section className="cp-form-section">
          <div className="cp-form-section__head">
            <div className="cp-form-section__eyebrow">{tt("Datos personales")}</div>
            <div className="cp-form-section__title">{tt("Contacto y acceso")}</div>
          </div>

          <div className="cp-grid2">
            <Field label={tt("Nombre")} htmlFor="profile-name" readOnly>
              <input id="profile-name" className="cp-input" value={fullName} readOnly aria-readonly="true" />
            </Field>
            <Field label={tt("Email")} htmlFor="profile-email" readOnly>
              <input id="profile-email" className="cp-input" value={email} readOnly aria-readonly="true" />
            </Field>
          </div>

          <div className="cp-grid2">
            <Field label={tt("Teléfono")} htmlFor="profile-phone" readOnly={readOnly}>
              <input
                id="profile-phone"
                className="cp-input"
                value={form.telefono}
                onChange={(event) => setForm((state) => ({ ...state, telefono: event.target.value }))}
                readOnly={readOnly}
                aria-readonly={readOnly ? "true" : undefined}
              />
            </Field>
            <Field label={tt("Móvil")} htmlFor="profile-mobile" readOnly={readOnly}>
              <input
                id="profile-mobile"
                className="cp-input"
                value={form.movil}
                onChange={(event) => setForm((state) => ({ ...state, movil: event.target.value }))}
                readOnly={readOnly}
                aria-readonly={readOnly ? "true" : undefined}
              />
            </Field>
          </div>
        </section>

        <div className="cp-divider cp-divider--section" />

        <section className="cp-form-section">
          <div className="cp-form-section__head">
            <div className="cp-form-section__eyebrow">{tt("Dirección")}</div>
            <div className="cp-form-section__title">{tt("Datos de ubicación")}</div>
          </div>

          <Field label={tt("Dirección")} htmlFor="profile-address" readOnly={readOnly}>
            <input
              id="profile-address"
              className="cp-input"
              value={form.direccion}
              onChange={(event) => setForm((state) => ({ ...state, direccion: event.target.value }))}
              readOnly={readOnly}
              aria-readonly={readOnly ? "true" : undefined}
            />
          </Field>

          <div className="cp-grid2">
            <Field label={tt("Código postal")} htmlFor="profile-postal-code" readOnly={readOnly}>
              <input
                id="profile-postal-code"
                className="cp-input"
                value={form.codPostal}
                onChange={(event) => setForm((state) => ({ ...state, codPostal: event.target.value }))}
                readOnly={readOnly}
                aria-readonly={readOnly ? "true" : undefined}
              />
            </Field>
            <Field label={tt("Población")} htmlFor="profile-city" readOnly={readOnly}>
              <input
                id="profile-city"
                className="cp-input"
                value={form.poblacion}
                onChange={(event) => setForm((state) => ({ ...state, poblacion: event.target.value }))}
                readOnly={readOnly}
                aria-readonly={readOnly ? "true" : undefined}
              />
            </Field>
          </div>

          <div className="cp-grid2">
            <Field label={tt("Provincia")} htmlFor="profile-region" readOnly={readOnly}>
              <input
                id="profile-region"
                className="cp-input"
                value={form.provincia}
                onChange={(event) => setForm((state) => ({ ...state, provincia: event.target.value }))}
                readOnly={readOnly}
                aria-readonly={readOnly ? "true" : undefined}
              />
            </Field>
            <Field
              label={tt("País")}
              htmlFor="profile-country"
              help={tt("(Opcional, según datos de facturación)")}
              readOnly={readOnly}
            >
              <input
                id="profile-country"
                className="cp-input"
                value={form.pais}
                onChange={(event) => setForm((state) => ({ ...state, pais: event.target.value }))}
                readOnly={readOnly}
                aria-readonly={readOnly ? "true" : undefined}
              />
            </Field>
          </div>
        </section>

        <div className="cp-actions-row">
          <button className="cp-btn-primary" type="button" onClick={() => onSave(form)} disabled={readOnly}>
            {readOnly ? tt("Edición desactivada") : tt("Guardar")}
          </button>
        </div>
      </div>

      <div className="cp-card">
        <div className="cp-card-title">{t("portal_language", "Idioma del portal")}</div>
        <section className="cp-form-section cp-form-section--compact">
          <Field label={tt("Idioma")} htmlFor="profile-locale" help={tt("Esto solo afecta al portal.")} readOnly={readOnly}>
            <select
              id="profile-locale"
              className="cp-input cp-input--narrow"
              value={locale}
              onChange={(event) => {
                const selected = languageItems.find((item) => (item.value || item.locale) === event.target.value);
                if (!selected) {
                  onLocale(event.target.value);
                  return;
                }
                onLocale({
                  locale: selected.locale || selected.value,
                  lang: selected.lang || String(selected.value || "").slice(0, 2).toLowerCase(),
                });
              }}
              disabled={readOnly}
              aria-disabled={readOnly ? "true" : undefined}
            >
              {languageItems.map((item) => (
                <option key={item.value || item.locale} value={item.value || item.locale}>
                  {item.name}
                </option>
              ))}
            </select>
          </Field>
        </section>
      </div>
    </div>
  );
}
