<?php
/** Run with php tests/roles.php; isolated legacy role migration fixture. */
require __DIR__ . '/../src/Main.php';

class Role_Fixture {
	public $capabilities;
	public function __construct( $caps ) { $this->capabilities = $caps; }
	public function has_cap( $cap ) { return ! empty( $this->capabilities[ $cap ] ); }
}
class User_Fixture {
	public $roles;
	public function __construct( $roles ) { $this->roles = $roles; }
	public function add_role( $role ) { $this->roles = array_unique( array_merge( $this->roles, array( $role ) ) ); }
	public function remove_role( $role ) { $this->roles = array_values( array_diff( $this->roles, array( $role ) ) ); }
}
function get_option( $key ) { return $GLOBALS['options'][ $key ] ?? false; }
function update_option( $key, $value ) { $GLOBALS['options'][ $key ] = $value; }
function get_role( $slug ) { return isset( $GLOBALS['roles'][ $slug ] ) ? new Role_Fixture( $GLOBALS['roles'][ $slug ]['capabilities'] ) : null; }
function wp_roles() { return (object) array( 'roles' => $GLOBALS['roles'] ); }
function get_users( $args ) { return array_slice( array_values( array_filter( $GLOBALS['users'], function ( $user ) use ( $args ) { return in_array( $args['role'], $user->roles, true ); } ) ), 0, $args['number'] ); }
function remove_role( $slug ) { unset( $GLOBALS['roles'][ $slug ] ); }

require __DIR__ . '/../src/Upgrade.php';
$GLOBALS['options'] = array( 'family_wiki_previous_install' => 1, 'default_role' => 'wiki-user' );
$GLOBALS['roles'] = array(
	'editor' => array( 'capabilities' => array( 'edit_others_pages' => true ) ),
	'administrator' => array( 'capabilities' => array( 'edit_others_pages' => true ) ),
	'subscriber' => array( 'capabilities' => array() ),
	'wiki-user' => array( 'capabilities' => array( 'wiki-user' => true ) ),
	'wiki-editor' => array( 'capabilities' => array( 'wiki-editor' => true ) ),
	'renamed-wiki' => array( 'capabilities' => array( 'wiki-user' => true ) ),
);
$cases = array(
	array( array( 'wiki-user' ), array( 'editor' ) ),
	array( array( 'wiki-editor' ), array( 'editor' ) ),
	array( array( 'administrator', 'wiki-user' ), array( 'administrator' ) ),
	array( array( 'subscriber', 'wiki-user', 'wiki-editor' ), array( 'subscriber', 'editor' ) ),
	array( array( 'renamed-wiki' ), array( 'editor' ) ),
	array( array( 'subscriber' ), array( 'subscriber' ) ),
);
$GLOBALS['users'] = array();
foreach ( $cases as $case ) { $GLOBALS['users'][] = new User_Fixture( $case[0] ); }
for ( $i = 0; $i < 205; ++$i ) { $GLOBALS['users'][] = new User_Fixture( array( 'wiki-user' ) ); }
Family_Wiki\Main::setup_roles();
foreach ( $GLOBALS['users'] as $i => $user ) {
	$expected = isset( $cases[ $i ] ) ? $cases[ $i ][1] : array( 'editor' );
	if ( $user->roles !== $expected ) { throw new RuntimeException( 'Incorrect migrated roles for user ' . $i ); }
}
if ( get_role( 'wiki-user' ) || get_role( 'wiki-editor' ) || get_role( 'renamed-wiki' ) || 'subscriber' !== get_option( 'default_role' ) ) {
	throw new RuntimeException( 'Legacy role cleanup failed' );
}
Family_Wiki\Main::setup_roles();
echo "Role migration, batching, multiple roles and repeat-run checks passed.\n";
