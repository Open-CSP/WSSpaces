<?php

namespace WSS\Validation;

use WSS\NamespaceRepository;
use WSS\Space;

/**
 * Class AddSpaceValidationCallback
 * @package WSS\Validation
 */
class AddSpaceValidationCallback extends AbstractValidationCallback {
	/**
	 * Validates whether the given value is allowed for the given field.
	 *
	 * @param string $field The name of the field. Can be any arbitrary string.
	 * @param string|array $value The value given to the field.
	 * @param array $form_data Other data given to the form.
	 * @return bool|string
	 * @throws \ConfigException
	 */
	public function validateField( string $field, $value, array $form_data ) {
		switch ( $field ) {
			case 'namespace':
				return $this->validateNamespace( $value, $form_data );
			case 'namespace_name':
				return $this->validateNamespaceName( $value, $form_data );
			default:
				return false;
		}
	}

	/**
	 * @param mixed $value
	 * @param array $form_data
	 * @return bool|string
	 * @throws \ConfigException
	 */
	private function validateNamespace( $value, array $form_data ) {
		$valid = ctype_alnum( $value ) && !empty( $value );

		if ( !$valid ) {
			return wfMessage( "wss-invalid-namespace" )->parse();
		}

		$namespace_repository = new NamespaceRepository();

		$namespaces = $namespace_repository->getAllNamespaces();
		$namespaces = array_map( 'strtolower', $namespaces );
		$namespaces = array_map( 'trim', $namespaces );

		$space = Space::newFromConstant( $form_data['namespaceid'] ?? 0 );

		if ( $space !== false && strtolower( $space->getKey() ) === strtolower( $value ) ) {
			// The given namespace name is the one we are editing
			return true;
		}

		if ( in_array( trim( strtolower( $value ) ), $namespaces, true ) ) {
			// This namespace/space name is already in use
			return wfMessage( "wss-namespace-in-use" )->parse();
		}

		return true;
	}

	/**
	 * @param string $value
	 * @param array $form_data
	 * @return bool|string
	 */
	private function validateNamespaceName( $value, array $form_data ) {
		// Get DB_MASTER to ensure integrity
		$database = wfGetDB( DB_MASTER );
		$namespaces = $database->select( "wss_namespaces", [ "namespace_id" ], [ "namespace_name" => $value ] );

		if ( $namespaces->numRows() === 0 ) {
			// There are no spaces with this name
			return true;
		}

		$space = Space::newFromConstant( $form_data['namespaceid'] ?? 0 );

		if ( $space !== false && strtolower( $space->getName() ) === strtolower( $value ) ) {
			// The given namespace name is the one we are editing
			return true;
		}

		return wfMessage( "wss-namespace-name-in-use" )->parse();
	}
}
