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

namespace MediaWiki\Extension\ContactManager;

use MediaWiki\MediaWikiServices;

// @see https://www.mediawiki.org/wiki/Extension:Echo/Creating_a_new_notification_type_(1.43)
class EchoInterface {
	/**
	 * @var bool
	 */
	private $isLoaded;

	/**
	 * @param \ExtensionRegistry $extensionRegistry
	 */
	public function __construct( \ExtensionRegistry $extensionRegistry ) {
		$this->isLoaded = $extensionRegistry->isLoaded( 'Echo' );
	}

	/**
	 * @return bool
	 */
	public function isLoaded(): bool {
		return $this->isLoaded;
	}

	/**
	 * @param \User $user
	 * @param Title|Mediawiki\Title\Title $mailboxTitle
	 * @param string $mailbox
	 * @param array $updates
	 * @return array
	 */
	public function sendNotifications( $user, $mailboxTitle, $mailbox, $updates ) {
		if ( !$this->isLoaded ) {
			return;
		}

		$extra = [
			'user' => $user,
			'mailbox' => $mailbox,
			'uid' => uniqid(),

			// *** important !!!
			'notifyAgent' => true,
		];

		// @see https://www.mediawiki.org/wiki/Extension:Echo/Creating_a_new_notification_type
		// $ret = \EchoEvent::create( [
		// 	'type' => 'contactmanager-get-messages-complete',
		// 	'title' => $mailboxTitle,
		// 	'extra' => $extra,
		// 	'agent' => $user
		// ] );

		$ret = [];
		foreach ( $updates as $name => $count ) {
			if ( !$count ) {
				continue;
			}

			$ret[] = \EchoEvent::create( [
				'type' => 'contactmanager-get-messages-new-' . $name,
				'title' => $mailboxTitle,
				'extra' => [ ...$extra, 'name' => $name, 'count' => $count ],
				'agent' => $user
			] );
		}

		return $ret;
	}

	/**
	 * @param array &$notifications notifications
	 * @param array &$notificationCategories notification categories
	 * @param array &$icons notification icons
	 * @noinspection PhpUnusedParameterInspection
	 */
	public static function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$icons
	) {
		// @see https://www.mediawiki.org/wiki/Extension:Echo/Creating_a_new_notification_type
		$notificationCategories['contactmanager-get-messages-complete-category'] = [
			'priority' => 3,
			'title' => 'contactmanager-echo-get-messages-updates-category',
			'tooltip' => 'contactmanager-echo-get-messages-updates-category-tooltip'
		];

		foreach ( [ 'messages', 'contacts', 'conversations' ] as $value ) {
			$notifications['contactmanager-get-messages-new-' . $value] = [
				'category' => 'contactmanager-get-messages-complete-category',
				'group' => 'positive',
				'section' => 'alert',
				'presentation-model' => EchoCMPresentationModel::class,
				'bundle' => [
					'web' => true,
					'email' => true,
					'expandable' => true,
				],
				'immediate' => true,
				'user-locators' => [ self::class . '::locateUsers' ],
				// 'user-filters' =>
				// 	[ self::class . '::locateUsersWatchingComment' ]
			];
		}
	}

	/**
	 * @param Event $event
	 * @param string &$bundleKey
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public static function onEchoGetBundleRules( $event, &$bundleKey ) {
		switch ( $event->getType() ) {
			case 'contactmanager-get-messages-new-messages':
			case 'contactmanager-get-messages-new-contacts':
			case 'contactmanager-get-messages-new-conversations':
				$bundleKey = 'contactmanager-echo-get-messages-updates-'
					. $event->getExtraParam( 'uid' );
				break;
		}
		return true;
	}

	/**
	 * @param \EchoEvent $event
	 * @return array
	 */
	public static function locateUsers( $event ) {
		$services = MediaWikiServices::getInstance();
		$userFactory = $services->getUserFactory();
		$user = $event->getExtraParam( 'user' );
		$title = $event->getTitle();

		$errors = [];
		$userIDs = \ContactManager::usersInGroups( $user, [ 'contactmanager-admin' ], $errors );

		$ret = [];
		foreach ( $userIDs as $value ) {
			$user_ = $userFactory->newFromName( $value );
			if ( $user_ ) {
				$ret[$user_->getId()] = $user_;
			}
		}

		$ret = array_merge( $ret, \ContactManager::getArticleEditors( $user, $title ) );

		if ( class_exists( 'PageOwnership' ) ) {
			$usernames = null;
			$pageids = [ $title->getArticleID() ];
			$namespaces = null;
			$created_by = null;
			$id = null;

			$results = \PageOwnership::getPermissions(
				$usernames,
				$pageids,
				$namespaces,
				$created_by,
				$id,
				$errors
			);

			foreach ( $results as $row ) {
				if ( !empty( $row['permissions_by_type'] ) && !empty( $row['usernames'] ) ) {
					$usernames = explode( ',', $row['usernames'] );
					foreach ( $usernames as $value ) {
						$user_ = $userFactory->newFromName( $value );
						if ( $user ) {
							$ret[$user_->getId()] = $user_;
						}
					}
				}
			}
		}

		return $ret;
	}

}
