<?php

namespace WSS\API;

use ApiUsageException;
use MWException;
use WSS\NamespaceRepository;
use WSS\Space;
use WSS\Validation\AddSpaceValidationCallback;

class ApiEditSpace extends ApiBase {
	/**
	 * Evaluates the parameters, performs the requested query, and sets up
	 * the result. Concrete implementations of ApiBase must override this
	 * method to provide whatever functionality their module offers.
	 * Implementations must not produce any output on their own and are not
	 * expected to handle any errors.
	 *
	 * The execute() method will be invoked directly by ApiMain immediately
	 * before the result of the module is output. Aside from the
	 * constructor, implementations should assume that no other methods
	 * will be called externally on the module before the result is
	 * processed.
	 *
	 * The result data should be stored in the ApiResult object available
	 * through getResult().
	 *
	 * @throws ApiUsageException
	 * @throws MWException
	 */
	public function execute() {
		$request_params = $this->extractRequestParams();

		if ( !isset( $request_params["nsid"] ) ) {
			$this->dieWithError( $this->msg( "wss-api-missing-param-nsid" ) );
		}
		$ns_id = $request_params["nsid"];
		$old_space = Space::newFromConstant( $ns_id );
		if ( !$old_space instanceof Space ) {
			$this->dieWithError(
				$this->msg(
					"wss-api-invalid-param-detailed-nsid",
					$this->msg( "wss-api-space-does-not-exist", $request_params["nsid"] )->parse()
				)
			);
		}

		$ns_key = $request_params["nskey"] ?? null;
		$ns_name = $request_params["nsname"] ?? null;
		$ns_description = $request_params["nsdescription"] ?? null;
		$ns_admins = $request_params["nsadmins"] ?? "";
		$ns_admins = explode( ",", $ns_admins );

		$this->validateParams( $old_space, $ns_id, $ns_key, $ns_name, $ns_description );

		$namespace_repository = new NamespaceRepository();

		$new_space = clone $old_space;

		if ( $ns_key !== null ) {
			$new_space->setKey( $ns_key );
		}
		if ( $ns_description !== null ) {
			$new_space->setDescription( $ns_description );
		}
		if ( $ns_name !== null ) {
			$new_space->setName( $ns_name );
		}
		if ( $ns_admins !== [""] ) {
			$new_space->setSpaceAdministrators( $ns_admins );
		}

		try {
			$namespace_repository->updateSpace( $old_space, $new_space );
		} catch ( \PermissionsError $e ) {
			// The user is not allowed to update this space
			$this->dieWithError( [ 'apierror-permissiondenied', $this->msg( "action-wss-edit-space" ) ] );
		}

		$this->getResult()->addValue( [], "result", "success" );
	}

	/**
	 * Evaluates the parameters given to the API module and throws an exception if the
	 * validation fails.
	 *
	 * @throws ApiUsageException
	 */
	private function validateParameters(
		Space $space,
		?string $ns_key,
		?string $ns_name,
		?string $ns_descripotion
	) {
		if ( !$space->canEdit() ) {
			$this->dieWithError( [ 'apierror-permissiondenied', $this->msg( "action-wss-edit-space" ) ] );
		}

		// Although this validation is made for HTMLForm, we use it here to avoid repeating ourselves
		$add_space_validation_callback = new AddSpaceValidationCallback();
		$request_data = [
			"namespaceid" => $ns_id,
			"namespace" => $ns_key,
			"namespace_name" => $ns_name,
			"description" => $ns_description
		];

		// Validate "nskey"
		if ( !is_null( $ns_key ) && $add_space_validation_callback->validateField( "namespace", $ns_key, $request_data ) !== true ) {
			$this->dieWithError( $this->msg( "wss-api-invalid-param-nskey" ) );
		}

		// Validate "nsname"
		if ( !is_null( $ns_name ) && $add_space_validation_callback->validateField( "namespace_name", $ns_name, $request_data ) !== true ) {
			$this->dieWithError( $this->msg( "wss-api-invalid-param-nsname" ) );
		}

		// Validate "nsdescription"
		if ( !is_null( $ns_description ) && $add_space_validation_callback->validateRequired( $ns_description ) !== true ) {
			$this->dieWithError( $this->msg( "wss-api-invalid-param-nsdescription" ) );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams(): array {
		return [
			'nsid' => [
				ApiBase::PARAM_TYPE => "integer",
				ApiBase::PARAM_HELP_MSG => "wss-api-nsid-param"
			],
			'nskey' => [
				ApiBase::PARAM_TYPE => "string",
				ApiBase::PARAM_HELP_MSG => "wss-api-nskey-param"
			],
			'nsname' => [
				ApiBase::PARAM_TYPE => "string",
				ApiBase::PARAM_HELP_MSG => "wss-api-nsname-param"
			],
			'nsdescription' => [
				ApiBase::PARAM_TYPE => "string",
				ApiBase::PARAM_HELP_MSG => "wss-api-nsdescription-param"
			],
			'nsadmins' => [
				ApiBase::PARAM_TYPE => "string",
				ApiBase::PARAM_HELP_MSG => "wss-api-nsadmins-param"
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getExamplesMessages() {
		return [
			"action=editspace&nsid=50000&nskey=Foo&nsname=Foo&nsdescription=Lorem ipsum&nsadmins=Admin,Foobar" =>
				"apihelp-addspace-example-50000"
		];
	}
}
