import React, { useEffect, useState } from "react";

import Icon from "../Icon.jsx";
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
    return Promise.reject(new Error(tt("Aplazame no está configurado correctamente.")));
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

function methodIconName(id) {
  if (id === "card_usd") return "dollar";
  if (id === "bank_transfer") return "bank";
  if (id === "aplazame") return "calendar";
  return "credit-card";
}

function methodBadges(id) {
  if (id === "card_usd") return ["USD", "Stripe", "SSL"];
  if (id === "bank_transfer") return ["PSD2", tt("Sin recargo"), "SEPA"];
  if (id === "aplazame") return [tt("3 cuotas"), tt("6 cuotas"), tt("12 cuotas")];
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
            setState({ loading: null, error: tt("No se pudo abrir Aplazame. Inténtalo de nuevo.") });
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
              setState({ loading: null, error: tt("No se pudo abrir Aplazame. Inténtalo de nuevo.") });
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
  const isUsdMethod = payMethod === "card_usd";
  const usdQuotes = payments?.usd_quotes && typeof payments.usd_quotes === "object" ? payments.usd_quotes : {};
  const quoteFor = (type) => {
    const quote = usdQuotes?.[type];
    if (!quote || typeof quote !== "object") return null;
    const amount = Number(quote.usd_amount);
    return Number.isFinite(amount) && amount > 0 ? { ...quote, usd_amount: amount } : null;
  };
  const displayAmountFor = (type, amount) => {
    const quote = isUsdMethod ? quoteFor(type) : null;
    if (quote) return { amount: quote.usd_amount, currency: "USD", baseAmount: amount };
    return { amount, currency, baseAmount: amount };
  };
  const formatActionAmount = (type, amount) => {
    const display = displayAmountFor(type, amount);
    return euro(display.amount, display.currency);
  };
  const formatBaseHint = (amount) => `${tt("Base EUR")}: ${euro(amount, currency)}`;
  const usdMethodHint = (() => {
    const parts = [];
    if (balanceAllowed) {
      const q = quoteFor("balance");
      if (q) parts.push(`${tt("Total")}: ${euro(q.usd_amount, "USD")}`);
    }
    if (depositAllowed) {
      const q = quoteFor("deposit");
      if (q) parts.push(`${tt("Depósito")}: ${euro(q.usd_amount, "USD")}`);
    }
    return parts.join(" - ");
  })();
  const transferNote = tt("El pago por transferencia bancaria online PSD2 no tiene recargo y es completamente seguro. Serás redirigido a una página de pago donde podrás seleccionar tu banco y acceder a tu banca online para autorizar la transferencia. Una vez completado el pago, volverás automáticamente a nuestra página. Este método es compatible con la mayoría de bancos españoles y portugueses.");
  const aplazameNote = tt("Aplazame te permite fraccionar el pago del viaje. Al continuar se abrirá su checkout seguro para completar la financiación en cuotas.");
  const usdNote = tt("Se cobrará con tarjeta en USD mediante Stripe.");

  // Resolved amount and label for CTA
  const resolvedType = payType || (balanceAllowed ? "balance" : "deposit");
  const resolvedAmount = resolvedType === "deposit" ? depositAmount : balanceAmount;
  const resolvedDisplay = displayAmountFor(resolvedType, resolvedAmount);
  const enabledMethods = methods.filter((m) => m && m.enabled);
  const activeMethodObj = enabledMethods.find((m) => m.id === payMethod) || enabledMethods[0];
  const activeMethodLabel = activeMethodObj
    ? (activeMethodObj.id === "card_usd"
        ? tt("tarjeta en USD")
        : activeMethodObj.id === "bank_transfer"
        ? tt("transferencia")
        : activeMethodObj.id === "aplazame"
          ? tt("Aplazame")
          : cardBrand === "amex"
            ? tt("AMEX")
          : (activeMethodObj.label || tt("tarjeta")).toLowerCase())
    : "";

  if (readOnly) {
    return (
      <div className="cp-pay-form">
        <Notice variant="warn" title={tt("Pagos desactivados")}>
          {lockedMessage} {tt("Puedes revisar el estado de pagos, pero no iniciar cobros desde esta vista.")}
        </Notice>
      </div>
    );
  }

  const amountOptions = [
    depositAllowed ? {
      type: "deposit",
      label: tt("Pagar depósito"),
      amount: depositAmount,
      desc: isUsdMethod
        ? formatBaseHint(depositAmount)
        : (Number.isFinite(totalAmount) && totalAmount > 0
            ? `${Math.round((depositAmount / totalAmount) * 100)}% ${tt("del total como reserva")}`
            : ""),
    } : null,
    balanceAllowed ? {
      type: "balance",
      label: tt("Pagar pendiente"),
      amount: balanceAmount,
      desc: isUsdMethod ? formatBaseHint(balanceAmount) : tt("Liquida el importe total pendiente"),
      tag: hasMultipleActionChoices ? tt("Saldar deuda completa") : "",
    } : null,
  ].filter(Boolean);

  const methodNote = payMethod === "bank_transfer"
    ? transferNote
    : payMethod === "aplazame"
      ? aplazameNote
      : payMethod === "card_usd"
        ? usdNote
        : "";

  return (
    <div className="cp-pay-form">
      <section className="cp-pay-step">
        <h2 className="cp-pay-step__title" id="cp-pay-method-title">{tt("Elige método de pago")}</h2>
        <div className="cp-pay-methods" role="radiogroup" aria-labelledby="cp-pay-method-title">
          {enabledMethods.map((method) => {
            const isBankTransfer = method.id === "bank_transfer";
            const isAplazame = method.id === "aplazame";
            const isUsdCard = method.id === "card_usd";
            const title = isUsdCard
              ? tt("Tarjeta en USD")
              : isBankTransfer
              ? tt("Transferencia")
              : isAplazame
                ? tt("Aplazame")
                : (method.label || tt("Tarjeta"));
            const desc = isUsdCard
              ? tt("Pago con tarjeta en dólares mediante Stripe.") + (usdMethodHint ? ` ${usdMethodHint}` : "")
              : isBankTransfer
              ? tt("Transferencia bancaria online PSD2. Sin recargo adicional.")
              : isAplazame
                ? tt("Pago a plazos. Divide el importe en cuotas mensuales cómodas.")
                : tt("Pago inmediato y seguro con tarjeta de crédito o débito.");
            const selected = payMethod === method.id;

            return (
              <button
                key={method.id}
                type="button"
                role="radio"
                aria-checked={selected ? "true" : "false"}
                className={`cp-pay-option ${selected ? "is-active" : ""}`.trim()}
                onClick={() => setPayMethod(method.id)}
              >
                <span className="cp-pay-option__icon"><Icon name={methodIconName(method.id)} size={20} /></span>
                <span className="cp-pay-option__body">
                  <span className="cp-pay-option__title">{title}</span>
                  <span className="cp-pay-option__desc">{desc}</span>
                  <span className="cp-pay-option__tags">
                    {methodBadges(method.id).map((badge) => <span key={badge}>{badge}</span>)}
                  </span>
                </span>
                <span className="cp-pay-option__check" aria-hidden="true"><Icon name="check" size={14} /></span>
              </button>
            );
          })}
        </div>

        {payMethod === "card" ? (
          <div className="cp-pay-brand">
            <p className="cp-pay-brand__label" id="cp-pay-brand-title">{tt("¿Con qué tarjeta vas a pagar?")}</p>
            <div className="cp-segmented cp-pay-brand__choices" role="radiogroup" aria-labelledby="cp-pay-brand-title">
              <button
                type="button"
                role="radio"
                aria-checked={cardBrand === "other" ? "true" : "false"}
                className={cardBrand === "other" ? "is-active" : ""}
                onClick={() => setCardBrand("other")}
              >
                {tt("Visa, Mastercard y otras")}
              </button>
              <button
                type="button"
                role="radio"
                aria-checked={cardBrand === "amex" ? "true" : "false"}
                className={cardBrand === "amex" ? "is-active" : ""}
                onClick={() => setCardBrand("amex")}
              >
                {tt("American Express")}
              </button>
            </div>
            <p className="cp-pay-brand__help">{tt("American Express se procesa en una pasarela segura aparte.")}</p>
          </div>
        ) : null}

        {methodNote ? <Notice variant="info">{methodNote}</Notice> : null}
      </section>

      <section className="cp-pay-step">
        <h2 className="cp-pay-step__title" id="cp-pay-amount-title">{tt("Selecciona cuánto pagar ahora")}</h2>
        {amountOptions.length > 1 ? (
          <div className="cp-pay-amounts" role="radiogroup" aria-labelledby="cp-pay-amount-title">
            {amountOptions.map((option) => {
              const selected = resolvedType === option.type;
              return (
                <button
                  key={option.type}
                  type="button"
                  role="radio"
                  aria-checked={selected ? "true" : "false"}
                  className={`cp-pay-option cp-pay-amount ${selected ? "is-active" : ""}`.trim()}
                  disabled={state.loading !== null}
                  onClick={() => setPayType(option.type)}
                >
                  <span className="cp-pay-option__body">
                    <span className="cp-pay-amount__label">
                      {option.label}
                      {option.tag ? <span className="cp-badge cp-badge--info">{option.tag}</span> : null}
                    </span>
                    <span className="cp-pay-amount__value">{formatActionAmount(option.type, option.amount)}</span>
                    {option.desc ? <span className="cp-pay-option__desc">{option.desc}</span> : null}
                  </span>
                  <span className="cp-pay-option__check" aria-hidden="true"><Icon name="check" size={14} /></span>
                </button>
              );
            })}
          </div>
        ) : amountOptions.length === 1 ? (
          // Con una sola opción no hay nada que elegir: se muestra el importe y el cobro
          // se inicia solo desde el botón «Pagar» (antes también al tocar la tarjeta).
          <div className="cp-pay-amount is-single">
            <span className="cp-pay-amount__label">{amountOptions[0].label}</span>
            <span className="cp-pay-amount__value">{formatActionAmount(amountOptions[0].type, amountOptions[0].amount)}</span>
            {amountOptions[0].desc ? <span className="cp-pay-option__desc">{amountOptions[0].desc}</span> : null}
          </div>
        ) : !isPaidLocal ? (
          <p className="cp-meta">{tt("Aún no hay pagos disponibles para este viaje.")}</p>
        ) : null}
      </section>

      {hasActions ? (
        <button
          type="button"
          className="cp-btn cp-btn--primary cp-pay-submit"
          disabled={state.loading !== null}
          aria-busy={state.loading ? "true" : "false"}
          onClick={() => startIntent(resolvedType, payMethod)}
        >
          {state.loading ? (
            <>
              <Icon name="refresh" size={20} className="is-spinning" />
              {tt("Redirigiendo…")}
            </>
          ) : (
            <>
              <Icon name="lock" size={20} />
              {tt("Pagar")} {euro(resolvedDisplay.amount, resolvedDisplay.currency)} {tt("con")} {activeMethodLabel}
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
