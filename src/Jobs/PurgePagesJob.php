<?php
namespace SteadLane\Cloudflare;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

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
        $serverName = CloudFlare::singleton()->getServerName();
        $batch_limit = CloudFlare::config()->purge_batch_limit ?? 30;
        $sleep_interval = CloudFlare::config()->purge_sleep_between_calls ?? 2;
        $i = 0;

        // $records = SiteTree::get()->map('ID', 'Link')->toArray();
        $records = Versioned::get_by_stage(SiteTree::class, Versioned::LIVE)
            ->map('ID', 'Link')->toArray();

        $batches = array_chunk($records, $batch_limit, true);

        $this->totalSteps = count($batches);
        foreach($batches as $i => $batch) {
            $purger = Purge::create();

            foreach($batch as $id => $link) {
                $purger->pushFile('https://'.$serverName . $link);
                DB::alteration_message(sprintf("[%s / %s]\t%s", ($i+1), count($batches), $link));
            }

            $purger->purge();
            $this->currentStep = ($i + 1);
            sleep($sleep_interval);
        }

        $this->isComplete = true;
    }
}
