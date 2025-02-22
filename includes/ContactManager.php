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
 * @copyright Copyright ©2023-2024, https://wikisphere.org
 */

use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;
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
	// @see Symfony\Component\Mime\Address
	private const FROM_STRING_PATTERN = '~(?<displayName>[^<]*)<(?<addrSpec>.*)>[^>]*~';

	public static function initialize() {
	}

	/**
	 * @param Parser $parser
	 * @param mixed ...$argv
	 * @return array
	 */
	public static function parserFunctionShadowRoot( Parser $parser, ...$argv ) {
		$parserOutput = $parser->getOutput();
		$str = base64_decode( $argv[0], true );

		if ( $str === false ) {
			$errorMessage = 'must be base64 encoded';
			return [
				// . $spinner
				'<div style="color:red;font-weight:bold">' . $errorMessage . '</div>',
				'noparse' => true,
				'isHTML' => true
			];
		}

		$ret = '<div id="' . $argv[1] . '"><template shadowrootmode="open">'
			. $str . '</template></div>';

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
		$imapMailbox->setAttachmentsIgnore( true );

		if ( strtolower( $params['fetch'] ) === 'search' ) {
			$criteria = [];
			foreach ( $params['search_criteria'] as $key => $value ) {
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

			switch ( strtolower( $params['fetch'] ) ) {
				case 'search':
					$UIDs = $imapMailbox->searchMailbox( implode( ' ', $criteria ) );
					$UIDs = array_values( $UIDs );
					$len_ = count( $UIDs );
					if ( $len_ === $UIDs[$len_ - 1] - $UIDs[0] ) {
						$UIDs = $UIDs[0] . ':' . $UIDs[$len_ - 1];
					} else {
						$UIDs = implode( ',', $UIDs );
					}
					$UIDsHeaderSequence = $UIDsMessageSequence = $UIDs;
					break;
				case 'uids greater than or equal':
					if ( $params['UID_from'] < 1 ) {
						$params['UID_from'] = 1;
					}
					$UIDsHeaderSequence = $UIDsMessageSequence = $params['UID_from'] . ':' . $status_['messages'];
					break;
				case 'uids less than or equal':
					$UIDsHeaderSequence = $UIDsMessageSequence = '1:' . $params['UID_to'];
					break;
				case 'uids range':
					if ( $params['UID_from'] < 1 ) {
						$params['UID_from'] = 1;
					}
					$UIDsHeaderSequence = $UIDsMessageSequence = $params['UID_from'] . ':' . $params['UID_to'];
					break;
				default:
				case 'uids incremental':
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

			$overviewHeader = $imapMailbox->fetch_overview( $UIDsHeaderSequence );
			foreach ( $overviewHeader as $header ) {
				$header = (array)$header;

				// *** an unquoted comma in the name is safe,
				// since only 1 recipient is captured by
				// imap_fetch_overview
				$header['from'] = preg_replace( '/(["\'])(.*?)\1/', '$2', str_replace( '\\', '', $header['from'] ) );

				if ( !empty( $header['to'] ) ) {
					$header['to'] = preg_replace( '/(["\'])(.*?)\1/', '$2', str_replace( '\\', '', $header['to'] ) );
				} else {
					$header['to'] = '';
				}

				$recordHeader = new RecordHeader( $user, array_merge( $params, [
					'folder' => $folder,
					'obj' => $header
				] ), $errors );

				$recordHeader->doImport();

				// *** alternatively use
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
			}

			if ( !empty( $params['fetch_message'] ) ) {
				$overviewMessage = ( $UIDsMessageSequence === $UIDsHeaderSequence
					 ? $overviewHeader
					 : $imapMailbox->fetch_overview( $UIDsMessageSequence )
				);

				foreach ( $overviewMessage as $header ) {
					$header = (array)$header;

					$importMessage = new ImportMessage( $user, array_merge( $params, [
						// 'job' => 'retrieve-message',
						'folder' => $folder['folder'],
						'folder_name' => $folder['folder_name'],
						'folder_type' => $folder['folder_type'],
						'uid' => $header['uid']
					] ), $errors );

					$importMessage->doImport();

					// *** alternatively use
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
	 * @see Symfony\Component\Mime\Address
	 * @param string $address
	 * @return array|false
	 */
	public static function parseRecipient( $address ) {
		$return = static function ( $address, $name = '' ) {
			$validator = new EmailValidator();
			return ( $validator->isValid( $address, new RFCValidation() ) ?
				[ $name, $address ] : false );
		};

		if ( strpos( $address, '<' ) === false ) {
			return $return( $address );
		}

		if ( !preg_match( self::FROM_STRING_PATTERN, $address, $matches ) ) {
			return false;
		}

		return $return( $matches['addrSpec'], trim( $matches['displayName'], ' \'"' ) );
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
	 * @param TheIconic\NameParser\Name $parsedName
	 */
	private static function fixIconicParserErrors( $parsedName ) {
		// @see TheIconic\NameParser\Part\AbstractPart
		$camelcaseReplace = static function ( $matches ) {
			if ( function_exists( 'mb_convert_case' ) ) {
				return mb_convert_case( $matches[0], MB_CASE_TITLE, 'UTF-8' );
			}
			return ucfirst( strtolower( $matches[0] ) );
		};

		// @see TheIconic\NameParser\Part\AbstractPart
		$isValid = static function ( $word ) use ( &$camelcaseReplace ) {
			if ( preg_match( '/\p{L}(\p{Lu}*\p{Ll}\p{Ll}*\p{Lu}|\p{Ll}*\p{Lu}\p{Lu}*\p{Ll})\p{L}*/u', $word ) ) {
				return $word;
			}
			return preg_replace_callback( '/[\p{L}0-9]+/ui', $camelcaseReplace, $word );
		};

		$parts = $parsedName->getParts();

		// remove invalid parts
		$parts_ = [];
		foreach ( $parts as $part ) {
			// *** when negative, could be a bug of the Iconic library
			if ( $part instanceof \TheIconic\NameParser\Part\AbstractPart ) {
				if ( $isValid( $part->getValue() ) ) {
					$parts_[] = $part;
				}
			}
		}
		$parsedName->setParts( $parts_ );
	}

	/**
	 * @param User $user
	 * @param Context $context
	 * @param string $name
	 * @param string $email
	 * @param array $categories
	 * @param string|null $detectedLanguage
	 */
	public static function saveContact( $user, $context, $name, $email, $categories, $detectedLanguage = null ) {
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
		self::fixIconicParserErrors( $parsedName );

		$fullName = trim( $parsedName->getFullname() );
		// if ( empty( $fullName ) ) {
		if ( empty( $parsedName->getGivenName() ) || empty( $parsedName->getLastname() ) ) {
			$fullName = implode( ' ', $parsedName->getAll( false ) );
		}

		$schema = $GLOBALS['wgContactManagerSchemasContact'];
		$query = '[[full_name::' . $fullName . ']]';
		$results = \VisualData::getQueryResults( $schema, $query );

		$pagenameFormula = str_replace( '$1', $fullName, $GLOBALS['wgContactManagerContactsArticle'] );

		// must reflect VisualDataSchema:ContactManager/Contact
		$data = [
			'first_name' => $parsedName->getFirstname(),
			'last_name' => $parsedName->getLastname(),
			'salutation' => $parsedName->getSalutation(),
			'middle_name' => $parsedName->getMiddlename(),
			'nickname' => $parsedName->getNickname(),
			'initials' => $parsedName->getInitials(),
			'suffix' => $parsedName->getSuffix(),
			'full_name' => $fullName,
			'email_addresses' => [
				$email
			],
			'phone_numbers' => [
			],
			'links' => [
			],
			'picture' => '',
			'languages' => [
				// can be null
				$detectedLanguage
			],
			'seen_since' => null,
			'seen_until' => null,
		];

		// merge previous entries
		if ( !array_key_exists( 'errors', $results ) && count( $results ) ) {
			if ( !empty( $results[0]['data'] ) ) {
				$data = \VisualData::array_merge_recursive( $data, $results[0]['data'] );
			}
		}

		$data = \VisualData::array_filter_recursive( $data, 'array_unique' );

		$seenUntil = ( !empty( $data['seen_until'] ) ? strtotime( $data['seen_until'] ) : 0 );
		$seenSince = ( !empty( $data['seen_since'] ) ? strtotime( $data['seen_since'] ) : PHP_INT_MAX );

		$currentTime = time();
		if ( $currentTime > $seenUntil ) {
			$data['seen_until'] = date( 'Y-m-d' );
		}

		if ( $currentTime < $seenSince ) {
			$data['seen_since'] = date( 'Y-m-d' );
		}

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
