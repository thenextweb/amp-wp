<?php
/**
 * Class AMP_Comments_Sanitizer.
 *
 * @package AMP
 */

/**
 * Class AMP_Comments_Sanitizer
 *
 * Strips and corrects attributes in forms.
 */
class AMP_Comments_Sanitizer extends AMP_Base_Sanitizer {

	/**
	 * Pre-process the comment form and comment list for AMP.
	 *
	 * @since 0.7
	 */
	public function sanitize() {

		foreach ( $this->dom->getElementsByTagName( 'form' ) as $comment_form ) {
			/**
			 * Comment form.
			 *
			 * @var DOMElement $comment_form
			 */
			$action = $comment_form->getAttribute( 'action-xhr' );
			if ( ! $action ) {
				$action = $comment_form->getAttribute( 'action' );
			}
			$action_path = wp_parse_url( $action, PHP_URL_PATH );
			if ( preg_match( '#/wp-comments-post\.php$#', $action_path ) ) {
				$this->process_comment_form( $comment_form );
			}
		}
	}

	/**
	 * Comment form.
	 *
	 * @param DOMElement $comment_form Comment form.
	 */
	protected function process_comment_form( $comment_form ) {
		/**
		 * Element.
		 *
		 * @var DOMElement $element
		 */

		/**
		 * Named input elements.
		 *
		 * @var DOMElement[][] $form_fields
		 */
		$form_fields = array();
		foreach ( $comment_form->getElementsByTagName( 'input' ) as $element ) {
			$name = $element->getAttribute( 'name' );
			if ( $name ) {
				$form_fields[ $name ][] = $element;
			}
		}
		foreach ( $comment_form->getElementsByTagName( 'textarea' ) as $element ) {
			$name = $element->getAttribute( 'name' );
			if ( $name ) {
				$form_fields[ $name ][] = $element;
			}
		}

		if ( empty( $form_fields['comment_post_ID'] ) ) {
			return;
		}
		$post_id  = (int) $form_fields['comment_post_ID'][0]->getAttribute( 'value' );
		$state_id = AMP_Theme_Support::get_comment_form_state_id( $post_id );

		$form_state = array(
			'values'     => array(),
			'submitting' => false,
		);

		$amp_bind_attr_format = AMP_DOM_Utils::get_amp_bind_placeholder_prefix() . '%s';
		foreach ( $form_fields as $name => $form_field ) {
			foreach ( $form_field as $element ) {

				// @todo Radio and checkbox inputs are not supported yet.
				if ( in_array( strtolower( $element->getAttribute( 'type' ) ), array( 'checkbox', 'radio' ), true ) ) {
					continue;
				}

				$element->setAttribute( sprintf( $amp_bind_attr_format, 'disabled' ), "$state_id.submitting" );

				if ( 'textarea' === strtolower( $element->nodeName ) ) {
					$form_state['values'][ $name ] = $element->textContent;
					$element->setAttribute( sprintf( $amp_bind_attr_format, 'text' ), "$state_id.values.$name" );
				} else {
					$form_state['values'][ $name ] = $element->hasAttribute( 'value' ) ? $element->getAttribute( 'value' ) : '';
					$element->setAttribute( sprintf( $amp_bind_attr_format, 'value' ), "$state_id.values.$name" );
				}

				// Update the state in response to changing the input.
				$element->setAttribute( 'on', sprintf(
					'change:AMP.setState( { %s: { values: { %s: event.value } } } )',
					$state_id,
					wp_json_encode( $name )
				) );
			}
		}

		// Add amp-state to the document.
		$amp_state = $this->dom->createElement( 'amp-state' );
		$amp_state->setAttribute( 'id', $state_id );
		$script = $this->dom->createElement( 'script' );
		$script->setAttribute( 'type', 'application/json' );
		$amp_state->appendChild( $script );
		$script->appendChild( $this->dom->createTextNode( wp_json_encode( $form_state ) ) );
		$comment_form->insertBefore( $amp_state, $comment_form->firstChild );

		// Update state when submitting form.
		$form_reset_state = $form_state;
		unset(
			$form_reset_state['values']['author'],
			$form_reset_state['values']['email'],
			$form_reset_state['values']['url']
		);
		$on = array(
			// Disable the form when submitting.
			sprintf(
				'submit:AMP.setState( { %s: { submitting: true } } )',
				wp_json_encode( $state_id )
			),
			// Re-enable the form fields when the submission fails.
			sprintf(
				'submit-error:AMP.setState( { %s: { submitting: false } } )',
				wp_json_encode( $state_id )
			),
			// Reset the form to its initial state (with enabled form fields), except for the author, email, and url.
			sprintf(
				'submit-success:AMP.setState( { %s: %s } )',
				$state_id,
				wp_json_encode( $form_reset_state )
			),
		);
		$comment_form->setAttribute( 'on', implode( ';', $on ) );
	}
}
