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
use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;
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

		$title = TitleClass::newFromText( $targetTitle );
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

		$title = TitleClass::newFromText( $targetTitle );
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

		$title = TitleClass::newFromID( $params['pageid'] );
		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$output = $context->getOutput();
		$output->setTitle( $title );

		$folders = $params['folders'];
		$jobs = [];

		$showMsg = static function ( $msg ) {
			echo $msg . PHP_EOL;
		};

		$fetchUIDsIncremental = static function () use ( $folders ) {
			foreach ( $folders as $folder ) {
				if ( strtolower( $folder['fetch'] ) === 'uids incremental' ) {
					return true;
				}
			}
			return false;
		};

		$foldersData = [];

		// get folders data, required to check
		// for uidvalidity
		if ( !empty( $params['fetch_folder_status'] ) || $fetchUIDsIncremental() ) {
			$schema_ = $GLOBALS['wgContactManagerSchemasMailboxFolders'];
			$query_ = '[[name::' . $params['mailbox'] . ']]';
			$results_ = \VisualData::getQueryResults( $schema_, $query_ );

			if ( empty( $results_ ) ) {
				$errors[] = 'mailbox folders haven\'t yet been retrieved';
				return false;
			}

			$foldersData = $results_[0]['data'];
		}

		$switchMailbox = static function ( $folder ) use( $imapMailbox ) {
			$name_pos = strpos( $folder['folder'], '}' );
			$shortpath = substr( $folder['folder'], $name_pos + 1 );
			$imapMailbox->switchMailbox( $shortpath );
			return $shortpath;
		};

		$determineFetchQuery = static function ( $folder = [] ) use ( $imapMailbox, $params ) {
			switch ( strtolower( $folder['fetch'] ) ) {
				case 'search':
					$criteria = [];
					foreach ( $folder['search_criteria'] as $key => $value ) {
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

					$UIDs = $imapMailbox->searchMailbox( implode( ' ', $criteria ) );
					$UIDs = array_values( $UIDs );
					$len_ = count( $UIDs );
					if ( $len_ === $UIDs[$len_ - 1] - $UIDs[0] ) {
						$UIDs = $UIDs[0] . ':' . $UIDs[$len_ - 1];
					} else {
						$UIDs = implode( ',', $UIDs );
					}
					return [
						$UIDs,
						$UIDs
					];

				case 'uids greater than or equal':
					if ( $folder['UID_from'] < 1 ) {
						$folder['UID_from'] = 1;
					}
					return [
						$folder['UID_from'] . ':' . $folder['mailboxStatus']['messages'],
						$folder['UID_from'] . ':' . $folder['mailboxStatus']['messages']
					];

				case 'uids less than or equal':
					return [
						'1:' . $folder['UID_to'],
						'1:' . $folder['UID_to']
					];
				case 'uids range':
					if ( $folder['UID_from'] < 1 ) {
						$folder['UID_from'] = 1;
					}
					return [
						$folder['UID_from'] . ':' . $folder['UID_to'],
						$folder['UID_from'] . ':' . $folder['UID_to']
					];

				case 'uids incremental':
				default:
					// get latest knows UID for this folder
					$schema_ = $GLOBALS['wgContactManagerSchemasMessageHeader'];
					// phpcs:ignore Generic.Files.LineLength.TooLong
					$query_ = '[[uid::+]][[ContactManager:Mailboxes/' . $params['mailbox'] . '/headers/' . $folder['folder_name'] . '/~]]';
					$printouts_ = [ 'uid' ];
					$options_ = [ 'limit' => 1, 'order' => 'uid DESC' ];
					$results_ = \VisualData::getQueryResults( $schema_, $query_, $printouts_, $options_ );

					$lastKnowHeaderUid = ( !empty( $results_[0] ) && !empty( $results_[0]['data']['uid'] ) ?
						$results_[0]['data']['uid'] : 0 );

					$UIDsMessageSequence = '';
					if ( !empty( $folder['fetch_message'] ) ) {
						$targetTitle_ = self::replaceParameter( 'ContactManagerMessagePagenameFormula',
							$params['mailbox'],
							$folder['folder_name'],
							'~'
						);
						$schema_ = $GLOBALS['wgContactManagerSchemasIncomingMail'];
						$query_ = "[[id::+]][[$targetTitle_]]";
						$printouts_ = [ 'id' ];
						$options_ = [ 'limit' => 1, 'order' => 'id DESC' ];
						$results_ = \VisualData::getQueryResults( $schema_, $query_, $printouts_, $options_ );

						$lastKnowMessageUid = ( !empty( $results_[0] ) && !empty( $results_[0]['data']['id'] ) ?
							$results_[0]['data']['id'] : 0 );

						$UIDsMessageSequence = ( $lastKnowMessageUid + 1 ) . ':' . $folder['mailboxStatus']['messages'];
					}

					return [
						( $lastKnowHeaderUid + 1 ) . ':' . $folder['mailboxStatus']['messages'],
						$UIDsMessageSequence
					];
			}
		};

		// determine fetch query
		foreach ( $folders as $key => $folder ) {
			$folders[$key]['shortpath'] = $switchMailbox( $folder );
			$folders[$key]['mailboxStatus'] = (array)$imapMailbox->statusMailbox( $errors );
			$folders[$key]['fetchQuery'] = $determineFetchQuery( $folders[$key] );
		}

		// retrieve first all headers and record status
		foreach ( $folders as $folder ) {
			$shortpath = $switchMailbox( $folder );
			[ $headersQuery, $messagesQuery ] = $folder['fetchQuery'];

			if ( !empty( $params['fetch_folder_status'] ) || $folder['fetch'] === 'UIDs incremental' ) {

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

				if ( !empty( $folder['mailboxStatus']['uidvalidity'] )
					&& (int)$folder['mailboxStatus']['uidvalidity'] !== $folder['mailboxStatus']['uidvalidity']
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
					$folder['mailboxStatus'] );
			// if ( !empty( $params['fetch_folder_status'] ) ) {
			}

			$overviewHeader = $imapMailbox->fetch_overview( $headersQuery );
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
		}

		if ( !empty( $params['fetch_folder_status'] ) || $folder['fetch'] === 'UIDs incremental' ) {
			$jsonData_ = [
				$GLOBALS['wgContactManagerSchemasMailboxFolders'] => $foldersData
			];
			\VisualData::updateCreateSchemas( $user, $title, $jsonData_ );
		}

		// then retrieve all messages
		foreach ( $folders as $folder ) {
			$shortpath = $switchMailbox( $folder );
			[ $headersQuery, $messagesQuery ] = $folder['fetchQuery'];

			if ( !empty( $folder['fetch_message'] ) ) {
				$overviewMessage = ( $headersQuery === $messagesQuery
					 ? $overviewHeader
					 : $imapMailbox->fetch_overview( $messagesQuery )
				);

				foreach ( $overviewMessage as $header ) {
					$header = (array)$header;

					$importMessage = new ImportMessage( $user, array_merge( $params, [
						'folder' => $folder,
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
	 * @param string $parameter
	 * @param mixed ...$argv
	 * @return string
	 */
	public static function replaceParameter( $parameter, ...$argv ) {
		if ( empty( $GLOBALS["wg$parameter"] ) ) {
			return '';
		}
		$n = 1;
		$ret = $GLOBALS["wg$parameter"];
		foreach ( $argv as $value ) {
			$ret = str_replace( '$' . $n, $value, $ret );
			$n++;
		}

		return $ret;
	}

	/**
	 * @param array $properties
	 * @param string $formula
	 * @param string $prefix
	 * @return string
	 */
	public static function replaceFormula( $properties, $formula, $prefix ) {
		// @see https://phabricator.wikimedia.org/T385935
		preg_match_all( '/<\s*+([^<>]++)\s*+>/', $formula, $matches, PREG_PATTERN_ORDER );
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
	 * @param array $params
	 * @param array $obj
	 * @param string $name
	 * @param string $email
	 * @param string|null $conversationHash
	 * @param string|null $detectedLanguage
	 */
	public static function saveContact( $user, $context, $params, $obj, $name, $email,
		$conversationHash = null, $detectedLanguage = null
	) {
		$fromUsername = false;

		if ( empty( $name ) ) {
			$fromUsername = true;
			$name = substr( $email, 0, strpos( $email, '@' ) );
			$name = str_replace( '.', ' ', $name );
			$name = ucwords( $name );
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
		$query = '[[email::' . $email . ']]';
		$results = \VisualData::getQueryResults( $schema, $query );

		$pagenameFormula = self::replaceParameter( 'ContactManagerContactPagenameFormula',
			$params['mailbox'],
			'#count'
		);

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
			'email' => [
				$email
			],
			'phone' => [
			],
			'links' => [
			],
			'picture' => '',
			'language' => [
				// can be null
				$detectedLanguage
			],
			'seen_since' => null,
			'seen_until' => null,
			'conversations' => [
				// can be null
				$conversationHash
			]
		];

		// merge previous entries
		if ( !array_key_exists( 'errors', $results ) && count( $results ) ) {
			$pagenameFormula = $results[0]['title'];
			if ( !empty( $results[0]['data'] ) && !$fromUsername ) {
				$data = \VisualData::array_merge_recursive( $data, $results[0]['data'] );
			}
		}

		$data = \VisualData::array_filter_recursive( $data, 'array_unique' );

		$seenUntil = ( !empty( $data['seen_until'] ) ? strtotime( $data['seen_until'] ) : 0 );
		$seenSince = ( !empty( $data['seen_since'] ) ? strtotime( $data['seen_since'] ) : PHP_INT_MAX );

		$messageDateTime = strtotime( $obj['date'] );
		if ( $messageDateTime > $seenUntil ) {
			$data['seen_until'] = date( 'Y-m-d', $messageDateTime );
		}

		if ( $messageDateTime < $seenSince ) {
			$data['seen_since'] = date( 'Y-m-d', $messageDateTime );
		}

		$showMsg = static function ( $msg ) {
			echo $msg . PHP_EOL;
		};

		// $data['categories'] = $obj['categories'];
		$importer->importData( $pagenameFormula, $data, $showMsg );
	}

	/**
	 * @return string
	 */
	public static function getAttachmentsFolder() {
		return ( !empty( $GLOBALS['wgContactManagerAttachmentsFolder'] )
			? $GLOBALS['wgContactManagerAttachmentsFolder']
			: MW_INSTALL_PATH . '/ContactManagerFiles' );
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

	/**
	 * @param string $category
	 * @param int|false $limit
	 * @return array
	 */
	public static function articlesInCategories( $category, $limit = false ) {
		// @ATTENTION !! use instead
		// $cat = MediaWiki\Category\Category::newFromName( $value );
		// $iterator_ = $cat->getMembers( $limit );
		if ( empty( $limit ) ) {
			$options['limit'] = 50;
		}
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select( 'categorylinks',
			[ 'pageid' => 'cl_from' ],
			[ 'cl_to' => str_replace( ' ', '_', $category ) ],
			__METHOD__,
			$options
		);
		$ret = [];
		foreach ( $res as $row ) {
			$title_ = TitleClass::newFromID( $row->pageid );
			if ( $title_ ) {
				$ret[] = $title_;
			}
		}
		return $ret;
	}

}
