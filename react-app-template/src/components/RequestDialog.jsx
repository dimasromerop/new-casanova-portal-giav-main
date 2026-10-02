import React, { useEffect, useId, useMemo, useRef, useState } from "react";

import Icon from "./Icon.jsx";
import { Notice, Skeleton } from "./ui.jsx";
import { tt } from "../i18n/t.js";
import { api } from "../lib/api.js";
import { formatDateRange, travellersLabel } from "../lib/proposals.js";

/*
  Diálogo para actualizar una propuesta caducada (proposalId) o pedir una nueva (sin proposalId).
  No es un formulario propio: el servidor envía el mismo formulario de solicitud de la web, así
  que las opciones (green-fees, jugadores) y los textos del reparto de habitaciones vienen de él.
*/

// Reparto de habitaciones para N personas, igual que el formulario de la web
// (mu-plugin cg-2026-habitaciones): de más dobles a todo individuales, sin triples.
const fmt = (pair, n) => pair[n === 1 ? 0 : 1].replace("%d", n);
function roomOptions(n, t) {
  const out = [];
  if (!t || n < 2) return out;
  for (let d = Math.floor(n / 2); d >= 0; d--) {
    const s = n - 2 * d;
    const parts = [];
    if (d) parts.push(fmt(t.double, d));
    if (s) parts.push(fmt(t.single, s));
    out.push(parts.join(" + "));
  }
  return out;
}
function bedOptions(doubles, t) {
  if (!t || doubles < 1) return [];
  if (doubles === 1) return t.bed1.slice();
  const out = [];
  for (let m = doubles; m >= 0; m--) {
    const parts = [];
    if (m) parts.push(t.bedN[0].replace("%d", m));
    if (doubles - m) parts.push(t.bedN[1].replace("%d", doubles - m));
    out.push(parts.join(" + "));
  }
  return out;
}
const doublesIn = (value) => {
  const match = /^(\d+)\s+(doble|double)/u.exec(String(value || ""));
  return match ? Number(match[1]) : 0;
};

function isoTomorrow() {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

const ERROR_TEXTS = {
  too_many: () => tt("Ya hemos recibido tu petición. Te escribiremos en breve."),
  invalid_dates: () => tt("Revisa las fechas: la llegada tiene que ser futura y el regreso posterior a la llegada."),
  invalid_phone: () => tt("Indica un teléfono de contacto."),
  not_found: () => tt("Esta propuesta ya no está disponible."),
};

export default function RequestDialog({ proposalId = 0, mock = false, profile = null, readOnly = false, readOnlyMessage = "", onClose, onSubmitted }) {
  const dialogRef = useRef(null);
  const formId = useId();
  const [state, setState] = useState({ loading: true, error: null, form: null });
  const [values, setValues] = useState(null);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");
  const lang = String(window.CasanovaPortal?.currentLang || "es").slice(0, 2);
  const isRenewal = Number(proposalId) > 0;

  useEffect(() => {
    const dialog = dialogRef.current;
    if (dialog && !dialog.open) dialog.showModal();
  }, []);

  useEffect(() => {
    let alive = true;
    const params = new URLSearchParams({ lang });
    if (isRenewal) params.set("proposal_id", String(proposalId));
    if (mock) params.set("mock", "1");
    api(`/proposals/request-form?${params.toString()}`)
      .then((form) => {
        if (!alive) return;
        const p = form?.prefill || {};
        const giavPhone = String(profile?.giav?.movil || profile?.giav?.telefono || "").trim();
        setValues({
          destination: "",
          arrival: p.arrival || "",
          departure: p.departure || "",
          flexible: false,
          green_fees: p.green_fees || "",
          players: p.players || form?.options?.players?.[1] || form?.options?.players?.[0] || "",
          non_players: p.non_players || "0",
          rooms: p.rooms || "",
          beds: p.beds || "",
          phone: p.phone || giavPhone,
          comments: "",
        });
        setState({ loading: false, error: null, form });
      })
      .catch((e) => alive && setState({ loading: false, error: e, form: null }));
    return () => { alive = false; };
  }, [proposalId, mock, lang, isRenewal]); // eslint-disable-line react-hooks/exhaustive-deps

  // El perfil (teléfono de GIAV) puede llegar después de abrir el diálogo.
  useEffect(() => {
    const giavPhone = String(profile?.giav?.movil || profile?.giav?.telefono || "").trim();
    if (giavPhone) setValues((v) => (v && !v.phone ? { ...v, phone: giavPhone } : v));
  }, [profile]);

  const form = state.form;

  // Al cargar, el foco va al primer campo (no a la X de cerrar).
  useEffect(() => {
    if (!form) return;
    dialogRef.current?.querySelector(".cp-dialog__body input:not([type=checkbox]), .cp-dialog__body select")?.focus();
  }, [form]);
  const people = (parseInt(values?.players, 10) || 0) + (parseInt(values?.non_players, 10) || 0);
  const rooms = useMemo(() => roomOptions(people, form?.rooms_text), [people, form]);
  const beds = useMemo(
    () => (form?.has_beds && rooms.length ? bedOptions(doublesIn(values?.rooms), form?.rooms_text) : []),
    [rooms, values?.rooms, form],
  );

  // Si cambia el número de personas, el reparto elegido puede dejar de valer.
  useEffect(() => {
    if (!values) return;
    if (values.rooms && !rooms.includes(values.rooms)) setValues((v) => ({ ...v, rooms: "", beds: "" }));
  }, [rooms]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (!values) return;
    if (values.beds && !beds.includes(values.beds)) setValues((v) => ({ ...v, beds: "" }));
  }, [beds]); // eslint-disable-line react-hooks/exhaustive-deps

  const set = (key) => (event) => {
    const value = event.target.type === "checkbox" ? event.target.checked : event.target.value;
    setValues((v) => ({ ...v, [key]: value }));
  };

  function close() {
    dialogRef.current?.close();
  }

  async function submit(event) {
    event.preventDefault();
    if (sending || readOnly) return;
    setError("");
    if (values.departure && values.arrival && values.departure < values.arrival) {
      setError(ERROR_TEXTS.invalid_dates());
      return;
    }
    setSending(true);
    try {
      await api(`/proposals/request${mock ? "?mock=1" : ""}`, {
        method: "POST",
        body: { ...values, proposal_id: isRenewal ? Number(proposalId) : 0, lang },
      });
      onSubmitted?.({ renewal: isRenewal, agent: form?.proposal?.agent || null });
      close();
    } catch (e) {
      const known = ERROR_TEXTS[e?.code];
      setError(known ? known() : (e?.message || tt("No hemos podido enviar tu solicitud. Inténtalo de nuevo o escríbenos.")));
    } finally {
      setSending(false);
    }
  }

  const proposal = form?.proposal || null;
  const title = isRenewal ? tt("Actualizar tu propuesta") : tt("Nueva solicitud");
  const lead = isRenewal
    ? tt("Confírmanos tus datos y te prepararemos una propuesta nueva con precios actualizados.")
    : tt("Cuéntanos qué viaje tienes en mente y te prepararemos una propuesta.");
  const agentName = proposal?.agent?.name || "";
  const minDate = isoTomorrow();

  return (
    <dialog
      ref={dialogRef}
      className="cp-dialog"
      aria-labelledby={`${formId}-title`}
      onClose={() => onClose?.()}
      onClick={(event) => { if (event.target === dialogRef.current) close(); }}
    >
      <form className="cp-dialog__panel" onSubmit={submit}>
        <div className="cp-dialog__head">
          <div>
            <h2 className="cp-dialog__title" id={`${formId}-title`}>{title}</h2>
            <p className="cp-dialog__lead">{lead}</p>
          </div>
          <button type="button" className="cp-dialog__close" onClick={close} aria-label={tt("Cerrar")}>
            <Icon name="x" size={20} />
          </button>
        </div>

        <div className="cp-dialog__body">
          {state.loading ? <Skeleton lines={6} /> : null}
          {state.error ? (
            <Notice variant="warn" title={tt("No podemos abrir el formulario")}>
              {(ERROR_TEXTS[state.error?.code] || (() => tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")))()}
            </Notice>
          ) : null}

          {form && values ? (
            <>
              {proposal ? (
                <div className="cp-dialog__from">
                  {proposal.image ? <img src={proposal.image} alt="" /> : null}
                  <p>
                    <strong>{proposal.title}</strong>
                    {[tt("Propuesta original"), formatDateRange(proposal.start_date, proposal.end_date), travellersLabel(proposal.players, proposal.non_players)].filter(Boolean).join(" · ")}
                  </p>
                </div>
              ) : (
                <div className="cp-field">
                  <label className="cp-label" htmlFor={`${formId}-dest`}>{tt("¿Qué viaje tienes en mente?")}</label>
                  <input id={`${formId}-dest`} className="cp-input" type="text" required maxLength={200} value={values.destination} onChange={set("destination")} placeholder={tt("Destino, hotel o campos de golf")} />
                </div>
              )}

              <div className="cp-grid3">
                <div className="cp-field">
                  <label className="cp-label" htmlFor={`${formId}-in`}>{tt("Fecha de llegada")}</label>
                  <input id={`${formId}-in`} className="cp-input" type="date" required min={minDate} value={values.arrival} onChange={set("arrival")} />
                </div>
                <div className="cp-field">
                  <label className="cp-label" htmlFor={`${formId}-out`}>{tt("Fecha de regreso")}</label>
                  <input id={`${formId}-out`} className="cp-input" type="date" required min={values.arrival || minDate} value={values.departure} onChange={set("departure")} />
                </div>
                <div className="cp-field">
                  <label className="cp-label" htmlFor={`${formId}-gf`}>{tt("Green-fees por jugador")}</label>
                  <select id={`${formId}-gf`} className="cp-input" required value={values.green_fees} onChange={set("green_fees")}>
                    <option value="" disabled>{tt("Elige")}</option>
                    {(form.options?.green_fees || []).map((o) => <option key={o} value={o}>{o}</option>)}
                  </select>
                </div>
              </div>
              <label className="cp-check">
                <input type="checkbox" checked={values.flexible} onChange={set("flexible")} />
                {tt("Mis fechas son flexibles (± unos días)")}
              </label>

              <div className="cp-grid2">
                <div className="cp-field">
                  <label className="cp-label" htmlFor={`${formId}-p`}>{tt("Jugadores")}</label>
                  <select id={`${formId}-p`} className="cp-input" required value={values.players} onChange={set("players")}>
                    {(form.options?.players || []).map((o) => <option key={o} value={o}>{o}</option>)}
                  </select>
                </div>
                <div className="cp-field">
                  <label className="cp-label" htmlFor={`${formId}-np`}>{tt("No jugadores")}</label>
                  <select id={`${formId}-np`} className="cp-input" value={values.non_players} onChange={set("non_players")}>
                    {(form.options?.non_players || []).map((o) => <option key={o} value={o}>{o}</option>)}
                  </select>
                </div>
              </div>

              {rooms.length ? (
                <div className="cp-grid2 cp-grid2--stack">
                  <div className="cp-field">
                    <label className="cp-label" htmlFor={`${formId}-rooms`}>{tt("Distribución de habitaciones")}</label>
                    <select id={`${formId}-rooms`} className="cp-input" required value={values.rooms} onChange={set("rooms")}>
                      <option value="" disabled>{form.rooms_text?.choose || tt("Elige")}</option>
                      {rooms.map((o) => <option key={o} value={o}>{o}</option>)}
                    </select>
                  </div>
                  {beds.length ? (
                    <div className="cp-field">
                      <label className="cp-label" htmlFor={`${formId}-beds`}>{tt("Camas en las habitaciones dobles")}</label>
                      <select id={`${formId}-beds`} className="cp-input" required value={values.beds} onChange={set("beds")}>
                        <option value="" disabled>{form.rooms_text?.choose || tt("Elige")}</option>
                        {beds.map((o) => <option key={o} value={o}>{o}</option>)}
                      </select>
                    </div>
                  ) : null}
                </div>
              ) : null}

              <div className="cp-field">
                <label className="cp-label" htmlFor={`${formId}-tel`}>{tt("Teléfono de contacto")}</label>
                <input id={`${formId}-tel`} className="cp-input" type="tel" required autoComplete="tel" value={values.phone} onChange={set("phone")} />
              </div>

              <div className="cp-field">
                <label className="cp-label" htmlFor={`${formId}-c`}>
                  {isRenewal ? tt("¿Quieres cambiar algo más?") : tt("Cuéntanos algo más")} <span className="cp-label__opt">{tt("(opcional)")}</span>
                </label>
                <textarea id={`${formId}-c`} className="cp-input" maxLength={2000} rows={3} value={values.comments} onChange={set("comments")} placeholder={tt("Otro hotel, más rondas, un día más…")} />
              </div>

              {readOnly ? <Notice variant="info" title={tt("Solo lectura")}>{readOnlyMessage}</Notice> : null}
              {error ? <p className="cp-dialog__error" role="alert">{error}</p> : null}
            </>
          ) : null}
        </div>

        <div className="cp-dialog__foot">
          <button type="button" className="cp-btn cp-btn--ghost" onClick={close}>{tt("Cancelar")}</button>
          <button type="submit" className="cp-btn cp-btn--primary" disabled={!form || sending || readOnly}>
            {sending ? tt("Enviando...") : (isRenewal && agentName ? `${tt("Enviar a")} ${agentName}` : tt("Enviar solicitud"))}
          </button>
        </div>
      </form>
    </dialog>
  );
}
