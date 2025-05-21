<?php

namespace WSS\API;

use ApiUsageException;
use MWException;
use WSS\NamespaceRepository;
use WSS\Space;
use WSS\Validation\AddSpaceValidationCallback;
use Wikimedia\ParamValidator\ParamValidator;

class ApiAddSpace extends ApiBase {
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
		$this->checkUserRightsAny( 'wss-add-space' );
		$this->validateParameters();

		$request_params = $this->extractRequestParams();

		$ns_key = $request_params["nskey"];
		$ns_name = $request_params["nsname"];
		$ns_description = $request_params["nsdescription"];
		$ns_admins = $request_params["nsadmins"];
		$ns_admins = explode( ",", $ns_admins );
		$ns_protected = $request_params["nsprotected"];

		$current_user = \RequestContext::getMain()->getUser();

		// If no admins were given, add current user to the list of admins.
		if ( !$ns_admins || empty( $ns_admins ) ) {
			$current_user_name = $current_user->getName();
			$ns_admins[] = $current_user_name;
		}

		$space = Space::newFromValues( $ns_key, $ns_name, $ns_description, $current_user );
		$space->setProtected( $ns_protected );

		$namespace_repository = new NamespaceRepository();

		$namespace_id = $namespace_repository->addSpace( $space );

		// Update the list of namespace admins
		$old_space = Space::newFromConstant( $namespace_id );
		$new_space = clone $old_space;

		$new_space->setSpaceAdministrators( $ns_admins );

		$namespace_repository->updateSpace( $old_space, $new_space );

		$this->getResult()->addValue( [], "result", "success" );
		$this->getResult()->addValue( [ "namespace" ], "id", $namespace_id );
	}

	/**
	 * Evaluates the parameters given to the API module and throws an exception if the
	 * validation fails.
	 *
	 * @throws ApiUsageException
	 */
	private function validateParameters() {
		$request_params = $this->extractRequestParams();

		$ns_key = isset( $request_params["nskey"] ) ? $request_params["nskey"] : false;

		if ( $ns_key === false ) {
			$this->dieWithError( $this->msg( "wss-api-missing-param-nskey" ) );
		}

		$ns_name = isset( $request_params["nsname"] ) ? $request_params["nsname"] : false;

		if ( $ns_name === false ) {
			$this->dieWithError( $this->msg( "wss-api-missing-param-nsname" ) );
		}

		$ns_description = isset( $request_params["nsdescription"] ) ? $request_params["nsdescription"] : false;

		if ( $ns_description === false ) {
			$this->dieWithError( $this->msg( "wss-api-missing-param-nsdescription" ) );
		}

		$ns_admins = isset( $request_params["nsadmins"] ) ? $request_params["nsadmins"] : false;

		if ( $ns_admins === false ) {
			$this->dieWithError( $this->msg( "wss-api-missing-param-nsadmins" ) );
		}

		// Although this validation is made for HTMLForm, we use it here to avoid repeating ourselves
		$add_space_validation_callback = new AddSpaceValidationCallback();
		$request_data = [
			"namespace" => $ns_key,
			"namespace_name" => $ns_name,
			"description" => $ns_description
		];

		// Validate "nskey"
		$error_or_true = $add_space_validation_callback->validateField( "namespace", $ns_key, $request_data );
		if ( $error_or_true !== true ) {
			$this->dieWithError( $this->msg( "wss-api-invalid-param-detailed-nskey", $error_or_true ) );
		}

		// Validate "nsname"
		$error_or_true = $add_space_validation_callback->validateField( "namespace_name", $ns_name, $request_data );
		if ( $error_or_true !== true ) {
			$this->dieWithError( $this->msg( "wss-api-invalid-param-detailed-nsname", $error_or_true ) );
		}

		// Validate "nsdescription"
		if ( $add_space_validation_callback->validateRequired( $ns_description ) !== true ) {
			$this->dieWithError( $this->msg( "wss-api-invalid-param-nsdescription" ) );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams(): array {
		return [
			'nskey' => [
				ParamValidator::PARAM_TYPE => "string",
				ApiBase::PARAM_HELP_MSG => "wss-api-nskey-param"
			],
			'nsname' => [
				ParamValidator::PARAM_TYPE => "string",
				ApiBase::PARAM_HELP_MSG => "wss-api-nsname-param"
			],
			'nsdescription' => [
				ParamValidator::PARAM_TYPE => "string",
				ApiBase::PARAM_HELP_MSG => "wss-api-nsdescription-param"
			],
			'nsadmins' => [
				ParamValidator::PARAM_TYPE => "string",
				ApiBase::PARAM_HELP_MSG => "wss-api-nsadmins-param"
			],
			'nsprotected' => [
				ParamValidator::PARAM_TYPE => "boolean",
				ApiBase::PARAM_HELP_MSG => "wss-api-nsprotected-param"
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getExamplesMessages() {
		return [
			"action=addspace&nskey=Foo&nsname=Foo&nsdescription=Lorem ipsum&nsadmins=Admin,Foobar" =>
				"apihelp-addspace-example-foo"
		];
	}
}
