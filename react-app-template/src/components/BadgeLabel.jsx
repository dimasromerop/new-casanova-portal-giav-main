export default function BadgeLabel({ label, variant = "info", className = "" }) {
  if (!label) return null;
  const base = `cp-badge cp-badge--${variant}`.trim();
  return <span className={`${base} ${className}`.trim()}>{label}</span>;
}
