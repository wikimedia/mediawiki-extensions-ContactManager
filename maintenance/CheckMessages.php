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
 * @copyright Copyright ©2024-2025, https://wikisphere.org
 */

use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;
use MediaWiki\Extension\ContactManager\ContactManagerJob;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class CheckMessages extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'check messages' );
		$this->requireExtension( 'ContactManager' );

		// name,  description, required = false,
		//	withArg = false, shortName = false, multiOccurrence = false
		$this->addOption( 'force', 'delete existing ContactManager jobs', false, false );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$force = $this->getOption( 'force' ) ?? false;
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );

		\ContactManager::logError( 'debug', 'CheckMessages start ' . date( 'Y-m-d H:i:s' ) );

		[ $count, $countAcquired, $countDelayed ] = \ContactManager::getJobGroupCount( $user );

		echo "count $count" . PHP_EOL;
		echo "countAcquired $countAcquired" . PHP_EOL;
		echo "countDelayed $countDelayed" . PHP_EOL;

		\ContactManager::logError( 'debug', 'count ' . $count );
		\ContactManager::logError( 'debug', 'countAcquired ' . $countAcquired );
		\ContactManager::logError( 'debug', 'countDelayed ' . $countDelayed );

		if ( $count || $countAcquired || $countDelayed ) {
			if ( $force ) {
				$jobQueueGroup->get( 'ContactManagerJob' )->delete();
			} else {
				echo 'Queued jobs (ContactManagerJob). Wait until they end or run with --force parameter';
				\ContactManager::logError( 'debug', 'Queued jobs (ContactManagerJob). Wait until they end or run with --force parameter' );
				return;
			}
		}

		$title = \SpecialPage::getTitleFor( 'Badtitle' );
		$context = \RequestContext::getMain();
		$context->setTitle( $title );
		$context->setUser( $user );

		$jobs = [];

		$this->deleteOldRevisions( $context, $user, $jobs );
		$this->retrieveMessages( $context, $user, $jobs );

		\ContactManager::pushJobs( $jobs );

		echo 'created jobs:' . PHP_EOL;
		foreach ( $jobs as $value ) {
			echo $value->params['name'] . PHP_EOL;
		}

		\ContactManager::logError( 'debug', 'created jobs', $jobs );
	}

	/**
	 * @param Context $context
	 * @param User $user
	 * @param array &$jobs
	 */
	public function retrieveMessages( $context, $user, &$jobs ) {
		$schema = $GLOBALS['wgContactManagerSchemasJobRetrieveMessages'];
		$query = '[[name::retrieve-messages]]';

		// required by \ContactManager::isRunning
		$printouts = [
			'is_running',
			'start_date',
			'end_date',
			'last_status',
			'check_email_interval',
		];
		$params = [
		];
		$results = \VisualData::getQueryResults( $schema, $query, $printouts, $params );

		if ( \ContactManager::queryError( $results, true ) ) {
			echo 'error query' . PHP_EOL;
			print_r( $results );
			\ContactManager::logError( 'error', 'error query', $results );
			return;
		}

		$data = [];
		$data['session'] = $context->exportSession();

		foreach ( $results as $value ) {
			// add session parameter
			$data_ = array_merge( $value['data'], $data );

			if ( empty( $data_['check_email_interval'] ) ) {
				continue;
			}

			if ( \ContactManager::isRunning( $data_ ) ) {
				continue;
			}

			\ContactManager::logError( 'debug', 'retrieveMessages -allows', $data_ );

			$exp = '*/' . $data_['check_email_interval'] . ' * * * *';
			try {
				$cron = new Cron\CronExpression( $exp );
			} catch ( Exception $e ) {
				continue;
			}

			if ( $cron->isDue() ) {
				// repeat the query with all the printouts
				// (this is slower)
				$query_ = $value['pageid'];
				$printouts_ = [];

				// *** when pageid is set in the query, the first result is returned
				$value = \VisualData::getQueryResults( $schema, $query_, $printouts_ );

				if ( \ContactManager::queryError( $value, true ) ) {
					echo 'error query' . PHP_EOL;
					print_r( $value );
					\ContactManager::logError( 'error', 'error query', $value );
					continue;
				}

				$data_ = array_merge( $value['data'], $data );

				// must be a valid title, otherwise if an "inner"
				// job is created, will trigger the error
				// $params must be an array in $IP/includes/jobqueue/Job.php on line 101
				$title_ = TitleClass::newFromID( $value['pageid'] );
				$data_['pageid'] = $value['pageid'];

				if ( !$title_ ) {
					\ContactManager::logError( 'error', 'error creating title: ' . $value['pageid'] );
					continue;
				}

				$job = new ContactManagerJob( $title_, $data_ );
				if ( $job ) {
					$jobs[] = $job;
				}
			}
		}
	}

	/**
	 * @param Context $context
	 * @param User $user
	 * @param array &$jobs
	 * @return bool|void
	 */
	public function deleteOldRevisions( $context, $user, &$jobs ) {
		// execute job 'delete-old-revisions' only if job retrieve-messages
		// is not running
		$schema = $GLOBALS['wgContactManagerSchemasJobRetrieveMessages'];
		$query = '[[name::retrieve-messages]]';

		// required by \ContactManager::isRunning
		$printouts = [
			'is_running',
			'start_date',
			'end_date',
			'last_status',
			'check_email_interval',
		];
		$params = [
		];
		$results = \VisualData::getQueryResults( $schema, $query, $printouts, $params );

		if ( \ContactManager::queryError( $results, false ) ) {
			echo 'error query' . PHP_EOL;
			print_r( $results );
			\ContactManager::logError( 'error', 'error query', $results );
			return false;
		}

		if ( count( $results ) ) {
			foreach ( $results as $value_ ) {
				if ( \ContactManager::isRunning( $value_['data'] ) ) {
					\ContactManager::logError( 'debug', 'delete-old-revisions skip' );
					return;
				}
			}
		}

		$schema = $GLOBALS['wgContactManagerSchemasJobDeleteOldRevisions'];
		$query = '[[name::delete-old-revisions]]';
		$printouts = [
			'is_running',
			'start_date',
			'end_date',
		];
		$params_ = [
		];
		$results = \VisualData::getQueryResults( $schema, $query, $printouts, $params_ );

		\ContactManager::logError( 'debug', 'delete-old-revisions results', $results );

		if ( \ContactManager::queryError( $results, false ) ) {
			echo 'error query' . PHP_EOL;
			print_r( $results );
			\ContactManager::logError( 'error', 'error query', $results );
			return false;
		}

		if ( count( $results ) && !empty( $results[0]['data'] ) ) {
			$value = $results[0]['data'];

			if ( \ContactManager::isRunning( $value ) ) {
				return;
			}

			// once per day
			if ( time() - strtotime( $value['start_date'] ) <= strtotime( '1 day', 0 ) ) {
				return;
			}
		}

		\ContactManager::logError( 'debug', 'delete-old-revisions run' );

		$data = [];
		$data['session'] = $context->exportSession();

		$data_ = array_merge( [ 'name' => 'delete-old-revisions' ], $data );
		$title_ = TitleClass::newFromText( $GLOBALS['wgContactManagerMainJobsArticle'] );
		$data_['pageid'] = $title_->getArticleID();

		$job = new ContactManagerJob( $title_, $data_ );
		if ( $job ) {
			$jobs[] = $job;
		}

		return true;
	}
}

$maintClass = CheckMessages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
