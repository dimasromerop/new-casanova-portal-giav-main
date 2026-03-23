import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";

import { Notice, Skeleton } from "./ui.jsx";
import { tt } from "../i18n/t.js";
import { api } from "../lib/api.js";
import { formatMsgDate } from "../lib/formatters.js";

const MAX_ATTACHMENTS = 3;
const MAX_ATTACHMENT_SIZE_MB = 5;
const ACCEPTED_ATTACHMENT_TYPES = ".pdf,image/jpeg,image/png,image/webp";
const MAX_CHARS = 4000;

function formatFileSize(size) {
  const bytes = Number(size) || 0;
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${bytes} B`;
}

function dateLabelFromISO(dateStr) {
  if (!dateStr) return null;
  const match = String(dateStr).match(/(\d{4})-(\d{2})-(\d{2})/);
  if (!match) return null;
  const d = new Date(Date.UTC(+match[1], +match[2] - 1, +match[3]));
  if (isNaN(d.getTime())) return null;
  return d.toLocaleDateString("es-ES", { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" });
}

/* ─── SVG icons ─── */
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
function IconPhone() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
      <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z" />
    </svg>
  );
}
function IconMail() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
      <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
      <path d="M22 6l-10 7L2 6" />
    </svg>
  );
}
function IconFile() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <path d="M14 2v6h6" />
    </svg>
  );
}
function IconX() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M18 6L6 18M6 6l12 12" />
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

export default function MessagesTimeline({
  expediente,
  mock,
  onSeen,
  readOnly = false,
  readOnlyMessage = "",
}) {
  const [state, setState] = useState({ loading: true, error: null, data: null });
  const [draft, setDraft] = useState("");
  const [files, setFiles] = useState([]);
  const [sendError, setSendError] = useState("");
  const [sending, setSending] = useState(false);
  const onSeenRef = useRef(onSeen);
  const fileInputRef = useRef(null);
  const messagesRef = useRef(null);
  const textareaRef = useRef(null);

  useEffect(() => {
    onSeenRef.current = onSeen;
  }, [onSeen]);

  useEffect(() => {
    setDraft("");
    setFiles([]);
    setSendError("");
    if (fileInputRef.current) fileInputRef.current.value = "";
  }, [expediente]);

  const loadMessages = useCallback(async ({ markSeen = true } = {}) => {
    try {
      setState((c) => ({ ...c, loading: true, error: null }));
      const params = new URLSearchParams();
      if (mock) params.set("mock", "1");
      params.set("expediente", String(expediente));
      if (markSeen && !readOnly) params.set("mark_seen", "1");

      const data = await api(`/messages?${params.toString()}`);
      setState({ loading: false, error: null, data });

      if (!readOnly) {
        onSeenRef.current?.(data);
      }
    } catch (error) {
      setState({ loading: false, error, data: null });
    }
  }, [expediente, mock, readOnly]);

  useEffect(() => {
    let alive = true;

    (async () => {
      try {
        const params = new URLSearchParams();
        if (mock) params.set("mock", "1");
        params.set("expediente", String(expediente));
        if (!readOnly) params.set("mark_seen", "1");

        setState({ loading: true, error: null, data: null });
        const data = await api(`/messages?${params.toString()}`);
        if (!alive) return;
        setState({ loading: false, error: null, data });
        if (!readOnly) {
          onSeenRef.current?.(data);
        }
      } catch (error) {
        if (!alive) return;
        setState({ loading: false, error, data: null });
      }
    })();

    return () => { alive = false; };
  }, [expediente, mock, readOnly]);

  /* scroll to bottom */
  useEffect(() => {
    if (messagesRef.current) {
      messagesRef.current.scrollTop = messagesRef.current.scrollHeight;
    }
  }, [state.data]);

  /* auto-resize textarea */
  function handleTextareaInput(e) {
    setDraft(e.target.value);
    const ta = e.target;
    ta.style.height = "auto";
    ta.style.height = Math.min(ta.scrollHeight, 140) + "px";
  }

  function handleFilesChange(event) {
    const selectedFiles = Array.from(event.target.files || []);
    const mergedFiles = [...files, ...selectedFiles];
    const uniqueFiles = mergedFiles.filter((file, index, list) => {
      const key = `${file.name}:${file.size}:${file.lastModified}`;
      return index === list.findIndex((c) => `${c.name}:${c.size}:${c.lastModified}` === key);
    });

    if (uniqueFiles.length > MAX_ATTACHMENTS) {
      setSendError(tt("Puedes adjuntar como máximo 3 archivos por mensaje."));
    } else {
      setSendError("");
    }

    setFiles(uniqueFiles.slice(0, MAX_ATTACHMENTS));
    if (fileInputRef.current) fileInputRef.current.value = "";
  }

  function removeFile(indexToRemove) {
    setSendError("");
    setFiles((current) => current.filter((_, index) => index !== indexToRemove));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    const body = draft.trim();
    if ((body === "" && files.length === 0) || sending) return;

    try {
      setSending(true);
      setSendError("");

      const formData = new FormData();
      formData.append("expediente", String(Number(expediente)));
      formData.append("body", body);
      files.forEach((file) => formData.append("attachments[]", file));

      await api("/messages", { method: "POST", body: formData });

      setDraft("");
      setFiles([]);
      if (fileInputRef.current) fileInputRef.current.value = "";
      if (textareaRef.current) textareaRef.current.style.height = "auto";

      await loadMessages({ markSeen: true });
    } catch (error) {
      setSendError(error?.message || tt("No se pudo enviar el mensaje."));
    } finally {
      setSending(false);
    }
  }

  /* Enter to send (shift+enter for newline) */
  function handleKeyDown(e) {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      handleSubmit(e);
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

  const charLen = draft.length;
  const charClass = charLen > 3600 ? " is-danger" : charLen > 3000 ? " is-warn" : "";

  if (state.loading) {
    return (
      <div className="cp-chat-panel">
        <div style={{ padding: 24 }}>
          <Skeleton lines={6} />
        </div>
      </div>
    );
  }

  if (state.error) {
    return (
      <div className="cp-chat-panel">
        <div style={{ padding: 24 }}>
          <Notice variant="error" title={tt("Error")}>
            {tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")}
          </Notice>
        </div>
      </div>
    );
  }

  return (
    <div className="cp-chat-panel">
      {readOnly ? (
        <div style={{ padding: "16px 24px 0" }}>
          <Notice variant="info" title={tt("Solo lectura")}>
            {readOnlyMessage || tt("Modo de vista cliente activo. Solo lectura.")}
          </Notice>
        </div>
      ) : null}

      {/* Chat header */}
      <div className="cp-chat-header">
        <div className="cp-chat-header__left">
          <div className="cp-chat-header__avatar">CG</div>
          <div className="cp-chat-header__info">
            <h4>Casanova Golf</h4>
            <p>{tt("Equipo de soporte · Responde en menos de 24h")}</p>
          </div>
        </div>
        <div className="cp-chat-header__right">
          <a href="tel:+34692525791" style={{ all: "unset" }}>
            <button type="button" title={tt("Llamar")}>
              <IconPhone />
            </button>
          </a>
          <a href="mailto:info@golfcasanova.com" style={{ all: "unset" }}>
            <button type="button" title={tt("Email")}>
              <IconMail />
            </button>
          </a>
        </div>
      </div>

      {/* Messages area */}
      {items.length === 0 ? (
        <div className="cp-chat-empty">
          <IconChat />
          <p>
            {tt("Todavía no hay mensajes")}
            <br />
            {tt("Si necesitas algo sobre este viaje, escríbenos desde aquí y seguiremos la conversación en el portal.")}
          </p>
        </div>
      ) : (
        <div className="cp-chat-messages" ref={messagesRef}>
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
                  <span className="cp-bubble__sender">{msg.author || fallbackAuthor}</span>
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
                          {att.sizeLabel ? ` · ${att.sizeLabel}` : ""}
                        </a>
                      ))}
                    </div>
                  )}
                  <span className="cp-bubble__time">{formatMsgDate(msg.date)}</span>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Compose area */}
      {!readOnly ? (
        <form className="cp-chat-compose" onSubmit={handleSubmit}>
          <div className="cp-chat-compose__row">
            <textarea
              ref={textareaRef}
              placeholder={tt("Escribe aquí tu mensaje sobre este viaje...")}
              rows="1"
              value={draft}
              onChange={handleTextareaInput}
              onKeyDown={handleKeyDown}
              disabled={sending}
              maxLength={MAX_CHARS}
            />
            <button
              type="submit"
              className="cp-chat-compose__send"
              disabled={sending || (draft.trim() === "" && files.length === 0)}
            >
              {sending ? tt("Enviando...") : tt("Enviar")}
              <IconSend />
            </button>
          </div>

          <div className="cp-chat-compose__bottom">
            <label className="cp-chat-compose__file-label">
              <IconAttach />
              {tt("Adjuntar archivo")}
              <input
                ref={fileInputRef}
                type="file"
                multiple
                accept={ACCEPTED_ATTACHMENT_TYPES}
                onChange={handleFilesChange}
                disabled={sending}
              />
            </label>
            <span className="cp-chat-compose__hint">
              {tt("Máx. 3 archivos · 5 MB · PDF, JPG, PNG o WEBP")}
            </span>
          </div>

          {files.length > 0 && (
            <div className="cp-chat-compose__attached">
              {files.map((file, index) => (
                <div key={`${file.name}-${file.size}-${index}`} className="cp-chat-compose__attached-file">
                  <IconFile />
                  {file.name.length > 24 ? file.name.slice(0, 21) + "..." : file.name}
                  <button
                    type="button"
                    className="cp-chat-compose__attached-remove"
                    onClick={() => removeFile(index)}
                    disabled={sending}
                  >
                    <IconX />
                  </button>
                </div>
              ))}
            </div>
          )}

          {sendError ? <div className="cp-chat-compose__error">{sendError}</div> : null}

          <div className={`cp-chat-compose__charcount${charClass}`}>
            {charLen.toLocaleString("es")} / 4.000
          </div>
        </form>
      ) : null}
    </div>
  );
}
