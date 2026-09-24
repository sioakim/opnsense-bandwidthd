<?php
/* Execute the collector body off-box. Only its bootstrap and HTTP boundary are
 * replaced; exits, locking, persistence, housekeeping and status are real. */
$dir = $argv[1];
$fetchError = $argv[2];
define('BWD_FLOWS_DIR', $dir);
require dirname(__DIR__) . '/bootstrap.php';
$GLOBALS['bwd_test_cfg'] = array('flows_enable' => 'on', 'ntopng_token' => 'fixture');
$source = file_get_contents(dirname(__DIR__, 2) . '/plugin/src/opnsense/scripts/OPNsense/Bandwidthd/flows.php');
$source = substr($source, strpos($source, '<?php') + 5);
foreach (array('bwd_platform', 'bwd_data') as $lib) {
	$source = str_replace("require_once(__DIR__ . '/lib/$lib.inc.php');", '', $source, $count);
	if ($count !== 1) { throw new RuntimeException('Collector bootstrap seam changed'); }
}
$source = str_replace('bwd_ntopng_flows($base, $token)', 'array(array(), $fetchError)', $source, $count);
if ($count !== 1) { throw new RuntimeException('Collector HTTP seam changed'); }
eval($source);
