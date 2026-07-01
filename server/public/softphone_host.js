// softphone_host.js — served from the extension backend, used by softphone.php.
//
// softphone.php has already set the iframe's src to the softphone runtime with
// the bearer ?token= injected (server-side), and exposed window.PB_SOFTPHONE
// with the runtime origin + dial details (NO token in JS). Our job here is just
// to drive the call over the documented postMessage contract once the softphone
// signals ready.
//
// Auth note: because the iframe is authenticated by the token, this works with
// no pb.com login / session cookie / CSRF — the previous third-party-cookie and
// 401 problems are gone.

(function () {
  var MSG = {
    DIAL: "pb-softphone:dial",
    HELLO: "pb-softphone:hello",
    READY: "pb-softphone:ready",
    DIALING: "pb-softphone:dialing",
    NAVIGATE: "pb-softphone:navigate",
    CALL_COMPLETE: "pb-softphone:call-complete",
  };

  var cfg = window.PB_SOFTPHONE || {};
  var runtimeOrigin = cfg.runtimeOrigin || "";
  var number = cfg.number || "";
  var crmId = cfg.crmId || "";
  var crmName = cfg.crmName || "";

  var frame = document.getElementById("sp-frame");
  var statusEl = document.getElementById("sp-status");
  var logEl = document.getElementById("sp-log");
  var micBtn = document.getElementById("sp-mic");

  var isReady = false;
  var pendingDial = null;
  var fallback = null;

  function log(m) {
    if (!logEl) return;
    var t = new Date().toLocaleTimeString();
    logEl.textContent += "[" + t + "] " + m + "\n";
    logEl.scrollTop = logEl.scrollHeight;
  }
  function setStatus(s) { if (statusEl) statusEl.textContent = s; }

  function externalCrmData() {
    // Identity is the (crm_name, crm_id) pair — the object-type-aware namespace
    // PhoneBurner dedupes/logs on (e.g. hubspot / hubspotcompany / hubspotdeal),
    // NOT a display label.
    if (crmId && crmName) return [{ crm_id: String(crmId), crm_name: crmName }];
    return null;
  }

  function post(msg) {
    try {
      log("→ SEND " + JSON.stringify(msg));
      frame.contentWindow.postMessage(msg, runtimeOrigin);
    } catch (e) {
      log("post failed: " + e);
    }
  }

  function flush() {
    if (!pendingDial) return;
    var p = pendingDial;
    pendingDial = null;
    post(p);
  }

  function dial(num) {
    if (!num) return;
    pendingDial = { type: MSG.DIAL, number: num, external_crm_data: externalCrmData() };
    if (isReady) flush();
    else { setStatus("queued — waiting for softphone…"); log("dial queued: " + num); }
  }

  window.addEventListener("message", function (event) {
    if (event.source !== frame.contentWindow) return;
    if (event.origin !== runtimeOrigin) return; // verify origin before trusting
    var data = event.data || {};
    var type = typeof data.type === "string" ? data.type : "";
    if (type.indexOf("pb-softphone:") !== 0) return;
    log("← RECV " + JSON.stringify(data));
    if (type === MSG.READY) {
      isReady = true;
      setStatus("ready");
      if (fallback) { clearTimeout(fallback); fallback = null; }
      flush();
    } else if (type === MSG.DIALING) {
      setStatus("dialing…");
      // The softphone is dialing — mic must be working. Flip the button
      // into a status indicator regardless of what the Permissions API says.
      markMicActive();
    } else if (type === MSG.CALL_COMPLETE) {
      setStatus("ready");
    }
  });

  // The iframe src is set server-side, so it may already be loading/loaded.
  // ready is also BROADCAST on load (handled above), so we don't depend on
  // catching this load event — it's just an extra nudge + a fallback timer.
  frame.addEventListener("load", function () {
    log("iframe loaded");
    post({ type: MSG.HELLO });
    if (fallback) clearTimeout(fallback);
    fallback = setTimeout(function () {
      if (pendingDial && !isReady) { log("ready not received — sending anyway"); flush(); }
    }, 2500);
  });
  // Also greet immediately in case the iframe already finished loading.
  post({ type: MSG.HELLO });

  // ── Mic status footer ────────────────────────────────────────────────
  // Two UI states share the same #sp-mic element:
  //
  //   BUTTON  "🎤 Enable microphone for calls" — first-run helper. Clicking
  //           calls getUserMedia to prime Chrome's mic permission prompt so
  //           the softphone can dial cleanly on its first attempt.
  //   STATUS  "🎤 Microphone active" — non-clickable indicator confirming
  //           the mic is granted (no explicit action needed from the user).
  //
  // We flip button → status when ANY of these signals fire:
  //   • enumerateDevices() returns audioinput devices with populated labels
  //     (spec: labels are only exposed after mic grant on the parent origin)
  //   • Permissions API onchange event → "granted"
  //   • The softphone iframe posts "pb-softphone:dialing" (proves mic works
  //     regardless of where Chrome tracks the grant — parent or delegated)
  //   • The user clicks the button and getUserMedia resolves successfully
  //
  // The dialing-event signal is our fallback for when Chrome's permission
  // tracking is quirky (delegated permission via allow="microphone" doesn't
  // always show up as "granted" at the parent origin, so enumerateDevices
  // returns unlabeled devices even though the softphone dials fine).
  function markMicActive() {
    if (!micBtn || micBtn.dataset.pbMicActive === "1") return;
    micBtn.dataset.pbMicActive = "1";
    micBtn.textContent = "🎤 Microphone active";
    micBtn.disabled = true;
    micBtn.style.cursor = "default";
    micBtn.style.opacity = "0.7";
  }

  function probeMicPermission() {
    if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
      navigator.mediaDevices
        .enumerateDevices()
        .then(function (devices) {
          var hasLabeledMic = devices.some(function (d) {
            return d.kind === "audioinput" && d.label && d.label.length > 0;
          });
          if (hasLabeledMic) markMicActive();
        })
        .catch(function () {});
    }
    if (navigator.permissions && navigator.permissions.query) {
      try {
        navigator.permissions
          .query({ name: "microphone" })
          .then(function (status) {
            if (status.state === "granted") markMicActive();
            status.onchange = function () {
              if (status.state === "granted") markMicActive();
            };
          })
          .catch(function () {});
      } catch (e) {}
    }
  }

  if (micBtn) {
    probeMicPermission();

    micBtn.addEventListener("click", function () {
      if (micBtn.dataset.pbMicActive === "1") return; // status indicator, not a button
      log("requesting microphone…");
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        log("✗ getUserMedia unavailable");
        return;
      }
      navigator.mediaDevices
        .getUserMedia({ audio: true })
        .then(function (s) {
          s.getTracks().forEach(function (t) { t.stop(); });
          log("✓ microphone granted");
          markMicActive();
        })
        .catch(function (e) {
          log("✗ mic error: " + ((e && e.name) || "") + " " + ((e && e.message) || e));
        });
    });
  }

  // Boot
  if (!runtimeOrigin) {
    setStatus("missing runtime");
    log("no runtime origin — nothing to drive");
    return;
  }
  if (!cfg.authed) {
    setStatus("not authenticated");
    log("no bearer token resolved (code missing/expired?) — softphone may show login");
  } else {
    setStatus("connecting…");
  }
  if (number) dial(number);
})();
