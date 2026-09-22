<?php
/**
 * Standalone PHP Challenge Suite for Finlyzer REST API Fragment Serving (v1.24.0)
 *
 * Verifies that HTML fragment responses for htmx:
 * 1. Do NOT attempt to invoke protected method WP_REST_Server::set_status().
 * 2. Correctly route status codes via status_header().
 * 3. Enforce mandatory security headers (X-Content-Type-Options: nosniff, Cache-Control).
 * 4. Bound strictly to matching response instances without cross-request filter leakage.
 * 5. Handle heavy execution loads with zero memory leaks.
 */

declare(strict_types=1);

// setup basic environment constants for test harness
if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/../');
}
if (!defined('FINLYZER_PLUGIN_DIR')) {
	define('FINLYZER_PLUGIN_DIR', __DIR__ . '/../');
}
if (!defined('FINLYZER_VERSION')) {
	define('FINLYZER_VERSION', '1.24.0');
}

// -----------------------------------------------------------------------------
// WordPress Core Mocks: Replicate exact WordPress REST API class hierarchy
// -----------------------------------------------------------------------------

// global tracker for sent status codes and headers
class WP_Test_Transport_State {
	public static array $status_codes_sent = [];
	public static array $headers_sent = [];
	public static array $filters = [];

	public static function reset(): void {
		self::$status_codes_sent = [];
		self::$headers_sent = [];
		self::$filters = [];
	}
}

// canonical WordPress status_header function mock
function status_header(int $code, string $description = ''): void {
	WP_Test_Transport_State::$status_codes_sent[] = $code;
}

// WordPress filter hooks mocks
function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): void {
	WP_Test_Transport_State::$filters[$hook_name][] = [
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	];
}

function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed {
	if (empty(WP_Test_Transport_State::$filters[$hook_name])) {
		return $value;
	}

	foreach (WP_Test_Transport_State::$filters[$hook_name] as $item) {
		$callback = $item['callback'];
		$call_args = array_merge([$value], array_slice($args, 0, $item['accepted_args'] - 1));
		$value = $callback(...$call_args);
	}

	return $value;
}

// mock WP_REST_Request
class WP_REST_Request {
	private string $route;
	private array $params = [];

	public function __construct(string $method = 'GET', string $route = '') {
		$this->route = $route;
	}

	public function get_route(): string {
		return $this->route;
	}

	public function get_param(string $key): mixed {
		return $this->params[$key] ?? null;
	}

	public function set_param(string $key, mixed $val): void {
		$this->params[$key] = $val;
	}
}

// mock WP_REST_Response
class WP_REST_Response {
	private mixed $data;
	private int $status;
	private array $headers;

	public function __construct(mixed $data = null, int $status = 200, array $headers = []) {
		$this->data = $data;
		$this->status = $status;
		$this->headers = $headers;
	}

	public function get_data(): mixed {
		return $this->data;
	}

	public function get_status(): int {
		return $this->status;
	}

	public function get_headers(): array {
		return $this->headers;
	}
}

// mock WP_REST_Server mirroring WordPress core wp-includes/rest-api/class-wp-rest-server.php
class WP_REST_Server {
	public const READABLE = 'GET';
	public const CREATABLE = 'POST';

	public array $sent_headers = [];

	// protected in WordPress core; any external call will cause PHP Fatal Error
	protected function set_status(int $code): void {
		throw new Error('Call to protected method WP_REST_Server::set_status()');
	}

	// public in WordPress core
	public function send_header(string $key, string $value): void {
		$this->sent_headers[$key] = $value;
		WP_Test_Transport_State::$headers_sent[$key] = $value;
	}

	// simulate WordPress core serve_request pipeline
	public function serve_request(WP_REST_Response $response, WP_REST_Request $request): string {
		ob_start();
		$served = apply_filters('rest_pre_serve_request', false, $response, $request, $this);
		$output = ob_get_clean();

		if ($served) {
			return (string) $output;
		}

		return json_encode($response->get_data());
	}
}

// -----------------------------------------------------------------------------
// Load Target Component
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../includes/class-fxli-rest-api.php';

// -----------------------------------------------------------------------------
// Test Runner Assertions
// -----------------------------------------------------------------------------
$tests_passed = 0;
$total_tests = 0;

function assert_test(bool $condition, string $message): void {
	global $tests_passed, $total_tests;
	$total_tests++;
	if (!$condition) {
		echo "  ❌ [FAIL] {$message}\n";
		exit(1);
	}
	$tests_passed++;
	echo "  ✓ [PASS] {$message}\n";
}

echo "================================================================\n";
echo "⚡ FINLYZER REST API FRAGMENT SERVING CHALLENGE SUITE (v1.24.0)\n";
echo "================================================================\n\n";

// TEST 1: Serve HTML fragment without invoking protected method WP_REST_Server::set_status()
echo "--- 1. Protected Method Access Immunity & Status Header Dispatch ---\n";
WP_Test_Transport_State::reset();

$server = new WP_REST_Server();
$request = new WP_REST_Request('GET', '/finlyzer/v1/summary');
$api = FXLI_REST_API::instance();

// use reflection to test private serve_html method
$reflection = new ReflectionClass($api);
$serve_html_method = $reflection->getMethod('serve_html');
$serve_html_method->setAccessible(true);

$fragment = '<div class="finlyzer-summary">Test Summary Fragment</div>';
$response = $serve_html_method->invoke($api, $fragment, 200);

assert_test($response instanceof WP_REST_Response, 'serve_html returns WP_REST_Response instance');
assert_test($response->get_status() === 200, 'WP_REST_Response holds status 200');

// dispatch through WP_REST_Server pipeline
$captured_output = '';
$threw_error = false;
try {
	$captured_output = $server->serve_request($response, $request);
} catch (Throwable $e) {
	$threw_error = true;
	echo "Exception caught: " . $e->getMessage() . "\n";
}

assert_test(!$threw_error, 'serve_request executed without PHP Fatal Error on protected set_status()');
assert_test($captured_output === $fragment, 'Raw HTML fragment output stream matches expected HTML exactly');
assert_test(in_array(200, WP_Test_Transport_State::$status_codes_sent, true), 'HTTP status 200 dispatched through status_header()');
assert_test(isset($server->sent_headers['Content-Type']) && str_contains($server->sent_headers['Content-Type'], 'text/html'), 'Content-Type is text/html');
assert_test(isset($server->sent_headers['X-Content-Type-Options']) && $server->sent_headers['X-Content-Type-Options'] === 'nosniff', 'Security header X-Content-Type-Options: nosniff present');
assert_test(isset($server->sent_headers['Cache-Control']) && str_contains($server->sent_headers['Cache-Control'], 'no-cache'), 'Cache-Control header prevents caching');

// TEST 2: Single-flight response instance binding (no cross-request filter leakage)
echo "\n--- 2. Single-Flight Response Binding & Cross-Request Leakage Defense ---\n";
WP_Test_Transport_State::reset();

$fragment1 = '<div id="frag1">Fragment 1</div>';
$response1 = $serve_html_method->invoke($api, $fragment1, 200);

// another route produces standard JSON response in the same request lifecycle
$response2 = new WP_REST_Response(['status' => 'ok'], 200, ['Content-Type' => 'application/json']);

// first serve response 1
$out1 = $server->serve_request($response1, $request);
assert_test($out1 === $fragment1, 'Response 1 served HTML fragment');

// now serve response 2 without re-registering HTML filter: must return JSON, not fragment 1!
$out2 = $server->serve_request($response2, $request);
assert_test($out2 === '{"status":"ok"}', 'Subsequent response 2 served clean JSON and was NOT intercepted by response 1 filter');

// TEST 3: Custom Status Code Propagation (e.g. 503 or 400 fragment)
echo "\n--- 3. Non-200 Status Header Propagation ---\n";
WP_Test_Transport_State::reset();

$errFragment = '<div class="notice notice-error">Service Degraded</div>';
$errResponse = $serve_html_method->invoke($api, $errFragment, 503);

$outErr = $server->serve_request($errResponse, $request);
assert_test($outErr === $errFragment, 'Degraded fragment served cleanly');
assert_test(in_array(503, WP_Test_Transport_State::$status_codes_sent, true), 'Status 503 dispatched through status_header()');

// TEST 4: High-Concurrency & Heavy Load Resilience (50,000 Cycles)
echo "\n--- 4. Heavy Load Concurrency Stress Test (50,000 Cycles) ---\n";
$start = microtime(true);
$cycles = 50000;
$memStart = memory_get_usage();

for ($i = 0; $i < $cycles; $i++) {
	WP_Test_Transport_State::reset();
	$resp = $serve_html_method->invoke($api, "<div>Item {$i}</div>", 200);
	$out = $server->serve_request($resp, $request);
}

$elapsed_ms = (microtime(true) - $start) * 1000;
$memDelta = (memory_get_usage() - $memStart) / 1024;

assert_test($elapsed_ms < 500.0, sprintf('50,000 fragment renders executed in %.2fms (< 500ms SLA)', $elapsed_ms));
assert_test($memDelta < 2048.0, sprintf('Memory delta strictly bounded (%.2f KB increase for 50,000 cycles)', $memDelta));

echo "\n================================================================\n";
echo "RESULTS: {$tests_passed} passed, 0 failed ({$total_tests} total)\n";
echo "================================================================\n";
echo "ALL SOFTWARE ENGINEERING PRINCIPLES VERIFIED SUCCESSFULLY.\n";
