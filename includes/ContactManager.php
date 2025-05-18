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

	public const JOB_START = 1;
	public const JOB_END = 2;
	public const JOB_LAST_STATUS = 3;

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

		$targetTitle = str_replace( '$1', $mailboxName, $GLOBALS['wgContactManagerMailboxArticle'] );

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

		self::setRunningJob( $user, $GLOBALS['wgContactManagerSchemasJobMailboxInfo'], self::JOB_END, $mailboxName );
	}

	/**
	 * @param User $user
	 * @param string $schema
	 * @param int $status
	 * @param string|null $mailbox null
	 */
	public static function setRunningJob( $user, $schema, $status, $mailbox = null ) {
		$targetTitle = ( $mailbox
			? str_replace( '$1', $mailbox, $GLOBALS['wgContactManagerMailboxArticleJobs'] )
			: $GLOBALS['wgContactManagerMainJobsArticle'] );

		switch ( $status ) {
			case self::JOB_START:
				$arr = [
					'is_running' => true,
					'start_date' => date( 'Y-m-d H:i:s' ),
					'end_date' => null,
				];
				break;

			case self::JOB_END:
				$arr = [
					'is_running' => false,
					'end_date' => date( 'Y-m-d H:i:s' ),
				];
				break;

			case self::JOB_LAST_STATUS:
				$arr = [
					'last_status' => date( 'Y-m-d H:i:s' ),
				];
				break;
		}

		$title_ = TitleClass::newFromText( $targetTitle );
		$context = RequestContext::getMain();
		$context->setTitle( $title_ );

		$jsonData = [
			$schema => $arr
		];

		\VisualData::updateCreateSchemas( $user, $title_, $jsonData, 'jsondata' );
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

		$targetTitle = str_replace( '$1', $mailboxName, $GLOBALS['wgContactManagerMailboxArticle'] );

		$jsonData = [
			$GLOBALS['wgContactManagerSchemasMailboxFolders'] => [
				'name' => $mailboxName,
				'folders' => $res
			]
		];

		$title = TitleClass::newFromText( $targetTitle );
		\VisualData::updateCreateSchemas( $user, $title, $jsonData, 'jsondata' );

		self::setRunningJob( $user, $GLOBALS['wgContactManagerSchemasJobGetFolders'], self::JOB_END, $mailboxName );
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

		$memoryLimit = ini_get( 'memory_limit' );
		echo 'memory_limit: ' . $memoryLimit . PHP_EOL;

		// @see RunJobs -> memoryLimit
		if ( $memoryLimit === '150M' ) {
			echo '***attention, use parameter --memory-limit default' . PHP_EOL;
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
			$foldersTitle = TitleClass::newFromText( $results_[0]['title'] );
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

			self::setRunningJob( $user, $GLOBALS['wgContactManagerSchemasJobRetrieveMessages'], self::JOB_LAST_STATUS, $params['mailbox'] );

			// retrieve all headers in this folder
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

			self::setRunningJob( $user, $GLOBALS['wgContactManagerSchemasJobRetrieveMessages'], self::JOB_LAST_STATUS, $params['mailbox'] );

			// then retrieve all messages
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

					self::setRunningJob( $user, $GLOBALS['wgContactManagerSchemasJobRetrieveMessages'], self::JOB_LAST_STATUS, $params['mailbox'] );

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

		if ( !empty( $params['fetch_folder_status'] ) || $folder['fetch'] === 'UIDs incremental' ) {
			$jsonData_ = [
				$GLOBALS['wgContactManagerSchemasMailboxFolders'] => $foldersData
			];
			\VisualData::updateCreateSchemas( $user, $foldersTitle, $jsonData_ );
		}

		// self::pushJobs( $jobs );

		self::setRunningJob( $user, $GLOBALS['wgContactManagerSchemasJobRetrieveMessages'], self::JOB_END, $params['mailbox'] );
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
		if ( empty( $email ) ) {
			echo '*** error, no email' . PHP_EOL;
			print_r( $params );
			print_r( $obj );
			return;
		}

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

		$dataOriginal = [];

		// merge previous entries
		if ( !array_key_exists( 'errors', $results ) && count( $results ) ) {
			$pagenameFormula = $results[0]['title'];
			if ( !empty( $results[0]['data'] ) ) {
				$dataOriginal = $results[0]['data'];
				if ( !$fromUsername ) {
					$data = \VisualData::array_merge_recursive( $data, $dataOriginal );

				} else {
					$data['seen_until'] = $dataOriginal['seen_until'];
					$data['seen_since'] = $dataOriginal['seen_since'];
					$data = \VisualData::array_merge_recursive( $dataOriginal, $data );
				}
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

		if ( $data === $dataOriginal ) {
			return;
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
	 * @param User $user
	 * @param array &$output
	 * @param bool $delete false
	 * @return bool
	 */
	public static function deleteOldRevisions( $user, &$output, $delete = false ) {
		$dbr = \VisualData::getDB( DB_REPLICA );
		$dbw = \VisualData::getDB( DB_PRIMARY );

		$method = ( $delete ? 'delete' : 'select' );

		$callFunction = static function ( $tables, $fields, $conds, $options, $joins ) use ( $dbw, $method, $delete ) {
			$args = [
				$tables,
				$fields,
				$conds,
				// phpcs:ignore MediaWiki.Usage.MagicConstantClosure.FoundConstantMethod
				__METHOD__,
				$options,
				$joins
			];

			if ( $delete ) {
				unset( $args[1] );
			}

			$ret = call_user_func_array( [ $dbw, $method ], $args );

			if ( $method === 'selectSQLText' ) {
				echo $ret;
				return;
			}

			return $ret;
		};

		// get user id of maintenance script
		$tableName = 'user';
		$conds = [];
		$conds['user_name'] = 'Maintenance script';
		$user_id = $dbr->selectField(
			$tableName,
			'user_id',
			$conds,
			__METHOD__,
			[ 'LIMIT' => 1 ]
		);

		// get actor id of maintenance script
		$tableName = 'actor';
		$conds = [];
		$conds['actor_user'] = $user_id;
		$actor_id = $dbr->selectField(
			$tableName,
			'actor_id',
			$conds,
			__METHOD__,
			[ 'LIMIT' => 1 ]
		);

		// get/delete all non-current slots created by
		// maintenance script in NS_CONTACTMANAGER namespace
		$tables = [ 's' => 'slots', 'rev' => 'revision', 'p' => 'page' ];
		$fields = [ 'rev.rev_id', 'rev.rev_page' ];
		$joins = [];
		$joins['rev'] = [ 'JOIN', 's.slot_revision_id=rev.rev_id' ];
		$joins['p'] = [ 'JOIN', 'p.page_id=rev.rev_page' ];

		$conds = [];
		$conds['p.page_namespace'] = NS_CONTACTMANAGER;
		$conds['rev.rev_actor'] = $actor_id;
		$options = [];

		$tables_ = [ 's2' => 'slots', 'rev2' => 'revision' ];
		$fields_ = [ 1 ];
		$conds_ = [];
		$conds_[] = 's2.slot_role_id = s.slot_role_id';
		$conds_[] = 'rev2.rev_page = rev.rev_page';
		$conds_[] = 's2.slot_revision_id > s.slot_revision_id';
		$options_ = [];
		$joins_ = [];
		$joins_['rev2'] = [ 'JOIN', 'rev2.rev_id=s2.slot_revision_id' ];
		$conds[] = 'EXISTS(' . $dbr->buildSelectSubquery(
			$tables_,
			$fields_,
			$conds_,
			__METHOD__,
			$options_,
			$joins_
		) . ')';

		$res = $callFunction( $tables, $fields, $conds, $options, $joins );

		if ( !$delete && is_object( $res ) ) {
			$output[] = 'found ' . $res->numRows() . ' old slots';
		}

		// delete orphaned revisions
		$tables = [ 'rev' => 'revision', 'p' => 'page', 's' => 'slots' ];
		$fields = [ 'rev.rev_page', 'rev.rev_id' ];
		$joins = [];
		$joins['p'] = [ 'JOIN', 'rev.rev_page=p.page_id' ];
		$joins['s'] = [ 'LEFT JOIN', 's.slot_revision_id = rev.rev_id' ];

		$conds = [];
		$conds[] = 's.slot_revision_id IS NULL';
		$conds['rev.rev_actor'] = $actor_id;
		$conds['p.page_namespace'] = NS_CONTACTMANAGER;
		$options = [];

		$args = [
			$tables,
			$fields,
			$conds,
			__METHOD__,
			$options,
			$joins
		];

		$res = $callFunction( $tables, $fields, $conds, $options, $joins );

		if ( !$delete && is_object( $res ) ) {
			$output[] = 'found ' . $res->numRows() . ' orphaned revisions';
		}

		// delete orphaned content rows
		$tables = [ 'c' => 'content' ];
		$fields = [ 'c.content_id' ];
		$joins = [];
		$conds = [];

		$tables_ = [ 's' => 'slots' ];
		$fields_ = [ 1 ];
		$conds_ = [];
		$conds_[] = 's.slot_content_id = c.content_id';
		$options_ = [];
		$joins_ = [];
		$conds[] = 'NOT EXISTS(' . $dbr->buildSelectSubquery(
			$tables_,
			$fields_,
			$conds_,
			__METHOD__,
			$options_,
			$joins_
		) . ')';

		$options = [];

		$res = $callFunction( $tables, $fields, $conds, $options, $joins );

		if ( !$delete && is_object( $res ) ) {
			$output[] = 'found ' . $res->numRows() . ' orphaned content rows';
		}

		// delete orphaned ip_changes
		$tables = [ 'ipc' => 'ip_changes', 'rev' => 'revision' ];
		$fields = [ 'ipc_rev_id' ];
		$joins = [];
		$joins['rev'] = [ 'LEFT JOIN', 'rev.rev_id = ipc.ipc_rev_id' ];
		$conds = [];
		$conds[] = 'rev.rev_id IS NULL';
		$options = [];

		$res = $callFunction( $tables, $fields, $conds, $options, $joins );

		if ( !$delete && is_object( $res ) ) {
			$output[] = 'found ' . $res->numRows() . ' orphaned ip_changes';
		}

		if ( $delete ) {
			$output[] = 'Done';
		}

		self::setRunningJob( $user, $GLOBALS['wgContactManagerSchemasJobGetFolders'], self::JOB_END );

		return $delete;
	}

}
