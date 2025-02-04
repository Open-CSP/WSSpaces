<?php

namespace WSS;

use MediaWiki\MediaWikiServices;
use RequestContext;
use User;

class Space {
	// phpcs:ignore
	const DEFAULT_NAMESPACE_CONSTANT = 0;

	/**
	 * @var User
	 */
	private $namespace_owner;

	/**
	 * @var string
	 */
	private $description;

	/**
	 * @var int
	 */
	private $namespace_id;

	/**
	 * @var int
	 */
	private $talkspace_id;

	/**
	 * @var string
	 */
	private $namespace_key;

	/**
	 * @var bool
	 */
	private $is_archived;

	/**
	 * @var array
	 */
	private $namespace_administrators;

	/**
	 * @var string
	 */
	private $namespace_name;

	/**
	 * Space constructor.
	 *
	 * @param string $namespace_key The canonical name of the namespace.
	 * @param string $namespace_name The name of the namespace.
	 * @param int $namespace_id The ID of the namespace. MUST be an even number.
	 * @param string $description The description of the namespace.
	 * @param User $namespace_owner The owner of the namespace.
	 * @param bool $is_archived Whether or not the space is archived.
	 * @param array $namespace_administrators The administrators of this namespace.
	 */
	private function __construct(
		string $namespace_key,
		string $namespace_name,
		int $namespace_id,
		string $description,
		User $namespace_owner,
		bool $is_archived = false,
		array $namespace_administrators = []
	) {
		if ( $namespace_id % 2 !== 0 ) {
			throw new \InvalidArgumentException(
				"Namespace ID must be an even number; '$namespace_id' is not even'"
			);
		}

		if ( empty( $namespace_key ) ) {
			throw new \InvalidArgumentException( "Namespace name must not be empty." );
		}

		if ( !ctype_alnum( $namespace_key ) ) {
			throw new \InvalidArgumentException(
				"A namespace name can only consist of alphanumerical characters, " .
				"therefore $namespace_key is invalid."
			);
		}

		$this->namespace_id    = $namespace_id;
		$this->talkspace_id    = $namespace_id + 1;
		$this->is_archived     = $is_archived;

		$this->namespace_name = $namespace_name;

		$namespace_key = ucfirst( $namespace_key );

		$this->setKey( $namespace_key );
		$this->setDescription( $description );
		$this->setOwner( $namespace_owner );
		$this->setSpaceAdministrators( $namespace_administrators );
	}

	/**
	 * Returns a new space object from the given values.
	 *
	 * @param string $namespace_key
	 * @param string $namespace_name
	 * @param string $description
	 * @param User $user
	 * @return Space
	 */
	public static function newFromValues(
		string $namespace_key,
		string $namespace_name,
		string $description,
		User $user
	): Space {
		return new Space(
			$namespace_key,
			$namespace_name,
			self::DEFAULT_NAMESPACE_CONSTANT,
			$description,
			$user
		);
	}

	/**
	 * Returns a new space object from the given namespace constant.
	 *
	 * @param int $namespace_constant
	 * @return bool|Space
	 * @throws \ConfigException
	 */
	public static function newFromConstant( int $namespace_constant ) {
		if ( $namespace_constant % 2 ) {
			// Get subject namespace if this is a talk namespace.
			$namespace_constant--;
		}

		return self::newFromRow( [ 'namespace_id' => $namespace_constant ] );
	}

	/**
	 * Returns a new space object from the given namespace name.
	 *
	 * @param string $nsName
	 * @return bool|Space
	 * @throws \ConfigException
	 */
	public static function newFromName( string $nsName ) {
		return self::newFromRow( [ 'namespace_name' => $nsName ] );
	}

	/**
	 * Returns a new space object from the given namespace key.
	 *
	 * @param string $nsKey
	 * @return bool|Space
	 * @throws \ConfigException
	 */
	public static function newFromKey( string $nsKey ) {
		return self::newFromRow( [ 'namespace_key' => $nsKey ] );
	}

	/**
	 * Returns a new space object for the given condition. Should be a condition on the wss_spaces table
	 *
	 * @param array $condition The condition that we query on
	 * @return Space|false     The (first) space that satisfies $condition
	 */
	private static function newFromRow( array $condition ) {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef( DB_REPLICA );

		// It might happen that this function is called during run of update.php,
		// while database is not property set up. In that case, give a sensible return value.
		if ( !$dbr->tableExists('wss_namespaces', __METHOD__ ) ) {
			return false;
		}
		$namespace = $dbr->newSelectQueryBuilder()->select(
			[ 'namespace_id', 'namespace_key', 'namespace_name', 'description', 'creator_id', 'archived' ]
		)->from(
			'wss_namespaces'
		)->where(
			$condition
		)->caller( __METHOD__ )->fetchRow();

		if ( $namespace === false ) {
			return false;
		}

		$user = User::newFromId( $namespace->creator_id );

		if ( !$user instanceof User ) {
			throw new \InvalidArgumentException( "Invalid creator_id '{$namespace->creator_id}'" );
		}

		$namespace_administrators = array_map( function ( $row ): string {
			return User::newFromId( $row->admin_user_id )->getName();
		}, iterator_to_array( $dbr->newSelectQueryBuilder()->select(
			'admin_user_id'
		)->from(
			'wss_namespace_admins'
		)->where(
			[ 'namespace_id' => $namespace->namespace_id ]
		)->caller( __METHOD__ )->fetchResultSet() ) );

		return new Space(
			$namespace->namespace_key,
			$namespace->namespace_name,
			$namespace->namespace_id,
			$namespace->description,
			$user,
			$namespace->archived,
			$namespace_administrators
		);
	}

	/**
	 * Returns the canonical name of this namespace.
	 *
	 * @return string
	 */
	public function getKey(): string {
		return ucfirst( $this->namespace_key );
	}

	/**
	 * Returns the name of this namespace.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->namespace_name;
	}

	/**
	 * Returns the id of this space.
	 *
	 * @return int
	 */
	public function getId(): int {
		if ( !$this->exists() ) {
			throw new \BadFunctionCallException( "Cannot call getId on a space that does not exist." );
		}

		return $this->namespace_id;
	}

	/**
	 * Returns the talkspace id of this space.
	 *
	 * @return int
	 */
	public function getTalkId(): int {
		if ( !$this->exists() ) {
			throw new \BadFunctionCallException( "Cannot call getTalkId on a space that does not exist." );
		}

		return $this->talkspace_id;
	}

	/**
	 * Returns an array of administrators of this space.
	 *
	 * @return array
	 */
	public function getSpaceAdministrators(): array {
		return $this->namespace_administrators;
	}

	/**
	 * Returns true if and only if the Space is archived.
	 *
	 * @return bool
	 */
	public function isArchived(): bool {
		return $this->is_archived;
	}

	/**
	 * Returns the description of this space.
	 *
	 * @return string
	 */
	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * Returns the owner of this space.
	 *
	 * @return User
	 */
	public function getOwner(): User {
		return $this->namespace_owner;
	}

	/**
	 * Returns the group name of this space.
	 *
	 * @return string
	 */
	public function getGroupName(): string {
		if ( !$this->exists() ) {
			throw new \BadFunctionCallException( "Cannot call getId on a space that does not exist." );
		}

		return strval( $this->namespace_id ) . "Admin";
	}

	/**
	 * Sets the name for this namespace.
	 *
	 * @param string $name
	 * @throws \ConfigException
	 */
	public function setKey( string $name ) {
		$this->namespace_key = $name;
	}

	/**
	 * Sets the display name for this namespace.
	 *
	 * @param string $name
	 */
	public function setName( string $name ) {
		$this->namespace_name = $name;
	}

	/**
	 * Sets the description for this Space.
	 *
	 * @param string $description
	 */
	public function setDescription( string $description ) {
		if ( empty( $description ) ) {
			throw new \InvalidArgumentException( "Description must not be empty." );
		}

		$this->description = $description;
	}

	/**
	 * Sets the owner of this Space.
	 *
	 * @param User $owner
	 */
	public function setOwner( User $owner ) {
		if ( $owner->isAnon() ) {
			throw new \InvalidArgumentException( "A namespace cannot be owned by an anonymous user." );
		}

		$this->namespace_owner = $owner;
	}

	/**
	 * Sets the administrators of this space.
	 *
	 * @param string[] $administrators
	 */
	public function setSpaceAdministrators( array $administrators ) {
		$this->namespace_administrators = $administrators;
	}

	/**
	 * Sets the archived status of this Space.
	 *
	 * @param bool $is_archived
	 */
	public function setArchived( bool $is_archived = true ) {
		$this->is_archived = $is_archived;
	}

	/**
	 * Returns true if and only if the given space exists.
	 *
	 * @return bool
	 */
	public function exists(): bool {
		// Get DB_PRIMARY to ensure integrity
		$database = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef( DB_PRIMARY );

		// If database has not been set up yet (e.g. during update.php run), namespace does not exist yet.
		if ( !$database->tableExists( 'wss_namespaces', __METHOD__ ) ) {
			return false;
		}
		$result = $database->newSelectQueryBuilder()->select(
			'namespace_id'
		)->from(
			'wss_namespaces'
		)->where(
			[ 'namespace_id' => $this->namespace_id ]
		)->caller( __METHOD__ )->fetchField();

		return $result !== false && $this->namespace_id !== self::DEFAULT_NAMESPACE_CONSTANT;
	}

	/**
	 * Returns true if and only if the current logged in user can edit this space.
	 *
	 * @return bool
	 */
	public function canEdit(): bool {
		$user = \RequestContext::getMain()->getUser();

		if ( in_array( $user->getName(), $this->namespace_administrators, true ) ) {
			// This user is a space administrator
			return true;
		}

		return MediaWikiServices::getInstance()->getPermissionManager()->userHasRight(
			$user,
			"wss-edit-all-spaces"
		);
	}

	/**
	 * Returns true if and only if archiving is currently enabled.
	 *
	 * @return bool
	 */
	public static function canArchive(): bool {
		$services = MediaWikiServices::getInstance();
		$user_can_archive = $services
			->getPermissionManager()
			->userHasRight(
				RequestContext::getMain()->getUser(),
				"wss-archive-space"
			);

		return $services->getMainConfig()->get( "WSSpacesEnableSpaceArchiving" ) && $user_can_archive;
	}
}
