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
 * @copyright Copyright ©2023-2025, https://wikisphere.org
 */

use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;
use MediaWiki\Extension\ContactManager\Aliases\DerivativeRequest as DerivativeRequestClass;
use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;
use MediaWiki\Extension\ContactManager\ImportMessage;
use MediaWiki\Extension\ContactManager\Mailbox;
use MediaWiki\Extension\ContactManager\Mailer;
use MediaWiki\Extension\ContactManager\RecordOverview;
use MediaWiki\Extension\VisualData\Importer as VisualDataImporter;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use TheIconic\NameParser\Parser as IconicParser;

if ( is_readable( __DIR__ . '/../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../vendor/autoload.php';
}

class ContactManager {

	/** @var LoggerFactory */
	public static $Logger;

	// @see Symfony\Component\Mime\Address
	private const FROM_STRING_PATTERN = '~(?<displayName>[^<]*)<(?<addrSpec>.*)>[^>]*~';

	public const JOB_START = 1;
	public const JOB_END = 2;
	public const JOB_LAST_STATUS = 3;

	public const SKIPPED_ON_ERROR = 1;
	public const SKIPPED_ON_FILTER = 2;
	public const SKIPPED_ON_EXISTING = 3;

	/** @var array */
	public static $UserAuthCache = [];

	public static function initialize() {
		self::$Logger = LoggerFactory::getInstance( 'ContactManager' );
	}

	/**
	 * @param Parser $parser
	 * @param mixed ...$argv
	 * @return array
	 */
	public static function parserFunctionShadowRoot( Parser $parser, ...$argv ) {
		$parserOutput = $parser->getOutput();
		$str = base64_decode( $argv[0], true );
		$pageId = ( $argv[2] ?? null );

		if ( $str === false ) {
			$errorMessage = 'must be base64 encoded';
			return [
				// . $spinner
				'<div style="color:red;font-weight:bold">' . $errorMessage . '</div>',
				'noparse' => true,
				'isHTML' => true
			];
		}

		$str = ( !$pageId ? $str : ImportMessage::replaceCidUrls( $str, $pageId ) );

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
	 * @param \User $user
	 * @param Title|MediaWiki\Title\Title $targetTitle
	 * @param array $jsonData
	 * @param string $freetext
	 * @param bool $isNewPage
	 * @param array &$errors
	 * @return bool
	 */
	public static function sendEmail( $user, $targetTitle, $jsonData, $freetext, $isNewPage, &$errors = [] ) {
		$data = $jsonData['schemas'][$GLOBALS['wgContactManagerSchemasComposeEmail']];
		$context = RequestContext::getMain();
		$schema = \VisualData::getSchema( $context, $GLOBALS['wgContactManagerSchemasComposeEmail'] );
		$editor = $schema['properties']['text']['wiki']['preferred-input'];

		$mailer = new Mailer( $user, $targetTitle, $data, $editor );
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
	 * @param \User $user
	 * @param string $mailboxName
	 * @param array &$errors []
	 * @return bool|array
	 */
	public static function getInfo( $user, $mailboxName, &$errors ) {
		$mailbox = new Mailbox( $mailboxName, $errors );

		if ( count( $errors ) ) {
			throw new \MWException( $errors[count( $errors ) - 1] );
		}

		$res = $mailbox->getInfo( $errors );

		$mailbox->disconnect();

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
	}

	/**
	 * @param \User $user
	 * @return array
	 */
	public static function getJobGroupCount( $user ) {
		$services = MediaWikiServices::getInstance();
		$services->getUserGroupManager()->addUserToGroup( $user, 'bureaucrat' );

		if ( method_exists( $services, 'getJobQueueGroup' ) ) {
			// MW 1.37+
			$jobQueueGroup = $services->getJobQueueGroup();
		} else {
			$jobQueueGroup = JobQueueGroup::singleton();
		}

		$jobGroup = $jobQueueGroup->get( 'ContactManagerJob' );

		$jobGroup->flushCaches();

		// @see JobQueue
		return [
				$jobGroup->getSize(),
				$jobGroup->getAcquiredCount(),
				$jobGroup->getDelayedCount()
		];
	}

	/**
	 * @return bool
	 */
	public static function isCommandLineInterface() {
		return ( defined( 'MW_ENTRY_POINT' ) && MW_ENTRY_POINT === 'cli' );
	}

	/**
	 * @param string $method
	 * @param string $message
	 * @param array $arr []
	 */
	public static function logError( $method, $message, $arr = [] ) {
		$logger = self::$Logger ?? LoggerFactory::getInstance( 'ContactManager' );
		$logger->$method( $message . ( $arr ? ' ' . print_r( $arr, true ) : '' ) );
	}

	/**
	 * @param string $jobName
	 * @param string|null $mailboxName null
	 * @return bool
	 */
	public static function jobIsRunning( $jobName, $mailboxName = null ) {
		$excludeJobs = [
			'get-message',
			'retrieve-message'
		];

		if ( in_array( $jobName, $excludeJobs ) ) {
			return false;
		}

		self::logError( 'debug', 'jobIsRunning start ' . date( 'Y-m-d H:i:s' ) );

		$schema = self::jobNameToSchema( $jobName );
		$query = '[[name::' . $jobName . ']]';
		$query .= ( $mailboxName ? '[[mailbox::' . $mailboxName . ']]' : '' );

		self::logError( 'debug', 'schema', $schema );
		self::logError( 'debug', 'query ' . $query );

		// required by self::isRunning
		$printouts = [
			'is_running',
			'start_date',
			'end_date',
			'last_status',
			'check_email_interval',
		];
		$params_ = [
		];
		$results = \VisualData::getQueryResults( $schema, $query, $printouts, $params_ );

		self::logError( 'debug', 'results', $results );

		if ( self::queryError( $results, false ) ) {
			self::logError( 'error', 'jobIsRunning query error' );
			self::logError( 'debug', name, $jobName );
			self::logError( 'debug', mailboxName, $mailboxName );
			self::logError( 'debug', results, $results );

			throw new \Exception( 'query error' );
		}

		if ( !count( $results ) ) {
			return false;
		}

		if ( empty( $results[0]['data'] ) ) {
			return false;
		}

		$ret = self::isRunning( $results[0]['data'] );
		self::logError( 'debug', 'jobIsRunning return ' . $ret );

		return $ret;
	}

	/**
	 * @param array $data
	 * @param bool $throw true
	 * @return bool
	 */
	public static function isRunning( $data, $throw = true ) {
		if ( !array_key_exists( 'is_running', $data ) ) {
			$message = 'isRunning missing data';
			self::logError( 'error', 'isRunning missing data' );
			self::logError( 'debug', 'data', $data );

			if ( $throw ) {
				throw new \Exception( $message );
			}
			return false;
		}

		if ( empty( $data['is_running'] ) ) {
			return false;
		}

		$refDate = $data['last_status'] ?? $data['start_date'] ?? null;
		$refTime = strtotime( $refDate );

		self::logError( 'debug', 'refTime ' . $refTime );

		if ( !$refTime ) {
			$message = 'isRunning error parsing date';
			self::logError( 'error', 'isRunning error parsing date' );
			self::logError( 'debug', 'refDate', $refDate );

			if ( $throw ) {
				throw new \Exception( $message );
			}
			return false;
		}

		if (
			!empty( $data['check_email_interval'] ) &&
			( time() - $refTime ) <= ( (int)$data['check_email_interval'] * 60 )
		) {
			self::logError( 'debug', 'cond 1' );
			return true;
		}

		$minutes = is_numeric( $GLOBALS['wgContactManangerConsiderJobDeadMinutes'] )
			? (int)$GLOBALS['wgContactManangerConsiderJobDeadMinutes']
			: 10;

		self::logError( 'debug', 'cond 2' );
		return ( time() - $refTime ) <= ( $minutes * 60 );
	}

	/**
	 * @param string $name
	 * @return string
	 */
	public static function jobNameToSchema( $name ) {
		$schema = '';

		switch ( $name ) {
			case 'get-message':
			case 'retrieve-message':
			case 'get-messages':
			case 'retrieve-messages':
				return $GLOBALS['wgContactManagerSchemasJobRetrieveMessages'];

			case 'get-folders':
				return $GLOBALS['wgContactManagerSchemasJobGetFolders'];

			case 'mailbox-info':
				return $GLOBALS['wgContactManagerSchemasJobMailboxInfo'];

			case 'delete-old-revisions':
				return $GLOBALS['wgContactManagerSchemasJobDeleteOldRevisions'];
		}

		return $schema;
	}

	/**
	 * @param \User $user
	 * @param string $jobName
	 * @param int $status
	 * @param string|null $mailbox null
	 */
	public static function setRunningJob( $user, $jobName, $status, $mailbox = null ) {
		// ***exclude jobs sharing the same schema
		$excludeJobs = [
			'get-message',
			'retrieve-message'
		];

		if ( in_array( $jobName, $excludeJobs ) ) {
			return;
		}

		$targetTitle = ( $mailbox
			? str_replace( '$1', $mailbox, $GLOBALS['wgContactManagerMailboxArticleJobs'] )
			: $GLOBALS['wgContactManagerMainJobsArticle'] );

		switch ( $status ) {
			case self::JOB_START:
				$arr = [
					'name' => $jobName,
					'is_running' => true,
					'start_date' => date( 'Y-m-d H:i:s' ),
					'end_date' => null,
				];
				break;

			case self::JOB_END:
				$arr = [
					'name' => $jobName,
					'is_running' => false,
					'end_date' => date( 'Y-m-d H:i:s' ),
				];
				break;

			case self::JOB_LAST_STATUS:
				$arr = [
					'name' => $jobName,
					'last_status' => date( 'Y-m-d H:i:s' ),
				];
				break;
		}

		$title_ = TitleClass::newFromText( $targetTitle );
		$context = RequestContext::getMain();
		$context->setTitle( $title_ );

		if ( $mailbox ) {
			$arr['mailbox'] = $mailbox;
		}

		$schema = self::jobNameToSchema( $jobName );
		$jsonData = [
			$schema => $arr
		];

		\VisualData::updateCreateSchemas( $user, $title_, $jsonData, 'main' );
	}

	/**
	 * @return int|bool
	 */
	public static function updateRecentChangesTable() {
		$dbw = \VisualData::getDB( DB_PRIMARY );

		// get actor_id of maintenance script
		$tableName = 'actor';
		$conds = [];
		$conds['actor_name'] = 'Maintenance script';
		$actor_id = $dbw->selectField(
			$tableName,
			'actor_id',
			$conds,
			__METHOD__,
			[ 'LIMIT' => 1 ]
		);

		if ( !$actor_id ) {
			return false;
		}

		$tableName = 'recentchanges';
		$conds = [
			'rc_actor' => $actor_id,
			'rc_namespace' => NS_CONTACTMANAGER
		];
		$update = [
			'rc_bot' => 1
		];

		return $dbw->update(
			$tableName,
			$update,
			$conds,
			__METHOD__
		);
	}

	/**
	 * @param \User $user
	 * @param string $mailboxName
	 * @param array &$errors []
	 * @return array
	 */
	public static function getFolders( $user, $mailboxName, &$errors ) {
		$mailbox = new Mailbox( $mailboxName, $errors );

		if ( count( $errors ) ) {
			throw new \MWException( $errors[count( $errors ) - 1] );
		}

		$res = $mailbox->getFolders( $errors );

		$mailbox->disconnect();

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
	}

	/**
	 * @see MediaWiki\Maintenance\Maintenance
	 * @codeCoverageIgnore
	 * @param int $seconds
	 */
	public static function countDown( $seconds ) {
		$message = "Press Ctrl-C to cancel in the next $seconds seconds... ";
		$width = strlen( $message ) + strlen( (string)$seconds );

		echo $message;
		for ( $i = $seconds; $i >= 0; $i-- ) {
			echo "\r" . str_pad( $message . $i, $width, ' ', STR_PAD_RIGHT );
			if ( $i > 0 ) {
				sleep( 1 );
			}
		}

		echo PHP_EOL;
	}

	/**
	 * @param \User $user
	 * @param array $params
	 * @param array &$errors []
	 * @return bool|null
	 */
	public static function getMessages( $user, $params, &$errors ) {
		echo 'getMessages start ' . date( 'Y-m-d H:i:s' ) . PHP_EOL;

		// this is redundant when called from ContactManagerJob
		if ( self::jobIsRunning( 'get-messages', $params['mailbox'] ) ) {
			echo '***exit, job running' . PHP_EOL;
			return;
		}

		$memoryLimit = ini_get( 'memory_limit' );
		echo 'memory_limit: ' . $memoryLimit . PHP_EOL;

		// @see RunJobs -> memoryLimit
		if ( $memoryLimit === '150M' ) {
			echo '***attention, use parameter --memory-limit default' . PHP_EOL;
		}

		// check regexes
		$isValidRegex = static function ( $pattern ) {
			set_error_handler( static function () {
			}, E_WARNING );
			$isValid = preg_match( $pattern, '' ) !== false;
			restore_error_handler();
			return $isValid;
		};

		$wrapWithDelimiter = static function ( $text ) {
			$delimiters = [ '/', '#', '~', '%', '`', '@', '!' ];

			foreach ( $delimiters as $delim ) {
				if ( strpos( $text, $delim ) === false ) {
					return "$delim$text$delim";
				}
			}

			$delim = '/';
			return $delim . str_replace( $delim, '\\' . $delim, $text ) . $delim;
		};

		foreach ( [ 'filters_by_overview', 'filters_by_message' ] as $k ) {
			if ( array_key_exists( $k, $params ) ) {
				foreach ( (array)$params[$k] as &$v ) {
					if ( $v['match'] !== 'regex' ) {
						continue;
					}
					$value = $v['value_text'];

					if ( $isValidRegex( $value ) ) {
						continue;
					}

					$regex = $wrapWithDelimiter ( $value );

					if ( $isValidRegex( $regex ) ) {
						$v['value_text'] = $regex;
						continue;
					}

					$errors[] = 'regex "' . $v['value_text'] . '" is not valid';
					echo 'regex "' . $v['value_text'] . '" is not valid' . PHP_EOL;
					return;
				}
				unset( $v );
			}
		}

		echo 'connecting to mailbox ' . $params['mailbox'] . PHP_EOL;

		// @TODO MIGRATE TO
		// https://github.com/Webklex/php-imap
		// or
		// https://github.com/DirectoryTree/ImapEngine

		$mailbox = new Mailbox( $params['mailbox'], $errors );

		if ( count( $errors ) ) {
			throw new \MWException( $errors[count( $errors ) - 1] );
		}

		$mailboxData = self::getMailboxData( $params['mailbox'] );

		if ( !$mailboxData ) {
			$errors[] = 'cannot get mailbox data';
			$mailbox->disconnect();
			return false;
		}

		$mailboxData['all_addresses'] = self::allMailboxAddresses( $mailboxData['data'] );

		$imapMailbox = $mailbox->getImapMailbox();
		$imapMailbox->setAttachmentsIgnore( true );

		$title = TitleClass::newFromID( $params['pageid'] );

		if ( !$title ) {
			$errors[] = 'title (#' . $params['pageid'] . ') is not valid';
			$mailbox->disconnect();
			return false;
		}

		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$output = $context->getOutput();
		$output->setTitle( $title );

		if ( !array_key_exists( 'folders', $params ) ) {
			$errors[] = 'undefined folders';
			$mailbox->disconnect();
			return false;
		}

		$folders = $params['folders'];
		$jobs = [];

		$showMsg = static function ( $msg ) {
			echo $msg . PHP_EOL;
		};

		$fetchUIDsIncremental = ( static function () use ( $folders ) {
			foreach ( $folders as $folder ) {
				if ( strtolower( $folder['fetch'] ) === 'uids incremental' ) {
					return true;
				}
			}
			return false;
		} )();

		$range = static function ( $start, $end ) {
			if ( $start > $end ) {
				return [];
			}
			return range( $start, $end );
		};

		$getRange = static function ( $UIDs ) {
			$len = count( $UIDs );
			if ( !$len ) {
				return '';
			}

			if ( $len === ( $UIDs[$len - 1] - $UIDs[0] + 1 ) ) {
				return $UIDs[0] . ':' . $UIDs[$len - 1];
			}

			return implode( ',', $UIDs );
		};

		$foldersData = [];

		// get folders data, required to check
		// for uidvalidity
		if ( !empty( $params['fetch_folder_status'] ) || $fetchUIDsIncremental ) {
			echo 'getting mailbox status' . PHP_EOL;

			$schema_ = $GLOBALS['wgContactManagerSchemasMailboxFolders'];
			$query_ = '[[name::' . $params['mailbox'] . ']]';
			$results_ = \VisualData::getQueryResults( $schema_, $query_ );

			if ( self::queryError( $results_, true ) ) {
				echo 'error query' . PHP_EOL;
				print_r( $results_ );
				return false;
			}

			if ( !count( $results_ ) ) {
				$errors[] = 'mailbox folders haven\'t yet been retrieved';
				$mailbox->disconnect();
				return false;
			}
			$foldersTitle = TitleClass::newFromText( $results_[0]['title'] );
			$foldersData = $results_[0]['data'];
		}

		$fetchedMessages = [];
		// alternatively store each fetched email as .eml
		// *** we cannot query the id of the last retrieved message
		// since messages could be individually fetched from
		// the read email page
		$schema_ = $GLOBALS['wgContactManagerSchemasFetchedMessages'];
		$query_ = '[[mailbox::' . $params['mailbox'] . ']]';
		$printouts_ = [ 'IDs' ];
		$options_ = [ 'limit' => 1 ];
		$results_ = \VisualData::getQueryResults( $schema_, $query_, $printouts_, $options_ );

		if ( self::queryError( $results_, false ) ) {
			echo 'error query' . PHP_EOL;
			print_r( $results_ );

		} elseif ( count( $results_ ) ) {
			$fetchedMessages = $results_[0]['data'];
		}

		$getExistingMessages = static function ( $folderName ) use ( $params, $fetchedMessages ) {
			if ( empty( $params['ignore_existing'] ) ) {
				return [];
			}

			if (
				!isset( $fetchedMessages['folders'] ) ||
				!is_array( $fetchedMessages['folders'] )
			) {
				return [];
			}

			foreach ( $fetchedMessages['folders'] as $folder ) {
				if ( $folder['name'] === $folderName ) {
					if ( isset( $folder['IDs'] ) && is_array( $folder['IDs'] ) ) {
						return array_values( $folder['IDs'] );
					}
					return [];
				}
			}

			return [];
		};

		$switchMailbox = static function ( $folder ) use( $imapMailbox ) {
			$name_pos = strpos( $folder['folder'], '}' );
			$shortpath = substr( $folder['folder'], $name_pos + 1 );
			$imapMailbox->switchMailbox( $shortpath );
			return $shortpath;
		};

		$determineOverviewQuery = static function ( $folder = [] ) use ( &$range, $imapMailbox, $params ) {
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
					return array_values( $UIDs );

				case 'uids greater than or equal':
					if ( $folder['UID_from'] < 1 ) {
						$folder['UID_from'] = 1;
					}
					return $range( $folder['UID_from'], $folder['mailboxStatus']['uidnext'] - 1 );

				case 'uids less than or equal':
					return $range( 1, $folder['UID_to'] );

				case 'uids range':
					if ( $folder['UID_from'] < 1 ) {
						$folder['UID_from'] = 1;
					}
					return $range( $folder['UID_from'], $folder['UID_to'] );

				case 'uids incremental':
				default:
					// get latest knows UID for this folder
					$schema_ = $GLOBALS['wgContactManagerSchemasMessageOverview'];
					// phpcs:ignore Generic.Files.LineLength.TooLong
					$query_ = '[[uid::+]][[ContactManager:Mailboxes/' . $params['mailbox'] . '/overview/' . $folder['folder_name'] . '/~]]';
					$printouts_ = [ 'uid' ];
					$options_ = [ 'limit' => 1, 'order' => 'uid DESC' ];
					$results_ = \VisualData::getQueryResults( $schema_, $query_, $printouts_, $options_ );

					if ( self::queryError( $results_, false ) ) {
						echo 'error query' . PHP_EOL;
						print_r( $results_ );
						// return false;
						$lastKnowOverviewUid = 0;

					} else {
						$lastKnowOverviewUid = ( !empty( $results_[0] ) && !empty( $results_[0]['data']['uid'] ) ?
							$results_[0]['data']['uid'] : 0 );
					}

					return $range( ( $lastKnowOverviewUid + 1 ), $folder['mailboxStatus']['uidnext'] - 1 );
			}
		};

		echo 'determining overview range' . PHP_EOL;

		foreach ( $folders as &$folder ) {
			$folder['shortpath'] = $switchMailbox( $folder );
			echo 'shortpath: ' . $folder['shortpath'] . PHP_EOL;

			if ( strtolower( $folder['folder_type'] ) !== 'other' &&
				strpos( mb_strtolower( $folder['shortpath'] ), strtolower( $folder['folder_type'] ) ) === false
			) {
				echo '***attention, foder name/type mismatch, ensure folder type is correct for folder "' . $folder['shortpath'] . '"';
				echo ' (current value: "' . $folder['folder_type'] . '")' . PHP_EOL;

				self::countDown( 10 );
			}

			$folder['mailboxStatus'] = (array)$imapMailbox->statusMailbox( $errors );
			echo 'mailboxStatus' . PHP_EOL;
			print_r( $folder['mailboxStatus'] );

			// get/update folder status
			if ( !empty( $params['fetch_folder_status'] ) || $folder['fetch'] === 'UIDs incremental' ) {
				$folderKey = -1;
				foreach ( $foldersData['folders'] as $folderKey => $value_ ) {
					if ( $value_['shortpath'] === $folder['shortpath'] ) {
						break;
					}
				}

				if ( $folderKey === -1 ) {
					$errors[] = 'mailbox folder (' . $folder['shortpath'] . ') hasn\'t been retrieved';
					$mailbox->disconnect();
					return false;
				}

				if ( !empty( $folder['mailboxStatus']['uidvalidity'] )
					&& (int)$folder['mailboxStatus']['uidvalidity'] !== $folder['mailboxStatus']['uidvalidity']
				) {
					$errors[] = 'mailbox\'s UIDs have been reorganized, fetch again the entire mailbox';
					$mailbox->disconnect();
					return false;
				}

				// update status
				if ( !isset( $foldersData['folders'][$folderKey]['status'] ) ||
					!is_array( $foldersData['folders'][$folderKey]['status'] )
				) {
					$foldersData['folders'][$folderKey]['status'] = [];
				}

				$foldersData['folders'][$folderKey]['status'] = array_merge(
					$foldersData['folders'][$folderKey]['status'], $folder['mailboxStatus'] );
			}

			$folder['overviewQuery'] = $determineOverviewQuery( $folder );

			if ( count( $folder['overviewQuery'] ) ) {
				$folder['overviewRange'] = $getRange( $folder['overviewQuery'] );
				echo 'overviewRange: ' . $folder['overviewRange'] . PHP_EOL;

			} else {
				echo 'no overview to retrieve' . PHP_EOL;
			}
		}
		unset( $folder );

		echo 'retrieving overview' . PHP_EOL;

		$newOverview = 0;
		$newContacts = 0;
		$fetchedOverview = [];
		$skippedByFilters = [];
		foreach ( $folders as $key => $folder ) {
			if ( !array_key_exists( 'overviewRange', $folder ) ) {
				continue;
			}

			echo 'switching folder ' . $folder['shortpath'] . PHP_EOL;

			$shortpath = $switchMailbox( $folder );
			$skippedByFilters[$shortpath] = [];

			$overviewRange = $folder['overviewRange'];

			echo 'getting folder overview: ' . $overviewRange . PHP_EOL;
			$fetchedOverview[$key] = $imapMailbox->fetch_overview( $overviewRange );

			echo 'recording job status' . PHP_EOL;
			self::setRunningJob( $user, 'retrieve-messages', self::JOB_LAST_STATUS, $params['mailbox'] );

			$n = 0;
			$i = 0;
			$size_ = count( $fetchedOverview[$key] );
			echo 'size ' . $size_ . PHP_EOL;

			// retrieve all headers in this folder
			foreach ( $fetchedOverview[$key] as $header ) {
				$n++;
				echo "importing overview $n/{$size_}" . PHP_EOL;

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

				$recordOverview = new RecordOverview( $user, $mailboxData, array_merge( $params, [
					'folder' => $folder,
					'obj' => $header
				] ), $errors );

				$res_ = $recordOverview->doImport();
				if ( is_array( $res_ ) ) {
					[ $newOverview_, $newContacts_ ] = $res_;
					$newOverview += count( $newOverview_ );
					$newContacts += count( $newContacts_ );

				} elseif ( $res_ === self::SKIPPED_ON_FILTER ) {
					$skippedByFilters[$shortpath][] = $header['uid'];
				}

				if ( !is_array( $res_ ) ) {
					continue;
				}

				if ( $i % 100 === 0 ) {
					echo 'recording job status' . PHP_EOL;
					self::setRunningJob( $user, 'retrieve-messages', self::JOB_LAST_STATUS, $params['mailbox'] );
				}

				$i++;
			}

			echo 'recording job status' . PHP_EOL;
			self::setRunningJob( $user, 'retrieve-messages', self::JOB_LAST_STATUS, $params['mailbox'] );
		}

		// @TODO show Echo notification
		echo "$newOverview new overviews" . PHP_EOL;

		echo 'retrieve messages' . PHP_EOL;

		$newMessages = 0;
		$newConversations = 0;
		// then retrieve all messages
		foreach ( $folders as $folder ) {
			if ( empty( !$folder['only_overview'] ) ) {
				continue;
			}

			echo 'switching folder ' . $folder['shortpath'] . PHP_EOL;
			$shortpath = $switchMailbox( $folder );
			$overviewQuery = $folder['overviewQuery'];

			// use overviewQuery except if $folder['fetch'] === 'UIDs incremental'
			if ( $folder['fetch'] === 'UIDs incremental' ) {
				$overviewQuery = $range( 1, $folder['mailboxStatus']['uidnext'] - 1 );
			}

			// ignore existing messages if $params['ignore_existing'] == true
			// or $fetchedMessages[$folder['folder_name']]['IDs'] is not an array
			$existingMessages = $getExistingMessages( $folder['folder_name'] );
			$IDs = array_diff( $overviewQuery, $existingMessages );
			$IDs = array_values( $IDs );

			$n = 0;
			$i = 0;
			$size_ = count( $IDs );
			echo 'size ' . $size_ . PHP_EOL;

			foreach ( $IDs as $uid ) {
				$n++;
				echo "importing message $n/{$size_}" . PHP_EOL;

				if ( array_key_exists( $shortpath, $skippedByFilters ) && in_array( $uid, $skippedByFilters[$shortpath] ) ) {
					echo $uid . ' skipped by header\'s filter' . PHP_EOL;
					continue;
				}

				$importMessage = new ImportMessage( $user, $mailbox, $mailboxData, array_merge( $params, [
					'folder' => $folder,
					'uid' => $uid
				] ), $errors );

				$res_ = $importMessage->doImport( $fetchedMessages );
				if ( is_array( $res_ ) ) {
					[ $newMessages_, $newContacts_, $newConversations_ ] = $res_;
					$newMessages += count( $newMessages_ );
					$newContacts += count( $newContacts_ );
					$newConversations += count( $newConversations_ );
				}

				if ( $i % 10 === 0 ) {
					echo 'recording job status' . PHP_EOL;
					self::setRunningJob( $user, 'retrieve-messages', self::JOB_LAST_STATUS, $params['mailbox'] );
				}

				$i++;
			}
		}

		echo "$newMessages new messages" . PHP_EOL;
		echo "$newContacts new contacts" . PHP_EOL;
		echo "$newConversations new conversations" . PHP_EOL;

		// send Echo notification
		if ( $newMessages || $newContacts || $newConversations ) {
			$services = MediaWikiServices::getInstance();
			$contactManagerEchoInterface = $services->getService( 'ContactManagerEchoInterface' );
			$title_ = TitleClass::newFromText( $mailboxData['title'] );
			$contactManagerEchoInterface->sendNotifications(
				$user,
				$title_,
				$params['mailbox'],
				[
					'messages' => $newMessages,
					'contacts' => $newContacts,
					'conversations' => $newConversations
				]
			);
		}

		if ( !empty( $foldersData ) ) {
			echo 'saving folders status' . PHP_EOL;

			$jsonData_ = [
				$GLOBALS['wgContactManagerSchemasMailboxFolders'] => $foldersData
			];
			\VisualData::updateCreateSchemas( $user, $foldersTitle, $jsonData_ );
		}

		echo 'disconnecting' . PHP_EOL;

		$mailbox->disconnect();

		return true;
	}

	/**
	 * @param \User $user
	 * @param array $params
	 * @param array &$errors []
	 * @return bool|null
	 */
	public static function getSingleMessage( $user, $params, &$errors ) {
		$mailbox = new Mailbox( $params['mailbox'], $errors );
		if ( count( $errors ) ) {
			// throw new \MWException( $errors[count( $errors ) - 1] );
			return false;
		}

		// alternatively store each fetched email as .eml
		// *** we cannot query the id of the last retrieved message
		// since messages could be individually fetched from
		// the read email page
		$fetchedMessages = [];
		$schema_ = $GLOBALS['wgContactManagerSchemasFetchedMessages'];
		$query_ = '[[mailbox::' . $params['mailbox'] . ']]';
		$printouts_ = [ 'IDs' ];
		$options_ = [ 'limit' => 1 ];
		$results_ = \VisualData::getQueryResults( $schema_, $query_, $printouts_, $options_ );

		if ( self::queryError( $results_, false ) ) {
			echo 'error query' . PHP_EOL;
			print_r( $results_ );

		} elseif ( count( $results_ ) ) {
			$fetchedMessages = $results_[0]['data'];
		}

		$mailboxData = self::getMailboxData( $params['mailbox'] );

		if ( !array_key_exists( 'folder_name', $params ) || empty( params['folder_name'] ) ) {
			echo 'empty parameter "folder_name"' . PHP_EOL;
			$errors[] = 'empty parameter "folder_name"';
			return false;
		}

		foreach ( $params['folders'] as $folder_ ) {
			if ( $folder_['folder_name'] === $params['folder_name'] ) {
				$params['folder'] = $folder_;
				break;
			}
		}

		$importMessage = new ImportMessage( $user, $mailbox, $mailboxData, $params, $errors );
		$res_ = $importMessage->doImport( $fetchedMessages );
		if ( !is_array( $res_ ) ) {
			switch ( $res_ ) {
				case self::SKIPPED_ON_ERROR:
					$errors[] = 'ContactManager: error retrieving message';
					break;
				case self::SKIPPED_ON_FILTER:
					$errors[] = 'ContactManager: skipped on filter';
					break;
				case self::SKIPPED_ON_EXISTING:
					$errors[] = 'ContactManager: skipped on existing';
					break;
			}
			return false;
		}

		return $res_;
	}

	/**
	 * @param array $mailboxData
	 * @return array
	 */
	public static function allMailboxAddresses( $mailboxData ) {
		$ret = [];
		if ( is_array( $mailboxData['from'] ) ) {
			foreach ( $mailboxData['from'] as $value ) {
				$ret_ = self::parseRecipient( $value );
				if ( $ret_ ) {
					[ $name, $address ] = $ret_;
					$ret[] = $address;
				}
			}
		}

		if ( array_key_exists( 'delivered-to', $mailboxData ) && is_array( $mailboxData['delivered-to'] ) ) {
			foreach ( $mailboxData['delivered-to'] as $address ) {
				$ret[] = $address;
			}
		}

		return array_values( array_unique( $ret ) );
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
	 * @param \User $user
	 * @param Context $context
	 * @param array $params
	 * @param array $obj
	 * @param string $name
	 * @param string $email
	 * @param array $categories
	 * @param string|null $conversationHash
	 * @param string|null $detectedLanguage
	 * @return bool|null|string
	 */
	public static function saveUpdateContact( $user, $context, $params, $obj, $name, $email,
		$categories, $conversationHash = null, $detectedLanguage = null
	) {
		if ( empty( $email ) ) {
			echo '*** error, no email' . PHP_EOL;
			print_r( $obj );
			return false;
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

		$fullName = $parsedName->getFullname();
		// if ( empty( $fullName ) ) {
		if ( empty( $parsedName->getGivenName() ) || empty( $parsedName->getLastname() ) ) {
			$fullName = implode( ' ', $parsedName->getAll( false ) );
		}

		$schema = $GLOBALS['wgContactManagerSchemasContact'];
		$targetTitle_ = self::replaceParameter( 'ContactManagerContactPagenameFormula',
			$params['mailbox'],
			'~'
		);
		$query = "[[email::$email]][[$targetTitle_]]";
		$results = \VisualData::getQueryResults( $schema, $query );

		if ( self::queryError( $results, false ) ) {
			echo 'error query' . PHP_EOL;
			print_r( $results );
		}

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
			'full_name' => trim( $fullName ),
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
			],

			'categories' => $categories
		];

		$exists = false;
		$dataOriginal = [];
		// merge previous entries
		if ( count( $results ) && !empty( $results[0]['data'] ) ) {
			$exists = true;
			$pagenameFormula = $results[0]['title'];
			$dataOriginal = $results[0]['data'];
			if ( !$fromUsername ) {
				$data = \VisualData::array_merge_recursive( $data, $dataOriginal );

			} else {
				if ( !empty( $dataOriginal['seen_until'] ) ) {
					$data['seen_until'] = $dataOriginal['seen_until'];
				}
				if ( !empty( $dataOriginal['seen_since'] ) ) {
					$data['seen_since'] = $dataOriginal['seen_since'];
				}
				$data = \VisualData::array_merge_recursive( $dataOriginal, $data );
			}
		}

		$data = \VisualData::array_filter_recursive( $data, 'array_unique' );

		$seenUntil = ( !empty( $data['seen_until'] ) ? strtotime( $data['seen_until'] ) : 0 );
		$seenSince = ( !empty( $data['seen_since'] ) ? strtotime( $data['seen_since'] ) : PHP_INT_MAX );

		$messageDateTime = strtotime( $obj['parsedDate'] ?? $obj['date'] );
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

		$ret = $importer->importData( $pagenameFormula, $data, $showMsg );

		if ( !is_array( $ret ) || !count( $ret ) ) {
			return false;
		}

		return ( !$exists ? $pagenameFormula : null );
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
	 * @param string $mailboxName
	 * @param array &$errors []
	 * @return bool|array
	 */
	public static function getMailboxData( $mailboxName, &$errors = [] ) {
		$schema = $GLOBALS['wgContactManagerSchemasMailbox'];
		$query = '[[name::' . $mailboxName . ']]';
		$results = \VisualData::getQueryResults( $schema, $query );

		if ( self::queryError( $results, true ) ) {
			return false;
		}

		if ( !count( $results ) || empty( $results[0]['data'] ) ) {
			return false;
		}

		return $results[0];
	}

	/**
	 * @param string|null $mailboxName
	 * @param array &$errors []
	 * @return array
	 */
	public static function getMailboxes( $mailboxName = null, &$errors = [] ) {
		$schema = $GLOBALS['wgContactManagerSchemasMailbox'];
		$query = '[[name::' . ( $mailboxName ?? '+' ) . ']]';
		$results = \VisualData::getQueryResults( $schema, $query );

		if ( self::queryError( $results, true ) ) {
			$errors = $results['errors'];
			return false;
		}

		if ( !$mailboxName ) {
			return $results;
		}

		foreach ( $results as $value ) {
			if ( $value['data']['name'] === $mailboxName ) {
				return $value['data'];
			}
		}

		return [];
	}

	/**
	 * @param array &$results
	 * @param bool $mustExist
	 * @return bool
	 */
	public static function queryError( &$results, $mustExist ) {
		if ( !array_key_exists( 'errors', $results ) ) {
			return false;
		}

		if ( !$mustExist && count( $results['errors'] ) === 1 &&
			$results['errors'][0] === 'schema has no data'
		) {
			unset( $results['errors'] );
			return false;
		}

		return true;
	}

	/**
	 * @param \User $user
	 * @param array $groups
	 * @param array &$errors
	 * @return array|bool
	 */
	public static function usersInGroups( $user, $groups, &$errors = [] ) {
		$context = RequestContext::getMain();
		$context->setUser( $user );

		// @see https://www.mediawiki.org/wiki/API:Allusers
		$row = [
			'action' => 'query',
			'list' => 'allusers',
			'augroup' => implode( '|', $groups ),
			'aulimit' => 500,
		];

		$req = new DerivativeRequestClass(
			$context->getRequest(),
			$row,
			true
		);

		try {
			$api = new \ApiMain( $req, true );
			$api->getContext()->setUser( $user );
			$api->execute();

		} catch ( \Exception $e ) {
			$errors[] = 'api error ' . $e->getMessage();
			self::$Logger->error( current( $errors ) );
			return false;
		}

		$res = $api->getResult()->getResultData();
		$ret = [];
		if ( !empty( $res['query']['allusers'] ) ) {
			foreach ( $res['query']['allusers'] as $value ) {
				if ( is_array( $value ) ) {
					$ret[] = $value['userid'];
				}
			}
		}
		return $ret;
	}

	/**
	 * @param \User $user
	 * @return bool
	 */
	public static function isAuthorizedGroup( $user ) {
		$cacheKey = $user->getName();
		if ( array_key_exists( $cacheKey, self::$UserAuthCache ) ) {
			return self::$UserAuthCache[$cacheKey];
		}

		$services = MediaWikiServices::getInstance();
		$userGroupManager = $services->getUserGroupManager();
		$userGroups = $userGroupManager->getUserEffectiveGroups( $user );
		$authorizedGroups = [
			'sysop',
			'bureaucrat',
			'interface-admin',
			'autoconfirmed'
		];
		self::$UserAuthCache[$cacheKey] = count( array_intersect( $authorizedGroups, $userGroups ) );
		return self::$UserAuthCache[$cacheKey];
	}

	/**
	 * @param \User $user
	 * @param Title|MediaWiki\Title\Title $title
	 * @return array
	 */
	public static function getArticleEditors( $user, $title ) {
		$context = RequestContext::getMain();
		$context->setUser( $user );

		// @see https://www.mediawiki.org/wiki/API:Contributors
		$row = [
			'action' => 'query',
			'prop' => 'contributors',
			'titles' => $title->getFullText(),
			'pclimit' => 500,
		];

		$req = new DerivativeRequestClass(
			$context->getRequest(),
			$row,
			true
		);

		try {
			$api = new ApiMain( $req, true );
			$api->getContext()->setUser( $user );
			$api->execute();

		} catch ( \Exception $e ) {
			$errors[] = 'api error ' . $e->getMessage();
			self::$Logger->error( current( $errors ) );
			return false;
		}

		$res = $api->getResult()->getResultData();

		if ( empty( $res['query']['pages'] ) ) {
			return [];
		}

		$services = MediaWikiServices::getInstance();
		$userFactory = $services->getUserFactory();
		$ret = [];

		foreach ( $res['query']['pages'] as $page ) {
			if ( is_array( $page ) && isset( $page['contributors'] ) ) {
				foreach ( $page['contributors'] as $value ) {
					if ( is_array( $value ) ) {
						$user_ = $userFactory->newFromId( $value['userid'] );
						if ( $user_ ) {
							$ret[$user_->getId()] = $user_;
						}
					}
				}
			}
		}

		return $ret;
	}

	/**
	 * @param \User $user
	 * @param array &$output
	 * @param bool $delete false
	 * @return bool
	 */
	public static function deleteOldRevisions( $user, &$output, $delete = false ) {
		$dbr = \VisualData::getDB( DB_REPLICA );
		$dbw = \VisualData::getDB( DB_PRIMARY );

		// ***ATTENTION ! delete does not work in conjunction with join on the same table,
		// use select and delete by id, or use deleteJoin
		$method = 'select';

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

			$ret = call_user_func_array( [ $dbw, $method ], $args );

			if ( $method === 'selectSQLText' ) {
				echo $ret;
				return;
			}

			return $ret;
		};

		$deleteRows = static function ( $res, $tableName, $field ) use ( $dbw ) {
			if ( !$res->numRows() ) {
				echo "no rows to delete from $tableName" . PHP_EOL;
				return;
			}

			$delRows = [];
			foreach ( $res as $row ) {
				$delRows[] = $row->$field;
			}

			echo 'deleting ' . $res->numRows() . " entries from $tableName" . PHP_EOL;
			$conds = [ $field => $delRows ];
			$dbw->delete(
				$tableName,
				$conds,
				// phpcs:ignore MediaWiki.Usage.MagicConstantClosure.FoundConstantMethod
				__METHOD__
			);
		};

		// get actor_id of maintenance script
		$tableName = 'actor';
		$conds = [];
		$conds['actor_name'] = 'Maintenance script';
		$actor_id = $dbw->selectField(
			$tableName,
			'actor_id',
			$conds,
			__METHOD__,
			[ 'LIMIT' => 1 ]
		);

		if ( !$actor_id ) {
			return false;
		}

		// get/delete all non-current slots created by
		// maintenance script in NS_CONTACTMANAGER namespace
		$tables = [ 's' => 'slots', 'rev' => 'revision', 'p' => 'page' ];
		$fields = [ 's.slot_revision_id' ];
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

		if ( is_object( $res ) ) {
			if ( $delete ) {
				$deleteRows( $res, 'slots', 'slot_revision_id' );
			} else {
				$output[] = 'found ' . $res->numRows() . ' old slots';
			}
		}

		// delete orphaned revisions
		$tables = [ 'rev' => 'revision', 'p' => 'page', 's' => 'slots' ];
		$fields = [ 'rev.rev_id' ];
		$joins = [];
		$joins['p'] = [ 'JOIN', 'rev.rev_page=p.page_id' ];
		$joins['s'] = [ 'LEFT JOIN', 's.slot_revision_id = rev.rev_id' ];

		$conds = [];
		$conds[] = 's.slot_revision_id IS NULL';
		$conds['rev.rev_actor'] = $actor_id;
		$conds['p.page_namespace'] = NS_CONTACTMANAGER;
		$options = [];

		$res = $callFunction( $tables, $fields, $conds, $options, $joins );

		if ( is_object( $res ) ) {
			if ( $delete ) {
				$deleteRows( $res, 'revision', 'rev_id' );
			} else {
				$output[] = 'found ' . $res->numRows() . ' old revisions';
			}
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

		if ( is_object( $res ) ) {
			if ( $delete ) {
				$deleteRows( $res, 'content', 'content_id' );
			} else {
				$output[] = 'found ' . $res->numRows() . ' old contents';
			}
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

		if ( is_object( $res ) ) {
			if ( $delete ) {
				$deleteRows( $res, 'ip_changes', 'ipc_rev_id' );
			} else {
				$output[] = 'found ' . $res->numRows() . ' old ip_changes';
			}
		}

		if ( $delete ) {
			$output[] = 'Done';
		}

		return $delete;
	}

	/**
	 * @param array $name
	 * @return bool|File
	 */
	public static function getFile( $name ) {
		$title = TitleClass::newFromText( $name, NS_FILE );

		if ( !$title ) {
			return false;
		}

		$wikiFilePage = new \WikiFilePage( $title );
		return $wikiFilePage->getFile();
	}

	/**
	 * @param array $data
	 * @param string $prefix
	 * @return array
	 */
	public static function flattenArray( $data, $prefix = '' ) {
		$ret = [];

		foreach ( (array)$data as $key => $value ) {
			$fullKey = ( $prefix !== '' ? "$prefix/$key" : $key );

			if ( is_array( $value ) || is_object( $value ) ) {
				$ret += self::flattenArray( $value, $fullKey );
			} else {
				$ret[$fullKey] = $value;
			}
		}

		return $ret;
	}
}
