(function () {
  "use strict";

  function getI18n() {
    return window.CasanovaPortalI18n || null;
  }

  function interpolate(template, vars) {
    return String(template || "").replace(/\{(\w+)\}/g, function (_, name) {
      return Object.prototype.hasOwnProperty.call(vars || {}, name) ? vars[name] : "";
    });
  }

  function tt(literal, fallback) {
    var runtime = getI18n();
    if (runtime && typeof runtime.tt === "function") {
      return runtime.tt(literal, fallback);
    }
    return fallback == null ? String(literal || "") : String(fallback || "");
  }

  function ttf(literal, vars, fallback) {
    var runtime = getI18n();
    if (runtime && typeof runtime.ttf === "function") {
      return runtime.ttf(literal, vars || {}, fallback);
    }
    return interpolate(tt(literal, fallback), vars || {});
  }

  function qs(root, sel) {
    return root.querySelector(sel);
  }

  function qsa(root, sel) {
    return Array.prototype.slice.call(root.querySelectorAll(sel));
  }

  function show(el) {
    if (el) el.classList.remove("casanova-link-account__form--hidden", "casanova-link-account__alert--hidden");
  }

  function hide(el) {
    if (!el) return;
    if (el.hasAttribute("data-casanova-linking-alert")) {
      el.classList.add("casanova-link-account__alert--hidden");
      return;
    }
    el.classList.add("casanova-link-account__form--hidden");
  }

  function setBusy(btn, busy) {
    if (!btn) return;
    btn.disabled = !!busy;
    btn.classList.toggle("is-busy", !!busy);
  }

  function alertBox(root, type, msg) {
    var box = qs(root, "[data-casanova-linking-alert]");
    if (!box) return;
    if (!msg) {
      hide(box);
      box.textContent = "";
      box.className = "casanova-link-account__alert";
      return;
    }
    box.textContent = msg;
    box.className = "casanova-link-account__alert casanova-link-account__alert--" + (type || "info");
    show(box);
  }

  function postJson(url, data) {
    return fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": (window.CASANOVA_LINKING && window.CASANOVA_LINKING.nonce) || "",
      },
      credentials: "same-origin",
      body: JSON.stringify(data || {}),
    }).then(function (res) {
      return res
        .json()
        .catch(function () {
          return {};
        })
        .then(function (json) {
          return { ok: res.ok, status: res.status, json: json };
        });
    });
  }

  function initOne(root) {
    var step1 = qs(root, "[data-casanova-linking-step='1']");
    var step2 = qs(root, "[data-casanova-linking-step='2']");
    if (!step1 || !step2) return;

    var identifierTypeInput = qs(step1, "[data-casanova-linking-identifier-type]");
    var identifierLabel = qs(step1, "[data-casanova-linking-identifier-label]");
    var identifierInput = qs(step1, "[data-casanova-linking-identifier]");
    var otpInput = qs(step2, "input[name='otp']");
    var sentHint = qs(step2, "[data-casanova-linking-sent-hint]");

    var submitBtn = qs(step1, "[data-casanova-linking-submit]");
    var verifyBtn = qs(step2, "[data-casanova-linking-verify]");
    var resendBtn = qs(step2, "[data-casanova-linking-resend]");

    var base = (window.CASANOVA_LINKING && window.CASANOVA_LINKING.restUrl) || "";
    var redirectTo = (window.CASANOVA_LINKING && window.CASANOVA_LINKING.redirectTo) || "";

    var resendCooldown = 0;
    var resendTimer = null;
    var identifierDrafts = {
      dni: (identifierInput && identifierInput.value) ? identifierInput.value : "",
      giav_id: "",
    };
    var currentIdentifierType = "dni";

    function getIdentifierType() {
      return identifierTypeInput && identifierTypeInput.value === "giav_id" ? "giav_id" : "dni";
    }

    function getIdentifierConfig(type) {
      if (type === "giav_id") {
        return {
          label: tt("ID de Usuario"),
          placeholder: tt("Ej.: 12345"),
          inputmode: "numeric",
          pattern: "[0-9]*",
          emptyMessage: tt("Introduce tu ID de Usuario."),
        };
      }

      return {
        label: tt("DNI"),
        placeholder: tt("Ej.: 12345678Z"),
        inputmode: "text",
        pattern: "",
        emptyMessage: tt("Introduce tu DNI."),
      };
    }

    function syncIdentifierUi(swapValue) {
      var nextType = getIdentifierType();
      var config = getIdentifierConfig(nextType);

      if (!identifierInput) return config;

      if (swapValue && nextType !== currentIdentifierType) {
        identifierDrafts[currentIdentifierType] = identifierInput.value || "";
        identifierInput.value = identifierDrafts[nextType] || "";
      }

      currentIdentifierType = nextType;

      if (identifierLabel) {
        identifierLabel.textContent = config.label;
      }

      identifierInput.placeholder = config.placeholder;
      identifierInput.setAttribute("inputmode", config.inputmode);
      if (config.pattern) {
        identifierInput.setAttribute("pattern", config.pattern);
      } else {
        identifierInput.removeAttribute("pattern");
      }

      return config;
    }

    function startCooldown(seconds) {
      resendCooldown = seconds || 60;
      if (!resendBtn) return;

      resendBtn.disabled = true;
      resendBtn.textContent = ttf("Reenviar código ({seconds}s)", { seconds: resendCooldown });
      if (resendTimer) clearInterval(resendTimer);

      resendTimer = setInterval(function () {
        resendCooldown -= 1;
        if (resendCooldown <= 0) {
          clearInterval(resendTimer);
          resendTimer = null;
          resendBtn.disabled = false;
          resendBtn.textContent = tt("Reenviar código");
          return;
        }
        resendBtn.textContent = ttf("Reenviar código ({seconds}s)", { seconds: resendCooldown });
      }, 1000);
    }

    function toStep2(emailMasked) {
      hide(step1);
      show(step2);
      alertBox(root, null, null);

      if (sentHint) {
        sentHint.textContent = emailMasked
          ? ttf("Código enviado a {email}. Caduca en 10 minutos.", { email: emailMasked })
          : tt("El código caduca en 10 minutos.");
      }

      if (otpInput) otpInput.focus();
      startCooldown(60);
    }

    syncIdentifierUi(false);
    if (identifierTypeInput) {
      identifierTypeInput.addEventListener("change", function () {
        syncIdentifierUi(true);
      });
    }

    step1.addEventListener("submit", function (e) {
      e.preventDefault();
      alertBox(root, null, null);

      var identifierType = getIdentifierType();
      var identifierConfig = syncIdentifierUi(false);
      var identifier = (identifierInput && identifierInput.value) ? identifierInput.value.trim() : "";
      if (!identifier) {
        alertBox(root, "error", identifierConfig.emptyMessage);
        return;
      }

      setBusy(submitBtn, true);

      postJson(base + "/linking/request", { identifierType: identifierType, identifier: identifier })
        .then(function (r) {
          var j = r.json || {};
          if (!r.ok || !j.ok) {
            alertBox(root, "error", j.message || tt("No se ha podido enviar el código."));
            return;
          }
          toStep2(j.emailMasked || "");
        })
        .catch(function () {
          alertBox(root, "error", tt("No se ha podido enviar el código."));
        })
        .finally(function () {
          setBusy(submitBtn, false);
        });
    });

    step2.addEventListener("submit", function (e) {
      e.preventDefault();
      alertBox(root, null, null);

      var identifierType = getIdentifierType();
      var identifierConfig = syncIdentifierUi(false);
      var identifier = (identifierInput && identifierInput.value) ? identifierInput.value.trim() : "";
      var otp = (otpInput && otpInput.value) ? otpInput.value.trim() : "";
      if (!identifier) {
        alertBox(root, "error", identifierConfig.emptyMessage);
        return;
      }
      if (!otp) {
        alertBox(root, "error", tt("Introduce el código que te hemos enviado."));
        return;
      }

      setBusy(verifyBtn, true);

      postJson(base + "/linking/verify", { identifierType: identifierType, identifier: identifier, otp: otp })
        .then(function (r) {
          var j = r.json || {};
          if (!r.ok || !j.ok) {
            alertBox(root, "error", j.message || tt("El código no es válido."));
            return;
          }
          window.location.href = j.redirectTo || redirectTo || "/portal-app/";
        })
        .catch(function () {
          alertBox(root, "error", tt("No se ha podido validar el código."));
        })
        .finally(function () {
          setBusy(verifyBtn, false);
        });
    });

    if (resendBtn) {
      resendBtn.addEventListener("click", function () {
        if (resendBtn.disabled) return;

        alertBox(root, null, null);
        var identifierType = getIdentifierType();
        var identifierConfig = syncIdentifierUi(false);
        var identifier = (identifierInput && identifierInput.value) ? identifierInput.value.trim() : "";
        if (!identifier) {
          alertBox(root, "error", identifierConfig.emptyMessage);
          return;
        }

        setBusy(resendBtn, true);
        postJson(base + "/linking/request", { identifierType: identifierType, identifier: identifier })
          .then(function (r) {
            var j = r.json || {};
            if (!r.ok || !j.ok) {
              alertBox(root, "error", j.message || tt("No se ha podido reenviar el código."));
              return;
            }
            if (sentHint) {
              sentHint.textContent = j.emailMasked
                ? ttf("Código reenviado a {email}. Caduca en 10 minutos.", { email: j.emailMasked })
                : tt("Código reenviado. Caduca en 10 minutos.");
            }
            startCooldown(60);
          })
          .catch(function () {
            alertBox(root, "error", tt("No se ha podido reenviar el código."));
          })
          .finally(function () {
            setBusy(resendBtn, false);
          });
      });
    }
  }

  function boot() {
    qsa(document, "[data-casanova-link-account]").forEach(initOne);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
