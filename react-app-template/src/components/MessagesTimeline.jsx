import React, { useEffect, useState } from "react";

import { EmptyState, Skeleton } from "./ui.jsx";
import { tt } from "../i18n/t.js";
import { api } from "../lib/api.js";
import { formatMsgDate } from "../lib/formatters.js";

export default function MessagesTimeline({ expediente, mock, onLatestTs, onSeen }) {
  const [state, setState] = useState({ loading: true, error: null, data: null });

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        setState({ loading: true, error: null, data: null });
        const params = new URLSearchParams();
        if (mock) params.set("mock", "1");
        params.set("expediente", String(expediente));
        const query = `?${params.toString()}`;
        const data = await api(`/messages${query}`);
        if (!alive) return;
        setState({ loading: false, error: null, data });
      } catch (error) {
        if (!alive) return;
        setState({ loading: false, error, data: null });
      }
    })();
    return () => {
      alive = false;
    };
  }, [expediente, mock]);

  const items = Array.isArray(state.data?.items) ? state.data.items : [];
  const latestTs = items.length
    ? Math.max(
        ...items
          .map((item) => new Date(item?.date || 0).getTime())
          .filter((value) => Number.isFinite(value))
      )
    : 0;

  useEffect(() => {
    if (latestTs && typeof onLatestTs === "function") onLatestTs(latestTs);
  }, [latestTs, onLatestTs]);

  useEffect(() => {
    if (typeof onSeen === "function") onSeen();
  }, [expediente, onSeen]);

  if (state.loading) {
    return (
      <div className="cp-card">
        <div className="cp-card-title">{tt("Cargando mensajes")}</div>
        <Skeleton lines={6} />
      </div>
    );
  }

  if (state.error) {
    return (
      <div className="cp-notice is-warn">
        {tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")}
      </div>
    );
  }

  if (items.length === 0) {
    return (
      <EmptyState title={tt("No hay mensajes disponibles")} icon="💬">
        {tt("Si te escribimos, lo verás aquí al momento.")}
      </EmptyState>
    );
  }

  return (
    <div className="cp-timeline cp-mt-14">
      {items.map((message) => (
        <div key={message.id} className="cp-msg">
          <div className="cp-msg-head">
            <div className="cp-msg-author">
              <span className="cp-dot" />
              <span>{message.author || (message.direction === "agency" ? "Casanova Golf" : "Tú")}</span>
            </div>
            <div>{formatMsgDate(message.date)}</div>
          </div>
          <div className="cp-msg-body">{message.content || ""}</div>
        </div>
      ))}
    </div>
  );
}
