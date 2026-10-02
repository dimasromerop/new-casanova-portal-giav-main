import React from "react";

import Icon from "./Icon.jsx";

export default function Field({ label, children, help, htmlFor = "", readOnly = false, className = "" }) {
  return (
    <div className={`cp-field ${readOnly ? "is-readonly" : ""} ${className}`.trim()}>
      <label className="cp-label" htmlFor={htmlFor || undefined}>{label}</label>
      <div className="cp-field__control">
        {children}
        {readOnly ? (
          <span className="cp-field__lock" aria-hidden="true">
            <Icon name="lock" size={16} />
          </span>
        ) : null}
      </div>
      {help ? <div className="cp-help">{help}</div> : null}
    </div>
  );
}
