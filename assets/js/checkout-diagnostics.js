(function () {
  function onReady(callback) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback);
      return;
    }

    callback();
  }

  function createSessionKey() {
    return "cd_" + Math.random().toString(36).slice(2) + Date.now().toString(36);
  }

  function writeSessionCookie(name, value) {
    const parts = [
      encodeURIComponent(name) + "=" + encodeURIComponent(value),
      "path=/",
      "SameSite=Lax",
    ];

    if (window.location.protocol === "https:") {
      parts.push("Secure");
    }

    document.cookie = parts.join("; ");
  }

  function navigationType() {
    if (
      window.performance &&
      typeof window.performance.getEntriesByType === "function"
    ) {
      const entries = window.performance.getEntriesByType("navigation");

      if (entries && entries.length && entries[0].type) {
        return entries[0].type;
      }
    }

    if (
      window.performance &&
      window.performance.navigation &&
      typeof window.performance.navigation.type !== "undefined"
    ) {
      if (window.performance.navigation.type === 1) {
        return "reload";
      }
    }

    return "";
  }

  onReady(function () {
    if (
      typeof checkoutDiagnostics === "undefined" ||
      !checkoutDiagnostics.shouldTrack
    ) {
      return;
    }

    const checkoutForm = document.querySelector("form.checkout");

    if (!checkoutForm) {
      return;
    }

    const storageKey = "checkout_diagnostics_session";
    let sessionKey = "";

    try {
      sessionKey = window.sessionStorage.getItem(storageKey) || "";

      if (!sessionKey) {
        sessionKey = createSessionKey();
        window.sessionStorage.setItem(storageKey, sessionKey);
      }
    } catch (error) {
      sessionKey = createSessionKey();
    }

    function selectedShippingMethod() {
      const input = checkoutForm.querySelector(
        'input[name^="shipping_method"]:checked',
      );

      return input ? input.value : "";
    }

    function selectedPaymentMethod() {
      const input = checkoutForm.querySelector(
        'input[name="payment_method"]:checked',
      );

      return input ? input.value : "";
    }

    function isCompanyChecked() {
      const input = checkoutForm.querySelector("#billing_is_company");

      return !!(input && input.checked);
    }

    function currentFirstName() {
      const input = checkoutForm.querySelector("#billing_first_name");

      return input ? input.value.trim() : "";
    }

    function syncSessionField() {
      let input = checkoutForm.querySelector(
        '#' + checkoutDiagnostics.sessionFieldName,
      );

      if (!input) {
        input = document.createElement("input");
        input.type = "hidden";
        input.id = checkoutDiagnostics.sessionFieldName;
        input.name = checkoutDiagnostics.sessionFieldName;
        checkoutForm.appendChild(input);
      }

      input.value = sessionKey;
      writeSessionCookie(checkoutDiagnostics.sessionFieldName, sessionKey);
    }

    function sendEvent(eventType, eventData, useBeacon) {
      const payload = new URLSearchParams();
      payload.append("action", checkoutDiagnostics.action);
      payload.append("nonce", checkoutDiagnostics.nonce);
      payload.append("event_type", eventType);
      payload.append("session_key", sessionKey);
      payload.append("event_data", JSON.stringify(eventData || {}));

      if (useBeacon && navigator.sendBeacon) {
        const blob = new Blob([payload.toString()], {
          type: "application/x-www-form-urlencoded; charset=UTF-8",
        });

        navigator.sendBeacon(checkoutDiagnostics.ajaxUrl, blob);
        return;
      }

      fetch(checkoutDiagnostics.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: payload.toString(),
        keepalive: true,
      }).catch(function () {
        return null;
      });
    }

    syncSessionField();

    if (navigationType() === "reload") {
      sendEvent("checkout_refresh", {
        shipping_method: selectedShippingMethod(),
        payment_method: selectedPaymentMethod(),
        field_key: "",
        is_company: isCompanyChecked() ? "1" : "0",
        first_name: currentFirstName(),
      });
    }

    sendEvent("checkout_view", {
      shipping_method: selectedShippingMethod(),
      payment_method: selectedPaymentMethod(),
      field_key: "",
      is_company: isCompanyChecked() ? "1" : "0",
      first_name: currentFirstName(),
    });

    checkoutForm.addEventListener("change", function (event) {
      const shippingInput = event.target.closest('input[name^="shipping_method"]');

      if (shippingInput) {
        sendEvent("shipping_method_change", {
          shipping_method: shippingInput.value,
          payment_method: selectedPaymentMethod(),
          field_key: "",
          first_name: currentFirstName(),
        });
        return;
      }

      const paymentInput = event.target.closest('input[name="payment_method"]');

      if (paymentInput) {
        sendEvent("payment_method_change", {
          shipping_method: selectedShippingMethod(),
          payment_method: paymentInput.value,
          field_key: "",
          first_name: currentFirstName(),
        });
        return;
      }

      const companyInput = event.target.closest("#billing_is_company");

      if (companyInput) {
        sendEvent("company_toggle_change", {
          shipping_method: selectedShippingMethod(),
          payment_method: selectedPaymentMethod(),
          field_key: "billing_is_company",
          is_company: companyInput.checked ? "1" : "0",
          first_name: currentFirstName(),
        });
        return;
      }

      const firstNameInput = event.target.closest("#billing_first_name");

      if (firstNameInput && firstNameInput.value.trim()) {
        sendEvent("first_name_capture", {
          shipping_method: selectedShippingMethod(),
          payment_method: selectedPaymentMethod(),
          field_key: "billing_first_name",
          first_name: firstNameInput.value.trim(),
        });
      }
    });

    if (window.jQuery) {
      window.jQuery(document.body).on("updated_checkout", function () {
        syncSessionField();
      });
    }
  });
})();
