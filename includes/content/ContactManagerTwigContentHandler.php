<?php

if ( is_readable( __DIR__ . '/../../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../../vendor/autoload.php';
}

use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\MediaWikiServices;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class ContactManagerTwigContentHandler extends \TextContentHandler {
	/**
	 * @inheritDoc
	 */
	public function __construct( $modelId = CONTENT_MODEL_CONTACTMANAGER_TWIG, $formats = [ 'text/html' ] ) {
		parent::__construct( $modelId, $formats );
	}

	/**
	 * @return string
	 */
	protected function getContentClass() {
		return ContactManagerTwigContent::class;
	}

	/**
	 * @param Content $content
	 * @param ContentParseParams $cpoParams
	 * @param ParserOutput &$output The output object to fill (reference).
	 */
	protected function fillParserOutput(
		Content $content,
		ContentParseParams $cpoParams,
		ParserOutput &$output
	) {
		$textModelsToParse = MediaWikiServices::getInstance()->getMainConfig()->get( 'TextModelsToParse' );
		'@phan-var TextContent $content';
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

		if ( $cpoParams->getGenerateHtml() ) {
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
		$output->setText( $html );
	}

}
