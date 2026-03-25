import React, { useEffect, useState } from "react";

import { Notice } from "../ui.jsx";
import { tt } from "../../i18n/t.js";
import { api } from "../../lib/api.js";
import { euro } from "../../lib/formatters.js";

let aplazameSdkPromise = null;

function onAplazameReady() {
  return new Promise((resolve) => {
    if (window.aplazame && typeof window.aplazame.checkout === "function") {
      resolve(window.aplazame);
      return;
    }

    (window.aplazame = window.aplazame || []).push((aplazame) => {
      resolve(aplazame);
    });
  });
}

function loadAplazameSdk(config = {}) {
  const publicKey = String(config?.public_key || "").trim();
  const sandbox = Boolean(config?.sandbox);
  if (!publicKey) {
    return Promise.reject(new Error(tt("Aplazame no esta configurado correctamente.")));
  }

  if (aplazameSdkPromise) {
    return aplazameSdkPromise;
  }

  const src = `https://cdn.aplazame.com/aplazame.js?public-key=${encodeURIComponent(publicKey)}&sandbox=${sandbox ? "true" : "false"}`;
  aplazameSdkPromise = new Promise((resolve, reject) => {
    const existing = document.querySelector('script[data-casanova-aplazame="1"]');
    if (existing) {
      onAplazameReady().then(resolve).catch(reject);
      return;
    }

    const script = document.createElement("script");
    script.src = src;
    script.async = true;
    script.defer = true;
    script.dataset.casanovaAplazame = "1";
    script.onload = () => {
      onAplazameReady().then(resolve).catch(reject);
    };
    script.onerror = () => {
      aplazameSdkPromise = null;
      reject(new Error(tt("No se pudo cargar Aplazame.")));
    };

    document.head.appendChild(script);
  });

  return aplazameSdkPromise;
}

const CheckSvg = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 6L9 17l-5-5"/></svg>
);

function methodIcon(id) {
  if (id === "bank_transfer") {
    return {
      bg: "var(--accent-light)",
      stroke: "var(--accent)",
      svg: <svg viewBox="0 0 24 24" fill="none" stroke="var(--accent)" strokeWidth="1.8"><path d="M3 21V7a2 2 0 0 1 2-2h6v16"/><path d="M21 21V11a2 2 0 0 0-2-2h-4v12"/></svg>,
    };
  }
  if (id === "aplazame") {
    return {
      bg: "var(--purple-light)",
      stroke: "var(--purple)",
      svg: <svg viewBox="0 0 24 24" fill="none" stroke="var(--purple)" strokeWidth="1.8"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 1 0 0 7h5a3.5 3.5 0 1 1 0 7H6"/></svg>,
    };
  }
  // card
  return {
    bg: "var(--blue-light)",
    stroke: "var(--blue)",
    svg: <svg viewBox="0 0 24 24" fill="none" stroke="var(--blue)" strokeWidth="1.8"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>,
  };
}

function methodBadges(id) {
  if (id === "bank_transfer") return ["PSD2", "Sin recargo", "SEPA"];
  if (id === "aplazame") return ["3 cuotas", "6 cuotas", "12 cuotas"];
  return ["Visa", "Mastercard", "AMEX", "SSL"];
}

export default function PaymentActions({ expediente, payments, mock, readOnly = false, readOnlyMessage = "" }) {
  const [state, setState] = useState({ loading: null, error: null });
  const lockedMessage = readOnlyMessage || tt("Modo de vista cliente activo. Solo lectura.");

  const methods = Array.isArray(payments?.payment_methods)
    ? payments.payment_methods
    : [
        { id: "card", enabled: true, label: tt("Tarjeta") },
        { id: "bank_transfer", enabled: true, label: tt("Transferencia bancaria") },
      ];

  const firstEnabledMethod = (methods.find((method) => method && method.enabled) || methods[0] || { id: "card" }).id;
  const [payMethod, setPayMethod] = useState(firstEnabledMethod);
  const [payType, setPayType] = useState(null);
  const [cardBrand, setCardBrand] = useState("other");

  useEffect(() => {
    const enabledIds = methods.filter((method) => method && method.enabled).map((method) => method.id);
    if (!enabledIds.includes(payMethod)) {
      setPayMethod(firstEnabledMethod || "card");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [payments?.payment_methods]);

  const totalAmount = typeof payments?.total === "number" ? payments.total : Number.NaN;
  const paidAmount = typeof payments?.paid === "number" ? payments.paid : Number.NaN;
  const pendingCandidate = typeof payments?.pending === "number" ? payments.pending : Number.NaN;
  const pendingAmount = Number.isFinite(pendingCandidate)
    ? pendingCandidate
    : (Number.isFinite(totalAmount) && Number.isFinite(paidAmount)
        ? Math.max(0, totalAmount - paidAmount)
        : null);
  const isPaidLocal = pendingAmount !== null ? pendingAmount <= 0.01 : false;
  const actions = payments?.actions ?? {};
  const deposit = actions.deposit ?? { allowed: false, amount: 0 };
  const balance = actions.balance ?? { allowed: false, amount: 0 };
  const options = payments?.payment_options ?? null;
  const depositAllowed =
    typeof options?.can_pay_deposit === "boolean" ? options.can_pay_deposit : deposit.allowed;
  const depositAmount =
    typeof options?.deposit_amount === "number" ? options.deposit_amount : deposit.amount;
  const balanceAllowed =
    typeof options?.can_pay_full === "boolean" ? options.can_pay_full : balance.allowed;
  const balanceAmount =
    typeof options?.pending_amount === "number" ? options.pending_amount : balance.amount;

  // Auto-select pay type
  useEffect(() => {
    if (balanceAllowed && !depositAllowed) {
      setPayType("balance");
    } else if (depositAllowed && !balanceAllowed) {
      setPayType("deposit");
    } else if (balanceAllowed && depositAllowed && payType === null) {
      setPayType("balance");
    }
  }, [depositAllowed, balanceAllowed, payType]);

  const startIntent = async (type, method) => {
    if (readOnly) {
      setState({ loading: null, error: lockedMessage });
      return;
    }

    setState({ loading: type, error: null });
    try {
      const qs = mock ? "?mock=1" : "";
      const body = {
        expediente_id: Number(expediente),
        type,
        method,
      };
      if (method === "card") {
        body.card_brand = cardBrand;
      }
      const payload = await api(`/payments/intent${qs}`, {
        method: "POST",
        body,
      });

      if (payload?.ok && method === "aplazame" && payload?.checkout_id) {
        const aplazame = await loadAplazameSdk(payload?.aplazame);
        const returnUrls = payload?.return_urls || {};

        aplazame.checkout(payload.checkout_id, {
          onSuccess() {
            window.location.href = returnUrls.success || window.location.href;
          },
          onPending() {
            window.location.href = returnUrls.pending || window.location.href;
          },
          onKO() {
            window.location.href = returnUrls.ko || window.location.href;
          },
          onError() {
            setState({ loading: null, error: tt("No se pudo abrir Aplazame. Intentalo de nuevo.") });
          },
          onDismiss() {
            setState({ loading: null, error: null });
          },
          onClose(resultStatus) {
            if (resultStatus === "success") {
              window.location.href = returnUrls.success || window.location.href;
              return;
            }
            if (resultStatus === "pending") {
              window.location.href = returnUrls.pending || window.location.href;
              return;
            }
            if (resultStatus === "ko") {
              window.location.href = returnUrls.ko || window.location.href;
              return;
            }
            if (resultStatus === "error") {
              setState({ loading: null, error: tt("No se pudo abrir Aplazame. Intentalo de nuevo.") });
              return;
            }
            setState({ loading: null, error: null });
          },
        });
        return;
      }

      if (payload?.ok && payload?.redirect_url) {
        window.location.href = payload.redirect_url;
        return;
      }

      throw payload;
    } catch (error) {
      const message =
        typeof error === "string"
          ? error
          : error?.message || error?.msg || error?.code || tt("No se pudo iniciar el pago.");
      setState({ loading: null, error: message });
    }
  };

  const hasActions = depositAllowed || balanceAllowed;
  const hasMultipleActionChoices = depositAllowed && balanceAllowed;
  const currency = payments?.currency || "EUR";
  const transferNote = tt("El pago por transferencia bancaria online PSD2 no tiene recargo y es completamente seguro. Serás redirigido a una página de pago donde podrás seleccionar tu banco y acceder a tu banca online para autorizar la transferencia. Una vez completado el pago, volverás automáticamente a nuestra página. Este método es compatible con la mayoría de bancos españoles y portugueses.");
  const aplazameNote = tt("Aplazame te permite fraccionar el pago del viaje. Al continuar se abrira su checkout seguro para completar la financiacion en cuotas.");

  // Resolved amount and label for CTA
  const resolvedType = payType || (balanceAllowed ? "balance" : "deposit");
  const resolvedAmount = resolvedType === "deposit" ? depositAmount : balanceAmount;
  const enabledMethods = methods.filter((m) => m && m.enabled);
  const activeMethodObj = enabledMethods.find((m) => m.id === payMethod) || enabledMethods[0];
  const activeMethodLabel = activeMethodObj
    ? (activeMethodObj.id === "bank_transfer"
        ? tt("transferencia")
        : activeMethodObj.id === "aplazame"
          ? tt("Aplazame")
          : cardBrand === "amex"
            ? tt("AMEX")
          : (activeMethodObj.label || tt("tarjeta")).toLowerCase())
    : "";

  if (readOnly) {
    return (
      <div className="cp-mt-20">
        <Notice variant="warn" title={tt("Pagos desactivados")}>
          {lockedMessage} {tt("Puedes revisar el estado de pagos, pero no iniciar cobros desde esta vista.")}
        </Notice>
      </div>
    );
  }

  return (
    <div className="cp-mt-20 cp-stack-10">
      {/* ─── Method selection ─── */}
      <div className="cp-pay-section">
        <div className="cp-pay-section__label">{tt("Elige método de pago")}</div>
        <div className="cp-pay-methods" role="tablist" aria-label={tt("Método de pago")}>
          {enabledMethods.map((method) => {
            const isBankTransfer = method.id === "bank_transfer";
            const isAplazame = method.id === "aplazame";
            const title = isBankTransfer
              ? tt("Transferencia")
              : isAplazame
                ? tt("Aplazame")
                : (method.label || tt("Tarjeta"));
            const desc = isBankTransfer
              ? tt("Transferencia bancaria online PSD2. Sin recargo adicional.")
              : isAplazame
                ? tt("Pago a plazos. Divide el importe en cuotas mensuales cómodas.")
                : tt("Pago inmediato y seguro con tarjeta de crédito o débito.");
            const icon = methodIcon(method.id);
            const badges = methodBadges(method.id);

            return (
              <button
                key={method.id}
                type="button"
                className={`cp-pay-method ${payMethod === method.id ? "is-active" : ""}`}
                onClick={() => setPayMethod(method.id)}
              >
                <span className="cp-pay-method__check"><CheckSvg /></span>
                <div className="cp-pay-method__top">
                  <span className="cp-pay-method__icon" style={{ background: icon.bg }}>
                    {icon.svg}
                  </span>
                  <span className="cp-pay-method__title">{title}</span>
                </div>
                <span className="cp-pay-method__meta">{desc}</span>
                <span className="cp-pay-method__badges">
                  {badges.map((b) => <span key={b}>{b}</span>)}
                </span>
              </button>
            );
          })}
        </div>
      </div>

      {payMethod === "card" ? (
        <div className="cp-pay-card-brand">
          <div className="cp-pay-card-brand__label">{tt("Tipo de tarjeta")}</div>
          <div className="cp-pay-card-brand__choices" role="radiogroup" aria-label={tt("Tipo de tarjeta")}>
            <button
              type="button"
              role="radio"
              aria-checked={cardBrand === "other"}
              className={`cp-pay-card-brand__choice ${cardBrand === "other" ? "is-active" : ""}`}
              onClick={() => setCardBrand("other")}
            >
              <span className="cp-pay-card-brand__choice-title">{tt("Otra tarjeta")}</span>
              <span className="cp-pay-card-brand__choice-hint">{tt("Visa, Mastercard y similares.")}</span>
            </button>
            <button
              type="button"
              role="radio"
              aria-checked={cardBrand === "amex"}
              className={`cp-pay-card-brand__choice ${cardBrand === "amex" ? "is-active" : ""}`}
              onClick={() => setCardBrand("amex")}
            >
              <span className="cp-pay-card-brand__choice-title">{tt("American Express (AMEX)")}</span>
              <span className="cp-pay-card-brand__choice-hint">{tt("Selecciona esta opcion si vas a pagar con AMEX.")}</span>
            </button>
          </div>
          <div className="cp-pay-card-brand__hint">{tt("Elige con que tarjeta quieres realizar el pago.")}</div>
        </div>
      ) : null}

      {payMethod === "bank_transfer" ? (
        <div className="cp-pay-method-note">
          {transferNote}
        </div>
      ) : null}

      {payMethod === "aplazame" ? (
        <div className="cp-pay-method-note">
          {aplazameNote}
        </div>
      ) : null}

      {/* ─── Amount selection ─── */}
      <div className="cp-pay-section">
        <div className="cp-pay-section__label">{tt("Selecciona cuánto pagar ahora")}</div>
        <div className="cp-pay-cta-row">
          {depositAllowed ? (
            <button
              type="button"
              className={`cp-pay-cta ${payType === "deposit" || (!hasMultipleActionChoices) ? "primary" : ""}`.trim()}
              disabled={state.loading !== null}
              onClick={() => {
                if (hasMultipleActionChoices) {
                  setPayType("deposit");
                } else {
                  startIntent("deposit", payMethod);
                }
              }}
            >
              <span className="cp-pay-cta__check"><CheckSvg /></span>
              <span className="cp-pay-cta__label">{tt("Pagar depósito")}</span>
              <span className="cp-pay-cta__amount">{euro(depositAmount, currency)}</span>
              {Number.isFinite(totalAmount) && totalAmount > 0 ? (
                <span className="cp-pay-cta__desc">
                  {Math.round((depositAmount / totalAmount) * 100)}% {tt("del total como reserva")}
                </span>
              ) : null}
            </button>
          ) : null}

          {balanceAllowed ? (
            <button
              type="button"
              className={`cp-pay-cta ${payType === "balance" || (!hasMultipleActionChoices) ? "primary" : ""}`.trim()}
              disabled={state.loading !== null}
              onClick={() => {
                if (hasMultipleActionChoices) {
                  setPayType("balance");
                } else {
                  startIntent("balance", payMethod);
                }
              }}
            >
              {hasMultipleActionChoices ? (
                <span className="cp-pay-cta__rec">{tt("Saldar deuda completa")}</span>
              ) : null}
              <span className="cp-pay-cta__check"><CheckSvg /></span>
              <span className="cp-pay-cta__label">{tt("Pagar pendiente")}</span>
              <span className="cp-pay-cta__amount">{euro(balanceAmount, currency)}</span>
              <span className="cp-pay-cta__desc">{tt("Liquida el importe total pendiente")}</span>
            </button>
          ) : null}

          {!hasActions && !isPaidLocal ? (
            <div className="cp-meta cp-self-center">
              {tt("Aún no hay pagos disponibles para este viaje.")}
            </div>
          ) : null}
        </div>
      </div>

      {/* ─── Submit CTA ─── */}
      {hasActions ? (
        <button
          type="button"
          className="cp-pay-submit"
          disabled={state.loading !== null}
          onClick={() => startIntent(resolvedType, payMethod)}
        >
          {state.loading ? (
            tt("Redirigiendo…")
          ) : (
            <>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>
              {tt("Pagar")} {euro(resolvedAmount, currency)} {tt("con")} {activeMethodLabel}
            </>
          )}
        </button>
      ) : null}

      {state.error ? (
        <Notice variant="error" title={tt("No se puede iniciar el pago")}>
          {state.error}
        </Notice>
      ) : null}
    </div>
  );
}
