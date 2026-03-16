import React, { useEffect, useMemo } from "react";

import { EmptyState, Notice, Skeleton } from "./ui.jsx";
import { tt } from "../i18n/t.js";
import { formatMsgDate } from "../lib/formatters.js";

function openTripMessages(expedienteId) {
  const params = new URLSearchParams(window.location.search);
  params.set("view", "trip");
  params.set("expediente", String(expedienteId));
  params.set("tab", "messages");
  window.history.pushState({}, "", `${window.location.pathname}?${params.toString()}`);
  window.dispatchEvent(new PopStateEvent("popstate"));
}

export default function InboxView({ mock, inbox, loading, error, onLatestTs, onSeen }) {
  const items = Array.isArray(inbox?.items) ? inbox.items : [];

  const sorted = useMemo(() => {
    return items
      .slice()
      .sort((left, right) => {
        const leftTs = left?.last_message_at ? new Date(left.last_message_at).getTime() : 0;
        const rightTs = right?.last_message_at ? new Date(right.last_message_at).getTime() : 0;
        return rightTs - leftTs;
      });
  }, [items]);

  useEffect(() => {
    if (!sorted.length) return;
    const latest = sorted.reduce((max, item) => {
      const timestamp = item?.last_message_at ? new Date(item.last_message_at).getTime() : 0;
      return timestamp > max ? timestamp : max;
    }, 0);
    if (latest) onLatestTs?.(latest);
  }, [sorted, onLatestTs]);

  useEffect(() => {
    onSeen?.();
  }, [onSeen]);

  if (loading) {
    return (
      <div className="cp-card">
        <div className="cp-card-title">{tt("Mensajes")}</div>
        <Skeleton lines={6} />
      </div>
    );
  }

  if (error) {
    return (
      <div className="cp-card">
        <div className="cp-card-title">{tt("Mensajes")}</div>
        <Notice variant="error" title={tt("No se pueden cargar los mensajes")}>
          {tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")}
        </Notice>
      </div>
    );
  }

  const status = inbox?.status === "mock" || mock ? "mock" : "ok";

  if (!sorted.length) {
    return (
      <div className="cp-card">
        <div className="cp-card-title">{tt("Mensajes")}</div>
        <EmptyState title={tt("No hay mensajes nuevos")} icon="✅">
          {tt("Si te escribimos, lo verás aquí al momento.")}
        </EmptyState>
      </div>
    );
  }

  return (
    <div className="cp-card">
      <div className="cp-card-title">{tt("Mensajes")}</div>
      {status === "mock" ? <div className="cp-chip">{tt("Modo prueba")}</div> : null}

      <div className="cp-inbox-list">
        {sorted.map((item) => (
          <button
            key={String(item.expediente_id)}
            className="cp-inbox-item"
            onClick={() => openTripMessages(item.expediente_id)}
          >
            <div className="cp-inbox-left">
              <div className="cp-inbox-title">
                {item.trip_title || tt("Viaje")}{" "}
                <span className="cp-muted">
                  {item.trip_code ? `· ${item.trip_code}` : ""} {item.trip_status ? `· ${item.trip_status}` : ""}
                </span>
              </div>
              <div className="cp-inbox-snippet">{item.content || "Sin mensajes"}</div>
            </div>
            <div className="cp-inbox-right">
              <div className="cp-muted">{item.last_message_at ? formatMsgDate(item.last_message_at) : ""}</div>
              {typeof item.unread === "number" && item.unread > 0 ? <span className="cp-badge">{item.unread}</span> : null}
            </div>
          </button>
        ))}
      </div>
    </div>
  );
}
