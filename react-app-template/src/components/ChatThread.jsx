import React, { useEffect, useId, useMemo, useRef, useState } from "react";

import Icon from "./Icon.jsx";
import { Notice, Skeleton } from "./ui.jsx";
import { tt } from "../i18n/t.js";
import { api } from "../lib/api.js";
import { formatMsgDate } from "../lib/formatters.js";

const MAX_ATTACHMENTS = 3;
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
  const locale = window.CASANOVA_I18N_META?.locale || document.documentElement.lang || "es-ES";
  return d.toLocaleDateString(locale, { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" });
}

/**
 * Conversación de un viaje con el equipo: carga, lista de mensajes y redacción.
 * La usan la bandeja de Mensajes y la pestaña «Mensajes» de la ficha.
 */
export default function ChatThread({
  expediente,
  mock = false,
  onSeen,
  readOnly = false,
  readOnlyMessage = "",
  header = null,
  emptyText = "",
  placeholder = "",
}) {
  const [state, setState] = useState({ loading: true, error: null, data: null });
  const [draft, setDraft] = useState("");
  const [files, setFiles] = useState([]);
  const [sendError, setSendError] = useState("");
  const [sending, setSending] = useState(false);
  const onSeenRef = useRef(onSeen);
  const fileInputRef = useRef(null);
  const listRef = useRef(null);
  const textareaRef = useRef(null);
  const hintId = useId();

  useEffect(() => {
    onSeenRef.current = onSeen;
  }, [onSeen]);

  async function fetchMessages() {
    const params = new URLSearchParams();
    if (mock) params.set("mock", "1");
    params.set("expediente", String(expediente));
    if (!readOnly) params.set("mark_seen", "1");
    return api(`/messages?${params.toString()}`);
  }

  useEffect(() => {
    let alive = true;
    setDraft("");
    setFiles([]);
    setSendError("");
    if (fileInputRef.current) fileInputRef.current.value = "";
    setState({ loading: true, error: null, data: null });

    fetchMessages()
      .then((data) => {
        if (!alive) return;
        setState({ loading: false, error: null, data });
        if (!readOnly) onSeenRef.current?.(data);
      })
      .catch((error) => {
        if (alive) setState({ loading: false, error, data: null });
      });

    return () => { alive = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [expediente, mock, readOnly]);

  // Al cargar o enviar, la conversación baja al último mensaje.
  useEffect(() => {
    if (listRef.current) listRef.current.scrollTop = listRef.current.scrollHeight;
  }, [state.data]);

  function handleTextareaInput(event) {
    setDraft(event.target.value);
    const ta = event.target;
    ta.style.height = "auto";
    ta.style.height = `${Math.min(ta.scrollHeight, 160)}px`;
  }

  function handleFilesChange(event) {
    const selected = Array.from(event.target.files || []);
    const merged = [...files, ...selected];
    const unique = merged.filter((file, index, list) => {
      const key = `${file.name}:${file.size}:${file.lastModified}`;
      return index === list.findIndex((c) => `${c.name}:${c.size}:${c.lastModified}` === key);
    });
    setSendError(unique.length > MAX_ATTACHMENTS ? tt("Puedes adjuntar como máximo 3 archivos por mensaje.") : "");
    setFiles(unique.slice(0, MAX_ATTACHMENTS));
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

      const data = await fetchMessages();
      setState({ loading: false, error: null, data });
      if (!readOnly) onSeenRef.current?.(data);
    } catch (error) {
      setSendError(error?.message || tt("No se pudo enviar el mensaje."));
    } finally {
      setSending(false);
    }
  }

  // Enter envía; Mayús + Enter hace salto de línea.
  function handleKeyDown(event) {
    if (event.key === "Enter" && !event.shiftKey && !event.nativeEvent.isComposing) {
      event.preventDefault();
      handleSubmit(event);
    }
  }

  const items = Array.isArray(state.data?.items) ? state.data.items : [];

  const entries = useMemo(() => {
    const out = [];
    let lastLabel = null;
    items.forEach((msg) => {
      const label = dateLabelFromISO(msg.date);
      if (label && label !== lastLabel) {
        out.push({ type: "sep", label });
        lastLabel = label;
      }
      out.push({ type: "msg", data: msg });
    });
    return out;
  }, [items]);

  const charsLeft = MAX_CHARS - draft.length;
  const canSend = !sending && (draft.trim() !== "" || files.length > 0);

  return (
    <div className="cp-chat">
      {header ? <div className="cp-chat__header">{header}</div> : null}

      {state.loading ? (
        <div className="cp-chat__state" aria-busy="true"><Skeleton lines={5} /></div>
      ) : state.error ? (
        <div className="cp-chat__state">
          <Notice variant="error" title={tt("No se pueden cargar los mensajes")}>
            {tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")}
          </Notice>
        </div>
      ) : items.length === 0 ? (
        <div className="cp-chat__empty">
          <span className="cp-chat__empty-icon"><Icon name="message" size={24} /></span>
          <p>{emptyText || tt("Inicia una conversación con el equipo de Casanova Golf.")}</p>
        </div>
      ) : (
        <ol className="cp-chat__list" ref={listRef} aria-live="polite">
          {entries.map((entry, index) => {
            if (entry.type === "sep") {
              return (
                <li key={`sep-${index}`} className="cp-chat__sep"><span>{entry.label}</span></li>
              );
            }
            const msg = entry.data;
            const isMe = msg.direction === "client";
            const attachments = Array.isArray(msg.attachments) ? msg.attachments : [];
            return (
              <li key={msg.id} className={`cp-msg ${isMe ? "is-me" : "is-team"}`}>
                {!isMe ? <span className="cp-msg__avatar" aria-hidden="true">CG</span> : null}
                <div className="cp-msg__bubble">
                  {!isMe ? <span className="cp-msg__author">{msg.author || "Casanova Golf"}</span> : null}
                  {msg.content ? <p className="cp-msg__text">{msg.content}</p> : null}
                  {attachments.length > 0 ? (
                    <div className="cp-msg__files">
                      {attachments.map((att) => (
                        <a
                          key={`${msg.id}:${att.id}`}
                          className="cp-msg__file"
                          href={att.downloadUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          <Icon name="file" size={16} />
                          <span>{att.name || tt("Adjunto")}{att.sizeLabel ? ` · ${att.sizeLabel}` : ""}</span>
                        </a>
                      ))}
                    </div>
                  ) : null}
                  <time className="cp-msg__time">{formatMsgDate(msg.date)}</time>
                </div>
              </li>
            );
          })}
        </ol>
      )}

      {readOnly ? (
        <div className="cp-chat__state">
          <Notice variant="info" title={tt("Solo lectura")}>
            {readOnlyMessage || tt("Modo de vista cliente activo. Solo lectura.")}
          </Notice>
        </div>
      ) : !state.error ? (
        <form className="cp-chat__compose" onSubmit={handleSubmit}>
          {files.length > 0 ? (
            <ul className="cp-chat__attached">
              {files.map((file, index) => (
                <li key={`${file.name}-${file.size}-${index}`}>
                  <Icon name="file" size={16} />
                  <span>{file.name} · {formatFileSize(file.size)}</span>
                  <button type="button" onClick={() => removeFile(index)} disabled={sending} aria-label={`${tt("Cerrar")}: ${file.name}`}>
                    <Icon name="x" size={16} />
                  </button>
                </li>
              ))}
            </ul>
          ) : null}

          {sendError ? <p className="cp-chat__error" role="alert">{sendError}</p> : null}

          <div className="cp-chat__row">
            <input
              ref={fileInputRef}
              type="file"
              multiple
              accept={ACCEPTED_ATTACHMENT_TYPES}
              onChange={handleFilesChange}
              className="cp-sr-only"
              tabIndex={-1}
              aria-hidden="true"
            />
            <button
              type="button"
              className="cp-chat__icon-btn"
              onClick={() => fileInputRef.current?.click()}
              disabled={sending || files.length >= MAX_ATTACHMENTS}
              aria-label={tt("Adjuntar archivo")}
              title={tt("Máx. 3 archivos · 5 MB · PDF, JPG, PNG o WEBP")}
            >
              <Icon name="paperclip" size={20} />
            </button>
            <label className="cp-sr-only" htmlFor={`${hintId}-text`}>{tt("Escribe un mensaje...")}</label>
            <textarea
              id={`${hintId}-text`}
              ref={textareaRef}
              className="cp-chat__input"
              placeholder={placeholder || (items.length === 0 ? tt("Escribe el primer mensaje...") : tt("Escribe un mensaje..."))}
              rows={1}
              value={draft}
              onChange={handleTextareaInput}
              onKeyDown={handleKeyDown}
              disabled={sending}
              maxLength={MAX_CHARS}
              aria-describedby={charsLeft < 500 ? `${hintId}-count` : undefined}
            />
            <button type="submit" className="cp-chat__send" disabled={!canSend} aria-label={tt("Enviar")}>
              {sending ? <Icon name="refresh" size={20} className="is-spinning" /> : <Icon name="send" size={20} />}
              <span className="cp-chat__send-label">{sending ? tt("Enviando...") : tt("Enviar")}</span>
            </button>
          </div>
          {charsLeft < 500 ? (
            <p className={`cp-chat__count ${charsLeft < 100 ? "is-low" : ""}`.trim()} id={`${hintId}-count`}>
              {draft.length} / {MAX_CHARS}
            </p>
          ) : null}
        </form>
      ) : null}
    </div>
  );
}
