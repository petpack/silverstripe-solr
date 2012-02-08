<?php
/**
 * A page type specifically used for displaying search results.
 *
 * This is an alternative encapsulation of search logic as it comprises much more than the out of the
 * box example. To use this instead of the default implementation, your search form call in Page should first
 * retrieve the SolrSimpleSearchPage to use as its context.
 * 
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
class SolrSimpleSearchPage extends Page {
	
    public static $db = array(
		'ResultsPerPage' => 'Int',
		'SortBy' => "Varchar(64)",
		'SortDir' => "Enum('Ascending,Descending')",
		'StartWithListing'	=> 'Boolean',			// whether to start display with a *:* search
	);
	
	/**
	 * A local cache of the current query the user is executing based
	 * on data in the request
	 *
	 * @var SolrResultSet
	 */
	protected $query;

	/**
	 * @var SolrSearchService
	 */
	protected $solr;

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$fields->addFieldToTab('Root.Content.Main', new CheckboxField('StartWithListing', _t('SolrSimpleSearchPage.START_LISTING', 'Display initial listing')), 'Content');

		$perPage = array('5' => '5', '10' => '10', '15' => '15', '20' => '20');
		$fields->addFieldToTab('Root.Content.Main',new DropdownField('ResultsPerPage', _t('SolrSimpleSearchPage.RESULTS_PER_PAGE', 'Results per page'), $perPage), 'Content');

		return $fields;
	}
	
	/**
	 * Get the solr instance. 
	 * 
	 * Note that we do this as a method just in case we decide in future
	 * that different pages can utilise different solr instances.. 
	 */
	public function getSolr() {
		if (!$this->solr) {
			$this->solr = singleton('SolrSearchService');
		}
		return $this->solr;
	}
	
	/**
	 * Get the currently active query for this page, if any
	 * 
	 * @return SolrResultSet
	 */
	public function getQuery() {
		if ($this->query) {
			return $this->query;
		}

		if (!$this->getSolr()->isConnected()) {
			return null;
		}

		$builder = $this->queryBuilder();
		$offset = isset($_GET['start']) ? $_GET['start'] : 0;
		$limit = isset($_GET['limit']) ? $_GET['limit'] : ($this->ResultsPerPage ? $this->ResultsPerPage : 10);

		$params = array(
			'fl' => '*,score'
		);

		$this->query = $this->getSolr()->query($builder, $offset, $limit, $params);
		return $this->query;
	}
	
	protected function queryBuilder() {
		$builder = $this->getSolr()->getQueryBuilder();
		
		if (isset($_GET['Search'])) {
			$query = $_GET['Search'];

			// lets convert it to a base solr query
			$builder->baseQuery($query);
		}
		return $builder;
	}

	/**
	 * Returns a url parameter string that was just used to execute the current query.
	 *
	 * This is useful for ensuring the parameters used in the search can be passed on again
	 * for subsequent queries.
	 *
	 * @param array $exclusions
	 *			A list of elements that should be excluded from the final query string
	 *
	 * @return String
	 */
	function SearchQuery() {
		$parts = parse_url($_SERVER['REQUEST_URI']);
		if(!$parts) {
			throw new InvalidArgumentException("Can't parse URL: " . $uri);
		}

		// Parse params and add new variable
		$params = array();
		if(isset($parts['query'])) {
			parse_str($parts['query'], $params);
			if (count($params)) {
				return http_build_query($params);
			}
		}
	}

}

class SolrSimpleSearchPage_Controller extends Page_Controller {

	protected function getSolr() {
		return $this->data()->getSolr();
	}
	
	public function index() {
		if ($this->StartWithListing) {
			$_GET['Search'] = '*';
			$this->DefaultListing = true;
			
			return $this->results();
		}
		return array();
	}

	public function Form() {
		$fields = new FieldSet(
			new TextField('Search', _t('SolrSimpleSearchPage.SEARCH','Search'), isset($_GET['Search']) ? $_GET['Search'] : '')
		);

		$actions = new FieldSet(new FormAction('results', _t('SolrSimpleSearchPage.DO_SEARCH', 'Search')));
		
		$form = new Form($this, 'Form', $fields, $actions);
		$form->addExtraClass('searchPageForm');
		$form->setFormMethod('GET');
		$form->disableSecurityToken();
		return $form;
	}

	/**
	 * Process and render search results
	 */
	function results($data = null, $form = null){
		$errorMessage = null;
		$query = null;
		try{
			$query = $this->data()->getQuery();
		}
		catch(Exception $exception){
			//query has caused an exception
			//Check if it's a parse error, and if so just show an error message.
			if (false !== strpos($exception->getMessage(), 'orgapachelucenequeryParserParseException')) {
				$errorMessage = 'Invalid Query';
			}
			else {
				throw $exception;
			}
		}

		$term = isset($_GET['Search']) ? Convert::raw2xml($_GET['Search']) : '';

	  	$data = array(
	     	'Results' => $query ? $query->getDataObjects() : new DataObjectSet(),
	     	'Query' => $term,
	      	'Title' => 'Search Results',
	      	'ErrorMessage' => $errorMessage,
	  	);

	  	return $this->customise($data)->renderWith(array('SolrSimpleSearchPage_results', 'SolrSimpleSearchPage', 'Page'));
	}
	
	public function SearchTerm() {
		return isset($_GET['Search']) ? $_GET['Search'] : '';
	}

}
