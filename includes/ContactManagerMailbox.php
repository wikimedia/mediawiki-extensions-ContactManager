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
 * @copyright Copyright ©2023, https://wikisphere.org
 */
 
class ContactManagerMailbox extends PhpImap\Mailbox {

	public function fetch_overview( $criteria ) {
		$mails = imap_fetch_overview( $this->getImapStream(), $criteria, FT_UID );

        if ( \count($mails) ) {
			return $mails;
		}

		return [];
	}

    public function getMailHeaderRaw( int $mailId ): string {
       return PhpImap\Imap::fetchheader(
            $this->getImapStream(),
            $mailId,
            (SE_UID === $this->imapSearchOption) ? FT_UID : 0
        );

	}

}

