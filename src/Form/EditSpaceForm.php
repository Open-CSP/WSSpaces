<?php

namespace WSS\Form;

use OutputPage;
use WSS\Space;
use WSS\SubmitCallback\SubmitCallback;
use WSS\Validation\AbstractValidationCallback;

class EditSpaceForm extends AbstractForm {
	/**
	 * @var Space
	 */
	private $space;

	/**
	 * EditSpaceForm constructor.
	 *
	 * @param Space $space
	 * @param OutputPage $page
	 * @param SubmitCallback $submit_callback
	 * @param AbstractValidationCallback|null $validation_callback
	 */
	public function __construct(
		Space $space,
		OutputPage $page,
		SubmitCallback $submit_callback,
		AbstractValidationCallback $validation_callback = null ) {
		$this->space = $space;

		parent::__construct( $page, $submit_callback, $validation_callback );
	}

	/**
	 * Returns this form's descriptor.
	 *
	 * @return array
	 */
	public function getDescriptor(): array {
		return [
			'namespaceid' => [
				'label-message' => 'wss-manage-space-form-namespaceid-label',
				'type' => 'text',
				'disabled' => true,
				'default' => $this->space->getId()
			],
			'createdby' => [
				'label-message' => 'wss-manage-space-form-createdby-label',
				'type' => 'text',
				'disabled' => true,
				'default' => $this->space->getOwner()->getName()
			],
			'namespace' => [
				'label-message' => 'wss-manage-space-form-namespace-label',
				'help-message' => 'wss-manage-space-form-namespace-help',
				'type' => 'text',
				'size' => 32,
				'maxlength' => 24,
				'required' => true,
				'default' => $this->space->getKey(),
				'validation-callback' => function ( $field, $data ) {
					return $this->getValidationCallback()->validateField( 'namespace', $field, $data );
				}
			],
			'namespace_name' => [
				'label-message' => 'wss-manage-space-form-namespacename-label',
				'type' => 'text',
				'size' => 32,
				'maxlength' => 64,
				'required' => true,
				'default' => $this->space->getName(),
				'validation-callback' => function ( $field, array $data ) {
					return $this->getValidationCallback()->validateField( 'namespace_name', $field, $data );
				}
			],
			'description' => [
				'label-message' => 'wss-add-space-form-description-label',
				'type' => 'textarea',
				'rows' => 4,
				'required' => true,
				'default' => $this->space->getDescription(),
				'validation-callback' => function ( $field, $data ) {
					return $this->getValidationCallback()->validateRequired( $field );
				}
			],
			'protected' => [
				'label-message' => 'wss-add-space-form-protected-label',
				'type' => 'check',
				'default' => $this->space->isProtected(),
			],
			'administrators' => [
				'label-message' => 'wss-manage-space-form-administrators-label',
				'type' => 'usersmultiselect',
				'required' => false,
				'default' => implode( "\n", $this->space->getSpaceAdministrators() ),
				'exists' => true
			]
		];
	}

	/**
	 * Returns this form's name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'wss_manage_space';
	}

	/**
	 * Returns this form's submit text.
	 *
	 * @return string
	 */
	public function getSubmitText(): string {
		return wfMessage( 'wss-manage-space-form-submit-text' )->plain();
	}

	/**
	 * Returns true if and only if this form is (or can be) destructive.
	 *
	 * @return bool
	 */
	public function isDestructive(): bool {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function showCancel(): bool {
		return true;
	}

	public function cancelTarget(): \Title {
		return \Title::newFromText( "Special:ActiveSpaces" );
	}
}
