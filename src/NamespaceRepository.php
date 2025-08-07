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

	const ADMIN_GROUP = 'SpaceAdmin';

	const GROUP_CHANGE_REASON = 'Namespace admin changes';

	/**
	 * @var array
	 */
	private $canonical_namespaces;

	/**
	 * @var array
	 */
	private $extension_namespaces;

	private UserGroupManager $user_group_manager;

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

	public function getSpacesForAdmin( int $admin_user_id ): array {
		$dbr = self::getConnection( $this->dbLoadBalancer );

		$result = $dbr->newSelectQueryBuilder()->select(
			'*'
		)->from(
			'wss_namespace_admins'
		)->join(
			'wss_namespaces', 'spaces', 'spaces.namespace_id=wss_namespace_admins.namespace_id'
		)->where( [
			'admin_user_id' => $admin_user_id
		] )->caller( __METHOD__ )->fetchResultSet();

		$buffer = [];
		foreach( $result as $spaceRow ) {
			$buffer[] = Space::newFromDbRow( $spaceRow, $dbr );
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
			'protected' => $space->isProtected() ? '1' : '0',
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
			'archived' => $new_space->isArchived() ? '1' : '0',
			'protected' => $new_space->isProtected() ? '1' : '0',
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

		// If it is required that Admins are automatically removed from User Groups, perform the remove operation here:
		if ( MediaWikiServices::getInstance()->getMainConfig()->get( "WSSpacesAutoAddAdminsToUserGroups" ) ) {
			$this->removeAdminUserGroups( $difference_of_admins, $space );
		}

		// Do the actual database changes.
		$database->delete( 'wss_namespace_admins', [
			"namespace_id" => $namespace_id
		] );

		$database->insert( 'wss_namespace_admins', $rows );

		// If it is required that Admins are automatically added to User Groups, perform the add operation here:
		if ( MediaWikiServices::getInstance()->getMainConfig()->get( "WSSpacesAutoAddAdminsToUserGroups" ) ) {
			$this->addAdminUserGroups( $admin_input, $space );
		}
	}

	private function removeAdminUserGroups( $difference_of_admins, $space ) {
		foreach ( $difference_of_admins as $admin ) {
			$admin_object = User::newFromId( (int)$admin );
			if ( !$admin_object->loadFromDatabase() ) {
				// If the user doesn't exist, we can't change their usergroups
				continue;
			}

			$groupsToRemove = [ $space->getGroupName(), self::ADMIN_GROUP ];

			// Check if a user is part of at least one other space admin group. If so,
			// allow them to keep the SpaceAdmin group membership.
			$admin_user_groups = $this->getUserGroupManager()->getUserGroups( $admin_object );
			foreach ( $admin_user_groups as $checked_group ) {
				if (
					!in_array( $checked_group, $groupsToRemove ) &&
					( strpos( $checked_group, "Admin" ) !== false )
				) {
					unset( $groupsToRemove[1] );
					break;
				}
			}

			// We only remove groups that the user is actually part of
			$groupsToRemove = array_intersect( $groupsToRemove, $admin_user_groups );

			if ( !empty( $groupsToRemove ) ) {
				$this->removeUserFromUserGroups( $admin_object, $groupsToRemove );
			}
		}
	}

	private function addAdminUserGroups( $admin_input, $space ) {
		foreach ( $admin_input as $admin ) {
			$admin_object = User::newFromId( $admin );

			$groups = $this->getUserGroupManager()->getUserGroups( $admin_object );

			$groupsToAdd = [ $space->getGroupName(), self::ADMIN_GROUP ];

			// Only add to groups that user is not in yet
			$groupsToAdd = array_diff( $groupsToAdd, $groups );

			if ( !empty( $groupsToAdd ) ) {
				$this->addUserToUserGroups( $admin_object, $groupsToAdd );
			}
		}
	}

	/**
	 * Adds a user to user groups and notifies MediaWiki of this.
	 *
	 * @param User $user The user object for the user that is being added.
	 * @param string[] $user_groups The user groups that the user is being added to.
	 */
	private function addUserToUserGroups(
		User $user,
		array $user_groups
	): void {
		$user_messages = [];

		$oldGroupMemberships = $this->getUserGroupManager()->getUserGroupMemberships( $user );

		foreach( $user_groups as $user_group ) {
			$this->getUserGroupManager()->addUserToGroup( $user, $user_group );
			$user_messages []= $this->getUserMessage( $user_group );
		}

		$newGroupMemberships = $this->getUserGroupManager()->getUserGroupMemberships( $user );

		MediaWikiServices::getInstance()->getHookContainer()->run(
			"UserGroupsChanged",
			[
				$user,
				$user_messages,
				[],
				RequestContext::getMain()->getUser(),
				self::GROUP_CHANGE_REASON,
				$oldGroupMemberships,
				$newGroupMemberships
			]
		);
	}

	/**
	 * Removes a user from user groups and notifies MediaWiki of this.
	 *
	 * @param User $user The user object for the user that is being removed.
	 * @param string[] $userGroups The user group that the user is being removed from.
	 */
	private function removeUserFromUserGroups( User $user, array $userGroups ): void {
		$user_messages = [];

		$oldGroupMemberships = $this->getUserGroupManager()->getUserGroupMemberships( $user );

		foreach ( $userGroups as $userGroup ) {
			$this->getUserGroupManager()->removeUserFromGroup( $user, $userGroup );
			$user_messages []= $this->getUserMessage( $userGroup );
		}

		$newGroupMemberships = $this->getUserGroupManager()->getUserGroupMemberships( $user );

		MediaWikiServices::getInstance()->getHookContainer()->run(
			"UserGroupsChanged",
			[
				$user,
				[],
				$user_messages,
				RequestContext::getMain()->getUser(),
				self::GROUP_CHANGE_REASON,
				$oldGroupMemberships,
				$newGroupMemberships
			]
		);
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

	private function getUserGroupManager(): UserGroupManager {
		return $this->user_group_manager ??= MediaWikiServices::getInstance()->getUserGroupManager();
	}

	private function getUserMessage( string $user_group ): string {
		if ( $user_group === self::ADMIN_GROUP ) {
			$msg = wfMessage( "group-" . self::ADMIN_GROUP . "-member" );
			if ( $msg->exists() )  {
				return $msg->parse();
			}
		} else {
			$msg = wfMessage( "group-" . $user_group );
			if ( $msg->exists() ) {
				return $msg->parse();
			}
		}

		return $user_group;
	}
}
