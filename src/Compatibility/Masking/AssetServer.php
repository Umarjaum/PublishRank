<?php
/**
 * Serves theme (and in-scope) files from the neutral address.
 *
 * Pure PHP so it can answer before WordPress loads the theme and the remaining plugins (see FastServe in the main
 * plugin file): one autoloaded option lookup, no theme boot, no template hooks — which keeps masking cheap on busy
 * sites. Processed CSS/JS are cached on disk and refreshed when the source file changes.
 *
 * Security: allow-listed extensions only (never PHP, templates or text), no dot-files, no traversal, realpath
 * confinement to the theme folder (or the explicit in-scope wp-content folders), no filesystem paths in errors.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Compatibility\Masking;

final class AssetServer {

	const TYPES = array(
		'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript', 'map' => 'application/json', 'json' => 'application/json',
		'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
		'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
		'eot' => 'application/vnd.ms-fontobject', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wasm' => 'application/wasm',
	);

	const TEXT = array( 'css', 'js', 'mjs', 'svg', 'json', 'map' );

	/** Largest text file that is rewritten (bigger files are streamed untouched). */
	const MAX_REWRITE = 6291456;

	/**
	 * Resolve "c/assets/x.css" to a real file.
	 *
	 * @param array<string,mixed> $cfg
	 * @return array{path:string,ext:string,key:string,type:string}|null
	 */
	public static function resolve( $req, array $cfg ) {
		$req = rawurldecode( (string) $req );
		if ( '' === $req || false !== strpos( $req, "\0" ) || false !== strpos( $req, '\\' ) || preg_match( '#(^|/)\.#', $req ) ) {
			return null;
		}
		$parts = explode( '/', ltrim( $req, '/' ), 2 );
		if ( 2 !== count( $parts ) || ! in_array( $parts[0], array( 'c', 'p', 'x' ), true ) || '' === $parts[1] ) {
			return null;
		}
		list( $key, $sub ) = $parts;
		if ( empty( $cfg['roots'][ $key ]['dir'] ) ) {
			return null;
		}
		$ext = strtolower( pathinfo( $sub, PATHINFO_EXTENSION ) );
		if ( ! isset( self::TYPES[ $ext ] ) ) {
			return null;
		}
		if ( 'map' === $ext && 'strip' === ( isset( $cfg['sourcemaps'] ) ? $cfg['sourcemaps'] : 'strip' ) ) {
			return null;
		}
		$root = realpath( (string) $cfg['roots'][ $key ]['dir'] );
		if ( ! $root ) {
			return null;
		}
		foreach ( array_unique( array( Rewriter::path_in( $sub, $cfg ), $sub ) ) as $cand ) {
			if ( 'x' === $key ) {
				$ok = false;
				foreach ( (array) $cfg['scope'] as $prefix ) {
					if ( 0 === strpos( $cand, $prefix ) ) {
						$ok = true;
					}
				}
				if ( ! $ok ) {
					continue;
				}
			}
			$file = realpath( $root . '/' . $cand );
			if ( $file && 0 === strpos( $file, $root . DIRECTORY_SEPARATOR ) && is_file( $file ) && is_readable( $file ) ) {
				return array( 'path' => $file, 'ext' => $ext, 'key' => $key, 'type' => self::TYPES[ $ext ] );
			}
		}
		return null;
	}

	/** Processed body for text files (cached), or null to stream the original. */
	public static function processed( array $hit, $req, array $cfg ) {
		if ( ! in_array( $hit['ext'], array( 'css', 'js', 'mjs', 'svg' ), true ) || filesize( $hit['path'] ) > self::MAX_REWRITE ) {
			return null;
		}
		$src_m = (int) filemtime( $hit['path'] );
		$cache = '';
		if ( ! empty( $cfg['cache_dir'] ) ) {
			$cache = rtrim( (string) $cfg['cache_dir'], '/' ) . '/' . md5( $req ) . '.' . $hit['ext'];
			if ( is_file( $cache ) && (int) filemtime( $cache ) >= $src_m ) {
				$body = file_get_contents( $cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false !== $body ) {
					return $body;
				}
			}
		}
		$raw = file_get_contents( $hit['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return null;
		}
		if ( 'css' === $hit['ext'] ) {
			$body = Rewriter::css( $raw, $cfg );
		} elseif ( 'svg' === $hit['ext'] ) {
			$body = empty( $cfg['identifiers'] ) ? $raw : Rewriter::identifiers( preg_replace( '#<!--.*?-->#s', '', $raw ), $cfg );
		} else {
			$body = Rewriter::js( $raw, $cfg );
		}
		if ( $cache ) {
			$dir = dirname( $cache );
			if ( ! is_dir( $dir ) ) {
				@mkdir( $dir, 0755, true ); // phpcs:ignore
				@file_put_contents( dirname( $dir ) . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
			}
			$tmp = $cache . '.' . getmypid() . '.tmp';
			if ( false !== @file_put_contents( $tmp, $body, LOCK_EX ) ) { // phpcs:ignore
				@rename( $tmp, $cache ); // phpcs:ignore
			}
		}
		return $body;
	}

	/** Answer the request and exit. */
	public static function serve( $req, array $cfg ) {
		$hit = self::resolve( $req, $cfg );
		if ( function_exists( 'header_remove' ) ) {
			header_remove( 'X-Powered-By' );
			header_remove( 'Link' );
		}
		if ( ! $hit ) {
			http_response_code( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			echo 'Not found';
			exit;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			http_response_code( 405 );
			header( 'Allow: GET, HEAD' );
			exit;
		}
		$body  = self::processed( $hit, $req, $cfg );
		$mtime = (int) filemtime( $hit['path'] );
		$size  = null === $body ? (int) filesize( $hit['path'] ) : strlen( $body );
		$etag  = '"' . substr( md5( $req . '|' . $mtime . '|' . $size . '|' . ( isset( $cfg['hash'] ) ? $cfg['hash'] : '' ) ), 0, 20 ) . '"';
		$text  = in_array( $hit['ext'], self::TEXT, true );
		header( 'Content-Type: ' . $hit['type'] . ( $text ? '; charset=utf-8' : '' ) );
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		header( 'ETag: ' . $etag );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Vary: Accept-Encoding' );
		if ( in_array( $hit['ext'], array( 'woff', 'woff2', 'ttf', 'otf', 'eot' ), true ) ) {
			header( 'Access-Control-Allow-Origin: *' );
		}
		if ( 'svg' === $hit['ext'] ) {
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:" );
		}
		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? (string) $_SERVER['HTTP_IF_NONE_MATCH'] : ''; // phpcs:ignore
		$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : false; // phpcs:ignore
		if ( ( '' !== $inm && in_array( $etag, array_map( static function ( $t ) { return preg_replace( '#^W/#', '', trim( $t ) ); }, explode( ',', $inm ) ), true ) ) || ( '' === $inm && $ims && $ims >= $mtime ) ) {
			http_response_code( 304 );
			exit;
		}
		$start = 0;
		$end   = $size - 1;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? (string) $_SERVER['HTTP_RANGE'] : ''; // phpcs:ignore
		if ( ! $text ) {
			header( 'Accept-Ranges: bytes' );
		}
		if ( ! $text && '' !== $range && preg_match( '#^bytes=(\d*)-(\d*)$#', trim( $range ), $r ) ) {
			if ( '' === $r[1] && '' !== $r[2] ) {
				$start = max( 0, $size - (int) $r[2] );
			} else {
				$start = (int) $r[1];
				$end   = '' !== $r[2] ? min( (int) $r[2], $size - 1 ) : $size - 1;
			}
			if ( $start > $end || $start >= $size ) {
				http_response_code( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			http_response_code( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		} else {
			http_response_code( 200 );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		if ( 'HEAD' === $method ) {
			exit;
		}
		if ( null !== $body ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static asset body.
			exit;
		}
		$fh = fopen( $hit['path'], 'rb' ); // phpcs:ignore
		if ( $fh ) {
			fseek( $fh, $start );
			$left = $end - $start + 1;
			while ( $left > 0 && ! feof( $fh ) ) {
				$chunk = fread( $fh, (int) min( 65536, $left ) ); // phpcs:ignore
				if ( false === $chunk ) {
					break;
				}
				echo $chunk; // phpcs:ignore
				$left -= strlen( $chunk );
			}
			fclose( $fh ); // phpcs:ignore
		}
		exit;
	}
}
