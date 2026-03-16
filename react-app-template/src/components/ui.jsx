import React from "react";

import { t } from "../i18n/t.js";

export function Notice({ variant = "info", title, children, action, className = "", onClose, closeLabel = t("close", "Cerrar") }) {
  return (
    <div className={`cp-notice2 is-${variant} ${className}`.trim()}>
      <div className="cp-notice2__body">
        {title ? <div className="cp-notice2__title">{title}</div> : null}
        <div className="cp-notice2__text">{children}</div>
      </div>
      <div className="cp-notice2__action">
        {action ? <div className="cp-notice2__action-inner">{action}</div> : null}
        {typeof onClose === "function" ? (
          <button type="button" className="cp-notice2__close" onClick={onClose} aria-label={closeLabel} title={closeLabel}>
            ×
          </button>
        ) : null}
      </div>
    </div>
  );
}

export function EmptyState({ title, children, icon = "🗂️", action }) {
  return (
    <div className="cp-empty">
      <div className="cp-empty__icon" aria-hidden="true">
        {icon}
      </div>
      <div className="cp-empty__title">{title}</div>
      {children ? <div className="cp-empty__text">{children}</div> : null}
      {action ? <div className="cp-empty__action">{action}</div> : null}
    </div>
  );
}

export function Skeleton({ lines = 3 }) {
  return (
    <div className="cp-skeleton" aria-hidden="true">
      {Array.from({ length: lines }).map((_, index) => (
        <div key={index} className="cp-skeleton__line" />
      ))}
    </div>
  );
}

export function TableSkeleton({ rows = 6, cols = 7 }) {
  return (
    <div className="cp-table-skel" aria-hidden="true">
      <div className="cp-table-skel__row is-head">
        {Array.from({ length: cols }).map((_, index) => (
          <div key={index} className="cp-table-skel__cell" />
        ))}
      </div>
      {Array.from({ length: rows }).map((_, rowIndex) => (
        <div key={rowIndex} className="cp-table-skel__row">
          {Array.from({ length: cols }).map((_, colIndex) => (
            <div key={colIndex} className="cp-table-skel__cell" />
          ))}
        </div>
      ))}
    </div>
  );
}
