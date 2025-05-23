<?php

namespace WSS\Special;

use Exception;
use MWException;
use WSS\NamespaceRepository;
use WSS\Space;
use WSS\SpecialPage;
use WSS\UI\ArchivedSpacesUI;
use WSS\UI\ExceptionUI;
use WSS\UI\InvalidPageUI;
use WSS\UI\MissingPermissionsUI;
use WSS\UI\UnarchiveSpaceUI;

/**
 * Class SpecialArchivedSpaces
 *
 * @package WSS\Special
 */
class SpecialArchivedSpaces extends SpecialPage {
	/**
	 * SpecialPermissions constructor.
	 *
	 * @throws \UserNotLoggedIn
	 */
	public function __construct() {
		parent::__construct( self::getName(), self::getRestriction(), true );
	}

	/**
	 * @inheritDoc
	 */
	public function getName() {
		return "ArchivedSpaces";
	}

	/**
	 * @inheritDoc
	 */
	public function getRestriction() {
		return 'wss-archive-space';
	}

	/**
	 * @inheritDoc
	 */
	public function getGroupName() {
		return 'wss-spaces';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		$msg = $this->msg( 'wss-archived-spaces-header' );
		if ( version_compare( MW_VERSION, '1.41' ) < 0 ) {
			return $msg->plain();
		}
		return $msg;
	}

	/**
	 * @inheritDoc
	 * @throws MWException
	 */
	public function preExecute(): bool {
		if ( !Space::canArchive() ) {
			// We can't edit this space
			$ui = new MissingPermissionsUI( $this->getOutput(), $this->getLinkRenderer() );
			$ui->execute();

			return false;
		}

		return true;
	}

	/**
	 * @inheritDoc
	 * @throws MWException
	 */
	public function doExecute( string $parameter ) {
		try {
			$namespace_repository = new NamespaceRepository();

			$output   = $this->getOutput();
			$renderer = $this->getLinkRenderer();

			if ( empty( $parameter ) ) {
				$ui = new ArchivedSpacesUI( $output, $renderer );
				$ui->execute();

				return;
			}

			if ( !ctype_digit( $parameter ) ) {
				$ui = new InvalidPageUI( $this->getOutput(), $this->getLinkRenderer() );
				$ui->execute();

				return;
			}

			$namespace_constant = intval( $parameter );

			if ( !in_array( $namespace_constant, $namespace_repository->getArchivedSpaces( true ), true ) ) {
				// This space isn't archived or does not exist
				$ui = new InvalidPageUI( $this->getOutput(), $this->getLinkRenderer() );
				$ui->execute();

				return;
			}

			$space = Space::newFromConstant( $namespace_constant );

			if ( !$space->canEdit() ) {
				// We can't edit this space
				$ui = new MissingPermissionsUI( $this->getOutput(), $this->getLinkRenderer() );
				$ui->execute();

				return;
			}

			$ui = new UnarchiveSpaceUI( $output, $renderer );
			$ui->setParameter( $parameter );
			$ui->execute();
		} catch ( Exception $e ) {
			$ui = new ExceptionUI( $e, $this->getOutput(), $this->getLinkRenderer() );
			$ui->execute();
		}
	}
}
