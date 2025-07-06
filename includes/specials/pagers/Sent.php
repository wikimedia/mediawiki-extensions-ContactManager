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
 * along with ContactManager.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2025, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager\Pagers;

use Linker;
use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use TablePager;

class Sent extends TablePager {

	/** @var Request */
	private $request;

	/** @var MediaWiki\Extension\ContactManager\Special\BrowseTracking */
	private $parentClass;

	/** @var int */
	private $itemId;

	// @IMPORTANT!, otherwise the pager won't show !
	/** @var mLimit */
	public $mLimit = 40;

	/**
	 * @inheritDoc
	 */
	public function __construct( $parentClass, $request, LinkRenderer $linkRenderer ) {
		parent::__construct( $parentClass->getContext(), $linkRenderer );

		$this->request = $request;
		$this->parentClass = $parentClass;
		$this->itemId = $parentClass->par;
	}

	/**
	 * @inheritDoc
	 */
	protected function getDefaultDirections() {
		return self::DIR_DESCENDING;
	}

	/**
	 * @inheritDoc
	 */
	protected function getFieldNames() {
		$headers = [
			'user' => 'contactmanager-browsetracking-pager-header-user',
			'mailbox' => 'contactmanager-browsetracking-pager-header-mailbox',
			'page_id' => 'contactmanager-browsetracking-pager-header-page',
			'subject' => 'contactmanager-browsetracking-pager-header-subject',
			'recipients' => 'contactmanager-browsetracking-pager-header-recipients',
			'account' => 'contactmanager-browsetracking-pager-header-account',
			'created_at' => 'contactmanager-browsetracking-pager-header-created_at',
			'actions' => 'contactmanager-browsetracking-pager-header-actions'
		];

		foreach ( $headers as $key => $val ) {
			$headers[$key] = $this->msg( $val )->text();
		}

		return $headers;
	}

	/**
	 * @inheritDoc
	 */
	public function formatValue( $field, $value ) {
		/** @var object $row */
		$row = $this->mCurrentRow;

		switch ( $field ) {
			case 'user':
				$services = MediaWikiServices::getInstance();
				$userIdentityLookup = $services->getUserIdentityLookup();
				$user = $userIdentityLookup->getUserIdentityByUserId( $row->user );
				$formatted = $user->getName();
				break;

			case 'created_at':
				$formatted = htmlspecialchars(
					$this->getLanguage()->userTimeAndDate(
						wfTimestamp( TS_MW, $row->created_at ),
						$this->getUser()
					)
				);
				break;

			case 'page_id':
				$title_ = TitleClass::newFromId( $row->page_id );
				$query = [];
				if ( $title_ ) {
					$formatted = Linker::link( $title_, $title_->getText(), [], $query );
				}
				break;

			case 'mailbox':
				$title_ = TitleClass::newFromText( 'ContactManager:Mailboxes/' . $row->$field );
				$formatted = Linker::link( $title_, $row->$field, [], [] );
				break;

			case 'subject':
			case 'recipients':
			case 'account':
				$formatted = $row->$field;
				break;

			case 'actions':
				$link = '<span class="mw-ui-button mw-ui-progressive">' .
					$this->msg( 'contactmanager-browsetracking-table-button-view' )->text() . '</span>';
				$title_ = \SpecialPage::getTitleFor( 'ContactManagerBrowseTracking', $row->id );
				$query = [
					'view' => 'Tracking',
					'account' => $row->account
				];
				$formatted = Linker::link( $title_, $link, [], $query );
				break;

			default:
				throw new \MWException( "Unknown field '$field'" );
		}

		return $formatted;
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryInfo() {
		$ret = [];

		$tables = [ 'contactmanager_sent' ];
		$fields = [ '*' ];
		$join_conds = [];
		$conds = [];
		$options = [];

		$user = $this->request->getVal( 'user' );
		if ( !empty( $user ) ) {
			$services = MediaWikiServices::getInstance();
			$userIdentityLookup = $services->getUserIdentityLookup();
			$user = $userIdentityLookup->getUserIdentityByName( $user );
			$conds[ 'user' ] = ( $user ? $user->getId() : 0 );
		}

		$searchSubjects = [ 'mailbox', 'account' ];
		foreach ( $searchSubjects as $subject ) {
			$value_ = $this->request->getVal( $subject );
			if ( !empty( $value_ ) ) {
				$conds[ $subject ] = $value_;
			}
		}

		array_unique( $tables );

		$ret['tables'] = $tables;
		$ret['fields'] = $fields;
		$ret['join_conds'] = $join_conds;
		$ret['conds'] = $conds;
		$ret['options'] = $options;

		return $ret;
	}

	/**
	 * @inheritDoc
	 */
	protected function getTableClass() {
		return parent::getTableClass() . ' contactmanager-browsetracking-pager-table';
	}

	/**
	 * @inheritDoc
	 */
	public function getIndexField() {
		return 'created_at';
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultSort() {
		return 'created_at';
	}

	/**
	 * @inheritDoc
	 */
	protected function isFieldSortable( $field ) {
		// no index for sorting exists
		return false;
	}
}
