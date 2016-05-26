<?php
namespace Wwwision\Eventr\Adapters\GetEventStore;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Http\Client\RequestEngineInterface;
use TYPO3\Flow\Http\Request;
use TYPO3\Flow\Http\Uri;
use TYPO3\Flow\Utility\Algorithms;
use Wwwision\Eventr\Domain\Dto\Event;
use Wwwision\Eventr\Domain\Dto\WritableEvent;
use Wwwision\Eventr\Domain\Model\AggregateType;
use Wwwision\Eventr\EventStoreAdapterInterface;
use Wwwision\Eventr\EventStream;
use Wwwision\Eventr\ExpectedVersion;
use Wwwision\Eventr\WrongExpectedVersionException;

/**
 * Adapter for the HTTP API of the geteventstore.com server
 */
class EventStoreAdapter implements EventStoreAdapterInterface
{
    /**
     * @Flow\Inject
     * @var RequestEngineInterface
     */
    protected $httpClient;

    /**
     * @var string
     */
    protected $baseUrl;

    public function __construct($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * {@inheritDoc}
     */
    public function writeToStream($streamName, WritableEvent $event, $expectedVersion = ExpectedVersion::ANY)
    {
        $url = sprintf('%s/streams/%s', $this->baseUrl, $streamName);
        $request = Request::create(new Uri($url), 'POST');
        $request->setHeader('Content-Type', 'application/vnd.eventstore.events+json');
        $request->setHeader('ES-ExpectedVersion', (integer)$expectedVersion);
        $eventData = [
            'eventId' => Algorithms::generateUUID(),
            'eventType' => $event->getType(),
            'data' => $event->getData()
        ];
        if ($event->hasMetadata()) {
            $eventData['metaData'] = $event->getMetadata();
        }
        $request->setContent(json_encode([$eventData]));
        $response = $this->httpClient->sendRequest($request);
        if ($response->getStatusCode() === 400) {
            throw new WrongExpectedVersionException(sprintf('Expected version: %d', $expectedVersion), 1464088109);
        }
        // TODO error handling

        $lookupRequest = Request::create(new Uri($response->getHeader('Location')), 'GET');
        $lookupRequest->setHeader('Accept', 'application/vnd.eventstore.atom+json');
        $lookupResponse = $this->httpClient->sendRequest($lookupRequest);

        $entry = json_decode($lookupResponse->getContent(), true)['content'];
        return new Event($entry['eventType'], $entry['eventNumber'], $entry['data'], $entry['metadata']);
    }

    /**
     * {@inheritDoc}
     */
    public function getEventStreamFor(AggregateType $aggregateType, $offset = 0)
    {
        $streamName = sprintf('$ce-%s', $aggregateType->getName());
        return new EventStream(new StreamIterator($this->baseUrl, $streamName, $offset));
    }
}