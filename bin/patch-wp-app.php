<?php
/**
 * Apply the PHP 8 compatibility fix to the bundled wp-app 1.9 dependency.
 *
 * Its asset helpers put a required scope after optional arguments. PHP 8
 * deprecates those declarations; null already invokes the library's existing
 * missing-scope warning. Keep the fix reproducible after Composer installs.
 *
 * @package Family_Wiki
 */

$family_wiki_functions = dirname( __DIR__ ) . '/vendor/akirk/wp-app/src/functions.php';
if ( ! file_exists( $family_wiki_functions ) ) {
	exit( 1 );
}
$family_wiki_source = file_get_contents( $family_wiki_functions );
$family_wiki_fixed = str_replace(
	array( '$ver = false, $scope )', '$in_footer = true, $scope )' ),
	array( '$ver = false, $scope = null )', '$in_footer = true, $scope = null )' ),
	$family_wiki_source
);
if ( $family_wiki_source !== $family_wiki_fixed ) {
	file_put_contents( $family_wiki_functions, $family_wiki_fixed );
}
