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

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class DeleteOldJobRevisions extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Delete old (non-current) revisions of pages in the ContactManager namespace created by jobs' );
		$this->addOption( 'delete', 'Actually perform the deletion' );
	}

	public function execute() {
		$this->doDelete( $this->hasOption( 'delete' ) );
	}

	/**
	 * @param bool $delete false
	 */
	private function doDelete( $delete = false ) {
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		$output = [];
		$ret = \ContactManager::deleteOldRevisions( $user, $output, $delete );

		foreach ( $output as $value ) {
			$this->output( "$value\n" );
		}

		if ( $res ) {
			$this->purgeRedundantText( true );
			$this->output( "done.\n" );
		}
	}
}

$maintClass = DeleteOldJobRevisions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
