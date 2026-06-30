// softphone_config.js — Single source of truth for the embedded PhoneBurner
// softphone (click-to-call) integration.
//
// ─────────────────────────────────────────────────────────────────────────
// DUAL-APP MODEL (dev vs prod)
//
// Each PhoneBurner softphone integration registration defines ONE webhook
// URL. Because our dev backend and prod backend live at different URLs
// (extension-dev.phoneburner.biz vs extension.phoneburner.biz), they need
// TWO separate PB softphone registrations:
//
//   • DEV  — slug below; webhook → extension-dev.phoneburner.biz
//   • PROD — slug TBD; webhook → extension.phoneburner.biz
//
// Each registration has its OWN HMAC secret. The matching secret lives in
// the corresponding backend's config.php (never in extension code).
//
// Selection happens at runtime via the env toggle: CURRENT_ENV in
// background.js picks the slug below. Both registrations are kept active so
// switching dev↔prod in the popup just changes which softphone iframe the
// extension loads.
// ─────────────────────────────────────────────────────────────────────────
//
// PARTNER INTEGRATION NOTE
//
// Everything in PARTNER_SOFTPHONE is what a 3rd-party partner changes to
// stand up their own click-to-call. NOTHING ELSE in the click-to-call code
// is partner-specific — the postMessage message names, the iframe
// permissions, and the HMAC signature scheme are fixed by PhoneBurner.
//
//   slug          Per-env softphone registration slug. The full runtime URL
//                 for a given env is: {runtimeBase}/softphone/{slug}/
//   runtimeBase   PhoneBurner platform host the softphone app is served
//                 from. Both dev and prod registrations live on PB's prod
//                 platform host.
//   defaultEnv    Which env to use when no override is stored. Matches the
//                 extension's DEFAULT_ENV.
//
// You ALSO configure on the PhoneBurner side (not here), per env:
//   • softphone_call_done webhook URL — the backend host that handles call
//     completions (e.g. https://extension-dev.phoneburner.biz)
//   • Allowed iframe origins — only the backend host needs to be registered,
//     NOT the chrome-extension:// origin. The softphone iframe's parent in
//     our architecture is softphone.php on our backend, so PB only ever sees
//     the backend host as the parent origin. The extension ID is invisible
//     to PB and intentionally not referenced anywhere in this codebase.
//   • HMAC secret — lives only on your server's config.php, never here.
//
// For ad-hoc testing, chrome.storage.local["pb_softphone_runtime_override"]
// (a full URL) wins over everything below.
// ─────────────────────────────────────────────────────────────────────────

const PARTNER_SOFTPHONE = {
  // Both dev and prod softphone registrations live on PhoneBurner's prod
  // platform host. Only the slug (and webhook URL on PB's side) differs.
  runtimeBase: "https://www.phoneburner.com",

  // Per-env registration slugs. Each is a separate PhoneBurner softphone
  // integration with its own webhook URL + HMAC secret.
  //
  //   dev:  "Chrome Extension Dev"  — webhook → extension-dev backend
  //   prod: "Chrome Extension"      — webhook → extension (prod) backend
  //
  // The matching HMAC secret for each env lives in that backend's config.php
  // as SOFTPHONE_HMAC_SECRET. The slug itself is a public identifier (it
  // appears in the iframe URL) so committing it here is safe.
  slug: {
    dev: "d642dcb35bc4474e0159561acedb234e3b041c58",
    prod: "c3687a4fcd17437b16d6c31571b9ef96fce2af61",
  },

  // Which env to use when nothing is overridden. Matches background.js
  // DEFAULT_ENV. While click-to-call is dev-only, the practical default is
  // controlled by clickToCallEnabled() in background.js.
  defaultEnv: "prod",
};

// Fixed postMessage contract — DO NOT change these per partner.
const SOFTPHONE_MESSAGES = {
  DIAL: "pb-softphone:dial", // extension → softphone
  HELLO: "pb-softphone:hello", // extension → softphone (ask for a ready reply)
  READY: "pb-softphone:ready", // softphone → extension
  DIALING: "pb-softphone:dialing", // softphone → extension
  NAVIGATE: "pb-softphone:navigate", // softphone → extension
  CALL_COMPLETE: "pb-softphone:call-complete", // softphone → extension
};

// Build the runtime URL for a given env. Returns empty string if no slug is
// registered for that env (e.g. before the prod slug is provisioned).
function softphoneRuntimeUrl(env) {
  const slug =
    PARTNER_SOFTPHONE.slug[env] ||
    PARTNER_SOFTPHONE.slug[PARTNER_SOFTPHONE.defaultEnv];
  if (!slug) return "";
  return (
    PARTNER_SOFTPHONE.runtimeBase.replace(/\/+$/, "") +
    "/softphone/" +
    slug +
    "/"
  );
}

// Origin (scheme://host) of the runtime URL — used to (a) target postMessage
// and (b) verify the origin of every inbound message before trusting it.
function softphoneRuntimeOrigin(env) {
  try {
    const url = softphoneRuntimeUrl(env);
    return url ? new URL(url).origin : "";
  } catch (e) {
    return "";
  }
}
