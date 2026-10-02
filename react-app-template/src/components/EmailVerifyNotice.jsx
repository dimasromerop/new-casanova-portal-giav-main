import React, { useId, useState } from "react";

import { Notice } from "./ui.jsx";
import { tt, ttf } from "../i18n/t.js";
import { api } from "../lib/api.js";

const ERRORS = {
  rate_limited: () => tt("Demasiados intentos. Espera unos minutos."),
  invalid_code: () => tt("El código no es correcto."),
  expired: () => tt("El código ha caducado. Pide uno nuevo."),
  no_code: () => tt("Pide un código nuevo."),
  too_many_attempts: () => tt("Demasiados intentos. Pide un código nuevo."),
};

/*
  «Confirma tu email»: el registro no verifica el email, así que solo emparejamos propuestas por
  email cuando el cliente confirma que es suyo con un código (ver portal-email-verification.php).
*/
export default function EmailVerifyNotice({ emailMasked = "", mock = false, onVerified }) {
  const id = useId();
  const [step, setStep] = useState("idle"); // idle | sent | done
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function call(body) {
    if (mock) return { ok: true, sent: true, verified: Boolean(body.code) };
    return api("/proposals/verify-email", { method: "POST", body });
  }

  async function send() {
    setBusy(true);
    setError("");
    try {
      const res = await call({});
      if (res?.verified) {
        setStep("done");
        onVerified?.();
      } else {
        setStep("sent");
      }
    } catch (e) {
      setError((ERRORS[e?.code] || (() => e?.message || tt("No hemos podido enviar el email. Inténtalo más tarde.")))());
    } finally {
      setBusy(false);
    }
  }

  async function confirm(event) {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      await call({ code });
      setStep("done");
      onVerified?.();
    } catch (e) {
      setError((ERRORS[e?.code] || (() => e?.message || tt("No hemos podido comprobar el código.")))());
    } finally {
      setBusy(false);
    }
  }

  if (step === "done") {
    return (
      <Notice variant="success" title={tt("Email confirmado")}>
        {tt("Ya puedes ver aquí las propuestas que te enviemos a este email.")}
      </Notice>
    );
  }

  return (
    <div className="cp-verify">
      <Notice
        variant="info"
        title={tt("Confirma tu email")}
        action={step === "idle" ? (
          <button type="button" className="cp-btn cp-btn--sm" onClick={send} disabled={busy}>
            {busy ? tt("Enviando...") : tt("Enviarme el código")}
          </button>
        ) : null}
      >
        {step === "idle"
          ? (emailMasked
            ? ttf("Para enseñarte las propuestas que te enviemos a {email}, confírmanos que es tuyo.", { email: emailMasked })
            : tt("Para enseñarte las propuestas que te enviemos por email, confírmanos que es tuyo."))
          : ttf("Te hemos enviado un código de 6 cifras a {email}. Caduca en 15 minutos.", { email: emailMasked || tt("tu email") })}
      </Notice>

      {step === "sent" ? (
        <form className="cp-verify__form" onSubmit={confirm}>
          <label className="cp-sr-only" htmlFor={`${id}-code`}>{tt("Código de 6 cifras")}</label>
          <input
            id={`${id}-code`}
            className="cp-input cp-verify__code"
            inputMode="numeric"
            autoComplete="one-time-code"
            pattern="[0-9]{6}"
            maxLength={6}
            required
            placeholder="000000"
            value={code}
            onChange={(event) => setCode(event.target.value.replace(/\D+/g, ""))}
          />
          <button type="submit" className="cp-btn cp-btn--primary" disabled={busy || code.length !== 6}>
            {busy ? tt("Comprobando...") : tt("Confirmar")}
          </button>
          <button type="button" className="cp-btn cp-btn--ghost" onClick={send} disabled={busy}>{tt("Reenviar código")}</button>
        </form>
      ) : null}
      {error ? <p className="cp-dialog__error" role="alert">{error}</p> : null}
    </div>
  );
}
