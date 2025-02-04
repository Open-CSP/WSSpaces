<?php

namespace WSS;

use Config;
use ConfigException;
use ExtensionRegistry;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserGroupManager;
use MWException;
use PermissionsError;
use RequestContext;
use User;
use Wikimedia\Rdbms\ILoadBalancer;
use WSS\Log\AddSpaceLog;
use WSS\Log\ArchiveSpaceLog;
use WSS\Log\UnarchiveSpaceLog;
use WSS\Log\UpdateSpaceLog;

class NamespaceRepository {
	// Lowest allowed ID for a space.
	// phpcs:ignore
	const MIN_SPACE_ID = 50000;

	/**
	 * @var array
	 */
	private $canonical_namespaces;

	/**
	 * @var array
	 */
	private $extension_namespaces;

	/**
	 * @var DBLoadBalancer
	 */
	private $dbLoadBalancer;

	/**
	 * NamespaceRepository constructor.
	 *
	 * @throws ConfigException
	 */
	public function __construct() {
		$this->canonical_namespaces = [ NS_MAIN => 'Main' ] + $this->getConfig()->get( 'CanonicalNamespaceNames' );
		$this->extension_namespaces = ExtensionRegistry::getInstance()->getAttribute( 'ExtensionNamespaces' );
		$this->dbLoadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
	}

	/**
	 * Returns the next available namespace id.
	 *
	 * @return int
	 */
	public static function getNextAvailableNamespaceId(): int {
		$dbr = self::getConnection( MediaWikiServices::getInstance()->getDBLoadBalancer() );

		$result = $dbr->newSelectQueryBuilder()->select(
			'namespace_id'
		)->from(
			  'wss_namespaces'
		)->orderBy(
			'namespace_id',
			'DESC'
		)->caller( __METHOD__ )->fetchField();

		if ( $result === false ) {
			return self::MIN_SPACE_ID;
		}

		// + 2 because we need to skip the talk page.
		return $result + 2;
	}

	/**
	 * Gets all namespaces. When the first parameter is true, the key will be the name
	 * of the namespace, and the value the constant, otherwise the key will be the namespace constant and
	 * the value the namespace name.
	 *
	 * @param bool $flip
	 * @return array
	 */
	public function getAllNamespaces( $flip = false ): array {
		$canonical_namespaces = $this->getCanonicalNamespaces();
		$extension_namespaces = $this->getExtensionNamespaces();
		$spaces               = $this->getAllSpaces();

		$namespaces = $canonical_namespaces + $extension_namespaces + $spaces;

		return $flip ? array_flip( $namespaces ) : $namespaces;
	}

	/**
	 * Returns the list of all (archived and unarchived) dynamic spaces defined by the WSS extension. When the first
	 * parameter is true, the key will be the name of the namespace, and the value the
	 * constant, otherwise the key will be the namespace constant and the value the namespace name.
	 *
	 * @param bool $flip
	 * @return array
	 */
	public function getAllSpaces( $flip = false ): array {
		$dbr = self::getConnection( $this->dbLoadBalancer, DB_REPLICA );

		// In some rare cases, update.php might look up namespaces before
		// the database has been set up for this extension.
		// Give a reasonable default in that case.
		if ( !$dbr->tableExists( 'wss_namespaces', __METHOD__ ) ) {
			return [];
		}
		$result = $dbr->newSelectQueryBuilder()->select(
			[
				'namespace_id',
				'namespace_key'
			]
		)->from(
			'wss_namespaces'
		)->caller( __METHOD__ )->fetchResultSet();

		$buffer = [];
		foreach ( $result as $item ) {
			$buffer[$item->namespace_id] = $item->namespace_key;
		}

		return $flip ? array_flip( $buffer ) : $buffer;
	}

	/**
	 * Returns a numbered list of all admins for namespaces defined by the WSS extension. The parameter passed is the
	 * namespace id for which a list of admins is requested.
	 *
	 * @param int $namespace_id
	 * @return array
	 */
	public static function getNamespaceAdmins( int $namespace_id ): array {
		$dbr = self::getConnection( MediaWikiServices::getInstance()->getDBLoadBalancer(), DB_REPLICA );

		// In some rare cases, update.php might look up namespace admins before
		// the database has been set up for this extension.
		// Give a reasonable default in that case.
		if ( !$dbr->tableExists( 'wss_namespace_admins', __METHOD__ ) ) {
			return [];
		}
		$result = $dbr->newSelectQueryBuilder()->select(
			[
				'namespace_id',
				'admin_user_id'
			]
		)->from(
			'wss_namespace_admins'
		)->where(
			[
				'namespace_id' => $namespace_id
			]
		)->caller( __METHOD__ )->fetchResultSet();

		$buffer = [];
		foreach ( $result as $item ) {
			$buffer[] = $item->admin_user_id;
		}

		return $buffer;
	}

	/**
	 * Returns the list of unarchived dynamic spaces defined by the WSS extension. When the first parameter is true,
	 * the key will be the name of the namespace, and the value the constant, otherwise
	 * the key will be the namespace constant and the value the namespace name.
	 *
	 * @param bool $flip
	 * @return array
	 */
	public function getSpaces( $flip = false ): array {
		$result = $this->getSpacesOnArchived( false );
		return $flip ? array_flip( $result ) : $result;
	}

	/**
	 * Returns the list of archived dynamic spaces defined by the WSS extension. When
	 * the first parameter is true, the key will be the name of the namespace, and the value the constant,
	 * otherwise the key will be the namespace constant and the value the namespace name.
	 *
	 * @param bool $flip
	 * @return array
	 */
	public function getArchivedSpaces( $flip = false ): array {
		$result = $this->getSpacesOnArchived( true );
		return $flip ? array_flip( $result ) : $result;
	}

	/**
	 * Returns the list of canonical namespace names as a key-value pair. When the first parameter is true, the key
	 * will be the name of the namespace, and the value the constant, otherwise the key will be the namespace
	 * constant and the value the namespace name.
	 *
	 * @param bool $flip
	 * @return array
	 */
	public function getCanonicalNamespaces( $flip = false ): array {
		return $flip ? array_flip( $this->canonical_namespaces ) : $this->canonical_namespaces;
	}

	/**
	 * Returns the list of namespace names defined by MediaWiki extensions. When the first parameter is true, the
	 * key will be the name of the namespace, and the value the constant, otherwise the key will be the namespace
	 * constant and the value the namespace name.
	 *
	 * @param bool $flip
	 * @return array
	 */
	public function getExtensionNamespaces( $flip = false ): array {
		return $flip ? array_flip( $this->extension_namespaces ) : $this->extension_namespaces;
	}

	/**
	 * Adds the given Space to the database.
	 *
	 * @param Space $space
	 * @return int The ID of the created namespace
	 * @throws MWException
	 * @throws ConfigException
	 * @throws \Exception
	 */
	public function addSpace( Space $space ): int {
		if ( $space->exists() ) {
			throw new \InvalidArgumentException(
				"Cannot add existing space to database, use NamespaceRepository::updateSpace() instead."
			);
		}

		// We publish the log first, since we ...?
		$log = new AddSpaceLog( $space );
		$log->insert();

		$namespace_id = self::getNextAvailableNamespaceId();

		$database = self::getConnection( $this->dbLoadBalancer );
		$database->insert(
		'wss_namespaces',  [
			'namespace_id' => $namespace_id,
			'namespace_name' => $space->getName(),
			'namespace_key' => $space->getKey(),
			'description' => $space->getDescription(),
			'archived' => $space->isArchived() ? '1' : '0',
			'creator_id' => $space->getOwner()->getId(),
			'created_on' => time()
		] );

		// Create a new space from the name, go get the latest details from the database.
		$space = Space::newFromConstant( $namespace_id );

		// Run the hook so any custom actions can be taken on our new space.
		MediaWikiServices::getInstance()->getHookContainer()->run(
			"WSSpacesAfterCreateSpace",
			[ $space ]
		);

		// Set the admins. Do this after running the WSSpacesAfterCreateSpace hook!
		$space->setSpaceAdministrators( [ $space->getOwner()->getName() ] );
		$this->updateSpaceAdministrators( $database, $space );

		$log->publish();

		return $namespace_id;
	}

	/**
	 * Updates an existing space in the database.
	 *
	 * @param Space|false $old_space
	 * @param Space $new_space
	 * @param bool $force True to force the creation of the space and skip the permission check
	 * @param bool $log Whether or not to log this update (true by default)
	 *
	 * @throws MWException
	 * @throws PermissionsError
	 */
	public function updateSpace( $old_space, Space $new_space, bool $force = false, bool $log = true ) {
		if ( $old_space === false || !$old_space->exists() ) {
			throw new \InvalidArgumentException(
				"Cannot update non-existing space in database, use NamespaceRepository::addSpace() instead."
			);
		}

		// Last minute check to see if the user actually does have enough permissions to edit this space.
		if ( !$new_space->canEdit() && !$force ) {
			throw new PermissionsError( "Not enough permissions to edit this space." );
		}

		if ( $log ) {
			$log = new UpdateSpaceLog( $old_space, $new_space );
			$log->insert();
		}

		$database = self::getConnection( $this->dbLoadBalancer );
		$database->update( 'wss_namespaces', [
			'namespace_key' => $new_space->getKey(),
			'namespace_name' => $new_space->getName(),
			'description' => $new_space->getDescription(),
			'creator_id' => $new_space->getOwner()->getId(),
			'archived' => $new_space->isArchived() ? '1' : '0'
		], [
			'namespace_id' => $old_space->getId()
		] );

		$this->updateSpaceAdministrators( $database, $new_space );

		if ( $log ) {
			$log->publish();
		}
	}

	/**
	 * Helper function to archive a namespace.
	 *
	 * @param Space $space
	 * @throws MWException
	 * @throws PermissionsError
	 */
	public function archiveSpace( Space $space ) {
		$log = new ArchiveSpaceLog( $space );
		$log->insert();

		$new_space = clone $space;
		$new_space->setArchived();

		// Because of the way "updateSpace" works, we need a clone of the original
		// space
		$this->updateSpace( $space, $new_space, false, false );

		$log->publish();
	}

	/**
	 * Helper function to unarchive a namespace.
	 *
	 * @param Space $space
	 * @throws MWException
	 * @throws PermissionsError
	 */
	public function unarchiveSpace( Space $space ) {
		$log = new UnarchiveSpaceLog( $space );
		$log->insert();

		$new_space = clone $space;
		$new_space->setArchived( false );

		$this->updateSpace( $space, $new_space, false, false );

		$log->publish();
	}

	/**
	 * Returns the main MediaWiki configuration.
	 *
	 * @return Config
	 */
	private function getConfig(): Config {
		return MediaWikiServices::getInstance()->getMainConfig();
	}

	/**
	 * Updates the space administrators for the given space. Should only be called by self::updateSpace().
	 *
	 * @param \Database|\DBConnRef $database
	 * @param Space $space
	 */
	private function updateSpaceAdministrators( $database, Space $space ) {
		$namespace_id = $space->getId();
		$space_administrators = $space->getSpaceAdministrators();
		$rows = $this->createRowsFromSpaceAdministrators( $space_administrators, $namespace_id );

		// Get the admins that were saved to mw last time.
		$mw_saved_admins = $this->getNamespaceAdmins( $space->getId() );

		// Get the admins that were input as part of the change space form.
		$admin_input = array_map( function ( $row ) {
			return $row["admin_user_id"];
		}, $rows );

		// Check which admins disappeared in the new input.
		$difference_of_admins = array_diff( $mw_saved_admins, $admin_input );

		// Check which admins remained the same in the new input.
		$intersection_of_admins = array_intersect( $mw_saved_admins, $admin_input );

		// Get the MW User Group Manager and prepare the names for the space.
		$user_group_manager = MediaWikiServices::getInstance()->getUserGroupManager();

		// If it is required that Admins are automatically removed to User Groups, perform the remove operation here:
		if ( MediaWikiServices::getInstance()->getMainConfig()->get( "WSSpacesAutoAddAdminsToUserGroups" ) ) {
			foreach ( $difference_of_admins as $admin ) {
				$admin_object = User::newFromId( (int)$admin );
				if ( !$admin_object->loadFromDatabase() ) {
					// If the user doesn't exist, we can't change their usergroups
					continue;
				}

				$this->removeUserFromUserGroup( $admin_object, $space->getGroupName(), $user_group_manager );

				// Check if a user is part of at least one space admin group. If so,
				// allow them to keep the SpaceAdmin group membership.
				$remain_space_admin = false;
				$admin_user_groups = $user_group_manager->getUserGroups( $admin_object );

				foreach ( $admin_user_groups as $checked_group ) {
					if ( ( strpos( $checked_group, "Admin" ) !== false ) && $checked_group !== "SpaceAdmin" ) {
						$remain_space_admin = true;
					}
				}

				// Remove the user from the SpaceAdmin group if they are not allowed to remain space admin.
				$admin_user_groups = $user_group_manager->getUserGroups( $admin_object );
				if ( !$remain_space_admin ) {
					if ( in_array( "SpaceAdmin", $admin_user_groups, true ) ) {
						$this->removeUserFromUserGroup( $admin_object, "SpaceAdmin", $user_group_manager );
					}
				}
			}
		}

		// Do the actual database changes.
		$database->delete( 'wss_namespace_admins', [
			"namespace_id" => $namespace_id
		] );

		$database->insert( 'wss_namespace_admins', $rows );

		// If it is required that Admins are automatically added to User Groups, perform the add operation here:
		if ( MediaWikiServices::getInstance()->getMainConfig()->get( "WSSpacesAutoAddAdminsToUserGroups" ) ) {
			foreach ( $admin_input as $admin ) {
				$admin_object = User::newFromId( $admin );

				// If the user was not an overarching space admin before, add them to this group now.
				if ( !in_array( "SpaceAdmin", $user_group_manager->getUserGroups( $admin_object ), true ) ) {
					$this->addUserToUserGroup( $admin_object, "SpaceAdmin", $user_group_manager );
				}

				// If the user was not an admin of the altered space before, add them now.
				// Also send the space along, just in case no system message was set.
				if ( !in_array( $admin, $intersection_of_admins, false ) ) {
					$this->addUserToUserGroup( $admin_object, $space->getGroupName(), $user_group_manager );
				}
			}
		}
	}

	/**
	 * Adds a user to a user group and notifies MediaWiki of this.
	 *
	 * @param User $user The user object for the user that is being added.
	 * @param string $user_group The user group that the user is being added to.
	 * @param UserGroupManager $groupManager The user group manager for the current context.
	 */
	private function addUserToUserGroup(
		User $user,
		string $user_group,
		UserGroupManager $groupManager
	): void {
		if ( ( wfMessage( "group-SpaceAdmin-member" )->exists() ) && ( $user_group === "SpaceAdmin" ) ) {
			$user_message = wfMessage( "group-SpaceAdmin-member" )->parse();
		} elseif ( wfMessage( "group-" . $user_group )->exists() ) {
			$user_message = wfMessage( "group-" . $user_group )->parse();
		} else {
			$user_message = $user_group;
		}

		MediaWikiServices::getInstance()->getHookContainer()->run(
			"UserGroupsChanged",
			[ $user, [ $user_message ], [], RequestContext::getMain()->getUser() ]
		);

		$groupManager->addUserToGroup( $user, $user_group );
	}

	/**
	 * Removes a user from a user group and notifies MediaWiki of this.
	 *
	 * @param User $user The user object for the user that is being removed.
	 * @param string $userGroup The user group that the user is being removede from.
	 * @param UserGroupManager $groupManager The user group manager for the current context.
	 */
	private function removeUserFromUserGroup( User $user, string $userGroup, UserGroupManager $groupManager ): void {
		if ( ( wfMessage( "group-SpaceAdmin-member" )->exists() ) && ( $userGroup === "SpaceAdmin" ) ) {
			$user_message = wfMessage( "group-SpaceAdmin-member" )->parse();
		} elseif ( wfMessage( "group-" . $userGroup )->exists() ) {
			$user_message = wfMessage( "group-" . $userGroup )->parse();
		} else {
			$user_message = $userGroup;
		}

		MediaWikiServices::getInstance()->getHookContainer()->run(
			"UserGroupsChanged",
			[ $user, [], [ $user_message ], RequestContext::getMain()->getUser() ]
		);

		$groupManager->removeUserFromGroup( $user, $userGroup );
	}

	/**
	 * Gets all spaces available to the current logged in user, based on whether they are archived
	 * or not.
	 *
	 * @param bool $archived True to only get archived spaces, false otherwise
	 * @return array
	 */
	private function getSpacesOnArchived( bool $archived ): array {
		$dbr = self::getConnection( $this->dbLoadBalancer, DB_REPLICA );
		if ( !$dbr->tableExists( 'wss_namespaces', __METHOD__ ) ) {
			return [];
		}
		$result = $dbr->newSelectQueryBuilder()->select(
			[
				'namespace_id',
				'namespace_key'
			]
		)->from(
			'wss_namespaces'
		)->where(
			[
				'archived' => $archived ? '1' : '0'
			]
		)->caller( __METHOD__ )->fetchResultSet();

		$buffer = [];

		foreach ( $result as $item ) {
			$buffer[$item->namespace_id] = $item->namespace_key;
		}

		return $buffer;
	}

	/**
	 * @param array $administrators
	 * @param int $namespace_id
	 * @return array
	 */
	private function createRowsFromSpaceAdministrators( array $administrators, $namespace_id ) {
		// FIXME: Make this more readable
		$rows = array_map(
			function ( int $admin_id ) use ( $namespace_id ): array {
				return [
					"namespace_id" => $namespace_id,
					"admin_user_id" => $admin_id
				];
			},
			array_filter(
				array_map(
					function ( string $admin ): int {
						// This function returns the ID of the given administrator, or 0 if they dont exist.
						$user = \User::newFromName( $admin );

						if ( !$user instanceof User ) {
							return 0;
						}

						return $user->isAnon() ? 0 : $user->getId();
					}, $administrators
				),
				function ( int $id ): bool {
					return $id !== 0;
				}
			)
		);

		return array_values( $rows );
	}

	private static function getConnection( ILoadBalancer $dbLoadBalancer, int $i = DB_PRIMARY ) {
		if ( method_exists( $dbLoadBalancer, 'getConnection' ) ) {
			return $dbLoadBalancer->getConnection( $i );
		} else {
			return $dbLoadBalancer->getConnectionRef( $i );
		}
	}
}
