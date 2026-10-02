import React, { useEffect, useState } from "react";

import Field from "./Field.jsx";
import Icon from "./Icon.jsx";
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

  const field = (key) => ({
    className: "cp-input",
    value: form[key],
    onChange: (event) => setForm((state) => ({ ...state, [key]: event.target.value })),
    readOnly,
    "aria-readonly": readOnly ? "true" : undefined,
  });

  return (
    <div className="cp-content cp-settings">
      {readOnly ? (
        <Notice variant="warn" title={tt("Edición desactivada")}>
          {lockedMessage} {tt("Puedes consultar los datos del cliente, pero no modificarlos desde esta vista.")}
        </Notice>
      ) : null}

      <form
        className="cp-settings__form"
        onSubmit={(event) => {
          event.preventDefault();
          if (!readOnly) onSave(form);
        }}
      >
        <section className="cp-form-section">
          <h2 className="cp-form-section__title">{tt("Contacto y acceso")}</h2>

          <div className="cp-grid2">
            <Field label={tt("Nombre")} htmlFor="profile-name" readOnly>
              <input id="profile-name" className="cp-input" value={fullName} readOnly aria-readonly="true" autoComplete="name" />
            </Field>
            <Field label={tt("Email")} htmlFor="profile-email" readOnly>
              <input id="profile-email" className="cp-input" value={email} readOnly aria-readonly="true" autoComplete="email" />
            </Field>
          </div>

          <div className="cp-grid2">
            <Field label={tt("Teléfono")} htmlFor="profile-phone" readOnly={readOnly}>
              <input id="profile-phone" type="tel" autoComplete="tel" {...field("telefono")} />
            </Field>
            <Field label={tt("Móvil")} htmlFor="profile-mobile" readOnly={readOnly}>
              <input id="profile-mobile" type="tel" autoComplete="tel" {...field("movil")} />
            </Field>
          </div>
        </section>

        <section className="cp-form-section">
          <h2 className="cp-form-section__title">{tt("Datos de ubicación")}</h2>

          <Field label={tt("Dirección")} htmlFor="profile-address" readOnly={readOnly}>
            <input id="profile-address" autoComplete="street-address" {...field("direccion")} />
          </Field>

          <div className="cp-grid2">
            <Field label={tt("Código postal")} htmlFor="profile-postal-code" readOnly={readOnly}>
              <input id="profile-postal-code" autoComplete="postal-code" inputMode="numeric" {...field("codPostal")} />
            </Field>
            <Field label={tt("Población")} htmlFor="profile-city" readOnly={readOnly}>
              <input id="profile-city" autoComplete="address-level2" {...field("poblacion")} />
            </Field>
          </div>

          <div className="cp-grid2">
            <Field label={tt("Provincia")} htmlFor="profile-region" readOnly={readOnly}>
              <input id="profile-region" autoComplete="address-level1" {...field("provincia")} />
            </Field>
            <Field
              label={tt("País")}
              htmlFor="profile-country"
              help={tt("(Opcional, según datos de facturación)")}
              readOnly={readOnly}
            >
              <input id="profile-country" autoComplete="country-name" {...field("pais")} />
            </Field>
          </div>
        </section>

        <div className="cp-settings__actions">
          <button className="cp-btn cp-btn--primary" type="submit" disabled={readOnly}>
            {readOnly ? tt("Edición desactivada") : tt("Guardar")}
          </button>
        </div>
      </form>

      <section className="cp-form-section">
        <h2 className="cp-form-section__title">{t("portal_language", "Idioma del portal")}</h2>
        <Field label={tt("Idioma")} htmlFor="profile-locale" help={tt("Esto solo afecta al portal.")} readOnly={readOnly}>
          <span className="cp-select cp-select--field">
            <select
              id="profile-locale"
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
            <Icon name="chevron" size={16} />
          </span>
        </Field>
      </section>
    </div>
  );
}
