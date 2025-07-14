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

		\ContactManager::logError( 'debug', 'ApiCreateJob start ' . date( 'Y-m-d H:i:s' ) );

		[ $count, $countAcquired, $countDelayed ] = \ContactManager::getJobGroupCount( $user );

		\ContactManager::logError( 'debug', 'count ' . $count );
		\ContactManager::logError( 'debug', 'countAcquired ' . $countAcquired );
		\ContactManager::logError( 'debug', 'countDelayed ' . $countDelayed );

		if ( $count || $countAcquired || $countDelayed ) {
			$this->dieWithError( 'apierror-contactmanager-job-queued' );
		}

		$result = $this->getResult();
		$params = $this->extractRequestParams();
		$data = json_decode( $params['data'], true );

		$title = ( !empty( $params['pageid'] ) ? TitleClass::newFromID( $params['pageid'] ) :
			\SpecialPage::getTitleFor( 'Badtitle' ) );

		$context = \RequestContext::getMain();
		$context->setTitle( $title );

		$data['pageid'] = $params['pageid'];
		$data['session'] = $context->exportSession();

		$schema = \ContactManager::jobNameToSchema( $data['name'] );

		if ( empty( $schema ) ) {
			$this->dieWithError( 'apierror-contactmanager-unknown-job-schema' );
		}

		try {
			if ( \ContactManager::jobIsRunning( $data['name'], $data['mailbox'] ?? null ) ) {
				\ContactManager::logError( 'debug', 'ApiCreateJob isRunning true' );
				\ContactManager::logError( 'debug', 'data', $data );

				$this->dieWithError( 'apierror-contactmanager-running-job' );
			}

			\ContactManager::logError( 'debug', 'ApiCreateJob isRunning false' );

			\ContactManager::logError( 'debug', 'ApiCreateJob data', $data );

			$job = new ContactManagerJob( $title, $data );

			if ( !$job ) {
				$this->dieWithError( 'apierror-contactmanager-unknown-job' );
			}

			\ContactManager::pushJobs( [ $job ] );

			$result->addValue( [ $this->getModuleName() ], 'data', true );

		} catch ( \Exception $e ) {
			\ContactManager::logError( 'error', 'ApiCreateJob error: ' . $e->getMessage() );
			$result->addValue( [ $this->getModuleName() ], 'error', $e->getMessage() );
		}
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
