<?php
/*

Copyright (c) 2009, SilverStripe Australia PTY LTD - www.silverstripe.com.au
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * Neither the name of SilverStripe nor the names of its contributors may be used to endorse or promote products derived from this software
      without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY
OF SUCH DAMAGE.
*/

/**
 * A queued job used for reindexing content
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class SolrReindexJob extends AbstractQueuedJob {

	public function __construct() {
		
	}

	public function getTitle() {
		return "Reindex content in Solr";
	}

	/**
	 * Lets see how many pages we're re-indexing
	 */
	public function getJobType() {
		$query = 'SELECT count(*) FROM "Page"';
		$this->totalSteps = DB::query($query)->value();
		return $this->totalSteps > 100 ? QueuedJob::LARGE : QueuedJob::QUEUED;
	}

	public function setup() {
		$this->lastIndexedID = 0;
		$service = singleton('SolrSearchService');
		$service->getSolr()->deleteByQuery('*:*');
	}

	/**
	 * To process this job, we need to get the next page whose ID is the next greater than the last
	 * processed. This way we don't need to remember a bunch of data about what we've processed
	 */
	public function process() {
		if (ClassInfo::exists('Subsite')) {
			Subsite::disable_subsite_filter();
		}
		$page = DataObject::get_one('SiteTree', db_quote(array('SiteTree.ID >' => $this->lastIndexedID)), true, 'ID ASC');
		if (ClassInfo::exists('Subsite')) {
			Subsite::$disable_subsite_filter = false;
		}
		
		if (!$page || !$page->exists()) {
			$this->isComplete = true;
			return;
		}

		// index away
		$service = singleton('SolrSearchService');
		$service->index($page);

		$this->currentStep++;

		$this->lastIndexedID = $page->ID;

	}
}
?>