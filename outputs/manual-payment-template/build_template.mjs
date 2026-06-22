import fs from "node:fs/promises";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const outputDir = ".";
const workbook = Workbook.create();

const cobros = workbook.worksheets.add("Cobros");
const ayuda = workbook.worksheets.add("Ayuda");

cobros.showGridLines = false;
ayuda.showGridLines = false;

const headers = [
  "Expediente",
  "Id forma de pago",
  "Pagador",
  "DNI/NIF",
  "Fecha de pago",
  "Forma de pago",
  "Banco",
  "Importe",
  "Concepto",
  "Referencia bancaria",
  "Importe total reserva",
  "Estado",
];

cobros.getRange("A1:L1").values = [headers];
cobros.getRange("A2:L51").values = Array.from({ length: 50 }, () => Array(headers.length).fill(null));

const table = cobros.tables.add("A1:L51", true, "TablaCobrosManual");
table.showFilterButton = true;
table.showBandedRows = true;

cobros.freezePanes.freezeRows(1);
cobros.getRange("A1:L1").format = {
  fill: "#0F5132",
  font: { bold: true, color: "#FFFFFF" },
  wrapText: true,
  horizontalAlignment: "center",
  verticalAlignment: "middle",
};
cobros.getRange("A1:L51").format.borders = { preset: "all", style: "thin", color: "#D0D7DE" };
cobros.getRange("A2:L51").format = {
  fill: "#FFFFFF",
  verticalAlignment: "top",
};
cobros.getRange("E2:E51").setNumberFormat("yyyy-mm-dd");
cobros.getRange("H2:H51").setNumberFormat("#,##0.00");
cobros.getRange("K2:K51").setNumberFormat("#,##0.00");
cobros.getRange("A2:B51").setNumberFormat("0");
cobros.getRange("C2:D51").setNumberFormat("@");
cobros.getRange("F2:G51").setNumberFormat("@");
cobros.getRange("I2:J51").setNumberFormat("@");
cobros.getRange("L2:L51").dataValidation = { rule: { type: "list", values: ["Recibido", "NO recibido"] } };
cobros.getRange("B2:B51").dataValidation = { rule: { type: "whole", operator: "greaterThan", formula1: 0 } };
cobros.getRange("H2:H51").dataValidation = { rule: { type: "decimal", operator: "greaterThan", formula1: 0 } };
cobros.getRange("A1:A51").format.columnWidth = 14;
cobros.getRange("B1:B51").format.columnWidth = 18;
cobros.getRange("C1:C51").format.columnWidth = 30;
cobros.getRange("D1:D51").format.columnWidth = 14;
cobros.getRange("E1:E51").format.columnWidth = 15;
cobros.getRange("F1:F51").format.columnWidth = 20;
cobros.getRange("G1:G51").format.columnWidth = 14;
cobros.getRange("H1:H51").format.columnWidth = 13;
cobros.getRange("I1:I51").format.columnWidth = 28;
cobros.getRange("J1:J51").format.columnWidth = 24;
cobros.getRange("K1:K51").format.columnWidth = 18;
cobros.getRange("L1:L51").format.columnWidth = 16;
cobros.getRange("A1:L1").format.rowHeight = 34;
cobros.getRange("A2:L51").format.rowHeight = 24;

ayuda.getRange("A1:D1").merge();
ayuda.getRange("A1").values = [["Plantilla para importar cobros manuales en GIAV"]];
ayuda.getRange("A1:D1").format = {
  fill: "#0F5132",
  font: { bold: true, color: "#FFFFFF", size: 14 },
  horizontalAlignment: "left",
};

ayuda.getRange("A3:D3").values = [["Campo", "Obligatorio", "Uso", "Ejemplo"]];
ayuda.getRange("A3:D3").format = {
  fill: "#E6F4EA",
  font: { bold: true, color: "#1D2327" },
  borders: { preset: "all", style: "thin", color: "#C7D7CC" },
};

const helpRows = [
  ["Expediente", "Opcional", "ID interno o codigo visible. Si lo indicas en la pantalla de importacion, puedes dejarlo vacio en el Excel.", "2553848"],
  ["Id forma de pago", "Opcional", "ID interno de Forma de Pago en GIAV. Si todo el archivo usa el mismo tipo, puedes indicarlo en pantalla.", "1027"],
  ["Pagador", "Si", "Nombre que quedara como pagador del cobro.", "Tomas Calero"],
  ["DNI/NIF", "No", "Si existe, se busca el cliente pagador. Si no existe o no se encuentra, se usa el titular del expediente.", "12345678Z"],
  ["Fecha de pago", "Si", "Fecha real del cobro. Usa formato fecha de Excel o yyyy-mm-dd.", "2026-06-16"],
  ["Forma de pago", "No", "Texto descriptivo para auditoria. No sustituye al ID de forma de pago GIAV.", "Transferencia"],
  ["Banco", "No", "Banco o entidad origen/destino para trazabilidad.", "BBVA"],
  ["Importe", "Si", "Importe recibido en EUR. No incluyas simbolo si puedes evitarlo.", "1512.00"],
  ["Concepto", "No", "Concepto que se enviara a GIAV.", "Resto 80%"],
  ["Referencia bancaria", "No", "Referencia de transferencia, justificante u operacion. Ayuda a detectar duplicados.", "TRF-20260616-001"],
  ["Importe total reserva", "No", "Campo de control interno. El importador no lo necesita para registrar el cobro.", "1890.00"],
  ["Estado", "Recomendado", "Usa Recibido para importar. Cualquier fila con NO recibido se bloqueara en la previsualizacion.", "Recibido"],
];
ayuda.getRange("A4:D15").values = helpRows;
ayuda.getRange("A4:D15").format.borders = { preset: "all", style: "thin", color: "#D0D7DE" };
ayuda.getRange("A4:D15").format.wrapText = true;
ayuda.getRange("A4:B15").format.verticalAlignment = "top";
ayuda.getRange("C4:D15").format.verticalAlignment = "top";
ayuda.getRange("A:A").format.columnWidth = 22;
ayuda.getRange("B:B").format.columnWidth = 14;
ayuda.getRange("C:C").format.columnWidth = 74;
ayuda.getRange("D:D").format.columnWidth = 24;
ayuda.getRange("A4:D15").format.rowHeight = 46;

ayuda.getRange("A17:D17").values = [["Notas"]];
ayuda.getRange("A17:D17").merge();
ayuda.getRange("A17:D17").format = {
  fill: "#F6F7F7",
  font: { bold: true },
  borders: { preset: "outside", style: "thin", color: "#D0D7DE" },
};
ayuda.getRange("A18:D21").values = [
  ["1. La primera hoja, Cobros, es la hoja que lee el importador."],
  ["2. No pongas filas de ejemplo en Cobros si vas a subir el archivo real."],
  ["3. Si hay varios tipos de cobro en el mismo archivo, rellena Id forma de pago por fila."],
  ["4. Antes de registrar, el admin mostrara una previsualizacion y podras desmarcar filas."],
];
ayuda.getRange("A18:D21").merge(true);
ayuda.getRange("A18:D21").format = {
  wrapText: true,
  borders: { preset: "outside", style: "thin", color: "#D0D7DE" },
};

const inspect = await workbook.inspect({
  kind: "workbook,sheet,table",
  maxChars: 4000,
  tableMaxRows: 4,
  tableMaxCols: 12,
});
console.log(inspect.ndjson);

const errors = await workbook.inspect({
  kind: "match",
  searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A",
  options: { useRegex: true, maxResults: 50 },
  summary: "formula error scan",
});
console.log(errors.ndjson);

const previewCobros = await workbook.render({ sheetName: "Cobros", range: "A1:L12", scale: 1, format: "png" });
await fs.writeFile(`${outputDir}/plantilla_cobros_preview.png`, new Uint8Array(await previewCobros.arrayBuffer()));

const previewAyuda = await workbook.render({ sheetName: "Ayuda", range: "A1:D21", scale: 1, format: "png" });
await fs.writeFile(`${outputDir}/plantilla_ayuda_preview.png`, new Uint8Array(await previewAyuda.arrayBuffer()));

const xlsx = await SpreadsheetFile.exportXlsx(workbook);
await xlsx.save(`${outputDir}/Plantilla_Importacion_Cobros_GIAV.xlsx`);
