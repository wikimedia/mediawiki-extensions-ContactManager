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
use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;
use MediaWiki\Extension\VisualData\Importer as VisualDataImporter;
// use MWException;
use RequestContext;

if ( is_readable( __DIR__ . '/../../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../../vendor/autoload.php';
}

class RecordHeader {

	/** @var User */
	private $user;

	/** @var array */
	private $params;

	/** @var array */
	private $mailboxData;

	/** @var array */
	private $errors;

	/**
	 * @param User $user
	 * @param array $mailboxData
	 * @param array $params
	 * @param array &$errors []
	 */
	public function __construct( $user, $mailboxData, $params, &$errors = [] ) {
		$this->user = $user;
		$this->mailboxData = $mailboxData;
		$this->params = $params;
		$this->errors = &$errors;
	}

	/**
	 * @return array|int
	 */
	public function doImport() {
		$params = $this->params;
		$user = $this->user;

		$showMsg = static function ( $msg ) {
			echo $msg . PHP_EOL;
		};

		$title = TitleClass::newFromID( $params['pageid'] );
		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$output = $context->getOutput();

		// header obj
		$obj = $params['obj'];
		$folder = $params['folder'];

		// may be overridden by $this->applyFilters
		$pagenameFormula = \ContactManager::replaceParameter( 'ContactManagerHeaderPagenameFormula',
			$params['mailbox'],
			$folder['folder_name'],
			'<ContactManager/Message header/uid>'
		);

		$categories_ = [];
		if ( !$this->applyFilters( $obj, $pagenameFormula, $categories_ ) ) {
			echo 'skipped by filter' . PHP_EOL;
			return \ContactManager::SKIPPED_ON_FILTER;
		}

		// only if provided from applyFilters
		$pagenameFormula = str_replace( '<folder_name>', $folder['folder_name'], $pagenameFormula );

		$pagenameFormula = \ContactManager::replaceFormula( $obj, $pagenameFormula,
			$GLOBALS['wgContactManagerSchemasMessageHeader'] );

		$pagenameFormula = \ContactManager::parseWikitext( $output, $pagenameFormula );

		$title_ = TitleClass::newFromText( $pagenameFormula );

		if ( !$title_ ) {
			$this->errors[] = 'invalid title';
			echo '***skipped on error' . PHP_EOL;
			return \ContactManager::SKIPPED_ON_ERROR;
		}

		if ( $title_->isKnown() && !empty( $params['ignore_existing'] ) ) {
			echo 'skipped as existing' . PHP_EOL;
			return \ContactManager::SKIPPED_ON_EXISTING;
		}

		$schema_ = $GLOBALS['wgContactManagerSchemasMessageHeader'];
		$options_ = [
			'main-slot' => true,
			'limit' => INF,
			'category-field' => 'categories'
		];
		$importer = new VisualDataImporter( $user, $context, $schema_, $options_ );

		$obj['categories'] = $categories_;

		$retHeader = $importer->importData( $pagenameFormula, $obj, $showMsg );

		if ( !is_array( $retHeader ) ) {
			$this->errors[] = 'import failed';
			echo '***skipped on error' . PHP_EOL;
			return \ContactManager::SKIPPED_ON_ERROR;
		}

		// ***important, get title object again
		$title_ = TitleClass::newFromText( $pagenameFormula );

		$retContacts = [];
		if ( !empty( $params['save_contacts'] ) ) {
			$allContacts = [];
			foreach ( [ 'to', 'from' ] as $value ) {
				$parsed_ = EmailParse::getInstance()->parse( $obj[$value] );
				if ( $parsed_['success'] ) {
					foreach ( $parsed_['email_addresses'] as $v_ ) {
						$v_['simple_address'] = strtolower( $v_['simple_address'] );
						$allContacts[$v_['simple_address']] = $v_['name'];
					}
				}
			}

			foreach ( $allContacts as $email => $name ) {
				$ret_ = \ContactManager::saveUpdateContact( $user, $context, $params, $obj, $name, $email );
				if ( is_string( $ret_ ) && $ret_ ) {
					$retContacts[] = $ret_;
				}
			}
		}

		return [ $retHeader, $retContacts ];
	}

	/**
	 * @param obj $obj
	 * @param string &$pagenameFormula
	 * @param array &$categories
	 * @return bool
	 */
	private function applyFilters( $obj, &$pagenameFormula, &$categories ) {
		$params = $this->params;

		if ( !array_key_exists( 'filters_by_headers', $params ) ) {
			$params['filters_by_headers'] = [];
		}

		foreach ( (array)$params['filters_by_headers'] as $v ) {
			if ( !array_key_exists( 'header', $v ) || empty( $v['header'] ) ) {
				continue;
			}

			if ( !array_key_exists( $v['header'], $obj ) ) {
				echo 'error, ignoring filter ' . $v['header'] . PHP_EOL;
				continue;
			}

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
							$result_ = preg_match( '/' . str_replace( '/', '\/', $v['value_text'] ) . '/', $value_ );
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
				echo 'matching filter ' . $value_ . ' on ' . $v['header'] . PHP_EOL;

				switch ( $v['action'] ) {
					case 'skip':
						echo 'skipping message' . PHP_EOL;
						return false;
					default:
						if ( !empty( $v['pagename_formula'] ) ) {
							$pagenameFormula = $v['pagename_formula'];
							echo 'new pagenameFormula ' . $pagenameFormula . PHP_EOL;
						}

						if ( !empty( $v['categories'] ) ) {
							$categories = array_merge( $categories, $v['categories'] );
							echo 'apply categories ' . implode( ', ', $categories ) . PHP_EOL;
						}
				}
			}
		}

		return true;
	}
}
