<?php

namespace SteadLane\Cloudflare;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SteadLane\Cloudflare\Messages\Notifications;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Class CloudFlareLeftAndMainExtension
 * @package silverstripe-cloudflare
 */
class CloudFlareLeftAndMainExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    private static $allowed_actions = array(
        'purgesinglepage',
        'purgeallpagesAction'
    );

    /**
     * Purge a single page in CloudFlare
     *
     * @param array $request The SiteTree data requested to be purged
     */
    public function purgesinglepageAction($request)
    {
        if (!Permission::check('CF_PURGE_PAGE')) {
            Security::permissionFailure();
        }

        if (empty($request) || empty($request['ID'])) {
            return;
        }

        Purge::singleton()->quick('page', $request['ID']);
    }

    public function purgeallpagesAction($request)
    {
        QueuedJobService::singleton()->queueJob(
            Injector::inst()->create(PurgePagesJob::class)
        );

        Notifications::handleMessage("Purge all pages queued successfully");
    }
}
