<?php
/**
 * This file is part of the MediaWiki extension ContactMananger.
 *
 * ContactManager is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * ContactMananger is distributed in the hope that it will be useful,
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

use PhpImap\IncomingMail;

class PHPImapMailbox extends \PhpImap\Mailbox {

	/**
	 * credits OpenAI & the unaware unknown contributors
	 * @param string $str
	 * @return bool
	 */
	protected function brokenEncoding( $str ) {
		// Trim and check if it consists mostly of "?" or unreadable sequences
		$str = trim( $str );

		// If it's completely unreadable or made of placeholder characters
		if ( preg_match( '/^\?+(\s*\?+)*(\.\w+)?$/u', $str ) ) {
			return true;
		}

		// Check for valid UTF-8 (excluding pure ASCII, since valid UTF-8 could contain non-ASCII)
		if ( mb_detect_encoding( $str, 'UTF-8', true ) === false ) {
			return true;
		}

		// Additional heuristic: too many replacement characters (�) or only symbols
		if ( substr_count( $str, '�' ) > 1 ) {
			return true;
		}

		return false;
	}

	/**
	 * some of the credits: OpenAI & the unaware unknown contributors
	 * @param stdClass $partStructure
	 * @return array
	 */
	protected function extractParamsFromPart( $partStructure ) {
		$params = [];

		foreach ( [ 'parameters', 'dparameters' ] as $key ) {
			if ( !empty( $partStructure->$key ) ) {
				foreach ( $partStructure->$key as $param ) {
					$attr = strtolower( $param->attribute );
					if ( str_ends_with( $attr, '*' ) ) {
						$attr = preg_replace( '/\*$/', '', $attr );
					}
					$params[ $attr ] = $this->decodeMimeStr( $param->value ?? '' );
				}
			}
		}

		// @see here https://github.com/barbushin/php-imap/issues/569
		// both name and filename can be missing
		if (
			( !isset( $params['filename'] ) || empty( trim( $params['filename'] ) ) ) &&
			( !isset( $params['name'] ) || empty( trim( $params['name'] ) ) )
		) {
			$contentId = isset( $partStructure->id ) ? trim( $partStructure->id, ' <>' ) : '';
			if ( !empty( $contentId ) ) {
				$ext = !empty( $partStructure->subtype ) ? '.' . strtolower( $partStructure->subtype ) : '';
				$params['filename'] = $contentId . $ext;
			} elseif ( !empty( $partStructure->subtype ) ) {
				$params['filename'] = strtolower( $partStructure->subtype );
			} else {
				$params['filename'] = 'unknown';
			}
		}

		return $params;
	}

	/**
	 * @inheritDoc
	 */
	protected function initMailPart( IncomingMail $mail, object $partStructure, $partNum, bool $markAsSeen = true, bool $emlParse = false ): void {
		parent::initMailPart( $mail, $partStructure, $partNum, $markAsSeen, $emlParse );

		// ***some of the credits: OpenAI & the unaware unknown contributors
		foreach ( $mail->getAttachments() as $attachment ) {
			$params = $this->extractParamsFromPart( $partStructure );

			$rawName = $params['name'] ?? '';
			$rawFilename = $params['filename'] ?? '';
			$decoded = null;

			if ( $this->brokenEncoding( $attachment->name ) ) {
				if ( !empty( trim( $rawName ) ) && !$this->brokenEncoding( $rawName ) ) {
					$decoded = $this->decodeRFC2231( $this->decodeMimeStr( $rawName ) );
				} elseif ( !empty( trim( $rawFilename ) ) && !$this->brokenEncoding( $rawFilename ) ) {
					$decoded = $this->decodeRFC2231( $this->decodeMimeStr( $rawFilename ) );
				}
			}

			if ( !empty( $decoded ) ) {
				$attachment->name = $decoded;

			} elseif ( empty( $attachment->name ) && !empty( $rawFilename ) ) {
				$attachment->name = $rawFilename;
			}
		}
	}

	/**
	 * @param string $sequence
	 * @return array
	 */
	public function fetch_overview( string $sequence ) {
		// ***edited
		// $mails = imap_fetch_overview( $this->getImapStream(), $criteria, FT_UID );
		$mails = \PhpImap\Imap::fetch_overview(
			$this->getImapStream(),
			$sequence,
			( SE_UID === $this->imapSearchOption ) ? FT_UID : 0
		);

		// ***some of the credits: OpenAI & the unaware unknown contributors
		if ( !empty( $mails ) ) {
			foreach ( $mails as $index => &$mail ) {
				foreach ( [ 'subject', 'from', 'sender', 'to' ] as $field ) {
					if ( isset( $mail->$field ) ) {
						if ( !is_string( $mail->$field ) ) {
							throw new UnexpectedValueException( sprintf(
								'%s property at index %d of argument 1 passed to %s() was not a string!',
								$field,
								$index,
								__METHOD__
							) );
						}
						if ( trim( $mail->$field ) !== '' ) {
							$mail->$field = $this->decodeMimeStr( $mail->$field );
						}
					}
				}
			}
		}

		/** @var list<object> */
		return $mails;
	}

	/**
	 * @param int $mailId
	 * @return string
	 */
	public function getMailHeaderRaw( int $mailId ) {
		return \PhpImap\Imap::fetchheader(
			$this->getImapStream(),
			$mailId,
			( SE_UID === $this->imapSearchOption ) ? FT_UID : 0
		);
	}

}
