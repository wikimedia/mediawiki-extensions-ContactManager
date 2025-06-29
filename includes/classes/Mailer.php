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
 * @copyright Copyright ©2024-2025, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager;

if ( is_readable( __DIR__ . '/../../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../../vendor/autoload.php';
}

use MediaWiki\Category\Category;
use MediaWiki\Extension\ContactManager\Aliases\Title as TitleClass;
use MediaWiki\Extension\ContactManager\Transport\SendgridApiTransport;
use MediaWiki\Extension\VisualData\DatabaseManager;
use MediaWiki\MediaWikiServices;
use Parser;
use ParserOptions;
use RequestContext;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use User;

class Mailer {

	/** @var array */
	private $obj;

	/** @var AbstractApiTransport */
	private $transportClass = null;

	/** @var array */
	private $personalizations = [];

	/** @var bool */
	public $usePersonalizations = false;

	/** @var string */
	private $editor;

	/** @var SymfonyMailer */
	private $mailerClass;

	/** @var User */
	private $user;

	/** @var Title */
	private $title;

	/** @var array */
	public $errors = [];

	/** @var string */
	public $mailer;

	/**
	 * @param User $user
	 * @param Title|Mediawiki\Title\Title $title
	 * @param array $obj
	 * @param string $editor
	 * @return bool
	 */
	public function __construct( $user, $title, $obj, $editor ) {
		$this->user = $user;
		$this->title = $title;
		$this->obj = $obj;
		$this->editor = $editor;

		$dns = null;
		$username = null;
		$password = null;
		$host = null;
		$transportScheme = null;

		// @see https://symfony.com/doc/5.x/mailer.html
		switch ( $obj['transport'] ) {
			case 'mailbox':
				if ( empty( $GLOBALS['wgContactManagerSMTP'][$obj['mailbox']] ) ) {
					$this->errors[] = 'credentials not found';
					return false;
				}

				$credentials = $GLOBALS['wgContactManagerSMTP'][$obj['mailbox']];
				$credentials = array_change_key_case( $credentials, CASE_LOWER );

				$transportScheme = 'smtp';
				$username = $credentials['username'];
				$password = $credentials['password'];
				$host = $credentials['server'] . ':' . $credentials['port'];
				break;
			case 'mailer':
				$this->obj['from'] = $this->obj['from_mailer'];
				$schema = $GLOBALS['wgContactManagerSchemasMailer'];
				$mailer = preg_replace( '/\s*-[^-]+$/', '', $obj['mailer'] );
				$query = '[[name::' . $mailer . ']]';
				$results = \VisualData::getQueryResults( $schema, $query );

				if ( empty( $results[0]['data'] ) ) {
					$this->errors[] = 'mailer not found';
					return false;
				}
				$data = $results[0]['data'];
				if ( empty( $GLOBALS['wgContactManager' . strtoupper( $data['provider'] ) ][$data['name']] ) ) {
					$this->errors[] = 'credentials not found';
					return false;
				}

				$credentials = $GLOBALS['wgContactManager' . strtoupper( $data['provider'] ) ][$data['name']];
				$credentials = array_change_key_case( $credentials, CASE_LOWER );

				if ( empty( $credentials[$data['transport']] ) ) {
					$this->errors[] = 'transport not found';
					return false;
				}

				$credentials = $credentials[$data['transport']];
				$credentials = array_change_key_case( $credentials, CASE_LOWER );

				// @see https://symfony.com/doc/5.x/mailer.html
				switch ( $data['provider'] ) {
					case 'smtp':
						$transportScheme = 'smtp';
						$username = $credentials['username'];
						$password = $credentials['password'];
						$host = $credentials['server'] . ':' . $credentials['port'];
						break;
					case 'sendmail':
						$dns = 'sendmail://default';
						break;
					case 'native':
						$dns = 'native://default';
						break;

					default:
						$this->mailer = $data['provider'];

						switch ( $data['transport'] ) {
							case 'smtp':
								switch ( $data['provider'] ) {
									case 'amazon':
										// ses+smtp://USERNAME:PASSWORD@default
										$transportScheme = 'ses+smtp';
										$username = $credentials['username'];
										$password = $credentials['password'];
										break;
									case 'gmail':
										$transportScheme = 'gmail+smtp';
										$username = $credentials['username'];
										$password = $credentials['app-password'];
										break;
									case 'mandrill':
										$transportScheme = 'mandrill+smtp';
										$username = $credentials['username'];
										$password = $credentials['password'];
										break;
									case 'mailgun':
										$transportScheme = 'mailgun+smtp';
										$username = $credentials['username'];
										$password = $credentials['password'];
										break;
									case 'mailjet':
										$transportScheme = 'mailjet+smtp';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'postmark':
										$transportScheme = 'postmark+smtp';
										$username = $credentials['id'];
										break;
									case 'sendgrid':
										$transportScheme = 'sendgrid+smtp';
										$username = $credentials['key'];
										break;
									case 'sendinblue':
										$transportScheme = 'sendinblue+smtp';
										$username = $credentials['username'];
										$password = $credentials['password'];
										break;
									case 'ohmysmtp':
										$dns = 'ohmysmtp+smtp';
										$username = $credentials['api_token'];
										break;
								}
								break;
							case 'http':
								switch ( $data['provider'] ) {
									case 'amazon':
										$transportScheme = 'ses+htpps';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'gmail':
										break;
									case 'mandrill':
										$transportScheme = 'mandrill+htpps';
										$username = $credentials['key'];
										break;
									case 'mailgun':
										$transportScheme = 'mailgun+htpps';
										$username = $credentials['key'];
										$password = $credentials['domain'];
										break;
									case 'mailjet':
									case 'postmark':
									case 'sendgrid':
									case 'sendinblue':
									case 'ohmysmtp':
								}
								break;
							case 'api':
								switch ( $data['provider'] ) {
									case 'amazon':
										$transportScheme = 'ses+api';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'gmail':
										break;
									case 'mandrill':
										$transportScheme = 'mandrill+api';
										$username = $credentials['key'];
										break;
									case 'mailgun':
										$transportScheme = 'mailgun+api';
										$username = $credentials['key'];
										$password = $credentials['domain'];
										break;
									case 'mailjet':
										$transportScheme = 'mailjet+api';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'postmark':
										$transportScheme = 'postmark+api';
										$username = $credentials['key'];
										break;
									case 'sendgrid':
										$transportScheme = 'sendgrid+api';
										$username = $credentials['key'];
										$this->transportClass = new SendgridApiTransport( $username );
										break;
									case 'sendinblue':
										$transportScheme = 'sendinblue+api';
										$username = $credentials['key'];
										break;
									case 'ohmysmtp':
										$dns = 'ohmysmtp+api';
										$username = $credentials['api_token'];
										break;
								}
								break;
						}
				}
		}

		if ( $this->transportClass ) {
			$this->mailerClass = new SymfonyMailer( $this->transportClass );
			return true;
		}

		if ( !$dns ) {
			if ( !$username ) {
				$this->errors[] = 'transport not supported';
				return false;
			}

			// ses+smtp://USERNAME:PASSWORD@default
			$dns = $transportScheme . '://' . urlencode( $username );

			if ( $password ) {
				$dns .= ':' . urlencode( $password );
			}

			$dns .= '@' . ( $host ?? 'default' );
		}

		$transport = Transport::fromDsn( $dns );
		$this->mailerClass = new SymfonyMailer( $transport );

		return true;
	}

	/**
	 * @param string $text
	 * @return array
	 */
	public function parseWikitext( $text ) {
		$parser = MediaWikiServices::getInstance()->getParserFactory()->getInstance();
		// $parser->setTitle();
		$parserOptions = ParserOptions::newFromUser( $this->user );
		// $parser->setOptions( $parserOptions );

		// OT_PLAIN
		$parser->setOutputType( Parser::OT_HTML );

		// or use source page
		$t = TitleClass::makeTitle( NS_SPECIAL, 'Badtitle/Parser' );
		$parserOutput = $parser->parse( $text, $t, $parserOptions );

		$options = [
			'allowTOC' => false,
			'injectTOC' => false,
			'enableSectionEditLinks' => false,
			'userLang' => null,
			'skin' => null,
			'unwrap' => false,
			// 'wrapperDivClass' => $this->getWrapperDivClass(),
			'deduplicateStyles' => true,
			'absoluteURLs' => true,
			'includeDebugInfo' => false,
			'bodyContentOnly' => true,
		];

		$html = $parserOutput->getText();
		$html2Text = new \Html2Text\Html2Text( $html );
		$text = $html2Text->getText();

		return [ $text, $html ];
	}

	/**
	 * @param array $recipients
	 * @param array $allPrintouts
	 * @param array $emailPrintouts
	 * @return array
	 */
	public function getContactDataFromRecipients( $recipients, $allPrintouts, $emailPrintouts ) {
		if ( !count( $recipients ) ) {
			return [];
		}

		$parsedRecipients = array_filter( array_map( static function ( $value ) {
			return \ContactManager::parseRecipient( $value );
		}, $recipients ) );

		if ( !count( $parsedRecipients ) ) {
			return [];
		}

		$emailAddresses = [];
		foreach ( $parsedRecipients as $value ) {
			$emailAddresses[] = trim( $value[1] );
		}

		$schemaName = $GLOBALS['wgContactManagerSchemasContact'];
		$targetTitle_ = \ContactManager::replaceParameter( 'ContactManagerContactPagenameFormula',
			$this->obj['mailbox'],
			'~'
		);

		$query = "[[$targetTitle_]]";

		$cond_ = [];
		foreach ( $emailPrintouts as $emailPrintout ) {
			$cond_[] = implode( '||', array_map( static function ( $value ) use ( $emailPrintout ) {
				return "$emailPrintout::$value";
			}, $emailAddresses ) );
		}

		$query .= '[[' . implode( '||', $cond_ ) . ']]';

		// retrieves one email address per row
		$params = [ 'nested' => false ];
		$ret = \VisualData::getQueryResults( $schemaName, $query, $allPrintouts, $params );

		// @TODO log errors
		if ( array_key_exists( 'errors', $ret ) ) {
			return [];
		}

		foreach ( $parsedRecipients as $value ) {
			$found = false;
			foreach ( $ret as $data ) {
				foreach ( $emailPrintouts as $emailPrintout ) {
					if ( !empty( $data['data'][$emailPrintout] ) ) {
						$found = true;
						break 2;
					}
				}
			}
			if ( !$found ) {
				$ret[] = [
					'data' => [
						$emailPrintouts[0] => trim( $value[1] ),
						'full_name' => $value[0],
					]
				];
			}
		}

		return $ret;
	}

	/**
	 * @param array $schema
	 * @param string $text
	 * @return array
	 */
	private function getRelevantPrintouts( $schema, $text ) {
		$emailPrintouts = [];
		$requiredPrintouts = [];
		$callback = static function ( $subSchema, $path, $printout, $property )
			use ( &$emailPrintouts, &$requiredPrintouts, $text
		) {
			// $printout will match both items' subschema or
			// single values
			if ( array_key_exists( 'format', $subSchema ) && $subSchema['format'] === 'email' ) {
				$emailPrintouts[] = $printout;
			}

			if ( strpos( $text, "%$printout%" ) !== false ) {
				$requiredPrintouts[] = $printout;
			}
		};
		$printout = '';
		$path = '';
		DatabaseManager::traverseSchema( $schema, $path, $printout, $callback );

		return [ array_unique( $emailPrintouts ), $requiredPrintouts ];
	}

	/**
	 * @param array $data
	 * @param array $recipientsParams
	 * @return array
	 */
	private function getDataMap( $data, $recipientsParams ) {
		$dataMap = [];
		$emailAddresses = [];
		$path = '';
		$printout = '';
		$callback = static function () {
		};
		$callbackValue = static function ( $schema, &$data, $path, $printout, $key )
			use ( $recipientsParams, &$dataMap, &$emailAddresses
		) {
			if ( in_array( $printout, $recipientsParams['requiredPrintouts'] ) ) {
				$dataMap[$printout] = $data;
			}
			if ( in_array( $printout, $recipientsParams['emailPrintouts'] ) ) {
				$emailAddresses[] = $data;
			}
		};
		DatabaseManager::traverseData( $recipientsParams['schema'], $data, $path, $printout, $callback, $callbackValue );

		return [ $dataMap, $emailAddresses ];
	}

	/**
	 * @param string $key
	 * @param string $name
	 * @param array $requiredPrintouts
	 * @param array $emailAddresses
	 * @param array $dataMap
	 */
	private function appendToPayload( $key, $name, $requiredPrintouts, $emailAddresses, $dataMap ) {
		// use always personalizations
		// if ( count( $requiredPrintouts ) === 0 ) {
		// 	$this->obj[$key][] = new Address( $emailAddresses[0], $name );
		// 	return;
		// }

		$val = [
			// must be an array
			// do not use $key, produces the sendgrid error
			// "The to array is required for all personalization objects, and must have at least one email object with a valid email address."
			'to' => [
				[
					'email' => $emailAddresses[0],
					'name' => $name,
				]
			]
		];

		if ( count( $requiredPrintouts ) > 0 ) {
			$val['substitutions'] = array_combine( array_map( static function ( $value ) {
				return "%$value%";
			}, array_keys( $dataMap ) ), array_values( $dataMap ) );
		}

		$this->personalizations[] = $val;
	}

	private function prepareData() {
		$context = RequestContext::getMain();

		$contactsData = [
			'to' => [],
			'cc' => [],
			'bcc' => [],
		];

		// get required printouts for Contacts schema
		$schemaName = $GLOBALS['wgContactManagerSchemasContact'];
		$schema = \VisualData::getSchema( $context, $schemaName );
		[ $emailPrintouts, $requiredPrintouts ] = $this->getRelevantPrintouts( $schema, $this->obj['text'] );

		if ( count( $requiredPrintouts ) && !$this->usePersonalizations ) {
			$this->errors[] = 'Substitutions not allowed';
			return;
		}

		// if ( !count( $requiredPrintouts ) ) {
		// 	$this->usePersonalizations = false;
		// }

		$allPrintouts = array_merge( $requiredPrintouts, $emailPrintouts );

		if ( $this->usePersonalizations && count( $allPrintouts ) > 0 ) {
			if ( !in_array( 'full_name', $allPrintouts ) ) {
				$allPrintouts[] = 'full_name';
			}

			$contactsData['to'] = $this->getContactDataFromRecipients( $this->obj['to'], $allPrintouts, $emailPrintouts );
			$contactsData['cc'] = $this->getContactDataFromRecipients( $this->obj['cc'], $allPrintouts, $emailPrintouts );
			$contactsData['bcc'] = $this->getContactDataFromRecipients( $this->obj['bcc'], $allPrintouts, $emailPrintouts );

			// $recipientsParams = [
			// 	'emailPrintouts' => $emailPrintouts,
			// 	'requiredPrintouts' => $requiredPrintouts,
			// 	'schema' => $schema
			// ];

			foreach ( $contactsData as $key => $value ) {
				foreach ( $value as $k => $v ) {
					$dataMap = array_intersect_key( $v['data'], array_flip( $requiredPrintouts ) );
					$emailAddresses = [];
					foreach ( $emailPrintouts as $printout ) {
						$emailAddresses[] = $v['data'][$printout];
					}
					$name = $v['data']['full_name'];

					// *** not required, since we use $params = [ 'nested' => false ];
					// inside getContactDataFromRecipients
					// [ $dataMap, $emailAddresses ] = $this->getDataMap( $v['data'], $recipientsParams );

					$this->appendToPayload( $key, $name, $requiredPrintouts, $emailAddresses, $dataMap );
				}
			}
		}

		$categoriesParams = [];
		if ( !empty( $this->obj['bcc_categories'] ) ) {
			foreach ( $this->obj['bcc_categories'] as $value ) {
				// $title_ = TitleClass::newFromText( $value, NS_CATEGORY );

				// get the schemas associated to the articles belonging to
				// the given category and the relevant printouts
				// loop the first 10 category's articles until a valid
				// email printout is found
				$cat = Category::newFromName( $value );
				$iterator_ = $cat->getMembers( 10 );
				$found = false;
				while ( $iterator_->valid() && !$found ) {
					$title_ = $iterator_->current();
					$data_ = \VisualData::getJsonData( $title_ );
					if ( !empty( $data_['schemas'] ) ) {
						$schemas_ = array_keys( $data_['schemas'] );
						foreach ( $schemas_ as $schemaName_ ) {
							$schema_ = \VisualData::getSchema( $context, $schemaName_ );
							[ $emailPrintouts_, $requiredPrintouts_ ] = $this->getRelevantPrintouts( $schema_, $this->obj['text'] );

							if ( $emailPrintouts_ ) {
								$categoriesParams[$value][$schemaName_] = [ $emailPrintouts_, $requiredPrintouts_, $schema_ ];
								$found = true;
								break;
							}
						}
					}
					$iterator_->next();
				}
			}

			// get articles data of the requested categories
			// using the relevant schema and the required printouts
			$categoriesData = [];
			$collected = 0;
			$skipped = 0;

			// the following assumes that all categories share the same
			// schema
			// $query = '[[' . implode( '||', array_map( static function ( $value ) {
			// 	return "Category:$value";
			// }, $this->obj['bcc_categories'] ) ) . ']]';

			foreach ( $categoriesParams as $cat => $value ) {
				$query = "[[Category:$cat]]";

				// contains no more than 1 schema
				foreach ( $value as $schemaName => $values ) {
					[ $emailPrintouts_, $requiredPrintouts_ ] = $values;

					$params = [ 'format' => 'count' ];
					$allPrintouts_ = array_merge( $emailPrintouts_, $requiredPrintouts_ );
					$count_ = \VisualData::getQueryResults( $schemaName, $query, $allPrintouts_, $params );

					// >>>>>>>>>>>>>>>>>> ChatGPT idea
					if ( $skipped + $count_ <= $this->obj['offset'] ) {
						$skipped += $count_;
						continue 2;
					}

					$localOffset = max( 0, $this->obj['offset'] - $skipped );
					$localLimit = $this->obj['limit'] - $collected;
					// <<<<<<<<<<<<<<<<<< ChatGPT idea

					$params = [ 'format' => 'json-raw', 'offset' => $localOffset, 'limit' => $localLimit ];
					$results_ = \VisualData::getQueryResults( $schemaName, $query, $allPrintouts_, $params );

					if ( array_key_exists( 'errors', $results_ ) ) {
						$this->errors = array_merge( $this->errors, $results_['errors'] );
						continue;
					}

					$categoriesData[$cat][$schemaName] = $results_;

					// >>>>>>>>>>>>>>>>>> ChatGPT idea
					$collected += count( $results_ );
					$skipped += $count_;

					if ( $collected >= $this->obj['limit'] ) {
						break 2;
					}
					// <<<<<<<<<<<<<<<<<< ChatGPT idea
				}
			}

			$exclude_bcc_categories = [];
			if ( array_key_exists( 'exclude_bcc_categories', $this->obj )
				&& is_array( $this->obj['exclude_bcc_categories'] )
			) {
				$exclude_bcc_categories = $this->obj['exclude_bcc_categories'];
			}

			// create the payload associating the required printouts
			// to nested data
			foreach ( $categoriesData as $cat => $value ) {
				foreach ( $value as $schemaName => $values ) {
					[ $emailPrintouts_, $requiredPrintouts_, $schema_ ] = $categoriesParams[$cat][$schemaName];

					$recipientsParams = [
						'emailPrintouts' => $emailPrintouts_,
						'requiredPrintouts' => $requiredPrintouts_,
						'schema' => $schema_
					];

					foreach ( $values as $k => $v ) {
						[ $dataMap, $emailAddresses ] = $this->getDataMap( $v['data'], $recipientsParams );
						if ( count( array_intersect( $exclude_bcc_categories, $emailAddresses ) ) ) {
							continue;
						}

						$namePrintouts = [ 'full_name', 'name', 'contact_person' ];
						$name = '';
						foreach ( $namePrintouts as $printout_ ) {
							if ( array_key_exists( $printout_, $dataMap ) ) {
								$name = $dataMap[$printout_];
								break;
							}
						}
						$this->appendToPayload( 'bcc', $name, $requiredPrintouts_, $emailAddresses, $dataMap );
					}
				}
			}
		}
	}

	/**
	 * @return string|void
	 */
	public function renderTwigTemplate() {
		$templateTitle = TitleClass::newFromText( $this->obj['template'], NS_CONTACTMANAGER_EMAIL_TEMPLATE );
		if ( !$templateTitle->isKnown() ) {
			return;
		}

		$content = \VisualData::getWikipageContent( $templateTitle );

		if ( !$content ) {
			return;
		}

		$templateName = 'twigTemplate';
		$loader = new ArrayLoader( [
			$templateName => $content,
		] );

		$twig = new Environment( $loader );
		$substitutions = [ 'body' => $this->obj['text'], 'subject' => $this->obj['subject'] ];

		try {
			$html = $twig->render( $templateName, $substitutions );

		} catch ( \Exception $e ) {
			$this->errors[] = $e->getMessage();
			return false;
		}

		return $html;
	}

	/**
	 * @return bool
	 */
	private function setupTracking() {
		if ( !count( $this->personalizations ) ) {
			return;
		}

		$rows = [];
		$dateTime = date( 'Y-m-d H:i:s' );
		foreach ( $this->personalizations as $field ) {
			foreach ( $field as $values ) {
				foreach ( $values as $value ) {
					$rows[] = [
						'page_id' => $this->title->getArticleID(),
						'email' => $value['email'],
						'name' => $value['name'],
						'mailer' => $this->mailer,
						'created_at' => $dateTime
					];
				}
			}
		}

		$dbw = \VisualData::getDB( DB_PRIMARY );
		$tableName = 'contactmanager_tracking';
		$options = [];
		$res = $dbw->insert(
			$tableName,
			$rows,
			__METHOD__,
			$options
		);
	}

	/**
	 * @return bool
	 */
	public function sendEmail() {
		$this->usePersonalizations = $this->transportClass &&
			method_exists( $this->transportClass, 'setPersonalizations' );

		$this->prepareData();

		if ( $this->usePersonalizations ) {
			$dbr = \VisualData::getDB( DB_REPLICA );
			if ( !$dbr->tableExists( 'contactmanager_tracking' ) ) {
				$this->errors[] = "table 'contactmanager_tracking' does not exist (run maintenance/update.php)";
				return false;
			}
			$this->transportClass->setPersonalizations( $this->personalizations );
		}

		$email = new Email();
		$email->from( $this->obj['from'] );

		if ( $this->editor === 'VisualEditor' ) {
			[ $text, $html ] = $this->parseWikitext( $this->obj['text'] );
			$email->text( $text );
			$email->html( $html );

		} else {
			if ( $this->obj['is_html'] ) {
				$html = $this->obj['text_html'];
				$html2Text = new \Html2Text\Html2Text( $html );
				$text = $html2Text->getText();
				$email->text( $text );
				$email->html( $html );

			} else {
				if ( !empty( $this->obj['template'] ) ) {
					// throws an error if it does not work
					$email->html( $this->renderTwigTemplate() );
				}

				$email->text( $this->obj['text'] );
			}
		}

		// this is equivalent to $email->to( ...$this->obj['to'] );
		// however it filters the invalid email addresses
		$getParsedRecipients = static function ( $recipients ) {
			$arr = array_filter( array_map( static function ( $value ) {
				if ( $value instanceof Address ) {
					return [ $value->getName(), $value->getAddress() ];
				}
				return \ContactManager::parseRecipient( $value );
			}, $recipients ) );

			return array_map( static function ( $value ) {
				return new Address( $value[1], $value[0] );
			}, $arr );
		};

		// avoids symfony error "An email must have a "To", "Cc", or "Bcc" header."
		if ( count( $this->personalizations ) &&
			!count( $this->obj['to'] ) &&
			!count( $this->obj['cc'] ) &&
			!count( $this->obj['bcc'] )
		) {
			// reserved by RFC 2606, will be ignored
			$this->obj['to'] = [ 'test@example.com' ];
		}

		if ( count( $this->obj['to'] ) ) {
			$email->to( ...$getParsedRecipients( $this->obj['to'] ) );
		}

		if ( count( $this->obj['cc'] ) ) {
			$email->cc( ...$getParsedRecipients( $this->obj['cc'] ) );
		}

		if ( count( $this->obj['bcc'] ) ) {
			$email->bcc( ...$getParsedRecipients( $this->obj['bcc'] ) );
		}

		if ( empty( $this->obj['subject'] ) ) {
			// zero width space, this is a workaround
			// for the annoying error
			// 'Unable to send an email: The subject is required'
			$this->obj['subject'] = '​';
		}

		$email->subject( $this->obj['subject'] );

		// $filePaths = [];
		// @see https://symfony.com/doc/5.x/mailer.html#file-attachments
		if ( is_array( $this->obj['attachments'] ) ) {
			foreach ( $this->obj['attachments'] as $value_ ) {
				// *** already entered as filepath formula within the schema
				// \ContactManager::getAttachmentsFolder() . '/' . $this->title->getArticleID() . '/' .
				$path_ = $value_;

				// #method 1: attach uploaded (unpublished) file
				if ( file_exists( $path_ ) ) {
					$email->attachFromPath( $path_, $value_ );
				} else {
					$this->errors[] = "file '$path_' does not exist";
					return false;
				}

				// #method 2: attach published file
				// $file_ = \ContactManager::getFile( $value_ );
				// if ( $file_ ) {
				// 	if ( $file_->isLocal() ) {
				// 		$filePaths[] = $file_->getLocalRefPath();
				// 		$email->attachFromPath( $file_->getLocalRefPath(), $file_->getTitle()->getText(), $file_->getMimeType() );
				// 	}
				// }
			}
		}

		$headersEmail = $email->getHeaders();
		// @see Symfony\Component\Mailer\Header\MetadataHeader
		// @see MediaWiki\Extension\ContactManager\Transport\SendgridApiTransport
		// saved as $payload['custom_args']
		$headersEmail->addTextHeader( 'X-Metadata-page_id', $this->title->getArticleID() );

		if ( $this->mailer ) {
			$headersEmail->addTextHeader( 'X-Metadata-mailer', $this->mailer );
		}

		try {
			$this->mailerClass->send( $email );

		} catch ( TransportExceptionInterface | TransportException | \Exception $e ) {
			$this->errors[] = $e->getMessage();
			return false;
		}

		$this->setupTracking();

		return true;
	}
}
