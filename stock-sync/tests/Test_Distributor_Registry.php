<?php

class Test_Distributor_Registry extends PHPUnit\Framework\TestCase {

	private function reset_singleton() {
		$ref = new ReflectionClass(StockSync_Distributor_Registry::class);
		$prop = $ref->getProperty('instance');
		$prop->setValue(null, null);
	}

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

	protected function setUp(): void {
		parent::setUp();
		$this->reset_singleton();
	}

	protected function tearDown(): void {
		$this->reset_singleton();
		parent::tearDown();
	}

	public function test_singleton() {
		$instance1 = StockSync_Distributor_Registry::instance();
		$instance2 = StockSync_Distributor_Registry::instance();
		$this->assertSame($instance1, $instance2);
	}

	public function test_register() {
		$registry = StockSync_Distributor_Registry::instance();
		$distributor = $this->create_distributor('test', 'Test Dist');
		$registry->register($distributor);
		$this->assertSame($distributor, $registry->get('test'));
	}

	public function test_duplicate_slug_throws_exception() {
		$registry = StockSync_Distributor_Registry::instance();
		$distributor = $this->create_distributor('test', 'Test Dist');
		$registry->register($distributor);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Distributor with slug "test" is already registered.');
		$registry->register($distributor);
	}

	public function test_get_returns_null_for_unknown_slug() {
		$registry = StockSync_Distributor_Registry::instance();
		$this->assertNull($registry->get('unknown'));
	}

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
