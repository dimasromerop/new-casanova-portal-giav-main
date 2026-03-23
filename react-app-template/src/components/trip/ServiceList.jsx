import React, { useMemo, useState } from "react";

import { tt } from "../../i18n/t.js";
import { euro } from "../../lib/formatters.js";
import { dateToUtcMidnight, serviceSemanticType } from "../../lib/tripServices.js";

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

function cleanServiceText(value) {
  const text = String(value ?? "").trim();
  return text ? text.replace(/\s+/g, " ") : "";
}

function parseDateRangeBounds(range) {
  const source = cleanServiceText(range);
  if (!source) return { start: "", end: "" };

  const matches = source.match(/\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4}/g);
  if (!matches?.length) return { start: source, end: source };

  return {
    start: matches[0] || "",
    end: matches[1] || matches[0] || "",
  };
}

function getServiceDateBounds(service) {
  const start = cleanServiceText(service?.date_from || service?.date_start);
  const end = cleanServiceText(service?.date_to || service?.date_end);
  if (start || end) {
    const fallback = start || end;
    return {
      start: start || fallback,
      end: end || fallback,
    };
  }

  return parseDateRangeBounds(service?.date_range);
}

function getGolfObservationText(service, detail, detailPayload) {
  const candidates = [
    detailPayload?.observations,
    detailPayload?.observation,
    detailPayload?.observaciones,
    detailPayload?.observacion,
    detail?.observations,
    detail?.observation,
    detail?.observaciones,
    detail?.observacion,
    service?.observations,
    service?.observation,
    service?.observaciones,
    service?.observacion,
    detail?.bonus_text,
  ];

  for (const candidate of candidates) {
    const clean = cleanServiceText(candidate);
    if (clean) return clean;
  }

  return "";
}

export function ServiceItem({ service, indent = false }) {
  const [open, setOpen] = useState(false);
  const detail = service.detail || {};
  const bonusText = typeof detail.bonus_text === "string" ? detail.bonus_text.trim() : "";
  const price = typeof service.price === "number" ? service.price : null;
  const imageUrl = service?.media?.image_url || "";
  const hasImage = Boolean(imageUrl);
  const viewUrl = service.voucher_urls?.view || "";
  const pdfUrl = service.voucher_urls?.pdf || "";
  const canVoucher = Boolean(service.actions?.voucher);
  const canPdf = Boolean(service.actions?.pdf);
  const tagLabel = (service.type || tt("Servicio")).toUpperCase();
  const serviceType = (detail.type || service.type || "").toUpperCase();
  const isPlaneService = serviceType === "AV";
  const semanticType = serviceSemanticType(service);
  const detailPayload = detail.details || service.details || {};
  const segments = Array.isArray(detailPayload.segments) ? detailPayload.segments : [];
  const serviceDatesLabel = cleanServiceText(service.date_range) || tt("Fechas por confirmar");
  const golfObservationText = semanticType === "golf"
    ? getGolfObservationText(service, detail, detailPayload)
    : "";

  const shouldShowRow = (...expectedTypes) => !semanticType || expectedTypes.includes(semanticType);
  const extraDetailRows = [
    {
      key: "rooms",
      label: tt("Habitaciones"),
      value: detailPayload.rooms,
      show: shouldShowRow("hotel"),
    },
    {
      key: "board",
      label: tt("Régimen"),
      value: detailPayload.board,
      show: shouldShowRow("hotel"),
    },
    {
      key: "rooming",
      label: tt("Rooming"),
      value: detailPayload.rooming,
      show: shouldShowRow("hotel"),
    },
    {
      key: "players",
      label: tt("Jugadores"),
      value: detailPayload.players,
      show: shouldShowRow("golf"),
    },
    {
      key: "route",
      label: tt("Trayecto"),
      value: detailPayload.route,
      show: shouldShowRow("flight", "transfer"),
    },
    {
      key: "flight_code",
      label: tt("Código de vuelo"),
      value: detailPayload.flight_code,
      show: shouldShowRow("flight"),
    },
    {
      key: "schedule",
      label: tt("Horario"),
      value: detailPayload.schedule,
      show: shouldShowRow("flight", "transfer"),
    },
    {
      key: "passengers",
      label: tt("Pasajeros"),
      value: detailPayload.passengers,
      show: shouldShowRow("flight", "transfer"),
    },
    {
      key: "provider",
      label: tt("Proveedor"),
      value: detailPayload.provider,
      show: shouldShowRow("transfer"),
    },
  ].filter((row) => row.show && row.value !== undefined && row.value !== null && String(row.value).trim() !== "");

  const toggleDetail = () => {
    if (!service.actions?.detail) return;
    setOpen((prev) => !prev);
  };

  const dotClass = semanticType === "hotel" ? "is-hotel"
    : semanticType === "golf" ? "is-golf"
    : semanticType === "transfer" ? "is-transfer"
    : semanticType === "flight" ? "is-flight"
    : "";

  return (
    <div className={`cp-service${indent ? " cp-service--child" : ""}`}>
      <div className={`cp-service__dot ${dotClass}`} aria-hidden="true">
        {semanticType === "hotel" ? (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
            <path d="M4 12V9.5A2.5 2.5 0 0 1 6.5 7H9a2 2 0 0 1 2 2v3" /><path d="M11 12V8.8A1.8 1.8 0 0 1 12.8 7H16a4 4 0 0 1 4 4v1" /><path d="M4 12h16v4H4z" /><path d="M6 16v2M18 16v2" />
          </svg>
        ) : semanticType === "golf" ? (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
            <path d="M8 20V4" /><path d="M8 5c1.2 0 2.2-.8 3.8-.8 1.7 0 2.3.8 3.8.8 1.2 0 1.9-.4 2.4-.7v6.4c-.5.3-1.2.7-2.4.7-1.5 0-2.1-.8-3.8-.8-1.6 0-2.6.8-3.8.8" strokeLinejoin="round" /><path d="M6 20h4" />
          </svg>
        ) : semanticType === "transfer" ? (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
            <path d="M6.5 16.5h11a1.5 1.5 0 0 0 1.5-1.5v-3l-1.7-3.6A2.2 2.2 0 0 0 15.3 7H8.7a2.2 2.2 0 0 0-2 1.4L5 12v3a1.5 1.5 0 0 0 1.5 1.5z" strokeLinejoin="round" /><path d="M7 12h10" /><circle cx="8.5" cy="16.5" r="1.4" /><circle cx="15.5" cy="16.5" r="1.4" />
          </svg>
        ) : semanticType === "flight" ? (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
            <path d="M6.5 16.5h11a1.5 1.5 0 0 0 1.5-1.5v-3l-1.7-3.6A2.2 2.2 0 0 0 15.3 7H8.7a2.2 2.2 0 0 0-2 1.4L5 12v3a1.5 1.5 0 0 0 1.5 1.5z" strokeLinejoin="round" /><path d="M7 12h10" /><circle cx="8.5" cy="16.5" r="1.4" /><circle cx="15.5" cy="16.5" r="1.4" />
          </svg>
        ) : null}
      </div>
      <div className={`cp-service__card${open ? " is-open" : ""}`}>
        <div className={`cp-service__summary${hasImage ? "" : " is-no-image"}`} onClick={toggleDetail}>
          {imageUrl ? (
            <div className="cp-service__thumb" aria-hidden="true">
              <img src={imageUrl} alt="" loading="lazy" />
            </div>
          ) : null}
          <div className="cp-service__main">
            <div className="cp-service__title">
              {isPlaneService ? (
                <span className="cp-service__title-icon" aria-hidden="true">
                  <IconPlane />
                </span>
              ) : null}
              <span>{service.title || tt("Servicio")}</span>
            </div>
            <div className="cp-service__dates">
              <span>{serviceDatesLabel}</span>
              {golfObservationText ? <span className="cp-service__dates-note">{golfObservationText}</span> : null}
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
                onClick={(e) => { e.stopPropagation(); toggleDetail(); }}
                disabled={!service.actions?.detail}
                aria-expanded={open}
              >
                {tt("Detalle")}
              </button>
              {canVoucher && viewUrl ? (
                <a className="cp-btn cp-btn--ghost" href={viewUrl} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()}>
                  {tt("Bono")}
                </a>
              ) : (
                <span className="cp-btn cp-btn--ghost cp-btn--disabled">{tt("Bono")}</span>
              )}
              {canPdf && pdfUrl ? (
                <a className="cp-btn cp-btn--ghost" href={pdfUrl} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()}>
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

function getChronologicalSortMeta(service) {
  const semantic = serviceSemanticType(service);
  const bounds = getServiceDateBounds(service);
  const startUtc = dateToUtcMidnight(bounds.start);
  const endUtc = dateToUtcMidnight(bounds.end);
  const isMultiDay = Number.isFinite(startUtc) && Number.isFinite(endUtc) && startUtc !== endUtc;

  return {
    bucket: semantic !== "hotel" && isMultiDay ? 1 : 0,
    date: Number.isFinite(startUtc) ? startUtc : Number.MAX_SAFE_INTEGER,
    tieBreaker: semantic === "hotel" ? 0 : 1,
    key: getServiceSortKey(service),
    title: cleanServiceText(service?.title).toLowerCase(),
  };
}

function compareServicesChronologically(a, b) {
  const metaA = getChronologicalSortMeta(a);
  const metaB = getChronologicalSortMeta(b);

  if (metaA.bucket !== metaB.bucket) return metaA.bucket - metaB.bucket;
  if (metaA.date !== metaB.date) return metaA.date - metaB.date;
  if (metaA.tieBreaker !== metaB.tieBreaker) return metaA.tieBreaker - metaB.tieBreaker;

  const keyCompare = metaA.key.localeCompare(metaB.key, undefined, {
    numeric: true,
    sensitivity: "base",
  });
  if (keyCompare !== 0) return keyCompare;

  return metaA.title.localeCompare(metaB.title, undefined, {
    numeric: true,
    sensitivity: "base",
  });
}

export default function ServiceList({ services, indent = false, sortMode = "code" }) {
  const sortedServices = useMemo(() => {
    if (!Array.isArray(services)) return [];
    const list = [...services];
    return list.sort(sortMode === "chronological" ? compareServicesChronologically : compareServicesByGiavCode);
  }, [services, sortMode]);

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
