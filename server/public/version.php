<?php
// version.php
// Single source of truth for what build is deployed.

return [
  // Update this on deploy (or later automate it with git)
  'version' => 'dev-2025-12-22_01',

  // Optional: helpful metadata
  'env'     => 'dev',
  'built_at'=> gmdate('c'),
];
