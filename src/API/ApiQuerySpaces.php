<?php

namespace WSS\API;

use WSS\NamespaceRepository;
use WSS\Space;

class ApiQuerySpaces extends \ApiQueryBase {
	public function execute() {
		$this->checkUserRightsAny( "wss-view-spaces-overview" );

		$request_params = $this->extractRequestParams();
		$archived = isset( $request_params["archived"] ) ? $request_params["archived"] : false;

		$namespace_repository = new NamespaceRepository();

		// Get all non-archived spaces
		$spaces = $archived === false ?
			$namespace_repository->getSpaces( true ) :
			$namespace_repository->getArchivedSpaces( true );

		// Turn the list of namespace constants into Space objects
		/** @var Space[] $spaces */
		$spaces = array_map( [ Space::class, "newFromConstant" ], $spaces );

		// Get a pointer to the result
		$result = $this->getResult();

		foreach ( $spaces as $space ) {
			$space_id = $space->getId();

			$result->addValue( $space_id, "id", $space->getId() );
			$result->addValue( $space_id, "talkid", $space->getTalkId() );
			$result->addValue( $space_id, "key", $space->getKey() );
			$result->addValue( $space_id, "name", $space->getName() );
			$result->addValue( $space_id, "description", $space->getDescription() );
			$result->addValue( $space_id, "owner", $space->getOwner() );
			$result->addValue( $space_id, "admins", $space->getSpaceAdministrators() );
			$result->addValue( $space_id, "protected", $space->isProtected() ? 'true' : 'false' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams(): array {
		return [
			'archived' => [
				ApiBase::PARAM_TYPE => "boolean",
				ApiBase::PARAM_HELP_MSG => "wss-api-archived-param"
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getExamplesMessages() {
		return [
			"action=query&list=spaces" =>
				"apihelp-query-example-not-archived",
			"action=query&list=spaces&archived=1" =>
				"apihelp-query-example-archived"
		];
	}
}
