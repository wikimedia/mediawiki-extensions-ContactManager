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

namespace MediaWiki\Extension\ContactManager\Special;

use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserIdentityLookup;

class GetResource extends \SpecialPage {

	/** @var PermissionManager */
	private $permissionManager;

	/** @var UserIdentityLookup */
	private $userIdentityLookup;

	/**
	 * @param PermissionManager $permissionManager
	 * @param UserIdentityLookup $userIdentityLookup
	 */
	public function __construct(
		PermissionManager $permissionManager,
		UserIdentityLookup $userIdentityLookup
	) {
		$this->permissionManager = $permissionManager;
		$this->userIdentityLookup = $userIdentityLookup;

		$listed = false;
		parent::__construct( 'ContactManagerGetResource', '', $listed );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->requireLogin();

		// $this->setHeaders();
		// $this->outputHeader();

		[ $pageid, $filename ] = explode( '/', (string)$par, 2 ) + [ null, null ];

		if ( !$pageid || !$filename ) {
			echo 'missing data';
			exit();
		}

		$title_ = TitleClass::newFromID( $pageid );
		if ( !$title_ || !$title_->isKnown() ) {
			echo 'no valid attachment';
			exit();
		}

		$user = $this->getUser();
		if ( !$this->permissionManager->userCan( 'read', $user, $title_ ) ) {
			$this->displayRestrictionError();
			return;
		}

		$out = $this->getOutput();
		$out->disable();

		$jsonData = \VisualData::getJsonData( $title_ );
		$schema_ = $GLOBALS['wgContactManagerSchemasIncomingMail'];
		if ( !isset( $jsonData['schemas'][$schema_]['attachments'] ) ) {
			echo 'no valid attachment';
			exit();
		}
		$attachments = $jsonData['schemas'][$schema_]['attachments'];

		$attachment = null;
		foreach ( (array)$attachments as $value ) {
			if ( str_replace( '_', ' ', $value['name'] ) === str_replace( '_', ' ', $filename ) ) {
				$attachment = $value;
				break;
			}
		}

		if ( !$attachment ) {
			echo 'no valid attachment';
			exit();
		}

		$path = \ContactManager::getAttachmentsFolder() . '/' . $pageid . '/' . $attachment['name'];

		if ( !file_exists( $path ) ) {
			echo 'file does not exist';
			exit();
		}
		$contents = file_get_contents( $path );

		$mediawikiResponse = $out->getContext()->getRequest()->response();
		$mediawikiResponse->statusHeader( 200 );
		$mediawikiResponse->header( 'Content-Type: ' . $attachment['mimeType'] );
		$mediawikiResponse->header( 'Content-Disposition: inline' );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Headers: *' );
		$mediawikiResponse->header( 'Access-Control-Allow-Credentials: true' );
		$mediawikiResponse->header( 'Access-Control-Allow-Origin: *' );
		$mediawikiResponse->header( 'Access-Control-Allow-Headers: *' );

		echo $contents;
		$mediaWiki = new MediaWiki();
		$mediaWiki->restInPeace();
	}
}
