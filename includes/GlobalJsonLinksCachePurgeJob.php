<?php

namespace JsonConfig;

use Job;
use JobSpecification;
use MediaWiki\JobQueue\JobQueueGroupFactory;

/**
 * Class to insert HTMLCacheUpdate jobs on local wikis to purge all pages that use
 * a given data page. Note that these objects are serialized for the job queue.
 */
class GlobalJsonLinksCachePurgeJob extends Job {
	private GlobalJsonLinks $globalJsonLinks;
	private JobQueueGroupFactory $jobQueueGroupFactory;

	public function __construct( $title, $params,
		GlobalJsonLinks $globalJsonLinks,
		JobQueueGroupFactory $jobQueueGroupFactory
	) {
		parent::__construct( 'globalJsonLinksCachePurge', $title, $params );
		$this->globalJsonLinks = $globalJsonLinks;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
		$this->removeDuplicates = true; // expensive
	}

	public function run() {
		$title = $this->getTitle();
		if ( !$title->inNamespace( NS_DATA ) ) {
			return true; // should not happen! ignore
		}

		// These help identify the job source for debug needs later.
		$rootParams = Job::newRootJobParams( // "overall" purge job info
			"GlobalJsonLinks:htmlCacheUpdate:globaljsonlinks:{$title->getPrefixedText()}" );

		// Note we can't use dependency injection here because job classes get serialized.
		$backlinks = $this->globalJsonLinks->getLinksToTarget( $title->getTitleValue() );

		// Build up a list of HTMLCacheUpdateJob jobs to put on each affected wiki to clear
		// the caches for each page that links to these file pages. Note this could be done
		// with fewer jobs if we had the articleId by passing the 'pages' param to the job.
		$jobsByWiki = [];
		foreach ( $backlinks as $record ) {
			$jobsByWiki[$record['wiki']][] = new JobSpecification(
				'htmlCacheUpdate',
				[
					'namespace' => $record['namespace'],
					'title' => $record['title'],
				],
				$rootParams
			);
		}

		// Batch insert the jobs by wiki to save a few round trips
		foreach ( $jobsByWiki as $wiki => $jobs ) {
			$this->jobQueueGroupFactory->makeJobQueueGroup( $wiki )->push( $jobs );
		}

		return true;
	}

}
