export default function Field({ label, children, help }) {
  return (
    <div className="cp-field">
      <div className="cp-label">{label}</div>
      {children}
      {help ? <div className="cp-help">{help}</div> : null}
    </div>
  );
}
