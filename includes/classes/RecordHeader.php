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

namespace MediaWiki\Extension\ContactManager;

use Email\Parse as EmailParse;
use MediaWiki\Extension\VisualData\Importer as VisualDataImporter;
use RequestContext;
use Title;

if ( is_readable( __DIR__ . '/../../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../../vendor/autoload.php';
}

class RecordHeader {

	/** @var User */
	private $user;

	/** @var array */
	private $params;

	/**
	 * @param User $user
	 * @param array $params
	 * @param array &$errors []
	 */
	public function __construct( $user, $params, &$errors = [] ) {
		$this->user = $user;
		$this->params = $params;
	}

	/**
	 * @return bool
	 */
	public function doImport() {
		$params = $this->params;
		$user = $this->user;
		$errors = [];

		$showMsg = static function ( $msg ) {
			echo $msg . PHP_EOL;
		};

		$title = Title::newFromID( $params['pageid'] );
		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$output = $context->getOutput();

		$headerPagenameFormula_ = $params['header_pagename_formula'];
		$header = $params['obj'];
		$folder = $params['folder'];
		$obj = $header;

		$categories_ = $params['categories'];
		if ( !$this->applyFilters( $obj, $headerPagenameFormula_, $categories_ ) ) {
			echo 'skip message ' . $header['uid'] . PHP_EOL;
			return;
		}

		$headerPagenameFormula_ = str_replace( '<folder_name>', $folder['folder_name'], $headerPagenameFormula_ );

		$headerPagenameFormula_ = \ContactManager::replaceFormula( $obj, $headerPagenameFormula_,
		$GLOBALS['wgContactManagerSchemasMessageHeader'] );

		$headerPagenameFormula_ = \ContactManager::parseWikitext( $output, $headerPagenameFormula_ );

		$schema_ = $GLOBALS['wgContactManagerSchemasMessageHeader'];
		$options_ = [
			'main-slot' => true,
			'limit' => INF,
			'category-field' => 'categories'
		];
		$importer = new VisualDataImporter( $user, $context, $schema_, $options_ );

		$obj['categories'] = $categories_;

		$importer->importData( $headerPagenameFormula_, $obj, $showMsg );
		$title_ = Title::newFromText( $headerPagenameFormula_ );

		if ( $title_->getArticleID() === 0 ) {
			throw new MWException( 'article title not set' );
		}

		if ( !empty( $params['save_contacts'] ) ) {
			$allContacts = [];
			foreach ( [ 'to', 'from' ] as $value ) {
				$parsed_ = EmailParse::getInstance()->parse( $obj[$value] );
				if ( $parsed_['success'] ) {
					foreach ( $parsed_['email_addresses'] as $v_ ) {
						$allContacts[$v_['simple_address']] = $v_['name'];
					}
				}
			}
			foreach ( $allContacts as $email => $name ) {
				\ContactManager::saveContact( $user, $context, $name, $email, $categories_ );
			}
		}
	}

	/**
	 * @param obj $obj
	 * @param string &$pagenameFormula
	 * @param array &$categories
	 * @return bool
	 */
	private function applyFilters( $obj, &$pagenameFormula, &$categories ) {
		$params = $this->params;
		foreach ( $params['filters_by_headers'] as $v ) {
			$result_ = false;
			$value_ = $obj[$v['header']];
			switch ( $v['header'] ) {
				case 'size':
				case 'uid':
				case 'msgno':
					$value_ = (int)$value_;
					$result_ = ( $value_ >= $v['number_from']
						&& $value_ <= $v['number_to'] );
					break;
				case 'subject':
				case 'from':
				case 'to':
				case 'message_id':
				case 'references':
				case 'in_reply_to':
					$value_ = (string)$value_;
					switch ( $v['match'] ) {
						case 'contains':
							$result_ = strpos( $value_, $v['value_text'] ) !== false;
							break;
						case 'does not contain':
							$result_ = strpos( $value_, $v['value_text'] ) === false;
							break;
						case 'regex':
							$result_ = preg_match( '/' . preg_quote( $v['value_text'], '/' ) . '/', $value_ );
							break;
					}
					break;
				case 'date':
				case 'udate':
					$value_ = strtotime( $value_ );
					$result_ = ( $value_ >= strtotime( $v['date_from'] )
						&& $value_ <= strtotime( $v['date_to'] ) );
					break;
				case 'recent':
				case 'flagged':
				case 'answered':
				case 'deleted':
				case 'seen':
				case 'draft':
					$value_ = (bool)$value_;
					$result_ = $v['value_boolean'];
					break;
			}

			// apply filter
			if ( $result_ ) {
				switch ( $v['action'] ) {
					case 'skip':
						return false;
					default:
						if ( !empty( $v['pagename_formula'] ) ) {
							$pagenameFormula = $v['pagename_formula'];
						}

						if ( !empty( $v['categories'] ) ) {
							$categories = array_merge( $categories, $v['categories'] );
						}
				}
			}
		}

		return true;
	}
}
