<?php
/**
 * Tests for StockSync_Distributor_Registry.
 */


class Test_Distributor_Registry extends PHPUnit\Framework\TestCase {

	/**
	 * Reset the StockSync_Distributor_Registry singleton to an uninitialized state.
	 *
	 * Sets the registry's internal `instance` property to `null` so a fresh
	 * instance will be created on next access (used to isolate tests).
	 */
	private function reset_singleton() {
		$ref = new ReflectionClass(StockSync_Distributor_Registry::class);
		$prop = $ref->getProperty('instance');
		$prop->setValue(null, null);
	}

	/**
	 * Create an anonymous StockSync_Distributor that returns the provided slug and name.
	 *
	 * @param string $slug The slug returned by the distributor's get_slug().
	 * @param string $name The name returned by the distributor's get_name().
	 * @return StockSync_Distributor An instance configured with the given slug and name implementing the minimal distributor interface.
	 */
	private function create_distributor(string $slug, string $name): StockSync_Distributor {
		return new class($slug, $name) extends StockSync_Distributor {
			private string $slug;
			private string $name;

			public function __construct(string $slug, string $name) {
				$this->slug = $slug;
				$this->name = $name;
			}

			public function get_name() { return $this->name; }
			public function get_slug() { return $this->slug; }
			public function get_header_row() { return 1; }
			public function get_column_map() { return []; }
			public function is_product_row($row_data) { return true; }
			public function is_unavailable($value) { return false; }
		};
	}

	/**
	 * Prepare the test environment and reset the distributor registry singleton before each test.
	 *
	 * Ensures parent test setup runs and clears StockSync_Distributor_Registry's singleton so each test starts with a fresh registry state.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reset_singleton();
	}

	/**
	 * Tear down the test fixture and reset the distributor registry singleton.
	 *
	 * Ensures test isolation by resetting the registry singleton and then executing the parent tearDown.
	 */
	protected function tearDown(): void {
		$this->reset_singleton();
		parent::tearDown();
	}
    /**
     * Verify that the registry returns the same instance on repeated calls.
     */

	public function test_singleton() {
		$instance1 = StockSync_Distributor_Registry::instance();
		$instance2 = StockSync_Distributor_Registry::instance();
		$this->assertSame($instance1, $instance2);
	}
    /**
     * Verify that a distributor can be registered and retrieved by slug.
     */

	public function test_register() {
		$registry = StockSync_Distributor_Registry::instance();
		$distributor = $this->create_distributor('test', 'Test Dist');
		$registry->register($distributor);
		$this->assertSame($distributor, $registry->get('test'));
	}

	/**
	 * Verifies that registering a distributor with a slug already present causes an InvalidArgumentException.
	 *
	 * Expects the registry to throw an `InvalidArgumentException` with message
	 * 'Distributor with slug "test" is already registered.' when attempting to register a duplicate slug.
	 */
	public function test_duplicate_slug_throws_exception() {
		$registry = StockSync_Distributor_Registry::instance();
		$distributor = $this->create_distributor('test', 'Test Dist');
		$registry->register($distributor);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Distributor with slug "test" is already registered.');
		$registry->register($distributor);
	}
    /**
     * Verify that an unknown slug returns null.
     */

	public function test_get_returns_null_for_unknown_slug() {
		$registry = StockSync_Distributor_Registry::instance();
		$this->assertNull($registry->get('unknown'));
	}
    /**
     * Verify that get_all returns every registered distributor.
     */

	public function test_get_all() {
		$registry = StockSync_Distributor_Registry::instance();
		$distributor1 = $this->create_distributor('test1', 'Test One');
		$distributor2 = $this->create_distributor('test2', 'Test Two');
		$registry->register($distributor1);
		$registry->register($distributor2);

		$all = $registry->get_all();
		$this->assertCount(2, $all);
		$this->assertSame($distributor1, $all['test1']);
		$this->assertSame($distributor2, $all['test2']);
	}

	/**
	 * Verifies that get_options returns an associative array mapping distributor slugs to their names for registered distributors.
	 *
	 * Registers two distributors and asserts the returned options array contains their slugs as keys and names as values.
	 */
	public function test_get_options() {
		$registry = StockSync_Distributor_Registry::instance();
		$distributor1 = $this->create_distributor('test1', 'Test One');
		$distributor2 = $this->create_distributor('test2', 'Test Two');
		$registry->register($distributor1);
		$registry->register($distributor2);

		$options = $registry->get_options();
		$this->assertSame([
			'test1' => 'Test One',
			'test2' => 'Test Two',
		], $options);
	}
}
