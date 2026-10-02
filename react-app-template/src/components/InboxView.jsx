import React, { useEffect, useMemo, useState } from "react";

import ChatThread from "./ChatThread.jsx";
import Icon from "./Icon.jsx";
import { EmptyState, Notice, Skeleton } from "./ui.jsx";
import { tt } from "../i18n/t.js";
import { formatDateES, formatMsgDate } from "../lib/formatters.js";
import { setParams } from "../lib/params.js";

function statusVariant(status) {
  const s = String(status || "").toLowerCase();
  return s === "confirmado" || s === "confirmed" ? "success" : "warning";
}

// El hilo abierto vive en la URL (?thread=…): en móvil el botón «atrás» vuelve a la bandeja.
function threadFromUrl() {
  return new URLSearchParams(window.location.search).get("thread") || "";
}

function ThreadHeader({ thread, onBack }) {
  const dateRange = thread?.date_start || thread?.date_end
    ? `${formatDateES(thread.date_start)} — ${formatDateES(thread.date_end)}`
    : "";

  return (
    <div className="cp-thread-head">
      <button type="button" className="cp-thread-head__back" onClick={onBack} aria-label={tt("Bandeja")}>
        <Icon name="arrow-left" size={20} />
      </button>
      <div className="cp-thread-head__copy">
        <h2 className="cp-thread-head__title">{thread.trip_title || tt("Viaje")}</h2>
        <p className="cp-thread-head__meta">
          {thread.trip_code ? <span>{thread.trip_code}</span> : null}
          {thread.trip_status ? <span className={`cp-badge cp-badge--${statusVariant(thread.trip_status)}`}>{thread.trip_status}</span> : null}
          {dateRange ? <span className="cp-thread-head__dates">{dateRange}</span> : null}
        </p>
      </div>
      <button
        type="button"
        className="cp-btn cp-btn--ghost cp-btn--sm"
        onClick={() => setParams({ view: "trip", expediente: String(thread.expediente_id), tab: null, thread: null })}
      >
        {tt("Ver viaje")}
        <Icon name="arrow-right" size={16} />
      </button>
    </div>
  );
}

export default function InboxView({ mock, inbox, loading, error, onSeen, readOnly = false, readOnlyMessage = "" }) {
  const items = Array.isArray(inbox?.items) ? inbox.items : [];
  const [search, setSearch] = useState("");
  const [urlThread, setUrlThread] = useState(threadFromUrl);

  useEffect(() => {
    const onPop = () => setUrlThread(threadFromUrl());
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);

  const sorted = useMemo(() => {
    const time = (item) => (item?.last_message_at ? new Date(item.last_message_at).getTime() : 0);
    return items.slice().sort((a, b) => time(b) - time(a));
  }, [items]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return sorted;
    return sorted.filter((it) =>
      (it.trip_title || "").toLowerCase().includes(q)
      || (it.trip_code || "").toLowerCase().includes(q)
      || (it.content || "").toLowerCase().includes(q));
  }, [sorted, search]);

  if (loading) {
    return (
      <div className="cp-content">
        <div className="cp-card" aria-busy="true"><Skeleton lines={6} /></div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="cp-content">
        <Notice variant="error" title={tt("No se pueden cargar los mensajes")}>
          {tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")}
        </Notice>
      </div>
    );
  }

  if (!sorted.length) {
    return (
      <div className="cp-content">
        <EmptyState title={tt("No hay mensajes nuevos")} icon="mail-check">
          {tt("Si te escribimos, lo verás aquí al momento.")}
        </EmptyState>
      </div>
    );
  }

  // En escritorio, sin hilo en la URL se abre el más reciente; en móvil se ve la bandeja.
  const activeThread = sorted.find((it) => String(it.expediente_id) === urlThread) || sorted[0];
  const isThreadOpen = Boolean(urlThread);

  const openThread = (id) => setParams({ thread: String(id) }, { scrollTop: false });
  const closeThread = () => setParams({ thread: null }, { scrollTop: false });

  return (
    <div className="cp-content">
      <div className={`cp-inbox ${isThreadOpen ? "is-thread-open" : ""}`.trim()}>
        <aside className="cp-inbox__list" aria-label={tt("Bandeja")}>
          <div className="cp-inbox__head">
            <h2 className="cp-inbox__title">
              {tt("Bandeja")}
              <span className="cp-inbox__count">{sorted.length}</span>
            </h2>
            <label className="cp-trips-search cp-inbox__search">
              <span className="cp-sr-only">{tt("Buscar conversación...")}</span>
              <Icon name="search" size={18} />
              <input
                type="search"
                placeholder={tt("Buscar conversación...")}
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </label>
          </div>

          <ul className="cp-inbox__items">
            {filtered.map((item) => {
              const isActive = String(item.expediente_id) === String(activeThread?.expediente_id);
              const unread = typeof item.unread === "number" ? item.unread : 0;
              return (
                <li key={String(item.expediente_id)}>
                  <button
                    type="button"
                    className={`cp-inbox-item ${isActive ? "is-active" : ""} ${unread > 0 ? "is-unread" : ""}`.trim()}
                    aria-current={isActive ? "true" : undefined}
                    onClick={() => openThread(item.expediente_id)}
                  >
                    <span className="cp-inbox-item__top">
                      <span className="cp-inbox-item__name">{item.trip_title || tt("Viaje")}</span>
                      <span className="cp-inbox-item__date">{item.last_message_at ? formatMsgDate(item.last_message_at) : ""}</span>
                    </span>
                    <span className="cp-inbox-item__meta">
                      {item.trip_code ? <span>{item.trip_code}</span> : null}
                      {item.trip_status ? <span className={`cp-badge cp-badge--${statusVariant(item.trip_status)}`}>{item.trip_status}</span> : null}
                    </span>
                    <span className="cp-inbox-item__preview">
                      <span>{item.content || tt("Sin mensajes")}</span>
                      {unread > 0 ? <span className="cp-count">{unread}</span> : null}
                    </span>
                  </button>
                </li>
              );
            })}
          </ul>
        </aside>

        <section className="cp-inbox__thread">
          {activeThread ? (
            <ChatThread
              key={activeThread.expediente_id}
              expediente={activeThread.expediente_id}
              mock={mock}
              onSeen={onSeen}
              readOnly={readOnly}
              readOnlyMessage={readOnlyMessage}
              header={<ThreadHeader thread={activeThread} onBack={closeThread} />}
            />
          ) : null}
        </section>
      </div>
    </div>
  );
}
