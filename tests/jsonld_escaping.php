<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
// Verifies JSON-LD output flags prevent </script> breakout.
$src = file_get_contents( __DIR__ . '/../src/Schema/GraphOutput.php' );
$ok  = false !== strpos( $src, 'JSON_HEX_TAG' ) && false !== strpos( $src, 'JSON_HEX_AMP' );
$out = json_encode( array( 'n' => 'x</script><script>alert(1)</script>' ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
$ok  = $ok && false === stripos( $out, '</script' ) && false === strpos( $out, '<' );
echo $ok ? "PASS: JSON-LD escaping\n" : "FAIL: JSON-LD escaping\n";
exit( $ok ? 0 : 1 );
