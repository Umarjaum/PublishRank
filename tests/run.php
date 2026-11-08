<?php
/**
 * Dependency-free test runner:  WP_PATH=/path/to/wordpress php tests/run.php [--filter=Text]
 * Exit status is 0 when every test passes.
 *
 * @package PublishRankSEO
 */

require __DIR__ . '/bootstrap.php';

$filter = '';
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--filter=' ) ) {
		$filter = substr( $arg, 9 );
	}
}

$before = get_declared_classes();
foreach ( glob( __DIR__ . '/cases/*Test.php' ) as $file ) {
	require_once $file;
}
$classes = array_filter(
	array_diff( get_declared_classes(), $before ),
	static function ( $class ) {
		return is_subclass_of( $class, 'PublishRankTestCase' ) && ! ( new ReflectionClass( $class ) )->isAbstract();
	}
);

$passed = $failed = $skipped = 0;
$errors = array();
$start  = microtime( true );
set_error_handler(
	static function ( $no, $str, $file, $line ) {
		if ( ! ( error_reporting() & $no ) || E_DEPRECATED === $no || E_USER_DEPRECATED === $no ) {
			return false;
		}
		throw new ErrorException( $str, 0, $no, $file, $line );
	}
);

$invoke = static function ( $object, $method ) {
	$ref = new ReflectionMethod( $object, $method );
	$ref->setAccessible( true );
	$ref->invoke( $object );
};

foreach ( $classes as $class ) {
	foreach ( ( new ReflectionClass( $class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
		if ( 0 !== strpos( $method->name, 'test' ) || $method->class !== $class ) {
			continue;
		}
		$label = $class . '::' . $method->name;
		if ( '' !== $filter && false === stripos( $label, $filter ) ) {
			continue;
		}
		$object = new $class();
		try {
			$invoke( $object, 'setUp' );
			$object->{$method->name}();
			++$passed;
			echo '.';
		} catch ( \PHPUnit\Framework\SkippedTestError $e ) {
			++$skipped;
			echo 'S';
		} catch ( \Throwable $e ) {
			++$failed;
			echo 'F';
			$errors[] = $label . "\n    " . get_class( $e ) . ': ' . $e->getMessage() . "\n    at " . basename( $e->getFile() ) . ':' . $e->getLine();
		} finally {
			try {
				$invoke( $object, 'tearDown' );
			} catch ( \Throwable $e ) {
				$errors[] = $label . " (tearDown)\n    " . $e->getMessage();
			}
		}
	}
}
echo "\n\n";
foreach ( $errors as $i => $error ) {
	echo ( $i + 1 ) . ') ' . $error . "\n\n";
}
printf( "%d passed, %d failed, %d skipped, %d assertions in %.1fs\n", $passed, $failed, $skipped, \PHPUnit\Framework\TestCase::$assertions, microtime( true ) - $start );
exit( $failed ? 1 : 0 );
