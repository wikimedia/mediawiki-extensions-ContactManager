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

	/** @var string */
	private $username;

	/**
	 * @param string $mailboxName
	 * @param array &$errors []
	 * @return false|ContactManagerMailbox
	 * @throws \MWException
	 */
	public function __construct( $mailboxName, &$errors = [] ) {
		if ( !extension_loaded( 'imap' ) ) {
			throw new \MWException( 'PHP IMAP extension not installed' );
		}

		$data = \ContactManager::getMailboxes( $mailboxName );
		if ( empty( $data ) ) {
			$errors[] = 'mailbox not found';
			return false;
		}

		if ( empty( $GLOBALS['wgContactManagerIMAP'][$data['name']] ) ) {
			$errors[] = 'credentials not found';
			return false;
		}

		$credentials = $GLOBALS['wgContactManagerIMAP'][$data['name']];
		$credentials = array_change_key_case( $credentials, CASE_LOWER );

		$this->username = $credentials['username'];

		$this->mailbox = self::connectMailbox(
			$credentials['server'],
			$credentials['username'],
			$credentials['password'],
			"",
			$credentials['port'],
		);
	}

	/**
	 * @return null|ContactManagerMailbox
	 */
	public function getImapMailbox() {
		return $this->mailbox;
	}

	/**
	 * @return string
	 */
	public function getUsername() {
		return $this->username;
	}

	public function disconnect() {
		$this->mailbox->disconnect();
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
	public function statusMailbox( &$errors = [] ) {
		if ( !$this->mailbox ) {
			$errors[] = 'no mailbox';
			return;
		}
		$ret = $this->mailbox->statusMailbox();

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
	 * @throws \MWException
	 */
	private function connectMailbox( $server, $username, $password, $mailbox = "", $port = 993 ) {
		// can be null, check in conjunction with setAttachmentsIgnore
		$attachmentsFolder = \ContactManager::getAttachmentsFolder();

		if ( !class_exists( 'PhpImap\Mailbox' ) ) {
			throw new \MWException( 'PhpImap not installed, run "composer install --no-dev" in the extension folder' );
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
			false
		);
	}
}
