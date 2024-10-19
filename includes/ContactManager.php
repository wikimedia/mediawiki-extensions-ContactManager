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

use MediaWiki\Extension\ContactManager\ContactManagerJob;
use MediaWiki\Extension\ContactManager\Mailbox;
use MediaWiki\Extension\ContactManager\Mailer;
use MediaWiki\MediaWikiServices;

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

		$folders = $params['folders'];
		$criteria = [];
		foreach ( $params['criteria'] as $key => $value ) {
			switch ( $value['criteria'] ) {
				case 'SINCE':
					if ( !empty( $value['since_autoupdate'] ) ) {

						// *** we cannot rely on $value['date_value']
						// since is not guaranteed to be updated
						$title_ = Title::newFromText( $params['job_pagetitle'] );

						if ( empty( $title_ ) ) {
							throw new MWException( 'job_pagetitle not set' );
						}
						$jsonData = \VisualData::getJsonData( $title_ );
						$schemaData = $jsonData['schemas'][$GLOBALS['wgContactManagerSchemasRetrieveMessages']];
						if ( !empty( $schemaData['criteria'][$key]['date_value'] ) ) {
							$criteria[] = $value['criteria'] . ' "' . $schemaData['criteria'][$key]['date_value'] . '"';
						}

					} elseif ( !empty( $value['date_value'] ) ) {
						$criteria[] = $value['criteria'] . ' "' . $value['since-value'] . '"';
					}
					break;
				case 'BEFORE':
				case 'ON':
					$criteria[] = $value['criteria'] . ' "' . $value['date_value'] . '"';
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

		$title = Title::newFromID( $params['pageid'] );
		$jobs = [];
		$n = 0;
		foreach ( $folders as $folder ) {
			$name_pos = strpos( $folder['folder'], '}' );
			$shortpath = substr( $folder['folder'], $name_pos + 1 );
			$imapMailbox->switchMailbox( $shortpath );

			// @TODO implement UI for fetch_overview
			// $latest_uid = 1;
			// $mails = $imapMailbox->fetch_overview( "$latest_uid:*" );
			$mails = $imapMailbox->searchMailbox( implode( ' ', $criteria ) );

			foreach ( $mails as $uid ) {
				if ( !empty( $params['limit'] ) && $n === $params['limit'] ) {
					break;
				}

				$jobs[] = new ContactManagerJob( $title, array_merge( $params, [
					'job' => 'retrieve-message',
					'folder' => $folder['folder'],
					'folder_name' => $folder['folder_name'],
					'uid' => $uid
				] ) );

				$n++;
			}
		}

		self::pushJobs( $jobs );
	}

	/**
	 * @return string
	 */
	public static function getAttachmentsFolder() {
		return ( !empty( $GLOBALS['wgContactManagerAttachmentsFolder'] )
			? $GLOBALS['wgContactManagerAttachmentsFolder']
			: $GLOBALS['wgBaseDirectory'] . '/ContactManager' );
	}

	/**
	 * @param array $data
	 * @param array &$errors []
	 * @return array
	 */
	public static function retrieveContacts( $data, &$errors ) {
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
