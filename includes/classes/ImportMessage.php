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
 * @copyright Copyright ©2023-2025, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager;

use EmailReplyParser\Parser\EmailParser;
use LanguageDetection\Language;
use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;
use MediaWiki\Extension\VisualData\Importer as VisualDataImporter;
use RequestContext;

if ( is_readable( __DIR__ . '/../../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../../vendor/autoload.php';
}

class ImportMessage {

	/** @var User */
	private $user;

	/** @var array */
	private $params;

	/** @var array */
	private $mailboxData;

	/** @var array */
	private $errors;

	/** @var MediaWiki\Extension\ContactManager\Mailbox */
	private $mailbox;

	/**
	 * @param User $user
	 * @param MediaWiki\Extension\ContactManager\Mailbox $mailbox
	 * @param array $mailboxData
	 * @param array $params
	 * @param array &$errors []
	 */
	public function __construct( $user, $mailbox, $mailboxData, $params, &$errors = [] ) {
		$this->user = $user;
		$this->mailbox = $mailbox;
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
		$imapMailbox = $this->mailbox->getImapMailbox();

		if ( !$imapMailbox ) {
			$this->errors[] = 'no imap mailbox';
			echo '***skipped on error' . PHP_EOL;
			return \ContactManager::SKIPPED_ON_ERROR;
		}

		$uid = $params['uid'];
		$folder = $params['folder'];

		// *** not necessary, but ensure doImport is called from
		// the right folder
		// $imapMailbox->switchMailbox( $folder['folder'] );

		// *** attention, this is empty if called from
		// 'get message' and the toggle 'fetch message'
		// is false in the ContactManager/Retrieve messages form
		if ( !array_key_exists( 'download_attachments', $params ) ) {
			$params['download_attachments'] = false;
		}

		$this->createFolderArticle( $user, $params );

		$imapMailbox->setAttachmentsIgnore( empty( $params['download_attachments'] ) );

		if ( !empty( $params['download_attachments'] ) ) {
			$attachmentsFolder = \ContactManager::getAttachmentsFolder();

			if ( !file_exists( $attachmentsFolder ) ) {
				if ( !mkdir( $attachmentsFolder, 0777, true ) ) {
					echo '***error cannot create attachments folder ' . $attachmentsFolder . PHP_EOL;
				}
			}
		}

		// *** optioanlly save the email as eml format
		// $imapMailbox->getRawMail( $uid, false );
		// and parse using mail-mime-parser

		// alternative libraries:
		// IMAP:
		// https://github.com/ddeboer/imap
		// https://github.com/Webklex/php-imap
		// MIME parser:
		// https://github.com/zbateson/mail-mime-parser
		// https://github.com/php-mime-mail-parser/php-mime-mail-parser

		$mail = $imapMailbox->getMail( $uid, false );

		$obj = json_decode( json_encode( $mail ), true );

		// remove $obj['*dataInfo'], ...
		foreach ( $obj as $key => $value ) {
			if ( $key[0] === '*' ) {
				unset( $obj[$key] );
			}
		}

		$decode = [
			'headers' => [
				'subject',
				'Subject',
				'toaddress',
				'to' => [
					'x' => [ 'personal' ]
				],
				'fromaddress',
				'from' => [
					'x' => [ 'personal' ]
				],
				'reply_toaddress',
				'reply_to' => [
					'x' => [ 'personal' ]
				],
				'senderaddress',
				'sender' => [
					'x' => [ 'personal' ]
				],
			]
		];

		// decodeMimeStr of the items above
		$recIterator = static function ( &$arr1, $arr2 ) use ( &$recIterator, &$imapMailbox ) {
			foreach ( $arr1 as $key => $value ) {
				if ( is_array( $value ) ) {
					$key_ = ( is_int( $key ) && array_key_exists( 'x', $arr2 ) ?
						'x' : $key );
					if ( array_key_exists( $key_, $arr2 ) ) {
						$recIterator( $arr1[$key], $arr2[$key_] );
					}
				} elseif ( !empty( $value ) && in_array( $key, $arr2 ) ) {
					$arr1[$key] = $imapMailbox->decodeMimeStr( $value );
				}
			}
		};
		$recIterator( $obj, $decode );

		$allContacts = [];
		$obj['fromAddress'] = strtolower( $obj['fromAddress'] );
		$allContacts[$obj['fromAddress']] = $obj['fromName'];

		// replace the unwanted format
		// email => name with "name <email>"
		foreach ( [ 'to', 'cc', 'bcc', 'replyTo' ] as $value ) {
			$formattedRecipients = [];
			foreach ( $obj[$value] as $addresss_ => $name_ ) {
				if ( empty( $addresss_ ) ) {
					echo '*** warning, address is empty' . PHP_EOL;
					print_r( $obj[$value] );
					continue;
				}

				// @see https://datatracker.ietf.org/doc/html/rfc5321#section-2.4
				$addresss_ = strtolower( $addresss_ );

				if ( !empty( $name_ ) ) {
					$name_ = trim( $name_, '"' );
				}
				$formattedRecipients[] = ( $name_ ? "$name_ <$addresss_>" : $addresss_ );

				if ( !array_key_exists( $addresss_, $allContacts ) || !empty( $name_ ) ) {
					$allContacts[$addresss_] = $name_;
				}
			}
			$obj[$value] = $formattedRecipients;
		}

		$attachmentsObj = $mail->getAttachments();

		// all attachments
		$attachments = json_decode( json_encode( $attachmentsObj ), true );

		if ( count( $attachments ) ) {
			echo count( $attachments ) . ' attachments' . PHP_EOL;

			[ $regularAttachments, $inlineAttachments ] = $this->getAttachmentsType( $mail->textHtml, $attachmentsObj );

			foreach ( $attachments as $k => $v ) {
				$attachments[$k]['name'] = $imapMailbox->decodeMimeStr( $v['name'] );
			}

			$obj['regularAttachments'] = [];
			foreach ( $regularAttachments as $v ) {
				$obj['regularAttachments'][] = $imapMailbox->decodeMimeStr( $v->name );
			}
		}

		$parsedEmail = ( new EmailParser() )->parse( $mail->textPlain );

		$obj['textPlain'] = $mail->textPlain;
		$obj['textHtml'] = $mail->textHtml;

		// custom entries
		$obj['visibleText'] = $parsedEmail->getVisibleText();
		$obj['attachments'] = array_values( $attachments );
		$obj['hasAttachments'] = count( $obj['attachments'] ) ? true : false;

		// get Delivered-To (only for inbox)
		$deliveredTo = $imapMailbox->getMailHeaderFieldValue( $mail->headersRaw, 'Delivered-To' );
		$obj['deliveredTo'] = $deliveredTo;

		$conversationRecipients = $allContacts;

		// this will remove the mailbox related address from $conversationRecipients
		// by reference
		[ $conversationHash, $mailboxRelatedAddress ] = $this->getConversationHashAndRelatedAddress( $params, $obj, $conversationRecipients );
		$obj['conversationHash'] = $conversationHash;

		$showMsg = static function ( $msg ) {
			echo $msg . PHP_EOL;
		};

		$pagenameFormula = \ContactManager::replaceParameter( 'ContactManagerMessagePagenameFormula',
			$params['mailbox'],
			$folder['folder_name'],
			'<ContactManager/Incoming mail/id>'
		);

		$categories = ( array_key_exists( 'categories', $params )
			&& is_array( $params['categories'] ) ? $params['categories'] : [] );

		if ( strtolower( $params['folder']['folder_type'] ) === 'inbox' &&
			in_array( $obj['fromAddress'], $this->mailboxData['all_addresses'] )
		) {
			$categories[] = 'Messages in wrong folder';
		}

		if ( !$this->applyFilters( $obj, $pagenameFormula, $categories ) ) {
			echo 'skipped by filter' . PHP_EOL;
			return \ContactManager::SKIPPED_ON_FILTER;
		}

		$pagenameFormula = str_replace( '<folder_name>', $folder['folder_name'], $pagenameFormula );

		$pagenameFormula = \ContactManager::replaceFormula( $obj, $pagenameFormula,
			$GLOBALS['wgContactManagerSchemasIncomingMail'] );

		$pagenameFormula = \ContactManager::replaceFormula( $obj['headers'], $pagenameFormula,
			$GLOBALS['wgContactManagerSchemasIncomingMail'] . '/headers' );

		// echo 'pagenameFormula: ' . $pagenameFormula . "\n";

		// mailbox article
		$title = TitleClass::newFromID( $params['pageid'] );
		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$output = $context->getOutput();
		$output->setTitle( $title );

		$pagenameFormula = \ContactManager::parseWikitext( $output, $pagenameFormula );

		if ( empty( $pagenameFormula ) ) {
			// throw new \MWException( 'invalid title' );
			$this->errors[] = 'empty pagename formula';
			echo '***skipped on error' . PHP_EOL;
			return \ContactManager::SKIPPED_ON_ERROR;
		}

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

		$schema = $GLOBALS['wgContactManagerSchemasIncomingMail'];
		$options = [
			'main-slot' => true,
			'limit' => INF,
			'category-field' => 'categories'
		];
		$importer = new VisualDataImporter( $user, $context, $schema, $options );

		$obj['categories'] = $categories;

		$retMessage = $importer->importData( $pagenameFormula, $obj, $showMsg );

		if ( !is_array( $retMessage ) || !count( $retMessage ) ) {
			$this->errors[] = 'import failed';
			echo '***skipped on error' . PHP_EOL;
			return \ContactManager::SKIPPED_ON_ERROR;
		}

		// update the delivered-to list
		if ( !empty( $deliveredTo ) ) {
			$mailboxData = $this->mailboxData['data'];
			if ( !array_key_exists( 'delivered-to', $mailboxData )
				|| !is_array( $mailboxData['delivered-to'] )
			) {
				$mailboxData['delivered-to'] = [];
			}
			if ( !in_array( $deliveredTo, $mailboxData['delivered-to'] ) ) {
				$schema_ = $GLOBALS['wgContactManagerSchemasMailbox'];
				$query_ = '[[name::' . $params['mailbox'] . ']]';
				$mailboxData['delivered-to'][] = $deliveredTo;
				$jsonData_ = [
					$GLOBALS['wgContactManagerSchemasMailbox'] => $mailboxData
				];
				$title_ = TitleClass::newFromText( $this->mailboxData['title'] );
				\VisualData::updateCreateSchemas( $user, $title_, $jsonData_ );
			}
		}

		// ***important, get title object again
		$title_ = TitleClass::newFromText( $pagenameFormula );

		if ( $obj['hasAttachments'] ) {
			$pathTarget = $attachmentsFolder . '/' . $title_->getArticleID();
			echo 'attachment path ' . $pathTarget . PHP_EOL;

			if ( !is_dir( $pathTarget ) ) {
				if ( !mkdir( $pathTarget, 0777, true ) ) {
					echo '***error cannot create folder ' . $pathTarget . PHP_EOL;
				}
			}

			if ( file_exists( $pathTarget ) ) {
				foreach ( $regularAttachments as $value ) {
					$dest_ = $pathTarget . '/' . $value->name;
					if ( rename( $value->__get( 'filePath' ), $dest_ ) ) {
						echo 'saving attachment to ' . $dest_ . PHP_EOL;
					} else {
						echo '***error saving attachment to ' . $dest_ . PHP_EOL;
					}
				}

				foreach ( $inlineAttachments as $value ) {
					$dest_ = $pathTarget . '/' . ( !empty( $value->contentId ) ? $value->contentId : $value->name );
					if ( rename( $value->__get( 'filePath' ), $dest_ ) ) {
						echo 'saving inline attachment to ' . $dest_ . PHP_EOL;
					} else {
						echo '***error saving inline attachment to ' . $dest_ . PHP_EOL;
					}
				}

			} else {
				echo "cannot access folder \"$pathTarget\"" . PHP_EOL;
				$this->errors[] = "cannot access folder \"$pathTarget\"";
			}
		}

		// language detect @see https://github.com/patrickschur/language-detection
		$ld = new Language;
		$ld->setMaxNgrams( 5000 );
		$detectedLanguages = $ld->detect( $obj['textPlain'] )->close();
		$detectedLanguage = ( count( $detectedLanguages ) ? array_key_first( $detectedLanguages ) : null );

		$retContacts = [];
		$retConversation = [];
		if ( !empty( $params['save_contacts'] ) ) {
			foreach ( $allContacts as $email => $name ) {
				// do not include in the conversation's participants the mailbox related address
				// it will be added separately
				$conversationHash_ = ( array_key_exists( $email, $conversationRecipients ) ? $conversationHash : null );

				$ret_ = \ContactManager::saveUpdateContact( $user, $context, $params, $obj, $name, $email, $conversationHash_,
					( $email === $obj['fromAddress'] ? $detectedLanguage : null ) );

				if ( is_string( $ret_ ) && $ret_ ) {
					$retContacts[] = $ret_;
				}
			}

			$ret_ = $this->saveConversation( $user, $context, $params, $conversationHash,
				$deliveredTo, $conversationRecipients, $mailboxRelatedAddress, $obj['date'] );

			if ( is_string( $ret_ ) && $ret_ ) {
				$retConversation[] = $ret_;
			}
		}

		return [ $retMessage, $retContacts, $retConversation ];
	}

	/**
	 * @param User $user
	 * @param array $params
	 */
	private function createFolderArticle( $user, $params ) {
		$folderTitleText = 'ContactManager:Mailboxes/' . $params['mailbox'] . '/folders/' . $params['folder']['folder_name'];
		$folderArticleTitle = TitleClass::newFromText( $folderTitleText );

		if ( $folderArticleTitle && $folderArticleTitle->isKnown() ) {
			\VisualData::purgeArticle( $folderArticleTitle );
			return;
		}

		$folderType = null;
		switch ( strtolower( $params['folder']['folder_type'] ) ) {
			case 'inbox':
			case 'sent':
			case 'draft':
			case 'trash':
			case 'spam':
				$folderType = $params['folder']['folder_type'];
				break;
			default:
				$folderType = 'other';
		}

		$folderType = ucfirst( $folderType );
		$folderArticleTemplateTitle = TitleClass::newFromText( 'ContactManager/Preload messages ' . $folderType );
		if ( $folderArticleTemplateTitle->isKnown() ) {
			$content = \VisualData::getWikipageContent( $folderArticleTemplateTitle );

		} else {
			$dirPath = __DIR__ . '/../../data';
			$filePath = "$dirPath/templates/Preload messages $folderType.txt";
			$content = file_get_contents( $filePath );
		}

		if ( empty( $content ) ) {
			$this->errors[] = 'cannot create folder page';
			return;
		}

		echo 'creating folder ' . $params['folder']['folder_name'] . PHP_EOL;

		$content = str_replace( [ '%mailbox%', '%folder_name%' ],
			[ $params['mailbox'], $params['folder']['folder_name'] ], $content );

		\VisualData::saveRevision( $user, $folderArticleTitle, $content );

		$mailboxArticleTitle = TitleClass::newFromText( 'ContactManager:Mailboxes/' . $params['mailbox'] );
		\VisualData::purgeArticle( $mailboxArticleTitle );
	}

	/**
	 * @param array $params
	 * @param array $obj
	 * @param array &$conversationRecipients
	 * @return array
	 */
	private function getConversationHashAndRelatedAddress( $params, $obj, &$conversationRecipients ) {
		ksort( $conversationRecipients );

		// get the hash before removing the related mailbox address
		// : and , are not supported in email address
		// use the mailbox as prefix to distinguish hashes
		// when more than 1 mailbox address appears among recipients
		$hash = dechex( crc32( $this->mailbox->getUsername() . ':' . implode( ',', array_keys( $conversationRecipients ) ) ) );

		foreach ( $this->mailboxData['all_addresses'] as $address ) {
			if ( array_key_exists( $address, $conversationRecipients ) ) {
				unset( $conversationRecipients[$address] );
			}
		}

		$relatedAddress = null;
		switch ( strtolower( $params['folder']['folder_type'] ) ) {
			case 'sent':
			case 'draft':
				$relatedAddress = $obj['fromAddress'];
				break;
			case 'spam':
			case 'other':
			case 'inbox':
			case 'trash':
			default:
				$relatedAddress = $obj['deliveredTo'];
				break;
		}

		if ( !$relatedAddress ) {
			$relatedAddress = $this->mailboxData['all_addresses'][count( $this->mailboxData['all_addresses'] ) - 1];
		}

		return [ $hash, $relatedAddress ];
	}

	/**
	 * @param User $user
	 * @param Context $context
	 * @param array $params
	 * @param string $hash
	 * @param string $deliveredTo
	 * @param array $conversationRecipients
	 * @param string $relatedAddress
	 * @param string $date
	 * @return bool|null|string
	 */
	private function saveConversation( $user, $context, $params, $hash, $deliveredTo, $conversationRecipients, $relatedAddress, $date ) {
		// sending to oneself
		if ( !count( $conversationRecipients ) ) {
			return;
		}

		$schema = $GLOBALS['wgContactManagerSchemasConversation'];
		$options = [
			'main-slot' => true,
			'limit' => INF,
			'category-field' => 'categories'
		];
		$importer = new VisualDataImporter( $user, $context, $schema, $options );

		$targetTitle_ = \ContactManager::replaceParameter( 'ContactManagerConversationPagenameFormula',
			$params['mailbox'],
			'~'
		);
		$query = "[[$targetTitle_]][[hash::$hash]]";
		$printouts = [
			'date_last',
			'date_first',
			'count'
		];
		$results = \VisualData::getQueryResults( $schema, $query, $printouts );

		if ( \ContactManager::queryError( $results, false ) ) {
			echo 'error query' . PHP_EOL;
			print_r( $results );
		}

		// use numeric increment
		$pagenameFormula = \ContactManager::replaceParameter( 'ContactManagerConversationPagenameFormula',
			$params['mailbox'],
			'#count'
		);

		$data = [
			'mailbox' => $params['mailbox'],
			'related_address' => $relatedAddress,
			'addresses' => array_keys( $conversationRecipients ),
			'hash' => $hash,
			'date_last' => null,
			'date_first' => null,
			'count' => null,
		];

		$exists = false;
		// merge previous entries
		if ( count( $results ) && !empty( $results[0]['data'] ) ) {
			$pagenameFormula = $results[0]['title'];
			$data = \VisualData::array_merge_recursive( $data, $results[0]['data'] );
			$exists = true;
		}

		$data = \VisualData::array_filter_recursive( $data, 'array_unique' );

		$messageDateTime = strtotime( $date );

		$date_last = ( !empty( $data['date_last'] ) ? strtotime( $data['date_last'] ) : 0 );
		$date_first = ( !empty( $data['date_first'] ) ? strtotime( $data['date_first'] ) : PHP_INT_MAX );

		if ( $messageDateTime > $date_last ) {
			$data['date_last'] = date( 'Y-m-d', $messageDateTime );
		}

		if ( $messageDateTime < $date_first ) {
			$data['date_first'] = date( 'Y-m-d', $messageDateTime );
		}

		// *** this is necessary only if we want to order the
		// conversations table by number of messages
		$schema = $GLOBALS['wgContactManagerSchemasIncomingMail'];

		$targetTitle_ = \ContactManager::replaceParameter( 'ContactManagerAllMessagesPagenameFormula',
			$params['mailbox'],
			'~'
		);
		$query = "[[$targetTitle_]][[conversationHash::$hash]]";
		$printouts = [];
		$params = [ 'format' => 'count' ];
		$count = \VisualData::getQueryResults( $schema, $query, $printouts, $params );

		if ( is_array( $count ) && \ContactManager::queryError( $count, false ) ) {
			echo 'error query' . PHP_EOL;
			print_r( $count );
		}

		if ( $count === -1 ) {
			echo 'error query' . PHP_EOL;
		}

		if ( !is_array( $count ) && $count !== -1 ) {
			$data['count'] = $count;
		}

		$showMsg = static function ( $msg ) {
			echo $msg . PHP_EOL;
		};

		$ret = $importer->importData( $pagenameFormula, $data, $showMsg );

		if ( !is_array( $ret ) || !count( $ret ) ) {
			return false;
		}

		return ( !$exists ? $pagenameFormula : null );
	}

	/**
	 * @param array $obj
	 * @param string &$pagenameFormula
	 * @param array &$categories
	 * @return bool
	 */
	private function applyFilters( $obj, &$pagenameFormula, &$categories ) {
		$params = $this->params;

		$obj = \ContactManager::flattenArray( $obj );

		// *** attention, this is empty if called from
		// 'get message' and the toggle 'fetch message'
		// is false in the ContactManager/Retrieve messages form
		if ( !array_key_exists( 'filters_by_message_fields', $params ) ) {
			$params['filters_by_message_fields'] = [];
		}

		foreach ( (array)$params['filters_by_message_fields'] as $v ) {
			if ( !array_key_exists( 'field', $v ) || empty( $v['field'] ) ) {
				continue;
			}

			if ( !array_key_exists( $v['field'], $obj ) ) {
				echo 'error, ignoring filter ' . $v['field'] . PHP_EOL;
				continue;
			}

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
				case 'visibleText':
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
							$result_ = preg_match( '/' . str_replace( '/', '\/', $v['value_text'] ) . '/', $value_ );
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
				echo 'matching filter ' . $value_ . ' on ' . $v['field'] . PHP_EOL;

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

	/**
	 * @param string $textHtml
	 * @param int $pageId
	 * @return string
	 */
	public static function replaceCidUrls( $textHtml, $pageId ) {
		$pattern = '/(<img[^>]+src=["\'])cid:([^\s\'"<>]{1,256})(["\'][^>]*>)/mi';
		$specialTitle = \SpecialPage::getTitleFor( 'ContactManagerGetResource', $pageId );

		return preg_replace_callback( $pattern, static function ( $matches ) use ( $specialTitle ) {
			$cid = $matches[2];
			$url = wfAppendQuery( $specialTitle->getLocalURL(),
				[ 'cid' => $cid ] );
			return $matches[1] . $url . $matches[3];
		}, $textHtml );
	}

	/**
	 * @see PhpImap\IncomingMail
	 * @param string $textHtml
	 * @param \PhpImap\IncomingMailAttachment $attachments
	 * @return array
	 */
	private function getAttachmentsType( $textHtml, $attachments ) {
		preg_match_all( '/\bcid:([^\s\'"<>]{1,256})/mi', $textHtml, $matches );
		$cidList = isset( $matches[1] ) ? array_unique( $matches[1] ) : [];

		$regular = [];
		$inline = [];
		foreach ( $attachments as $attachment ) {
			$disposition = \mb_strtolower( (string)$attachment->disposition );
			// @see https://github.com/barbushin/php-imap/issues/569
			if (
				in_array( $attachment->contentId, $cidList, true ) ||
				strtolower( $disposition ) === 'inline'
			) {
				$inline[] = $attachment;
			} else {
				$regular[] = $attachment;
			}
		}

		return [ $regular, $inline ];
	}

}
