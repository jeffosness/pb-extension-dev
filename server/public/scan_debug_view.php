<?php
// generic_crm/scan_debug_view.php

$logFile = __DIR__ . '/scan_debug.log';

$lines = [];
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

// Show last 50 entries (most recent last)
$lines = array_slice($lines, -50);

function safe($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Scan Debug Viewer</title>
  <style>
    body { font-family: system-ui, sans-serif; font-size: 14px; margin: 20px; }
    pre { background: #f5f5f5; padding: 8px; border-radius: 4px; overflow: auto; }
    .entry { margin-bottom: 16px; border-bottom: 1px solid #ddd; padding-bottom: 8px; }
    .meta { color: #666; font-size: 12px; }
  </style>
</head>
<body>
  <h1>Scan Debug Viewer</h1>
  <p>Showing up to the last 50 scan_debug events.</p>

  <?php if (empty($lines)): ?>
    <p><em>No entries found yet.</em></p>
  <?php else: ?>
    <?php foreach ($lines as $line): ?>
      <?php
        // Each line is "timestamp {json...}"
        $spacePos = strpos($line, ' ');
        $ts = $spacePos !== false ? substr($line, 0, $spacePos) : '';
        $jsonPart = $spacePos !== false ? substr($line, $spacePos + 1) : $line;
        $data = json_decode($jsonPart, true);
      ?>
      <div class="entry">
        <div class="meta"><?php echo safe($ts); ?></div>
        <?php if (is_array($data)): ?>
          <pre><?php echo safe(json_encode($data, JSON_PRETTY_PRINT)); ?></pre>
        <?php else: ?>
          <pre><?php echo safe($jsonPart); ?></pre>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</body>
</html>

