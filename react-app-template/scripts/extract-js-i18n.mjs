import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const appRoot = path.resolve(scriptDir, "..");
const pluginRoot = path.resolve(appRoot, "..");

const sourceEntries = [
  path.join(appRoot, "src"),
  path.join(pluginRoot, "assets", "link-account.js"),
  path.join(pluginRoot, "assets", "portal.js"),
];

const outputFile = path.join(pluginRoot, "includes", "generated", "js-i18n-literals.php");
const supportedExtensions = new Set([".js", ".jsx"]);
const literalPattern = /\bttf?\(\s*(["'`])((?:\\.|(?!\1)[\s\S])*?)\1/g;

function walk(entryPath) {
  if (!fs.existsSync(entryPath)) return [];

  const stats = fs.statSync(entryPath);
  if (stats.isFile()) return [entryPath];

  const files = [];
  for (const item of fs.readdirSync(entryPath, { withFileTypes: true })) {
    const resolved = path.join(entryPath, item.name);

    if (item.isDirectory()) {
      if (item.name === "node_modules" || item.name === "dist") continue;
      files.push(...walk(resolved));
      continue;
    }

    if (item.isFile() && supportedExtensions.has(path.extname(item.name))) {
      files.push(resolved);
    }
  }

  return files;
}

function unescapeLiteral(value, quote) {
  if (quote === "`" && value.includes("${")) {
    return null;
  }

  const source = quote === "`" ? "`" + value + "`" : "\"" + value.replace(/"/g, "\\\"") + "\"";
  try {
    return JSON.parse(source);
  } catch {
    if (quote === "'") {
      try {
        return JSON.parse("\"" + value.replace(/\\/g, "\\\\").replace(/"/g, "\\\"").replace(/\\'/g, "'") + "\"");
      } catch {
        return null;
      }
    }
    return null;
  }
}

function phpSingleQuoted(value) {
  return "'" + value.replace(/\\/g, "\\\\").replace(/'/g, "\\'") + "'";
}

const literals = new Set();

for (const entry of sourceEntries) {
  for (const file of walk(entry)) {
    const source = fs.readFileSync(file, "utf8");
    literalPattern.lastIndex = 0;

    let match;
    while ((match = literalPattern.exec(source)) !== null) {
      const literal = unescapeLiteral(match[2], match[1]);
      if (!literal) continue;

      const normalized = String(literal).trim();
      if (!normalized) continue;

      literals.add(normalized);
    }
  }
}

const sorted = Array.from(literals).sort((left, right) => left.localeCompare(right, "es"));

const outputLines = [
  "<?php",
  "",
  "return [",
  ...sorted.map((literal) => {
    const quoted = phpSingleQuoted(literal);
    return "  " + quoted + " => __(" + quoted + ", 'casanova-portal'),";
  }),
  "];",
  "",
];

fs.mkdirSync(path.dirname(outputFile), { recursive: true });
fs.writeFileSync(outputFile, outputLines.join("\n"), "utf8");
