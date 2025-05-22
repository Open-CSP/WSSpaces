<?php

namespace WSS\SubmitCallback;

use WSS\NamespaceRepository;
use WSS\Space;
use WSS\UI\SpacesUI;
use WSS\UI\WSSUI;

/**
 * Class AddSpaceSubmitCallback
 *
 * @package WSS\SubmitCallback
 */
class AddSpaceSubmitCallback implements SubmitCallback {
	/**
	 * @var WSSUI
	 */
	private $ui;

	/**
	 * SubmitCallback constructor.
	 *
	 * @param SpacesUI $ui
	 */
	public function __construct( SpacesUI $ui ) {
		$this->ui = $ui;
	}

	/**
	 * Called upon submitting a form.
	 *
	 * @param array $form_data The data submitted via the form.
	 * @return string|bool
	 * @throws \ConfigException
	 * @throws \MWException
	 */
	public function onSubmit( array $form_data ) {
		if ( !isset( $form_data['description'] ) || empty( $form_data['description'] ) ) {
			return "wss-invalid-input";
		}

		if ( !isset( $form_data['namespace'] ) || empty( $form_data['namespace'] ) ) {
			return "wss-invalid-input";
		}

		$description  = $form_data['description'];
		$namespace_key = $form_data['namespace'];
		$namespace_name = $form_data['namespace_name'];
		$protected = $form_data[ 'protected' ] ?? false;
		$space = Space::newFromValues(
			$namespace_key,
			$namespace_name,
			$description,
			\RequestContext::getMain()->getUser()
		);

		$space->setProtected( $protected );

		$namespace_repository = new NamespaceRepository();
		$namespace_repository->addSpace( $space );

		$this->ui->setAllowCallback();

		\RequestContext::getMain()->getOutput()->redirect(
			\Title::newFromText( "ActiveSpaces", NS_SPECIAL )->getFullUrlForRedirect(
				[ 'wss_callback' => 'created' ]
			)
		);

		return true;
	}
}
