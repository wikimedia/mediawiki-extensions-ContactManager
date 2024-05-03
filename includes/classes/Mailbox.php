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
 * @copyright Copyright ©2023, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager;

if ( is_readable( __DIR__ . '/../../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../../vendor/autoload.php';
}

class Mailbox {

	/** @var string */
	private $mailbox = null;

	/**
	 * @param string $mailboxName
	 * @param array &$errors []
	 * @return false|ContactManagerMailbox
	 */
	public function __construct( $mailboxName, &$errors = [] ) {
		$data = \ContactManager::getMailboxes( $mailboxName );
		if ( empty( $data ) ) {
			$errors[] = 'mailbox not found';
			return false;
		}

		$password = ( !empty( $GLOBALS['wgContactManagerMailboxPassword'][$data['name']] ) ?
			$GLOBALS['wgContactManagerMailboxPassword'][$data['name']] : $data['password'] );

		$this->mailbox = self::connectMailbox(
			$data['server'],
			$data['username'],
			$password
		);
	}

	/**
	 * @return null|ContactManagerMailbox
	 */
	public function getImapMailbox() {
		return $this->mailbox;
	}

	/**
	 * @param array &$errors []
	 * @return array|null
	 */
	public function getInfo( &$errors = [] ) {
		if ( !$this->mailbox ) {
			$errors[] = 'no mailbox';
			return;
		}
		$ret = $this->mailbox->getMailboxInfo();

		return (array)$ret;
	}

	/**
	 * @param array &$errors []
	 * @return array|null
	 */
	public function getFolders( &$errors = [] ) {
		if ( !$this->mailbox ) {
			$errors[] = 'no mailbox';
			return;
		}
		$ret = $this->mailbox->getMailboxes( '*' );
		return (array)$ret;
	}

	/**
	 * @param string $server
	 * @param string $username
	 * @param string $password
	 * @param string|null $mailbox
	 * @param int|null $port
	 * @return ContactManagerMailbox
	 */
	private function connectMailbox( $server, $username, $password, $mailbox = "", $port = 993 ) {
		$attachmentsFolder = \ContactManager::getAttachmentsFolder();

		if ( !file_exists( $attachmentsFolder ) ) {
			mkdir( $attachmentsFolder, 0777, true );
		}

		return new PHPImapMailbox(
			'{' . $server . ':' . $port . '/imap/ssl}' . $mailbox,
			$username,
			$password,
			// Directory, where attachments will be saved (optional)
			$attachmentsFolder,
			// Server encoding (optional)
			'UTF-8',
			// Trim leading/ending whitespaces of IMAP path (optional)
			true,
			// Attachment filename mode (optional; false = random filename; true = original filename)
			true
		);
	}
}
