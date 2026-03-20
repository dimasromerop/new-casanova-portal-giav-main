(function () {
  "use strict";

  function getI18n() {
    return window.CasanovaPortalI18n || null;
  }

  function tt(literal, fallback) {
    var runtime = getI18n();
    if (runtime && typeof runtime.tt === "function") {
      return runtime.tt(literal, fallback);
    }
    return fallback == null ? String(literal || "") : String(fallback || "");
  }

  function closest(el, sel) {
    return el && el.closest ? el.closest(sel) : null;
  }

  function isModifiedClick(e) {
    return !!(e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1);
  }

  function getDetailContainer() {
    var detail = document.querySelector(".casanova-detail");
    if (detail) return detail;

    var portal = document.querySelector(".casanova-portal");
    if (portal) return portal;

    return document.body;
  }

  function ensureLoadingOverlay(container) {
    if (container.querySelector(".casanova-loading") || container.querySelector(".casanova-tabs-loading")) {
      return;
    }

    var overlay = document.createElement("div");
    overlay.className = "casanova-loading";
    overlay.innerHTML =
      '<div class="casanova-loading__card">' +
        '<div class="casanova-loading__bar"></div>' +
        '<div class="casanova-loading__text">' + tt("Cargando…") + "</div>" +
      "</div>";

    container.appendChild(overlay);
  }

  function scrollDetailIntoView(detail) {
    try {
      if (!detail) return;
      var isMobile = window.matchMedia && window.matchMedia("(max-width: 900px)").matches;
      if (!isMobile) return;

      var rect = detail.getBoundingClientRect();
      if (rect.top < 0 || rect.top > (window.innerHeight * 0.35)) {
        window.scrollTo(0, Math.max(0, window.scrollY + rect.top - 16));
      }
    } catch (_) {}
  }

  document.addEventListener("click", function (e) {
    var toggle = closest(e.target, "[data-casanova-toggle-details]");
    if (toggle) {
      var details = closest(toggle, "details");
      if (details) details.open = !details.open;
      e.preventDefault();
      e.stopPropagation();
      return;
    }

    var openBtn = closest(e.target, "[data-casanova-open-drawer]");
    if (openBtn) {
      document.documentElement.classList.add("casanova-drawer-open");
      e.preventDefault();
      return;
    }

    var closeBtn = closest(e.target, "[data-casanova-close-drawer]");
    if (closeBtn) {
      document.documentElement.classList.remove("casanova-drawer-open");
      e.preventDefault();
      return;
    }

    var navLink = closest(e.target, "[data-casanova-nav-link]");
    if (navLink) {
      if (isModifiedClick(e) || navLink.getAttribute("target") === "_blank") return;
      if (!navLink.classList.contains("is-active")) {
        navLink.classList.add("is-loading");
      }
      document.documentElement.classList.remove("casanova-drawer-open");
      return;
    }

    var expLink = closest(e.target, "[data-casanova-expediente-link]");
    if (expLink) {
      if (isModifiedClick(e) || expLink.getAttribute("target") === "_blank") return;

      var target = getDetailContainer();
      if (target) {
        scrollDetailIntoView(target);
        if (!target.classList.contains("casanova-detail")) {
          ensureLoadingOverlay(target);
        }
        target.classList.add("is-loading");
        target.classList.add("casanova-is-loading");
      }
      document.documentElement.classList.remove("casanova-drawer-open");
    }
  });

  document.addEventListener("change", function (e) {
    if (!(e.target && e.target.id === "periodo-select")) return;

    var form = e.target.form;
    if (!form) return;

    var portal = document.querySelector(".casanova-portal") || document.querySelector(".casanova-main") || document.body;
    ensureLoadingOverlay(portal);
    portal.classList.add("is-loading");
    portal.classList.add("casanova-is-loading");

    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        form.submit();
      });
    });
  });

  function clearLoading() {
    document.documentElement.classList.remove("casanova-drawer-open");
    var loadingEls = document.querySelectorAll(".is-loading, .casanova-is-loading");
    loadingEls.forEach(function (el) {
      el.classList.remove("is-loading");
      el.classList.remove("casanova-is-loading");
    });
  }

  document.addEventListener("DOMContentLoaded", clearLoading);
  window.addEventListener("pageshow", clearLoading);
})();

document.addEventListener("DOMContentLoaded", function () {
  function tt(literal, fallback) {
    var runtime = window.CasanovaPortalI18n || null;
    if (runtime && typeof runtime.tt === "function") {
      return runtime.tt(literal, fallback);
    }
    return fallback == null ? String(literal || "") : String(fallback || "");
  }

  var forms = document.querySelectorAll("form[action*=\"casanova_update_address\"], .casanova-portal form");
  forms.forEach(function (form) {
    form.addEventListener("submit", function () {
      if (form.querySelector("#periodo-select")) return;

      var btn = form.querySelector(".casanova-btn-submit") || form.querySelector("button[type=\"submit\"], input[type=\"submit\"]");
      if (!btn) return;

      btn.classList.add("is-loading");
      btn.disabled = true;

      var label = btn.querySelector(".label");
      if (label) label.textContent = tt("Guardando…");
    });
  });
});

(function () {
  var WRAP_ID = "casanova-exp-tabs";
  var STORAGE_KEY = "casanova_active_tab_" + WRAP_ID;

  function wrap() {
    return document.getElementById(WRAP_ID);
  }

  function buttons(root) {
    return root ? Array.from(root.querySelectorAll("[role=\"tab\"]")) : [];
  }

  function tabId(btn) {
    if (!btn) return "";
    return btn.getAttribute("aria-controls") || (btn.getAttribute("href") || "").replace("#", "") || "";
  }

  function activateById(id) {
    var root = wrap();
    if (!root || !id) return;

    var btn = buttons(root).find(function (candidate) {
      return tabId(candidate) === id;
    });
    if (btn) btn.click();
  }

  document.addEventListener("DOMContentLoaded", function () {
    var root = wrap();
    if (!root) return;

    var fromHash = (location.hash || "").replace("#", "").trim();
    var fromStore = sessionStorage.getItem(STORAGE_KEY) || "";
    if (fromHash) activateById(fromHash);
    else if (fromStore) activateById(fromStore);
  });

  document.addEventListener("click", function (e) {
    var root = wrap();
    if (!root) return;

    var btn = e.target.closest("#" + WRAP_ID + " [role=\"tab\"]");
    if (!btn) return;

    var id = tabId(btn);
    if (!id) return;

    sessionStorage.setItem(STORAGE_KEY, id);
    if (history && history.replaceState) history.replaceState(null, "", "#" + id);
    else location.hash = id;
  });

  document.addEventListener("click", function (e) {
    var link = e.target.closest("a[href]");
    if (!link) return;

    var href = link.getAttribute("href") || "";
    if (!href || href.includes("#") || !href.includes("expediente=")) return;

    var id = (location.hash || "").replace("#", "").trim() || sessionStorage.getItem(STORAGE_KEY) || "";
    if (!id) return;

    link.setAttribute("href", href + "#" + id);
  }, true);
})();
