import React, { useEffect, useState } from "react";

import Field from "./Field.jsx";
import { Notice } from "./ui.jsx";
import { t, tt } from "../i18n/t.js";

export default function ProfileView({ profile, onSave, onLocale, readOnly = false, readOnlyMessage = "" }) {
  const giav = profile?.giav || {};
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

  const locale = profile?.locale || "";
  const lockedMessage = readOnlyMessage || tt("Modo de vista cliente activo. Solo lectura.");

  return (
    <div className="cp-content">
      <div className="cp-card" style={{ background: "var(--surface)" }}>
        <div className="cp-card-title">{tt("Información personal")}</div>
        {readOnly ? (
          <div style={{ marginTop: 14 }}>
            <Notice variant="warn" title={tt("Edición desactivada")}>
              {lockedMessage} {tt("Puedes consultar los datos del cliente, pero no modificarlos desde esta vista.")}
            </Notice>
          </div>
        ) : null}

        <div className="cp-grid2">
          <Field label="Nombre">
            <input className="cp-input" value={`${giav.nombre || ""} ${giav.apellidos || ""}`.trim() || "—"} disabled />
          </Field>
          <Field label="Email">
            <input className="cp-input" value={giav.email || profile?.user?.email || "—"} disabled />
          </Field>
        </div>

        <div className="cp-grid2">
          <Field label="Teléfono">
            <input className="cp-input" value={form.telefono} onChange={(event) => setForm((state) => ({ ...state, telefono: event.target.value }))} placeholder="" disabled={readOnly} />
          </Field>
          <Field label="Móvil">
            <input className="cp-input" value={form.movil} onChange={(event) => setForm((state) => ({ ...state, movil: event.target.value }))} placeholder="" disabled={readOnly} />
          </Field>
        </div>

        <div className="cp-divider" />
        <div className="cp-card-subtitle">{tt("Dirección")}</div>

        <Field label="Dirección">
          <input className="cp-input" value={form.direccion} onChange={(event) => setForm((state) => ({ ...state, direccion: event.target.value }))} disabled={readOnly} />
        </Field>

        <div className="cp-grid2">
          <Field label="Código postal">
            <input className="cp-input" value={form.codPostal} onChange={(event) => setForm((state) => ({ ...state, codPostal: event.target.value }))} disabled={readOnly} />
          </Field>
          <Field label="Población">
            <input className="cp-input" value={form.poblacion} onChange={(event) => setForm((state) => ({ ...state, poblacion: event.target.value }))} disabled={readOnly} />
          </Field>
        </div>

        <div className="cp-grid2">
          <Field label="Provincia">
            <input className="cp-input" value={form.provincia} onChange={(event) => setForm((state) => ({ ...state, provincia: event.target.value }))} disabled={readOnly} />
          </Field>
          <Field label="País" help="(Opcional, según datos de facturación)">
            <input className="cp-input" value={form.pais} onChange={(event) => setForm((state) => ({ ...state, pais: event.target.value }))} disabled={readOnly} />
          </Field>
        </div>

        <div className="cp-actions-row">
          <button className="cp-btn-primary" type="button" onClick={() => onSave(form)} disabled={readOnly}>
            {readOnly ? tt("Edición desactivada") : tt("Guardar")}
          </button>
        </div>
      </div>

      <div className="cp-card" style={{ background: "var(--surface)" }}>
        <div className="cp-card-title">{t("portal_language", "Idioma del portal")}</div>
        <div className="cp-row" style={{ gap: 12, alignItems: "center" }}>
          <select className="cp-input" value={locale} onChange={(event) => onLocale(event.target.value)} style={{ maxWidth: 280 }} disabled={readOnly}>
            <option value="es_ES">{tt("Español")}</option>
            <option value="en_US">{tt("English")}</option>
          </select>
          <div className="cp-help">{tt("Esto solo afecta al portal.")}</div>
        </div>
      </div>
    </div>
  );
}
