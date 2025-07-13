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
 * @copyright Copyright ©2022, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager;

use Job;
use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;
use MediaWiki\Session\SessionManager;
use RequestContext;
use Wikimedia\ScopedCallback;

class ContactManagerJob extends Job {

	/**
	 * @param Title|Mediawiki\Title\Title $title
	 * @param array|bool $params
	 */
	public function __construct( $title, $params = [] ) {
		parent::__construct( 'ContactManagerJob', $title, $params );
	}

	/**
	 * @return bool
	 */
	public function run() {
		// T279090
		// $user = User::newFromId( $this->params['user_id'] );

		$requiredParameters = [ 'session', 'name' ];
		foreach ( $requiredParameters as $value ) {
			if ( !isset( $this->params[$value] ) ) {
				$this->error = "ContactManager: $value parameter not set";
				return false;
			}
		}

		// use 'session' => $this->getContext()->exportSession()
		// if ( isset( $this->params['session'] ) ) {
		if ( !SessionManager::getGlobalSession()->isPersistent() ) {
			$callback = RequestContext::importScopedSession( $this->params['session'] );
			$this->addTeardownCallback( static function () use ( &$callback ) {
				ScopedCallback::consume( $callback );
			} );
		}
		$context = RequestContext::getMain();
		$user = $context->getUser();

		if ( !$user->isAllowed( 'contactmanager-can-manage-mailboxes' ) ) {
			$this->error = 'ContactManager: Permission error';
			return false;
		}

		echo 'recording job status (start)' . PHP_EOL;

		\ContactManager::setRunningJob( $user, $this->params['name'], \ContactManager::JOB_START,
			( array_key_exists( 'mailbox', $this->params ) ? $this->params['mailbox'] : null ) );

		$title = TitleClass::newFromID( $this->params['pageid'] );
		$context->setTitle( $title );

		$errors = [];
		switch ( $this->params['name'] ) {
			case 'mailbox-info':
				\ContactManager::getInfo( $user, $this->params['mailbox'], $errors );
				break;

			case 'get-folders':
				\ContactManager::getFolders( $user, $this->params['mailbox'], $errors );
				break;

			case 'retrieve-messages':
			case 'get-messages':
				\ContactManager::getMessages( $user, $this->params, $errors );
				break;

			case 'delete-old-revisions':
				$output = [];
				\ContactManager::deleteOldRevisions( $user, $output, true );
				break;

			case 'get-message':
			case 'retrieve-message':
				foreach ( $this->params['folders'] as $folder_ ) {
					if ( $folder_['folder_name'] === $this->params['folder_name'] ) {
						$this->params['folder'] = $folder_;
						break;
					}
				}
				$mailbox = new Mailbox( $mailboxName, $errors );
				if ( $mailbox ) {
					$mailboxData = \ContactManager::getMailboxData( $params['mailbox'] );
					$importMessage = new ImportMessage( $user, $mailbox, $mailboxData, $this->params, $errors );
					$res_ = $importMessage->doImport();
					if ( !is_array( $res_ ) ) {
						$this->error = 'ContactManager: error retrieving message';
					}
				}
				break;
			// case 'record-header':
			// 	$recordHeader = new RecordHeader( $user, $this->params, $errors );
			// 	$recordHeader->doImport();
			// 	break;
			// case 'get-contacts':
			// case 'retrieve-contacts':
			// 	\ContactManager::retrieveContacts( $user, $this->params, $errors );
			// 	break;
		}

		echo 'recording job status (end)' . PHP_EOL;

		\ContactManager::setRunningJob( $user, $this->params['name'], \ContactManager::JOB_END,
			( array_key_exists( 'mailbox', $this->params ) ? $this->params['mailbox'] : null ) );

		// set rc_bot to 1 to avoid polluting Special:RecentChanges
		\ContactManager::updateRecentChangesTable();

		if ( count( $errors ) ) {
			$this->error = array_pop( $errors );
			return false;
		}

		// @TODO call ECHO when finished
		return true;
	}

	public function allowRetries() {
		return false;
	}

}
