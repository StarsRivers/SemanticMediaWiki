<?php

use SMW\ApplicationFactory;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMWDataItem as DataItem;
use SMW\HashBuilder;
use SMW\RequestOptions;
use SMW\PropertyRegistry;
use SMW\SQLStore\IdToDataItemMatchFinder;
use SMW\SQLStore\PropertyStatisticsStore;
use SMW\SQLStore\RedirectStore;
use SMW\SQLStore\TableFieldUpdater;
use SMW\MediaWiki\Collator;
use SMW\SQLStore\SQLStoreFactory;
use SMW\SQLStore\SQLStore;

/**
 * @ingroup SMWStore
 * @since 1.8
 * @author Markus Krötzsch
 */

/**
 * Class to access the SMW IDs table in SQLStore3.
 * Provides transparent in-memory caching facilities.
 *
 * Documentation for the SMW IDs table: This table is a dictionary that
 * assigns integer IDs to pages, properties, and other objects used by SMW.
 * All tables that refer to such objects store these IDs instead. If the ID
 * information is lost (e.g., table gets deleted), then the data stored in SMW
 * is no longer meaningful: all tables need to be dropped, recreated, and
 * refreshed to get back to a working database.
 *
 * The table has a column for storing interwiki prefixes, used to refer to
 * pages on external sites (like in MediaWiki). This column is also used to
 * mark some special objects in the table, using "interwiki prefixes" that
 * cannot occur in MediaWiki:
 *
 * - Rows with iw SMW_SQL3_SMWREDIIW are similar to normal entries for
 * (internal) wiki pages, but the iw indicates that the page is a redirect, the
 * (target of which should be sought using the smw_fpt_redi table.
 *
 * - The (unique) row with iw SMW_SQL3_SMWBORDERIW just marks the border
 * between predefined ids (rows that are reserved for hardcoded ids built into
 * SMW) and normal entries. It is no object, but makes sure that SQL's auto
 * increment counter is high enough to not add any objects before that marked
 * "border".
 *
 * @note Do not call the constructor of SMWDIWikiPage using data from the SMW
 * IDs table; use SMWDIHandlerWikiPage::dataItemFromDBKeys() instead. The table
 * does not always contain data as required wiki pages. Especially predefined
 * properties are represented by language-independent keys rather than proper
 * titles. SMWDIHandlerWikiPage takes care of this.
 *
 * @since 1.8
 *
 * @ingroup SMWStore
 */
class SMWSql3SmwIds {

	/**
	 * Specifies the border limit for pre-defined properties declared
	 * in SMWSql3SmwIds::special_ids
	 */
	const FXD_PROP_BORDER_ID = SMWSQLStore3::FIXED_PROPERTY_ID_UPPERBOUND;

	/**
	 * Name of the table to store IDs in.
	 *
	 * @note This should never change. Existing wikis will have to drop and
	 * rebuild their SMW tables completely to recover from any change here.
	 */
	const TABLE_NAME = SMWSQLStore3::ID_TABLE;

	const MAX_CACHE_SIZE = 500;
	const POOLCACHE_ID = 'smw.sqlstore';
	/**
	 * Id for which property table hashes are cached, if any.
	 *
	 * @since 1.8
	 * @var integer
	 */
	protected $hashCacheId = 0;

	/**
	 * Cached property table hashes for $hashCacheId.
	 *
	 * @since 1.8
	 * @var string
	 */
	protected $hashCacheContents = '';

	/**
	 * Parent SMWSQLStore3.
	 *
	 * @since 1.8
	 * @var SMWSQLStore3
	 */
	public $store;

	/**
	 * @var SQLStoreFactory
	 */
	private $factory;

	/**
	 * @var IdToDataItemMatchFinder
	 */
	private $idMatchFinder;

	/**
	 * @var RedirectStore
	 */
	private $redirectStore;

	/**
	 * @var TableFieldUpdater
	 */
	private $tableFieldUpdater;

	/**
	 * Use pre-defined ids for Very Important Properties, avoiding frequent
	 * ID lookups for those.
	 *
	 * @note These constants also occur in the store. Changing them will
	 * require to run setup.php again. They can also not be larger than 50.
	 *
	 * @since 1.8
	 * @var array
	 */
	public static $special_ids = array(
		'_TYPE' => 1,
		'_URI'  => 2,
		'_INST' => 4,
		'_UNIT' => 7,
		'_IMPO' => 8,
		'_PPLB' => 9,
		'_PDESC' => 10,
		'_PREC' => 11,
		'_CONV' => 12,
		'_SERV' => 13,
		'_PVAL' => 14,
		'_REDI' => 15,
		'_DTITLE' => 16,
		'_SUBP' => 17,
		'_SUBC' => 18,
		'_CONC' => 19,
//		'_SF_DF' => 20, // Semantic Form's default form property
//		'_SF_AF' => 21,  // Semantic Form's alternate form property
		'_ERRP' => 22,
// 		'_1' => 23, // properties for encoding (short) lists
// 		'_2' => 24,
// 		'_3' => 25,
// 		'_4' => 26,
// 		'_5' => 27,
// 		'_SOBJ' => 27
		'_LIST' => 28,
		'_MDAT' => 29,
		'_CDAT' => 30,
		'_NEWP' => 31,
		'_LEDT' => 32,
		// properties related to query management
		'_ASK'   =>  33,
		'_ASKST' =>  34,
		'_ASKFO' =>  35,
		'_ASKSI' =>  36,
		'_ASKDE' =>  37,
		'_ASKPA' =>  38,
		'_ASKSC' =>  39,
		'_LCODE' =>  40,
		'_TEXT'  =>  41,
	);

	/**
	 * @var IdCacheManager
	 */
	private $idCacheManager;

	/**
	 * @var IdEntityFinder
	 */
	private $idEntityFinder;

	/**
	 * @since 1.8
	 * @param SMWSQLStore3 $store
	 */
	public function __construct( SMWSQLStore3 $store, SQLStoreFactory $factory ) {
		$this->store = $store;
		$this->factory = $factory;
		$this->initCache();

		$this->idEntityFinder = $this->factory->newIdEntityFinder(
			$this->idCacheManager->get( 'entity.lookup' )
		);

		$this->redirectStore = $this->factory->newRedirectStore();

		$this->tableFieldUpdater = new TableFieldUpdater(
			$this->store
		);
	}

	/**
	 * @since  2.1
	 *
	 * @param DIWikiPage $subject
	 *
	 * @return boolean
	 */
	public function isRedirect( DIWikiPage $subject ) {
		return $this->redirectStore->isRedirect( $subject->getDBKey(), $subject->getNamespace() );
	}

	/**
	 * @since 3.0
	 *
	 * @param DataItem $dataItem
	 *
	 * @return boolean
	 */
	public function isUnique( DataItem $dataItem ) {

		$type = $dataItem->getDIType();

		if ( $type !== DataItem::TYPE_WIKIPAGE && $type !== DataItem::TYPE_PROPERTY ) {
			throw new InvalidArgumentException( 'Expects a DIProperty or DIWikiPage object.' );
		}

		$connection = $this->store->getConnection( 'mw.db' );
		$conditions = [];

		if ( $type === DataItem::TYPE_WIKIPAGE ) {
			$conditions[] = "smw_title=" . $connection->addQuotes( $dataItem->getDBKey() );
			$conditions[] = "smw_namespace=" . $connection->addQuotes( $dataItem->getNamespace() );
			$conditions[] = "smw_subobject=" . $connection->addQuotes( $dataItem->getSubobjectName() );
		} else {
			$conditions[] = "smw_sortkey=" . $connection->addQuotes( $dataItem->getCanonicalLabel() );
			$conditions[] = "smw_namespace=" . $connection->addQuotes( SMW_NS_PROPERTY );
			$conditions[] = "smw_subobject=''";
		}

		$conditions[] = "smw_iw!=" . $connection->addQuotes( SMW_SQL3_SMWIW_OUTDATED );
		$conditions[] = "smw_iw!=" . $connection->addQuotes( SMW_SQL3_SMWDELETEIW );
		$conditions[] = "smw_iw!=" . $connection->addQuotes( SMW_SQL3_SMWREDIIW );

		$res = $connection->select(
			SMWSQLStore3::ID_TABLE,
			[
				'smw_id, smw_sortkey'
			],
			$conditions,
			__METHOD__,
			[
				'LIMIT' => 2
			]
		);

		return $res->numRows() < 2;
	}

	/**
	 * @see RedirectStore::findRedirect
	 *
	 * @since 2.1
	 *
	 * @param string $title DB key
	 * @param integer $namespace
	 *
	 * @return integer
	 */
	public function findRedirect( $title, $namespace ) {
		return $this->redirectStore->findRedirect( $title, $namespace );
	}

	/**
	 * @see RedirectStore::addRedirect
	 *
	 * @since 2.1
	 *
	 * @param integer $id
	 * @param string $title
	 * @param integer $namespace
	 */
	public function addRedirect( $id, $title, $namespace ) {
		$this->redirectStore->addRedirect( $id, $title, $namespace );
	}

	/**
	 * @see RedirectStore::updateRedirect
	 *
	 * @since 3.0
	 *
	 * @param integer $id
	 * @param string $title
	 * @param integer $namespace
	 */
	public function updateRedirect( $id, $title, $namespace ) {
		$this->redirectStore->updateRedirect( $id, $title, $namespace );
	}

	/**
	 * @see RedirectStore::deleteRedirect
	 *
	 * @since 2.1
	 *
	 * @param string $title
	 * @param integer $namespace
	 */
	public function deleteRedirect( $title, $namespace ) {
		$this->redirectStore->deleteRedirect( $title, $namespace );
	}

	/**
	 * Find the numeric ID used for the page of the given title,
	 * namespace, interwiki, and subobject. If $canonical is set to true,
	 * redirects are taken into account to find the canonical alias ID for
	 * the given page. If no such ID exists, 0 is returned. The Call-By-Ref
	 * parameter $sortkey is set to the current sortkey, or to '' if no ID
	 * exists.
	 *
	 * If $fetchhashes is true, the property table hash blob will be
	 * retrieved in passing if the opportunity arises, and cached
	 * internally. This will speed up a subsequent call to
	 * getPropertyTableHashes() for this id. This should only be done
	 * if such a call is intended, both to safe the previous cache and
	 * to avoid extra work (even if only a little) to fill it.
	 *
	 * @since 1.8
	 * @param string $title DB key
	 * @param integer $namespace namespace
	 * @param string $iw interwiki prefix
	 * @param string $subobjectName name of subobject
	 * @param string $sortkey call-by-ref will be set to sortkey
	 * @param boolean $canonical should redirects be resolved?
	 * @param boolean $fetchHashes should the property hashes be obtained and cached?
	 * @return integer SMW id or 0 if there is none
	 */
	public function getSMWPageIDandSort( $title, $namespace, $iw, $subobjectName, &$sortkey, $canonical, $fetchHashes = false ) {
		$id = $this->getPredefinedData( $title, $namespace, $iw, $subobjectName, $sortkey );
		if ( $id != 0 ) {
			return (int)$id;
		} else {
			return (int)$this->getDatabaseIdAndSort( $title, $namespace, $iw, $subobjectName, $sortkey, $canonical, $fetchHashes );
		}
	}

	/**
	 * Find the numeric ID used for the page of the given normalized title,
	 * namespace, interwiki, and subobjectName. Predefined IDs are not
	 * taken into account (however, they would still be found correctly by
	 * an avoidable database read if they are stored correctly in the
	 * database; this should always be the case). In all other aspects, the
	 * method works just like getSMWPageIDandSort().
	 *
	 * @since 1.8
	 * @param string $title DB key
	 * @param integer $namespace namespace
	 * @param string $iw interwiki prefix
	 * @param string $subobjectName name of subobject
	 * @param string $sortkey call-by-ref will be set to sortkey
	 * @param boolean $canonical should redirects be resolved?
	 * @param boolean $fetchHashes should the property hashes be obtained and cached?
	 * @return integer SMW id or 0 if there is none
	 */
	protected function getDatabaseIdAndSort( $title, $namespace, $iw, $subobjectName, &$sortkey, $canonical, $fetchHashes ) {
		global $smwgQEqualitySupport;

		$db = $this->store->getConnection();

		// Integration test "query-04-02-subproperty-dc-import-marc21.json"
		// showed a deterministic failure (due to a wrong cache id during querying
		// for redirects) hence we force to read directly from the RedirectStore
		// for objects marked as redirect
		if ( $iw === SMW_SQL3_SMWREDIIW && $canonical &&
			$smwgQEqualitySupport !== SMW_EQ_NONE && $subobjectName === '' ) {
			$id = $this->findRedirect( $title, $namespace );
		} else {
			$id = $this->idCacheManager->getId( [ $title, (int)$namespace, $iw, $subobjectName ] );
		}

		if ( $id !== false ) { // cache hit
			$sortkey = $this->idCacheManager->getSort( [ $title, (int)$namespace, $iw, $subobjectName ] );
		} elseif ( $iw == SMW_SQL3_SMWREDIIW && $canonical &&
			$smwgQEqualitySupport != SMW_EQ_NONE && $subobjectName === '' ) {
			$id = $this->findRedirect( $title, $namespace );
			if ( $id != 0 ) {

				if ( $fetchHashes ) {
					$select = array( 'smw_sortkey', 'smw_sort', 'smw_proptable_hash' );
				} else {
					$select = array( 'smw_sortkey', 'smw_sort' );
				}

				$row = $db->selectRow(
					self::TABLE_NAME,
					$select,
					array( 'smw_id' => $id ),
					__METHOD__
				);

				if ( $row !== false ) {
					// Make sure that smw_sort is being re-computed in case it is null
					$sortkey = $row->smw_sort === null ? '' : $row->smw_sortkey;
					if ( $fetchHashes ) {
						$this->setPropertyTableHashesCache( $id, $row->smw_proptable_hash );
					}
				} else { // inconsistent DB; just recover somehow
					$sortkey = str_replace( '_', ' ', $title );
				}
			} else {
				$sortkey = '';
			}
			$this->setCache( $title, $namespace, $iw, $subobjectName, $id, $sortkey );
		} else {

			if ( $fetchHashes ) {
				$select = array( 'smw_id', 'smw_sortkey', 'smw_sort', 'smw_proptable_hash' );
			} else {
				$select = array( 'smw_id', 'smw_sortkey', 'smw_sort' );
			}

			$row = $db->selectRow(
				self::TABLE_NAME,
				$select,
				array(
					'smw_title' => $title,
					'smw_namespace' => $namespace,
					'smw_iw' => $iw,
					'smw_subobject' => $subobjectName
				),
				__METHOD__
			);

			//$this->selectrow_sort_debug++;

			if ( $row !== false ) {
				$id = $row->smw_id;
				// Make sure that smw_sort is being re-computed in case it is null
				$sortkey = $row->smw_sort === null ? '' : $row->smw_sortkey;
				if ( $fetchHashes ) {
					$this->setPropertyTableHashesCache( $id, $row->smw_proptable_hash);
				}
			} else {
				$id = 0;
				$sortkey = '';
			}

			$this->setCache(
				$title,
				$namespace,
				$iw,
				$subobjectName,
				$id,
				$sortkey
			);
		}

		if ( $id == 0 && $subobjectName === '' && $iw === '' ) { // could be a redirect; check
			$id = $this->getSMWPageIDandSort(
				$title,
				$namespace,
				SMW_SQL3_SMWREDIIW,
				$subobjectName,
				$sortkey,
				$canonical,
				$fetchHashes
			);
		}

		return $id;
	}

	/**
	 * @since 3.0
	 *
	 * @return []
	 */
	public function findDuplicates() {
		return $this->idEntityFinder->findDuplicates();
	}

	/**
	 * @since 2.3
	 *
	 * @param string $title DB key
	 * @param integer $namespace namespace
	 * @param string|null $iw interwiki prefix
	 * @param string $subobjectName name of subobject
	 *
	 * @param array
	 */
	public function findAllEntitiesThatMatch( $title, $namespace, $iw = null, $subobjectName = '' ) {

		$matches = [];
		$query = [];

		$query['fields'] = ['smw_id'];

		$query['conditions'] = [
			'smw_title' => $title,
			'smw_namespace' => $namespace,
			'smw_iw' => $iw,
			'smw_subobject' => $subobjectName
		];

		// Null means select all (incl. those marked delete, redi etc.)
		if ( $iw === null ) {
			unset( $query['conditions']['smw_iw'] );
		}

		$connection = $this->store->getConnection( 'mw.db' );

		$rows = $connection->select(
			self::TABLE_NAME,
			$query['fields'],
			$query['conditions'],
			__METHOD__
		);

		if ( $rows === false ) {
			return $matches;
		}

		foreach ( $rows as $row ) {
			$matches[] = $row->smw_id;
		}

		return $matches;
	}

	/**
	 * @since 2.4
	 *
	 * @param DIWikiPage $subject
	 *
	 * @param boolean
	 */
	public function exists( DIWikiPage $subject ) {
		return $this->getIDFor( $subject ) > 0;
	}

	/**
	 * @note SMWSql3SmwIds::getSMWPageID has some issues with the cache as it returned
	 * 0 even though an object was matchable, using this method is safer then trying
	 * to encipher getSMWPageID related methods.
	 *
	 * It uses the PoolCache which means Lru is in place to avoid memory leakage.
	 *
	 * @since 2.4
	 *
	 * @param DIWikiPage $subject
	 *
	 * @param integer
	 */
	public function getIDFor( DIWikiPage $subject ) {

		// Try to match a predefined property
		if ( $subject->getNamespace() === SMW_NS_PROPERTY && $subject->getInterWiki() === '' ) {
			$property = DIProperty::newFromUserLabel( $subject->getDBKey() );
			$key = $property->getKey();

			// Has a fixed ID?
			if ( isset( self::$special_ids[$key] ) && $subject->getSubobjectName() === '' ) {
				return self::$special_ids[$key];
			}

			// Switch title for fixed properties without a fixed ID (e.g. _MIME is the smw_title)
			if ( !$property->isUserDefined() ) {
				$subject = new DIWikiPage(
					$key,
					SMW_NS_PROPERTY,
					$subject->getInterWiki(),
					$subject->getSubobjectName()
				);
			}
		}

		if ( ( $id = $this->idCacheManager->getId( $subject ) ) !== false ) {
			return $id;
		}

		$id = 0;

		$row = $this->store->getConnection( 'mw.db' )->selectRow(
			self::TABLE_NAME,
			array( 'smw_id' ),
			array(
				'smw_title' => $subject->getDBKey(),
				'smw_namespace' => $subject->getNamespace(),
				'smw_iw' => $subject->getInterWiki(),
				'smw_subobject' => $subject->getSubobjectName()
			),
			__METHOD__
		);

		if ( $row !== false ) {
			$id = $row->smw_id;

			// Legacy
			$this->setCache(
				$subject->getDBKey(),
				$subject->getNamespace(),
				$subject->getInterWiki(),
				$subject->getSubobjectName(),
				$id,
				$subject->getSortKey()
			);
		}

		return $id;
	}

	/**
	 * Convenience method for calling getSMWPageIDandSort without
	 * specifying a sortkey (if not asked for).
	 *
	 * @since 1.8
	 * @param string $title DB key
	 * @param integer $namespace namespace
	 * @param string $iw interwiki prefix
	 * @param string $subobjectName name of subobject
	 * @param boolean $canonical should redirects be resolved?
	 * @param boolean $fetchHashes should the property hashes be obtained and cached?
	 * @return integer SMW id or 0 if there is none
	 */
	public function getSMWPageID( $title, $namespace, $iw, $subobjectName, $canonical = true, $fetchHashes = false ) {
		$sort = '';
		return $this->getSMWPageIDandSort( $title, $namespace, $iw, $subobjectName, $sort, $canonical, $fetchHashes );
	}

	/**
	 * Find the numeric ID used for the page of the given title, namespace,
	 * interwiki, and subobjectName. If $canonical is set to true,
	 * redirects are taken into account to find the canonical alias ID for
	 * the given page. If no such ID exists, a new ID is created and
	 * returned. In any case, the current sortkey is set to the given one
	 * unless $sortkey is empty.
	 *
	 * @note Using this with $canonical==false can make sense, especially when
	 * the title is a redirect target (we do not want chains of redirects).
	 * But it is of no relevance if the title does not have an id yet.
	 *
	 * @since 1.8
	 * @param string $title DB key
	 * @param integer $namespace namespace
	 * @param string $iw interwiki prefix
	 * @param string $subobjectName name of subobject
	 * @param boolean $canonical should redirects be resolved?
	 * @param string $sortkey call-by-ref will be set to sortkey
	 * @param boolean $fetchHashes should the property hashes be obtained and cached?
	 * @return integer SMW id or 0 if there is none
	 */
	public function makeSMWPageID( $title, $namespace, $iw, $subobjectName, $canonical = true, $sortkey = '', $fetchHashes = false ) {
		$id = $this->getPredefinedData( $title, $namespace, $iw, $subobjectName, $sortkey );
		if ( $id != 0 ) {
			return (int)$id;
		} else {
			return (int)$this->makeDatabaseId( $title, $namespace, $iw, $subobjectName, $canonical, $sortkey, $fetchHashes );
		}
	}

	/**
	 * Find the numeric ID used for the page of the given normalized title,
	 * namespace, interwiki, and subobjectName. Predefined IDs are not
	 * taken into account (however, they would still be found correctly by
	 * an avoidable database read if they are stored correctly in the
	 * database; this should always be the case). In all other aspects, the
	 * method works just like makeSMWPageID(). Especially, if no ID exists,
	 * a new ID is created and returned.
	 *
	 * @since 1.8
	 * @param string $title DB key
	 * @param integer $namespace namespace
	 * @param string $iw interwiki prefix
	 * @param string $subobjectName name of subobject
	 * @param boolean $canonical should redirects be resolved?
	 * @param string $sortkey call-by-ref will be set to sortkey
	 * @param boolean $fetchHashes should the property hashes be obtained and cached?
	 * @return integer SMW id or 0 if there is none
	 */
	protected function makeDatabaseId( $title, $namespace, $iw, $subobjectName, $canonical, $sortkey, $fetchHashes ) {

		$oldsort = '';
		$id = $this->getDatabaseIdAndSort( $title, $namespace, $iw, $subobjectName, $oldsort, $canonical, $fetchHashes );
		$db = $this->store->getConnection( 'mw.db' );

		// Safeguard to ensure that no duplicate IDs are created
		if ( $id == 0 ) {
			$id = $this->getIDFor( new DIWikiPage( $title, $namespace, $iw, $subobjectName ) );
		}

		$db->beginAtomicTransaction( __METHOD__ );

		if ( $id == 0 ) {
			$sortkey = $sortkey ? $sortkey : ( str_replace( '_', ' ', $title ) );
			$sequenceValue = $db->nextSequenceValue( $this->getIdTable() . '_smw_id_seq' ); // Bug 42659

			// #2089 (MySQL 5.7 complained with "Data too long for column")
			$sortkey = mb_substr( $sortkey, 0, 254 );

			$db->insert(
				self::TABLE_NAME,
				array(
					'smw_id' => $sequenceValue,
					'smw_title' => $title,
					'smw_namespace' => $namespace,
					'smw_iw' => $iw,
					'smw_subobject' => $subobjectName,
					'smw_sortkey' => $sortkey,
					'smw_sort' => Collator::singleton()->getSortKey( $sortkey )
				),
				__METHOD__
			);

			$id = (int)$db->insertId();

			// Properties also need to be in the property statistics table
			if( $namespace === SMW_NS_PROPERTY ) {

				$propertyStatisticsStore = $this->factory->newPropertyStatisticsStore(
					$db
				);

				$propertyStatisticsStore->insertUsageCount( $id, 0 );
			}

			$this->setCache( $title, $namespace, $iw, $subobjectName, $id, $sortkey );

			if ( $fetchHashes ) {
				$this->setPropertyTableHashesCache( $id, null );
			}

		} elseif ( $sortkey !== '' && $sortkey != $oldsort ) {

			$this->tableFieldUpdater->updateSortField( $id, $sortkey );
			$this->setCache( $title, $namespace, $iw, $subobjectName, $id, $sortkey );
		} elseif ( $sortkey !== '' && $this->tableFieldUpdater->canUpdateSortField( $oldsort, $sortkey ) ) {
			$this->tableFieldUpdater->updateSortField( $id, $sortkey );
		}

		$db->endAtomicTransaction( __METHOD__ );

		return $id;
	}

	/**
	 * Properties have a mechanisms for being predefined (i.e. in PHP instead
	 * of in wiki). Special "interwiki" prefixes separate the ids of such
	 * predefined properties from the ids for the current pages (which may,
	 * e.g., be moved, while the predefined object is not movable).
	 *
	 * @todo This documentation is out of date. Right now, the special
	 * interwiki is used only for special properties without a label, i.e.,
	 * which cannot be shown to a user. This allows us to filter such cases
	 * from all queries that retrieve lists of properties. It should be
	 * checked that this is really the only use that this has throughout
	 * the code.
	 *
	 * @since 1.8
	 * @param SMWDIProperty $property
	 * @return string
	 */
	public function getPropertyInterwiki( SMWDIProperty $property ) {
		return ( $property->getLabel() !== '' ) ? '' : SMW_SQL3_SMWINTDEFIW;
	}

	/**
	 * @since  2.1
	 *
	 * @param integer $sid
	 * @param DIWikiPage $subject
	 * @param integer|string|null $interWiki
	 */
	public function updateInterwikiField( $sid, DIWikiPage $subject, $interWiki = null ) {

		$this->store->getConnection()->update(
			self::TABLE_NAME,
			array( 'smw_iw' => $interWiki !== null ? $interWiki : $subject->getInterWiki() ),
			array( 'smw_id' => $sid ),
			__METHOD__
		);

		$this->setCache(
			$subject->getDBKey(),
			$subject->getNamespace(),
			$subject->getInterWiki(),
			$subject->getSubobjectName(),
			$sid,
			$subject->getSortKey()
		);
	}

	/**
	 * Fetch the ID for an SMWDIProperty object. This method achieves the
	 * same as getSMWPageID(), but avoids additional normalization steps
	 * that have already been performed when creating an SMWDIProperty
	 * object.
	 *
	 * @note There is no distinction between properties and inverse
	 * properties here. A property and its inverse have the same ID in SMW.
	 *
	 * @param SMWDIProperty $property
	 * @return integer
	 */
	public function getSMWPropertyID( SMWDIProperty $property ) {
		if ( array_key_exists( $property->getKey(), self::$special_ids ) ) {
			return self::$special_ids[$property->getKey()];
		} else {
			$sortkey = '';
			return $this->getDatabaseIdAndSort( $property->getKey(), SMW_NS_PROPERTY, $this->getPropertyInterwiki( $property ), '', $sortkey, true, false );
		}
	}

	/**
	 * Fetch and possibly create the ID for an SMWDIProperty object. The
	 * method achieves the same as getSMWPageID() but avoids additional
	 * normalization steps that have already been performed when creating
	 * an SMWDIProperty object.
	 *
	 * @see getSMWPropertyID
	 * @param SMWDIProperty $property
	 * @return integer
	 */
	public function makeSMWPropertyID( SMWDIProperty $property ) {
		if ( array_key_exists( $property->getKey(), self::$special_ids ) ) {
			return (int)self::$special_ids[$property->getKey()];
		} else {
			return (int)$this->makeDatabaseId(
				$property->getKey(),
				SMW_NS_PROPERTY,
				$this->getPropertyInterwiki( $property ),
				'',
				true,
				$property->getLabel(),
				false
			);
		}
	}

	/**
	 * Normalize the information for an SMW object (page etc.) and return
	 * the predefined ID if any. All parameters are call-by-reference and
	 * will be changed to perform any kind of built-in normalization that
	 * SMW requires. This mainly applies to predefined properties that
	 * should always use their property key as a title, have fixed
	 * sortkeys, etc. Some very special properties also have fixed IDs that
	 * do not require any DB lookups. In such cases, the method returns
	 * this ID; otherwise it returns 0.
	 *
	 * @note This function could be extended to account for further kinds
	 * of normalization and predefined ID. However, both getSMWPropertyID
	 * and makeSMWPropertyID must then also be adjusted to do the same.
	 *
	 * @since 1.8
	 * @param string $title DB key
	 * @param integer $namespace namespace
	 * @param string $iw interwiki prefix
	 * @param string $subobjectName
	 * @param string $sortkey
	 * @return integer predefined id or 0 if none
	 */
	protected function getPredefinedData( &$title, &$namespace, &$iw, &$subobjectName, &$sortkey ) {
		if ( $namespace == SMW_NS_PROPERTY &&
			( $iw === '' || $iw == SMW_SQL3_SMWINTDEFIW ) && $title != '' ) {

			// Check if this is a predefined property:
			if ( $title{0} != '_' ) {
				// This normalization also applies to
				// subobjects of predefined properties.
				$newTitle = PropertyRegistry::getInstance()->findPropertyIdByLabel( str_replace( '_', ' ', $title ) );
				if ( $newTitle ) {
					$title = $newTitle;
					$sortkey = PropertyRegistry::getInstance()->findPropertyLabelById( $title );
					if ( $sortkey === '' ) {
						$iw = SMW_SQL3_SMWINTDEFIW;
					}
				}
			}

			// Check if this is a property with a fixed SMW ID:
			if ( $subobjectName === '' && array_key_exists( $title, self::$special_ids ) ) {
				return self::$special_ids[$title];
			}
		}

		return 0;
	}

	/**
	 * Change an internal id to another value. If no target value is given, the
	 * value is changed to become the last id entry (based on the automatic id
	 * increment of the database). Whatever currently occupies this id will be
	 * moved consistently in all relevant tables. Whatever currently occupies
	 * the target id will be ignored (it should be ensured that nothing is
	 * moved to an id that is still in use somewhere).
	 *
	 * @since 1.8
	 * @param integer $curid
	 * @param integer $targetid
	 */
	public function moveSMWPageID( $curid, $targetid = 0 ) {
		$db = $this->store->getConnection();

		$row = $db->selectRow(
			self::TABLE_NAME,
			'*',
			array( 'smw_id' => $curid ),
			__METHOD__
		);

		if ( $row === false ) {
			return; // no id at current position, ignore
		}

		$db->beginAtomicTransaction( __METHOD__ );

		if ( $targetid == 0 ) { // append new id
			$sequenceValue = $db->nextSequenceValue( $this->getIdTable() . '_smw_id_seq' ); // Bug 42659

			$db->insert(
				self::TABLE_NAME,
				array(
					'smw_id' => $sequenceValue,
					'smw_title' => $row->smw_title,
					'smw_namespace' => $row->smw_namespace,
					'smw_iw' => $row->smw_iw,
					'smw_subobject' => $row->smw_subobject,
					'smw_sortkey' => $row->smw_sortkey,
					'smw_sort' => $row->smw_sort
				),
				__METHOD__
			);

			$targetid = $db->insertId();
		} else { // change to given id
			$db->insert(
				self::TABLE_NAME,
				array( 'smw_id' => $targetid,
					'smw_title' => $row->smw_title,
					'smw_namespace' => $row->smw_namespace,
					'smw_iw' => $row->smw_iw,
					'smw_subobject' => $row->smw_subobject,
					'smw_sortkey' => $row->smw_sortkey,
					'smw_sort' => $row->smw_sort
				),
				__METHOD__
			);
		}

		$db->delete(
			self::TABLE_NAME,
			array(
				'smw_id' => $curid
			),
			__METHOD__
		);

		$this->setCache(
			$row->smw_title,
			$row->smw_namespace,
			$row->smw_iw,
			$row->smw_subobject,
			$targetid,
			$row->smw_sortkey
		);

		$this->store->changeSMWPageID(
			$curid,
			$targetid,
			$row->smw_namespace,
			$row->smw_namespace
		);

		$db->endAtomicTransaction( __METHOD__ );
	}

	/**
	 * Add or modify a cache entry. The key consists of the
	 * parameters $title, $namespace, $interwiki, and $subobject. The
	 * cached data is $id and $sortkey.
	 *
	 * @since 1.8
	 * @param string $title
	 * @param integer $namespace
	 * @param string $interwiki
	 * @param string $subobject
	 * @param integer $id
	 * @param string $sortkey
	 */
	public function setCache( $title, $namespace, $interwiki, $subobject, $id, $sortkey ) {
		$this->idCacheManager->setCache( $title, $namespace, $interwiki, $subobject, $id, $sortkey );
	}

	/**
	 * @since 2.1
	 *
	 * @param integer $id
	 *
	 * @return DIWikiPage|null
	 */
	public function getDataItemById( $id ) {
		return $this->idEntityFinder->getDataItemById( $id );
	}

	/**
	 * @since 2.3
	 *
	 * @param integer $id
	 * @param RequestOptions|null $requestOptions
	 *
	 * @return string[]
	 */
	public function getDataItemPoolHashListFor( array $idlist, RequestOptions $requestOptions = null ) {
		return $this->idEntityFinder->getDataItemsFromList( $idlist, $requestOptions );
	}

	/**
	 * Remove any cache entry for the given data. The key consists of the
	 * parameters $title, $namespace, $interwiki, and $subobject. The
	 * cached data is $id and $sortkey.
	 *
	 * @since 1.8
	 * @param string $title
	 * @param integer $namespace
	 * @param string $interwiki
	 * @param string $subobject
	 */
	public function deleteCache( $title, $namespace, $interwiki, $subobject ) {
		$this->idCacheManager->deleteCache( $title, $namespace, $interwiki, $subobject );
	}

	/**
	 * Move all cached information about subobjects.
	 *
	 * @todo This method is neither efficient nor very convincing
	 * architecturally; it should be redesigned.
	 *
	 * @since 1.8
	 * @param string $oldtitle
	 * @param integer $oldnamespace
	 * @param string $newtitle
	 * @param integer $newnamespace
	 */
	public function moveSubobjects( $oldtitle, $oldnamespace, $newtitle, $newnamespace ) {
		// Currently we have no way to change title and namespace across all entries.
		// Best we can do is clear the cache to avoid wrong hits:
		if ( $oldnamespace != SMW_NS_PROPERTY || $newnamespace != SMW_NS_PROPERTY ) {
			$this->idCacheManager->deleteCache( $oldtitle, $oldnamespace, '', '' );
			$this->idCacheManager->deleteCache( $newtitle, $newnamespace, '', '' );
		}
	}

	/**
	 * @since 3.0
	 */
	public function initCache() {

		// Tests indicate that it is more memory efficient to have two
		// arrays (IDs and sortkeys) than to have one array that stores both
		// values in some data structure (other than a single string).
		$this->idCacheManager = $this->factory->newIdCacheManager(
			self::POOLCACHE_ID,
			[
				'entity.id' => self::MAX_CACHE_SIZE,
				'entity.sort' => self::MAX_CACHE_SIZE,
				'entity.lookup' => 2000
			]
		);
	}

	/**
	 * Return an array of hashes with table names as keys. These
	 * hashes are used to compare new data with old data for each
	 * property-value table when updating data
	 *
	 * @since 1.8
	 *
	 * @param integer $subjectId ID of the page as stored in the SMW IDs table
	 *
	 * @return array
	 */
	public function getPropertyTableHashes( $subjectId ) {
		$hash = null;
		$db = $this->store->getConnection();

		if ( $this->hashCacheId == $subjectId ) {
			$hash = $this->hashCacheContents;
		} elseif ( $subjectId !== 0 ) {

			$row = $db->selectRow(
				self::TABLE_NAME,
				array( 'smw_proptable_hash' ),
				'smw_id=' . $subjectId,
				__METHOD__
			);

			if ( $row !== false ) {
				$hash = $row->smw_proptable_hash;
			}
		}

		if ( $hash !== null && $GLOBALS['wgDBtype'] == 'postgres' ) {
			$hash = pg_unescape_bytea( $hash );
		}

		return is_null( $hash ) ? array() : unserialize( $hash );
	}

	/**
	 * Update the proptable_hash for a given page.
	 *
	 * @since 1.8
	 * @param integer $sid ID of the page as stored in SMW IDs table
	 * @param string[] of hash values with table names as keys
	 */
	public function setPropertyTableHashes( $sid, array $newTableHashes ) {
		$db = $this->store->getConnection();
		$propertyTableHash = serialize( $newTableHashes );

		$db->update(
			self::TABLE_NAME,
			array( 'smw_proptable_hash' => $propertyTableHash ),
			array( 'smw_id' => $sid ),
			__METHOD__
		);

		if ( $sid == $this->hashCacheId ) {
			$this->setPropertyTableHashesCache( $sid, $propertyTableHash );
		}
	}

	/**
	 * Temporarily cache a property tablehash that has been retrieved for
	 * the given SMW ID.
	 *
	 * @since 1.8
	 * @param $id integer
	 * @param $propertyTableHash string
	 */
	protected function setPropertyTableHashesCache( $id, $propertyTableHash ) {
		if ( $id == 0 ) {
			return; // never cache 0
		}
		//print "Cache set for $id.\n";
		$this->hashCacheId = $id;
		$this->hashCacheContents = $propertyTableHash;
	}

	/**
	 * Returns store Id table name
	 *
	 * @return string
	 */
	public function getIdTable() {
		return self::TABLE_NAME;
	}

}
