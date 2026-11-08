<?php
/**
 * Minimal stand-in for PHPUnit's TestCase so the suite runs with `php tests/run.php` on any machine.
 * When real PHPUnit is installed (composer install) this file is not loaded.
 *
 * @package PublishRankSEO
 */
namespace PHPUnit\Framework;

if ( class_exists( __NAMESPACE__ . '\\TestCase', false ) ) {
	return;
}

class AssertionFailedError extends \Exception {}
class SkippedTestError extends \Exception {}

abstract class TestCase {
	/** @var int */
	public static $assertions = 0;

	protected function setUp(): void {}
	protected function tearDown(): void {}
	public static function setUpBeforeClass(): void {}
	public static function tearDownAfterClass(): void {}

	/** Run before/after each test (internal). */
	public function runSetUp(): void { $this->setUp(); }
	public function runTearDown(): void { $this->tearDown(); }

	private function ok( $condition, $message ) {
		++self::$assertions;
		if ( ! $condition ) {
			throw new AssertionFailedError( $message );
		}
	}
	private function show( $value ) {
		if ( is_string( $value ) ) {
			return '"' . ( strlen( $value ) > 400 ? substr( $value, 0, 400 ) . '…' : $value ) . '"';
		}
		return str_replace( "\n", ' ', var_export( $value, true ) );
	}

	public function fail( $message = '' ) { $this->ok( false, $message ?: 'Failed.' ); }
	public function markTestSkipped( $message = '' ) { throw new SkippedTestError( $message ); }
	public function assertTrue( $v, $m = '' ) { $this->ok( true === $v, $m ?: 'Expected true, got ' . $this->show( $v ) ); }
	public function assertFalse( $v, $m = '' ) { $this->ok( false === $v, $m ?: 'Expected false, got ' . $this->show( $v ) ); }
	public function assertNull( $v, $m = '' ) { $this->ok( null === $v, $m ?: 'Expected null, got ' . $this->show( $v ) ); }
	public function assertNotNull( $v, $m = '' ) { $this->ok( null !== $v, $m ?: 'Expected a value, got null' ); }
	public function assertSame( $e, $a, $m = '' ) { $this->ok( $e === $a, $m ?: 'Expected ' . $this->show( $e ) . ' but got ' . $this->show( $a ) ); }
	public function assertNotSame( $e, $a, $m = '' ) { $this->ok( $e !== $a, $m ?: 'Did not expect ' . $this->show( $a ) ); }
	public function assertEquals( $e, $a, $m = '' ) { $this->ok( $e == $a, $m ?: 'Expected ' . $this->show( $e ) . ' but got ' . $this->show( $a ) ); } // phpcs:ignore
	public function assertEmpty( $v, $m = '' ) { $this->ok( empty( $v ), $m ?: 'Expected empty, got ' . $this->show( $v ) ); }
	public function assertNotEmpty( $v, $m = '' ) { $this->ok( ! empty( $v ), $m ?: 'Expected a non-empty value' ); }
	public function assertCount( $n, $v, $m = '' ) { $this->ok( is_countable( $v ) && count( $v ) === $n, $m ?: 'Expected count ' . $n . ', got ' . ( is_countable( $v ) ? count( $v ) : 'n/a' ) ); }
	public function assertContains( $needle, $haystack, $m = '' ) { $this->ok( in_array( $needle, (array) $haystack, true ), $m ?: 'Expected ' . $this->show( $haystack ) . ' to contain ' . $this->show( $needle ) ); }
	public function assertNotContains( $needle, $haystack, $m = '' ) { $this->ok( ! in_array( $needle, (array) $haystack, true ), $m ?: 'Expected ' . $this->show( $haystack ) . ' not to contain ' . $this->show( $needle ) ); }
	public function assertStringContainsString( $n, $h, $m = '' ) { $this->ok( false !== strpos( (string) $h, (string) $n ), $m ?: 'Expected ' . $this->show( $h ) . ' to contain ' . $this->show( $n ) ); }
	public function assertStringNotContainsString( $n, $h, $m = '' ) { $this->ok( false === strpos( (string) $h, (string) $n ), $m ?: 'Expected ' . $this->show( $h ) . ' NOT to contain ' . $this->show( $n ) ); }
	public function assertStringStartsWith( $p, $s, $m = '' ) { $this->ok( 0 === strpos( (string) $s, (string) $p ), $m ?: 'Expected ' . $this->show( $s ) . ' to start with ' . $this->show( $p ) ); }
	public function assertMatchesRegularExpression( $re, $s, $m = '' ) { $this->ok( 1 === preg_match( $re, (string) $s ), $m ?: 'Expected ' . $this->show( $s ) . ' to match ' . $re ); }
	public function assertDoesNotMatchRegularExpression( $re, $s, $m = '' ) { $this->ok( 1 !== preg_match( $re, (string) $s ), $m ?: 'Expected ' . $this->show( $s ) . ' NOT to match ' . $re ); }
	public function assertGreaterThan( $e, $a, $m = '' ) { $this->ok( $a > $e, $m ?: $this->show( $a ) . ' is not greater than ' . $this->show( $e ) ); }
	public function assertGreaterThanOrEqual( $e, $a, $m = '' ) { $this->ok( $a >= $e, $m ?: $this->show( $a ) . ' is not >= ' . $this->show( $e ) ); }
	public function assertLessThan( $e, $a, $m = '' ) { $this->ok( $a < $e, $m ?: $this->show( $a ) . ' is not less than ' . $this->show( $e ) ); }
	public function assertLessThanOrEqual( $e, $a, $m = '' ) { $this->ok( $a <= $e, $m ?: $this->show( $a ) . ' is not <= ' . $this->show( $e ) ); }
	public function assertArrayHasKey( $k, $a, $m = '' ) { $this->ok( is_array( $a ) && array_key_exists( $k, $a ), $m ?: 'Expected key ' . $this->show( $k ) ); }
	public function assertArrayNotHasKey( $k, $a, $m = '' ) { $this->ok( ! is_array( $a ) || ! array_key_exists( $k, $a ), $m ?: 'Unexpected key ' . $this->show( $k ) ); }
	public function assertInstanceOf( $c, $v, $m = '' ) { $this->ok( $v instanceof $c, $m ?: 'Expected instance of ' . $c ); }
	public function assertIsArray( $v, $m = '' ) { $this->ok( is_array( $v ), $m ?: 'Expected array' ); }
	public function assertIsString( $v, $m = '' ) { $this->ok( is_string( $v ), $m ?: 'Expected string' ); }
}
