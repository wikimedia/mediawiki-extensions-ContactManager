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

use MediaWiki\Extension\ContactManager\Special\Tracking as TrackingSpecial;
use MediaWiki\Linker\LinkRenderer;
use TablePager;

class Details extends TablePager {

	/** @var Request */
	private $request;

	/** @var MediaWiki\Extension\ContactManager\Special\BrowseTracking */
	private $parentClass;

	/** @var int */
	private $itemId;

	// @IMPORTANT!, otherwise the pager won't show !
	/** @var mLimit */
	public $mLimit = 20;

	/**
	 * @inheritDoc
	 */
	public function __construct( $parentClass, $request, LinkRenderer $linkRenderer ) {
		parent::__construct( $parentClass->getContext(), $linkRenderer );

		$this->request = $request;
		$this->parentClass = $parentClass;
		$this->itemId = $parentClass->par;

		$dbr = \VisualData::getDB( DB_REPLICA );
		if ( !$dbr->tableExists( 'contactmanager_tracking_' . $this->request->getVal( 'mailer' ) ) ) {
			throw new \MWException( 'no mailer-specific table' );
		}
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
		switch ( $this->getRequest()->getVal( 'mailer' ) ) {
			case 'sendgrid':
				$columns = TrackingSpecial::$sendgridColumns;
				break;
		}

		$headers = [];
		foreach ( $columns as $col ) {
			// $headers[$col] = $this->msg( "contactmanager-browsetracking-pager-header-$col" )->text();
			$headers[$col] = $col;
		}

		unset( $headers['id'], $headers['page_id'], $headers['email'] );

		return $headers;
	}

	/**
	 * @inheritDoc
	 */
	public function formatValue( $field, $value ) {
		/** @var object $row */
		$row = $this->mCurrentRow;

		switch ( $field ) {
			case 'created_at':
				$formatted = htmlspecialchars(
					$this->getLanguage()->userTimeAndDate(
						wfTimestamp( TS_MW, $row->created_at ),
						$this->getUser()
					)
				);
				break;

			default:
				$formatted = $row->$field;
		}

		return $formatted;
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryInfo() {
		$ret = [];

		$tables = [ 'contactmanager_tracking_' . $this->request->getVal( 'mailer' ) ];
		$fields = [ '*' ];
		$join_conds = [];
		$conds = [
			'page_id' => $this->request->getVal( 'page_id' ),
			'email' => $this->request->getVal( 'email' ),
		];
		$options = [];

		$searchSubjects = [ 'event', 'sg_event_id', 'sg_message_id' ];
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
