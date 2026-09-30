<?php

if (PHP_SAPI !== 'cli') {
  exit;
}

/**
 * Bootstrap for the MAS tool pack's tests (mcp/). They run on civicrm_mcp's autoloader and dev
 * tools, then add this pack's own namespaces.
 */

$mcpDir = getenv('CIVICRM_MCP_DIR') ?: dirname(__DIR__, 3) . '/civicrm_mcp';
if (!is_file("$mcpDir/vendor/autoload.php")) {
  fwrite(STDERR, "civicrm_mcp dev tools not found at $mcpDir: run composer install there, or set CIVICRM_MCP_DIR\n");
  exit(1);
}
$loader = require "$mcpDir/vendor/autoload.php";
$loader->addPsr4('Civi\\Mascode\\Mcp\\', dirname(__DIR__) . '/src/');
$loader->addPsr4('Civi\\Mascode\\Mcp\\Tests\\', __DIR__ . '/');

// civicrm_mcp's bootstrap: a cv-booted site for the live suite, stand-ins for the unit suite.
require "$mcpDir/tests/bootstrap.php";
