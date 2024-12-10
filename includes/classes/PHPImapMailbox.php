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
 * @copyright Copyright ©2023-2024, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager;

class PHPImapMailbox extends \PhpImap\Mailbox {

	/**
	 * @param string $sequence
	 * @return array
	 */
	public function fetch_overview( string $sequence ): array {
		// $mails = imap_fetch_overview( $this->getImapStream(), $criteria, FT_UID );
		$mails = \PhpImap\Imap::fetch_overview(
			$this->getImapStream(),
			$sequence,
			( SE_UID === $this->imapSearchOption ) ? FT_UID : 0
		);
		if ( \count( $mails ) ) {
			foreach ( $mails as $index => &$mail ) {
				if ( isset( $mail->subject ) && !\is_string( $mail->subject ) ) {
					throw new UnexpectedValueException( 'subject property at index ' .
						(string)$index . ' of argument 1 passed to ' . __METHOD__ . '() was not a string!' );
				}
				if ( isset( $mail->from ) && !\is_string( $mail->from ) ) {
					throw new UnexpectedValueException( 'from property at index ' .
						(string)$index . ' of argument 1 passed to ' . __METHOD__ . '() was not a string!' );
				}
				if ( isset( $mail->sender ) && !\is_string( $mail->sender ) ) {
					throw new UnexpectedValueException( 'sender property at index ' .
						(string)$index . ' of argument 1 passed to ' . __METHOD__ . '() was not a string!' );
				}
				if ( isset( $mail->to ) && !\is_string( $mail->to ) ) {
					throw new UnexpectedValueException( 'to property at index ' .
						(string)$index . ' of argument 1 passed to ' . __METHOD__ . '() was not a string!' );
				}
				if ( isset( $mail->subject ) && !empty( \trim( $mail->subject ) ) ) {
					$mail->subject = $this->decodeMimeStr( $mail->subject );
				}
				if ( isset( $mail->from ) && !empty( \trim( $mail->from ) ) ) {
					$mail->from = $this->decodeMimeStr( $mail->from );
				}
				if ( isset( $mail->sender ) && !empty( \trim( $mail->sender ) ) ) {
					$mail->sender = $this->decodeMimeStr( $mail->sender );
				}
				if ( isset( $mail->to ) && !empty( \trim( $mail->to ) ) ) {
					$mail->to = $this->decodeMimeStr( $mail->to );
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
	public function getMailHeaderRaw( int $mailId ): string {
		return \PhpImap\Imap::fetchheader(
			$this->getImapStream(),
			$mailId,
			( SE_UID === $this->imapSearchOption ) ? FT_UID : 0
		);
	}

}
