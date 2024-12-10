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

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\ContactManager\ImportMessage;
use MediaWiki\Extension\ContactManager\Mailbox;
use MediaWiki\Extension\ContactManager\Mailer;
use MediaWiki\Extension\ContactManager\RecordHeader;
use MediaWiki\Extension\VisualData\Importer as VisualDataImporter;
use MediaWiki\MediaWikiServices;
use TheIconic\NameParser\Parser as IconicParser;

if ( is_readable( __DIR__ . '/../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../vendor/autoload.php';
}

class ContactManager {
	public static function initialize() {
	}

	/**
	 * @param Parser $parser
	 * @param mixed ...$argv
	 * @return array
	 */
	public static function parserFunctionShadowRoot( Parser $parser, ...$argv ) {
		$parserOutput = $parser->getOutput();

		$ret = '<div id="' . $argv[1] . '"><template shadowrootmode="open">'
			. base64_decode( $argv[0] ) . '</template></div>';

		return [
			$ret,
			// should't be the opposite ?
			// however with 'noparse' => true,
			// the head tag is converted to 'pre'
			// causing unintended behaviour
			'noparse' => false,
			'isHTML' => true,
		];
	}

	/**
	 * @param User $user
	 * @param array $obj
	 * @param array &$errors
	 * @return bool
	 */
	public static function sendEmail( $user, $obj, &$errors = [] ) {
		$context = RequestContext::getMain();
		$schema = \VisualData::getSchema( $context, $GLOBALS['wgContactManagerSchemasComposeEmail'] );
		$editor = $schema['properties']['text']['wiki']['preferred-input'];

		$mailer = new Mailer( $user, $obj, $editor );
		if ( count( $mailer->errors ) ) {
			$errors = $mailer->errors;
			return false;
		}
		if ( $mailer->sendEmail() === false ) {
			$errors = $mailer->errors;
			return false;
		}
		return true;
	}

	/**
	 * @param array $jobs
	 * @return int
	 */
	public static function pushJobs( $jobs ) {
		$count = count( $jobs );
		if ( !$count ) {
			return 0;
		}
		$services = MediaWikiServices::getInstance();
		if ( method_exists( $services, 'getJobQueueGroup' ) ) {
			// MW 1.37+
			$services->getJobQueueGroup()->push( $jobs );
		} else {
			JobQueueGroup::singleton()->push( $jobs );
		}

		return $count;
	}

	/**
	 * @param User $user
	 * @param string $mailboxName
	 * @param array &$errors []
	 * @return bool|array
	 */
	public static function getInfo( $user, $mailboxName, &$errors ) {
		$mailbox = new Mailbox( $mailboxName, $errors );

		if ( !$mailbox ) {
			return false;
		}

		$res = $mailbox->getInfo( $errors );

		if ( !$res ) {
			return false;
		}

		$targetTitle = str_replace( '$1', $mailboxName, $GLOBALS['wgContactManagerMailboxInfoArticle'] );

		$jsonData = [
			$GLOBALS['wgContactManagerSchemasMailboxInfo'] => [
				'name' => $mailboxName,
				'unread' => $res['Unread'],
				'deleted' => $res['Deleted'],
				'nmsgs' => $res['Nmsgs'],
				'size' => $res['Size'],
				'date' => $res['Date'],
				'mailbox' => $res['Mailbox'],
				'recent' => $res['Recent'],
			]
		];

		$title = Title::newFromText( $targetTitle );
		\VisualData::updateCreateSchemas( $user, $title, $jsonData, 'jsondata' );
	}

	/**
	 * @param User $user
	 * @param string $mailboxName
	 * @param array &$errors []
	 * @return array
	 */
	public static function getFolders( $user, $mailboxName, &$errors ) {
		$mailbox = new Mailbox( $mailboxName, $errors );

		if ( !$mailbox ) {
			return false;
		}

		$res = $mailbox->getFolders( $errors );

		if ( !$res ) {
			// $this->error = array_pop( $errors );
			return false;
		}

		$targetTitle = str_replace( '$1', $mailboxName, $GLOBALS['wgContactManagerMailboxFoldersArticle'] );

		$jsonData = [
			$GLOBALS['wgContactManagerSchemasMailboxFolders'] => [
				'name' => $mailboxName,
				'folders' => $res
			]
		];

		$title = Title::newFromText( $targetTitle );
		\VisualData::updateCreateSchemas( $user, $title, $jsonData, 'jsondata' );
	}

	/**
	 * @param User $user
	 * @param array $params
	 * @param array &$errors []
	 * @return array
	 */
	public static function getMessages( $user, $params, &$errors ) {
		$mailbox = new Mailbox( $params['mailbox'], $errors );

		if ( !$mailbox ) {
			return false;
		}

		$imapMailbox = $mailbox->getImapMailbox();
		$imapMailbox->setAttachmentsIgnore( (bool)$params['attachments_ignore'] );

		if ( $params['fetch'] === 'search' ) {
			$criteria = [];
			foreach ( $params['criteria'] as $key => $value ) {
				switch ( $value['criteria'] ) {
					case 'SINCE':
					case 'BEFORE':
					case 'ON':
						if ( !empty( $value['date_value'] ) ) {
							$criteria[] = $value['criteria'] . ' "' . $value['date_value'] . '"';
						}
						break;
					case 'BCC':
					case 'BODY':
					case 'CC':
					case 'FROM':
					case 'KEYWORD':
					case 'SUBJECT':
					case 'TEXT':
					case 'TO':
					case 'UNKEYWORD':
						$criteria[] = $value['criteria'] . ' "' . addslashes( $value['string_value'] ) . '"';
						break;
					default:
						$criteria[] = $value['criteria'];
				}
			}
		}

		// get folders data, required to check
		// for uidvalidity
		if ( !empty( $params['fetch_folder_status'] ) || $params['fetch'] === 'UIDs incremental' ) {
			$schema_ = $GLOBALS['wgContactManagerSchemasMailboxFolders'];
			$query_ = '[[name::' . $params['mailbox'] . ']]';
			$results_ = \VisualData::getQueryResults( $schema_, $query_ );

			if ( empty( $results_ ) ) {
				$errors[] = 'mailbox folders haven\'t yet been retrieved';
				return false;
			}

			$foldersData = $results_[0]['data'];
		}

		$title = Title::newFromID( $params['pageid'] );
		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$output = $context->getOutput();
		$output->setTitle( $title );

		$folders = $params['folders'];
		$jobs = [];

		$showMsg = static function ( $msg ) {
			echo $msg . PHP_EOL;
		};

		foreach ( $folders as $folder ) {
			$name_pos = strpos( $folder['folder'], '}' );
			$shortpath = substr( $folder['folder'], $name_pos + 1 );
			$imapMailbox->switchMailbox( $shortpath );

			if ( !empty( $params['fetch_folder_status'] ) || $params['fetch'] === 'UIDs incremental' ) {
				// check mailbox status (of this folder)
				$status_ = (array)$imapMailbox->statusMailbox( $errors );

				// get/update folder status
				$folderKey = -1;
				foreach ( $foldersData['folders'] as $folderKey => $value_ ) {
					if ( $value_['shortpath'] === $shortpath ) {
						break;
					}
				}

				if ( $folderKey === -1 ) {
					$errors[] = 'mailbox folder (' . $shortpath . ') hasn\'t been retrieved';
					return false;
				}

				$folder_ = $foldersData['folders'][$folderKey];

				if ( !empty( $folder_['status']['uidvalidity'] )
					&& (int)$folder_['status']['uidvalidity'] !== $status_['uidvalidity']
				) {
					$errors[] = 'mailbox\'s UIDs have been reorganized, fetch again the entire mailbox';
					return false;
				}

				// update status
				$foldersData['folders'][$folderKey]['status'] = array_merge(
						( !empty( $data_['folders'][$folderKey]['status'] )
							? $data_['folders'][$folderKey]['status']
							: []
						),
					$status_ );
			// if ( !empty( $params['fetch_folder_status'] ) ) {
			}

			// @TODO implement UI for fetch_overview
			// $latest_uid = 1;
			// $mails = $imapMailbox->fetch_overview( "$latest_uid:*" );

			switch ( $params['fetch'] ) {
				case 'search':
					$UIDs = $imapMailbox->searchMailbox( implode( ' ', $criteria ) );
					$UIDsHeaderSequence = $UIDsMessageSequence = implode( ',', array_values( $UIDs ) );
					break;
				case 'UIDs greater than or equal':
					$UIDsHeaderSequence = $UIDsMessageSequence = $params['UID_from'] . ':' . $status_['messages'];
					break;
				case 'UIDs less than or equal':
					$UIDsHeaderSequence = $UIDsMessageSequence = '1:' . $params['UID_to'];
					break;
				case 'UIDs range':
					$UIDsHeaderSequence = $UIDsMessageSequence = $params['UID_from'] . ':' . $params['UID_to'];
					break;
				default:
				case 'UIDs incremental':
					// get latest knows UID for this folder
					$schema_ = $GLOBALS['wgContactManagerSchemasMessageHeader'];
					// phpcs:ignore Generic.Files.LineLength.TooLong
					$query_ = '[[uid::+]][[ContactManager:Mailboxes/' . $params['mailbox'] . '/headers/' . $folder['folder_name'] . '/~]]';
					$printouts_ = [ 'uid' ];
					$options_ = [ 'limit' => 1, 'order' => 'uid DESC' ];
					$results_ = \VisualData::getQueryResults( $schema_, $query_, $printouts_, $options_ );

					$lastKnowHeaderUid = ( !empty( $results_[0] ) && !empty( $results_[0]['data']['uid'] ) ?
						$results_[0]['data']['uid'] : 0 );

					if ( !empty( $params['fetch_message'] ) ) {
						$schema_ = $GLOBALS['wgContactManagerSchemasIncomingMail'];
						// phpcs:ignore Generic.Files.LineLength.TooLong
						$query_ = '[[id::+]][[ContactManager:Mailboxes/' . $params['mailbox'] . '/messages/' . $folder['folder_name'] . '/~]]';
						$printouts_ = [ 'id' ];
						$options_ = [ 'limit' => 1, 'order' => 'id DESC' ];
						$results_ = \VisualData::getQueryResults( $schema_, $query_, $printouts_, $options_ );

						$lastKnowMessageUid = ( !empty( $results_[0] ) && !empty( $results_[0]['data']['id'] ) ?
							$results_[0]['data']['id'] : 0 );

						$UIDsMessageSequence = ( $lastKnowMessageUid + 1 ) . ':' . $status_['messages'];
					}
					$UIDsHeaderSequence = ( $lastKnowHeaderUid + 1 ) . ':' . $status_['messages'];
			}

			// get headers
			// $mailsInfo = $imapMailbox->getMailsInfo( $UIDs_sequence );
			$overviewHeader = $imapMailbox->fetch_overview( $UIDsHeaderSequence );

			foreach ( $overviewHeader as $header ) {
				$header = (array)$header;

				// $job_ = new ContactManagerJob( $title, array_merge( $params, [
				// 	'job' => 'record-header',
				// 	'folder' => $folder,
				// 	'obj' => $header
				// ] ) );

				// run synch
				// $job_->run();

				// *** this will run asynch, which is not
				// what we want, since the $lastKnowMessageUid
				// won't be reliable
				// $jobs[] = $job_;

				// *** alternatively use the following
				$recordHeader = new RecordHeader( $user, array_merge( $params, [
					'folder' => $folder,
					'obj' => $header
				] ), $errors );

				$recordHeader->doImport();

				// *** important !!
				// @see JobRunner -> doExecuteJob
				DeferredUpdates::doUpdates();
			}

			if ( !empty( $params['fetch_message'] ) ) {
				$overviewMessage = ( $UIDsMessageSequence === $UIDsHeaderSequence
					 ? $overviewHeader
					 : $imapMailbox->fetch_overview( $UIDsMessageSequence )
				);

				foreach ( $overviewMessage as $header ) {
					$header = (array)$header;

					// $jobs[] = new ContactManagerJob( $title, array_merge( $params, [
					// 	'job' => 'retrieve-message',
					// 	'folder' => $folder['folder'],
					// 	'folder_name' => $folder['folder_name'],
					// 	'uid' => $header['uid']
					// ] ) );

					// run synch
					// $job_->run();

					// *** this will run asynch, which is not
					// what we want, since the $lastKnowMessageUid
					// won't be reliable
					// $jobs[] = $job_;

					// *** alternatively use the following
					$importMessage = new ImportMessage( $user, array_merge( $params, [
						// 'job' => 'retrieve-message',
						'folder' => $folder['folder'],
						'folder_name' => $folder['folder_name'],
						'uid' => $header['uid']
					] ), $errors );

					$importMessage->doImport();

					// *** important !!
					// @see JobRunner -> doExecuteJob
					DeferredUpdates::doUpdates();
				}
			}
		}

		if ( !empty( $params['fetch_folder_status'] ) || $params['fetch'] === 'UIDs incremental' ) {
			$jsonData_ = [
				$GLOBALS['wgContactManagerSchemasMailboxFolders'] => $foldersData
			];
			\VisualData::updateCreateSchemas( $user, $title, $jsonData_ );
		}

		self::pushJobs( $jobs );
	}

	/**
	 * @param Output $output
	 * @param string $str
	 * @return string
	 */
	public static function parseWikitext( $output, $str ) {
		// return $this->parser->recursiveTagParseFully( $str );
		return Parser::stripOuterParagraph( $output->parseAsContent( $str ) );
	}

	/**
	 * @param array $properties
	 * @param string $formula
	 * @param string $prefix
	 * @return string
	 */
	public static function replaceFormula( $properties, $formula, $prefix ) {
		preg_match_all( '/<\s*([^<>]+)\s*>/', $formula, $matches, PREG_PATTERN_ORDER );
		foreach ( $properties as $property => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			if ( in_array( "{$prefix}/{$property}", $matches[1] ) ) {
				$formula = preg_replace( '/\<\s*' . preg_quote( "{$prefix}/{$property}", '/' )
					. '\s*\>/', $value, $formula );
			}
		}

		return $formula;
	}

	/**
	 * @param User $user
	 * @param Context $context
	 * @param string $name
	 * @param string $email
	 * @param array $categories
	 */
	public static function saveContact( $user, $context, $name, $email, $categories ) {
		if ( empty( $name ) ) {
			$name = substr( $email, 0, strpos( $email, '@' ) );
		}

		$schema = $GLOBALS['wgContactManagerSchemasContact'];
		$options = [
			'main-slot' => true,
			'limit' => INF,
			'category-field' => 'categories'
		];
		$importer = new VisualDataImporter( $user, $context, $schema, $options );

		$iconicParser = new IconicParser;
		$parsedName = $iconicParser->parse( $name );
		$fullName = $parsedName->getFullname();

		$schema = $GLOBALS['wgContactManagerSchemasContact'];
		$query = '[[full_name::' . $fullName . ']]';
		$results = \VisualData::getQueryResults( $schema, $query );

		// @TODO merge additional email address if
		// different from existing
		if ( count( $results ) ) {
			return;
		}

		$pagenameFormula = str_replace( '$1', $fullName, $GLOBALS['wgContactManagerContactsArticle'] );

		$data = [
			'first_name' => $parsedName->getFirstname(),
			'last_name' => $parsedName->getLastname(),
			'salutation' => $parsedName->getSalutation(),
			'middle_name' => $parsedName->getMiddlename(),
			'nickname' => $parsedName->getNickname(),
			'initials' => $parsedName->getInitials(),
			'suffix' => $parsedName->getSuffix(),
			'full_name' => $parsedName->getFullname(),
			'email_addresses' => [
				$email
			]
		];

		$showMsg = static function ( $msg ) {
			echo $msg . PHP_EOL;
		};

		$data['categories'] = $categories;

		$importer->importData( $pagenameFormula, $data, $showMsg );
	}

	/**
	 * @return string
	 */
	public static function getAttachmentsFolder() {
		return ( !empty( $GLOBALS['wgContactManagerAttachmentsFolder'] )
			? $GLOBALS['wgContactManagerAttachmentsFolder']
			: MW_INSTALL_PATH . '/ContactManager' );
	}

	/**
	 * @param array $data
	 * @param array &$errors []
	 * @return array
	 */
	public static function retrieveContacts( $data, &$errors ) {
		// @TODO
		// retrieve only contacts
	}

	/**
	 * @param string|null $mailboxName
	 * @return array
	 */
	public static function getMailboxes( $mailboxName = null ) {
		$schema = $GLOBALS['wgContactManagerSchemasMailbox'];
		$query = '[[name::' . ( $mailboxName ?? '+' ) . ']]';
		$results = \VisualData::getQueryResults( $schema, $query );

		if ( !$mailboxName ) {
			return $results;
		}

		foreach ( $results as $value ) {
			if ( $value['data']['name'] === $mailboxName ) {
				return $value['data'];
			}
		}

		return null;
	}
}
