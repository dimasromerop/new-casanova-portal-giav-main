export function api(path, options = {}) {
  const base = window.CasanovaPortal?.restUrl;
  const nonce = window.CasanovaPortal?.nonce;
  const method = options.method ? options.method.toUpperCase() : "GET";
  const headers = {
    "X-WP-Nonce": nonce,
    ...(options.headers || {}),
  };

  const init = {
    method,
    credentials: "same-origin",
    headers,
  };

  if (options.body !== undefined) {
    if (options.body instanceof FormData) {
      init.body = options.body;
    } else {
      headers["Content-Type"] = headers["Content-Type"] || "application/json";
      init.body = typeof options.body === "string" ? options.body : JSON.stringify(options.body);
    }
  }

  return fetch(base + path, init).then(async (response) => {
    const json = await response.json().catch(() => ({}));
    if (response.ok && json?.status === "degraded") {
      throw json;
    }
    if (!response.ok) throw json;
    return json;
  });
}
