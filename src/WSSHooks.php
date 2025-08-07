<?php

namespace WSS;

use ConfigException;
use MediaWiki\Auth\AuthManager;
use MediaWiki\MediaWikiServices;
use MediaWiki\Session\Session;
use MWException;
use Parser;
use WSS\UI\WSSUI;

/**
 * Class WSSHooks
 *
 * @package WSS
 */
abstract class WSSHooks {
	// phpcs:ignore
	const TIMEOUT = 12000;

	/**
	 * Hook WSSpaces into the parser.
	 *
	 * @param Parser $parser
	 * @throws MWException
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$functions = new ParserFunctions();
		$parser->setFunctionHook( 'spaceadmins', [ $functions, 'renderSpaceAdmins' ] );
		$parser->setFunctionHook( 'spacedescription', [ $functions, 'renderSpaceDescription' ] );
		$parser->setFunctionHook( 'spacename', [ $functions, 'renderSpaceName' ] );
		$parser->setFunctionHook( 'spaceprotected', [ $functions, 'renderSpaceProtected' ] );
		$parser->setFunctionHook( 'spaces', [ $functions, 'renderSpaces' ] );
	}

	/**
	 * Affect the return value from AuthManager::securitySensitiveOperationStatus().
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SecuritySensitiveOperationStatus
	 *
	 * @param string $status
	 * @param string $operation
	 * @param Session $session
	 * @param int $timeSinceAuth
	 * @return bool
	 */
	public static function onSecuritySensitiveOperationStatus(
		string &$status,
		string $operation,
		Session $session,
		int $timeSinceAuth
	): bool {
		$security_sensitive_operations = [
			"ws-manage-namespaces",
			"ws-create-namespaces"
		];

		if ( $session->getLoggedOutTimestamp() > 0 ) {
			$status = AuthManager::SEC_FAIL;
		} elseif ( in_array( $operation, $security_sensitive_operations, true ) && $timeSinceAuth > self::TIMEOUT ) {
			$status = AuthManager::SEC_REAUTH;
		} else {
			$status = AuthManager::SEC_OK;
		}

		return true;
	}

	/**
	 * Disallow edits on pages in protected spaces
	 *
	 * @param \Title $title Title that is being edited
	 * @param \User $user User that tries the edit
	 * @param string $action Action that the user tries to do
	 * @param &string $result Error message to display.
	 *
	 * @return bool Whether the user is allowed to edit
	 */
	public static function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();

		if ( !$config->get( 'WSSpacesEnforceProtection' ) ) {
			return true;
		}

		$restrictedActions = $config->get( 'WSSpacesProtectedActions' );

		if ( in_array( $action, $restrictedActions ) ) {
			$ns = $title->getNamespace();
			$space = Space::newFromConstant( $ns );
			if ( $space && !$space->canEditPages( $user ) ) {
				// User cannot edit pages in this space!
				$result = 'wss-edit-protected-page';
				return false;
			}
		}

		return true;
	}

	/**
	 * At the end of Skin::buildSidebar().
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinBuildSidebar
	 *
	 * @param \Skin $skin
	 * @param array $bar
	 * @return bool
	 * @throws ConfigException
	 */
	public static function onSkinBuildSidebar( \Skin $skin, &$bar ): bool {
		if ( !WSSUI::isQueued() ) {
			return true;
		}

		$bar[wfMessage( 'wss-sidebar-header' )->plain()][] = [
			'text' => wfMessage( 'wss-add-space-header' ),
			'href' => \Title::newFromText( "AddSpace", NS_SPECIAL )->getFullUrlForRedirect(),
			'id'   => 'wss-add-space-special',
			'active' => ''
		];

		$bar[wfMessage( 'wss-sidebar-header' )->plain()][] = [
			'text' => wfMessage( 'wss-active-spaces-header' ),
			'href' => \Title::newFromText( "ActiveSpaces", NS_SPECIAL )->getFullUrlForRedirect(),
			'id'   => 'wss-manage-space-special',
			'active' => ''
		];

		$bar[wfMessage( 'wss-sidebar-header' )->plain()][] = [
			'text' => wfMessage( 'wss-archived-spaces-header' ),
			'href' => \Title::newFromText( "ArchivedSpaces", NS_SPECIAL )->getFullUrlForRedirect(),
			'id'   => 'wss-archived-spaces-special',
			'active' => ''
		];

		WSSUI::setSidebar( $bar );

		return true;
	}

	/**
	 * Fired when MediaWiki is updated to allow extensions to register updates for the database schema.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
	 *
	 * @param \DatabaseUpdater $updater
	 * @return bool
	 * @throws MWException
	 */
	public static function onLoadExtensionSchemaUpdates( \DatabaseUpdater $updater ): bool {
		$directory = $GLOBALS['wgExtensionDirectory'] . '/WSSpaces/sql';
		$type = $updater->getDB()->getType();

		// Associative array where the keys are the name of the table and the value the name of the associated SQL file
		$sql_create_files = [
			"wss_namespaces" => "wss_namespaces_table.sql",
			"wss_namespace_admins" => "wss_namespace_admins_table.sql"
		];

		foreach ( $sql_create_files as $table => $sql_file ) {
			$path = sprintf( "%s/%s/%s", $directory, $type, $sql_file );

			if ( !file_exists( $path ) ) {
				throw new MWException( wfMessage( 'wss-unsupported-dbms', $type )->parse() );
			}

			$updater->addExtensionTable( $table, $path );
		}

		$sql_patch_files = [
			[
				'table' => 'wss_namespaces',
				'field' => 'namespace_name',
				'file' => "wss_namespaces_patch_1.sql"
			],
			[
				'table' => 'wss_namespaces',
				'newfield' => 'protected',
				'file' => "wss_namespaces_patch_2.sql"
			],
			[
				'table' => 'wss_namespace_admins',
				'index' => 'wss_namespace_admins_admin_user_id',
				'file' => 'wss_namespace_admins_patch_1.sql'
			],
		];

		foreach ( $sql_patch_files as $patch ) {
			$path = sprintf( "%s/%s/%s", $directory, $type, $patch['file'] );

			if ( !file_exists( $path ) ) {
				throw new MWException( wfMessage( 'wss-unsupported-dbms', $type )->parse() );
			}

			if ( isset( $patch['field'] ) ) {
				$updater->modifyExtensionField( $patch[ 'table' ], $patch[ 'field' ], $path );
			} elseif ( isset( $patch['newfield'] ) ) {
				$updater->addExtensionField( $patch[ 'table' ], $patch[ 'newfield' ], $path );
			} elseif ( isset( $patch['index'] ) ) {
				$updater->addExtensionIndex( $patch[ 'table' ], $patch[ 'index' ], $path );
			} else {
				$updater->modifyExtensionTable( $patch[ 'table' ], $path );
			}
		}

		return true;
	}

	/**
	 * For extensions adding their own namespaces or altering the defaults.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/CanonicalNamespaces
	 *
	 * @param array $namespaces
	 * @return bool
	 *
	 * @throws ConfigException
	 */
	public static function onCanonicalNamespaces( array &$namespaces ): bool {
		$namespace_repository = new NamespaceRepository();
		$spaces = $namespace_repository->getSpaces();
		foreach ( $spaces as $id => $space ) {
			$spaces[$id + 1] = $space . "_talk";
		}

		$namespaces += $spaces;

		return true;
	}

	/**
	 * A hook of the UserMerge extension: If a user is merged, do migrate the admin table accordingly
	 *
	 * @see https://www.mediawiki.org/wiki/Extension:UserMerge/Hooks/UserMergeAccountFields
	 *
	 * @param array $updateFields The fields as described on the documentation page
	 */
	public static function onUserMergeAccountFields( &$updateFields ) {
		$updateFields [] = [ 'wss_namespace_admins', 'admin_user_id', 'options' => [ 'IGNORE' ] ];
	}

	/**
	 * Called when generating the extensions credits, use this to change the tables headers.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ExtensionTypes
	 *
	 * @param array $extension_types
	 * @return bool
	 */
	public static function onExtensionTypes( array &$extension_types ): bool {
		$extension_types['csp'] = wfMessage( "version-csp" )->parse();
		return true;
	}
}
