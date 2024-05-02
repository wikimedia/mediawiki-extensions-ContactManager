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
 * @copyright Copyright ©2023, https://wikisphere.org
 */

// @credits: CommentStreams/EchoInterface

namespace MediaWiki\Extension\ContactManager;

// use EchoEvent;
use ExtensionRegistry;
use MWException;
use User;
use WikiPage;

class EchoInterface {
	/**
	 * @var bool
	 */
	private $isLoaded;

	/**
	 * @param ExtensionRegistry $extensionRegistry
	 */
	public function __construct(
		ExtensionRegistry $extensionRegistry
	) {
		$this->isLoaded = $extensionRegistry->isLoaded( 'Echo' );
	}

	/**
	 * @return bool
	 */
	public function isLoaded(): bool {
		return $this->isLoaded;
	}

	/**
	 * @param Reply $reply the comment to send notifications for
	 * @param WikiPage $associatedPage the associated page for the comment
	 * @param User $user
	 * @param Comment $parentComment
	 * @throws MWException
	 */
	public function sendNotifications(
		Reply $reply,
		WikiPage $associatedPage,
		User $user,
		Comment $parentComment
	) {
		if ( !$this->isLoaded ) {
			return;
		}

		// @TODO see commentstreams

		// EchoEvent::create( [
		// 	'type' => 'commentstreams-reply-to-watched-comment',
		// 	'title' => $associatedPage->getTitle(),
		// 	'extra' => $extra,
		// 	'agent' => $user
		// ] );
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
		// @TODO see commentstreams
	}
}
