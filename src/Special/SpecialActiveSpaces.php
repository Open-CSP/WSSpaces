<?php

namespace WSS\Special;

use Exception;
use MediaWiki\MediaWikiServices;
use WSS\NamespaceRepository;
use WSS\Space;
use WSS\SpecialPage;
use WSS\UI\ExceptionUI;
use WSS\UI\InvalidPageUI;
use WSS\UI\ManageSpaceBaseUI;
use WSS\UI\ManageSpaceFormUI;
use WSS\UI\MissingPermissionsUI;

/**
 * Class SpecialActiveSpaces
 *
 * @package WSS\Special
 */
class SpecialActiveSpaces extends SpecialPage {
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
		return "ActiveSpaces";
	}

	/**
	 * @inheritDoc
	 */
	public function getRestriction() {
		return '';
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
		$msg = $this->msg( 'wss-active-spaces-header' );
		if ( version_compare( MW_VERSION, '1.41' ) < 0 ) {
			return $msg->plain();
		}
		return $msg;
	}

	/**
	 * The main method that gets invoked upon successfully loading the special page, after preExecuteChecks have
	 * been performed.
	 *
	 * @param string $parameter
	 * @return void
	 * @throws \MWException
	 */
	public function doExecute( string $parameter ) {
		$output = $this->getOutput();
		$renderer = $this->getLinkRenderer();

		if ( empty( $parameter ) ) {
			if ( !MediaWikiServices::getInstance()->getPermissionManager()->userHasRight(
				\RequestContext::getMain()->getUser(),
				"wss-view-spaces-overview"
			) ) {
				throw new \PermissionsError( "wss-view-spaces-overview" );
			}

			$ui = new ManageSpaceBaseUI( $output, $renderer );
			$ui->execute();

			return;
		}

		if ( !ctype_digit( $parameter ) ) {
			$ui = new InvalidPageUI( $this->getOutput(), $this->getLinkRenderer() );
			$ui->execute();

			return;
		}

		$namespace_constant = intval( $parameter );

		try {
			$namespace_repository = new NamespaceRepository();

			if ( !in_array( $namespace_constant, $namespace_repository->getSpaces( true ), true ) ) {
				$ui = new InvalidPageUI( $this->getOutput(), $this->getLinkRenderer() );
				$ui->execute();

				return;
			}

			$space = Space::newFromConstant( $namespace_constant );

			if ( !$space->canEdit() ) {
				$ui = new MissingPermissionsUI( $this->getOutput(), $this->getLinkRenderer() );
				$ui->execute();

				return;
			}

			$ui = new ManageSpaceFormUI( $output, $renderer );
			$ui->setParameter( $parameter );
			$ui->execute();
		} catch ( Exception $e ) {
			$ui = new ExceptionUI( $e, $this->getOutput(), $this->getLinkRenderer() );
			$ui->execute();
		}
	}
}
