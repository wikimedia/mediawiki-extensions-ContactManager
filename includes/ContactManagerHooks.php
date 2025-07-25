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
 * @copyright Copyright ©2023-2025, https://wikisphere.org
 */

use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;

define( 'CONTENT_MODEL_CONTACTMANAGER_TWIG', 'twig' );

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
		$GLOBALS['wgDebugLogGroups']['ContactManager'] = $GLOBALS['IP'] . '/'
			. $GLOBALS['wgContactManagerDebugPath'];
	}

	/**
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'shadowroot', [ \ContactManager::class, 'parserFunctionShadowRoot' ] );
	}

	/**
	 * @param DatabaseUpdater|null $updater
	 */
	public static function onLoadExtensionSchemaUpdates( ?DatabaseUpdater $updater = null ) {
		$base = __DIR__;
		$db = $updater->getDB();
		$dbType = $db->getType();
		$tables = [
			'contactmanager_sent',
			'contactmanager_tracking',
			'contactmanager_tracking_sendgrid'
		];

		foreach ( $tables as $tableName ) {
			$filename = "$base/../$dbType/$tableName.sql";

			// echo $filename;
			if ( file_exists( $filename ) && !$db->tableExists( $tableName ) ) {
				$updater->addExtensionUpdate(
					[
						'addTable',
						$tableName,
						$filename,
						true
					]
				);
			}
		}
	}

	/**
	 * @param OutputPage $outputPage
	 * @param Skin $skin
	 * @return void
	 */
	public static function onBeforePageDisplay( OutputPage $outputPage, Skin $skin ) {
		$outputPage->addModules( 'ext.ContactManager' );
	}

	/**
	 * @param Title|Mediawiki\Title\Title &$title
	 * @param null $unused
	 * @param OutputPage $output
	 * @param User $user
	 * @param WebRequest $request
	 * @param MediaWiki $mediaWiki
	 * @return void
	 */
	public static function onBeforeInitialize(
		&$title,
		$unused,
		OutputPage $output,
		User $user,
		WebRequest $request,
		/* MediaWiki|MediaWiki\Actions\ActionEntryPoint */ $mediaWiki
	) {
		\ContactManager::initialize();
	}

	/**
	 * @param User $user
	 * @param Title|Mediawiki\Title\Title $targetTitle
	 * @param array $jsonData
	 * @param string $freetext
	 * @param bool $isNewPage
	 * @param array &$errors
	 * @return void
	 */
	public static function VisualDataOnFormSubmit( $user, $targetTitle, $jsonData, $freetext, $isNewPage, &$errors = [] ) {
		if ( !empty( $jsonData['schemas'][$GLOBALS['wgContactManagerSchemasComposeEmail']] ) ) {

			if ( !$user->isAllowed( 'contactmanager-can-manage-mailboxes' ) ) {
				return;
			}

			\ContactManager::sendEmail( $user, $targetTitle, $jsonData, $freetext, $isNewPage, $errors );
		}
	}

	/**
	 * @param Skin $skin
	 * @param array &$bar
	 * @return void
	 */
	public static function onSkinBuildSidebar( $skin, &$bar ) {
		$user = $skin->getUser();
		if ( !empty( $GLOBALS['wgContactManangerDisableSidebarLink'] ) ) {
			return;
		}

		if ( !$user->isAllowed( 'contactmanager-can-manage-mailboxes' ) ) {
			return;
		}

		$links = [
			'main-page' => 'Main Page',
			'compose' => 'Compose',
			'mailboxes' => 'Mailboxes',
			'mailers' => 'Mailers',
			'create-contact' => 'Create contact',
			'organizations' => 'Organizations',
			'data-structure' => 'Data structure',
		];

		foreach ( $links as $key => $value ) {
			$title_ = TitleClass::newFromText( "ContactManager:$value" );
			$bar[ wfMessage( 'contactmanager-sidepanel-section' )->text() ][] = [
				'text'   => wfMessage( "contactmanager-sidepanel-$key" )->text(),
				'href'   => $title_->getLocalURL()
			];
		}

		$title_ = \SpecialPage::getTitleFor( 'ContactManagerBrowseTracking' );
		$bar[ wfMessage( 'contactmanager-sidepanel-section' )->text() ][] = [
				'text'   => wfMessage( 'contactmanager-browse-tracking' )->text(),
				'href'   => $title_->getLocalURL()
			];
	}
}
