<?php
namespace SteadLane\Cloudflare;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;

if(!class_exists(AbstractQueuedJob::class)) {
    return;
}

class PurgePagesJob extends AbstractQueuedJob
{
    public function getTitle()
    {
        return 'Purge Cloudflare pages';
    }

    public function process()
    {
        if(class_exists('SilverStripe\Subsites\Model\Subsite')) {
            \SilverStripe\Subsites\Model\Subsite::disable_subsite_filter();
        }

        $batch_limit = CloudFlare::config()->purge_batch_limit ?? 30;
        $sleep_interval = CloudFlare::config()->purge_sleep_between_calls ?? 2;

        $i = 0;

        $records = Versioned::get_by_stage(SiteTree::class, Versioned::LIVE)
            ->map('ID', 'AbsoluteLink')->toArray();

        $batches = array_chunk($records, $batch_limit, true);

        $this->totalSteps = count($batches);
        foreach($batches as $i => $batch) {
            $purger = Purge::create();

            foreach($batch as $id => $link) {
                $link = str_replace('http://', 'https://', $link);
                $purger->pushFile($link);
                DB::alteration_message(sprintf("[%s / %s]\t%s", ($i+1), count($batches), $link));
            }

            $purger->purge();
            $this->currentStep = ($i + 1);
            sleep($sleep_interval);
        }

        $this->isComplete = true;
    }
}
