import React, { useEffect, useRef } from "react";

import { tt } from "../../i18n/t.js";

export const TRIP_TABS = ["summary", "payments", "invoices", "vouchers", "messages"];

export default function Tabs({ tab, onTab }) {
  const listRef = useRef(null);
  const items = [
    { k: "summary", label: tt("Resumen") },
    { k: "payments", label: tt("Pagos") },
    { k: "invoices", label: tt("Facturas") },
    { k: "vouchers", label: tt("Bonos") },
    { k: "messages", label: tt("Mensajes") },
  ];

  // En móvil la fila se desplaza: la pestaña activa se mantiene a la vista.
  useEffect(() => {
    // Solo desplazamiento horizontal: scrollIntoView movería también la página.
    const list = listRef.current;
    const active = list?.querySelector('[aria-selected="true"]');
    if (!list || !active) return;
    // Si el foco estaba en la fila (navegación con flechas), acompaña a la pestaña activa.
    if (list.contains(document.activeElement) && document.activeElement !== active) active.focus();
    const left = active.offsetLeft;
    const right = left + active.offsetWidth;
    if (left < list.scrollLeft) list.scrollLeft = left - 16;
    else if (right > list.scrollLeft + list.clientWidth) list.scrollLeft = right - list.clientWidth + 16;
  }, [tab]);

  // Flechas izquierda/derecha, Inicio y Fin, como en el patrón ARIA de pestañas.
  const onKeyDown = (event) => {
    const index = items.findIndex((item) => item.k === tab);
    let next = null;
    if (event.key === "ArrowRight") next = items[(index + 1) % items.length];
    if (event.key === "ArrowLeft") next = items[(index - 1 + items.length) % items.length];
    if (event.key === "Home") next = items[0];
    if (event.key === "End") next = items[items.length - 1];
    if (!next) return;
    event.preventDefault();
    onTab(next.k);
  };

  return (
    <div className="cp-trip-tabs">
      <div className="cp-trip-tabs__list" role="tablist" aria-label={tt("Secciones del viaje")} ref={listRef} onKeyDown={onKeyDown}>
        {items.map((it) => {
          const selected = tab === it.k;
          return (
            <button
              key={it.k}
              id={`cp-tab-${it.k}`}
              type="button"
              role="tab"
              aria-selected={selected ? "true" : "false"}
              aria-controls="cp-trip-panel"
              tabIndex={selected ? 0 : -1}
              className={`cp-trip-tabs__tab ${selected ? "is-active" : ""}`.trim()}
              onClick={() => onTab(it.k)}
            >
              {it.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}
