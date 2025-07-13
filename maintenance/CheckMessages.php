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
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

if ( is_readable( __DIR__ . '/../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../vendor/autoload.php';
}

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
		$services = MediaWikiServices::getInstance();
		$services->getUserGroupManager()->addUserToGroup( $user, 'bureaucrat' );

		if ( method_exists( $services, 'getJobQueueGroup' ) ) {
			// MW 1.37+
			$jobQueueGroup = $services->getJobQueueGroup();
		} else {
			$jobQueueGroup = JobQueueGroup::singleton();
		}

		// @see JobQueue
		$count = $jobQueueGroup->get( 'ContactManagerJob' )->getSize();
		$countAcquired = $jobQueueGroup->get( 'ContactManagerJob' )->getAcquiredCount();
		$countDelayed = $jobQueueGroup->get( 'ContactManagerJob' )->getDelayedCount();

		if ( $count || $countAcquired || $countDelayed ) {
			echo "count $count" . PHP_EOL;
			echo "countAcquired $countAcquired" . PHP_EOL;
			echo "countDelayed $countDelayed" . PHP_EOL;

			if ( $force ) {
				$jobQueueGroup->get( 'ContactManagerJob' )->delete();
			} else {
				echo 'Queued jobs (ContactManagerJob). Wait until they end or run with --delete parameter';
				return;
			}
		}

		$title = SpecialPage::getTitleFor( 'Badtitle' );
		$context = RequestContext::getMain();
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
			return;
		}

		$data = [];
		$data['session'] = $context->exportSession();
		$data['jobSchema'] = $schema;

		foreach ( $results as $value ) {
			// add session parameter
			$data_ = array_merge( $value['data'], $data );

			if ( empty( $data_['check_email_interval'] ) ) {
				continue;
			}

			if ( \ContactManager::isRunning( $data_ ) ) {
				continue;
			}

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
					continue;
				}

				$data_ = array_merge( $value['data'], $data );

				// must be a valid title, otherwise if an "inner"
				// job is created, will trigger the error
				// $params must be an array in $IP/includes/jobqueue/Job.php on line 101
				$title_ = TitleClass::newFromText( $value['title'] );
				$data_['pageid'] = $title_->getArticleID();

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
			return false;
		}

		if ( count( $results ) ) {
			foreach ( $results as $value_ ) {
				if ( \ContactManager::isRunning( $value_['data'] ) ) {
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

		if ( \ContactManager::queryError( $results, false ) ) {
			echo 'error query' . PHP_EOL;
			print_r( $results );
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

		$data = [];
		$data['session'] = $context->exportSession();
		$data['jobSchema'] = $GLOBALS['wgContactManagerSchemasJobDeleteOldRevisions'];

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
