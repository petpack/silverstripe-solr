<?php
 
/**
 * A decorator that adds the ability to index a DataObject in Solr
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 *
 */
class SolrIndexable extends DataObjectDecorator {
	/**
	 * We might not want to index, eg during a data load
	 * 
	 * @var boolean
	 */
	public static $indexing = true;

	/**
	 * Should we index draft content too?
	 *
	 * @var boolean
	 */
	public static $index_draft = true;
	
	public static $db = array(
		'ResultBoost'		=> 'Int',
	);

	protected function createIndexJob($item, $stage = null, $mode = 'index') {
		$job = new SolrIndexItemJob($item, $stage, $mode);
		singleton('QueuedJobService')->queueJob($job);
	}

	/**
	 * Index after publish
	 */
	function onAfterPublish() {
		if (!self::$indexing) return;

		if (class_exists('SolrIndexItemJob')) {
			$this->createIndexJob($this->owner, 'Live');
		} else {
			// make sure only the fields that are highlighted in searchable_fields are included!!
			singleton('SolrSearchService')->index($this->owner, 'Live');
		}
	}

	/**
	 * Index after every write; this lets us search on Draft data as well as live data
	 */
	public function onAfterWrite() {
		if (!self::$indexing) return;

		if (!$this->ownerIsIndexable()) {
			return;
		}
		$changes = $this->owner->getChangedFields(true, 2);
		
		if (count($changes)) {
			
			$this->solrIndex();
		}
	}
	
	/**
	 * Returns true if the onwer can currently be indexed.
	 */
	public function ownerIsIndexable() {
		if ($this->owner->hasMethod('isIndexable')) {
			return $this->owner->isIndexable();
		}
		return true;
	}
	
	/**
	 * Forces a index of this data to the solr server.
	 */
	public function solrIndex() {
		$stage = null;
		// if it's being written and a versionable, then save only in the draft
		// repository. 
		if (Object::has_extension($this->owner, 'Versioned')) {
			$stage = 'Stage';
		}

		if (class_exists('SolrIndexItemJob')) {
			$this->createIndexJob($this->owner, $stage);
		} else {
			// make sure only the fields that are highlighted in searchable_fields are included!!
			singleton('SolrSearchService')->index($this->owner, $stage);
		}
	}

	/**
	 * If unpublished, we delete from the index then reindex the 'stage' version of the 
	 * content
	 *
	 * @return 
	 */
	function onAfterUnpublish() {
		if (!self::$indexing) return;

		if (class_exists('SolrIndexItemJob')) {
			$this->createIndexJob($this->owner, null, 'unindex');
			$this->createIndexJob($this->owner, 'Stage');
		} else {
			singleton('SolrSearchService')->unindex($this->owner);
			singleton('SolrSearchService')->index($this->owner, 'Stage');
		}
	}

	function onAfterDelete() {
		if (!self::$indexing) return;
		$this->solrUnindex();
	}
	
	/**
	 * Forces an unindex of this data from the solr server.
	 */
	public function solrUnindex() {
		if (class_exists('SolrIndexItemJob')) {
			$this->createIndexJob($this->owner, null, 'unindex');
		} else {
			singleton('SolrSearchService')->unindex($this->owner);
		}
	}
}
