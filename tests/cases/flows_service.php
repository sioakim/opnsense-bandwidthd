<?php
/* The controller contract is tested with the platform parent and Backend
 * stubbed: cron must neither mask a failure nor run after a failed parent. */
namespace OPNsense\Base {
	class ApiMutableServiceControllerBase {
		public $request;
		public $result = array('status' => 'ok');
		public function reconfigureAction() { return $this->result; }
	}
}
namespace OPNsense\Core {
	class Backend {
		public static $calls = array();
		public static $result = "OK\n";
		public function configdRun($action) { self::$calls[] = $action; return self::$result; }
	}
}
namespace {
	t_group('flows service');
	require dirname(__DIR__, 2) . '/plugin/src/opnsense/mvc/app/controllers/OPNsense/Bandwidthd/Api/ServiceController.php';
	$controller = new \OPNsense\Bandwidthd\Api\ServiceController();
	$controller->request = new class {
		public $post = true;
		public function isPost() { return $this->post; }
	};
	t_eq(array('status' => 'ok'), $controller->reconfigureAction(), 'successful cron preserves settings response shape');
	t_eq(array('bandwidthd cron'), \OPNsense\Core\Backend::$calls, 'successful POST regenerates cron');
	\OPNsense\Core\Backend::$calls = array();
	$controller->result = array('status' => 'failed');
	t_eq($controller->result, $controller->reconfigureAction(), 'parent failure is preserved');
	t_eq(array(), \OPNsense\Core\Backend::$calls, 'parent failure must not regenerate cron');
	$controller->result = array('status' => 'ok');
	\OPNsense\Core\Backend::$result = 'Execute error';
	$result = $controller->reconfigureAction();
	t_eq('failed', $result['status'], 'cron failure cannot report reconfigure success');
	t_ok(strpos($result['message'], 'Cron regeneration failed') === 0, 'cron failure explains returned failure');
	\OPNsense\Core\Backend::$calls = array();
	$controller->request->post = false;
	$controller->reconfigureAction();
	t_eq(array(), \OPNsense\Core\Backend::$calls, 'GET does not regenerate cron');
}
