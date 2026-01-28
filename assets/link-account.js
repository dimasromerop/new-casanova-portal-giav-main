(function () {
  "use strict";

  function qs(root, sel) {
    return root.querySelector(sel);
  }

  function qsa(root, sel) {
    return Array.prototype.slice.call(root.querySelectorAll(sel));
  }

  function show(el) {
    if (el) el.style.display = "";
  }

  function hide(el) {
    if (el) el.style.display = "none";
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

    var dniInput = qs(step1, "input[name='dni']");
    var otpInput = qs(step2, "input[name='otp']");
    var sentHint = qs(step2, "[data-casanova-linking-sent-hint]");

    var submitBtn = qs(step1, "[data-casanova-linking-submit]");
    var verifyBtn = qs(step2, "[data-casanova-linking-verify]");
    var resendBtn = qs(step2, "[data-casanova-linking-resend]");

    var base = (window.CASANOVA_LINKING && window.CASANOVA_LINKING.restUrl) || "";
    var redirectTo = (window.CASANOVA_LINKING && window.CASANOVA_LINKING.redirectTo) || "";

    var resendCooldown = 0;
    var resendTimer = null;

    function startCooldown(seconds) {
      resendCooldown = seconds || 60;
      if (!resendBtn) return;
      resendBtn.disabled = true;
      resendBtn.textContent = "Reenviar código (" + resendCooldown + "s)";
      if (resendTimer) clearInterval(resendTimer);
      resendTimer = setInterval(function () {
        resendCooldown -= 1;
        if (resendCooldown <= 0) {
          clearInterval(resendTimer);
          resendTimer = null;
          resendBtn.disabled = false;
          resendBtn.textContent = "Reenviar código";
          return;
        }
        resendBtn.textContent = "Reenviar código (" + resendCooldown + "s)";
      }, 1000);
    }

    function toStep2(emailMasked) {
      hide(step1);
      show(step2);
      alertBox(root, null, null);
      if (sentHint) {
        var msg = "";
        if (emailMasked) msg = "Código enviado a " + emailMasked + ".";
        sentHint.textContent = msg ? msg + " Caduca en 10 minutos." : "";
      }
      if (otpInput) otpInput.focus();
      startCooldown(60);
    }

    step1.addEventListener("submit", function (e) {
      e.preventDefault();
      alertBox(root, null, null);

      var dni = (dniInput && dniInput.value) ? dniInput.value.trim() : "";
      if (!dni) {
        alertBox(root, "error", "Introduce tu DNI.");
        return;
      }

      setBusy(submitBtn, true);

      postJson(base + "/linking/request", { dni: dni })
        .then(function (r) {
          var j = r.json || {};
          if (!r.ok || !j.ok) {
            alertBox(root, "error", j.message || "No se ha podido enviar el código.");
            return;
          }
          toStep2(j.emailMasked || "");
        })
        .catch(function () {
          alertBox(root, "error", "No se ha podido enviar el código.");
        })
        .finally(function () {
          setBusy(submitBtn, false);
        });
    });

    step2.addEventListener("submit", function (e) {
      e.preventDefault();
      alertBox(root, null, null);

      var dni = (dniInput && dniInput.value) ? dniInput.value.trim() : "";
      var otp = (otpInput && otpInput.value) ? otpInput.value.trim() : "";
      if (!dni) {
        alertBox(root, "error", "Introduce tu DNI.");
        return;
      }
      if (!otp) {
        alertBox(root, "error", "Introduce el código que te hemos enviado.");
        return;
      }

      setBusy(verifyBtn, true);

      postJson(base + "/linking/verify", { dni: dni, otp: otp })
        .then(function (r) {
          var j = r.json || {};
          if (!r.ok || !j.ok) {
            alertBox(root, "error", j.message || "El código no es válido.");
            return;
          }
          window.location.href = j.redirectTo || redirectTo || "/portal-app/";
        })
        .catch(function () {
          alertBox(root, "error", "No se ha podido validar el código.");
        })
        .finally(function () {
          setBusy(verifyBtn, false);
        });
    });

    if (resendBtn) {
      resendBtn.addEventListener("click", function () {
        if (resendBtn.disabled) return;
        alertBox(root, null, null);
        var dni = (dniInput && dniInput.value) ? dniInput.value.trim() : "";
        if (!dni) {
          alertBox(root, "error", "Introduce tu DNI.");
          return;
        }

        setBusy(resendBtn, true);
        postJson(base + "/linking/request", { dni: dni })
          .then(function (r) {
            var j = r.json || {};
            if (!r.ok || !j.ok) {
              alertBox(root, "error", j.message || "No se ha podido reenviar el código.");
              return;
            }
            if (sentHint) {
              sentHint.textContent = (j.emailMasked ? "Código reenviado a " + j.emailMasked + "." : "Código reenviado.") + " Caduca en 10 minutos.";
            }
            startCooldown(60);
          })
          .catch(function () {
            alertBox(root, "error", "No se ha podido reenviar el código.");
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
