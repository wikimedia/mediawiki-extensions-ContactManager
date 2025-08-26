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
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;

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

	/** @var array */
	public static $languageMap = [
		'ab' => 'Abkhaz',
		'af' => 'Afrikaans',
		'am' => 'Amharic',
		'ar' => 'Arabic',
		'ay' => 'Aymara',
		'az-Cyrl' => 'Azerbaijani, North (Cyrillic)',
		'az-Latn' => 'Azerbaijani, North (Latin)',
		'be' => 'Belarusan',
		'bg' => 'Bulgarian',
		'bi' => 'Bislama',
		'bn' => 'Bengali',
		'bo' => 'Tibetan',
		'br' => 'Breton',
		'bs-Cyrl' => 'Bosnian (Cyrillic)',
		'bs-Latn' => 'Bosnian (Latin)',
		'ca' => 'Catalan',
		'ch' => 'Chamorro',
		'co' => 'Corsican',
		'cr' => 'Cree',
		'cs' => 'Czech',
		'cy' => 'Welsh',
		'da' => 'Danish',
		'de' => 'German',
		'dz' => 'Dzongkha',
		'el-monoton' => 'Greek (monotonic)',
		'el-polyton' => 'Greek (polytonic)',
		'en' => 'English',
		'eo' => 'Esperanto',
		'es' => 'Spanish',
		'et' => 'Estonian',
		'eu' => 'Basque',
		'fa' => 'Persian',
		'fi' => 'Finnish',
		'fj' => 'Fijian',
		'fo' => 'Faroese',
		'fr' => 'French',
		'fy' => 'Frisian',
		'ga' => 'Gaelic, Irish',
		'gd' => 'Gaelic, Scottish',
		'gl' => 'Galician',
		'gn' => 'Guarani',
		'gu' => 'Gujarati',
		'ha' => 'Hausa',
		'he' => 'Hebrew',
		'hi' => 'Hindi',
		'hr' => 'Croatian',
		'hu' => 'Hungarian',
		'hy' => 'Armenian',
		'ia' => 'Interlingua',
		'id' => 'Indonesian',
		'ig' => 'Igbo',
		'io' => 'Ido',
		'is' => 'Icelandic',
		'it' => 'Italian',
		'iu' => 'Inuktitut',
		'ja' => 'Japanese',
		'jv' => 'Javanese',
		'ka' => 'Georgian',
		'km' => 'Khmer',
		'ko' => 'Korean',
		'kr' => 'Kanuri',
		'ku' => 'Kurdish',
		'la' => 'Latin',
		'lg' => 'Ganda',
		'ln' => 'Lingala',
		'lo' => 'Lao',
		'lt' => 'Lithuanian',
		'lv' => 'Latvian',
		'mh' => 'Marshallese',
		'mn-Cyrl' => 'Mongolian, Halh (Cyrillic)',
		'ml' => 'Malayalam',
		'ms-Arab' => 'Malay (Arabic)',
		'ms-Latn' => 'Malay (Latin)',
		'mt' => 'Maltese',
		'nb' => 'Norwegian, Bokmål',
		'ng' => 'Ndonga',
		'nl' => 'Dutch',
		'nn' => 'Norwegian, Nynorsk',
		'nv' => 'Navajo',
		'oc' => 'Occitan',
		'om' => 'Afaan Oromo',
		'pl' => 'Polish',
		'pt-BR' => 'Portuguese (Brazil)',
		'pt-PT' => 'Portuguese (Portugal)',
		'ro' => 'Romanian',
		'ru' => 'Russian',
		'sa' => 'Sanskrit',
		'sk' => 'Slovak',
		'sl' => 'Slovene',
		'so' => 'Somali',
		'sq' => 'Albanian',
		'ss' => 'Swati',
		'sv' => 'Swedish',
		'sw' => 'Swahili/Kiswahili',
		'ta' => 'Tamil',
		'te' => 'Telugu',
		'th' => 'Thai',
		'tl' => 'Tagalog',
		'to' => 'Tonga',
		'tr' => 'Turkish',
		'tt' => 'Tatar',
		'ty' => 'Tahitian',
		'ug-Arab' => 'Uyghur (Arabic)',
		'ug-Latn' => 'Uyghur (Latin)',
		'uk' => 'Ukrainian',
		'ur' => 'Urdu',
		'uz' => 'Uzbek',
		've' => 'Venda',
		'vi' => 'Vietnamese',
		'wa' => 'Walloon',
		'wo' => 'Wolof',
		'xh' => 'Xhosa',
		'yo' => 'Yoruba',
		'zh-Hans' => 'Chinese, Mandarin (Simplified)',
		'zh-Hant' => 'Chinese, Mandarin (Traditional)',
	];

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

		$folder = $params['folder'];
		$mailId = $params['uid'];

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
		// and parse using mail-mime-parser

		// alternative libraries:
		// IMAP:
		// https://github.com/ddeboer/imap	-- depends on phpimap
		// https://github.com/Webklex/php-imap	-- <<<< MIGRATE TO
		// https://github.com/DirectoryTree/ImapEngine	-- <<<< OR THIS !!
		// MIME parser:
		// https://github.com/zbateson/mail-mime-parser -- removes double quotes from filenames
		// https://github.com/php-mime-mail-parser/php-mime-mail-parser -- sanitizes/removes spaces after double quotes in filenames -- requires php-mailparse

		// $mail = $imapMailbox->getMail( $mailId, false );
		$rawEmail = $imapMailbox->getRawMail( $mailId, false );

		$mailMimeParser = new MailMimeParser();
		$message = $mailMimeParser->parse( $rawEmail, false );

		$headers = [
			'returnPath'			=> $message->getHeaderValue( HeaderConsts::RETURN_PATH ),
			'received'				=> $message->getHeaderValue( HeaderConsts::RECEIVED ),
			'resentDate'			=> $message->getHeaderValue( HeaderConsts::RESENT_DATE ),
			'resentFrom'			=> $message->getHeaderValue( HeaderConsts::RESENT_FROM ),
			'resentSender'			=> $message->getHeaderValue( HeaderConsts::RESENT_SENDER ),
			'resentTo'				=> $message->getHeaderValue( HeaderConsts::RESENT_TO ),
			'resentCc'				=> $message->getHeaderValue( HeaderConsts::RESENT_CC ),
			'resentBcc'				=> $message->getHeaderValue( HeaderConsts::RESENT_BCC ),
			'resentMessageId'		=> $message->getHeaderValue( HeaderConsts::RESENT_MESSAGE_ID ),
			'date'					=> $message->getHeaderValue( HeaderConsts::DATE ),
			'from'					=> $message->getHeaderValue( HeaderConsts::FROM ),
			'sender'				=> $message->getHeaderValue( HeaderConsts::SENDER ),
			'replyTo'				=> $message->getHeaderValue( HeaderConsts::REPLY_TO ),
			'to'					=> $message->getHeaderValue( HeaderConsts::TO ),
			'cc'					=> $message->getHeaderValue( HeaderConsts::CC ),
			'bcc'					=> $message->getHeaderValue( HeaderConsts::BCC ),
			'messageId'				=> $message->getHeaderValue( HeaderConsts::MESSAGE_ID ),
			'inReplyTo'				=> $message->getHeaderValue( HeaderConsts::IN_REPLY_TO ),
			'references'			=> $message->getHeaderValue( HeaderConsts::REFERENCES ),
			'subject'				=> $message->getHeaderValue( HeaderConsts::SUBJECT ),

			// or $message->getHeader( HeaderConsts::COMMENTS)->getComments()
			'comments'				=> $message->getHeaderValue( HeaderConsts::COMMENTS ),
			'keywords'				=> $message->getHeaderValue( HeaderConsts::KEYWORDS ),
			'mimeVersion'			=> $message->getHeaderValue( HeaderConsts::MIME_VERSION ),
			'contentType'			=> $message->getHeaderValue( HeaderConsts::CONTENT_TYPE ),
			'contentTransferEncoding' => $message->getHeaderValue( HeaderConsts::CONTENT_TRANSFER_ENCODING ),
			'contentId'				=> $message->getHeaderValue( HeaderConsts::CONTENT_ID ),
			'contentDescription'	=> $message->getHeaderValue( HeaderConsts::CONTENT_DESCRIPTION ),
			'contentDisposition'	=> $message->getHeaderValue( HeaderConsts::CONTENT_DISPOSITION ),
			'contentLanguage'		=> $message->getHeaderValue( HeaderConsts::CONTENT_LANGUAGE ),
			'contentBase'			=> $message->getHeaderValue( HeaderConsts::CONTENT_BASE ),
			'contentLocation'		=> $message->getHeaderValue( HeaderConsts::CONTENT_LOCATION ),
			'contentFeatures'		=> $message->getHeaderValue( HeaderConsts::CONTENT_FEATURES ),
			'contentAlternative'	=> $message->getHeaderValue( HeaderConsts::CONTENT_ALTERNATIVE ),
			'contentMd5'			=> $message->getHeaderValue( HeaderConsts::CONTENT_MD5 ),
			'contentDuration'		=> $message->getHeaderValue( HeaderConsts::CONTENT_DURATION ),
			'autoSubmitted'			=> $message->getHeaderValue( HeaderConsts::AUTO_SUBMITTED ),
		];

/*
id
headersRaw
headers
imapPath
mailboxFolder
isSeen
isAnswered
isRecent
isFlagged
isDeleted
isDraft
parsedDate
sender				-- object { name, address }
from				-- array of strings [ 'name <email>', 'name <email>', ... ]
fromParsed			-- array of objects [ { name, address }, ... ]
to					-- array of strings [ 'name <email>', 'name <email>', ... ]
toParsed			-- array of objects [ { name, address }, ... ]
cc					-- array of strings [ 'name <email>', 'name <email>', ... ]
ccParsed			-- array of objects [ { name, address }, ... ]
bcc					-- array of strings [ 'name <email>', 'name <email>', ... ]
bccParsed			-- array of objects [ { name, address }, ... ]
replyTo				-- array of strings [ 'name <email>', 'name <email>', ... ]
replyToParsed		-- array of objects [ { name, address }, ... ]
resentFrom			-- object { name, address }
resentTo			-- array of strings [ 'name <email>', 'name <email>', ... ]
resentToParsed		-- array of objects [ { name, address }, ... ]
resentCc			-- array of strings [ 'name <email>', 'name <email>', ... ]
resentCcParsed		-- array of objects [ { name, address }, ... ]
resentBcc			-- array of strings [ 'name <email>', 'name <email>', ... ]
resentBcParsed		-- array of objects [ { name, address }, ... ]
deliveredTo
textPlain
textHtml
visibleText
detectedLanguage
hasAttachments
attachments
				-- contentId,
				-- encoding,
				-- description,
				-- contentType,
				-- name,
				-- sizeInBytes,
				-- sizeInMB,
				-- disposition,
				-- fileinfo,
				-- mime,
				-- mimeType,
				-- mimeEncoding,
				-- fileExtension

regularAttachments
conversationHash
*/

		$obj = [];

		// @see PhpImap/Mailbox
		$obj['headersRaw'] = $imapMailbox->getMailHeaderRaw( $mailId );
		$obj['headers'] = $headers;
		$obj['id'] = $mailId;
		$obj['imapPath'] = $imapMailbox->getImapPath();
		$obj['mailboxFolder'] = $imapMailbox->getMailboxFolder();
		$obj['isSeen'] = ( $imapMailbox->flagIsSet( $mailId, '\Seen' ) ) ? true : false;
		$obj['isAnswered'] = ( $imapMailbox->flagIsSet( $mailId, '\Answered' ) ) ? true : false;
		$obj['isRecent'] = ( $imapMailbox->flagIsSet( $mailId, '\Recent' ) ) ? true : false;
		$obj['isFlagged'] = ( $imapMailbox->flagIsSet( $mailId, '\Flagged' ) ) ? true : false;
		$obj['isDeleted'] = ( $imapMailbox->flagIsSet( $mailId, '\Deleted' ) ) ? true : false;
		$obj['isDraft'] = ( $imapMailbox->flagIsSet( $mailId, '\Draft' ) ) ? true : false;

		$obj['parsedDate'] = $imapMailbox->parseDateTime( $obj['headers']['date'] );

		// @see https://datatracker.ietf.org/doc/html/rfc5322#section-3.6.2
		$headersToExtract = [
			'from' => HeaderConsts::FROM,
			// multiple
			'to' => HeaderConsts::TO,
			'cc' => HeaderConsts::CC,
			'bcc' => HeaderConsts::BCC,
			// multiple
			'replyTo' => HeaderConsts::REPLY_TO,
			// single
			'sender' => HeaderConsts::SENDER,
			// single
			'resentFrom' => HeaderConsts::RESENT_FROM,
			// multiple
			'resentTo' => HeaderConsts::RESENT_TO,
			'resentCc' => HeaderConsts::RESENT_CC,
			'resentBcc' => HeaderConsts::RESENT_BCC,
		];

		$allRecipients = [];
		foreach ( $headersToExtract as $key => $headerName ) {
			$header = $message->getHeader( $headerName );
			if ( $header && method_exists( $header, 'getAddresses' ) ) {
				$allRecipients[$key] = array_map( static function ( $addr ) {
					return [
						'name' => ( method_exists( $addr, 'getPersonName' ) ? $addr->getPersonName() : '' ),
						'address' => ( method_exists( $addr, 'getEmail' ) ? $addr->getEmail() : '' ),
					];
				}, $header->getAddresses() );
			} else {
				$allRecipients[$key] = [];
			}
		}

		$senderAddresses = [];
		foreach ( $allRecipients['from'] as $value ) {
			$senderAddresses[] = $value['address'];
		}

		$formatRecipient = static function ( &$address, &$name ) {
			$name = trim( $name );
			if ( $name && strpos( $name, ',' ) !== false ) {
				$name = '"' . $name . '"';
			}
			// @see https://datatracker.ietf.org/doc/html/rfc5321#section-2.4
			$address = strtolower( $address );
			return ( $name ? "$name <$address>" : $address );
		};

		// @see here https://datatracker.ietf.org/doc/html/rfc5322#section-3.6.2
		if ( $message->getHeader( HeaderConsts::SENDER ) ) {
			$obj['sender'] = [
				'name' => $message->getHeader( HeaderConsts::SENDER )->getPersonName(),
				'address' => $message->getHeader( HeaderConsts::SENDER )->getEmail(),
			];
			$senderAddresses[] = $message->getHeader( HeaderConsts::SENDER )->getEmail();
		}

		if ( $message->getHeader( HeaderConsts::RESENT_FROM ) ) {
			$obj['resentFrom'] = [
				'name' => $message->getHeader( HeaderConsts::RESENT_FROM )->getPersonName(),
				'address' => $message->getHeader( HeaderConsts::RESENT_FROM )->getEmail(),
			];
		}

		$allContacts = [];
		foreach ( $allRecipients as $key => $value ) {
			foreach ( $value as $k => $v ) {
				$obj[$key][] = $formatRecipient( $v['address'], $v['name'] );
				$obj[$key . 'Parsed'][] = [
					'address' => $v['address'],
					'name' => $v['name'],
				];
				$allContacts[$v['address']] = $v['name'];
			}
		}

		$deliveredTo = $message->getHeaderValue( 'Delivered-To' );

		if ( !empty( $deliveredTo ) ) {
			$obj['deliveredTo'] = $deliveredTo;
			$this->mailboxData['all_addresses'][] = $deliveredTo;
			$this->mailboxData['all_addresses'] = array_filter( array_unique( $this->mailboxData['all_addresses'] ) );
		}

		$obj['subject'] = $message->getSubject();
		$obj['textPlain'] = $message->getTextContent();
		$obj['textHtml'] = $message->getHtmlContent();

		$parsedEmail = ( new EmailParser() )->parse( $obj['textPlain'] );

		// custom entries
		$obj['visibleText'] = $parsedEmail->getVisibleText();

		// language detect @see https://github.com/patrickschur/language-detection
		$detectedLanguage = null;
		if ( strlen( $obj['textPlain'] ) >= 200 ) {
			$ld = new Language;
			$ld->setMaxNgrams( 5000 );
			$detectedLanguages = $ld->detect( $obj['textPlain'] )->close();
			$detectedLanguageCode = ( count( $detectedLanguages ) ? array_key_first( $detectedLanguages ) : null );

			$detectedLanguage = ( array_key_exists( $detectedLanguageCode, self::$languageMap )
				? self::$languageMap[$detectedLanguageCode]
				: $detectedLanguageCode
			);

			$obj['detectedLanguage'] = $detectedLanguage;
		}

		$obj['hasAttachments'] = ( $message->getAttachmentCount() > 0 );

		$attachments = $message->getAllAttachmentParts();

		$getFileInfo = static function ( $flag, $contents ) {
			$finfo = new \finfo( $flag );
			return $finfo->buffer( $contents );
		};

		$obj['regularAttachments'] = [];
		$attachmentPaths = [];
		foreach ( $attachments as $part ) {
			// @see PhpImap/Mailbox
			$fileSysName = bin2hex( random_bytes( 16 ) ) . '.bin';
			$dest_ = $attachmentsFolder . '/' . $fileSysName;
			$part->saveContent( $dest_ );
			$attachmentPaths[] = $dest_;

			$contents_ = file_get_contents( $dest_ );
			$obj['attachments'][] = [
				'contentId'      => $part->getContentId(),
				'encoding'       => $part->getContentTransferEncoding(),
				'description'    => $part->getHeaderValue( 'Content-Description' ),
				'contentType'    => $part->getContentType(),
				'name'           => $part->getFilename(),
				'sizeInBytes'    => strlen( $contents_ ),
				'sizeInMB'       => round( strlen( $contents_ ) / 1048576, 2 ),
				'disposition'    => $part->getContentDisposition(),
				'charset'        => $part->getCharset(),
				'fileInfo'       => $getFileInfo( \FILEINFO_NONE, $contents_ ),
				// 'fileInfoRaw'  => $getFileInfo( \FILEINFO_RAW, $contents_ ),
				'mime'           => $getFileInfo( \FILEINFO_MIME, $contents_ ),
				'mimeType'       => $getFileInfo( \FILEINFO_MIME_TYPE, $contents_ ),
				'mimeEncoding'   => $getFileInfo( \FILEINFO_MIME_ENCODING, $contents_ ),
				'fileExtension'  => $getFileInfo( \FILEINFO_EXTENSION, $contents_ ),
			];

			if ( $part->getContentDisposition() === 'attachment' ) {
				$obj['regularAttachments'][count( $obj['attachments'] ) - 1] = $part->getFilename();
			}
		}

		$deleteAttachments = static function () use ( $attachmentPaths ) {
			echo 'deleting attachments' . PHP_EOL;
			foreach ( $attachmentPaths as $path ) {
				unlink( $path );
			}
		};

		$showMsg = static function ( $msg ) {
			echo $msg . PHP_EOL;
		};

		$pagenameFormula = \ContactManager::replaceParameter( 'ContactManagerMessagePagenameFormula',
			$params['mailbox'],
			$folder['folder_name'],
			'<ContactManager/Message/id>'
		);

		$categories = ( array_key_exists( 'categories', $params )
			&& is_array( $params['categories'] ) ? $params['categories'] : [] );

		if ( strtolower( $params['folder']['folder_type'] ) === 'inbox' &&
			count( array_intersect( $senderAddresses, $this->mailboxData['all_addresses'] ) )
		) {
			$categories[] = 'Messages in wrong folder';
		}

		if ( !$this->applyFilters( $obj, $pagenameFormula, $categories ) ) {
			echo 'skipped by filter' . PHP_EOL;
			$deleteAttachments();
			return \ContactManager::SKIPPED_ON_FILTER;
		}

		$pagenameFormula = str_replace( '<folder_name>', $folder['folder_name'], $pagenameFormula );

		$pagenameFormula = \ContactManager::replaceFormula( $obj, $pagenameFormula,
			$GLOBALS['wgContactManagerSchemasMessage'] );

		$pagenameFormula = \ContactManager::replaceFormula( $obj['headers'], $pagenameFormula,
			$GLOBALS['wgContactManagerSchemasMessage'] . '/headers' );

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
			$deleteAttachments();
			return \ContactManager::SKIPPED_ON_ERROR;
		}

		$title_ = TitleClass::newFromText( $pagenameFormula );

		if ( !$title_ ) {
			$this->errors[] = 'invalid title';
			echo '***skipped on error' . PHP_EOL;
			$deleteAttachments();
			return \ContactManager::SKIPPED_ON_ERROR;
		}

		if ( $title_->isKnown() && !empty( $params['ignore_existing'] ) ) {
			echo 'skipped as existing' . PHP_EOL;
			$deleteAttachments();
			return \ContactManager::SKIPPED_ON_EXISTING;
		}

		// this will remove the mailbox related address from $conversationRecipients
		// by reference
		$conversationRecipients = $allContacts;
		[ $conversationHash, $mailboxRelatedAddresses ] = $this->getConversationHashAndRelatedAddresses( $params, $obj, $conversationRecipients );
		$obj['conversationHash'] = $conversationHash;

		$schema = $GLOBALS['wgContactManagerSchemasMessage'];
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
			$deleteAttachments();
			return \ContactManager::SKIPPED_ON_ERROR;
		}

		// ***important, get title object again
		$title_ = TitleClass::newFromText( $pagenameFormula );

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

		if ( $obj['hasAttachments'] ) {
			$pathTarget = $attachmentsFolder . '/' . $title_->getArticleID();
			echo 'attachment path ' . $pathTarget . PHP_EOL;

			if ( !is_dir( $pathTarget ) ) {
				if ( !mkdir( $pathTarget, 0777, true ) ) {
					echo '***error cannot create folder ' . $pathTarget . PHP_EOL;
				}
			}

			if ( file_exists( $pathTarget ) ) {
				foreach ( $obj['attachments'] as $value ) {
					$dest_ = $pathTarget . '/' . (
						$value['disposition'] === 'attachment' || empty( $value['contentId'] )
							? $value['name']
							: $value['contentId']
					);

					$filePath_ = array_shift( $attachmentPaths );
					if ( rename( $filePath_, $dest_ ) ) {
						echo 'saving attachment to ' . $dest_ . PHP_EOL;
					} else {
						echo '***error saving attachment to ' . $dest_ . PHP_EOL;
					}
				}

			} else {
				echo "cannot access folder \"$pathTarget\"" . PHP_EOL;
				$this->errors[] = "cannot access folder \"$pathTarget\"";
			}
		}

		$retContacts = [];
		$retConversation = [];
		if ( !empty( $params['save_contacts'] ) ) {
			foreach ( $allContacts as $email => $name ) {
				// do not include in the conversation's participants the mailbox related address
				// it will be added separately
				$conversationHash_ = ( array_key_exists( $email, $conversationRecipients ) ? $conversationHash : null );

				$ret_ = \ContactManager::saveUpdateContact( $user, $context, $params, $obj, $name, $email, $conversationHash_,
					( in_array( $email, $senderAddresses ) ? $detectedLanguage : null ) );

				if ( is_string( $ret_ ) && $ret_ ) {
					$retContacts[] = $ret_;
				}
			}

			$ret_ = $this->saveConversation( $user, $context, $params, $conversationHash,
				$deliveredTo, $conversationRecipients, $mailboxRelatedAddresses, $obj['parsedDate'] );

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
	private function getConversationHashAndRelatedAddresses( $params, $obj, &$conversationRecipients ) {
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

		$relatedAddresses = [];
		switch ( strtolower( $params['folder']['folder_type'] ) ) {
			case 'sent':
			case 'draft':
				if ( !empty( $obj['sender'] ) ) {
					$relatedAddresses[] = $obj['sender']['address'];
				}
				array_map( static function ( $value ) use ( &$relatedAddresses ) {
					$relatedAddresses[] = $value['address'];
				}, $obj['fromParsed'] );
				break;
			case 'spam':
			case 'other':
			case 'inbox':
			case 'trash':
			default:
				$mailboxAddresses = $this->mailboxData['all_addresses'];
				if ( !empty( $obj['deliveredTo'] ) ) {
					$relatedAddresses[] = $obj['deliveredTo'];
				}
				foreach ( [ 'toParsed', 'ccParsed', 'bccParsed' ] as $value ) {
					if ( array_key_exists( $value, $obj ) ) {
						array_map( static function ( $v ) use ( &$relatedAddresses, $mailboxAddresses ) {
							if ( in_array( $v['address'], $mailboxAddresses ) ) {
								$relatedAddresses[] = $v['address'];
							}
						}, $obj[$value] );
					}
				}
				break;
		}

		$relatedAddresses = array_unique( $relatedAddresses );
		// if ( !$relatedAddress ) {
		// 	$relatedAddress = $this->mailboxData['all_addresses'][count( $this->mailboxData['all_addresses'] ) - 1];
		// }

		return [ $hash, $relatedAddresses ];
	}

	/**
	 * @param User $user
	 * @param Context $context
	 * @param array $params
	 * @param string $hash
	 * @param string $deliveredTo
	 * @param array $conversationRecipients
	 * @param array $relatedAddresses
	 * @param string $date
	 * @return bool|null|string
	 */
	private function saveConversation( $user, $context, $params, $hash, $deliveredTo, $conversationRecipients, $relatedAddresses, $date ) {
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
			'related_addresses' => $relatedAddresses,
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
		$schema = $GLOBALS['wgContactManagerSchemasMessage'];

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
				case 'headers/contentDuration':
				case 'attachments/sizeInBytes':
				case 'attachments/sizeInMB':
					$value_ = (int)$value_;
					$result_ = ( $value_ >= $v['number_from']
						&& $value_ <= $v['number_to'] );
					break;

				case 'headersRaw':
				case 'imapPath':
				case 'mailboxFolder':
				case 'sender/name':
				case 'sender/address':
				case 'deliveredTo':
				case 'textPlain':
				case 'textHtml':
				case 'visibleText':
				case 'detectedLanguage':
				case 'regularAttachments':
				case 'conversationHash':
				case 'headers/messageId':
				case 'headers/returnPath':
				case 'headers/received':
				case 'headers/resentDate':
				case 'headers/resentFrom':
				case 'headers/resentSender':
				case 'headers/resentTo':
				case 'headers/resentCc':
				case 'headers/resentBcc':
				case 'headers/resentMessageId':
				case 'headers/from':
				case 'headers/sender':
				case 'headers/replyTo':
				case 'headers/to':
				case 'headers/cc':
				case 'headers/bcc':
				case 'headers/messageId':
				case 'headers/inReplyTo':
				case 'headers/references':
				case 'headers/subject':
				case 'headers/comments':
				case 'headers/keywords':
				case 'headers/mimeVersion':
				case 'headers/contentType':
				case 'headers/contentTransferEncoding':
				case 'headers/contentId':
				case 'headers/contentDescription':
				case 'headers/contentDisposition':
				case 'headers/contentLanguage':
				case 'headers/contentBase':
				case 'headers/contentLocation':
				case 'headers/contentFeatures':
				case 'headers/contentAlternative':
				case 'headers/contentMd5':
				case 'headers/autoSubmitted':
				case 'attachments/contentId':
				case 'attachments/encoding':
				case 'attachments/description':
				case 'attachments/contentType':
				case 'attachments/name':
				case 'attachments/disposition':
				case 'attachments/fileinfo':
				case 'attachments/mime':
				case 'attachments/mimeType':
				case 'attachments/mimeEncoding':
				case 'attachments/fileExtension':
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

				case 'parsedDate':
				case 'headers/date':
					$value_ = strtotime( $value_ );
					$result_ = ( $value_ >= strtotime( $v['date_from'] )
						&& $value_ <= strtotime( $v['date_to'] ) );
					break;

				case 'hasAttachments':
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
