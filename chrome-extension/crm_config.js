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
];
