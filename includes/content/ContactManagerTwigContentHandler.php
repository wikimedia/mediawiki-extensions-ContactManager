<?php

if ( is_readable( __DIR__ . '/../../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../../vendor/autoload.php';
}

use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\MediaWikiServices;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class ContactManagerTwigContentHandler extends \TextContentHandler {

	public function __construct() {
		parent::__construct( CONTENT_MODEL_CONTACTMANAGER_TWIG, [ CONTENT_FORMAT_HTML ] );
	}

	/** @inheritDoc */
	protected function getContentClass() {
		return ContactManagerTwigContent::class;
	}

	/** @inheritDoc */
	protected function fillParserOutput(
		Content $content,
		ContentParseParams $cpoParams,
		ParserOutput &$output
	) {
		if ( !( $content instanceof TextContent ) ) {
			return;
		}

		$textModelsToParse = MediaWikiServices::getInstance()->getMainConfig()->get( 'TextModelsToParse' );
		if ( in_array( $content->getModel(), $textModelsToParse ) ) {
			// parse just to get links etc into the database, HTML is replaced below.
			$output = MediaWikiServices::getInstance()->getParser()
				->parse(
					$content->getText(),
					$cpoParams->getPage(),
					$cpoParams->getParserOptions(),
					true,
					true,
					$cpoParams->getRevId()
				);
		}

		// Skip this code path in e.g. CI environments without Twig
		if ( $cpoParams->getGenerateHtml() && class_exists( Environment::class ) ) {
			$templateName = 'twigTemplate';
			$loader = new ArrayLoader( [
				$templateName => $content->getText(),
			] );

			$twig = new Environment( $loader );

			$substitutions = [];
			$html = $twig->render( $templateName, $substitutions );
		} else {
			$html = '';
		}

		$output->clearWrapperDivClass();
		$output->setContentHolderText( $html );
	}

}
