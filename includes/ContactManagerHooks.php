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

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

define( 'SLOT_ROLE_CONTACTMANAGER_TEXT', 'contactmanager-text' );
define( 'CONTENT_MODEL_CONTACTMANAGER_TEXT', 'text' );

define( 'SLOT_ROLE_CONTACTMANAGER_HTML', 'contactmanager-html' );
define( 'CONTENT_MODEL_CONTACTMANAGER_HTML', 'html' );

define( 'SLOT_ROLE_CONTACTMANAGER_RAW', 'contactmanager-raw' );
define( 'CONTENT_MODEL_CONTACTMANAGER_RAW', 'text' );

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
		$importer = \PageProperties::getImporter();
		$fileContents = file_get_contents( __DIR__ . '/../data/forms.json' );
		$forms = json_decode( $fileContents, true );
		$error_messages = [];

		foreach ( $forms as $formName => $descriptor ) {
			$pagename = 'PagePropertiesForm:' . $formName;
			$contents = [
				[
					'role' => SlotRecord::MAIN,
					'model' => 'json',
					'text' => json_encode( $descriptor )
				]
			];
			try {
				$importer->doImportSelf( $pagename, $contents );
			} catch ( Exception $e ) {
				$error_messages[$pagename] = $e->getMessage();
			}
		}
	}

	/**
	 * @param MediaWikiServices $services
	 * @return void
	 */
	public static function onMediaWikiServices( $services ) {
		$services->addServiceManipulator(
			'SlotRoleRegistry',
			static function ( \MediaWiki\Revision\SlotRoleRegistry $registry ) {
				$roles = [
					SLOT_ROLE_CONTACTMANAGER_TEXT => CONTENT_MODEL_CONTACTMANAGER_TEXT,
					SLOT_ROLE_CONTACTMANAGER_RAW => CONTENT_MODEL_CONTACTMANAGER_RAW,
					SLOT_ROLE_CONTACTMANAGER_HTML => CONTENT_MODEL_CONTACTMANAGER_HTML
				];

				foreach ( $roles as $role => $model ) {
					if ( !$registry->isDefinedRole( $role ) ) {
						$registry->defineRoleWithModel( $role, $model, [
						"display" => "none",
						"region" => "center",
						"placement" => "append"
						] );
					}
				}
			} );
	}

	/**
	 * @see https://github.com/SemanticMediaWiki/SemanticMediaWiki/blob/master/docs/examples/hook.property.initproperties.md
	 * @param SMW\PropertyRegistry $propertyRegistry
	 * @return void
	 */
	public static function onSMWPropertyinitProperties( SMW\PropertyRegistry $propertyRegistry ) {
		$types = [
			'EmailDate' => '_dat',
			'ContactTelephoneNumber' => '_tel',
			'MailboxFilterAttachmentSizeValue' => '_num',
			'MailboxFilterActionTargetPage' => '_wpg',
			'MailboxFilterActionCategory' => '_wpg',
		];

		foreach ( \ContactManager::$labelsCamelCaseToKebabCase as $key => $value ) {
			$label = \ContactManager::propertyKeyToLabel( $key );
			$propertyId = '__contactmanager_' . str_replace( '-', '_', $value );
			$type = ( empty( $types[$key] ) ? '_txt' : $types[$key] );
			$viewable = true;
			$annotable = true;
			$description = 'contactmanager-' . $value . '-desc';

			$propertyRegistry->registerProperty(
				$propertyId,
				$type,
				$label,
				$viewable,
				$annotable
			);

			$propertyRegistry->registerPropertyDescriptionByMsgKey(
				$propertyId,
				$description
			);

		}

		return true;
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
		\Title &$title,
		$unused,
		\OutputPage $output,
		\User $user,
		\WebRequest $request,
		\MediaWiki $mediaWiki
	) {
		\ContactManager::initialize();
	}

}
