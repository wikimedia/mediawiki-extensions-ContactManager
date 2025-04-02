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

		$requiredParameters = [ 'session', 'mailbox', 'job' ];
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

		$title = TitleClass::newFromText( $this->params['pageid'] );
		$context->setTitle( $title );

		$mailboxName = $this->params['mailbox'];
		$errors = [];

		switch ( $this->params['job'] ) {
			case 'mailbox-info':
				\ContactManager::getInfo( $user, $mailboxName, $errors );
				break;
			case 'retrieve-folders':
			case 'get-folders':
				\ContactManager::getFolders( $user, $mailboxName, $errors );
				break;
			case 'retrieve-messages':
			case 'get-messages':
				\ContactManager::getMessages( $user, $this->params, $errors );
				break;
			case 'retrieve-message':
				$importMessage = new ImportMessage( $user, $this->params, $errors );
				$importMessage->doImport();
				break;
			case 'record-header':
				$recordHeader = new RecordHeader( $user, $this->params, $errors );
				$recordHeader->doImport();
				break;
			case 'get-contacts':
			case 'retrieve-contacts':
				\ContactManager::retrieveContacts( $user, $this->params, $errors );
				break;
		}

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
