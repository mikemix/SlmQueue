<?php

namespace SlmQueue\Strategy;

use SlmQueue\Job\JobInterface;
use SlmQueue\Worker\AbstractWorker;
use SlmQueue\Worker\Event\AbstractWorkerEvent;
use SlmQueue\Worker\Event\ProcessIdleEvent;
use SlmQueue\Worker\Event\ProcessJobEvent;
use SlmQueue\Worker\Event\ProcessQueueEvent;
use SlmQueue\Worker\Result\ExitWorkerLoopResult;
use SlmQueue\Worker\Result\ExitWorkerLoopResults;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ResponseCollection;

class ProcessQueueStrategy extends AbstractStrategy
{
    /**
     * {@inheritDoc}
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->listeners[] = $events->attach(
            AbstractWorkerEvent::EVENT_PROCESS_QUEUE,
            [$this, 'onJobPop'],
            $priority
        );
        $this->listeners[] = $events->attach(
            AbstractWorkerEvent::EVENT_PROCESS_JOB,
            [$this, 'onJobProcess'],
            $priority
        );
    }

    /**
     * @param ProcessQueueEvent $processQueueEvent
     * @return ExitWorkerLoopResults
     */
    public function onJobPop(ProcessQueueEvent $processQueueEvent)
    {
        /** @var AbstractWorker $worker */
        $worker       = $processQueueEvent->getTarget();
        $queue        = $processQueueEvent->getQueue();
        $options      = $processQueueEvent->getOptions();
        $eventManager = $worker->getEventManager();

        $job          = $queue->pop($options);

        // The queue may return null, for instance if a timeout was set
        if (!$job instanceof JobInterface) {
            /** @var ResponseCollection $results */
            $results = $eventManager->triggerEventUntil(
                function ($response) {
                    return $response instanceof ExitWorkerLoopResult;
                },
                new ProcessIdleEvent($worker, $queue)
            );

            $processQueueEvent->stopPropagation();

            if ($results->stopped()) {
                return $results->last();
            }

            return;
        }

        $eventManager->triggerEvent(new ProcessJobEvent($job, $worker, $queue));
    }

    /**
     * @param  AbstractWorkerEvent $processJobEvent
     * @return void
     */
    public function onJobProcess(ProcessJobEvent $processJobEvent)
    {
        $job   = $processJobEvent->getJob();
        $queue = $processJobEvent->getQueue();

        /** @var AbstractWorker $worker */
        $worker = $processJobEvent->getTarget();

        $result = $worker->processJob($job, $queue);
        $processJobEvent->setResult($result);
    }
}
