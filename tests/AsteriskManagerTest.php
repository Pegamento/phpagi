<?php

namespace PhpAgi\Tests;

use AGI_AsteriskManager;
use PhpAgi\Tests\Support\AmiTestCase;

use const PhpAgi\Tests\Support\EMPTY_CONFIG;

function recordingEventHandler($event, $parameters, $server, $port)
{
    AsteriskManagerTest::$events[] = [$event, $parameters['Event']];
    return 'handled';
}

class AsteriskManagerTest extends AmiTestCase
{
    public static array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$events = [];
    }

    public function testConstructorFillsDefaults(): void
    {
        $manager = new AGI_AsteriskManager(EMPTY_CONFIG, ['server' => 'pbx.example.com']);

        $this->assertSame('pbx.example.com', $manager->config['asmanager']['server']);
        $this->assertSame(5038, $manager->config['asmanager']['port']);
        $this->assertSame('phpagi', $manager->config['asmanager']['username']);
        $this->assertSame('phpagi', $manager->config['asmanager']['secret']);
        $this->assertFalse($manager->config['asmanager']['write_log']);
    }

    public function testSendRequestFormatsActionAndAppendsActionId(): void
    {
        $this->reply('Response: Success', 'ActionID: A1', 'Ping: Pong', 'Timestamp: 1');

        $this->manager->Ping();

        $this->assertSame("Action: Ping\r\nActionID: A1\r\n\r\n", $this->sent());
    }

    public function testSendRequestRepeatsArrayParameters(): void
    {
        $this->reply('Response: Success', 'ActionID: A1', 'Message: Originate successfully queued');

        $this->manager->send_request('Originate', ['Channel' => 'SIP/1', 'Variable' => ['A=1', 'B=2']]);

        $this->assertSame(
            "Action: Originate\r\nChannel: SIP/1\r\nVariable: A=1\r\nVariable: B=2\r\nActionID: A1\r\n\r\n",
            $this->sent()
        );
    }

    public function testExplicitActionIdIsNotDuplicated(): void
    {
        $this->reply('Response: Success', 'ActionID: mine', 'Message: ok');

        $res = $this->manager->Command('core show uptime', 'mine');

        $this->assertSame("Action: Command\r\nCommand: core show uptime\r\nActionID: mine\r\n\r\n", $this->sent());
        $this->assertSame('mine', $res['ActionID']);
    }

    public function testParsesResponseHeaders(): void
    {
        $this->reply('Response: Error', 'ActionID: A1', 'Message: No such channel');

        $this->assertSame(
            ['Response' => 'Error', 'ActionID' => 'A1', 'Message' => 'No such channel'],
            $this->manager->Hangup('SIP/9')
        );
    }

    public function testWaitResponseSkipsMessagesForOtherActions(): void
    {
        $this->reply('Event: Newchannel', 'Channel: SIP/2');
        $this->reply('Response: Success', 'ActionID: other', 'Message: not ours');
        $this->reply('Response: Error', 'ActionID: A1', 'Message: ours');

        $this->assertSame('ours', $this->manager->Ping()['Message']);
    }

    public function testCollectsEventListIntoEvents(): void
    {
        $this->reply('Response: Success', 'ActionID: A1', 'EventList: start', 'Message: Parked calls will follow');
        $this->reply('Event: ParkedCall', 'ActionID: A1', 'Exten: 701');
        $this->reply('Event: ParkedCall', 'ActionID: A1', 'Exten: 702');
        $this->reply('Event: ParkedCallsComplete', 'ActionID: A1', 'EventList: Complete', 'ListItems: 2');

        $res = $this->manager->ParkedCalls();

        $this->assertSame('ParkedCallsComplete', $res['Event']);
        $this->assertSame(['701', '702'], array_column($res['events'], 'Exten'));
    }

    public function testCommandOutputInModernFormat(): void
    {
        $this->reply('Response: Success', 'ActionID: A1', 'Message: Command output follows', 'Output: line1', 'Output: line2');

        $this->assertSame("line1\nline2", $this->manager->Command('core show version')['data']);
    }

    public function testCommandOutputInLegacyFollowsFormat(): void
    {
        // Asterisk <= 1.8 (main/manager.c, action_command) writes the raw CLI output, whose
        // lines end in a bare "\n", directly followed by "--END COMMAND--\r\n\r\n". So the
        // output and the marker arrive as one \r\n-delimited record.
        $this->reply(
            'Response: Follows',
            'Privilege: Command',
            'ActionID: A1',
            "Asterisk 1.8.32.3 built by root\nUptime: 1 day\n--END COMMAND--"
        );

        $this->assertSame(
            "Asterisk 1.8.32.3 built by root\nUptime: 1 day",
            $this->manager->Command('core show version')['data']
        );
    }

    public function testDbGetReturnsValueFromFollowUpEvent(): void
    {
        $this->reply('Response: Success', 'ActionID: A1', 'Message: Result will follow');
        $this->reply('Event: DBGetResponse', 'Family: fam', 'Key: key', 'Val: 42', 'ActionID: A1');

        $this->assertSame('42', $this->manager->DBGet('fam', 'key'));
        $this->assertStringContainsString("Family: fam\r\nKey: key\r\nActionID: A1\r\n", $this->sent());
    }

    public function testDbGetReturnsEmptyStringOnError(): void
    {
        $this->reply('Response: Error', 'ActionID: A1', 'Message: Database entry not found');

        $this->assertSame('', $this->manager->DBGet('fam', 'missing'));
    }

    public function testOriginateSendsOnlyGivenParameters(): void
    {
        $this->reply('Response: Success', 'ActionID: A1', 'Message: Originate successfully queued');

        $this->manager->Originate('SIP/1000', '200', 'default', 1, null, null, 30000, '"Bot" <100>', null, null, true);

        $this->assertSame(
            "Action: Originate\r\nChannel: SIP/1000\r\nExten: 200\r\nContext: default\r\nPriority: 1\r\n"
            . "Timeout: 30000\r\nCallerID: \"Bot\" <100>\r\nAsync: true\r\nActionID: A1\r\n\r\n",
            $this->sent()
        );
    }

    public function testQueueAddOmitsZeroPenalty(): void
    {
        $this->reply('Response: Success', 'ActionID: A1', 'Message: Added interface to queue');

        $this->manager->QueueAdd('support', 'SIP/1000', 0, 'Agent 1');

        $this->assertSame(
            "Action: QueueAdd\r\nQueue: support\r\nInterface: SIP/1000\r\nMemberName: Agent 1\r\nActionID: A1\r\n\r\n",
            $this->sent()
        );
    }

    public function testNamedFunctionEventHandlerIsCalled(): void
    {
        $this->assertTrue($this->manager->add_event_handler('Hangup', __NAMESPACE__ . '\recordingEventHandler'));
        $this->assertFalse($this->manager->add_event_handler('hangup', 'strlen'), 'handlers are not overwritten');

        $ret = $this->manager->process_event(['Event' => 'Hangup', 'Channel' => 'SIP/1']);

        $this->assertSame('handled', $ret);
        $this->assertSame([['hangup', 'Hangup']], self::$events);
    }

    public function testWildcardHandlerCatchesUnhandledEvents(): void
    {
        $this->manager->add_event_handler('*', __NAMESPACE__ . '\recordingEventHandler');

        $this->manager->process_event(['Event' => 'Newstate']);

        $this->assertSame([['newstate', 'Newstate']], self::$events);
    }

    public function testEventsReadWhileWaitingAreDispatched(): void
    {
        $this->manager->add_event_handler('Newchannel', __NAMESPACE__ . '\recordingEventHandler');
        $this->reply('Event: Newchannel', 'Channel: SIP/2');
        $this->reply('Response: Success', 'ActionID: A1', 'Ping: Pong', 'Timestamp: 1');

        $this->manager->Ping();

        $this->assertSame([['newchannel', 'Newchannel']], self::$events);
    }

    public function testRemoveEventHandler(): void
    {
        $this->manager->add_event_handler('Hangup', __NAMESPACE__ . '\recordingEventHandler');

        $this->assertTrue($this->manager->remove_event_handler('HANGUP'));
        $this->assertFalse($this->manager->remove_event_handler('hangup'));
        $this->assertFalse($this->manager->process_event(['Event' => 'Hangup']));
    }

    public function testReadOneMsgThrowsWhenSocketCloses(): void
    {
        fclose($this->server);
        $this->server = fopen('php://memory', 'r'); // keep tearDown happy

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Error reading from AMI socket');

        $this->manager->read_one_msg();
    }

    public function testConnectReturnsFalseWhenNothingListens(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $manager = new AGI_AsteriskManager(EMPTY_CONFIG, ['write_log' => false]);

        $this->assertFalse($manager->connect("127.0.0.1:$port", 'user', 'secret'));
        $this->assertSame('127.0.0.1', $manager->server);
        $this->assertSame((string) $port, $manager->port);
    }
}
