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
 * @copyright Copyright ©2023-2025, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager;

use ApiBase;
use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;
use RequestContext;

class ApiCreateJob extends ApiBase {

	/**
	 * @inheritDoc
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function mustBePosted(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$user = $this->getUser();
		if ( !$user->isAllowed( 'contactmanager-can-manage-mailboxes' ) ) {
			$this->dieWithError( 'apierror-contactmanager-permissions-error' );
		}

		$result = $this->getResult();
		$params = $this->extractRequestParams();
		$data = json_decode( $params['data'], true );

		switch ( $data['name'] ) {
			case 'retrieve-messages':
				$schema = $GLOBALS['wgContactManagerSchemasJobRetrieveMessages'];
				break;
			case 'get-folders':
				$schema = $GLOBALS['wgContactManagerSchemasJobGetFolders'];
				break;
			case 'mailbox-info':
				$schema = $GLOBALS['wgContactManagerSchemasJobMailboxInfo'];
				break;
			case 'delete-old-revisions':
				$schema = $GLOBALS['wgContactManagerSchemasJobDeleteOldRevisions'];
				break;
		}

		$query = '[[name::' . $data['name'] . ']]';
		$query .= ( array_key_exists( 'mailbox', $data ) ? '[[mailbox::' . $data['mailbox'] . ']]' : '' );
		$query .= '[[is_running:false]]';

		$printouts = [
			'name'
		];
		$params_ = [
		];
		$results = \VisualData::getQueryResults( $schema, $query, $printouts, $params_ );

		if ( count( $results ) && $results[0] ) {
			$this->dieWithError( 'apierror-contactmanager-running-job' );
		}

		$title = TitleClass::newFromID( $params['pageid'] );
		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$data['pageid'] = $params['pageid'];
		$data['session'] = $context->exportSession();

		$job = new ContactManagerJob( $title, $data );

		if ( !$job ) {
			$this->dieWithError( 'apierror-contactmanager-unknown-job' );
			return;
		}

		\ContactManager::setRunningJob( $user, $schema, true, ( array_key_exists( 'mailbox', $data ) ? $data['mailbox'] : null ) );

		\ContactManager::pushJobs( [ $job ] );

		$result->addValue( [ $this->getModuleName() ], 'data', true );
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			'pageid' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => true
			],
			'data' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function needsToken() {
		return 'csrf';
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamples() {
		return false;
	}

}
