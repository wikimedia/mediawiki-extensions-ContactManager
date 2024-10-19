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
		//	$this->addOption( 'format', 'import format (csv or json)', true, true );
	}

	/**
	 * inheritDoc
	 */
	public function execute() {
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		$services = MediaWikiServices::getInstance();
		$services->getUserGroupManager()->addUserToGroup( $user, 'bureaucrat' );

		$title = SpecialPage::getTitleFor( 'Badtitle' );
		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$context->setUser( $user );

		$schema = $GLOBALS['wgContactManagerSchemasRetrieveMessages'];
		$query = '[[job::retrieve-messages]]';
		$results = \VisualData::getQueryResults( $schema, $query );

		$data = [];
		$data['pageid'] = $title->getArticleID();
		$data['session'] = $context->exportSession();

		foreach ( $results as $value ) {
			$data_ = array_merge( $value['data'], $data );

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
				$job = new ContactManagerJob( $title, $data_ );

				if ( !$job ) {
					// $this->dieWithError( 'apierror-contactmanager-unknown-job' );
					continue;
				}

				\ContactManager::pushJobs( [ $job ] );
			}
		}
	}
}

$maintClass = CheckMessages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
