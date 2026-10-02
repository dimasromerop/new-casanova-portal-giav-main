import React, { useMemo, useState } from "react";

import { tt } from "../../i18n/t.js";
import Icon from "../Icon.jsx";
import { euro } from "../../lib/formatters.js";
import { dateToUtcMidnight, serviceSemanticType } from "../../lib/tripServices.js";

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
  const viewUrl = service.voucher_urls?.view || "";
  const pdfUrl = service.voucher_urls?.pdf || "";
  const canVoucher = Boolean(service.actions?.voucher);
  const canPdf = Boolean(service.actions?.pdf);
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

  const canOpen = Boolean(service.actions?.detail);
  const detailId = `cp-service-detail-${String(service.id || service.title || "x").replace(/[^\w-]/g, "")}`;
  const iconName = semanticType === "hotel" ? "bed"
    : semanticType === "golf" ? "golf"
    : semanticType === "transfer" ? "car"
    : semanticType === "flight" || isPlaneService ? "plane"
    : "sparkles";
  const kvRows = [
    { key: "code", label: tt("Código:"), value: detail.code || service.id },
    { key: "type", label: tt("Tipo:"), value: detail.type },
    { key: "dates", label: tt("Fechas:"), value: service.date_range || "—" },
    { key: "locator", label: tt("Localizador:"), value: detail.locator },
    { key: "price", label: tt("PVP:"), value: price != null ? euro(price) : "" },
  ].filter((row) => row.value !== undefined && row.value !== null && String(row.value).trim() !== "");

  const heading = (
    <>
      {imageUrl ? (
        <span className="cp-service__thumb" aria-hidden="true">
          <img src={imageUrl} alt="" loading="lazy" decoding="async" />
        </span>
      ) : null}
      <span className="cp-service__main">
        <span className="cp-service__title">{service.title || tt("Servicio")}</span>
        <span className="cp-service__dates">
          {serviceDatesLabel}
          {golfObservationText ? <span className="cp-service__note">{golfObservationText}</span> : null}
        </span>
      </span>
      {price != null ? <span className="cp-service__price">{euro(price)}</span> : null}
      {canOpen ? <Icon name="chevron" size={18} className="cp-service__chevron" /> : null}
    </>
  );

  return (
    <li className={`cp-service${indent ? " cp-service--child" : ""}${open ? " is-open" : ""}`}>
      <span className={`cp-service__dot is-${semanticType || "other"}`} aria-hidden="true">
        <Icon name={iconName} size={16} />
      </span>
      <div className="cp-service__card">
        <div className="cp-service__head">
          {canOpen ? (
            <button
              type="button"
              className="cp-service__summary"
              onClick={() => setOpen((prev) => !prev)}
              aria-expanded={open ? "true" : "false"}
              aria-controls={detailId}
            >
              {heading}
            </button>
          ) : (
            <div className="cp-service__summary">{heading}</div>
          )}
          {(canVoucher && viewUrl) || (canPdf && pdfUrl) ? (
            <div className="cp-service__actions">
              {canVoucher && viewUrl ? (
                <a className="cp-btn cp-btn--ghost cp-btn--sm" href={viewUrl} target="_blank" rel="noreferrer">
                  <Icon name="ticket" size={16} />
                  {tt("Bono")}
                </a>
              ) : null}
              {canPdf && pdfUrl ? (
                <a className="cp-btn cp-btn--ghost cp-btn--sm" href={pdfUrl} target="_blank" rel="noreferrer">
                  <Icon name="download" size={16} />
                  {tt("PDF")}
                </a>
              ) : null}
            </div>
          ) : null}
        </div>

        {open ? (
          <div className="cp-service__detail" id={detailId}>
            <dl className="cp-service__kv">
              {[...kvRows, ...extraDetailRows.map((row) => ({ ...row, label: `${row.label}:` }))].map((row) => (
                <div key={row.key}>
                  <dt>{String(row.label).replace(/:$/, "")}</dt>
                  <dd>{row.value}</dd>
                </div>
              ))}
            </dl>
            {segments.length > 0 ? (
              <div className="cp-service__block">
                <div className="cp-service__block-title">{tt("Segmentos:").replace(/:$/, "")}</div>
                <ul className="cp-service__segments">
                  {segments.map((segment, index) => (
                    <li key={`${segment}-${index}`}>{segment}</li>
                  ))}
                </ul>
              </div>
            ) : null}
            {bonusText ? (
              <div className="cp-service__block">
                <div className="cp-service__block-title">{tt("Texto adicional (bono):").replace(/:$/, "")}</div>
                <p className="cp-service__bonus">{bonusText}</p>
              </div>
            ) : null}
          </div>
        ) : null}
      </div>
    </li>
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
    <ul className="cp-service-list">
      {sortedServices.map((service, index) => (
        <ServiceItem
          key={service.id || `${service.type || "srv"}-${index}`}
          service={service}
          indent={indent}
        />
      ))}
    </ul>
  );
}
