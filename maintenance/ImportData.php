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
 * @copyright Copyright ©2025, https://wikisphere.org
 */

use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class ImportData extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'import data' );
		$this->requireExtension( 'ContactManager' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$delete = $this->getOption( 'delete' ) ?? false;
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		$services = MediaWikiServices::getInstance();
		$services->getUserGroupManager()->addUserToGroup( $user, 'bureaucrat' );

		$importer = \VisualData::getImporter();
		$error_messages = [];
		$context = RequestContext::getMain();
		$title = SpecialPage::getTitleFor( 'Badtitle' );
		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$context->setUser( $user );

		$doImport = static function ( $pagename, $contents ) use ( $context, $importer, &$error_messages ) {
			echo '(ContactManager) importing ' . $pagename;

			try {
				$title_ = TitleClass::newFromText( $pagename );
				$context->setTitle( $title_ );
				$importer->doImportSelf( $pagename, $contents );
				echo ' (success)' . PHP_EOL;
			} catch ( Exception $e ) {
				echo ' ( ***error)' . PHP_EOL;
				$error_messages[$pagename] = $e->getMessage();
			}
		};

		$import = static function ( $path, $callback ) {
			$files = scandir( $path );
			foreach ( $files as $file ) {
				$filePath_ = "$path/$file";
				if ( is_file( $filePath_ ) ) {
					$ext_ = pathinfo( $file, PATHINFO_EXTENSION );
					if ( in_array( $ext_, [ 'json', 'txt' ] ) ) {
						$titleText_ = substr( $file, 0, strpos( $file, '.' ) );
					} else {
						$titleText_ = $file;
					}
					$content = file_get_contents( $filePath_ );
					$callback( $titleText_, $content );
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

		$import( "$dirPath/styles", static function ( $titleText, $content ) use ( &$doImport ) {
			$doImport( "ContactManager:$titleText", [
				[
					'role' => SlotRecord::MAIN,
					'model' => 'sanitized-css',
					'text' => $content
				]
			] );
		} );

		$import( "$dirPath/emailTemplates", static function ( $titleText, $content ) use ( &$doImport ) {
			$doImport( "EmailTemplate:$titleText", [
				[
					'role' => SlotRecord::MAIN,
					'model' => 'twig',
					'text' => $content
				]
			] );
		} );

		$import( "$dirPath/modules", static function ( $titleText, $content ) use ( &$doImport ) {
			$doImport( "Module:ContactManager/$titleText", [
				[
					'role' => SlotRecord::MAIN,
					'model' => 'Scribunto',
					'text' => $content
				]
			] );
		} );

		$import( "$dirPath/schemas", static function ( $titleText, $content ) use ( &$doImport ) {
			if ( array_key_exists( 'wgContactManagerSchemas' . $titleText, $GLOBALS ) ) {
				$titleText = $GLOBALS['wgContactManagerSchemas' . $titleText];
			} else {
				$titleText = "ContactManager/$titleText";
			}

			$doImport( "VisualDataSchema:$titleText", [
				[
					'role' => SlotRecord::MAIN,
					'model' => 'json',
					'text' => $content
				]
			] );
		} );

		if ( count( $error_messages ) ) {
			echo '(ContactManager) ***error importing ' . count( $error_messages ) . ' articles' . PHP_EOL;
		}
	}
}

$maintClass = ImportData::class;
require_once RUN_MAINTENANCE_IF_MAIN;
