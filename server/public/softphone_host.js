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

  function hideMicButton() {
    if (micBtn) micBtn.style.display = "none";
  }

  // Detect whether the origin already has mic permission using two paths:
  //
  //   1. enumerateDevices — the reliable signal. If the browser returns
  //      populated `label` fields on audioinput devices, mic permission is
  //      granted (spec: labels are only exposed after a getUserMedia grant).
  //   2. Permissions API — subscribe to onchange so an in-session grant
  //      hides the button in real time (enumerateDevices only runs once).
  //      Chrome's permissions.query sometimes returns "prompt" for
  //      microphone even after grant, so we can't rely on the initial
  //      value alone — but the onchange callback still fires reliably.
  function detectMicPermissionAndHide() {
    if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
      navigator.mediaDevices
        .enumerateDevices()
        .then(function (devices) {
          var hasLabeledMic = devices.some(function (d) {
            return d.kind === "audioinput" && d.label && d.label.length > 0;
          });
          if (hasLabeledMic) hideMicButton();
        })
        .catch(function () {});
    }
    if (navigator.permissions && navigator.permissions.query) {
      try {
        navigator.permissions
          .query({ name: "microphone" })
          .then(function (status) {
            if (status.state === "granted") hideMicButton();
            status.onchange = function () {
              if (status.state === "granted") hideMicButton();
            };
          })
          .catch(function () {});
      } catch (e) {}
    }
  }

  if (micBtn) {
    detectMicPermissionAndHide();

    micBtn.addEventListener("click", function () {
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
          hideMicButton();
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
