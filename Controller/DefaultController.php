<?php

namespace CampaignChain\Activity\GoToWebinarBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use CampaignChain\Operation\GoToWebinarBundle\Form\Type\IncludeWebinarOperationType;
use Symfony\Component\HttpFoundation\Request;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Entity\Medium;
use CampaignChain\CoreBundle\Entity\SchedulerReportOperation;

class DefaultController extends Controller
{
    const ACTIVITY_BUNDLE_NAME          = 'campaignchain/activity-gotowebinar';
    const ACTIVITY_MODULE_IDENTIFIER    = 'campaignchain-gotowebinar';
    const OPERATION_BUNDLE_NAME         = 'campaignchain/operation-gotowebinar';
    const OPERATION_MODULE_IDENTIFIER   = 'campaignchain-gotowebinar';
    const LOCATION_BUNDLE_NAME          = 'campaignchain/location-citrix';
    const LOCATION_MODULE_IDENTIFIER    = 'campaignchain-gotowebinar';
    const TRIGGER_HOOK_IDENTIFIER       = 'campaignchain-duration';
    const METRIC_REGISTRANTS            = 'Registrants';
    const METRIC_ATTENDEES              = 'Attendees';

    public function newAction(Request $request)
    {
        $wizard = $this->get('campaignchain.core.activity.wizard');
        $campaign = $wizard->getCampaign();
        $activity = $wizard->getActivity();
        $location = $wizard->getLocation();

        $activity->setEqualsOperation(true);

        // Retrieve upcoming Webinars from Citrix.
        $client = $this->get('campaignchain.channel.citrix.rest.client');
        $connection = $client->connectByLocation($location);
        $upcomingWebinars = $connection->getUpcomingWebinars();

        $locationService = $this->get('campaignchain.core.location');

        if(is_array($upcomingWebinars) && count($upcomingWebinars)){

            $datetimeUtil = $this->get('campaignchain.core.util.datetime');

            foreach($upcomingWebinars as $key => $upcomingWebinar){
                // Check if Webinar has already been added to this Campaign.
                if(!$locationService->existsInCampaign($upcomingWebinar['webinarKey'], $campaign)){

                    $startDate = $datetimeUtil->formatLocale(
                        new \DateTime($upcomingWebinar['times'][0]['startTime']),
                        $upcomingWebinar['timeZone']
                    );

                    $endDate = $datetimeUtil->getLocalizedTime(
                        new \DateTime($upcomingWebinar['times'][0]['endTime']),
                        $upcomingWebinar['timeZone']
                    );

                    $timezoneDate = new \DateTime($upcomingWebinar['times'][0]['startTime']);
                    $timezoneDate->setTimezone(new \DateTimeZone($upcomingWebinar['timeZone']));
                    $timezone = $timezoneDate->format('T');

                    $webinars[$upcomingWebinar['webinarKey']] =
                        $upcomingWebinar['subject']
                        .' ('.$startDate.' - '.$endDate.' '.$timezone.')';
                } else {
                    $this->get('session')->getFlashBag()->add(
                        'warning',
                        'All upcoming Webinars have already been added to the campaign "'.$campaign->getName().'".'
                    );

                    return $this->redirect(
                        $this->generateUrl('campaignchain_core_activities_new')
                    );
                }
            }
        } else {
            $this->get('session')->getFlashBag()->add(
                'warning',
                'No upcoming Webinars available.'
            );

            return $this->redirect(
                $this->generateUrl('campaignchain_core_activities_new')
            );
        }

        $activityType = $this->get('campaignchain.core.form.type.activity');
        $activityType->setBundleName(self::ACTIVITY_BUNDLE_NAME);
        $activityType->setModuleIdentifier(self::ACTIVITY_MODULE_IDENTIFIER);
        $activityType->showNameField(false);

        $operationType = new IncludeWebinarOperationType($this->getDoctrine()->getManager(), $this->get('service_container'));

        $location = $locationService->getLocation($location->getId());
        $operationType->setLocation($location);

        $operationType->setWebinars($webinars);

        $operationForms[] = array(
            'identifier' => self::OPERATION_MODULE_IDENTIFIER,
            'form' => $operationType,
            'label' => 'Include Webinar',
        );
        $activityType->setOperationForms($operationForms);
        $activityType->setCampaign($campaign);

        $form = $this->createForm($activityType, $activity);

        $form->handleRequest($request);

        if ($form->isValid()) {
            // Retrieve Webinar data from Citrix REST API
            $webinarKey = $form->get(self::OPERATION_MODULE_IDENTIFIER)->getData()['webinar'];
            $webinar = $connection->getWebinar($webinarKey);

            $webinarStartDate = new \DateTime($webinar['times'][0]['startTime']);
            $webinarEndDate = new \DateTime($webinar['times'][0]['endTime']);

            $activity = $wizard->end();

            $activity->setName($webinar['subject']);
            $activity->setStartDate($webinarStartDate);
            $activity->setEndDate($webinarEndDate);

            $hookService = $this->get('campaignchain.core.hook');
            $triggerHook = $hookService->getHook(self::TRIGGER_HOOK_IDENTIFIER);

            $activity->setTriggerHook($triggerHook);

            // Get the operation module.
            $operationService = $this->get('campaignchain.core.operation');
            $operationModule = $operationService->getOperationModule(self::OPERATION_BUNDLE_NAME, self::OPERATION_MODULE_IDENTIFIER);

            // The activity equals the operation. Thus, we create a new operation with the same data.
            $operation = new Operation();
            $operation->setName($webinar['subject']);
            $operation->setStartDate($webinarStartDate);
            $operation->setEndDate($webinarEndDate);
            $operation->setTriggerHook($triggerHook);
            $operation->setActivity($activity);
            $activity->addOperation($operation);
            $operationModule->addOperation($operation);
            $operation->setOperationModule($operationModule);

            // The Operation creates a Location, i.e. the Twitter post
            // will be accessible through a URL after publishing.
            // Get the location module for the user stream.
            $locationService = $this->get('campaignchain.core.location');
            $locationModule = $locationService->getLocationModule(
                self::LOCATION_BUNDLE_NAME,
                self::LOCATION_MODULE_IDENTIFIER
            );

            $location = new Location();
            $location->setLocationModule($locationModule);
            $location->setParent($activity->getLocation());
            $location->setIdentifier($webinar['webinarKey']);
            $location->setName($webinar['subject']);
            $location->setUrl($webinar['registrationUrl']);
            $location->setStatus(Medium::STATUS_UNPUBLISHED);
            $location->setOperation($operation);
            $operation->addLocation($location);

            $repository = $this->getDoctrine()->getManager();

            // Make sure that data stays intact by using transactions.
            try {
                $repository->getConnection()->beginTransaction();

                $repository->persist($operation);
                $repository->persist($activity);

                $reportJob = $this->get('campaignchain.job.report.gotowebinar');
                $reportJob->schedule($operation, array(
                    self::METRIC_REGISTRANTS => $webinar['numberOfRegistrants']
                ));

                $repository->flush();

                $repository->getConnection()->commit();
            } catch (\Exception $e) {
                $repository->getConnection()->rollback();
                throw $e;
            }

            $this->get('session')->getFlashBag()->add(
                'success',
                'The Webinar <a href="'.$this->generateUrl('campaignchain_core_activity_edit', array('id' => $activity->getId())).'">'.$activity->getName().'</a> has been added successfully.'
            );

            return $this->redirect($this->generateUrl('campaignchain_core_activities'));

            //return $this->redirect($this->generateUrl('task_success'));
        }

        return $this->render(
            'CampaignChainCoreBundle:Operation:new.html.twig',
            array(
                'page_title' => 'Add a Webinar',
                'activity' => $activity,
                'campaign' => $campaign,
                'channel_module' => $wizard->getChannelModule(),
                'channel_module_bundle' => $wizard->getChannelModuleBundle(),
                'location' => $wizard->getLocation(),
                'form' => $form->createView(),
                'form_submit_label' => 'Save',
                'form_cancel_route' => 'campaignchain_core_activities_new'
            ));

    }
}
