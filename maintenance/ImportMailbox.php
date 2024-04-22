<?php

/**
 * This file is part of the MediaWiki extension ContactMananger.
 *
 * ContactManager is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * ContactMananger is distributed in the hope that it will be useful,
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

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

if ( is_readable( __DIR__ . '/../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../vendor/autoload.php';
}

class ImportMailbox extends Maintenance {
	/** @var User */
	private $user;

	/** @var string */
	private $langCode;

	/** @var importer */
	private $importer;

	/** @var error_messages */
	private $error_messages = [];

	/** @var attachmentsPathTmp */
	private $attachmentsPathTmp;

	/** @var propertyNames */
	private $propertyNames = [];

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'import mailbox' );
		$this->requireExtension( 'ContactManager' );

		$this->addOption( 'mailbox', 'limit import to specific mailbox', false, true );
	}

	public function execute() {
		$mailbox = $this->getOption( 'mailbox' ) ?? null;

		$this->propertyNames = \ContactManager::propertyKeysToLabel( [
			'MailboxName',
			'MailboxServer',
			'MailboxUsername',
			'MailboxPassword',
			'MailboxRetrievedMessages',
			'MailboxTargetPage',
			'MailboxCategoriesEmail',
			'MailboxCategoriesContact',
			'MailboxContactsTargetPage',
			'ContactFirstName',
			'ContactLastName',
			'ContactSalutation',
			'ContactMiddlename',
			'ContactNickname',
			'ContactInitials',
			'ContactSuffix',
			'ContactFullName',
			'EmailFrom',
			'EmailTo',
			'EmailCc',
			'EmailBcc',
			'EmailReplyTo',
			'EmailSubject',
			'EmailDate',
			'EmailAttachments'
		] );

		$services = MediaWikiServices::getInstance();
		$contLang = $services->getContentLanguage();
		$this->langCode = $contLang->getCode();
		$this->user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );

		echo 'getMailboxes ...' . "\n";
		$mailboxes = $this->getMailboxes();

		$this->importer = \PageProperties::getImporter();

		// loop mailboxes
		foreach ( $mailboxes as $titleText => $value ) {
			$title = Title::newFromText( $titleText );
			$properties = \PageProperties::getPageProperties( $title );

			[
				$mailboxName,
				$mailboxServer,
				$mailboxUsername,
				$mailboxPassword,
				$mailboxTargetPage,
				$mailboxCategoriesEmail,
				$mailboxCategoriesContact,
				$mailboxRetrievedMessages
			] = $this->getPropertyValue(
				$properties, [
					'MailboxName',
					'MailboxServer',
					'MailboxUsername',
					'MailboxPassword',
					'MailboxTargetPage',
					'MailboxCategoriesEmail',
					'MailboxCategoriesContact',
					'MailboxRetrievedMessages'
				] );

			if ( !empty( $mailbox ) && trim( $mailbox ) !== trim( $mailboxName ) ) {
				continue;
			}

			echo 'connectMailbox ...' . "\n";
			echo 'mailbox: ' . "$mailboxName \n";
			echo 'mailboxServer: ' . "$mailboxServer \n";
			echo 'mailboxUsername: ' . "$mailboxUsername \n";

			$mailbox = $this->connectMailbox( $mailboxServer, $mailboxUsername, $mailboxPassword );
			$mailbox->setAttachmentsDir( $this->attachmentsPathTmp );

			echo 'get folders ...' . "\n";

			$folders = $mailbox->getMailboxes( '*' );
			$retrievedMessages = (
				!empty( $mailboxRetrievedMessages ) ? json_decode( $mailboxRetrievedMessages, true ) : []
			);

			// loop folders
			foreach ( $folders as $key => $folder ) {
				$mailbox->switchMailbox( $folder['fullpath'] );
				$latest_uid = (
					!empty( $retrievedMessages[$folder['shortpath']] ) ? $retrievedMessages[$folder['shortpath']] : 1
				);

				// or: 'SINCE "1 Jan 2018" BEFORE "28 Jan 2018"'
				$mails = $mailbox->fetch_overview( "$latest_uid:*" );

				// loop emails
				foreach ( $mails as $headers ) {
					$uid = $headers->uid;

					echo "get email({$headers->uid}) ...\n";

					$mail = $mailbox->getMail( $headers->uid );

					$folderName = str_replace( [ '[', ']' ], '', $folder['shortpath'] );
					$folderName = str_replace( '/', ' - ', $folderName );

					// $pageName is adjusted by reference
					$targetPage = $this->createTargetPage( $mailboxName, $folderName, $uid );

					echo 'targetPage: ' . $targetPage . "\n";

					$mailRaw = $mailbox->getMailHeaderRaw( $headers->uid );

					$this->saveEmail( $folder, $uid, $targetPage, $mail, $mailRaw, $mailboxCategoriesEmail );
					$this->saveContact( $mailboxName, $folderName, $mailboxCategoriesContact, $mail->fromName );

				}
			}
		}
	}

	/**
	 * @param string $mailboxName
	 * @param string $folderName
	 * @param string $mailboxCategoriesContact
	 * @param string $fromName
	 * @return bool
	 */
	private function saveContact( $mailboxName, $folderName, $mailboxCategoriesContact, $fromName ) {
		$parser = new TheIconic\NameParser\Parser();
		if ( empty( $fromName ) ) {
			return false;
		}
		$parsedName = $parser->parse( $fromName );
		$fullName = $parsedName->getFullname();

		$query_string = '[[' . $this->propertyNames['ContactFullName'] . '::' . $fullName . ']]';
		$properties_to_display = [];
		$parameters = [];
		$display_title = false;

		$results = \PageProperties::getQueryResults(
			$query_string,
			$properties_to_display,
			$parameters,
			$display_title
		);
		$arr = $results->serializeToArray();

		if ( count( $arr ) ) {
			echo 'contact data exist (' . $fullName . ")\n";
			return false;
		}

		$pageName = str_ireplace(
			[ '{{mailboxName}}', '{{folder}}', '{{fullName}}' ],
			[ $mailboxName, $folderName, $fullName ],
		$this->propertyNames['MailboxContactsTargetPage'] );

		$title_ = Title::newFromText( $pageName );
		if ( $title_->isKnown() ) {
			$i = 0;
			do {
				$title_ = Title::newFromText( $titleText . ' (' . $i . ')' );
			} while ( $title_->isKnown() );
		}

		$pageProperties = [
			'semantic-properties' => [
				$this->propertyNames['ContactFirstName'] => $parsedName->getFirstname(),
				$this->propertyNames['ContactLastName'] => $parsedName->getLastname(),
				$this->propertyNames['ContactSalutation'] => $parsedName->getSalutation(),
				$this->propertyNames['ContactMiddlename'] => $parsedName->getMiddlename(),
				$this->propertyNames['ContactNickname'] => $parsedName->getNickname(),
				$this->propertyNames['ContactInitials'] => $parsedName->getInitials(),
				$this->propertyNames['ContactSuffix'] => $parsedName->getSuffix(),
				$this->propertyNames['ContactFullName'] => $fullName,
			],
			'semantic-forms' => [ "Contact manager contact" ],
			'page-properties' => [
				'categories' => $mailboxCategoriesContact ?? []
			]
		];

		$pageName = $title_->getText();
		$wikitext = null;

		$contents = [
			[
				'role' => SlotRecord::MAIN,
				'model' => 'wikitext',
				'text' => $wikitext
			],
			[
				'role' => SLOT_ROLE_PAGEPROPERTIES,
				'model' => CONTENT_MODEL_PAGEPROPERTIES_SEMANTIC,
				'text' => json_encode( $pageProperties )
			],
		];

		echo 'saving contact: ' . $pagename . "\n";

		try {
			$this->importer->doImportSelf( $pagename, $contents );
		} catch ( Exception $e ) {
			$this->error_messages[$pagename] = $e->getMessage();
		}
	}

	/**
	 * @param array $folder
	 * @param int $uid
	 * @param Title $targetPage
	 * @param PhpImap\IncomingMail $mail
	 * @param string $mailRaw
	 * @param string $mailboxCategoriesEmail
	 */
	private function saveEmail( $folder, $uid, $targetPage, $mail, $mailRaw, $mailboxCategoriesEmail ) {
		global $wgContactManagerAttachments;
		// $mailbox->setAttachmentsDir( $this->attachmentsPathTmp );

		$title = Title::newFromText( $targetPage );
		$attachments = $mail->getAttachments();

		$filenames = [];
		foreach ( $attachments as $attachment ) {
			if ( $attachment->disposition === 'attachment' ) {
				$filenames[] = $attachment->name;
			}
		}

		$emailFrom = (
			$mail->fromName ? $mail->fromName . ' <' . str_replace( [ '<', '>' ], '', $mail->fromAddress ) . '>'
			: str_replace( [ '<', '>' ], '', $mail->fromAddress )
		);

		$wikitext = '';

		$pageProperties = [
			'semantic-properties' => [
				$this->propertyNames['EmailFrom'] => $emailFrom,
				$this->propertyNames['EmailTo'] => $this->formatRecipient( $mail->to ),
				$this->propertyNames['EmailCc'] => $this->formatRecipient( $mail->cc ),
				$this->propertyNames['EmailBcc'] => $this->formatRecipient( $mail->bcc ),
				$this->propertyNames['EmailReplyTo'] => $this->formatRecipient( $mail->replyTo ),
				$this->propertyNames['EmailSubject'] => $mail->subject,
				$this->propertyNames['EmailDate'] => $mail->date,
				$this->propertyNames['EmailAttachments'] => $filenames,
			],
			'semantic-forms' => [ "Contact manager email" ],
				'page-properties' => [
					'categories' => $mailboxCategoriesEmail ?? []
				]
			];

		$this->doImport( $targetPage, $wikitext, $mail->textPlain, $mail->textHtml, $mailRaw, $pageProperties );

		$pathTarget = $wgContactManagerAttachments . '/' . $title->getArticleID();
		if ( count( $attachments ) &&
			( file_exists( $pathTarget ) || mkdir( $pathTarget, 0777, true ) ) ) {

			foreach ( $attachments as $attachment ) {
				rename( $this->attachmentsPathTmp . '/' . $attachment->name, $pathTarget . '/' . $attachment->name );
			}
		}

		// @TODO check if inline attachments are accessible
		// after move

		$retrievedMessages[$folder['shortpath']] = $uid;

		// update at each imported email
		$update_obj = $pageProperties;
		// phpcs:ignore Generic.Files.LineLength.TooLong
		$update_obj['semantic-properties'][$this->propertyNames['MailboxRetrievedMessages']] = json_encode( $retrievedMessages );

		$errors = [];
		$ret = \PageProperties::setPageProperties( $this->user, $title, $update_obj, $errors );
	}

	/**
	 * @param array $arr
	 * @return array
	 */
	private function formatRecipient( $arr ) {
		$ret = [];
		foreach ( $arr as $key => $value ) {
			$ret[] = ( $key !== $value ? $value . ' <' . $key . '>' : str_replace( [ '<', '>' ], '', $value ) );
		}

		return $ret;
	}

	/**
	 * @param array $properties
	 * @param array $names
	 * @return array
	 */
	private function getPropertyValue( $properties, $names ) {
		$ret = [];
		foreach ( $names as $value ) {
			$ret[] = (
				array_key_exists( $this->propertyNames[$value], $properties['semantic-properties'] )
					? $properties['semantic-properties'][$this->propertyNames[$value]] : null
			);
		}
		return $ret;
	}

	/**
	 * @return array
	 */
	private function getMailboxes() {
		$query_string = '[[' . $this->propertyNames['MailboxName'] . '::+]]';
		$properties_to_display = [];
		$parameters = [];
		$display_title = true;

		$results = \PageProperties::getQueryResults(
			$query_string,
			$properties_to_display,
			$parameters,
			$display_title
		);
		$ret = $results->serializeToArray();
		return ( array_key_exists( 'results', $ret ) ? $ret['results'] : [] );
	}

	/**
	 * @param string $mailboxName
	 * @param string $folderName
	 * @param int $uid
	 * @return string
	 */
	private function createTargetPage( $mailboxName, $folderName, $uid ) {
		if ( !empty( $mailboxTargetPage ) ) {
			$pageName = str_ireplace(
				[ '{{mailboxName}}', '{{folder}}', '{{messageId}}' ],
				[ $mailboxName, $folderName, $uid ],
				$mailboxTargetPage );

		} else {
			$pageName = $mailboxName . '/' . $folderName . '/' . $uid; // $mail->messageId;
		}

		// create parent pages
		$subpages = explode( '/', $pageName );
		array_pop( $subpages );
		$path_ = [];
		foreach ( $subpages as $titleText_ ) {
			$path_[] = $titleText_;
			$title_ = Title::newFromText( implode( '/', $path_ ) );

			if ( $title_ && !$title_->isKnown() ) {
				$ret_ = \ContactManager::doCreateContent( null, $title_, "" );
			}
		}

		// https://www.mediawiki.org/wiki/Manual:Page_title#:~:text=Titles%20containing%20the%20characters%20%23,for%20MediaWiki%20it%20is%20not.
		// forbidden chars: # < > [ ] | { } _
		$pageName = str_replace( [ '#', '<', '>', '[', ']', '|', '{', '}', '_' ], '', $pageName );
		return $pageName;
	}

	/**
	 * @param string $pagename
	 * @param string $wikitext
	 * @param string $mailText
	 * @param string $mailHtml
	 * @param string $mailRaw
	 * @param array $pageProperties
	 */
	private function doImport( $pagename, $wikitext, $mailText, $mailHtml, $mailRaw, $pageProperties ) {
		$contents = [
			[
				'role' => SlotRecord::MAIN,
				'model' => 'wikitext',
				'text' => $wikitext
			],
			[
				'role' => SLOT_ROLE_CONTACTMANAGER_TEXT,
				'model' => CONTENT_MODEL_CONTACTMANAGER_TEXT,
				'text' => $mailText
			],
			[
				'role' => SLOT_ROLE_CONTACTMANAGER_HTML,
				'model' => CONTENT_MODEL_CONTACTMANAGER_HTML,
				'text' => $mailHtml
			],
			[
				'role' => SLOT_ROLE_CONTACTMANAGER_RAW,
				'model' => CONTENT_MODEL_CONTACTMANAGER_RAW,
				'text' => $mailRaw
			],
			[
				'role' => SLOT_ROLE_PAGEPROPERTIES,
				'model' => CONTENT_MODEL_PAGEPROPERTIES_SEMANTIC,
				'text' => json_encode( $pageProperties )
			],
		];

		try {
			$this->importer->doImportSelf( $pagename, $contents );
		} catch ( Exception $e ) {
			$this->error_messages[$pagename] = $e->getMessage();
		}
	}

	/**
	 * @param string $server
	 * @param string $username
	 * @param string $password
	 * @param string|null $mailbox
	 * @param int $port
	 * @return ContactManagerMailbox
	 */
	private function connectMailbox( $server, $username, $password, $mailbox = "", $port = 993 ) {
		global $wgContactManagerAttachments;

		$attachmentsPathTmp = $wgContactManagerAttachments . '/tmp';
		if ( !file_exists( $attachmentsPathTmp ) ) {
			mkdir( $attachmentsPathTmp, 0777, true );
		}
		$this->attachmentsPathTmp = $attachmentsPathTmp;

		// return new PhpImap\Mailbox(
		return new ContactManagerMailbox(
			'{' . $server . ':' . $port . '/imap/ssl}' . $mailbox,
			$username,
			$password,
			 // __DIR__, // Directory, where attachments will be saved (optional)
			$this->attachmentsPathTmp,
			'UTF-8', // Server encoding (optional)
			true, // Trim leading/ending whitespaces of IMAP path (optional)
			true // Attachment filename mode (optional; false = random filename; true = original filename)
		);
	}

}

$maintClass = ImportMailbox::class;
require_once RUN_MAINTENANCE_IF_MAIN;
