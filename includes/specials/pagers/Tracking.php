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
use MediaWiki\Linker\LinkRenderer;
use TablePager;

class Tracking extends TablePager {

	/** @var Request */
	private $request;

	/** @var MediaWiki\Extension\ContactManager\Special\BrowseTracking */
	private $parentClass;

	/** @var int */
	private $itemId;

	// @IMPORTANT!, otherwise the pager won't show !
	/** @var mLimit */
	public $mLimit = 40;

	/** @var \Wikimedia\Rdbms\DBConnRef */
	public $dbr;

	/**
	 * @inheritDoc
	 */
	public function __construct( $parentClass, $request, LinkRenderer $linkRenderer ) {
		parent::__construct( $parentClass->getContext(), $linkRenderer );

		$this->request = $request;
		$this->parentClass = $parentClass;
		$this->itemId = $parentClass->par;
		$this->dbr = \VisualData::getDB( DB_REPLICA );
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
			'email' => 'contactmanager-browsetracking-pager-header-email',
			'name' => 'contactmanager-browsetracking-pager-header-name',
			'event' => 'contactmanager-browsetracking-pager-header-event',
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
			case 'name':
			case 'email':
			case 'event':
				$formatted = $row->$field;
				break;

			case 'created_at':
				$formatted = htmlspecialchars(
					$this->getLanguage()->userTimeAndDate(
						wfTimestamp( TS_MW, $row->created_at ),
						$this->getUser()
					)
				);
				break;

			case 'actions':
				$link = '<span class="mw-ui-button mw-ui-progressive">' .
					$this->msg( 'contactmanager-browsetracking-table-button-details' )->text() . '</span>';
				$title_ = \SpecialPage::getTitleFor( 'ContactManagerBrowseTracking', $this->itemId );

				$query = '[[name::' . $this->request->getVal( 'account' ) . ']]';
				$schema = $GLOBALS['wgContactManagerSchemasMailer'];
				$results = \VisualData::getQueryResults( $schema, $query );

				if ( array_key_exists( 'errors', $results ) ) {
					$formatted = 'query error: ' . $results['errors'];

				} elseif ( count( $results ) && !empty( $results[0]['data'] ) ) {
					$mailer = $results[0]['data']['provider'];
					if ( $this->dbr->tableExists( "contactmanager_tracking_$mailer" ) ) {
						$query = [
							'view' => 'Details',
							'page_id' => $row->page_id,
							'email' => $row->email,
							'account' => $this->request->getVal( 'account' ),
							'mailer' => $mailer
						];
						$formatted = Linker::link( $title_, $link, [], $query );
					}

				} else {
					$formatted = '';
				}
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

		$tables = [ 'contactmanager_tracking' ];
		$fields = [ '*' ];
		$join_conds = [];
		// $conds = [ 'notification_id' => $this->notificationId ];
		$conds = [];
		$options = [];

		array_unique( $tables );

		$searchSubjects = [ 'email', 'name' ];
		foreach ( $searchSubjects as $subject ) {
			$value_ = $this->request->getVal( $subject );
			if ( !empty( $value_ ) ) {
				$conds[ $subject ] = $value_;
			}
		}

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
