<?php
require __DIR__ . '/support/wp-stub.php';
use PublishRankSEO\Admin\OnboardingPage; use PublishRankSEO\Admin\SettingsPage;
$fail = 0; function t( $label, $ok ) { global $fail; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) $fail++; }
$w = new OnboardingPage(); $o =& $GLOBALS['opts'];

// 1. Settings form vs registration
$s = new SettingsPage(); $s->settings();
ob_start(); $s->page(); $html = ob_get_clean();
preg_match_all( '/name="([A-Za-z0-9_\[\]]+?)(?:\[\])?"/', $html, $m ); $names = array_unique( $m[1] );
$managed = array_keys( array_filter( $GLOBALS['registered'], fn( $g ) => 'publishrank_seo' === $g ) );
$missing = array_diff( $managed, $names );
t( 'every option in the Settings group has a form field (no blanking on save): ' . implode( ',', $missing ), ! $missing );
$setup = OnboardingPage::setup_keys();
t( 'no wizard-owned option remains in the Settings group: ' . implode( ',', array_intersect( $managed, $setup ) ), ! array_intersect( $managed, $setup ) );
t( 'all wizard options are still registered (unmanaged group)', ! array_diff( $setup, array_keys( $GLOBALS['registered'] ) ) );
t( 'Settings form no longer contains identity/social/verification inputs', false === strpos( $html, 'publishrank_identity_name' ) && false === strpos( $html, 'publishrank_social_x' ) && false === strpos( $html, 'publishrank_verification_google' ) && false === strpos( $html, 'publishrank_primary_url' ) );
t( 'Settings links to the wizard', false !== strpos( $html, 'publishrank-onboarding&amp;edit=1&amp;step=2' ) );
t( 'Settings still has ongoing controls', false !== strpos( $html, 'publishrank_seo_news_window' ) && false !== strpos( $html, 'publishrank_breadcrumbs_enabled' ) && false !== strpos( $html, 'publishrank_seo_hreflang_map' ) );

// 2. lock + redirect
$o['publishrank_setup_required'] = 1; $_GET = array( 'page' => 'publishrank-seo-dashboard' );
try { $w->maybe_lock_plugin_screens(); $r = null; } catch ( RedirectException $e ) { $r = $e->url; }
t( 'locked: dashboard redirects to wizard', $r && false !== strpos( $r, 'publishrank-onboarding' ) );
$_GET = array( 'page' => 'publishrank-onboarding' ); $r = 'none';
try { $w->maybe_lock_plugin_screens(); } catch ( RedirectException $e ) { $r = $e->url; }
t( 'locked: wizard page itself is reachable', 'none' === $r );
unset( $o['publishrank_setup_required'] ); $_GET = array( 'page' => 'publishrank-seo-dashboard' ); $r = 'none';
try { $w->maybe_lock_plugin_screens(); } catch ( RedirectException $e ) { $r = $e->url; }
t( 'not locked (upgrade path): no redirect', 'none' === $r );
$o['publishrank_setup_required'] = 1;
$GLOBALS['caps'] = false; $r = 'none'; try { $w->maybe_lock_plugin_screens(); } catch ( RedirectException $e ) { $r = 'x'; } t( 'non-admins are never locked', 'none' === $r ); $GLOBALS['caps'] = true;

// 3. render modal
$_GET = array( 'page' => 'publishrank-onboarding', 'step' => '2' );
ob_start(); $w->page(); $out = ob_get_clean();
t( 'locked modal renders dialog', false !== strpos( $out, 'role="dialog"' ) && false !== strpos( $out, 'prw-modal-backdrop' ) );
t( 'locked modal has no close button / exit link', false === strpos( $out, 'prw-modal__close' ) && false === strpos( $out, 'Exit wizard' ) );
t( 'required fields marked', substr_count( $out, 'required aria-required' ) >= 2 );
for ( $i = 1; $i <= 8; $i++ ) { $_GET['step'] = (string) $i; ob_start(); $w->page(); $x = ob_get_clean(); t( "step $i renders", strlen( $x ) > 800 ); }

// 4. save: required validation
$u = call_post( $w, 2, array( 'publishrank_identity_type' => 'organization', 'publishrank_identity_name' => '', 'publishrank_identity_country' => '' ) );
t( 'step 2 without required fields stays on step 2 with error', $u && false !== strpos( $u, 'step=2' ) && false !== strpos( $u, 'error=required' ) );
$u = call_post( $w, 2, array( 'publishrank_identity_type' => 'organization', 'publishrank_identity_name' => 'Test Daily News', 'publishrank_identity_country' => 'pk', 'publishrank_identity_logo' => 'https://example.test/l.png', 'publishrank_identity_alternate_name' => 'TDN', 'publishrank_identity_description' => 'About us', 'publishrank_default_language' => 'en', 'publishrank_primary_url' => 'https://example.test/' ) );
t( 'step 2 with required advances to 3', $u && false !== strpos( $u, 'step=3' ) && false !== strpos( $u, 'saved=1' ) );
t( 'identity mirrored to legacy options', 'Test Daily News' === $o['publishrank_seo_publication_name'] && 'Test Daily News' === $o['publishrank_seo_publisher_name'] && 'https://example.test/l.png' === $o['publishrank_seo_publisher_logo'] && 'TDN' === $o['publishrank_publication_short_name'] && 'About us' === $o['publishrank_schema_org_description'] );
t( 'country upper-cased and mirrored', 'PK' === $o['publishrank_identity_country'] && 'PK' === $o['publishrank_country'] );
t( 'PublicationProfile reads wizard values', 'Test Daily News' === PublishRankSEO\Core\PublicationProfile::publisher_name() && 'Test Daily News' === PublishRankSEO\Core\PublicationProfile::organization_identity()['name'] );
call_post( $w, 2, array( 'publishrank_identity_type' => 'organization', 'publishrank_identity_name' => 'X', 'publishrank_identity_country' => 'Pakistan' ) );
t( 'non-ISO country rejected (required again)', '' === $o['publishrank_identity_country'] );
call_post( $w, 2, array( 'publishrank_identity_type' => 'organization', 'publishrank_identity_name' => 'Test Daily News', 'publishrank_identity_country' => 'PK' ) );

// step 3 org
call_post( $w, 3, array( 'publishrank_schema_founder' => 'Ali Khan', 'publishrank_schema_knows_about' => 'Politics, Economy', 'publishrank_identity_parent_organization' => 'Media Group', 'publishrank_identity_contact_email' => 'ed@example.test', 'publishrank_identity_person_job_title' => 'SHOULD NOT SAVE' ) );
t( 'step 3 org fields saved + parent org read by schema', 'Ali Khan' === $o['publishrank_schema_founder'] && 'Media Group' === PublishRankSEO\Core\PublicationProfile::organization_identity()['parent_organization'] && 'ed@example.test' === $o['publishrank_contact'] );
t( 'person-only fields ignored for organization', ! isset( $o['publishrank_identity_person_job_title'] ) );
$org = PublishRankSEO\Core\PublicationProfile::organization_identity();
t( 'founder/expertise exposed to schema', 'Ali Khan' === $org['founder'] && 'Politics, Economy' === $org['knows_about'] );
// step 4/5/6
call_post( $w, 4, array( 'publishrank_social_x' => '@tdnews', 'publishrank_social_facebook' => 'https://facebook.com/tdn', 'publishrank_social_custom' => "https://mastodon.social/@tdn\njavascript:alert(1)", 'publishrank_seo_twitter_site' => '@tdnews' ) );
$profiles = PublishRankSEO\Core\PublicationProfile::social_profiles();
t( 'social saved, junk URL dropped', in_array( 'https://x.com/tdnews', $profiles, true ) && in_array( 'https://mastodon.social/@tdn', $profiles, true ) && 2 + 1 === count( $profiles ) && 'tdnews' === $o['publishrank_seo_twitter_site'] );
call_post( $w, 5, array( 'publishrank_home_title' => 'TDN – Home', 'publishrank_home_description' => 'Desc', 'publishrank_seo_title_separator' => '–', 'publishrank_verification_google' => 'abc123_-' ) );
t( 'search + verification saved', 'abc123_-' === $o['publishrank_verification_google'] && '–' === $o['publishrank_seo_title_separator'] );
call_post( $w, 6, array( 'publishrank_seo_news_enabled' => '1', 'publishrank_seo_news_post_types' => array( 'post', 'news', 'bogus' ), 'publishrank_sitemap_post_types' => array( 'post', 'page' ) ) );
t( 'content scope saved, invalid types dropped', 'post,news' === $o['publishrank_seo_news_post_types'] && 'post,page' === $o['publishrank_sitemap_post_types'] );
call_post( $w, 6, array( 'publishrank_seo_news_enabled' => '0', 'publishrank_seo_news_post_types' => array(), 'publishrank_sitemap_post_types' => array() ) );
t( 'empty selection falls back to post; toggle off saved', 'post' === $o['publishrank_sitemap_post_types'] && 0 === $o['publishrank_seo_news_enabled'] );

// person identity
$snap = $o; call_post( $w, 2, array( 'publishrank_identity_type' => 'person', 'publishrank_identity_name' => 'Jane Writer', 'publishrank_identity_country' => 'GB', 'publishrank_identity_logo' => 'https://example.test/me.jpg' ) );
t( 'person identity writes person_* options', 'Jane Writer' === $o['publishrank_identity_person_name'] && 'https://example.test/me.jpg' === $o['publishrank_identity_person_image'] );
$_GET = array( 'step' => '3' ); ob_start(); $w->page(); $x = ob_get_clean(); t( 'person step 3 shows job title, hides legal name', false !== strpos( $x, 'publishrank_identity_person_job_title' ) && false === strpos( $x, 'publishrank_identity_legal_name' ) );
$o = $snap;

// finish
$u = call_post( $w, 8, array() );
t( 'finish completes + unlocks', empty( $o['publishrank_setup_required'] ) && ! empty( $o['publishrank_onboarding_completed_at'] ) && $u && false !== strpos( $u, 'done=1' ) );
$_GET = array( 'page' => 'publishrank-seo-dashboard' ); $r = 'none'; try { $w->maybe_lock_plugin_screens(); } catch ( RedirectException $e ) { $r = 'x'; } t( 'unlocked after finish', 'none' === $r );
$_GET = array( 'page' => 'publishrank-onboarding' ); ob_start(); $w->page(); $x = ob_get_clean(); t( 'completed view shows summary, not modal', false !== strpos( $x, 'Your PublishRank configuration' ) && false === strpos( $x, 'role="dialog"' ) );
$_GET = array( 'page' => 'publishrank-onboarding', 'edit' => '1', 'step' => '2' ); ob_start(); $w->page(); $x = ob_get_clean(); t( 'edit mode is a closable modal', false !== strpos( $x, 'prw-modal__close' ) );
// finish with missing required
$o2 = $o; unset( $o['publishrank_identity_country'], $o['publishrank_country'], $o['publishrank_onboarding_completed_at'], $o['publishrank_onboarding_complete'] ); $o['publishrank_setup_required'] = 1;
$u = call_post( $w, 8, array() ); t( 'finish blocked when required field missing', $u && false !== strpos( $u, 'step=2' ) && empty( $o['publishrank_onboarding_completed_at'] ) );
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n"; exit( $fail ? 1 : 0 );
