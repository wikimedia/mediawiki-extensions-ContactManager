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
 * @copyright Copyright ©2023, https://wikisphere.org
 */

class ContactManager {

	/** @var array[] */
	public static $labelsCamelCaseToKebabCase = [

		'MailboxFilterName' => 'mailbox-filter-name',
		'MailboxFilterMatch' => 'mailbox-filter-match',
		'MailboxFilterMatchText' => 'mailbox-filter-match-text',

		'MailboxFilterHasAttachment' => 'mailbox-filter-has-attachment',
		'MailboxFilterAttachmentSize' => 'mailbox-filter-attachment-size',
		'MailboxFilterAttachmentSizeValue' => 'mailbox-filter-attachment-size-value',

		'MailboxFilterActionTargetPage' => 'mailbox-filter-action-target-page',
		'MailboxFilterActionCategory' => 'mailbox-filter-action-category',
		'MailboxFilterActionSkip' => 'mailbox-filter-action-skip',

		'MailboxName' => 'mailbox-name',
		'MailboxServer' => 'mailbox-server',
		'MailboxUsername' => 'mailbox-username',
		'MailboxPassword' => 'mailbox-password',
		'MailboxRetrievedMessages' => 'mailbox-retrieved-messages',
		'MailboxTargetPage' => 'mailbox-target-page',
		'MailboxAttachmentsFolder' => 'mailbox-attachments-folder',
		'MailboxContactsTargetPage' => 'mailbox-contacts-target-page',
		'MailboxFilters' => 'mailbox-filters',
		'MailboxCategoriesEmail' => 'mailbox-categories-email',
		'MailboxCategoriesContact' => 'mailbox-categories-contact',
		'MailboxContactPagename' => 'mailbox-contact-pagename',

		'EmailFrom' => 'email-from',
		'EmailTo' => 'email-to',
		'EmailCc' => 'email-cc',
		'EmailBcc' => 'email-bcc',
		'EmailReplyTo' => 'email-reply-to',
		'EmailSubject' => 'email-subject',
		'EmailDate' => 'email-date',
		'EmailAttachments' => 'email-attachments',

		'ContactFirstName' => 'contact-first-name',
		'ContactLastName' => 'contact-last-name',
		'ContactName' => 'contact-name',
		'ContactSalutation' => 'contact-salutation',
		'ContactMiddlename' => 'contact-middlename',
		'ContactNickname' => 'contact-nickname',
		'ContactSuffix' => 'contact-suffix',
		'ContactInitials' => 'contact-initials',
		'ContactFullName' => 'contact-full-name',
		'ContactEmail' => 'contact-email',
		'ContactTelephoneNumber' => 'contact-telephone-number',
		'ContactLanguage' => 'contact-language'
	];

	public static function initialize() {
	}

	/**
	 * @param string $key
	 * @return string
	 */
	public static function propertyKeyToLabel( $key ) {
		if ( !empty( $GLOBALS['wgContactManagerPropertyLabels'][$key] ) ) {
			return $GLOBALS['wgContactManagerPropertyLabels'][$key];
		}
		return wfMessage( 'contactmanager-' . self::$labelsCamelCaseToKebabCase[$key] )->text();
	}

	/**
	 * @param array $keys
	 * @return array
	 */
	public static function propertyKeysToLabel( $keys ) {
		$ret = [];
		foreach ( $keys as $key ) {
			if ( !empty( $GLOBALS['wgContactManagerPropertyLabels'][$key] ) ) {
				$ret[$key] = $GLOBALS['wgContactManagerPropertyLabels'][$key];
			} else {
				$ret[$key] = wfMessage( 'contactmanager-' . self::$labelsCamelCaseToKebabCase[$key] )->text();
			}
		}
		return $ret;
	}

	/**
	 * @param Title $title
	 * @return void
	 */
	public static function getWikiPage( $title ) {
		// MW 1.36+
		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			return MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		}
		return WikiPage::factory( $title );
	}

	/**
	 * @param User $user
	 * @param Title $title
	 * @param string $contents
	 * @return bool
	 */
	public static function doCreateContent( $user, $title, $contents ) {
		$content = ContentHandler::makeContent( $contents, $title );
		$wikiPage = self::getWikiPage( $title );

		$summary = "ContactManager extension";

		if ( method_exists( $wikiPage, 'doUserEditContent' ) ) {
			// MW 1.36+
			// Use the global user if necessary (same as doEditContent())
			$user = $user ?? RequestContext::getMain()->getUser();
			$status = $wikiPage->doUserEditContent(
				$content,
				$user,
				$summary,
				EDIT_FORCE_BOT
			);
		} else {
			// <= MW 1.35
			$status = $wikiPage->doEditContent(
				$content,
				$summary,
				EDIT_FORCE_BOT,
				false,
				$user
			);
		}

		if ( !$status->isOk() ) {
			return false;
		}

		$title->invalidateCache();

		return true;
	}
}
