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
 * @author thomas-topway-it <support@topway.i>
 * @copyright Copyright ©2025, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager;

// @see https://www.mediawiki.org/wiki/Extension:Echo/Creating_a_new_notification_type_(1.43)
class EchoCMPresentationModel extends \EchoEventPresentationModel {

	/**
	 * @inheritDoc
	 */
	public function canRender() {
		return (bool)$this->event->getTitle();
	}

	/**
	 * @inheritDoc
	 */
	public function getIconType() {
		// @see https://dahuawiki.com/extensions/Echo/modules/icons/
		// bell
		return 'emailuser';
	}

	/**
	 * @inheritDoc
	 */
	public function getHeaderMessage() {
		$mailbox = $this->event->getExtraParam( 'mailbox' );

		if ( $this->isBundled() ) {
			// This is the header message for the bundle that contains
			// several notifications of this type
			$msg = $this->msg( 'contactmanager-echo-get-messages-updates-header' );
			$msg->params( $mailbox );
			return $msg;

		} else {
			// This is the header message for individual non-bundle message
			$msg = $this->msg( 'contactmanager-echo-get-messages-updates-header' );
			$msg->params( $mailbox );
			return $msg;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getCompactHeaderMessage() {
		// This is the header message for individual notifications
		// *inside* the bundle
		$msg = $this->msg( 'contactmanager-echo-get-messages-new-'
		. $this->event->getExtraParam( 'name' ) );
		$count = $this->event->getExtraParam( 'count' );
		$msg->params( $count );

		return $msg;
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyMessage() {
		$msg = $this->msg( 'contactmanager-echo-get-messages-body-message' );
		return $msg;
	}

	/**
	 * @inheritDoc
	 */
	public function getPrimaryLink() {
		$link = $this->getPageLink( $this->event->getTitle(), '', true );
		return $link;
	}

	/**
	 * @inheritDoc
	 */
	public function getSecondaryLinks() {
		if ( $this->isBundled() ) {
			// For the bundle, we don't need secondary actions
			return [];
		} else {
			// For individual items, display a link to the user
			// that created this page
			return [ $this->getAgentLink() ];
		}
	}
}
