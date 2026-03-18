function LockIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M8 10V8a4 4 0 1 1 8 0v2"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <rect
        x="5"
        y="10"
        width="14"
        height="10"
        rx="2.5"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
      />
    </svg>
  );
}

export default function Field({ label, children, help, htmlFor = "", readOnly = false, className = "" }) {
  return (
    <div className={`cp-field ${readOnly ? "is-readonly" : ""} ${className}`.trim()}>
      <label className="cp-label" htmlFor={htmlFor || undefined}>{label}</label>
      <div className="cp-field__control">
        {children}
        {readOnly ? (
          <span className="cp-field__lock" aria-hidden="true">
            <LockIcon />
          </span>
        ) : null}
      </div>
      {help ? <div className="cp-help">{help}</div> : null}
    </div>
  );
}
