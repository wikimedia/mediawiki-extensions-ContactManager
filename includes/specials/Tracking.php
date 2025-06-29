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
 * @copyright Copyright ©2025, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager\Special;

class Tracking extends \SpecialPage {

	public function __construct() {
		$listed = false;
		parent::__construct( 'ContactManagerTracking', '', $listed );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		$out->disable();

		$raw = file_get_contents( 'php://input' );
		$dbw = \VisualData::getDB( DB_PRIMARY );
		$dateTime = date( 'Y-m-d H:i:s' );
		$pageId = $raw['custom_args']['page_id'];

		$tableName = 'contactmanager_tracking';
		$update = [
			'event_type' => $raw['eventType'],
			'updated_at' => $dateTime
		];
		$conds_ = [
			'page_id' => $pageId,
			'email' => $raw['email'],
		];
		$res = $this->dbw->update(
			$tableName,
			$update,
			$conds_,
			__METHOD__
		);

		switch ( $raw['custom_args']['mailer'] ) {
			case 'sendgrid':
				// @see https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/event
				$columns = [
					'id',
					'page_id',
					'email',
					'event_type',
					'timestamp',
					'smtp_id',
					'sg_event_id',
					'sg_message_id',
					'category',
					'ip',
					'url',
					'useragent',
					'response',
					'reason',
					'sg_machine_open',
					'created_at',
					'updated_at',
				];

				$row = [
					'page_id' => $pageId,
					'created_at' => $dateTime
				];
				foreach ( $columns as $value ) {
					if ( array_key_exists( $value, $raw ) ) {
						$row[$value] = $raw[$value];
					}
				}
				$tableName = 'contactmanager_tracking_sendgrid';
				$options = [];
				$res = $dbw->insert(
					$tableName,
					$row,
					__METHOD__,
					$options
				);
				break;
		}

		$mediaWiki = new MediaWiki();
		$mediaWiki->restInPeace();
	}
}
