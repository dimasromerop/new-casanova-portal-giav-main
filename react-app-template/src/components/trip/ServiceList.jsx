import React, { useMemo, useState } from "react";

import { tt } from "../../i18n/t.js";
import { euro } from "../../lib/formatters.js";

function IconPlane() {
  return (
    <svg
      viewBox="0 0 122.88 122.88"
      width={22}
      height={22}
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
    >
      <path
        fill="currentColor"
        fillRule="evenodd"
        clipRule="evenodd"
        d="M16.63,105.75c0.01-4.03,2.3-7.97,6.03-12.38L1.09,79.73c-1.36-0.59-1.33-1.42-0.54-2.4l4.57-3.9
		c0.83-0.51,1.71-0.73,2.66-0.47l26.62,4.5l22.18-24.02L4.8,18.41c-1.31-0.77-1.42-1.64-0.07-2.65l7.47-5.96l67.5,18.97L99.64,7.45
		c6.69-5.79,13.19-8.38,18.18-7.15c2.75,0.68,3.72,1.5,4.57,4.08c1.65,5.06-0.91,11.86-6.96,18.86L94.11,43.18l18.97,67.5
		l-5.96,7.47c-1.01,1.34-1.88,1.23-2.65-0.07L69.43,66.31L45.41,88.48l4.5,26.62c0.26,0.94,0.05,1.82-0.47,2.66l-3.9,4.57
		c-0.97,0.79-1.81,0.82-2.4-0.54l-13.64-21.57c-4.43,3.74-8.37,6.03-12.42,6.03C16.71,106.24,16.63,106.11,16.63,105.75
		L16.63,105.75z"
      />
    </svg>
  );
}

export function ServiceItem({ service, indent = false }) {
  const [open, setOpen] = useState(false);
  const detail = service.detail || {};
  const bonusText = typeof detail.bonus_text === "string" ? detail.bonus_text.trim() : "";
  const price = typeof service.price === "number" ? service.price : null;
  const imageUrl = service?.media?.image_url || "";
  const viewUrl = service.voucher_urls?.view || "";
  const pdfUrl = service.voucher_urls?.pdf || "";
  const canVoucher = Boolean(service.actions?.voucher);
  const canPdf = Boolean(service.actions?.pdf);
  const tagLabel = (service.type || "servicio").toUpperCase();
  const serviceType = (detail.type || service.type || "").toUpperCase();
  const isPlaneService = serviceType === "AV";
  const semanticType = String(service.semantic_type || "").toLowerCase();
  const detailPayload = detail.details || service.details || {};
  const segments = Array.isArray(detailPayload.segments) ? detailPayload.segments : [];

  const shouldShowRow = (...expectedTypes) => !semanticType || expectedTypes.includes(semanticType);
  const extraDetailRows = [
    {
      key: "rooms",
      label: "Habitaciones",
      value: detailPayload.rooms,
      show: shouldShowRow("hotel"),
    },
    {
      key: "board",
      label: "Régimen",
      value: detailPayload.board,
      show: shouldShowRow("hotel"),
    },
    {
      key: "rooming",
      label: "Rooming",
      value: detailPayload.rooming,
      show: shouldShowRow("hotel"),
    },
    {
      key: "players",
      label: "Jugadores",
      value: detailPayload.players,
      show: shouldShowRow("golf"),
    },
    {
      key: "route",
      label: "Trayecto",
      value: detailPayload.route,
      show: shouldShowRow("flight", "transfer"),
    },
    {
      key: "flight_code",
      label: "Código de vuelo",
      value: detailPayload.flight_code,
      show: shouldShowRow("flight"),
    },
    {
      key: "schedule",
      label: "Horario",
      value: detailPayload.schedule,
      show: shouldShowRow("flight", "transfer"),
    },
    {
      key: "passengers",
      label: "Pasajeros",
      value: detailPayload.passengers,
      show: shouldShowRow("flight", "transfer"),
    },
    {
      key: "provider",
      label: "Proveedor",
      value: detailPayload.provider,
      show: shouldShowRow("transfer"),
    },
  ].filter((row) => row.show && row.value !== undefined && row.value !== null && String(row.value).trim() !== "");

  const toggleDetail = () => {
    if (!service.actions?.detail) return;
    setOpen((prev) => !prev);
  };

  return (
    <div className={`cp-service${indent ? " cp-service--child" : ""}`}>
      <div className="cp-service__summary">
        {imageUrl ? (
          <div className="cp-service__thumb" aria-hidden="true">
            <img src={imageUrl} alt="" loading="lazy" />
          </div>
        ) : null}
        <div className="cp-service__main">
          <div className="cp-service__code">
            {detail.code || service.id || "Servicio"}
          </div>
          <div className="cp-service__title">
            {isPlaneService ? (
              <span className="cp-service__title-icon" aria-hidden="true">
                <IconPlane />
              </span>
            ) : null}
            <span>{service.title || "Servicio"}</span>
          </div>
          <div className="cp-service__dates">
            {service.date_range || "Fechas por confirmar"}
          </div>
        </div>
        <div className="cp-service__right">
          {price != null ? (
            <div className="cp-service__price">{euro(price)}</div>
          ) : null}
          <div className="cp-service__actions">
            <span className="cp-chip">{tagLabel}</span>
            <button
              type="button"
              className="cp-btn cp-btn--ghost"
              onClick={toggleDetail}
              disabled={!service.actions?.detail}
              aria-expanded={open}
            >
              {tt("Detalle")}
            </button>
            {canVoucher && viewUrl ? (
              <a className="cp-btn cp-btn--ghost" href={viewUrl} target="_blank" rel="noreferrer">
                {tt("Ver bono")}
              </a>
            ) : (
              <span className="cp-btn cp-btn--ghost cp-btn--disabled">{tt("Bono")}</span>
            )}
            {canPdf && pdfUrl ? (
              <a className="cp-btn cp-btn--ghost" href={pdfUrl} target="_blank" rel="noreferrer">
                {tt("PDF")}
              </a>
            ) : (
              <span className="cp-btn cp-btn--ghost cp-btn--disabled">{tt("PDF")}</span>
            )}
          </div>
        </div>
      </div>
      {open ? (
        <div className="cp-service__detail">
          <div className="cp-service__kv">
            {detail.code || service.id ? (
              <div>
                <strong>{tt("Código:")}</strong> {detail.code || service.id}
              </div>
            ) : null}
            {detail.type ? (
              <div>
                <strong>{tt("Tipo:")}</strong> {detail.type}
              </div>
            ) : null}
            <div>
              <strong>{tt("Fechas:")}</strong> {service.date_range || "—"}
            </div>
            {detail.locator ? (
              <div>
                <strong>{tt("Localizador:")}</strong> {detail.locator}
              </div>
            ) : null}
            {price != null ? (
              <div>
                <strong>{tt("PVP:")}</strong> {euro(price)}
              </div>
            ) : null}
            {extraDetailRows.map((row) => (
              <div key={row.key}>
                <strong>{row.label}:</strong> {row.value}
              </div>
            ))}
          </div>
          {segments.length > 0 ? (
            <>
              <div className="cp-service__divider" />
              <div>
                <strong>{tt("Segmentos:")}</strong>
                <ul className="cp-service__bonus">
                  {segments.map((segment, index) => (
                    <li key={`${segment}-${index}`}>{segment}</li>
                  ))}
                </ul>
              </div>
            </>
          ) : null}
          {bonusText ? (
            <>
              <div className="cp-service__divider" />
              <div>
                <strong>{tt("Texto adicional (bono):")}</strong>
                <p className="cp-service__bonus">{bonusText}</p>
              </div>
            </>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function getServiceSortKey(service) {
  if (!service) return "";
  const candidate = service.detail?.code ?? service.id;
  if (candidate === null || candidate === undefined) return "";
  return String(candidate).trim();
}

function compareServicesByGiavCode(a, b) {
  const keyA = getServiceSortKey(a);
  const keyB = getServiceSortKey(b);
  return keyA.localeCompare(keyB, undefined, {
    numeric: true,
    sensitivity: "base",
  });
}

export default function ServiceList({ services, indent = false }) {
  const sortedServices = useMemo(() => {
    if (!Array.isArray(services)) return [];
    return [...services].sort(compareServicesByGiavCode);
  }, [services]);

  if (!sortedServices.length) return null;

  return (
    <div className="cp-service-list">
      {sortedServices.map((service, index) => (
        <ServiceItem
          key={service.id || `${service.type || "srv"}-${index}`}
          service={service}
          indent={indent}
        />
      ))}
    </div>
  );
}
