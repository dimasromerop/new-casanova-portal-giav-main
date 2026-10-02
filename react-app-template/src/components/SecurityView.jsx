import React, { useState } from "react";

import Field from "./Field.jsx";
import { Notice } from "./ui.jsx";
import { tt } from "../i18n/t.js";

export default function SecurityView({ onChangePassword, readOnly = false, readOnlyMessage = "", username = "" }) {
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [confirm, setConfirm] = useState("");
  const lockedMessage = readOnlyMessage || tt("Modo de vista cliente activo. Solo lectura.");

  return (
    <div className="cp-content cp-settings">
      {readOnly ? (
        <Notice variant="warn" title={tt("Cambio de contraseña desactivado")}>
          {lockedMessage} {tt("Puedes revisar esta sección, pero no cambiar la contraseña del cliente.")}
        </Notice>
      ) : null}

      <form
        className="cp-settings__form"
        onSubmit={(event) => {
          event.preventDefault();
          if (!readOnly) onChangePassword({ current, next, confirm });
        }}
      >
        <section className="cp-form-section">
          <h2 className="cp-form-section__title">{tt("Cambiar contraseña")}</h2>
          <p className="cp-help">
            {tt("Usa al menos 8 caracteres y una contraseña que no utilices en otros sitios. No la compartas con nadie.")}
          </p>

          {/* Campo oculto de usuario: ayuda a los gestores de contraseñas a asociar la clave nueva. */}
          <input type="text" name="username" autoComplete="username" value={username} readOnly hidden />

          <Field label={tt("Contraseña actual")} htmlFor="security-current-password" readOnly={readOnly}>
            <input
              id="security-current-password"
              className="cp-input"
              type="password"
              autoComplete="current-password"
              value={current}
              onChange={(event) => setCurrent(event.target.value)}
              readOnly={readOnly}
              aria-readonly={readOnly ? "true" : undefined}
            />
          </Field>
          <div className="cp-grid2">
            <Field label={tt("Nueva contraseña")} htmlFor="security-next-password" readOnly={readOnly}>
              <input
                id="security-next-password"
                className="cp-input"
                type="password"
                autoComplete="new-password"
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
                autoComplete="new-password"
                value={confirm}
                onChange={(event) => setConfirm(event.target.value)}
                readOnly={readOnly}
                aria-readonly={readOnly ? "true" : undefined}
              />
            </Field>
          </div>
        </section>

        <div className="cp-settings__actions">
          <button className="cp-btn cp-btn--primary" type="submit" disabled={readOnly}>
            {readOnly ? tt("Actualización desactivada") : tt("Actualizar")}
          </button>
        </div>
      </form>
    </div>
  );
}
