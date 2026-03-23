import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";

import { EmptyState, Notice, Skeleton } from "./ui.jsx";
import { tt } from "../i18n/t.js";
import { api } from "../lib/api.js";
import { formatMsgDate, formatDateES } from "../lib/formatters.js";

const MAX_ATTACHMENTS = 3;
const ACCEPTED_ATTACHMENT_TYPES = ".pdf,image/jpeg,image/png,image/webp";

function formatFileSize(size) {
  const bytes = Number(size) || 0;
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${bytes} B`;
}

function statusClass(status) {
  if (!status) return "";
  const s = String(status).toLowerCase();
  if (s === "confirmado" || s === "confirmed") return "is-confirmed";
  return "is-in-progress";
}

function openTrip(expedienteId) {
  const params = new URLSearchParams(window.location.search);
  params.set("view", "trip");
  params.set("expediente", String(expedienteId));
  window.history.pushState({}, "", `${window.location.pathname}?${params.toString()}`);
  window.dispatchEvent(new PopStateEvent("popstate"));
}

/* ─── Date separator logic ─── */
function dateLabelFromISO(dateStr) {
  if (!dateStr) return null;
  const match = String(dateStr).match(/(\d{4})-(\d{2})-(\d{2})/);
  if (!match) return null;
  const d = new Date(Date.UTC(+match[1], +match[2] - 1, +match[3]));
  if (isNaN(d.getTime())) return null;
  return d.toLocaleDateString("es-ES", { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" });
}

/* ─── SVG icons (inline, tiny) ─── */
function IconSearch() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <circle cx="11" cy="11" r="8" /><path d="M21 21l-4.35-4.35" />
    </svg>
  );
}
function IconSend() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" />
    </svg>
  );
}
function IconAttach() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
      <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
    </svg>
  );
}
function IconExternalLink() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
      <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
      <path d="M15 3h6v6" /><path d="M10 14L21 3" />
    </svg>
  );
}
function IconChat() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
    </svg>
  );
}

/* ═══════════════════════════════════════
   Conversation panel (right side)
   ═══════════════════════════════════════ */
function ConversationPanel({ thread, mock }) {
  const [state, setState] = useState({ loading: true, error: null, data: null });
  const [draft, setDraft] = useState("");
  const [files, setFiles] = useState([]);
  const [sendError, setSendError] = useState("");
  const [sending, setSending] = useState(false);
  const fileInputRef = useRef(null);
  const bodyRef = useRef(null);
  const textareaRef = useRef(null);

  const expediente = thread?.expediente_id;

  /* load messages */
  const loadMessages = useCallback(async () => {
    if (!expediente) return;
    try {
      setState((c) => ({ ...c, loading: true, error: null }));
      const params = new URLSearchParams();
      if (mock) params.set("mock", "1");
      params.set("expediente", String(expediente));
      params.set("mark_seen", "1");
      const data = await api(`/messages?${params.toString()}`);
      setState({ loading: false, error: null, data });
    } catch (error) {
      setState({ loading: false, error, data: null });
    }
  }, [expediente, mock]);

  useEffect(() => {
    setDraft("");
    setFiles([]);
    setSendError("");
    setState({ loading: true, error: null, data: null });
    if (fileInputRef.current) fileInputRef.current.value = "";
    loadMessages();
  }, [expediente, loadMessages]);

  /* scroll to bottom when messages loaded */
  useEffect(() => {
    if (bodyRef.current) {
      bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
    }
  }, [state.data]);

  /* auto-resize textarea */
  function handleTextareaInput(e) {
    setDraft(e.target.value);
    const ta = e.target;
    ta.style.height = "auto";
    ta.style.height = Math.min(ta.scrollHeight, 120) + "px";
  }

  function handleFilesChange(event) {
    const selected = Array.from(event.target.files || []);
    const merged = [...files, ...selected];
    const unique = merged.filter((f, i, list) => {
      const key = `${f.name}:${f.size}:${f.lastModified}`;
      return i === list.findIndex((c) => `${c.name}:${c.size}:${c.lastModified}` === key);
    });
    if (unique.length > MAX_ATTACHMENTS) {
      setSendError(tt("Puedes adjuntar como máximo 3 archivos por mensaje."));
    } else {
      setSendError("");
    }
    setFiles(unique.slice(0, MAX_ATTACHMENTS));
    if (fileInputRef.current) fileInputRef.current.value = "";
  }

  function removeFile(idx) {
    setSendError("");
    setFiles((c) => c.filter((_, i) => i !== idx));
  }

  async function handleSend(e) {
    e.preventDefault();
    const body = draft.trim();
    if ((body === "" && files.length === 0) || sending) return;
    try {
      setSending(true);
      setSendError("");
      const formData = new FormData();
      formData.append("expediente", String(Number(expediente)));
      formData.append("body", body);
      files.forEach((f) => formData.append("attachments[]", f));
      await api("/messages", { method: "POST", body: formData });
      setDraft("");
      setFiles([]);
      if (fileInputRef.current) fileInputRef.current.value = "";
      if (textareaRef.current) {
        textareaRef.current.style.height = "auto";
      }
      await loadMessages();
    } catch (error) {
      setSendError(error?.message || tt("No se pudo enviar el mensaje."));
    } finally {
      setSending(false);
    }
  }

  const items = Array.isArray(state.data?.items) ? state.data.items : [];

  /* group messages by date for separators */
  const messagesWithSeps = useMemo(() => {
    const out = [];
    let lastDateLabel = null;
    for (const msg of items) {
      const label = dateLabelFromISO(msg.date);
      if (label && label !== lastDateLabel) {
        out.push({ type: "sep", label });
        lastDateLabel = label;
      }
      out.push({ type: "msg", data: msg });
    }
    return out;
  }, [items]);

  const dateRange =
    thread?.date_start || thread?.date_end
      ? `${formatDateES(thread.date_start)} — ${formatDateES(thread.date_end)}`
      : null;

  return (
    <div className="cp-conv">
      {/* Header */}
      <div className="cp-conv-header">
        <div className="cp-conv-header__left">
          <h3>{thread.trip_title || tt("Viaje")}</h3>
          <div className="cp-conv-header__meta">
            {thread.trip_code ? (
              <span className="cp-conv-header__ref">{thread.trip_code}</span>
            ) : null}
            {thread.trip_status ? (
              <span
                className={`cp-conv-header__status ${statusClass(thread.trip_status)}`}
                style={
                  statusClass(thread.trip_status) === "is-confirmed"
                    ? { background: "var(--accent-light)", color: "var(--accent)" }
                    : { background: "var(--gold-light)", color: "var(--gold)" }
                }
              >
                {thread.trip_status}
              </span>
            ) : null}
            {dateRange ? <span>{dateRange}</span> : null}
          </div>
        </div>
        <div className="cp-conv-header__actions">
          <button title={tt("Ver viaje")} onClick={() => openTrip(thread.expediente_id)}>
            <IconExternalLink />
          </button>
        </div>
      </div>

      {/* Body */}
      {state.loading ? (
        <div style={{ padding: 24 }}>
          <Skeleton lines={5} />
        </div>
      ) : state.error ? (
        <div style={{ padding: 24 }}>
          <Notice variant="error" title={tt("Error")}>
            {tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")}
          </Notice>
        </div>
      ) : items.length === 0 ? (
        <div className="cp-conv-empty">
          <IconChat />
          <p>
            {tt("No hay mensajes en esta conversación todavía.")}
            <br />
            {tt("Inicia una conversación con el equipo de Casanova Golf.")}
          </p>
        </div>
      ) : (
        <div className="cp-conv-body" ref={bodyRef}>
          {messagesWithSeps.map((entry, i) => {
            if (entry.type === "sep") {
              return (
                <div key={`sep-${i}`} className="cp-date-sep">
                  <span>{entry.label}</span>
                </div>
              );
            }
            const msg = entry.data;
            const isMe = msg.direction === "client";
            const fallbackAuthor = isMe ? tt("Tú") : "Casanova Golf";
            const initials = isMe ? "TÚ" : "CG";
            const attachments = Array.isArray(msg.attachments) ? msg.attachments : [];

            return (
              <div key={msg.id} className={`cp-bubble ${isMe ? "is-me" : "is-them"}`}>
                <div className="cp-bubble__avatar">{initials}</div>
                <div className="cp-bubble__body">
                  {msg.content || ""}
                  {attachments.length > 0 && (
                    <div className="cp-bubble__attachments">
                      {attachments.map((att) => (
                        <a
                          key={`${msg.id}:${att.id}`}
                          className="cp-bubble__attachment"
                          href={att.downloadUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          {att.name || tt("Adjunto")}
                        </a>
                      ))}
                    </div>
                  )}
                  <span className="cp-bubble__time">
                    {msg.author || fallbackAuthor} · {formatMsgDate(msg.date)}
                  </span>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* File chips */}
      {files.length > 0 && (
        <div className="cp-conv-compose__files">
          {files.map((f, i) => (
            <div key={`${f.name}-${i}`} className="cp-conv-compose__file-chip">
              <span>{f.name} · {formatFileSize(f.size)}</span>
              <button type="button" onClick={() => removeFile(i)} disabled={sending}>×</button>
            </div>
          ))}
        </div>
      )}

      {/* Send error */}
      {sendError ? <div className="cp-conv-compose__error">{sendError}</div> : null}

      {/* Compose bar */}
      <form className="cp-conv-compose" onSubmit={handleSend}>
        <input
          ref={fileInputRef}
          type="file"
          multiple
          accept={ACCEPTED_ATTACHMENT_TYPES}
          onChange={handleFilesChange}
          className="cp-conv-compose__file-input"
          tabIndex={-1}
        />
        <textarea
          ref={textareaRef}
          className="cp-conv-compose__input"
          placeholder={items.length === 0 ? tt("Escribe el primer mensaje...") : tt("Escribe un mensaje...")}
          rows="1"
          value={draft}
          onChange={handleTextareaInput}
          disabled={sending}
          maxLength={4000}
        />
        <div className="cp-conv-compose__actions">
          <button
            type="button"
            className="cp-conv-compose__btn-attach"
            title={tt("Adjuntar archivo")}
            onClick={() => fileInputRef.current?.click()}
            disabled={sending}
          >
            <IconAttach />
          </button>
          <button
            type="submit"
            className="cp-conv-compose__btn-send"
            disabled={sending || (draft.trim() === "" && files.length === 0)}
          >
            {sending ? tt("Enviando...") : tt("Enviar")}
            <IconSend />
          </button>
        </div>
      </form>
    </div>
  );
}

/* ═══════════════════════════════════════
   InboxView — split-panel chat layout
   ═══════════════════════════════════════ */
export default function InboxView({ mock, inbox, loading, error, onLatestTs, onSeen }) {
  const items = Array.isArray(inbox?.items) ? inbox.items : [];
  const [activeId, setActiveId] = useState(null);
  const [search, setSearch] = useState("");

  const sorted = useMemo(() => {
    return items
      .slice()
      .sort((a, b) => {
        const ta = a?.last_message_at ? new Date(a.last_message_at).getTime() : 0;
        const tb = b?.last_message_at ? new Date(b.last_message_at).getTime() : 0;
        return tb - ta;
      });
  }, [items]);

  const filtered = useMemo(() => {
    if (!search.trim()) return sorted;
    const q = search.toLowerCase();
    return sorted.filter(
      (it) =>
        (it.trip_title || "").toLowerCase().includes(q) ||
        (it.trip_code || "").toLowerCase().includes(q) ||
        (it.content || "").toLowerCase().includes(q)
    );
  }, [sorted, search]);

  /* auto-select first thread */
  useEffect(() => {
    if (activeId) return;
    if (sorted.length > 0) {
      setActiveId(sorted[0].expediente_id);
    }
  }, [sorted, activeId]);

  useEffect(() => {
    if (!sorted.length) return;
    const latest = sorted.reduce((max, item) => {
      const ts = item?.last_message_at ? new Date(item.last_message_at).getTime() : 0;
      return ts > max ? ts : max;
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

  const activeThread = sorted.find((it) => it.expediente_id === activeId) || null;

  return (
    <div className="cp-msg-layout">
      {/* ─── Inbox (left panel) ─── */}
      <div className="cp-inbox">
        <div className="cp-inbox-header">
          <h3>
            {tt("Bandeja")}{" "}
            <span className="cp-inbox-count">{sorted.length}</span>
          </h3>
        </div>

        <div className="cp-inbox-search">
          <div className="cp-inbox-search__box">
            <IconSearch />
            <input
              type="text"
              placeholder={tt("Buscar conversación...")}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>

        <div className="cp-inbox-list">
          {filtered.map((item) => {
            const isActive = item.expediente_id === activeId;
            const isUnread = typeof item.unread === "number" && item.unread > 0;
            let cls = "cp-inbox-item";
            if (isActive) cls += " is-active";
            if (isUnread) cls += " is-unread";

            return (
              <button
                key={String(item.expediente_id)}
                className={cls}
                onClick={() => setActiveId(item.expediente_id)}
              >
                <div className="cp-inbox-item__avatar">CG</div>
                <div className="cp-inbox-item__content">
                  <div className="cp-inbox-item__top">
                    <span className="cp-inbox-item__name">
                      {item.trip_title || tt("Viaje")}
                    </span>
                    <span className="cp-inbox-item__date">
                      {item.last_message_at ? formatMsgDate(item.last_message_at) : "—"}
                    </span>
                  </div>
                  <div className="cp-inbox-item__meta">
                    {item.trip_code ? (
                      <span className="cp-inbox-item__ref">{item.trip_code}</span>
                    ) : null}
                    {item.trip_status ? (
                      <span className={`cp-inbox-item__status ${statusClass(item.trip_status)}`}>
                        {item.trip_status}
                      </span>
                    ) : null}
                  </div>
                  <p className="cp-inbox-item__preview">
                    {item.content || tt("Sin mensajes")}
                  </p>
                </div>
              </button>
            );
          })}
        </div>
      </div>

      {/* ─── Conversation (right panel) ─── */}
      {activeThread ? (
        <ConversationPanel key={activeThread.expediente_id} thread={activeThread} mock={mock} />
      ) : (
        <div className="cp-conv-placeholder">
          <IconChat />
          <p>{tt("Selecciona una conversación para ver los mensajes.")}</p>
        </div>
      )}
    </div>
  );
}
