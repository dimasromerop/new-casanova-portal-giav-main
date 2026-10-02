export function readParams() {
  const params = new URLSearchParams(window.location.search);
  return {
    view: params.get("view") || "dashboard",
    expediente: params.get("expediente"),
    tab: params.get("tab") || "summary",
    mock: params.get("mock") === "1",
    payStatus: params.get("pay_status") || "",
    payment: params.get("payment") || "",
    method: params.get("method") || "",
    refresh: params.get("refresh") === "1",
  };
}

// Cambia varios parámetros en una sola entrada del historial (el botón «atrás» vuelve
// a la vista anterior, no a un estado intermedio). `null` o "" borran el parámetro.
export function setParams(updates, { scrollTop = true } = {}) {
  const params = new URLSearchParams(window.location.search);
  Object.entries(updates).forEach(([key, value]) => {
    if (value === null || value === undefined || value === "") params.delete(key);
    else params.set(key, String(value));
  });
  window.history.pushState({}, "", `${window.location.pathname}?${params.toString()}`);
  window.dispatchEvent(new Event("popstate"));
  if (scrollTop) window.scrollTo({ top: 0 });
}

export function setParam(key, value) {
  const params = new URLSearchParams(window.location.search);
  if (value === null || value === undefined || value === "") {
    params.delete(key);
  } else {
    params.set(key, value);
  }
  window.history.pushState({}, "", `${window.location.pathname}?${params.toString()}`);
  window.dispatchEvent(new Event("popstate"));
}
