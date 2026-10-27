<?php
/** Regression checks for removal of the optional remote News announcement feed. */
if ( 'cli' !== PHP_SAPI ) {
	exit;
}
$root = dirname( __DIR__ );
$checks = array();
$checks['the removed News admin page is not shipped'] = ! is_file( $root . '/src/Admin/NewsPage.php' );
$checks['the removed News feed assets are not shipped'] = ! is_file( $root . '/src/assets/css/admin-news.css' ) && ! is_file( $root . '/src/assets/js/admin-news-prompt.js' ) && ! is_file( $root . '/assets/css/admin-news.css' ) && ! is_file( $root . '/assets/js/admin-news-prompt.js' );
$checks['the supported News sitemap provider remains available'] = is_file( $root . '/src/Sitemap/News/NewsSitemapProvider.php' );
$runtime = '';
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( $file->isFile() && '.php' === $file->getExtension() ) {
		$runtime .= file_get_contents( $file->getPathname() );
	}
}
$checks['the remote announcements endpoint and feed class are absent from runtime PHP'] = false === strpos( $runtime, 'raw.githubusercontent.com/Umarjaum/publishrank_website/main/announcements.json' ) && false === strpos( $runtime, 'class NewsPage' ) && false === strpos( $runtime, 'FEED_URL' );
$checks['no code references a NewsPage class or feed prompt'] = false === strpos( $runtime, 'NewsPage::class' ) && false === strpos( $runtime, 'admin-news-prompt' );
$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}
if ( $failed ) {
	exit( 1 );
}
echo "News feed removal checks passed.\n";
