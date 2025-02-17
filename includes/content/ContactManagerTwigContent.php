<?php

class ContactManagerTwigContent extends \TextContent {
	/**
	 * @inheritDoc
	 */
	public function __construct( $text ) {
		parent::__construct( $text, CONTENT_MODEL_CONTACTMANAGER_TWIG );
	}
}
