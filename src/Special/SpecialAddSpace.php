<?php

namespace WSS\Special;

use Exception;
use WSS\SpecialPage;
use WSS\UI\AddSpaceUI;
use WSS\UI\ExceptionUI;
use WSS\UI\InvalidPageUI;

/**
 * Class SpecialNamespaces
 * @package WSS\Special
 */
class SpecialAddSpace extends SpecialPage {
	/**
	 * SpecialPermissions constructor.
	 */
	public function __construct() {
		parent::__construct( self::getName(), self::getRestriction(), true );
	}

	/**
	 * @inheritDoc
	 */
	public function getName() {
		return "AddSpace";
	}

	/**
	 * @inheritDoc
	 */
	public function getRestriction() {
		return 'wss-add-space';
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
		return $this->msg( 'wss-add-space-header' );
	}

	/**
	 * @inheritDoc
	 * @throws \MWException
	 */
	public function doExecute( string $parameter ) {
		try {
			if ( !empty( $parameter ) ) {
				$ui = new InvalidPageUI( $this->getOutput(), $this->getLinkRenderer() );
				$ui->execute();

				return;
			}

			$ui = new AddSpaceUI( $this->getOutput(), $this->getLinkRenderer() );
			$ui->execute();
		} catch ( Exception $e ) {
			$ui = new ExceptionUI( $e, $this->getOutput(), $this->getLinkRenderer() );
			$ui->execute();
		}
	}
}
