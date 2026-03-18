import React, { useEffect, useState } from "react";

import { Notice } from "../ui.jsx";
import { tt } from "../../i18n/t.js";
import { api } from "../../lib/api.js";
import { euro } from "../../lib/formatters.js";

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

  const startIntent = async (type, method) => {
    if (readOnly) {
      setState({ loading: null, error: lockedMessage });
      return;
    }

    setState({ loading: type, error: null });
    try {
      const qs = mock ? "?mock=1" : "";
      const payload = await api(`/payments/intent${qs}`, {
        method: "POST",
        body: {
          expediente_id: Number(expediente),
          type,
          method,
        },
      });
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
      <div className="cp-pay-section">
        <div className="cp-pay-section__label">{tt("Elige método de pago")}</div>
        <div className="cp-pay-methods" role="tablist" aria-label={tt("Método de pago")}>
          {methods.filter((method) => method && method.enabled).map((method) => {
            const isBankTransfer = method.id === "bank_transfer";
            const title = isBankTransfer
              ? tt("Transferencia bancaria online")
              : (method.label || tt("Tarjeta"));
            const meta = isBankTransfer
              ? tt("PSD2 · Sin recargo")
              : tt("Pago inmediato y seguro");

            return (
              <button
                key={method.id}
                type="button"
                className={`cp-pay-method ${payMethod === method.id ? "is-active" : ""}`}
                onClick={() => setPayMethod(method.id)}
              >
                <span className="cp-pay-method__title">{title}</span>
                <span className="cp-pay-method__meta">{meta}</span>
              </button>
            );
          })}
        </div>
      </div>

      {payMethod === "bank_transfer" ? (
        <div className="cp-pay-method-note">
          {transferNote}
        </div>
      ) : null}

      <div className="cp-pay-section">
        <div className="cp-pay-section__label">{tt("Selecciona cuánto pagar ahora")}</div>
        <div className="cp-pay-cta-row">
          {depositAllowed ? (
            <button
              className={`cp-btn cp-pay-cta ${hasMultipleActionChoices ? "is-secondary" : "primary"}`.trim()}
              disabled={state.loading !== null}
              onClick={() => startIntent("deposit", payMethod)}
            >
              {state.loading === "deposit"
                ? tt("Redirigiendo…")
                : (
                  <>
                    <span className="cp-pay-cta__label">{tt("Pagar depósito")}</span>
                    <span className="cp-pay-cta__amount">{euro(depositAmount, currency)}</span>
                  </>
                )}
            </button>
          ) : null}

          {balanceAllowed ? (
            <button
              className="cp-btn primary cp-pay-cta"
              disabled={state.loading !== null}
              onClick={() => startIntent("balance", payMethod)}
            >
              {state.loading === "balance"
                ? tt("Redirigiendo…")
                : (
                  <>
                    <span className="cp-pay-cta__label">{tt("Pagar pendiente")}</span>
                    <span className="cp-pay-cta__amount">{euro(balanceAmount, currency)}</span>
                  </>
                )}
            </button>
          ) : null}

          {!hasActions && !isPaidLocal ? (
            <div className="cp-meta cp-self-center">
              {tt("Aún no hay pagos disponibles para este viaje.")}
            </div>
          ) : null}
        </div>
      </div>

      {state.error ? (
        <Notice variant="error" title={tt("No se puede iniciar el pago")}>
          {state.error}
        </Notice>
      ) : null}
    </div>
  );
}
