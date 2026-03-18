import React, { useState } from "react";

import Field from "./Field.jsx";
import { Notice } from "./ui.jsx";
import { tt } from "../i18n/t.js";

export default function SecurityView({ onChangePassword, readOnly = false, readOnlyMessage = "" }) {
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [confirm, setConfirm] = useState("");
  const lockedMessage = readOnlyMessage || tt("Modo de vista cliente activo. Solo lectura.");

  return (
    <div className="cp-content">
      <div className="cp-card" style={{ background: "var(--surface)" }}>
        <div className="cp-card-title">{tt("Cambiar contraseña")}</div>
        <div className="cp-help" style={{ marginTop: -6, marginBottom: 18 }}>
          {tt("Tu contraseña es tu llave digital. No la compartas, aunque a los humanos les encante hacerlo.")}
        </div>
        {readOnly ? (
          <div style={{ marginBottom: 18 }}>
            <Notice variant="warn" title={tt("Cambio de contraseña desactivado")}>
              {lockedMessage} {tt("Puedes revisar esta sección, pero no cambiar la contraseña del cliente.")}
            </Notice>
          </div>
        ) : null}

        <section className="cp-form-section cp-form-section--compact">
          <div className="cp-form-section__head">
            <div className="cp-form-section__eyebrow">{tt("Seguridad")}</div>
            <div className="cp-form-section__title">{tt("Credenciales de acceso")}</div>
          </div>

          <Field label={tt("Contraseña actual")} htmlFor="security-current-password" readOnly={readOnly}>
            <input
              id="security-current-password"
              className="cp-input"
              type="password"
              value={current}
              onChange={(event) => setCurrent(event.target.value)}
              readOnly={readOnly}
              aria-readonly={readOnly ? "true" : undefined}
            />
          </Field>
          <Field label={tt("Nueva contraseña")} htmlFor="security-next-password" readOnly={readOnly}>
            <input
              id="security-next-password"
              className="cp-input"
              type="password"
              value={next}
              onChange={(event) => setNext(event.target.value)}
              readOnly={readOnly}
              aria-readonly={readOnly ? "true" : undefined}
            />
          </Field>
          <Field label={tt("Confirmar nueva contraseña")} htmlFor="security-confirm-password" readOnly={readOnly}>
            <input
              id="security-confirm-password"
              className="cp-input"
              type="password"
              value={confirm}
              onChange={(event) => setConfirm(event.target.value)}
              readOnly={readOnly}
              aria-readonly={readOnly ? "true" : undefined}
            />
          </Field>
        </section>

        <div className="cp-actions-row">
          <button className="cp-btn-primary" type="button" onClick={() => onChangePassword({ current, next, confirm })} disabled={readOnly}>
            {readOnly ? tt("Actualización desactivada") : tt("Actualizar")}
          </button>
        </div>
      </div>
    </div>
  );
}
