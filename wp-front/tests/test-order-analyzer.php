<?php
declare(strict_types=1);

/**
 * Finlyzer Order Analyzer & Security Unit Tests for PHPUnit.
 */
class Test_Finlyzer_Order_Analyzer extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option('woocommerce_currency', 'USD');
		FXLI_Order_Analyzer::instance()->clear_summary_transients();
	}

	public function tear_down(): void {
		FXLI_Order_Analyzer::instance()->clear_summary_transients();
		parent::tear_down();
	}

	// test empty order table returns zero loss without error
	public function test_get_summary_empty_table(): void {
		$summary = FXLI_Order_Analyzer::instance()->get_summary(30);

		$this->assertIsArray($summary);
		$this->assertSame(0.0, $summary['total_loss']);
		$this->assertSame(0, $summary['order_count']);
		$this->assertSame(0.0, $summary['avg_loss_per_order']);
		$this->assertSame('optimal', $summary['severity_level']);
		$this->assertEmpty($summary['by_currency']);
	}

	// test security prompt scalar sanitization rejects injection attempts
	public function test_sanitize_prompt_scalar_injection(): void {
		$payload = '<script>alert("xss")</script>EUR';
		$clean = FXLI_Security::sanitize_prompt_scalar($payload);
		$this->assertSame('EUR', $clean);

		$sql_injection = 'EUR; DROP TABLE wp_users;';
		$clean_sql = FXLI_Security::sanitize_prompt_scalar($sql_injection);
		$this->assertStringNotContainsString(';', $clean_sql);

		$long_string = str_repeat('X', 200);
		$clean_long = FXLI_Security::sanitize_prompt_scalar($long_string);
		$this->assertSame(64, strlen($clean_long));
	}

	// test REST API permission gating
	public function test_rest_route_permissions(): void {
		$request = new WP_REST_Request('GET', '/finlyzer/v1/summary');

		// unauthenticated request must fail
		wp_set_current_user(0);
		$response = rest_get_server()->dispatch($request);
		$this->assertSame(401, $response->get_status());
	}
}
