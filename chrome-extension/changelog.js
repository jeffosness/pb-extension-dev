// changelog.js — Version-keyed release notes shown once per version upgrade.
//
// HOW TO USE:
//   When shipping a user-facing feature, add an entry here keyed by the new
//   version number. Only versions with entries trigger the "What's New" modal.
//   Bump the version in manifest.json at the same time.
//
//   {
//     "0.6.0": {
//       title: "What's New in v0.6.0",
//       items: [
//         "Short description of feature 1",
//         "Short description of feature 2"
//       ]
//     }
//   }

const PB_CHANGELOG = {
  "0.8.1": {
    title: "What's Fixed in v0.8.1",
    items: [
      "Click-to-Call and Task-Based Dial Session now work on HubSpot's newer \"All Tasks\" table view (the one at /objects/0-27/), not just the classic Task Queue",
      "Cleared up a console error some HubSpot users were seeing on page load"
    ]
  },
  "0.8.0": {
    title: "What's New in v0.8.0",
    items: [
      "Click-to-Call for HubSpot — click the PhoneBurner flame next to any phone number for a quick single call, no dial session needed",
      "Every HubSpot phone field is dialable — Phone Number, Mobile, Home Phone, and custom phone fields",
      "AgencyZoom support — launch a dial session from your AgencyZoom task list in one click",
      "Cleaner setup — stay on the Dial tab after saving your PAT, with inline \"Connect\" prompts for your CRM",
      "Follow widget fix — now navigates between contacts correctly on all supported CRMs",
      "Turn Click-to-Call off in Settings if you use another dialer's version"
    ]
  },
  "0.7.1": {
    title: "What's Fixed in v0.7.1",
    items: [
      "Fix: the live Follow widget now connects properly during dial sessions. After the v0.7.0 backend migration, some users saw the dialer work but the in-CRM Follow widget never appeared — this update fixes that.",
      "No action needed on your part — the Follow widget will work as expected on your next dial session after the update."
    ]
  },
  "0.7.0": {
    title: "What's New in v0.7.0",
    items: [
      "We've migrated to a new production backend with better reliability and room to grow",
      "Most users: nothing to do! Your PhoneBurner, HubSpot, and Apollo connections carry over automatically",
      "Close users: you'll see a one-time reconnect prompt the next time you launch a Close dial session — takes about 30 seconds",
      "Your data is untouched throughout — contacts, lists, call history, and CRM records all stay where they are"
    ]
  },
  "0.6.4": {
    title: "What's New in v0.6.4",
    items: [
      "HubSpot Task Queue dialing — launch a dial session for all contacts associated with the tasks visible on your HubSpot tasks page",
      "Tasks are automatically marked complete in HubSpot as you finish each call",
      "Tip: check specific task rows in HubSpot to dial just those, or leave none checked to dial the entire visible queue",
      "HubSpot users will need to reconnect HubSpot once to enable the new task-completion behavior — a one-time prompt appears in the Task Queue card"
    ]
  },
  "0.6.2": {
    title: "What's New in v0.6.2",
    items: [
      "Apollo phone preference \u2014 choose which phone field (Direct, Mobile, Corporate) is dialed first in Settings",
      "Improved loading feedback when building dial sessions across all CRM integrations"
    ]
  },
  "0.6.0": {
    title: "What's New in v0.6.0",
    items: [
      "Apollo.io integration \u2014 connect your Apollo account and launch dial sessions from your People page or sequence call tasks",
      "Dial from Apollo sequences \u2014 select a sequence, filter by due tasks, and power-dial contacts with call tasks due today",
      "Auto-navigate to Apollo contacts during calls with the Follow widget"
    ]
  },
  "0.5.2": {
    title: "What's New in v0.5.2",
    items: [
      "Close CRM full integration — connect your Close account via OAuth for complete contact data including emails",
      "Launch dial sessions from Close with automatic phone and email fetching from the Close API"
    ]
  },
  "0.5.1": {
    title: "What's New in v0.5.1",
    items: [
      "Added Close CRM support — scan contacts and launch dial sessions directly from Close",
      "Improved phone number detection for CRMs that display phone in button labels"
    ]
  },
  "0.5.0": {
    title: "What's New in v0.5.0",
    items: [
      "Set a preferred primary phone field for HubSpot dial sessions — go to Settings to choose which phone field is dialed first",
      "Other phone fields are still included as additional numbers"
    ]
  }
};

// Welcome message shown on first install (no previous version stored).
// Returns an HTMLElement so the modal can render clickable links.
function buildWelcomeMessage() {
  const container = document.createElement("div");

  const p1 = document.createElement("p");
  p1.textContent =
    "Thank you for installing the PhoneBurner Dialer Companion! " +
    "This tool is designed to bring PhoneBurner to places it typically " +
    "can\u2019t go without the developers of that system building a custom integration.";
  p1.style.marginBottom = "10px";

  const p2 = document.createElement("p");
  p2.textContent =
    "Using this extension can help you and your team be more efficient " +
    "than ever when making your outbound calls.";
  p2.style.marginBottom = "10px";

  const p3 = document.createElement("p");
  p3.textContent = "To get started, paste your PhoneBurner Personal Access Token on the Dial tab.";
  p3.style.marginBottom = "10px";

  const p4 = document.createElement("p");
  const linkPrefix = document.createTextNode("Need help finding your PAT? ");
  const link = document.createElement("a");
  link.href = "#";
  link.textContent = "Watch this short video";
  link.style.color = "#5b8cff";
  link.style.cursor = "pointer";
  // Chrome extension popups can't use target="_blank" — use chrome.tabs.create instead
  link.addEventListener("click", (e) => {
    e.preventDefault();
    chrome.tabs.create({ url: "https://www.youtube.com/watch?v=ivc-B6YomLQ" });
  });
  p4.appendChild(linkPrefix);
  p4.appendChild(link);

  container.appendChild(p1);
  container.appendChild(p2);
  container.appendChild(p3);
  container.appendChild(p4);
  return container;
}

const PB_WELCOME = {
  title: "Welcome to PhoneBurner Dialer Companion",
  message: null // Built dynamically via buildWelcomeMessage() — needs DOM access
};
