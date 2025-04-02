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
 * @copyright Copyright ©2024, https://wikisphere.org
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
		$this->addOption( 'delete', 'force delete', false, false );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$delete = $this->getOption( 'delete' ) ?? false;
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
		// $queueJobs = $jobQueueGroup->get( 'ContactManagerJob' )->getAllQueuedJobs();
		$count = $jobQueueGroup->get( 'ContactManagerJob' )->getSize();
		// while ( $queueJobs->valid() ) {
		// 	print_r($queueJobs->current());
		// 	$queueJobs->next();
		// }

		if ( $count ) {
			if ( $delete ) {
				$jobQueueGroup->get( 'ContactManagerJob' )->delete();
			} else {
				return 'Queued jobs (ContactManagerJob). Wait until they end or run with --delete parameter';
			}
		}

		$title = SpecialPage::getTitleFor( 'Badtitle' );
		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$context->setUser( $user );

		$schema = $GLOBALS['wgContactManagerSchemasRetrieveMessages'];
		$query = '[[job::retrieve-messages]]';
		$printouts = [
			'fetch',
			'check_email_interval',
		];
		$results = \VisualData::getQueryResults( $schema, $query, $printouts );

		$data = [];
		$data['session'] = $context->exportSession();
		$jobs = [];
		foreach ( $results as $value ) {
			// add session parameter
			$data_ = array_merge( $value['data'], $data );

			if ( $data_['fetch'] !== 'UIDs incremental' ) {
				continue;
			}

			if ( empty( $data_['check_email_interval'] ) ) {
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
				$value = \VisualData::getQueryResults( $schema, $query_, $printouts_ );
				$data_ = array_merge( $value['data'], $data );

				// must be a valid title, otherwise if an "inner"
				// job is created, will trigger the error
				// $params must be an array in $IP/includes/jobqueue/Job.php on line 101
				$title_ = TitleClass::newFromText( $value['title'] );
				$data_['pageid'] = $title_->getArticleID();

				$job = new ContactManagerJob( $title_, $data_ );

				if ( !$job ) {
					// $this->dieWithError( 'apierror-contactmanager-unknown-job' );
					continue;
				}

				$jobs[] = $job;
			}
		}
		\ContactManager::pushJobs( $jobs );
	}
}

$maintClass = CheckMessages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
