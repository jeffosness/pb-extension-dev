// softphone_host.js — served from the extension backend
// (e.g. https://extension.phoneburner.biz/softphone.html).
//
// WHY THIS PAGE EXISTS
// A WebRTC softphone needs microphone access, which a chrome-extension side
// panel cannot get, and not every CRM will let us embed an iframe in their page.
// So we host the softphone on a normal first-party-capable web page that WE
// control and route every click-to-call through it. Benefits:
//   • One iframe origin to register with PhoneBurner (this page's origin),
//     regardless of how many CRMs the extension supports (incl. L1/unknown).
//   • Mic works (normal https page), and we control the grant UX.
//   • A persistent window can later receive inbound calls.
//
// The extension opens this page in a window with the dial details in the URL
// and we relay them to the softphone over the documented postMessage contract.
//
// NOTE (auth): today the embedded softphone authenticates via the PhoneBurner
// session cookie. A cleaner future option is to authenticate with a bearer
// token / single-use code the extension already holds for the account, passed
// here as a param and handed to the softphone — pending PhoneBurner support for
// token-based softphone auth. The `auth` param below is reserved for that.

(function () {
  var MSG = {
    DIAL: "pb-softphone:dial",
    HELLO: "pb-softphone:hello",
    READY: "pb-softphone:ready",
    DIALING: "pb-softphone:dialing",
    NAVIGATE: "pb-softphone:navigate",
    CALL_COMPLETE: "pb-softphone:call-complete",
  };

  var frame = document.getElementById("sp-frame");
  var statusEl = document.getElementById("sp-status");
  var logEl = document.getElementById("sp-log");
  var micBtn = document.getElementById("sp-mic");

  var params = new URLSearchParams(location.search);
  var runtimeUrl = params.get("runtime") || "";
  var number = params.get("number") || "";
  var recordId = params.get("recordId") || null;
  var recordType = params.get("recordType") || null;
  var crmName = params.get("crmName") || null;
  // var authCode = params.get("auth") || null; // reserved for token auth

  var runtimeOrigin = "";
  try { runtimeOrigin = new URL(runtimeUrl).origin; } catch (e) {}

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
    if (recordId && crmName) return [{ crm_id: String(recordId), crm_name: crmName }];
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
    pendingDial = {
      type: MSG.DIAL,
      number: num,
      recordId: recordId,
      recordType: recordType,
      external_crm_data: externalCrmData(),
    };
    if (isReady) flush();
    else { setStatus("queued — waiting for softphone…"); log("dial queued: " + num); }
  }

  window.addEventListener("message", function (event) {
    if (event.source !== frame.contentWindow) return;
    if (event.origin !== runtimeOrigin) return;
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

  frame.addEventListener("load", function () {
    log("iframe loaded");
    post({ type: MSG.HELLO });
    if (fallback) clearTimeout(fallback);
    fallback = setTimeout(function () {
      if (pendingDial && !isReady) { log("ready not received — sending anyway"); flush(); }
    }, 2500);
  });

  if (micBtn) {
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
          micBtn.textContent = "🎤 Microphone enabled";
          micBtn.disabled = true;
        })
        .catch(function (e) {
          log("✗ mic error: " + ((e && e.name) || "") + " " + ((e && e.message) || e));
        });
    });
  }

  // Boot
  if (!runtimeUrl) {
    setStatus("missing runtime URL");
    log("no ?runtime param — nothing to load");
    return;
  }
  setStatus("connecting…");
  log("loading " + runtimeUrl);
  frame.src = runtimeUrl;
  if (number) dial(number);
})();
