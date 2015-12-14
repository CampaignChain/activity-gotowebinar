<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Activity\GoToWebinarBundle\Controller;

use CampaignChain\Channel\CitrixBundle\REST\CitrixClient;
use CampaignChain\CoreBundle\Controller\Module\AbstractActivityHandler;
use CampaignChain\CoreBundle\Entity\Campaign;
use CampaignChain\CoreBundle\EntityService\HookService;
use CampaignChain\CoreBundle\EntityService\LocationService;
use CampaignChain\CoreBundle\Util\DateTimeUtil;
use CampaignChain\Operation\GoToWebinarBundle\EntityService\Webinar as WebinarService;
use CampaignChain\Operation\GoToWebinarBundle\Job\Report;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\CoreBundle\Entity\Activity;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\Operation\GoToWebinarBundle\Entity\Webinar;

class GoToWebinarAddHandler extends AbstractActivityHandler
{
    const LOCATION_BUNDLE_NAME          = 'campaignchain/location-citrix';
    const LOCATION_MODULE_IDENTIFIER    = 'campaignchain-gotowebinar';
    const GOTOWEBINAR_ADMIN_URL         = 'https://global.gotowebinar.com/webinars.tmpl';
    const WEBINAR_URL                   = 'https://global.gotowebinar.com/g2wbroker/manageWebinar.tmpl?webinar=';
    const TRIGGER_HOOK_IDENTIFIER       = 'campaignchain-duration';
    const METRIC_REGISTRANTS            = 'Registrants';

    protected $em;
    protected $router;
    protected $contentService;
    protected $locationService;
    protected $restClient;
    protected $datetimeUtil;
    protected $hookService;
    protected $reportJob;
    protected $session;
    protected $templating;
    protected $remoteWebinars;
    private     $remoteWebinar;
    private     $restApiConnection;

    public function __construct(
        EntityManager $em,
        WebinarService $contentService,
        LocationService $locationService,
        CitrixClient $restClient,
        DateTimeUtil $datetimeUtil,
        HookService $hookService,
        Report $reportJob,
        $session,
        TwigEngine $templating,
        Router $router
    )
    {
        $this->em = $em;
        $this->contentService   = $contentService;
        $this->locationService  = $locationService;
        $this->restClient       = $restClient;
        $this->datetimeUtil     = $datetimeUtil;
        $this->hookService      = $hookService;
        $this->reportJob        = $reportJob;
        $this->session          = $session;
        $this->templating       = $templating;
        $this->router           = $router;
    }

    public function createContent(Location $location = null, Campaign $campaign = null)
    {
        // Retrieve upcoming Webinars from Citrix.
        $connection = $this->getRestApiConnectionByLocation($location);
        $upcomingWebinars = $connection->getUpcomingWebinars();

        if(is_array($upcomingWebinars) && count($upcomingWebinars)){
            foreach($upcomingWebinars as $key => $upcomingWebinar){
                // Check if Webinar has already been added to this Campaign.
                if(!$this->locationService->existsInCampaign(
                    self::LOCATION_BUNDLE_NAME, self::LOCATION_MODULE_IDENTIFIER,
                    $upcomingWebinar['webinarKey'], $campaign
                )){
                    $webinarStartDateUtc = new \DateTime($upcomingWebinar['times'][0]['startTime']);
                    $startDate = $this->datetimeUtil->formatLocale(
                        $webinarStartDateUtc
                    );
                    $webinarEndDateUtc = new \DateTime($upcomingWebinar['times'][0]['endTime']);
                    $endDate = $this->datetimeUtil->getLocalizedTime(
                        $webinarEndDateUtc
                    );

                    $timezoneDate = new \DateTime($upcomingWebinar['times'][0]['startTime']);
                    $timezoneDate->setTimezone(new \DateTimeZone(
                            $this->datetimeUtil->getUserTimezone()
                        )
                    );
                    $timezone = $timezoneDate->format('T');

                    $webinarTeaser = $upcomingWebinar['subject']
                        .' ('.$startDate.' - '.$endDate.' '.$timezone.')';

                    // Check whether Webinar is outside campaign duration.
                    if(
                        $webinarStartDateUtc
                        < $campaign->getStartDate()->setTimezone(new \DateTimeZone('UTC'))
                        || $webinarEndDateUtc
                        > $campaign->getEndDate()->setTimezone(new \DateTimeZone('UTC'))
                    ){
                        // Webinar is beyond campaign's start or end date.
                        $webinarsOutside[] = $webinarTeaser;
                    } else {
                        $webinars[$upcomingWebinar['webinarKey']] = $webinarTeaser;
                    }
                } else {
                    $this->session->getFlashBag()->add(
                        'warning',
                        'All upcoming Webinars have already been added to the campaign "'.$campaign->getName().'".'
                    );

                    header('Location: '.$this->router->generate('campaignchain_core_activities_new'));
                    exit;
                }
            }
        } else {
            $this->session->getFlashBag()->add(
                'warning',
                'No upcoming Webinars available.'
            );

            header('Location: '.$this->router->generate('campaignchain_core_activities_new'));
            exit;
        }

        /*
         * If no upcoming Webinars within campaign duration, but others
         * outside of it, then redirect.
         */
        if(
            !isset($webinars)
            && is_array($webinarsOutside) && count($webinarsOutside)
        ){
            $webinarsList = '';
            foreach($webinarsOutside as $webinarOutside){
                $webinarsList .= '<li>'.$webinarOutside.'</li>';
            }

            $this->session->getFlashBag()->add(
                'warning',
                'No upcoming Webinars within the duration of the selected campaign.<br/>'
                .'<p>Campaign "'.$campaign->getName().'"</p>'
                .'<p>Start: '
                .$this->datetimeUtil->formatLocale($campaign->getStartDate())
                .' '.$campaign->getStartDate()->format('T')
                .'</p>'
                .'<p>End: '
                .$this->datetimeUtil->formatLocale($campaign->getEndDate())
                .' '.$campaign->getEndDate()->format('T')
                .'</p>'
                .'<p>Upcoming Webinars:</p>'
                .'<ul>'
                .$webinarsList
                .'</ul>'
            );

            header('Location: '.$this->router->generate('campaignchain_core_activities_new'));
            exit;
        }

        return $webinars;
    }

    public function postFormSubmitNewEvent(Activity $activity, $data)
    {
        $webinarKey = $data['webinar'];

        $connection = $this->getRestApiConnectionByActivity($activity);
        $this->remoteWebinar = $connection->getWebinar($webinarKey);
    }

    public function processActivityLocation(Location $location)
    {
        // Temporarily have the location point to the list of upcoming Webinars on the
        // GoToWebinar website until we know which Webinar has been chosen.
        $location->setUrl(self::GOTOWEBINAR_ADMIN_URL);

        return $location;
    }

    public function processActivity(Activity $activity, $data)
    {
        $activity->setName($this->remoteWebinar['subject']);
        $webinarStartDate = new \DateTime($this->remoteWebinar['times'][0]['startTime']);
        $activity->setStartDate($webinarStartDate);
        $webinarEndDate = new \DateTime($this->remoteWebinar['times'][0]['endTime']);
        $activity->setEndDate($webinarEndDate);
        $triggerHook = $this->hookService->getHook(self::TRIGGER_HOOK_IDENTIFIER);
        $activity->setTriggerHook($triggerHook);

        return $activity;
    }

    public function processContent(Operation $operation, $data)
    {
        if(isset($data['webinar'])) {
            $content = new Webinar();
            $content->setOperation($operation);
            $content->setWebinarKey($this->remoteWebinar['webinarKey']);
            $content->setOrganizerKey($this->remoteWebinar['organizerKey']);
            $content->setSubject($this->remoteWebinar['subject']);
            $content->setDescription($this->remoteWebinar['description']);
            $content->setTimeZone($this->remoteWebinar['timeZone']);
            $content->setRegistrationUrl($this->remoteWebinar['registrationUrl']);

            return $content;
        } else {
            throw new \Exception(
                'Webinar details cannot be edited in CampaignChain '.
                'due to restrictions in the GoToWebinar REST API.');
        }
    }

    public function processContentLocation(Location $location, $data)
    {
        $location->setIdentifier($this->remoteWebinar['webinarKey']);
        $location->setName($this->remoteWebinar['subject']);
        $location->setUrl($this->remoteWebinar['registrationUrl']);

        return $location;
    }

    public function postPersistNewEvent(Operation $operation, Form $form, $content = null)
    {
        $this->reportJob->schedule($operation, array(
            self::METRIC_REGISTRANTS => $this->remoteWebinar['numberOfRegistrants']
        ));
    }

    public function readAction(Operation $operation)
    {
        // Get Webinar info.
        $webinar = $this->contentService->getWebinarByOperation($operation->getId());

        // TODO: Check if Webinar dates were edited on GoToWebinar.

        return $this->templating->renderResponse(
            'CampaignChainOperationGoToWebinarBundle::read.html.twig',
            array(
                'page_title' => $operation->getActivity()->getName(),
                'operation' => $operation,
                'activity' => $operation->getActivity(),
                'webinar' => $webinar,
                'show_date' => true,
            ));
    }

    public function getEditModalRenderOptions(Operation $operation)
    {
        // Get Webinar info.
        $webinar = $this->contentService->getWebinarByOperation($operation->getId());

        // TODO: Check if Webinar dates were edited on GoToWebinar.

        return array(
            'template' => 'CampaignChainOperationGoToWebinarBundle::read_modal.html.twig',
            'vars' => array(
                'webinar' => $webinar,
                'show_date' => true,
                'operation' => $operation,
                'activity' => $operation->getActivity(),
            )
        );
    }

    private function getRestApiConnectionByActivity(Activity $activity)
    {
        if(!$this->restApiConnection){
            $this->restApiConnection = $this->restClient->connectByActivity(
                $activity
            );
        }

        return $this->restApiConnection;
    }

    private function getRestApiConnectionByLocation(Location $location)
    {
        if(!$this->restApiConnection){
            $this->restApiConnection =
                $this->restClient->connectByLocation($location);
        }

        return $this->restApiConnection;
    }

    public function hasContent($view)
    {
        if($view != 'new'){
            return false;
        }

        return true;
    }
}