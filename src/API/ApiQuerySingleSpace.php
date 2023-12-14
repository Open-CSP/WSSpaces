<?php

namespace WSS\API;

use WSS\NamespaceRepository;
use WSS\Space;
use MediaWiki\MediaWikiServices;

class ApiQuerySingleSpace extends \ApiQueryBase {
	/**
	 * Grab the space from namespace_id, namespace_key or namespace_name and return the info.
	 */
	public function execute() {
		// If you can query a sinlge space, you can query all spaces
		$this->checkUserRightsAny( "wss-view-spaces-overview" );

		$request_params = $this->extractRequestParams();
		$ns_id = $request_params["namespace_id"] ?? false;
		$ns_key = $request_params["namespace_key"] ?? false;
		$ns_name = $request_params["namespace_name"] ?? false;

		$space = $this->getSpace( $ns_id, $ns_key, $ns_name );

		if ( $space instanceof Space ) {
			$result = $this->getResult();
			$this->addSpaceValuesToResult( $result, $space );
		}
	}

	/**
	 * Get the space based on one of these properties (in the order provided)
	 */
	protected function getSpace( $ns_id, $ns_key, $ns_name ): ?Space
	{
		if ( $ns_id !== false ) {
			return Space::newFromConstant( $ns_id );
		}
		if ( $ns_key !== false ) {
			$cond = [ 'namespace_key' => $ns_key ];
		} elseif ( $ns_name !== false ) {
			$cond = [ 'namespace_name' => $ns_name ];
		} else {
			return null;
		}

		$database = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef( DB_MASTER );

		$result = $database->newSelectQueryBuilder()->select(
			'namespace_id'
		)->from(
			'wss_namespaces'
		)->where(
			$cond
		)->caller( __METHOD__ )->fetchField();

		return $result !== false ? Space::newFromConstant( $result ) : null;
	}

	protected function addSpaceValuesToResult( $result, $space ): void
	{
		$space_id = $space->getId();

		$result->addValue( $space_id, "id", $space->getId() );
		$result->addValue( $space_id, "talkid", $space->getTalkId() );
		$result->addValue( $space_id, "key", $space->getKey() );
		$result->addValue( $space_id, "name", $space->getName() );
		$result->addValue( $space_id, "description", $space->getDescription() );
		$result->addValue( $space_id, "owner", $space->getOwner() );
		$result->addValue( $space_id, "admins", $space->getSpaceAdministrators() );
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams(): array {
		return [
			'namespace_id' => [
				ApiBase::PARAM_TYPE => "integer",
				ApiBase::PARAM_HELP_MSG => "wss-api-namespace-id-param"
			],
			'namespace_key' => [
				ApiBase::PARAM_TYPE => "string",
				ApiBase::PARAM_HELP_MSG => "wss-api-namespace-key-param"
			],
			'namespace_name' => [
				ApiBase::PARAM_TYPE => "string",
				ApiBase::PARAM_HELP_MSG => "wss-api-namespace-name-param"
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getExamplesMessages() {
		return [
			"action=query&list=singlespace&namespace_id=50000" =>
				"apihelp-query-example-namespace-id",
			"action=query&list=singlespace&namespace_key=FOO" =>
				"apihelp-query-example-namespace-key",
			"action=query&list=singlespace&namespace_name=Foo%20fighters" =>
				"apihelp-query-example-namespace-name",
		];
	}
}
