<?php

/**
 * A search service built around Solr
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
class SolrSearchService {
	/**
	 * The connection details for the solr instance to connect to
	 *
	 * @var array
	 */
	public static $solr_details = array(
		'host' => 'localhost',
		'port' => '8983',
		'context' => '/solr',
	);
	
	public static $subite_field  = 'SubsiteID_i';
	
	protected static $multiple_subsite_values_enabled = false;
	
	/**
	 * A list of all fields that will be searched through by default, if the user hasn't specified
	 * any in their search query. 
	 *
	 * @var array 
	 */
	public static $default_query_fields = array(
		'title',
		'text'
	);

	/**
	 * Determines what mapper class to use to map to solr schema fields. 
	 * Change this if you have changed the schema that solr uses by default
	 * 
	 * @var String
	 */
	public static $mapper_class = 'SolrSchemaMapper';

	/**
	 * The mapper to use to map silverstripe objects to a solr schema
	 * 
	 * @var SolrSchemaMapper
	 */
	protected $mapper;
	
	protected $queryBuilders = array();
	
	public function __construct() {
		$m = self::$mapper_class;
		$this->mapper = new $m;
		
		$this->queryBuilders['default'] = 'SolrQueryBuilder'; 
		$this->queryBuilders['dismax'] = 'DismaxSolrSearchBuilder';
	}
	
	/**
	 * Enable pages/data-objects having multiple values for subsite.
	 * @param SS_Boolean $value
	 */
	public static function enableMultipleSubsiteValues($value = true) {
		if (static::$multiple_subsite_values_enabled = $value) {
			static::$subite_field  = 'SubsiteID_mi';
		}
		else {
			static::$subite_field  = 'SubsiteID_i';
		}
	}

	/**
	 * Is solr alive?
	 *
	 * @return SS_Boolean
	 */
	public function isConnected() {
		return $this->getSolr()->ping();
	}

	/**
	 * A class that can map field types to solr fields, and values to appropriate types
	 *
	 * @param SolrSchemaMapper $mapper
	 */
	public function setMapper($mapper) {
		$this->mapper = $mapper;
	}
	
	/**
	 * Add a field to be included in default searches
	 *
	 * @param string $field 
	 */
	public function add_default_query_field($field) {
		self::$default_query_fields[] = $field;
	}
	
	/**
	 * Add a new query parser into the service
	 *
	 * @param string $name
	 * @param object $obj 
	 */
	public function addQueryBuilder($name, $obj) {
		$this->queryBuilders[$name] = $obj;
	}
	
	/**
	 * Gets the list of query parsers available
	 *
	 * @return array
	 */
	public function getQueryBuilders() {
		return $this->queryBuilders;
	}
	
	/**
	 * Gets the query builder for the given search type
	 *
	 * @param SolrQueryBuilder $type 
	 */
	public function getQueryBuilder($type='default') {
		return isset($this->queryBuilders[$type]) ? new $this->queryBuilders[$type] : new $this->queryBuilders['default'];
	}
	
	/**
	 * Assuming here that we're indexing a stdClass object
	 * with an ID field that is a unique identifier
	 * 
	 * Note that the structur eof the object array must be 
	 * 
	 * array(
	 * 		'FieldName' => array(
	 * 			'Type' => 'Fieldtype (eg date, string, int)',
	 * 			'Value' => 'Actualvalue'
	 * 		)
	 * )
	 * 
	 * You should include a field named 'SS_ID' that dictates the 
	 * ID of the object, and a field named 'SS_ClassName' that is the 
	 * name of the document's type
	 * 
	 * @param DataObject $object
	 *				The object being indexed
	 * @param String $stage
	 *				If we're indexing for a particular stage or not. 
	 *
	 */
	public function index($dataObject, $stage=null) {
		$document = new Apache_Solr_Document();
		$fieldsToIndex = array();

		$id = 0;
		$object = null;
		$fieldsToIndex = null;
		if (is_object($dataObject)) {
			if ($dataObject->hasMethod('getSolrIndexable')) {
				$object = $dataObject->getSolrIndexable();
			}
			else {
				$fieldsToIndex = $this->getSearchableFieldsFor($dataObject); // $dataObject->searchableFields();
				$object = $this->objectToFields($dataObject);
				$id = $dataObject->ID;
			}
		} else {
			$object = $dataObject;
		}
		if (!$object) {
			$object = $dataObject;
		}
		if (!$id) {
			$id = isset($object['ID']) ? $object['ID'] : 0;
		}
		if (!$fieldsToIndex) {
			$fieldsToIndex = isset($object['index_fields']) ? $object['index_fields'] : array(
				'Title' => array(),
				'Content' => array(),
			);
		}
		if (isset($object['index_fields'])) {
			unset($object['index_fields']);
		}

		$fieldsToIndex['SS_ID'] = true;
		$fieldsToIndex['LastEdited'] = true;
		$fieldsToIndex['Created'] = true;
		$fieldsToIndex['SS_ClassName'] = true;
		$fieldsToIndex['ClassNameHierarchy'] = true;

		// the stage we're on when we write this doc to the index.
		// this is used for versioned AND non-versioned objects; we just cheat and
		// set it BOTH stages if it's non-versioned object
		$fieldsToIndex['SS_Stage'] = true;

		// if it's a versioned object, just save ONE stage value. 
		if ($stage) {
			$object['SS_Stage'] = array('Type' => 'Enum', 'Value' => $stage);
			$id = $id . '_' . $stage;
		} else {
			$object['SS_Stage'] = array('Type' => 'Enum', 'Value' => array('Draft', 'Live'));
		}

		// specially handle the subsite module - this has serious implications for our search
		// @TODO we want to genercise this later for other modules to hook into it!
		if (ClassInfo::exists('Subsite')) {
			$fieldsToIndex['SubsiteID'] = true;
			if (is_object($dataObject)) {
				$fieldType = (static::$multiple_subsite_values_enabled) ? 'MultiInt' : 'Int';
				$object['SubsiteID'] = array('Type' => $fieldType, 'Value' => $dataObject->SubsiteID);
			}
		}

		$classType = isset($object['SS_ClassName']) ? $object['SS_ClassName']['Value'] : 'INVALID_CLASS_TYPE';

		// we're not indexing these fields just at the moment because the conflict
		unset($object['ID']);

		// a special type hierarchy 
		$classes = array_values(ClassInfo::ancestry($classType));
		$object['ClassNameHierarchy'] = array(
			'Type' => 'MultiValueField',
			'Value' => $classes,
		);
		
		foreach ($object as $field => $valueDesc) {
			if (!is_array($valueDesc)) {
				continue;
			}

			$type = $valueDesc['Type'];
			$value = $valueDesc['Value'];

			// this should have already been taken care of, but just in case...
			if ($type == 'MultiValueField' && $value instanceof MultiValueField) {
				$value = $value->getValues();
			}

			if (!isset($fieldsToIndex[$field])) {
				continue;
			}

			$fieldName = $this->mapper->mapType($field, $type, $fieldsToIndex[$field]);

			if (!$fieldName) {
				continue;
			}

			$value = $this->mapper->mapValue($value, $type);

			if (is_array($value)) {
				foreach ($value as $v) {
					$document->addField($fieldName, $v);
				}
			} else {
				$document->$fieldName = $value;
			}
		}

		if ($id) {
			try {
				$document->id = $classType.'_'.$id;
				$this->getSolr()->addDocument($document);
				$this->getSolr()->commit();
				$this->getSolr()->optimize();
			} catch (Exception $ie) {
				SS_Log::log($ie, SS_Log::ERR);
			}
		}
	}

	/**
	 * Pull out all the fields that should be indexed for a particular object
	 *
	 * This mapping is done to make it easier to
	 *
	 * @param DataObject $dataObject
	 * @return array
	 */
	public function objectToFields($dataObject) {
		$ret = array();

		$fields = Object::combined_static($dataObject->ClassName, 'db');
		$fields['Created'] = 'SS_Datetime';
		$fields['LastEdited'] = 'SS_Datetime';

		$ret['SS_ID'] = array('Type' => 'Int', 'Value' => $dataObject->ID);
		$ret['ID'] = $dataObject->ID;
		$ret['SS_ClassName'] = array('Type' => 'Varchar', 'Value' => $dataObject->class);

		foreach($fields as $name => $type) {
			if (preg_match('/^(\w+)\(/', $type, $match)) {
				$type = $match[1];
			}

			// Just index everything; the query can figure out what to exclude... !
			$value = $dataObject->$name;

			if ($type == 'MultiValueField') {
				$value = $value->getValues();
//				if (!$value || count($value) == 0) {
//					continue;
//				}
			}

			$ret[$name] = array('Type' => $type, 'Value' => $value);
		}

		return $ret;
	}
	
	/**
	 * Delete a data object from the index
	 * 
	 * @param DataObject $object
	 */
	public function unindex($type, $id=null) {
		if (is_object($type)) {
			$id = $type->ID;
			$type = $type->class; // get_class($type);
		}
		try {
			// delete all published/non-published versions of this item. 
			$this->getSolr()->deleteByQuery('id:' . $type . '_' . $id.'*');
			$this->getSolr()->commit();
		} catch (Exception $ie) {
			SS_Log::log($ie, SS_Log::ERR);
		}
		
	}

	/**
	 * Parse a raw user search string into a query appropriate for
	 * execution.
	 *
	 * @param String $query
	 */
	public function parseSearch($query, $type='default') {
		// if there's a colon in the search, assume that the user is doing a custom power search
		if (strpos($query, ':')) {
			return $query;
		}

		if (isset($this->queryBuilders[$type])) {
			return $this->queryBuilders[$type]->parse($query);
		}

		$lucene = implode(':'.$query.' OR ', self::$default_query_fields).':'.$query;
		return $lucene;
	}

	/**
	 * Perform a raw query against the search index, returning a SolrResultSet object that 
	 * can be used to extract a more complete result set
	 *
	 * @param String $query
	 * 			The lucene query to execute.
	 * @param SS_Int $page
	 * 			What result page are we on?
	 * @param SS_Int $limit
	 * 			How many items to limit the query to return
	 * @param array $params
	 * 			A set of parameters to be passed along with the query
	 * @return SolrResultSet
	 */
	public function query($query, $offset = 0, $limit = 20, $params = array()) {
		if (is_string($query)) {
			$builder = $this->getQueryBuilder('default');
			$builder->baseQuery($query);
			$query = $builder;
		}
		// be very specific about the subsite support :). 
		if (ClassInfo::exists('Subsite')) {
			$query->andWith(static::$subite_field, Subsite::currentSubsiteID());
			// $query = "($query) AND (SubsiteID_i:".Subsite::currentSubsiteID().')';
		}

		// add the stage details in - we should probably use an extension mechanism for this,
		// but for now this will have to do. @TODO Refactor this....
		$stage = Versioned::current_stage();
		if (!$stage && !(isset($params['ignore_stage']) && $params['ignore_stage'])) {
			// default to searching live content only
			$stage = 'Live';
		}

		$query->andWith('SS_Stage', $stage);
		// $query = "($query) AND (SS_Stage_ms:$stage)";

		$extraParams = $query->getParams();
		$params = array_merge($params, $extraParams);

		$query = $query->toString();

		// execute the query
		$response = $this->getSolr()->search($query, $offset, $limit, $params);
		$params = new stdClass();
		$params->offset = $offset;
		$params->limit = $limit;
		$params->params = $params;

		return new SolrResultSet($query, $response, $params, $this);
	}


	/**
	 * Method used to return details about the facets stored for content, if any, for an empty query.
	 *
	 * Note - if you're wanting to perform actual queries using faceting information, please
	 * manually add the faceting information into the $params array during the query! This
	 * method is purely for convenience!
	 *
	 * @param $fields
	 *			An array of fields to get facet information for
	 *
	 */
	public function getFacetsForFields($fields, $number=10) {
		if (!is_array($fields)) {
			$fields = array($fields);
		}

		return $this->query('*', 0, 1, array('facet'=>'true', 'facet.field' => $fields, 'facet.limit' => 10, 'facet.mincount' => 1));
	}
	
	protected $client;
	
	/**
	 * Get the solr service client
	 * 
	 * @return Apache_Solr_Service
	 */
	public function getSolr() {
		if (!$this->client) {
			$this->client = new Apache_Solr_Service(self::$solr_details['host'],  self::$solr_details['port'], self::$solr_details['context']);
		} 
		
		return $this->client;
	}
	
	/**
	 * Get all the fields that can be indexed / searched on for a particular type
	 *
	 * @param string $className 
	 */
	public function getSearchableFieldsFor($className) {
		if (is_object($className)) {
			$className = get_class($className);
		}
		
		$searchable = $this->buildSearchableFieldCache();
		$hierarchy = array_reverse(ClassInfo::ancestry($className));
		
		foreach ($hierarchy as $class) {
			if (isset($searchable[$class])) {
				return $searchable[$class];
			}
		}

		return singleton($className)->searchableFields();
	}
	
	protected $searchableCache = array();
	
	/**
	 * Builds up the searchable fields configuration baased on the solrtypeconfiguration objects
	 */
	protected function buildSearchableFieldCache() {
		if (!$this->searchableCache) {
			$objects = DataObject::get('SolrTypeConfiguration');
			if ($objects) {
				foreach ($objects as $obj) {
					$this->searchableCache[$obj->Title] = $obj->FieldMappings->getValues();
				}
			}
		}
		return $this->searchableCache;
	}

	/**
	 * Return the field name for a given property within
	 * on a given data object type
	 *
	 * @param String $field
	 *				The field name to get the Solr type for.
	 * @param String $className
	 *				The data object class name. Defaults to 'page'. 
	 *
	 * @return String
	 *
	 */
	public function getSolrFieldName($field, $className='Page') {
		$dummy = singleton($className);
		$fields = $this->objectToFields($dummy);
		if ($field == 'ID') {
			$field = 'SS_ID';
		}
		$configForType = $this->getSearchableFieldsFor($className);
		if (isset($fields[$field])) {
			$hint = isset($configForType[$field]) ? $configForType[$field] : false;
			return $this->mapper->mapType($field, $fields[$field]['Type'], $hint);
		}
	}

	/**
	 * Get a field name used for sorting in a query. This is just a hardcoded
	 * way at the moment to handle the fact that to sort by 'Title', you
	 * actually want to sort by title_exact (due to tokenization in solr). 
	 *
	 * @param String $field
	 *				The field name to get the Solr type for.
	 * @param String $className
	 *				The data object class name. Defaults to 'page'.
	 */
	public function getSortFieldName($field, $className='Page') {
		return $field == 'Title' ? 'title_exact' : $this->getSolrFieldName($field, $className);
	}
}

/**
 * Class that defines how fields should be mapped to Solr properties
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class SolrSchemaMapper {
	protected $solrFields = array(
		'Title'					=> 'title',
		'LastEdited'			=> 'last_modified',
		'Content'				=> 'text',
		'ClassNameHierarchy'	=> 'ClassNameHierarchy_ms',
		'SS_Stage'				=> 'SS_Stage',
		'SS_ID'				    => 'SS_ID',
		'SS_ClassName'			=> 'SS_ClassName',
	);

	/**
	 * Map a SilverStripe field to a Solr field
	 *
	 * @param String $field
	 *          The field name
	 * @param String $type
	 *          The field type
	 * @param String $value
	 *			The value being stored (needed if a multival)
	 * 
	 * @return String
	 */
	public function mapType($field, $type, $hint = '') {
		if (isset($this->solrFields[$field])) {
			return $this->solrFields[$field];
		}

		if (strpos($type, '(')) {
			$type = substr($type, 0, strpos($type, '('));
		}
		
		if ($hint && is_string($hint) && $hint != 'default') {
			return str_replace(':field', $field, $hint);
		}

		// otherwise, lets use a generic field for it
		switch ($type) {
			case 'MultiValueField': {
				return $field.'_mt';
			}
			case 'Text':
			case 'HTMLText': {
				return $field.'_t';
			}
			case 'SS_Datetime': {
				return $field.'_dt';
			}
			case 'Enum': {
				return $field.'_ms';
			}
			case 'Varchar': {
				return $field.'_mt';
			}
			case 'Int':
			case 'Integer': {
				return $field.'_i';
			}
			case 'MultiInt':
			case 'MultiInteger': {
				return $field.'_mi';
			}
			default: {
				return $field.'_mt';
			}
		}
	}

	/**
	 * Convert a value to a format handled by solr
	 * 
	 * @param mixed $value
	 * @param string $type
	 * @return mixed
	 */
	public function mapValue($value, $type) {
		if (is_array($value)) {
			$newReturn = array();
			foreach ($value as $v) {
				$newReturn[] = $this->mapValue($v, $type);
			}
			return $newReturn;
		} else {
			switch ($type) {
				case 'SS_Datetime': {
					// we don't want a complete iso8601 date, we want it 
					// in UTC time with a Z at the end. It's okay, php's
					// strtotime will correctly re-convert this to the correct
					// timestamp, but this is how Solr wants things
					$hoursToRemove = date('Z');
					$ts = strtotime($value) - $hoursToRemove;

					return date('o-m-d\TH:i:s\Z', $ts);
				}
				case 'HTMLText': {
					//Add whitespace before and after tags, so that tags that visually separate words
					//are properly treated in the stripped version. Otherwise 
					//  <div>woot</div><div>stuff</div>
					//will result in 
					//  wootstuff
					//rather than
					//  woot stuff
				    $value = str_replace(
					    array('<', '>'),
					    array(' <', '> '),
					    $value
				    ); 
				    $value = html_entity_decode(strip_tags($value)); 
				    //Strip extraneous whitespace.
				    return preg_replace('/\s+/',' ',$value); 
				}
				default: {
					return $value;
				}
			}
		}
	} 
}
