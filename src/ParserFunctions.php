<?php

namespace WSS;

use Parser;
use User;

/**
 * Contains all parserFunctions for the WSSpaces extension
 */
class ParserFunctions {
		/**
		 * Render the output of {{#spaceadmins: namespace}}.
		 *
		 * @param Parser $parser
		 * @param string $namespace
		 * @return string
		 */
		public function renderSpaceAdmins( Parser $parser, $namespace = '' ) : string {
				// Check if user has the 'wss-view-space-admins' right.
				if ( !\RequestContext::getMain()->getUser()->isAllowed( 'wss-view-space-admins' ) ) {
						return wfMessage( 'wss-permission-denied-spaceadmins' );
				}

		$ns_valid = $this->validateNamespace( $namespace );
		if ( is_string( $ns_valid ) ) {
			// Namespace was not valid
			return $ns_valid;
		}

				// Get all admin ids for a namespace. If no admins are found, return the error message.
				$admin_ids = NamespaceRepository::getNamespaceAdmins( $namespace );

				if ( empty( $admin_ids ) ) {
						return wfMessage( 'wss-pf-no-admins-found', $namespace )->parse();
				}

				// Turn the list of admin ids into User objects
				$admins = array_map( [ User::class, "newFromId" ], $admin_ids );
				$admins = array_filter( $admins, function ( $user ): bool {
						// loadFromDatabase checks if the user actually exists in the database.
						return $user instanceof User && $user->loadFromDatabase();
				} );

				if ( empty( $admins ) ) {
						return wfMessage( 'wss-pf-no-valid-admins', $namespace )->parse();
				}

				// Add all admin names to a comma separated string.
				$admins = array_map( function ( User $user ): string {
						return $user->getName();
				}, $admins );

				// Return the admin list.
				return implode( ",", $admins );
		}

		/**
		 * Render the output of {{#spaces:}}.
		 *
		 * @param Parser $parser
		 * @return string
		 */
		public function renderSpaces( Parser $parser ) : string {
				$namespace_repository = new NamespaceRepository();
				$spaces = $namespace_repository->getSpaces();

				return implode( ",", $spaces );
		}

		/**
		 * Render the output of {{#spacename: namespace}}.
		 *
		 * @param Parser $parser
		 * @return string
		 */
	public function renderSpaceName( Parser $parser, $namespace = '' ) : string {
		$ns_valid = $this->validateNamespace( $namespace );
		if ( is_string( $ns_valid ) ) {
			// Namespace was invalid
			return $ns_valid;
		}

		$space = Space::newFromConstant( $namespace );
		if ( !$space instanceof Space ) {
			return wfMessage( "wss-space-not-wsspaces", $namespace );
		}

		return $space->getName();
	}

		/**
		 * Render the output of {{#spacedescription: namespace}}.
		 *
		 * @param Parser $parser
		 * @return string
		 */
	public function renderSpaceDescription( Parser $parser, $namespace = '' ) : string {
		$ns_valid = $this->validateNamespace( $namespace );
		if ( is_string( $ns_valid ) ) {
			// Namespace was invalid
			return $ns_valid;
		}

		$space = Space::newFromConstant( $namespace );
		if ( !$space instanceof Space ) {
			return wfMessage( "wss-space-not-wsspaces", $namespace );
		}

		return $space->getDescription();
	}

	/**
	 * Check if $namespace is a non-empty digits string
	 * @param string $namespace The namespace to check
	 * @return string|null Null on success, error message on failure.
	 */
	private function validateNamespace( string $namespace ) : ?string {
				// Validate namespace input.
				if ( $namespace === '' ) {
						return wfMessage( 'wss-api-missing-param-namespace' );
				}
				if ( !ctype_digit( $namespace ) ) {
						return wfMessage( 'wss-pf-invalid-number' );
				}
		return null;
	}
}
