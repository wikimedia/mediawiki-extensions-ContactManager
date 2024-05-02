<?php

/**
 * This file is part of the MediaWiki extension ContactManager.
 *
 * ContactManager is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * ContactManager is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ContactManager.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2023, https://wikisphere.org
 */

use MediaWiki\Revision\SlotRecord;

class ContactManagerHooks {
	/**
	 * @var array
	 */
	private static $SlotsParserOutput = [];

	/**
	 * @param array $credits
	 * @return void
	 */
	public static function initExtension( $credits = [] ) {
	}

	/**
	 * @param DatabaseUpdater|null $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater = null ) {
		if ( !class_exists( 'VisualData' ) ) {
			return;
		}
		$importer = \VisualData::getImporter();
		$error_messages = [];
		$doImport = static function ( $pagename, $contents ) use ( $importer ) {
			try {
				$importer->doImportSelf( $pagename, $contents );
			} catch ( Exception $e ) {
				$error_messages[$pagename] = $e->getMessage();
			}
		};

		$import = static function ( $path, $callback ) {
			$files = scandir( $path );
			foreach ( $files as $file ) {
				$filePath = "$path/$file";
				if ( is_file( $filePath ) ) {
					$titleText = substr( $file, 0, strpos( $file, '.' ) );
					$content = file_get_contents( $filePath );
					$callback( $titleText, $content );
				}
			}
		};

		$dirPath = __DIR__ . '/../data';

		$import( "$dirPath/articles", static function ( $titleText, $content ) use ( &$doImport ) {
			$doImport( "ContactManager:$titleText", [
				[
					'role' => SlotRecord::MAIN,
					'model' => 'wikitext',
					'text' => $content
				]
			] );
		} );

		$import( "$dirPath/templates", static function ( $titleText, $content ) use ( &$doImport ) {
			$doImport( "Template:ContactManager/$titleText", [
				[
					'role' => SlotRecord::MAIN,
					'model' => 'wikitext',
					'text' => $content
				]
			] );
		} );

		$import( "$dirPath/schemas", static function ( $titleText, $content ) use ( &$doImport ) {
			if ( array_key_exists( 'wgContactManagerSchemas' . $titleText, $GLOBALS ) ) {
				$doImport( 'VisualDataSchema:ContactManager/' . $GLOBALS['wgContactManagerSchemas' . $titleText], [
					[
						'role' => SlotRecord::MAIN,
						'model' => 'json',
						'text' => json_encode( $content )
					]
				] );
			}
		} );
	}

	/**
	 * @param OutputPage $outputPage
	 * @param Skin $skin
	 * @return void
	 */
	public static function onBeforePageDisplay( OutputPage $outputPage, Skin $skin ) {
		$title = $outputPage->getTitle();
		$outputPage->addModules( 'ext.ContactManager' );
	}

	/**
	 * @param Title &$title
	 * @param null $unused
	 * @param OutputPage $output
	 * @param User $user
	 * @param WebRequest $request
	 * @param MediaWiki $mediaWiki
	 * @return void
	 */
	public static function onBeforeInitialize(
		Title &$title,
		$unused,
		OutputPage $output,
		User $user,
		WebRequest $request,
		/* MediaWiki|MediaWiki\Actions\ActionEntryPoint */ $mediaWiki
	) {
		\ContactManager::initialize();
	}

}
