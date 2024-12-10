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
 * @copyright Copyright ©2023-2024, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager;

use EmailReplyParser\Parser\EmailParser;
use MediaWiki\Extension\VisualData\Importer as VisualDataImporter;
use RequestContext;
use Title;

if ( is_readable( __DIR__ . '/../../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../../vendor/autoload.php';
}

class ImportMessage {

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
		$mailbox = new Mailbox( $params['mailbox'], $errors );

		if ( !$mailbox ) {
			return false;
		}

		$uid = $params['uid'];
		$folder = $params['folder'];

		$imapMailbox = $mailbox->getImapMailbox();

		$imapMailbox->switchMailbox( $folder );

		$imapMailbox->setAttachmentsIgnore( !( (bool)$params['download_attachments'] ) );

		$mail = $imapMailbox->getMail( $uid );

		$obj = json_decode( json_encode( $mail ), true );

		// remove $obj['*dataInfo'], ...
		foreach ( $obj as $key => $value ) {
			if ( $key[0] === '*' ) {
				unset( $obj[$key] );
			}
		}

		$allContacts = [];
		// replace the unwanted format
		// email => name with "name <email>"
		foreach ( [ 'to', 'cc', 'bcc', 'replyTo' ] as $value ) {
			$formattedRecipients = [];
			foreach ( $obj[$value] as $k => $v ) {
				$formattedRecipients[] = ( $v ? "$v <$k>" : $k );

				if ( !array_key_exists( $k, $allContacts ) || !empty( $v ) ) {
					$allContacts[$k] = $v;
				}
			}
			$obj[$value] = $formattedRecipients;
		}

		$attachments = $mail->getAttachments();
		$attachments = json_decode( json_encode( $attachments ), true );

		$parsedEmail = ( new EmailParser() )->parse( $mail->textPlain );

		$obj['textPlain'] = $mail->textPlain;
		$obj['textHtml'] = $mail->textHtml;
		$obj['visible_text'] = $parsedEmail->getVisibleText();
		$obj['attachments'] = array_values( $attachments );
		$obj['hasAttachments'] = count( $obj['attachments'] ) ? true : false;

		$showMsg = static function ( $msg ) {
			echo $msg . PHP_EOL;
		};

		$pagenameFormula = $params['message_pagename_formula'];

		$categories = [];
		if ( !$this->applyFilters( $obj, $pagenameFormula, $categories ) ) {
			echo 'skip message ' . $uid . PHP_EOL;
			return;
		}

		$pagenameFormula = str_replace( '<folder_name>', $params['folder_name'], $pagenameFormula );

		$pagenameFormula = \ContactManager::replaceFormula( $obj, $pagenameFormula,
			$GLOBALS['wgContactManagerSchemasIncomingMail'] );

		$pagenameFormula = \ContactManager::replaceFormula( $obj['headers'], $pagenameFormula,
			$GLOBALS['wgContactManagerSchemasIncomingMail'] . '/headers' );

		// echo 'pagenameFormula: ' . $pagenameFormula . "\n";

		// mailbox article
		$title_ = Title::newFromID( $params['pageid'] );
		$context = RequestContext::getMain();
		$context->setTitle( $title_ );
		$output = $context->getOutput();
		$output->setTitle( $title_ );

		$pagenameFormula = \ContactManager::parseWikitext( $output, $pagenameFormula );

		$schema = $GLOBALS['wgContactManagerSchemasIncomingMail'];
		$options = [
			'main-slot' => true,
			'limit' => INF,
			'category-field' => 'categories'
		];
		$importer = new VisualDataImporter( $user, $context, $schema, $options );

		//
		$obj['categories'] = $categories;

		$importer->importData( $pagenameFormula, $obj, $showMsg );
		$title = Title::newFromText( $pagenameFormula );

		if ( $title->getArticleID() === 0 ) {
			throw new MWException( 'article title not set' );
		}

		$attachmentsFolder = \ContactManager::getAttachmentsFolder();
		$pathTarget = $attachmentsFolder . '/' . $title->getArticleID();

		if ( $obj['hasAttachments'] ) {
			mkdir( $pathTarget, 0777, true );
			echo '$pathTarget ' . $pathTarget . PHP_EOL;
		}

		foreach ( $obj['attachments'] as $value ) {
			rename( $attachmentsFolder . '/' . $value['name'], $pathTarget . '/' . $value['name'] );
			$this->handleUpload( $value );
		}

		$categories = [
			'Contacts from ' . $params['mailbox']
		];

		foreach ( $allContacts as $email => $name ) {
			\ContactManager::saveContact( $user, $context, $name, $email, $categories );
		}
	}

	/**
	 * @param array $obj
	 * @param string &$pagenameFormula
	 * @param array &$categories
	 * @return bool
	 */
	private function applyFilters( $obj, &$pagenameFormula, &$categories ) {
		$params = $this->params;

		foreach ( $params['filters_by_message_fields'] as $value ) {
			$value_ = $obj[$v['field']];

			$result_ = false;
			switch ( $v['field'] ) {
				case 'id':
				case 'attachments/type':
				case 'attachments/encoding':
				case 'attachments/sizeInBytes':
					$value_ = (int)$value_;
					$result_ = ( $value_ >= $v['number_from']
						&& $value_ <= $v['number_to'] );
					break;
				case 'imapPath':
				case 'mailboxFolder':
				case 'headersRaw':
				case 'headers/subject':
				case 'headers/Subject':
				case 'headers/message_id':
				case 'headers/toaddress':
				case 'headers/fromaddress':
				case 'headers/ccaddress':
				case 'headers/reply_toaddress':
				case 'headers/senderaddress':
				case 'mimeVersion':
				case 'xVirusScanned':
				case 'organization':
				case 'contentType':
				case 'xMailer':
				case 'contentLanguage':
				case 'xSenderIp':
				case 'priority':
				case 'importance':
				case 'sensitivity':
				case 'autoSubmitted':
				case 'precedence':
				case 'failedRecipients':
				case 'subject':
				case 'fromHost':
				case 'fromName':
				case 'fromAddress':
				case 'senderHost':
				case 'senderName':
				case 'senderAddress':
				case 'xOriginalTo':
				case 'toString':
				case 'ccString':
				case 'messageId':
				case 'textPlain':
				case 'textHtml':
				case 'visible_text':
				case 'attachments/id':
				case 'attachments/contentId':
				case 'attachments/subtype':
				case 'attachments/description':
				case 'attachments/name':
				case 'attachments/disposition':
				case 'attachments/charset':
				case 'attachments/emlOrigin':
				case 'attachments/fileInfoRaw':
				case 'attachments/fileInfo':
				case 'attachments/mime':
				case 'attachments/mimeEncoding':
				case 'attachments/fileExtension':
				case 'attachments/mimeType':
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
				case 'headers/date':
				case 'headers/Date':
					$value_ = strtotime( $value_ );
					$result_ = ( $value_ >= strtotime( $v['date_from'] )
						&& $value_ <= strtotime( $v['date_to'] ) );
					break;

				case 'isSeen':
				case 'isAnswered':
				case 'isRecent':
				case 'isFlagged':
				case 'isDeleted':
				case 'isDraft':
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
						if ( !empty( $v['message_pagename_formula'] ) ) {
							$pagenameFormula = $v['message_pagename_formula'];
						}

						if ( !empty( $v['categories'] ) ) {
								$categories = array_merge( $categories, $v['categories'] );
						}
				}
			}
		}

		return true;
	}

	/**
	 * @param array $value
	 */
	public function handleUpload( $value ) {
		global $wgFileExtensions;

		// text/plain; charset=us-ascii
		$mime = explode( ';', $value['mime'] );
		$mime = $mime[0];

		$ext = pathinfo( $value['name'], PATHINFO_EXTENSION );

		if ( !in_array( $ext, $wgFileExtensions ) ) {
			return;
		}

		// @TODO
		// import file in the wiki
	}

}
