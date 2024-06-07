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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ContactManager. If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright © 2023-2024, https://wikisphere.org
 */
/* eslint-disable no-unused-vars */

// eslint-disable-next-line no-implicit-globals, no-undef
ContactManager = ( function () {
	function initJob( value, data ) {
		return new Promise( ( resolve, reject ) => {
			const payload = {
				action: 'contactmanager-createjob',
				data: JSON.stringify( data ),
				pageid: mw.config.get( 'wgArticleId' )
			};

			mw.loader.using( 'mediawiki.api', () => {
				new mw.Api()
					.postWithToken( 'csrf', payload )
					.done( ( res ) => {
						// console.log( 'res', res );
						if ( payload.action in res ) {
							// eslint-disable-next-line no-alert
							alert( 'job created' );
						}
					} )
					.fail( ( res ) => {
						// eslint-disable-next-line no-alert
						alert( 'error' + res );
						// console.log("getFolders fail", res);
					} );
			} );
		} ).catch( ( err ) => {
			// eslint-disable-next-line no-console
			console.log( err );
		} );
	}

	return {
		initJob: initJob
	};
}() );
