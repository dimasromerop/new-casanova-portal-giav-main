import React, { useEffect, useRef, useState } from "react";

import { EmptyState, Notice, Skeleton } from "./ui.jsx";
import { tt } from "../i18n/t.js";
import { api } from "../lib/api.js";
import { formatMsgDate } from "../lib/formatters.js";

const MAX_ATTACHMENTS = 3;
const MAX_ATTACHMENT_SIZE_MB = 5;
const ACCEPTED_ATTACHMENT_TYPES = ".pdf,image/jpeg,image/png,image/webp";

function formatFileSize(size) {
  const bytes = Number(size) || 0;
  if (bytes >= 1024 * 1024) {
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }
  if (bytes >= 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }
  return `${bytes} B`;
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

  useEffect(() => {
    onSeenRef.current = onSeen;
  }, [onSeen]);

  useEffect(() => {
    setDraft("");
    setFiles([]);
    setSendError("");
    if (fileInputRef.current) {
      fileInputRef.current.value = "";
    }
  }, [expediente]);

  async function loadMessages({ markSeen = true } = {}) {
    try {
      setState((current) => ({ ...current, loading: true, error: null }));
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
  }

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

    return () => {
      alive = false;
    };
  }, [expediente, mock, readOnly]);

  function handleFilesChange(event) {
    const selectedFiles = Array.from(event.target.files || []);
    const mergedFiles = [...files, ...selectedFiles];
    const uniqueFiles = mergedFiles.filter((file, index, list) => {
      const key = `${file.name}:${file.size}:${file.lastModified}`;
      return index === list.findIndex((candidate) => `${candidate.name}:${candidate.size}:${candidate.lastModified}` === key);
    });

    if (uniqueFiles.length > MAX_ATTACHMENTS) {
      setSendError(tt("Puedes adjuntar como máximo 3 archivos por mensaje."));
    } else {
      setSendError("");
    }

    setFiles(uniqueFiles.slice(0, MAX_ATTACHMENTS));
    if (fileInputRef.current) {
      fileInputRef.current.value = "";
    }
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
      files.forEach((file) => {
        formData.append("attachments[]", file);
      });

      await api("/messages", {
        method: "POST",
        body: formData,
      });

      setDraft("");
      setFiles([]);
      if (fileInputRef.current) {
        fileInputRef.current.value = "";
      }

      await loadMessages({ markSeen: true });
    } catch (error) {
      setSendError(error?.message || tt("No se pudo enviar el mensaje."));
    } finally {
      setSending(false);
    }
  }

  const items = Array.isArray(state.data?.items) ? state.data.items : [];

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

  return (
    <div className="cp-messages-panel">
      {readOnly ? (
        <Notice variant="info" title={tt("Solo lectura")}>
          {readOnlyMessage || tt("Modo de vista cliente activo. Solo lectura.")}
        </Notice>
      ) : null}

      {!items.length ? (
        <EmptyState title={tt("Todavía no hay mensajes")} icon="💬">
          {tt("Si necesitas algo sobre este viaje, escríbenos desde aquí y seguiremos la conversación en el portal.")}
        </EmptyState>
      ) : (
        <div className="cp-timeline cp-mt-14">
          {items.map((message) => {
            const direction = message.direction === "client" ? "client" : "agency";
            const fallbackAuthor = direction === "agency" ? "Casanova Golf" : tt("Tú");
            const attachments = Array.isArray(message.attachments) ? message.attachments : [];

            return (
              <div key={message.id} className={`cp-msg cp-msg--${direction}`}>
                <div className="cp-msg-head">
                  <div className="cp-msg-author">
                    <span className="cp-dot" />
                    <span>{message.author || fallbackAuthor}</span>
                  </div>
                  <div>{formatMsgDate(message.date)}</div>
                </div>
                {message.content ? <div className="cp-msg-body">{message.content}</div> : null}
                {attachments.length ? (
                  <div className="cp-msg-attachments">
                    {attachments.map((attachment) => (
                      <a
                        key={`${message.id}:${attachment.id}`}
                        className="cp-msg-attachment"
                        href={attachment.downloadUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        <span>{attachment.name || tt("Adjunto")}</span>
                        <span>{attachment.sizeLabel || ""}</span>
                      </a>
                    ))}
                  </div>
                ) : null}
              </div>
            );
          })}
        </div>
      )}

      {!readOnly ? (
        <form className="cp-message-composer cp-mt-14" onSubmit={handleSubmit}>
          <label className="cp-pay-section__label" htmlFor="cp-message-body">
            {tt("Responder")}
          </label>
          <textarea
            id="cp-message-body"
            className="cp-message-composer__input"
            rows={4}
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            placeholder={tt("Escribe aquí tu mensaje sobre este viaje…")}
            disabled={sending}
          />
          <div className="cp-message-composer__files">
            <input
              ref={fileInputRef}
              type="file"
              multiple
              accept={ACCEPTED_ATTACHMENT_TYPES}
              onChange={handleFilesChange}
              disabled={sending}
            />
            <div className="cp-meta">
              {tt("Adjuntos básicos: hasta 3 archivos por mensaje, máximo 5 MB por archivo. PDF, JPG, PNG o WEBP.")}
            </div>
            {files.length ? (
              <div className="cp-message-composer__file-list">
                {files.map((file, index) => (
                  <div key={`${file.name}-${file.size}-${index}`} className="cp-message-composer__file-item">
                    <span>{`${file.name} · ${formatFileSize(file.size)}`}</span>
                    <button
                      type="button"
                      className="cp-message-composer__file-remove"
                      onClick={() => removeFile(index)}
                      disabled={sending}
                    >
                      {tt("Quitar")}
                    </button>
                  </div>
                ))}
              </div>
            ) : null}
          </div>
          {sendError ? (
            <div className="cp-meta cp-message-composer__error">{sendError}</div>
          ) : null}
          <div className="cp-message-composer__actions">
            <button
              type="submit"
              className="cp-btn primary"
              disabled={sending || (draft.trim() === "" && files.length === 0)}
            >
              {sending ? tt("Enviando…") : tt("Enviar mensaje")}
            </button>
          </div>
        </form>
      ) : null}
    </div>
  );
}
