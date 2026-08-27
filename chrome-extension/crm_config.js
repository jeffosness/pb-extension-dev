// crm_config.js — Single source of truth for CRM definitions
// Used by both background.js (import) and content.js (injected before it)

const CRM_REGISTRY = [
  {
    id: "hubspot",
    displayName: "HubSpot",
    level: 3,
    hostMatch: "hubspot.com",
  },
  {
    id: "salesforce",
    displayName: "Salesforce",
    level: 2,
    hostMatch: ["lightning.force.com", "salesforce.com", "my.salesforce.com"],
  },
  {
    id: "zoho",
    displayName: "Zoho CRM",
    level: 1,
    hostMatch: "crm.zoho.com",
  },
  {
    id: "monday",
    displayName: "monday.com",
    level: 1,
    hostMatch: "monday.com",
  },
  {
    id: "pipedrive",
    displayName: "Pipedrive",
    level: 2,
    hostMatch: "pipedrive.com",
  },
  {
    id: "close",
    displayName: "Close",
    level: 3,
    hostMatch: "close.com",
  },
  {
    id: "apollo",
    displayName: "Apollo.io",
    level: 3,
    hostMatch: "apollo.io",
  },
  {
    id: "agencyzoom",
    displayName: "AgencyZoom",
    level: 2,
    hostMatch: "app.agencyzoom.com",
  },
  {
    // Forth CRM (debt-settlement vertical). L3 API-Key integration.
    // Launched to all customers in v0.8.7 (GH #207). Previously carried
    // devOnly:true to stay invisible in prod; that gate was removed at launch.
    id: "forth",
    displayName: "Forth CRM",
    level: 3,
    // Match ANY Forth host — orgs are served under multiple subdomains
    // (client.forthcrm.com, login.forthcrm.com, …). This substring match keeps
    // content.js detectCrmContext consistent with background.js
    // detectCrmFromUrl, which already matches on "forthcrm.com". Matching only
    // "client." silently dropped login.forthcrm.com users to generic L1 (the
    // content script disagreed with background), which broke launch + follow-me.
    hostMatch: "forthcrm.com",
  },
];
