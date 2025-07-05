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
		$events = json_decode( $raw, true );

		if ( empty( $events ) ) {
			echo 'no data';
			exit();
		}

		$dbw = \VisualData::getDB( DB_PRIMARY );
		$dateTime = date( 'Y-m-d H:i:s' );

		/*
		[
			{
				"email": "...",
				"event": "delivered",
				"ip": "...",
				"mailer": "sendgrid",
				"page_id": "111122",
				"response": "250 Requested mail action okay, completed: id=1MumRZ-1uq5TJ473D-00ywQv",
				"sg_event_id": "ZGVsaXZlcmVkLTAtMTkxMzA1MTktWU5FcEJidS1TV3VQVFlrZnZKUm9RZy0w",
				"sg_message_id": "YNEpBbu-SWuPTYkfvJRoQg.recvd-65696df74f-8tbhj-1-6869002D-8.0",
				"smtp-id": "<YNEpBbu-SWuPTYkfvJRoQg@geopod-ismtpd-7>",
				"timestamp": 1751711791,
				"tls": 1
			},
			{
				"email": "...",
				"event": "processed",
				"mailer": "sendgrid",
				"page_id": "111122",
				"send_at": 0,
				"sg_event_id": "cHJvY2Vzc2VkLTE5MTMwNTE5LVlORXBCYnUtU1d1UFRZa2Z2SlJvUWctMA",
				"sg_message_id": "YNEpBbu-SWuPTYkfvJRoQg.recvd-65696df74f-8tbhj-1-6869002D-8.0",
				"smtp-id": "<YNEpBbu-SWuPTYkfvJRoQg@geopod-ismtpd-7>",
				"timestamp": 1751711789
			}
		]
		*/

		$eventPriority = [
			'click',
			'open',
			'delivered',
			'bounce',
			'dropped',
			'spamreport',
			'blocked',
			'deferred',
			'unsubscribe',
			'processed'
		];

		usort( $events, static function ( $a, $b ) use ( $eventPriority ) {
			$resA = $eventPriority[$a['event']] ?? PHP_INT_MAX;
			$resB = $eventPriority[$b['event']] ?? PHP_INT_MAX;
			return $resA <=> $resB;
		} );

		$updatedRows = [];
		foreach ( $events as $event ) {
			if ( !empty( $event['page_id'] ) ) {
				$pageId = $event['page_id'];
			} elseif ( array_key_exists( 'custom_args', $event ) && !empty( $event['custom_args']['page_id'] ) ) {
				$pageId = $event['custom_args']['page_id'];
			} else {
				continue;
			}

			$key = $pageId . '|' . $event['email'];
			if ( isset( $updatedRows[$key] ) ) {
				continue;
			}
			$updatedKeys[$key] = true;

			$tableName = 'contactmanager_tracking';
			$update = [
				'event_type' => $event['event'],
				'updated_at' => $dateTime
			];
			$conds_ = [
				'page_id' => $pageId,
				'email' => $event['email'],
			];

			$previousEvent = $dbw->selectField(
				$tableName,
				'event_type',
				$conds_,
				__METHOD__
			);

			$prevIndex = array_search( $previousEvent, $eventPriority );
			$currIndex = array_search( $event['event'], $eventPriority );

			if (
				$previousEvent !== null &&
				$prevIndex !== false && $currIndex !== false &&
				$prevIndex < $currIndex
			) {
				continue;
			}

			$res = $dbw->update(
				$tableName,
				$update,
				$conds_,
				__METHOD__
			);

			if ( !empty( $event['mailer'] ) ) {
				$mailer = $event['mailer'];
			} elseif ( array_key_exists( 'custom_args', $event ) && !empty( $event['custom_args']['mailer'] ) ) {
				$mailer = $event['custom_args']['mailer'];
			} else {
				continue;
			}

			switch ( $mailer ) {
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
					];

					$row = [
						'page_id' => $pageId,
						'event_type' => $event['event'],
						'smtp_id' => $event['smtp-id'],
						'created_at' => $dateTime
					];
					foreach ( $columns as $value ) {
						if ( array_key_exists( $value, $event ) ) {
							$row[$value] = $event[$value];
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
		}

		$mediaWiki = new \MediaWiki();
		$mediaWiki->restInPeace();
	}
}
